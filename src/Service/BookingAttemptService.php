<?php

namespace App\Service;

use App\Admin\Modules\Credentials;
use App\DTO\CreatePatientDTO;
use App\DTO\CreatePatientFormDTO;
use App\Exception\ApiException;
use App\Model\AppointmentType;
use App\Model\AvailableTimes;
use App\Model\Booking;
use App\Model\IndividualAppointment;
use App\Model\Patient;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\Validator\AppointmentRequestValidator;
use App\Validator\PatientFormValidator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

if (!defined('ABSPATH')) {
    exit;
}

class BookingAttemptService
{
    private const CLEANUP_DELAY_SECONDS = 1800;
    private const ATTENDEE_RETRY_COUNT = 5;
    private const ATTENDEE_RETRY_DELAY_US = 400000;
    private const PATIENT_FORM_ATTACH_RETRY_COUNT = 6;
    private const PATIENT_FORM_ATTACH_RETRY_DELAY_US = 400000;

    private BookingAttemptStore $store;

    public function __construct(?BookingAttemptStore $store = null)
    {
        $this->store = $store ?: new BookingAttemptStore();
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function preflight(array $body): array
    {
        $gateway = strtolower(trim((string) ($body['gateway'] ?? ($body['payment']['gateway'] ?? ''))));
        $moduleId = trim((string) ($body['moduleId'] ?? ''));
        $templateId = trim((string) ($body['patient_form_template_id'] ?? ''));

        $patientRaw = is_array($body['patient'] ?? null)
            ? $body['patient']
            : json_decode((string) ($body['patient'] ?? '[]'), true);
        $contentRaw = is_array($body['content'] ?? null)
            ? $body['content']
            : json_decode((string) ($body['content'] ?? '[]'), true);
        $submissionTemplateBody = is_array($body['submission_template'] ?? null)
            ? $body['submission_template']
            : [];
        $headlessFieldsRaw = $body['headless_patient_fields']
            ?? ($submissionTemplateBody['headless_patient_fields'] ?? []);

        $patient = PatientSubmissionSanitizer::sanitize(is_array($patientRaw) ? $patientRaw : []);
        $content = PatientFormPayloadSanitizer::sanitizeContent($contentRaw);
        $headlessPatientFields = PatientCustomFieldService::normalizeDefinitions(
            is_array($headlessFieldsRaw) ? $headlessFieldsRaw : []
        );

        $validationPayload = [
            'moduleId' => $moduleId,
            'patient_form_template_id' => $templateId,
            'patient' => $patient,
            'content' => $content,
        ];

        $errors = AppointmentRequestValidator::validate($validationPayload, false);
        $errors = array_merge($errors, PatientFormValidator::validateContentSections($content));
        $errors = array_merge(
            $errors,
            PatientCustomFieldService::validate($patient, $headlessPatientFields)
        );

        $customFields = PatientCustomFieldService::buildCustomFields($patient, $headlessPatientFields);
        if ($customFields !== null) {
            $patient['custom_fields'] = $customFields;
        } else {
            unset($patient['custom_fields']);
        }

        if (empty(trim((string) ($patient['appointment_start'] ?? '')))) {
            $errors[] = [
                'field' => 'patient.appointment_start',
                'label' => 'Appointment Start',
                'code' => 'required',
                'detail' => 'Appointment time is required before payment.',
            ];
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                422,
                'Invalid request parameters.',
                [
                    'code' => 'preflight_validation_failed',
                    'detail' => $this->summarizeErrors($errors),
                    'errors' => $errors,
                ]
            );
        }

        $client = cliniko_client(false, Credentials::getClinikoApiCacheTtl());
        $apptType = AppointmentType::find($moduleId, $client);
        if (!$apptType) {
            return $this->errorResponse(
                404,
                'Appointment type not found.',
                [
                    'code' => 'appointment_type_not_found',
                    'field' => 'moduleId',
                    'detail' => sprintf('No Cliniko appointment type was found for moduleId "%s".', $moduleId),
                    'context' => ['moduleId' => $moduleId],
                ]
            );
        }

        $template = PatientFormTemplate::find($templateId, $client);
        if (!$template) {
            return $this->errorResponse(
                404,
                'Patient form template not found.',
                [
                    'code' => 'patient_form_template_not_found',
                    'field' => 'patient_form_template_id',
                    'detail' => sprintf('No Cliniko patient form template was found for id "%s".', $templateId),
                    'context' => ['patient_form_template_id' => $templateId],
                ]
            );
        }

        $amount = $apptType->getBillableItemsFinalPrice();
        if ($amount > 0 && !in_array($gateway, ['stripe', 'tyrohealth'], true)) {
            return $this->errorResponse(
                422,
                'Unsupported payment gateway.',
                [
                    'code' => 'unsupported_payment_gateway',
                    'field' => 'gateway',
                    'detail' => sprintf(
                        'This appointment type requires payment, but gateway "%s" is not supported. Expected "stripe" or "tyrohealth".',
                        $gateway === '' ? '[empty]' : $gateway
                    ),
                    'context' => ['gateway' => $gateway, 'amount' => $amount],
                ]
            );
        }

        $practitionerValidation = $this->validatePractitionerSelection((string) $patient['practitioner_id'], $apptType);
        if ($practitionerValidation !== null) {
            return $practitionerValidation;
        }

        $slotValidation = $this->validateSelectedAppointmentTime(
            (string) $patient['practitioner_id'],
            (string) $patient['appointment_start'],
            $moduleId,
            $client
        );
        if ($slotValidation !== null) {
            return $slotValidation;
        }

        $resolvedPatient = null;
        $patientCreated = false;
        $patientForm = null;

        try {
            [$resolvedPatient, $patientCreated] = $this->findOrCreatePatient($patient, $client);
            $patientForm = $this->createDraftPatientForm(
                $resolvedPatient->getId(),
                $templateId,
                $template->getName() ?: 'Patient Form',
                $content,
                $client
            );
        } catch (\Throwable $e) {
            if ($patientCreated && $resolvedPatient) {
                $this->archivePatientSafely((string) $resolvedPatient->getId(), $client);
            }

            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Cliniko rejected the booking data during preflight.',
                'code' => 'cliniko_preflight_rejected',
                'detail' => $this->describeThrowableForClient($e),
                'errors' => $this->extractThrowableErrors($e),
                'source' => 'cliniko',
            ];
        }

