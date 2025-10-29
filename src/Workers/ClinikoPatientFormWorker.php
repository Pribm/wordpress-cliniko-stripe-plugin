<?php
namespace App\Workers;

use App\Client\Cliniko\Client;
use App\Model\Patient;
use App\Model\Booking;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\DTO\CreatePatientFormDTO;
use App\Service\NotificationService;

if (!defined('ABSPATH')) exit;

class ClinikoPatientFormWorker
{
    private const MUTEX_TTL = 10; // segundos

    public static function register(): void
    {
        add_action('cliniko_async_create_patient_form', [self::class, 'handle'], priority: 10);
    }

    /**
     * Worker responsável por:
     * - Buscar paciente
     * - Validar booking
     * - Criar PatientForm
     * - Enviar e-mails
     *
     * @param array{payload_key: string} $args
     */
    public static function handle(array $args): void
    {
        $payloadKey = $args['payload_key'] ?? null;
        if (!$payloadKey) {
            error_log('[ClinikoPatientFormWorker] Missing payload_key.');
            return;
        }

        // 🔒 Mutex persistente (para evitar execução duplicada)
        $lockKey = 'wp_cliniko_pf_lock_' . md5($payloadKey);
        if (get_transient($lockKey)) {
            error_log("[ClinikoPatientFormWorker] Job already locked for {$payloadKey}");
            return;
        }
        set_transient($lockKey, 1, self::MUTEX_TTL);

        // 🔍 Recupera payload
        $stored = get_option($payloadKey) ?: get_transient($payloadKey);
        if (!is_array($stored)) {
            error_log("[ClinikoPatientFormWorker] No payload found for key {$payloadKey}.");
            delete_transient($lockKey);
            return;
        }

        $notifier = new NotificationService();

        try {
            $client = Client::getInstance();

            $patientData = $stored['patient'] ?? [];
            $content     = $stored['content'] ?? [];
            $moduleId    = $stored['moduleId'] ?? '';
            $templateId  = $stored['patient_form_template_id'] ?? '';
            $bookedTime  = $stored['patient_booked_time'] ?? '';

            $firstName = $patientData['first_name'] ?? '';
            $lastName  = $patientData['last_name'] ?? '';
            $email     = $patientData['email'] ?? '';
            $dob       = $patientData['date_of_birth'] ?? '';

            // 1️⃣ Find patient
            $filters = [];
            if ($firstName) $filters[] = 'q[]=' . urlencode("first_name:={$firstName}");
            if ($lastName)  $filters[] = 'q[]=' . urlencode("last_name:={$lastName}");
            if ($email)     $filters[] = 'q[]=' . urlencode("email:={$email}");
            if ($dob)       $filters[] = 'q[]=' . urlencode("date_of_birth:={$dob}");
            $query = $filters ? implode('&', $filters) : '';

            $patient = Patient::queryOneByQueryString($query, $client);
            if (!$patient) {
                throw new \RuntimeException('Patient not found in Cliniko.');
            }

            // 2️⃣ Booking match verification
            $booking = Booking::queryOneByQueryString(
                "?order=desc&sort=created_at&q[]=created_at:<={$bookedTime}",
                $client
            );
            if (!$booking || $booking->getPatient()->getId() !== $patient->getId()) {
                throw new \RuntimeException('Booking does not match patient record.');
            }

            // 3️⃣ Create PatientForm
            $tpl = PatientFormTemplate::find($templateId, $client);
            if (!$tpl) {
                throw new \RuntimeException('Patient form template not found.');
            }

            $dto = new CreatePatientFormDTO();
            $dto->completed = true;
            $dto->content_sections = $content;
            $dto->business_id = get_option('wp_cliniko_business_id');
            $dto->patient_id = $patient->getId();
            $dto->attendee_id = $patient->getId();
            $dto->patient_form_template_id = $templateId;
            $dto->email_to_patient_on_completion = true;
            $dto->name = sprintf('%s - %s (%s)', $tpl->getName(), "{$firstName} {$lastName}", gmdate('Y-m-d H:i'));

            $form = PatientForm::create($dto, $client);
            if (!$form) {
                throw new \RuntimeException('Failed to create PatientForm.');
            }

            // 4️⃣ Success notification
            $notifier->sendSuccess(
                [
                    'moduleId' => $moduleId,
                    'appointment_label' => 'Patient Form',
                    'currency' => 'AUD',
                ],
                [
                    'first_name' => "{$firstName} {$lastName}",
                    'email'      => $email,
                ],
                $form->getId(),
                null
            );

            // ✅ Clean up payload & lock
            delete_option($payloadKey);
            delete_transient($lockKey);

            error_log("[ClinikoPatientFormWorker] ✅ Successfully created PatientForm for {$email} ({$form->getId()}).");

        } catch (\Throwable $e) {

            // Evita envio duplo de e-mail (usa transient anti-spam)
            $failKey = 'wp_cliniko_pf_fail_' . md5($payloadKey);
            if (!get_transient($failKey)) {
                set_transient($failKey, 1, 3600); // 1 hora
                $notifier->sendFailure(
                    [
                        'moduleId' => $stored['moduleId'] ?? '',
                        'appointment_label' => 'Patient Form',
                    ],
                    [
                        'first_name' => "{$stored['patient']['first_name']} {$stored['patient']['last_name']}" ?? 'Unknown',
                        'email'      => $stored['patient']['email'] ?? '',
                    ],
                    'PF-' . ($stored['patient']['first_name'] ?? uniqid()),
                    null
                );
            }

            error_log('[ClinikoPatientFormWorker] ❌ ' . $e->getMessage());
            delete_transient($lockKey);
        }
    }
}
