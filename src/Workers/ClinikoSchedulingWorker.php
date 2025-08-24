<?php
namespace App\Workers;

use App\Client\Cliniko\Client;
use App\DTO\CreatePatientCaseDTO;
use App\DTO\CreatePatientDTO;
use App\DTO\CreatePatientFormDTO;
use App\Model\AppointmentType;
use App\Model\IndividualAppointment;
use App\Model\PatientCase;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\Service\ClinikoService;
use App\Service\StripeService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

if (!defined('ABSPATH'))
    exit;

class ClinikoSchedulingWorker
{
    /** Cache-mutex TTL (seconds) */
    private const MUTEX_TTL = 15;

    /** Ledger table name */
    private static function table(): string
    {
        global $wpdb;
        return "{$wpdb->prefix}cliniko_jobs";
    }

    public static function forceHtmlMailType()
    {
        return 'text/html';
    }

    /** Try to create the ledger table if missing. Return true if table exists/usable. */
    private static function ensureLedgerAvailable(): bool
    {
        global $wpdb;
        $table = self::table();

        // Does it exist?
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            $wpdb->dbname,
            $table
        ));
        if ((int) $exists > 0) {
            return true;
        }

        // Try to create it now
        $charset = $wpdb->get_charset_collate();
        $sql = "
        CREATE TABLE {$table} (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          payment_reference VARCHAR(191) NOT NULL,
          status ENUM('started','succeeded','refunded') NOT NULL,
          patient_id VARCHAR(64) NULL,
          appointment_id VARCHAR(64) NULL,
          patient_case_id VARCHAR(64) NULL,
          patient_form_id VARCHAR(64) NULL,
          error_message TEXT NULL,
          created_at DATETIME NOT NULL,
          updated_at DATETIME NOT NULL,
          UNIQUE KEY uniq_payment_ref (payment_reference)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Check again after dbDelta
        $exists2 = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            $wpdb->dbname,
            $table
        ));
        return ((int) $exists2 > 0);
    }

    public static function register(): void
    {
        add_action('cliniko_schedule_appointment', [self::class, 'handle'], 10, 1);
    }

    public static function handle($args): void
    {
        global $wpdb;

        // ---------- Inputs ----------
        $paymentRef = (string) ($args['payment_reference'] ?? '');
        if (!$paymentRef)
            return; // nothing to refund without a reference

        $paymentRef = substr(trim($paymentRef), 0, 191);

        $moduleId = $args['moduleId'] ?? null;
        $patientFormTemplateId = $args['patient_form_template_id'] ?? null;
        $amountCents = isset($args['amount']) ? (int) $args['amount'] : null;

        // May be passed inline (small) or referenced via payload_key (large)
        $patient = is_array($args['patient'] ?? null) ? $args['patient'] : [];
        $content = is_array($args['content'] ?? null) ? $args['content'] : [];

        // Load heavy payload (patient/content/signature) by reference
        $payloadKey = $args['payload_key'] ?? null;
        $signatureAttachmentId = null;
        if ($payloadKey) {
            $stored = get_option($payloadKey);
            if (!$stored) {
                $stored = get_transient($payloadKey);
            } // in case you switch to transients
            if (is_array($stored)) {
                if (empty($patient) && !empty($stored['patient'])) {
                    $patient = $stored['patient'];
                }
                if (array_key_exists('content', $stored)) {
                    $content = $stored['content'];
                }
                if (isset($stored['signature_attachment_id'])) {
                    $signatureAttachmentId = $stored['signature_attachment_id'];
                }
            } else {
                error_log('[ClinikoSchedulingWorker] payload_key not found: ' . $payloadKey);
            }
        }

        // ---------- Mutex (concurrency guard) ----------
        $lockKey = 'wp_cliniko_job_lock_' . md5($paymentRef);
        if (!wp_cache_add($lockKey, 1, '', self::MUTEX_TTL)) {
            return; // another worker is processing this paymentRef right now
        }

        $table = self::table();
        $now = current_time('mysql');

        try {

            // Keep ledger handling inside try so any error still reaches catch/refund/email
            $useLedger = self::ensureLedgerAvailable();

            // ---------- Claim / load job row if ledger is available ----------
            if ($useLedger) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (payment_reference, status, created_at, updated_at)
                 VALUES (%s, 'started', %s, %s)
                 ON DUPLICATE KEY UPDATE payment_reference = payment_reference",
                    $paymentRef,
                    $now,
                    $now
                ));

                $job = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$table} WHERE payment_reference = %s", $paymentRef),
                    $wpdb->get_row
                );

                if (!$job) {
                    // Continue without ledger if row couldn't be read
                    $useLedger = false;
                    error_log('[ClinikoSchedulingWorker] Ledger row not readable; continuing without ledger: ' . $paymentRef);
                } else {
                    if (in_array($job['status'], ['succeeded', 'refunded'], true)) {
                        return; // already finalized for this paymentRef
                    }
                }
            }

            // ---------- Validate essentials (throw; do NOT return) ----------
            if (!$moduleId) {
                throw new \RuntimeException('Missing moduleId.');
            }
            if (!$patientFormTemplateId) {
                throw new \RuntimeException('Missing patient_form_template_id.');
            }

            // ---------- Core services ----------
            $client = Client::getInstance();
            $cliniko = new ClinikoService();

            // 1) Appointment type
            $apptType = AppointmentType::find($moduleId, $client);
            if (!$apptType) {
                throw new \RuntimeException('Appointment type not found.');
            }

            // 2) Patient
            $dto = new CreatePatientDTO();
            $dto->firstName = $patient["first_name"] ?? '';
            $dto->lastName = $patient["last_name"] ?? '';
            $dto->email = $patient["email"] ?? '';

            $dto->address1 = $patient['address_1'] ?? null;
            $dto->address2 = $patient['address_2'] ?? null;
            $dto->city = $patient['city'] ?? null;
            $dto->postCode = $patient['post_code'] ?? null;
            $dto->dateOfBirth = $patient['date_of_birth'] ?? null;

            // Medicare retained for Cliniko
            $dto->medicare = isset($patient['medicare']) ? (string) $patient['medicare'] : null;
            $dto->medicareReferenceNumber = isset($patient['medicare_reference_number'])
                ? (string) $patient['medicare_reference_number']
                : null;

            $dto->patientPhoneNumbers = [
                ['number' => $patient['phone'] ?? '', 'phone_type' => 'Home']
            ];
            $dto->acceptedPrivacyPolicy = true;

            $pt = $cliniko->findOrCreatePatient($dto);
            if (!$pt) {
                throw new \RuntimeException('Unable to find or create patient.');
            }

            if ($useLedger) {
                $wpdb->update(
                    $table,
                    ['patient_id' => (string) $pt->getId(), 'updated_at' => current_time('mysql')],
                    ['payment_reference' => $paymentRef]
                );
            }

            // 3) Slot & practitioner
            $nowDt = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));
            $to = $nowDt->add(new DateInterval('P7D'));

            $practitioners = $apptType->getPractitioners() ?: [];
            if (empty($practitioners)) {
                throw new \RuntimeException('No practitioners available for this appointment type.');
            }

            $practitionerId = $practitioners[0]->getId();
            $appointmentTypeId = $apptType->getId();
            $businessId = get_option('wp_cliniko_business_id');

            $next = $cliniko->getNextAvailableTime(
                $businessId,
                $practitionerId,
                $appointmentTypeId,
                $nowDt->format('Y-m-d'),
                $to->format('Y-m-d'),
                $client
            );
            if (!$next || empty($next->appointmentStart)) {
                throw new \RuntimeException('No available appointment time found.');
            }

            $start = new DateTimeImmutable($next->appointmentStart);
            $end = $start->add(new DateInterval("PT{$apptType->getDurationInMinutes()}M"));

            // 4) Patient case
            $pcDTO = new CreatePatientCaseDTO();
            $pcDTO->name = $apptType->getName();
            $pcDTO->issueDate = $nowDt->format('Y-m-d');
            $pcDTO->patientId = $pt->getId();

            $pc = PatientCase::create($pcDTO, $client);
            if (!$pc) {
                throw new \RuntimeException('Failed to create patient case.');
            }

            if ($useLedger) {
                $wpdb->update(
                    $table,
                    ['patient_case_id' => (string) $pc->getId(), 'updated_at' => current_time('mysql')],
                    ['payment_reference' => $paymentRef]
                );
            }

            // 5) Appointment
            $appt = IndividualAppointment::create([
                "appointment_type_id" => $appointmentTypeId,
                "business_id" => $businessId,
                "starts_at" => $start->format(DATE_ATOM),
                "ends_at" => $end->format(DATE_ATOM),
                "patient_id" => $pt->getId(),
                "practitioner_id" => $practitionerId,
                "patient_case_id" => $pc->getId()
            ], $client);

            if (!$appt) {
                throw new \RuntimeException('Failed to create appointment.');
            }

            if ($useLedger) {
                $wpdb->update(
                    $table,
                    ['appointment_id' => (string) $appt->getId(), 'updated_at' => current_time('mysql')],
                    ['payment_reference' => $paymentRef]
                );
            }

            // 6) Patient form
            $_tpl = PatientFormTemplate::find($patientFormTemplateId, $client);
            if (!$_tpl) {
                throw new \RuntimeException('Patient form template not found.');
            }

            $label = $start->setTimezone(new DateTimeZone('Australia/Sydney'))
                ->format('F j, Y \a\t g:i A (T)');

            $pfDTO = new CreatePatientFormDTO();
            $pfDTO->completed = true;
            $pfDTO->content_sections = $content;
            $pfDTO->business_id = $businessId;
            $pfDTO->patient_form_template_id = $patientFormTemplateId;
            $pfDTO->patient_id = $pt->getId();
            $pfDTO->attendee_id = $pt->getId();
            $pfDTO->appointment_id = $appt->getId();
            $pfDTO->email_to_patient_on_completion = true;
            $pfDTO->name = sprintf('%s - Appointment on %s', $_tpl->getName(), $label);

            $pf = PatientForm::create($pfDTO, $client);
            if (!$pf) {
                throw new \RuntimeException('Failed to create patient form.');
            }

            if ($useLedger) {
                $wpdb->update(
                    $table,
                    [
                        'patient_form_id' => (string) $pf->getId(),
                        'status' => 'succeeded',
                        'updated_at' => current_time('mysql')
                    ],
                    ['payment_reference' => $paymentRef]
                );
            }

            // ----- Success -----

        } catch (\Throwable $e) {
            // ---------- Always attempt refund on any failure ----------
            try {
                $stripe = new StripeService();
                $stripe->refundCharge(
                    $paymentRef,
                    $amountCents, // null => full refund
                    'requested_by_customer',
                    [
                        'moduleId' => (string) ($args['moduleId'] ?? ''),
                        'patient_email' => (string) ($patient['email'] ?? ''),
                        'failure' => 'cliniko_scheduling_failed',
                        'error_message' => substr($e->getMessage(), 0, 500),
                        'payment_reference' => $paymentRef,
                    ]
                );
            } catch (\Throwable $refundError) {
                error_log('[ClinikoSchedulingWorker] Refund failed for ' . $paymentRef . ': ' . $refundError->getMessage());
            } finally {
                // --- 1) Send failure email FIRST (before marking ledger "refunded") ---
                $toEmail = (string) ($patient['email'] ?? '');
                $notifyKey = 'cliniko_notify_' . md5($paymentRef);

                // Debug hook for failed mails
                if (!has_action('wp_mail_failed', '__cliniko_mail_fail_log')) {
                    add_action('wp_mail_failed', function ($wp_error) use ($paymentRef) {
                        error_log('[ClinikoSchedulingWorker] wp_mail_failed for ' . $paymentRef . ': ' . $wp_error->get_error_message());
                    }, 10, 1);
                }

                if ($toEmail) {
                    if (!get_transient($notifyKey)) {
                        $amountTxt = is_int($amountCents) ? number_format($amountCents / 100, 2) : null;
                        $subject = 'We could not schedule your appointment — refund on the way';
                        $first = isset($patient['first_name']) && $patient['first_name'] !== '' ? $patient['first_name'] : '';
                        $greet = $first ? 'Hi ' . $first : 'Hi';

                        // 🔑 Try to load Elementor-saved failure template
                        $tpl = get_option('wp_cliniko_failure_email_tpl', '');

                        if (!empty($tpl)) {
                            // Prepare placeholder values
                            $vars = [
                                '{first_name}' => esc_html((string) ($patient['first_name'] ?? '')),
                                '{last_name}' => esc_html((string) ($patient['last_name'] ?? '')),
                                '{email}' => esc_html((string) ($patient['email'] ?? '')),

                                // leave these as raw so your <strong> tags in template work correctly
                                '{amount}' => $amountTxt !== null ? $amountTxt : '',
                                '{currency}' => strtoupper((string) ($args['currency'] ?? 'AUD')),
                                '{payment_reference}' => $paymentRef,
                                '{appointment_label}' => (string) ($args['appointment_label'] ?? ''),
                            ];
                            $body = strtr($tpl, $vars);
                        } else {
                            // Fallback to your existing hardcoded email
                            $body = '<p>' . esc_html($greet) . ',</p>'
                                . '<p>We’re sorry — we were unable to schedule your appointment due to a technical issue.</p>'
                                . ($amountTxt
                                    ? "<p>A refund of <strong>\${$amountTxt}</strong> has been initiated to your original payment method.</p>"
                                    : "<p>A refund has been initiated to your original payment method.</p>")
                                . '<p>If you have any questions or would like us to try again, please reply to this email.</p>'
                                . '<p>— EasyScripts Support</p>';
                        }

                        $headers = ['Content-Type: text/html; charset=UTF-8'];
                        $mailOk = wp_mail($toEmail, $subject, $body, $headers);



                        error_log('[ClinikoSchedulingWorker] Failure email ' . ($mailOk ? 'SENT' : 'NOT SENT') . ' to ' . $toEmail . ' for ' . $paymentRef);

                        if ($mailOk) {
                            set_transient($notifyKey, 1, 86400); // 24 hours

                        }
                    } else {
                        error_log('[ClinikoSchedulingWorker] Failure email suppressed (already sent) for ' . $paymentRef);
                    }
                } else {
                    error_log('[ClinikoSchedulingWorker] No patient email available for ' . $paymentRef . '; skipping failure email.');
                }

            }

            error_log('[ClinikoSchedulingWorker] Scheduling failed for ' . $paymentRef . ': ' . $e->getMessage());
        }
    }

}