        $gatewayForAttempt = $amount > 0 ? $gateway : 'free';
        $invoiceReference = $gatewayForAttempt === 'tyrohealth'
            ? $this->buildTyroInvoiceReference((string) $apptType->getName(), (string) $patientForm->getId())
            : null;

        $attemptToken = PublicRequestGuard::issueAttemptToken();
        $attempt = $this->store->create([
            'status' => 'preflighted',
            'progress' => ['code' => 'preflighted', 'message' => 'Form validated with Cliniko.'],
            'module_id' => $moduleId,
            'patient_form_template_id' => $templateId,
            'appointment_label' => (string) $apptType->getName(),
            'gateway' => $gatewayForAttempt,
            'amount' => $amount,
            'currency' => 'aud',
            'patient' => $patient,
            'content' => $content,
            'patient_id' => (string) $resolvedPatient->getId(),
            'patient_was_created' => $patientCreated,
            'patient_form_id' => (string) $patientForm->getId(),
            'attempt_token_hash' => PublicRequestGuard::hashAttemptToken($attemptToken),
            'invoice_reference' => $invoiceReference,
            'payment' => [
                'status' => $amount > 0 ? 'pending' : 'skipped',
                'gateway' => $gatewayForAttempt,
                'reference' => null,
                'receipt_url' => null,
            ],
            'booking' => ['appointment_id' => null, 'attendee_id' => null],
        ]);

        (new \App\Infra\JobDispatcher())->enqueue(
            'cliniko_cleanup_booking_attempt',
            ['attempt_id' => $attempt['attempt_id']],
            self::CLEANUP_DELAY_SECONDS,
            $attempt['attempt_id']
        );

