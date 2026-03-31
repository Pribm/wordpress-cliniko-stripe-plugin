<?php

namespace App\Service;

use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccessService
{
    private const DEFAULT_HISTORY_LIMIT = 5;
    private const MAX_HISTORY_LIMIT = 10;
    private const MAX_PATIENT_MATCHES = 5;

    private NotificationService $notifications;
    private PatientAccessTokenService $tokens;
    private PatientAccessRequestStateService $requestStates;

    public function __construct(
        ?NotificationService $notifications = null,
        ?PatientAccessTokenService $tokens = null,
        ?PatientAccessRequestStateService $requestStates = null
    ) {
        $this->notifications = $notifications ?: new NotificationService();
        $this->tokens = $tokens ?: new PatientAccessTokenService();
        $this->requestStates = $requestStates ?: new PatientAccessRequestStateService($this->tokens);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function requestAccessCode(array $body): array
    {
        $email = $this->tokens->normalizeEmail((string) ($body['email'] ?? ''));
        $appointmentTypeId = trim((string) ($body['appointment_type_id'] ?? $body['module_id'] ?? ''));
        $rawRequestId = trim((string) ($body['request_id'] ?? ''));
        $requestId = $rawRequestId !== '' ? $this->requestStates->normalizeRequestId($rawRequestId) : '';

        if ($email === '' || (function_exists('is_email') && !is_email($email))) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Please provide a valid email address.',
            ];
        }

        if ($appointmentTypeId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Appointment type is required.',
            ];
        }

        if ($rawRequestId !== '' && $requestId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Invalid patient access request. Reload the page and try again.',
            ];
        }

        $client = cliniko_client(false);
        $patientIds = $this->findPatientIdsByEmail($email, $client);
        $latestBookingContext = !empty($patientIds)
            ? $this->findLatestBookingContext($patientIds, $client, $appointmentTypeId)
            : null;
        $challenge = $latestBookingContext !== null
            ? $this->tokens->issueChallenge($email, $appointmentTypeId, $patientIds, $latestBookingContext)
            : [
                'challenge_token' => '',
                'code' => '',
                'expires_in' => $this->tokens->challengeTtl(),
            ];

        if ($latestBookingContext !== null && $challenge['challenge_token'] === '') {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Verification is temporarily unavailable.',
            ];
        }

        if ($latestBookingContext !== null) {
            $ttlSeconds = max(1, (int) $challenge['expires_in']);
            $ttlMinutes = max(1, (int) ceil($ttlSeconds / 60));
            $appointmentLabel = trim((string) ($latestBookingContext['appointment_label'] ?? ''));
            $code = trim((string) $challenge['code']);

            $message = sprintf(
                'Your verification code for your most recent completed <strong>%s</strong> booking is <strong style="font-size:24px;letter-spacing:0.12em;">%s</strong>. It expires in %d minutes.<br><br>Enter this code on the form to continue.<br><br>If you did not request this code, you can ignore this email.',
                esc_html($appointmentLabel !== '' ? $appointmentLabel : 'appointment'),
                esc_html($code),
                $ttlMinutes
            );

            $sent = $this->notifications->sendGenericEmail(
                $email,
                'Your appointment access code',
                $message,
                'success'
            );

            if (!$sent) {
                return [
                    'ok' => false,
                    'status' => 502,
                    'message' => 'We could not send the verification code.',
                ];
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'If matching completed appointments exist for this booking type, we emailed a 6-digit code. Enter it below to continue.',
            'challenge_token' => (string) $challenge['challenge_token'],
            'expires_in' => max(1, (int) $challenge['expires_in']),
            'request_id' => $requestId,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function requestAccessLink(array $body): array
    {
        return $this->requestAccessCode($body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function verifyAccessCode(array $body): array
    {
        $email = $this->tokens->normalizeEmail((string) ($body['email'] ?? ''));
        $code = preg_replace('/\D+/', '', (string) ($body['code'] ?? ''));
        $challengeToken = trim((string) ($body['challenge_token'] ?? ''));

        if ($email === '' || (function_exists('is_email') && !is_email($email))) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Please provide a valid email address.',
            ];
        }

        if (!preg_match('/^\d{6}$/', (string) $code)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Please enter the 6-digit code sent to your email.',
            ];
        }

        $challengeClaims = $challengeToken !== ''
            ? $this->tokens->verifyChallenge($challengeToken, $email, (string) $code)
            : null;

        if ($challengeClaims === null) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'The verification code is invalid or has expired.',
            ];
        }

        $appointmentTypeId = trim((string) ($challengeClaims['appointment_type_id'] ?? ''));
        $patientIds = $this->tokens->normalizePatientIds(
            is_array($challengeClaims['patient_ids'] ?? null) ? $challengeClaims['patient_ids'] : []
        );
        if ($appointmentTypeId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Appointment type is required.',
            ];
        }

        if (empty($patientIds)) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'The verification code is invalid or has expired.',
            ];
        }

        $accessToken = $this->tokens->issue(
            $email,
            $appointmentTypeId,
            $patientIds,
            [
                'booking_id' => (string) ($challengeClaims['latest_booking_id'] ?? ''),
                'patient_id' => (string) ($challengeClaims['latest_patient_id'] ?? ''),
                'starts_at' => (string) ($challengeClaims['latest_starts_at'] ?? ''),
                'appointment_label' => (string) ($challengeClaims['latest_appointment_label'] ?? ''),
                'practitioner_id' => (string) ($challengeClaims['latest_practitioner_id'] ?? ''),
                'practitioner_name' => (string) ($challengeClaims['latest_practitioner_name'] ?? ''),
            ]
        );
        if ($accessToken === '') {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'We could not complete verification.',
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Verification successful.',
            'access_token' => $accessToken,
            'expires_in' => $this->tokens->ttl(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getRequestStatus(string $requestId): array
    {
        return $this->requestStates->getStatus($requestId, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function completeRequest(string $requestId, string $accessToken): array
    {
        return $this->requestStates->complete($requestId, $accessToken);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getHistory(
        string $email,
        int $limit = self::DEFAULT_HISTORY_LIMIT,
        string $appointmentTypeId = '',
        array $patientIds = []
    ): array
    {
        return $this->loadHistoryItems(
            $this->tokens->normalizeEmail($email),
            $limit,
            trim($appointmentTypeId),
            $this->tokens->normalizePatientIds($patientIds)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPrefill(
        string $email,
        string $bookingId,
        string $appointmentTypeId = '',
        array $patientIds = []
    ): ?array
    {
        $context = $this->loadAuthorizedBookingContext(
            $this->tokens->normalizeEmail($email),
            $bookingId,
            trim($appointmentTypeId),
            $this->tokens->normalizePatientIds($patientIds)
        );
        if ($context === null) {
            return null;
        }

        $patientData = $this->fetchPatientData($context['patient_id'], $context['client']);
        if ($patientData === null) {
            return null;
        }

        $latestForm = $context['attendee_row'] !== null
            ? $this->fetchLatestPatientFormForAttendeeRow($context['attendee_row'], $context['client'], true)
            : null;
        $patientForms = $latestForm !== null ? [$latestForm] : [];

        return [
            'appointment' => $context['history_item'],
            'forms' => $patientForms,
            'latest_form' => $latestForm,
            'prefill' => [
                'patient' => $this->buildPatientPrefill($patientData, $context['history_item']),
                'content' => [
                    'sections' => $latestForm['content_sections'] ?? [],
                ],
                'history' => [
                    'booking_id' => $context['history_item']['booking_id'],
                    'appointment_id' => $context['history_item']['appointment_id'],
                    'patient_form_id' => $latestForm['id'] ?? '',
                    'starts_at' => $context['history_item']['starts_at'],
                    'appointment_label' => $context['history_item']['appointment_label'],
                    'appointment_type_id' => $context['history_item']['appointment_type_id'],
                    'practitioner_id' => $context['history_item']['practitioner_id'],
                    'practitioner_name' => $context['history_item']['practitioner_name'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLatestPrefill(
        string $email,
        string $appointmentTypeId = '',
        array $patientIds = [],
        array $latestContext = []
    ): ?array
    {
        $normalizedEmail = $this->tokens->normalizeEmail($email);
        $normalizedAppointmentTypeId = trim($appointmentTypeId);
        $normalizedPatientIds = $this->tokens->normalizePatientIds($patientIds);
        $normalizedLatestContext = [
            'booking_id' => trim((string) ($latestContext['booking_id'] ?? '')),
            'patient_id' => trim((string) ($latestContext['patient_id'] ?? '')),
            'starts_at' => trim((string) ($latestContext['starts_at'] ?? '')),
            'appointment_label' => trim((string) ($latestContext['appointment_label'] ?? '')),
            'practitioner_id' => trim((string) ($latestContext['practitioner_id'] ?? '')),
            'practitioner_name' => trim((string) ($latestContext['practitioner_name'] ?? '')),
        ];

        $context = $normalizedLatestContext['booking_id'] !== '' && $normalizedLatestContext['patient_id'] !== ''
            ? $this->loadStoredLatestAuthorizedContext(
                $normalizedAppointmentTypeId,
                $normalizedPatientIds,
                $normalizedLatestContext
            )
            : null;

        if ($context === null) {
            $context = $this->loadLatestAuthorizedBookingContext(
                $normalizedEmail,
                $normalizedAppointmentTypeId,
                $normalizedPatientIds
            );
        }
        if ($context === null) {
            return null;
        }

        $patientData = $this->fetchPatientData($context['patient_id'], $context['client']);
        if ($patientData === null) {
            return null;
        }

        $latestForm = $context['attendee_row'] !== null
            ? $this->fetchLatestPatientFormForAttendeeRow($context['attendee_row'], $context['client'], true)
            : null;
        $patientForms = $latestForm !== null ? [$latestForm] : [];

        return [
            'appointment' => $context['history_item'],
            'forms' => $patientForms,
            'latest_form' => $latestForm,
            'prefill' => [
                'patient' => $this->buildPatientPrefill($patientData, $context['history_item']),
                'content' => [
                    'sections' => $latestForm['content_sections'] ?? [],
                ],
                'history' => [
                    'booking_id' => $context['history_item']['booking_id'],
                    'appointment_id' => $context['history_item']['appointment_id'],
                    'patient_form_id' => $latestForm['id'] ?? '',
                    'starts_at' => $context['history_item']['starts_at'],
                    'appointment_label' => $context['history_item']['appointment_label'],
                    'appointment_type_id' => $context['history_item']['appointment_type_id'],
                    'practitioner_id' => $context['history_item']['practitioner_id'],
                    'practitioner_name' => $context['history_item']['practitioner_name'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadHistoryItems(
        string $email,
        int $limit,
        string $appointmentTypeId = '',
        array $patientIds = []
    ): array
    {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '' || ($email === '' && empty($patientIds))) {
            return [];
        }

        $limit = max(1, min(self::MAX_HISTORY_LIMIT, $limit));
        $client = cliniko_client(false);
        $resolvedPatientIds = !empty($patientIds)
            ? $this->tokens->normalizePatientIds($patientIds)
            : $this->findPatientIdsByEmail($email, $client);
        if (empty($resolvedPatientIds)) {
            return [];
        }

        $bookingRows = $this->loadBookingRowsForPatients(
            $resolvedPatientIds,
            $client,
            $appointmentTypeId,
            max(8, min(40, $limit * 4))
        );
        if (empty($bookingRows)) {
            return [];
        }

        $items = [];
        foreach ($bookingRows as $bookingRow) {
            $item = $this->buildHistoryItemFromBookingRow($bookingRow);
            $items[$item['booking_id']] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        usort($items, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['starts_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['starts_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadAuthorizedBookingContext(
        string $email,
        string $bookingId,
        string $appointmentTypeId = '',
        array $patientIds = []
    ): ?array
    {
        $appointmentTypeId = trim($appointmentTypeId);
        if (trim($bookingId) === '' || $appointmentTypeId === '') {
            return null;
        }

        $client = cliniko_client(false);
        $resolvedPatientIds = !empty($patientIds)
            ? $this->tokens->normalizePatientIds($patientIds)
            : $this->findPatientIdsByEmail($email, $client);
        if (empty($resolvedPatientIds)) {
            return null;
        }

        $bookingRow = $this->fetchBookingRowById($bookingId, $client);
        if (empty($bookingRow) || !$this->isEligibleBookingRow($bookingRow, $appointmentTypeId)) {
            return null;
        }

        $patientId = $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['patient_id'] ?? ''));
        $attendeeRow = $this->findEligibleAttendeeRowForBooking($bookingRow, $resolvedPatientIds, $client);
        if ($patientId === '' && $attendeeRow !== null) {
            $patientId = $this->extractLinkedResourceId($attendeeRow['patient']['links']['self'] ?? null)
                ?? trim((string) ($attendeeRow['patient_id'] ?? ''));
        }

        if ($patientId === '' || !in_array($patientId, $resolvedPatientIds, true)) {
            return null;
        }

        $historyItem = $this->buildHistoryItemFromBookingRow($bookingRow);

        if (!hash_equals($appointmentTypeId, (string) ($historyItem['appointment_type_id'] ?? ''))) {
            return null;
        }

        return [
            'client' => $client,
            'attendee_row' => $attendeeRow,
            'patient_id' => $patientId,
            'history_item' => $historyItem,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadLatestAuthorizedBookingContext(
        string $email,
        string $appointmentTypeId = '',
        array $patientIds = []
    ): ?array
    {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '') {
            return null;
        }

        $client = cliniko_client(false);
        $resolvedPatientIds = !empty($patientIds)
            ? $this->tokens->normalizePatientIds($patientIds)
            : $this->findPatientIdsByEmail($email, $client);
        if (empty($resolvedPatientIds)) {
            return null;
        }

        $bookingRows = $this->loadBookingRowsForPatients(
            $resolvedPatientIds,
            $client,
            $appointmentTypeId,
            1
        );
        $bookingRow = is_array($bookingRows[0] ?? null) ? $bookingRows[0] : null;
        if ($bookingRow === null || !$this->isEligibleBookingRow($bookingRow, $appointmentTypeId)) {
            return null;
        }

        $patientId = $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['patient_id'] ?? ''));
        $attendeeRow = $this->findEligibleAttendeeRowForBooking($bookingRow, $resolvedPatientIds, $client);
        if ($patientId === '' && $attendeeRow !== null) {
            $patientId = $this->extractLinkedResourceId($attendeeRow['patient']['links']['self'] ?? null)
                ?? trim((string) ($attendeeRow['patient_id'] ?? ''));
        }

        if ($patientId === '' || !in_array($patientId, $resolvedPatientIds, true)) {
            return null;
        }

        $historyItem = $this->buildHistoryItemFromBookingRow($bookingRow);

        if (!hash_equals($appointmentTypeId, (string) ($historyItem['appointment_type_id'] ?? ''))) {
            return null;
        }

        return [
            'client' => $client,
            'attendee_row' => $attendeeRow,
            'patient_id' => $patientId,
            'history_item' => $historyItem,
        ];
    }

    /**
     * @param array<int,string> $patientIds
     * @return array<string,mixed>|null
     */
    private function loadStoredLatestAuthorizedContext(
        string $appointmentTypeId,
        array $patientIds,
        array $latestContext
    ): ?array
    {
        $bookingId = trim((string) ($latestContext['booking_id'] ?? ''));
        $patientId = trim((string) ($latestContext['patient_id'] ?? ''));
        if ($appointmentTypeId === '' || $bookingId === '' || $patientId === '') {
            return null;
        }

        if (!empty($patientIds) && !in_array($patientId, $patientIds, true)) {
            return null;
        }

        $client = cliniko_client(false);
        $attendeeRow = $this->findEligibleAttendeeRowForBookingAndPatient($bookingId, $patientId, $client);
        if ($attendeeRow === null) {
            return null;
        }

        return [
            'client' => $client,
            'attendee_row' => $attendeeRow,
            'patient_id' => $patientId,
            'history_item' => $this->buildHistoryItemFromStoredLatestContext(
                $bookingId,
                $appointmentTypeId,
                $latestContext
            ),
        ];
    }

    /**
     * @param array<int,string> $patientIds
     * @return array<string,mixed>|null
     */
    private function findLatestBookingContext(
        array $patientIds,
        $client,
        string $appointmentTypeId
    ): ?array {
        $bookingRows = $this->loadBookingRowsForPatients(
            $patientIds,
            $client,
            trim($appointmentTypeId),
            1
        );
        $bookingRow = is_array($bookingRows[0] ?? null) ? $bookingRows[0] : null;
        if ($bookingRow === null) {
            return null;
        }

        $patientId = $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['patient_id'] ?? ''));
        if ($patientId === '' || !in_array($patientId, $patientIds, true)) {
            return null;
        }

        $historyItem = $this->buildHistoryItemFromBookingRow($bookingRow);

        return [
            'booking_id' => (string) ($historyItem['booking_id'] ?? ''),
            'patient_id' => $patientId,
            'starts_at' => (string) ($historyItem['starts_at'] ?? ''),
            'appointment_label' => (string) ($historyItem['appointment_label'] ?? ''),
            'practitioner_id' => (string) ($historyItem['practitioner_id'] ?? ''),
            'practitioner_name' => (string) ($historyItem['practitioner_name'] ?? ''),
        ];
    }

    /**
     * @param array<int,Patient> $patients
     * @return array<int,Patient>
     */
    private function dedupePatients(array $patients): array
    {
        $unique = [];
        foreach ($patients as $patient) {
            $id = (string) $patient->getId();
            if ($id === '' || isset($unique[$id])) {
                continue;
            }
            $unique[$id] = $patient;
        }

        return array_values($unique);
    }

    /**
     * @return array<int,Patient>
     */
    private function findPatientsByEmail(string $email, $client): array
    {
        $query = sprintf(
            '?order=desc&sort=updated_at&per_page=%d&q[]=%s',
            self::MAX_PATIENT_MATCHES,
            rawurlencode('email:=' . $email)
        );

        try {
            $patients = Patient::queryManyByQueryString($query, $client);
        } catch (\Throwable $e) {
            return [];
        }

        return $this->dedupePatients($patients);
    }

    /**
     * @return array<int,string>
     */
    private function findPatientIdsByEmail(string $email, $client): array
    {
        return array_values(array_filter(array_map(
            static function (Patient $patient): string {
                return trim((string) $patient->getId());
            },
            $this->findPatientsByEmail($email, $client)
        )));
    }

    /**
     * @param array<int,string> $patientIds
     * @return array<int,array<string,mixed>>
     */
    private function loadBookingRowsForPatients(
        array $patientIds,
        $client,
        string $appointmentTypeId,
        int $perPatientLimit
    ): array {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '') {
            return [];
        }

        $rowsById = [];
        foreach ($patientIds as $patientId) {
            foreach ($this->queryBookingsForPatientId($patientId, $client, $appointmentTypeId, $perPatientLimit) as $row) {
                if (!$this->isEligibleBookingRow($row, $appointmentTypeId)) {
                    continue;
                }

                $bookingId = trim((string) ($row['id'] ?? ''));
                if ($bookingId === '') {
                    continue;
                }

                $rowsById[$bookingId] = $row;
            }
        }

        $rows = array_values($rowsById);
        usort($rows, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['starts_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['starts_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function queryBookingsForPatientId(
        string $patientId,
        $client,
        string $appointmentTypeId,
        int $limit
    ): array {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($patientId === '' || $appointmentTypeId === '') {
            return [];
        }

        $parts = [
            'order=desc',
            'sort=starts_at',
            'per_page=' . max(1, min(50, $limit)),
            'q[]=' . rawurlencode('patient_ids:~' . $patientId),
            'q[]=' . rawurlencode('appointment_type_id:=' . $appointmentTypeId),
        ];

        $response = $client->get('bookings?' . implode('&', $parts));
        if (!$response->isSuccessful()) {
            return [];
        }

        $rows = $response->data['bookings'] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param array<string,mixed> $bookingRow
     */
    private function isEligibleBookingRow(array $bookingRow, string $appointmentTypeId = ''): bool
    {
        if (!empty($bookingRow['archived_at'] ?? null) || !empty($bookingRow['deleted_at'] ?? null)) {
            return false;
        }

        if (!empty($bookingRow['cancelled_at'] ?? null) || !empty($bookingRow['did_not_arrive'] ?? false)) {
            return false;
        }

        if ($appointmentTypeId !== '') {
            $rowAppointmentTypeId = $this->extractLinkedResourceId($bookingRow['appointment_type']['links']['self'] ?? null)
                ?? trim((string) ($bookingRow['appointment_type_id'] ?? ''));
            if ($rowAppointmentTypeId === '' || !hash_equals($appointmentTypeId, $rowAppointmentTypeId)) {
                return false;
            }
        }

        $startsAt = (string) ($bookingRow['starts_at'] ?? '');
        $startsAtTs = $startsAt !== '' ? strtotime($startsAt) : false;
        return $startsAtTs !== false && $startsAtTs <= time();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchBookingRowById(string $bookingId, $client): ?array
    {
        if ($bookingId === '') {
            return null;
        }

        $response = $client->get('bookings/' . rawurlencode($bookingId));
        if (!$response->isSuccessful()) {
            return null;
        }

        return is_array($response->data) ? $response->data : null;
    }

    /**
     * @param array<string,mixed> $bookingRow
     * @param array<int,string> $patientIds
     * @return array<string,mixed>|null
     */
    private function findEligibleAttendeeRowForBooking(array $bookingRow, array $patientIds, $client): ?array
    {
        $attendeesUrl = $this->extractLinkedResourceUrl($bookingRow['attendees']['links']['self'] ?? null);
        if ($attendeesUrl === '') {
            return null;
        }

        $response = $client->get($attendeesUrl);
        if (!$response->isSuccessful()) {
            return null;
        }

        $rows = $response->data['attendees'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isEligibleAttendeeRow($row)) {
                continue;
            }

            $rowPatientId = $this->extractLinkedResourceId($row['patient']['links']['self'] ?? null)
                ?? trim((string) ($row['patient_id'] ?? ''));
            if ($rowPatientId === '' || !in_array($rowPatientId, $patientIds, true)) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findEligibleAttendeeRowForBookingAndPatient(
        string $bookingId,
        string $patientId,
        $client
    ): ?array {
        if ($bookingId === '' || $patientId === '') {
            return null;
        }

        $parts = [
            'order=desc',
            'sort=created_at',
            'per_page=10',
            'q[]=' . rawurlencode('booking_id:=' . $bookingId),
            'q[]=' . rawurlencode('patient_id:=' . $patientId),
        ];

        $response = $client->get('attendees?' . implode('&', $parts));
        if (!$response->isSuccessful()) {
            return null;
        }

        $rows = $response->data['attendees'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isEligibleAttendeeRow($row)) {
                continue;
            }

            $rowPatientId = $this->extractLinkedResourceId($row['patient']['links']['self'] ?? null)
                ?? trim((string) ($row['patient_id'] ?? ''));
            $rowBookingId = $this->extractLinkedResourceId($row['booking']['links']['self'] ?? null)
                ?? trim((string) ($row['booking_id'] ?? ''));

            if (!hash_equals($patientId, $rowPatientId) || !hash_equals($bookingId, $rowBookingId)) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isEligibleAttendeeRow(array $row): bool
    {
        if (trim((string) ($row['id'] ?? '')) === '') {
            return false;
        }

        return empty($row['archived_at'] ?? null)
            && empty($row['deleted_at'] ?? null)
            && empty($row['cancelled_at'] ?? null);
    }

    /**
     * @param array<string,mixed> $bookingRow
     * @return array<string,mixed>
     */
    private function buildHistoryItemFromBookingRow(
        array $bookingRow,
        string $appointmentLabel = ''
    ): array {
        $practitionerId = $this->extractLinkedResourceId($bookingRow['practitioner']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['practitioner_id'] ?? ''));
        $appointmentTypeId = $this->extractLinkedResourceId($bookingRow['appointment_type']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['appointment_type_id'] ?? ''));
        $resolvedAppointmentLabel = trim((string) ($bookingRow['appointment_type_name'] ?? ''));
        $practitionerName = trim((string) ($bookingRow['practitioner_name'] ?? ''));

        return [
            'booking_id' => trim((string) ($bookingRow['id'] ?? '')),
            'appointment_id' => trim((string) ($bookingRow['id'] ?? '')),
            'starts_at' => (string) ($bookingRow['starts_at'] ?? ''),
            'ends_at' => (string) ($bookingRow['ends_at'] ?? ''),
            'status' => 'completed',
            'appointment_label' => $resolvedAppointmentLabel !== ''
                ? $resolvedAppointmentLabel
                : ($appointmentLabel !== '' ? $appointmentLabel : 'Previous appointment'),
            'appointment_type_id' => $appointmentTypeId,
            'practitioner_id' => $practitionerId,
            'practitioner_name' => $practitionerName,
            'has_form' => null,
            'forms_count' => null,
            'patient_form_id' => '',
            'patient_form_name' => '',
        ];
    }

    /**
     * @param array<string,mixed> $latestContext
     * @return array<string,mixed>
     */
    private function buildHistoryItemFromStoredLatestContext(
        string $bookingId,
        string $appointmentTypeId,
        array $latestContext
    ): array {
        $startsAt = trim((string) ($latestContext['starts_at'] ?? ''));
        $appointmentLabel = trim((string) ($latestContext['appointment_label'] ?? ''));
        $practitionerId = trim((string) ($latestContext['practitioner_id'] ?? ''));
        $practitionerName = trim((string) ($latestContext['practitioner_name'] ?? ''));

        return [
            'booking_id' => $bookingId,
            'appointment_id' => $bookingId,
            'starts_at' => $startsAt,
            'ends_at' => '',
            'status' => 'completed',
            'appointment_label' => $appointmentLabel !== '' ? $appointmentLabel : 'Previous appointment',
            'appointment_type_id' => $appointmentTypeId,
            'practitioner_id' => $practitionerId,
            'practitioner_name' => $practitionerName,
            'has_form' => null,
            'forms_count' => null,
            'patient_form_id' => '',
            'patient_form_name' => '',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchLatestPatientFormForAttendeeRow(array $attendeeRow, $client, bool $includeContent): ?array
    {
        $link = $this->extractLinkedResourceUrl($attendeeRow['patient_forms']['links']['self'] ?? null);
        if ($link === '') {
            return null;
        }

        $response = $client->get($link);
        if (!$response->isSuccessful()) {
            return null;
        }

        $rows = is_array($response->data['patient_forms'] ?? null) ? $response->data['patient_forms'] : [];
        if (empty($rows)) {
            return null;
        }

        usort($rows, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['created_at'] ?? $a['updated_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['created_at'] ?? $b['updated_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });

        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if ($row === null) {
            return null;
        }

        $formId = trim((string) ($row['id'] ?? ''));
        if ($formId === '') {
            return null;
        }

        $entry = [
            'id' => $formId,
            'name' => (string) ($row['name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ];

        if ($includeContent) {
            $detail = $client->get('patient_forms/' . rawurlencode($formId));
            if ($detail->isSuccessful()) {
                $data = is_array($detail->data) ? $detail->data : [];
                $entry['content_sections'] = $this->normalizeContentSections($data['content']['sections'] ?? []);
                $entry['patient_form_template_id'] =
                    $this->extractLinkedResourceId($data['patient_form_template']['links']['self'] ?? null)
                    ?? trim((string) ($data['patient_form_template_id'] ?? ''));
            } else {
                $entry['content_sections'] = [];
                $entry['patient_form_template_id'] = '';
            }
        }

        return $entry;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchPatientData(string $patientId, $client): ?array
    {
        if ($patientId === '') {
            return null;
        }

        $response = $client->get('patients/' . rawurlencode($patientId));
        if (!$response->isSuccessful()) {
            return null;
        }

        return is_array($response->data) ? $response->data : null;
    }

    /**
     * @param array<string,mixed> $patientData
     * @param array<string,mixed> $historyItem
     * @return array<string,mixed>
     */
    private function buildPatientPrefill(array $patientData, array $historyItem): array
    {
        $phoneNumbers = is_array($patientData['patient_phone_numbers'] ?? null)
            ? $patientData['patient_phone_numbers']
            : [];
        $firstPhone = is_array($phoneNumbers[0] ?? null) ? $phoneNumbers[0] : [];

        return [
            'first_name' => (string) ($patientData['first_name'] ?? ''),
            'last_name' => (string) ($patientData['last_name'] ?? ''),
            'email' => (string) ($patientData['email'] ?? ''),
            'phone' => (string) ($firstPhone['number'] ?? ''),
            'medicare' => (string) ($patientData['medicare'] ?? ''),
            'medicare_reference_number' => (string) ($patientData['medicare_reference_number'] ?? ''),
            'address_1' => (string) ($patientData['address_1'] ?? ''),
            'address_2' => (string) ($patientData['address_2'] ?? ''),
            'city' => (string) ($patientData['city'] ?? ''),
            'state' => (string) ($patientData['state'] ?? ''),
            'post_code' => (string) ($patientData['post_code'] ?? ''),
            'country' => (string) ($patientData['country'] ?? ''),
            'date_of_birth' => (string) ($patientData['date_of_birth'] ?? ''),
            'practitioner_id' => (string) ($historyItem['practitioner_id'] ?? ''),
            'appointment_start' => '',
            'appointment_date' => '',
        ];
    }

    /**
     * @param mixed $rawSections
     * @return array<int,array<string,mixed>>
     */
    private function normalizeContentSections($rawSections): array
    {
        if (!is_array($rawSections)) {
            return [];
        }

        $sections = [];
        foreach ($rawSections as $section) {
            $questionsIn = is_array($section['questions'] ?? null) ? $section['questions'] : [];
            $questions = [];

            foreach ($questionsIn as $question) {
                $type = (string) ($question['type'] ?? 'text');
                if ($type === 'signature') {
                    continue;
                }

                $entry = [
                    'name' => (string) ($question['name'] ?? ''),
                    'type' => $type,
                    'required' => !empty($question['required']),
                ];

                if ($type === 'checkboxes' || $type === 'radiobuttons') {
                    $answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];
                    $entry['answers'] = array_values(array_filter(array_map(
                        static function ($answer): ?array {
                            if (!is_array($answer)) {
                                return null;
                            }

                            $value = trim((string) ($answer['value'] ?? ''));
                            if ($value === '') {
                                return null;
                            }

                            return array_merge(
                                ['value' => $value],
                                array_key_exists('selected', $answer) ? ['selected' => (bool) $answer['selected']] : []
                            );
                        },
                        $answers
                    )));

                    if (array_key_exists('answer', $question)) {
                        $entry['answer'] = is_array($question['answer'])
                            ? array_values(array_map('strval', $question['answer']))
                            : (string) ($question['answer'] ?? '');
                    }

                    if (isset($question['other']) && is_array($question['other'])) {
                        $entry['other'] = [
                            'enabled' => !empty($question['other']['enabled']),
                            'selected' => !empty($question['other']['selected']),
                            'value' => (string) ($question['other']['value'] ?? ''),
                        ];
                    }
                } else {
                    $entry['answer'] = is_scalar($question['answer'] ?? null)
                        ? (string) $question['answer']
                        : '';
                }

                $questions[] = $entry;
            }

            $sections[] = [
                'name' => (string) ($section['name'] ?? ''),
                'description' => (string) ($section['description'] ?? ''),
                'questions' => $questions,
            ];
        }

        return $sections;
    }

    /**
     * @param mixed $url
     */
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
     * @param mixed $url
     */
    private function extractLinkedResourceUrl($url): string
    {
        return trim((string) $url);
    }
}