        return [
            'ok' => true,
            'status' => 200,
            'attempt' => [
                'id' => $attempt['attempt_id'],
                'status' => $attempt['status'],
                'token' => $attemptToken,
            ],
            'payment' => [
                'required' => $amount > 0,
                'gateway' => $gatewayForAttempt,
                'amount' => $amount,
                'currency' => 'aud',
                'invoice_reference' => $invoiceReference,
            ],
            'booking' => [
                'appointment_label' => (string) $apptType->getName(),
                'patient_form_id' => (string) $patientForm->getId(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function chargeStripe(string $attemptId, string $stripeToken): array
    {
        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return ['ok' => false, 'status' => 404, 'message' => 'Booking attempt not found.'];
        }

        if (($attempt['payment']['status'] ?? '') === 'verified') {
            return ['ok' => true, 'status' => 200, 'payment' => $attempt['payment']];
        }

        if ((int) ($attempt['amount'] ?? 0) <= 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'This booking attempt does not require payment.'];
        }

        if (!preg_match('/^(tok_|pm_)/', $stripeToken)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Invalid payment token.'];
        }

        $patient = is_array($attempt['patient'] ?? null) ? $attempt['patient'] : [];
        $stripeMeta = $patient;
        unset($stripeMeta['medicare'], $stripeMeta['medicare_reference_number']);
        $stripeMeta['booking_attempt_id'] = $attemptId;
        $stripeMeta['patient_form_id'] = (string) ($attempt['patient_form_id'] ?? '');
        $stripeMeta['module_id'] = (string) ($attempt['module_id'] ?? '');

        try {
            $charge = (new StripeService())->createChargeFromToken(
                $stripeToken,
                (int) $attempt['amount'],
                (string) ($attempt['appointment_label'] ?? ''),
                $stripeMeta,
                (string) ($patient['email'] ?? '')
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 402, 'message' => 'Payment failed.', 'detail' => $e->getMessage()];
        }

        if (empty($charge->id)) {
            return ['ok' => false, 'status' => 402, 'message' => 'Payment failed.'];
        }

        $payment = [
            'status' => 'verified',
            'gateway' => 'stripe',
            'reference' => (string) $charge->id,
            'receipt_url' => $charge->receipt_url ?? null,
            'amount' => (int) $attempt['amount'],
            'currency' => (string) ($attempt['currency'] ?? 'aud'),
            'card_last4' => $charge->payment_method_details->card->last4 ?? null,
            'brand' => $charge->payment_method_details->card->brand ?? null,
        ];

        $this->store->update($attemptId, [
            'status' => 'paid',
            'progress' => ['code' => 'payment_verified', 'message' => 'Payment confirmed.'],
            'payment' => $payment,
        ]);

        return ['ok' => true, 'status' => 200, 'payment' => $payment];
    }

    /**
     * @return array<string,mixed>
     */
    public function confirmTyroPayment(string $attemptId, string $transactionId): array
    {
        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return ['ok' => false, 'status' => 404, 'message' => 'Booking attempt not found.'];
        }

        if (($attempt['payment']['status'] ?? '') === 'verified') {
            return ['ok' => true, 'status' => 200, 'payment' => $attempt['payment']];
        }

        if ((int) ($attempt['amount'] ?? 0) <= 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'This booking attempt does not require payment.'];
        }

        try {
            $tyro = new TyroHealthService();
            $transaction = $tyro->fetchTransaction($transactionId);
            if (
                !$tyro->isPaidTransaction(
                    $transaction,
                    (int) $attempt['amount'],
                    (string) ($attempt['invoice_reference'] ?? '')
                )
            ) {
                return ['ok' => false, 'status' => 422, 'message' => 'Tyro payment could not be verified.'];
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Tyro payment could not be verified.',
                'detail' => $e->getMessage(),
            ];
        }

        $payment = [
            'status' => 'verified',
            'gateway' => 'tyrohealth',
            'reference' => $transactionId,
            'receipt_url' => null,
            'amount' => (int) $attempt['amount'],
            'currency' => (string) ($attempt['currency'] ?? 'aud'),
        ];

        $this->store->update($attemptId, [
            'status' => 'paid',
            'progress' => ['code' => 'payment_verified', 'message' => 'Payment confirmed.'],
            'payment' => $payment,
        ]);

        return ['ok' => true, 'status' => 200, 'payment' => $payment];
    }

    /**
     * @return array<string,mixed>
     */
    public function finalize(string $attemptId): array
    {
        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return ['ok' => false, 'status' => 404, 'message' => 'Booking attempt not found.'];
        }

        if (($attempt['status'] ?? '') === 'completed') {
            return [
                'ok' => true,
                'status' => 200,
                'result' => [
                    'booking' => $attempt['booking'] ?? [],
                    'payment' => $attempt['payment'] ?? [],
                    'patient_form_id' => $attempt['patient_form_id'] ?? null,
                ],
            ];
        }

        $paymentStatus = (string) (($attempt['payment']['status'] ?? 'pending'));
        if (!in_array($paymentStatus, ['verified', 'skipped'], true)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Payment has not been verified for this attempt.'];
        }

        $client = cliniko_client(false);
        $patient = is_array($attempt['patient'] ?? null) ? $attempt['patient'] : [];
        $patientId = trim((string) ($attempt['patient_id'] ?? ''));
        $patientFormId = trim((string) ($attempt['patient_form_id'] ?? ''));
        $moduleId = trim((string) ($attempt['module_id'] ?? ''));
        $templateId = trim((string) ($attempt['patient_form_template_id'] ?? ''));

        $this->store->update($attemptId, [
            'status' => 'finalizing',
            'progress' => ['code' => 'making_appointment', 'message' => 'Making appointment.'],
        ]);

        try {
            if ($patientId === '' || $patientFormId === '' || $moduleId === '' || $templateId === '') {
                throw new \RuntimeException('Booking attempt is incomplete.');
            }

            Patient::update($patientId, $patient, $client);

            $apptType = AppointmentType::find($moduleId, $client);
            if (!$apptType) {
                throw new \RuntimeException('Appointment type not found.');
            }

            $businessId = (string) get_option('wp_cliniko_business_id');
            if ($businessId === '') {
                throw new \RuntimeException('Cliniko business ID is not configured.');
            }

            $start = new DateTimeImmutable((string) ($patient['appointment_start'] ?? ''));
            $end = $start->add(new DateInterval('PT' . $apptType->getDurationInMinutes() . 'M'));

            $appointment = IndividualAppointment::create([
                'appointment_type_id' => $apptType->getId(),
                'business_id' => $businessId,
                'starts_at' => $start->format(DATE_ATOM),
                'ends_at' => $end->format(DATE_ATOM),
                'patient_id' => $patientId,
                'practitioner_id' => (string) ($patient['practitioner_id'] ?? ''),
            ], $client);

            if (!$appointment) {
                throw new \RuntimeException('Failed to create appointment.');
            }

            $appointmentId = (string) $appointment->getId();
            $this->store->update($attemptId, [
                'booking' => ['appointment_id' => $appointmentId],
                'progress' => ['code' => 'linking_form', 'message' => 'Sending your form to the doctor.'],
            ]);

            $attendeeId = $this->resolveAttendeeIdWithRetry($appointmentId, $client);
            if ($attendeeId === null) {
                throw new \RuntimeException('Unable to resolve appointment attendee.');
            }

            $template = PatientFormTemplate::find($templateId, $client);
            $appointmentLabel = $start
                ->setTimezone(function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC'))
                ->format('F j, Y \a\t g:i A (T)');

            PatientForm::patch($patientFormId, [
                'attendee_id' => $attendeeId,
                'name' => sprintf('%s - Appointment on %s', $template?->getName() ?: 'Patient Form', $appointmentLabel),
                'email_to_patient_on_completion' => true,
            ], $client);

            if (!$this->verifyPatientFormAttachment($patientFormId, $attendeeId, $client)) {
                throw new \RuntimeException('Patient form attachment could not be verified.');
            }

            $finalAttempt = $this->store->update($attemptId, [
                'status' => 'completed',
                'progress' => ['code' => 'completed', 'message' => 'Appointment confirmed.'],
                'booking' => ['appointment_id' => $appointmentId, 'attendee_id' => $attendeeId],
            ]);

            return [
                'ok' => true,
                'status' => 200,
                'result' => [
                    'booking' => $finalAttempt['booking'] ?? [],
                    'payment' => $finalAttempt['payment'] ?? [],
                    'patient_form_id' => $patientFormId,
                ],
            ];
        } catch (\Throwable $e) {
            $failedAttempt = $this->store->update($attemptId, [
                'status' => 'failed',
                'progress' => ['code' => 'failed', 'message' => 'We could not complete your booking.'],
                'error' => substr($e->getMessage(), 0, 500),
            ]);

            $this->refundAndCleanupFailedAttempt($failedAttempt ?: $attempt, $client);

            return [
                'ok' => false,
                'status' => 500,
                'message' => 'We could not complete your booking.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getStatus(string $attemptId): array
    {
        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return ['ok' => false, 'status' => 404, 'message' => 'Booking attempt not found.'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'attempt' => [
                'id' => $attempt['attempt_id'],
                'status' => $attempt['status'] ?? 'unknown',
                'progress' => $attempt['progress'] ?? [],
                'payment' => $attempt['payment'] ?? [],
                'booking' => $attempt['booking'] ?? [],
                'error' => $attempt['error'] ?? null,
            ],
        ];
    }

    public function cleanupAbandonedAttempt(string $attemptId): void
    {
        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return;
        }

        $status = (string) ($attempt['status'] ?? '');
        if (in_array($status, ['completed', 'cleaned'], true)) {
            return;
        }

        $client = cliniko_client(false);
        $payment = is_array($attempt['payment'] ?? null) ? $attempt['payment'] : [];
        $paymentStatus = (string) ($payment['status'] ?? 'pending');
        $paymentGateway = (string) ($payment['gateway'] ?? '');
        $paymentRef = trim((string) ($payment['reference'] ?? ''));
        $refundStatus = null;

        if ($paymentStatus === 'verified' && $paymentGateway === 'stripe' && $paymentRef !== '') {
            try {
                (new StripeService())->refundCharge(
                    $paymentRef,
                    isset($attempt['amount']) ? (int) $attempt['amount'] : null,
                    'requested_by_customer',
                    ['booking_attempt_id' => $attemptId, 'failure' => 'attempt_abandoned']
                );
                $refundStatus = 'refunded';
            } catch (\Throwable $e) {
                $refundStatus = 'refund_failed';
            }
        }

        $patientFormArchived = false;
        $patientArchived = false;

        $patientFormId = trim((string) ($attempt['patient_form_id'] ?? ''));
        if ($patientFormId !== '') {
            $patientFormArchived = $this->archivePatientFormSafely($patientFormId, $client);
        }

        $patientId = trim((string) ($attempt['patient_id'] ?? ''));
        $patientWasCreated = !empty($attempt['patient_was_created']);
        $appointmentId = trim((string) ($attempt['booking']['appointment_id'] ?? ''));
        if ($patientWasCreated && $patientId !== '' && $appointmentId === '') {
            $patientArchived = $this->archivePatientSafely($patientId, $client);
        }

        $this->store->update($attemptId, [
            'status' => 'cleaned',
            'progress' => ['code' => 'cleaned', 'message' => 'Abandoned checkout cleaned up.'],
            'payment' => $refundStatus ? ['status' => $refundStatus] : [],
            'cleanup' => [
                'patient_form_archived' => $patientFormArchived,
                'patient_archived' => $patientArchived,
                'cleaned_at' => gmdate(DATE_ATOM),
            ],
        ]);
    }

    /**
     * @return array{0:Patient,1:bool}
     */
    private function findOrCreatePatient(array $patient, $client): array
    {
        $query = '?q[]=email:=' . rawurlencode((string) ($patient['email'] ?? ''))
            . '&q[]=first_name:=' . rawurlencode((string) ($patient['first_name'] ?? ''))
            . '&q[]=last_name:=' . rawurlencode((string) ($patient['last_name'] ?? ''));

        $existing = Patient::queryOneByQueryString($query, $client);
        if ($existing) {
            return [$existing, false];
        }

        $dto = new CreatePatientDTO();
        $dto->firstName = (string) ($patient['first_name'] ?? '');
        $dto->lastName = (string) ($patient['last_name'] ?? '');
        $dto->email = (string) ($patient['email'] ?? '');
        $dto->address1 = $patient['address_1'] ?? null;
        $dto->address2 = $patient['address_2'] ?? null;
        $dto->city = $patient['city'] ?? null;
        $dto->state = $patient['state'] ?? null;
        $dto->postCode = $patient['post_code'] ?? null;
        $dto->country = $patient['country'] ?? null;
        $dto->dateOfBirth = $patient['date_of_birth'] ?? null;
        $dto->medicare = $patient['medicare'] ?? null;
        $dto->medicareReferenceNumber = $patient['medicare_reference_number'] ?? null;
        if (is_array($patient['custom_fields'] ?? null)) {
            $dto->customFields = $patient['custom_fields'];
        }
        $dto->patientPhoneNumbers = [['number' => (string) ($patient['phone'] ?? ''), 'phone_type' => 'Home']];
        $dto->acceptedPrivacyPolicy = true;

        $created = Patient::create($dto, $client);
        if (!$created) {
            throw new \RuntimeException('Unable to create patient.');
        }

        return [$created, true];
    }

    private function createDraftPatientForm(string $patientId, string $templateId, string $templateName, array $content, $client): PatientForm
    {
        $dto = new CreatePatientFormDTO();
        $dto->completed = true;
        $dto->content_sections = $content;
        $dto->business_id = (string) get_option('wp_cliniko_business_id');
        $dto->patient_form_template_id = $templateId;
        $dto->patient_id = $patientId;
        $dto->email_to_patient_on_completion = false;
        $dto->name = sprintf('%s - Pending appointment', $templateName);

        $patientForm = PatientForm::create($dto, $client);
        if (!$patientForm) {
            throw new \RuntimeException('Failed to create patient form.');
        }

        return $patientForm;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function validatePractitionerSelection(string $practitionerId, AppointmentType $apptType): ?array
    {
        $practitioners = $apptType->getPractitioners();
        if (empty($practitioners)) {
            return null;
        }

        foreach ($practitioners as $practitioner) {
            if ((string) $practitioner->getId() === $practitionerId) {
                return null;
            }
        }

        return $this->errorResponse(
            422,
            'Selected practitioner is not valid for this appointment type.',
            [
                'code' => 'invalid_practitioner_for_appointment_type',
                'field' => 'patient.practitioner_id',
                'detail' => sprintf(
                    'Practitioner "%s" is not linked to appointment type "%s".',
                    $practitionerId,
                    (string) $apptType->getId()
                ),
                'context' => [
                    'patient.practitioner_id' => $practitionerId,
                    'moduleId' => (string) $apptType->getId(),
                    'allowed_practitioner_ids' => array_map(
                        static fn($practitioner) => (string) $practitioner->getId(),
                        $practitioners
                    ),
                ],
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function validateSelectedAppointmentTime(string $practitionerId, string $appointmentStart, string $moduleId, $client): ?array
    {
        $dateKey = substr($appointmentStart, 0, 10);
        if ($dateKey === '') {
            return $this->errorResponse(
                422,
                'Appointment date is invalid.',
                [
                    'code' => 'invalid_appointment_start',
                    'field' => 'patient.appointment_start',
                    'detail' => sprintf('Appointment start "%s" could not be parsed into a booking date.', $appointmentStart),
                    'context' => ['patient.appointment_start' => $appointmentStart],
                ]
            );
        }

        $businessId = (string) get_option('wp_cliniko_business_id');
        if ($businessId === '') {
            return $this->errorResponse(
                400,
                'Cliniko business ID is not configured.',
                [
                    'code' => 'cliniko_business_not_configured',
                    'detail' => 'The backend could not validate availability because wp_cliniko_business_id is empty.',
                ]
            );
        }

        $page = AvailableTimes::findForPractitionerAppointmentType(
            $businessId,
            $practitionerId,
            $moduleId,
            $dateKey,
            $dateKey,
            $client,
            1,
            100
        );

        if (!$page) {
            return $this->errorResponse(
                422,
                'Could not verify the selected appointment time.',
                [
                    'code' => 'appointment_time_verification_failed',
                    'field' => 'patient.appointment_start',
                    'detail' => sprintf(
                        'Cliniko returned no availability page for practitioner "%s", appointment type "%s", and date "%s".',
                        $practitionerId,
                        $moduleId,
                        $dateKey
                    ),
                    'context' => [
                        'patient.practitioner_id' => $practitionerId,
                        'moduleId' => $moduleId,
                        'patient.appointment_start' => $appointmentStart,
                        'appointment_date' => $dateKey,
                    ],
                ]
            );
        }

        do {
            if (in_array($appointmentStart, $page->getAppointmentStartStrings(), true)) {
                return null;
            }
            $page = $page->getNextPage();
        } while ($page);

        return $this->errorResponse(
            422,
            'The selected appointment time is no longer available.',
            [
                'code' => 'appointment_time_unavailable',
                'field' => 'patient.appointment_start',
                'detail' => sprintf(
                    'Appointment start "%s" was not found in Cliniko availability for practitioner "%s" and appointment type "%s".',
                    $appointmentStart,
                    $practitionerId,
                    $moduleId
                ),
                'context' => [
                    'patient.practitioner_id' => $practitionerId,
                    'moduleId' => $moduleId,
                    'patient.appointment_start' => $appointmentStart,
                    'appointment_date' => $dateKey,
                ],
            ]
        );
    }

    private function buildTyroInvoiceReference(string $label, string $patientFormId): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(substr($label, 0, 10))) ?: 'BOOKING';
        return substr($prefix . '-' . strtoupper(substr($patientFormId, 0, 12)), 0, 32);
    }

    private function resolveAttendeeIdWithRetry(string $appointmentId, $client): ?string
    {
        for ($attempt = 0; $attempt < self::ATTENDEE_RETRY_COUNT; $attempt++) {
            $booking = Booking::find($appointmentId, $client);
            if ($booking) {
                $attendees = $booking->getAttendees();
                if (!empty($attendees) && !empty($attendees[0])) {
                    return (string) $attendees[0]->getId();
                }
            }

            if ($attempt < self::ATTENDEE_RETRY_COUNT - 1) {
                usleep(self::ATTENDEE_RETRY_DELAY_US);
            }
        }

        return null;
    }

    private function verifyPatientFormAttachment(string $patientFormId, string $attendeeId, $client): bool
    {
        for ($attempt = 0; $attempt < self::PATIENT_FORM_ATTACH_RETRY_COUNT; $attempt++) {
            $response = $client->get('patient_forms/' . $patientFormId);
            $data = is_array($response->data) ? $response->data : [];
            $linkedAttendeeId = $this->extractLinkedResourceId($data['attendee']['links']['self'] ?? null)
                ?? trim((string) ($data['attendee_id'] ?? ''));

            if ($linkedAttendeeId !== '' && $linkedAttendeeId === $attendeeId) {
                return true;
            }

            if ($attempt < self::PATIENT_FORM_ATTACH_RETRY_COUNT - 1) {
                usleep(self::PATIENT_FORM_ATTACH_RETRY_DELAY_US);
            }
        }

        return false;
    }

    private function extractLinkedResourceId($url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($segments)) {
            return null;
        }

        return (string) end($segments);
    }

    /**
     * @param array<string,mixed> $attempt
     */
    private function refundAndCleanupFailedAttempt(array $attempt, $client): void
    {
        $payment = is_array($attempt['payment'] ?? null) ? $attempt['payment'] : [];
        $paymentGateway = (string) ($payment['gateway'] ?? '');
        $paymentRef = trim((string) ($payment['reference'] ?? ''));
        $amount = isset($attempt['amount']) ? (int) $attempt['amount'] : null;

        if ($paymentGateway === 'stripe' && $paymentRef !== '') {
            try {
                (new StripeService())->refundCharge(
                    $paymentRef,
                    $amount,
                    'requested_by_customer',
                    ['booking_attempt_id' => (string) ($attempt['attempt_id'] ?? ''), 'failure' => 'booking_finalize_failed']
                );
                $this->store->update((string) $attempt['attempt_id'], ['payment' => ['status' => 'refunded']]);
            } catch (\Throwable $e) {
                $this->store->update((string) $attempt['attempt_id'], ['payment' => ['status' => 'refund_failed']]);
            }
        }

        $patientFormId = trim((string) ($attempt['patient_form_id'] ?? ''));
        $attendeeId = trim((string) ($attempt['booking']['attendee_id'] ?? ''));
        if ($patientFormId !== '' && $attendeeId === '') {
            $this->archivePatientFormSafely($patientFormId, $client);
        }
    }

    private function archivePatientFormSafely(string $patientFormId, $client): bool
    {
        try {
            return PatientForm::delete($patientFormId, $client);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function archivePatientSafely(string $patientId, $client): bool
    {
        try {
            return Patient::delete($patientId, $client);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function errorResponse(int $status, string $message, array $extra = []): array
    {
        return array_merge(
            [
                'ok' => false,
                'status' => $status,
                'message' => $message,
            ],
            $extra
        );
    }

    /**
     * @param array<int,array<string,mixed>> $errors
     */
    private function summarizeErrors(array $errors): string
    {
        if (empty($errors)) {
            return 'Validation failed.';
        }

        $parts = [];
        foreach (array_slice($errors, 0, 3) as $error) {
            $label = trim((string) ($error['label'] ?? $error['field'] ?? 'Field'));
            $detail = trim((string) ($error['detail'] ?? $error['code'] ?? 'Invalid value.'));
            $parts[] = $label . ': ' . $detail;
        }

        if (count($errors) > 3) {
            $parts[] = '+' . (count($errors) - 3) . ' more issue(s).';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractThrowableErrors(\Throwable $e): array
    {
        if (!$e instanceof ApiException) {
            return [];
        }

        $context = $e->getContext();
        $responseErrors = $context['response_data']['errors'] ?? $context['errors'] ?? null;
        return $this->normalizeApiErrors($responseErrors);
    }

    private function describeThrowableForClient(\Throwable $e): string
    {
        $errors = $this->extractThrowableErrors($e);
        if (!empty($errors)) {
            return $this->summarizeErrors($errors);
        }

        if ($e instanceof ApiException) {
            $context = $e->getContext();
            $statusCode = (int) ($context['status_code'] ?? 0);

            if ($statusCode >= 500) {
                return "Cliniko returned HTTP {$statusCode} while validating the form.";
            }

            $responseMessage = trim((string) ($context['response_data']['message'] ?? ''));
            if ($responseMessage !== '') {
                return $responseMessage;
            }

            $error = trim((string) ($context['error'] ?? ''));
            if ($error !== '' && strpos($error, '<') === false) {
                return $error;
            }
        }

        return $e->getMessage();
    }

    /**
     * @param mixed $errors
     * @return array<int,array<string,mixed>>
     */
    private function normalizeApiErrors($errors, string $prefix = ''): array
    {
        if (!is_array($errors)) {
            return [];
        }

        $normalized = [];
        foreach ($errors as $field => $value) {
            $currentField = trim($prefix . (string) $field, '.');

            if (is_array($value)) {
                $nested = $this->normalizeApiErrors($value, $currentField . '.');
                if (!empty($nested)) {
                    $normalized = array_merge($normalized, $nested);
                    continue;
                }

                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $normalized[] = [
                    'field' => $currentField,
                    'label' => $this->humanizeField($currentField),
                    'code' => 'invalid',
                    'detail' => is_string($encoded) ? $encoded : 'Invalid value.',
                ];
                continue;
            }

            $detail = trim((string) $value);
            if ($detail === '') {
                continue;
            }

            $normalized[] = [
                'field' => $currentField,
                'label' => $this->humanizeField($currentField),
                'code' => 'invalid',
                'detail' => $detail,
            ];
        }

        return $normalized;
    }

    private function humanizeField(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return 'Field';
        }

        $last = preg_replace('/[\[\]_.]+/', ' ', $field);
        $last = preg_replace('/\s+/', ' ', (string) $last);
        return ucwords(trim((string) $last));
    }
}
