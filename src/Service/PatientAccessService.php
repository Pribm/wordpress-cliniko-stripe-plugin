<?php

namespace App\Service;

use App\Exception\ApiException;
use App\Model\Attendee;
use App\Model\Booking;
use App\Model\Patient;
use App\Model\PatientForm;

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
    private PatientAccessRequestLookupCacheService $requestLookupCache;

    public function __construct(
        ?NotificationService $notifications = null,
        ?PatientAccessTokenService $tokens = null,
        ?PatientAccessRequestStateService $requestStates = null,
        ?PatientAccessRequestLookupCacheService $requestLookupCache = null
    ) {
        $this->notifications = $notifications ?: new NotificationService();
        $this->tokens = $tokens ?: new PatientAccessTokenService();
        $this->requestStates = $requestStates ?: new PatientAccessRequestStateService($this->tokens);
        $this->requestLookupCache = $requestLookupCache ?: new PatientAccessRequestLookupCacheService($this->tokens);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function requestAccessCode(array $body): array
    {
        $email = $this->tokens->normalizeEmail((string) ($body['email'] ?? ''));
        $appointmentTypeId = trim((string) ($body['appointment_type_id'] ?? $body['module_id'] ?? ''));
        $returnUrl = trim((string) ($body['return_url'] ?? ''));
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
        $lookup = $this->resolveRequestLookup($email, $client, $appointmentTypeId);
        if (!$lookup['ok']) {
            error_log('[PatientAccessService] Patient access lookup failed: ' . (string) $lookup['error']);

            return [
                'ok' => false,
                'status' => 503,
                'message' => 'We could not check your completed bookings right now. Please try again.',
            ];
        }

        $patientIds = $lookup['patient_ids'];
        if (empty($patientIds)) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'No patient record was found for that email address.',
            ];
        }

        $latestBookingContext = $lookup['latest_booking_context'];
        if ($latestBookingContext === null) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'No completed booking was found for this appointment type.',
            ];
        }

        $accessToken = $this->tokens->issue($email, $appointmentTypeId, $patientIds, $latestBookingContext);
        $challenge = $this->tokens->issueChallenge($email, $appointmentTypeId, $patientIds, $latestBookingContext);

        if ($challenge['challenge_token'] === '') {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Verification is temporarily unavailable.',
            ];
        }

        $magicLink = '';
        $requestStateId = '';
        if ($accessToken !== '') {
            $linkParams = $requestId !== '' ? ['request_id' => $requestId] : [];
            $magicLink = $this->tokens->buildMagicHashLink($returnUrl, $accessToken, $linkParams);
        }

        $ttlSeconds = max(1, (int) $challenge['expires_in']);
        $ttlMinutes = max(1, (int) ceil($ttlSeconds / 60));
        $linkTtlMinutes = max(1, (int) ceil($this->tokens->ttl() / 60));
        $appointmentLabel = trim((string) ($latestBookingContext['appointment_label'] ?? ''));
        $code = trim((string) $challenge['code']);
        $message = $this->buildRequestAccessEmailMessage(
            $appointmentLabel,
            $code,
            $ttlMinutes,
            $magicLink,
            $linkTtlMinutes
        );

        $sent = $this->notifications->sendGenericEmail(
            $email,
            $this->buildRequestAccessEmailSubject($appointmentLabel),
            $message,
            'success'
        );

        if (!$sent) {
            return [
                'ok' => false,
                'status' => 502,
                'message' => 'We could not send the verification email.',
            ];
        }

        if ($requestId !== '' && $magicLink !== '' && $this->requestStates->start($requestId, $this->tokens->ttl())) {
            $requestStateId = $requestId;
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => $magicLink !== ''
                ? 'We emailed a 6-digit code and a secure link. Use either option to continue.'
                : 'We emailed a 6-digit code. Enter it below to continue.',
            'challenge_token' => (string) $challenge['challenge_token'],
            'expires_in' => max(1, (int) $challenge['expires_in']),
            'request_id' => $requestStateId,
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

    private function buildRequestAccessEmailSubject(string $appointmentLabel): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($appointmentLabel)));
        return $label !== ''
            ? sprintf('Your %s access details', $label)
            : 'Your appointment access details';
    }

    private function buildRequestAccessEmailMessage(
        string $appointmentLabel,
        string $code,
        int $ttlMinutes,
        string $magicLink = '',
        int $linkTtlMinutes = 0
    ): string {
        $resolvedLabel = esc_html($appointmentLabel !== '' ? $appointmentLabel : 'appointment');
        $resolvedCode = esc_html($code);
        $resolvedCodeTtl = max(1, $ttlMinutes);
        $resolvedLinkTtl = max(1, $linkTtlMinutes);

        $parts = [
            "<p style='margin:0 0 14px;font-size:16px;line-height:1.6;color:#1f2937;'>Use the details below to reopen your most recent completed <strong>{$resolvedLabel}</strong> booking.</p>",
            "<div style='margin:18px 0 0;padding:20px 18px;border-radius:16px;border:1px solid #a7f3d0;background:#ffffff;text-align:center;'>"
                . "<div style='font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#047857;'>6-digit code</div>"
                . "<div style='margin-top:12px;font-size:34px;line-height:1.1;font-weight:800;letter-spacing:0.26em;color:#111827;'>{$resolvedCode}</div>"
                . "<div style='margin-top:10px;font-size:13px;line-height:1.5;color:#4b5563;'>Enter this code on the form. It expires in {$resolvedCodeTtl} " . ($resolvedCodeTtl === 1 ? 'minute' : 'minutes') . ".</div>"
                . "</div>",
        ];

        if ($magicLink !== '') {
            $parts[] = "<div style='margin:16px 0 0;padding:20px 18px;border-radius:16px;border:1px solid #bfdbfe;background:#f8fafc;text-align:center;'>"
                . "<div style='font-size:14px;font-weight:700;line-height:1.5;color:#0f172a;'>Prefer not to type the code?</div>"
                . "<div style='margin-top:14px;'><a href='" . esc_url($magicLink) . "' style='display:inline-block;padding:12px 20px;border-radius:999px;background:#047857;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;'>Open your saved details</a></div>"
                . "<div style='margin-top:10px;font-size:13px;line-height:1.5;color:#475569;'>This secure link expires in {$resolvedLinkTtl} " . ($resolvedLinkTtl === 1 ? 'minute' : 'minutes') . ".</div>"
                . "</div>";
        }

        $parts[] = "<p style='margin:18px 0 0;font-size:13px;line-height:1.6;color:#6b7280;'>If you did not request this email, you can ignore it.</p>";

        return implode('', $parts);
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
        $cachedLookup = $normalizedEmail !== '' && $normalizedAppointmentTypeId !== ''
            ? $this->requestLookupCache->load($normalizedEmail, $normalizedAppointmentTypeId, 0, false)
            : null;

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

        $patientPrefill = $this->resolveCachedLatestPatientPrefill(
            $cachedLookup,
            $context['history_item'],
            (string) $context['patient_id'],
            $normalizedPatientIds
        );
        if ($patientPrefill === null) {
            $patientData = $this->fetchPatientData($context['patient_id'], $context['client']);
            if ($patientData === null) {
                return null;
            }

            $patientPrefill = $this->buildPatientPrefill($patientData, $context['history_item']);
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
                'patient' => $patientPrefill,
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
        $result = $this->findLatestBookingContextWithStatus($patientIds, $client, $appointmentTypeId);
        return $result['ok'] ? $result['latest_booking_context'] : null;
    }

    /**
     * @param array<int,string> $patientIds
     * @return array{ok:bool,latest_booking_context:?array<string,mixed>,error:string}
     */
    private function findLatestBookingContextWithStatus(
        array $patientIds,
        $client,
        string $appointmentTypeId
    ): array {
        $bookingRowsResult = $this->loadBookingRowsForPatientsWithStatus(
            $patientIds,
            $client,
            trim($appointmentTypeId),
            1
        );
        if (!$bookingRowsResult['ok']) {
            return [
                'ok' => false,
                'latest_booking_context' => null,
                'error' => $bookingRowsResult['error'],
            ];
        }

        $bookingRows = $bookingRowsResult['rows'];
        $bookingRow = is_array($bookingRows[0] ?? null) ? $bookingRows[0] : null;
        if ($bookingRow === null) {
            return [
                'ok' => true,
                'latest_booking_context' => null,
                'error' => '',
            ];
        }

        $patientId = $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['patient_id'] ?? ''));
        if ($patientId === '' || !in_array($patientId, $patientIds, true)) {
            return [
                'ok' => true,
                'latest_booking_context' => null,
                'error' => '',
            ];
        }

        $historyItem = $this->buildHistoryItemFromBookingRow($bookingRow);

        return [
            'ok' => true,
            'latest_booking_context' => [
                'booking_id' => (string) ($historyItem['booking_id'] ?? ''),
                'patient_id' => $patientId,
                'starts_at' => (string) ($historyItem['starts_at'] ?? ''),
                'appointment_label' => (string) ($historyItem['appointment_label'] ?? ''),
                'practitioner_id' => (string) ($historyItem['practitioner_id'] ?? ''),
                'practitioner_name' => (string) ($historyItem['practitioner_name'] ?? ''),
            ],
            'error' => '',
        ];
    }

    /**
     * @param array<int,string> $patientIds
     * @return array{ok:bool,latest_booking_context:?array<string,mixed>,has_matching_booking:bool,error:string}
     */
    private function findLatestBookingContextWithFormStatus(
        array $patientIds,
        $client,
        string $appointmentTypeId
    ): array {
        $bookingRowsResult = $this->loadBookingRowsForPatientsWithStatus(
            $patientIds,
            $client,
            trim($appointmentTypeId),
            self::DEFAULT_HISTORY_LIMIT
        );
        if (!$bookingRowsResult['ok']) {
            return [
                'ok' => false,
                'latest_booking_context' => null,
                'has_matching_booking' => false,
                'error' => $bookingRowsResult['error'],
            ];
        }

        $hasMatchingBooking = false;
        foreach ($bookingRowsResult['rows'] as $bookingRow) {
            if (!$this->isEligibleBookingRow($bookingRow, $appointmentTypeId)) {
                continue;
            }

            $hasMatchingBooking = true;

            $attendeeResult = $this->findEligibleAttendeeRowForBookingWithStatus($bookingRow, $patientIds, $client);
            if (!$attendeeResult['ok']) {
                return [
                    'ok' => false,
                    'latest_booking_context' => null,
                    'has_matching_booking' => true,
                    'error' => $attendeeResult['error'],
                ];
            }

            $attendeeRow = $attendeeResult['attendee_row'];
            if ($attendeeRow === null) {
                continue;
            }

            $patientId = $this->extractLinkedResourceId($attendeeRow['patient']['links']['self'] ?? null)
                ?? $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
                ?? trim((string) ($attendeeRow['patient_id'] ?? $bookingRow['patient_id'] ?? ''));
            if ($patientId === '' || !in_array($patientId, $patientIds, true)) {
                continue;
            }

            $formResult = $this->fetchLatestPatientFormForAttendeeRowWithStatus($attendeeRow, $client, false);
            if (!$formResult['ok']) {
                return [
                    'ok' => false,
                    'latest_booking_context' => null,
                    'has_matching_booking' => true,
                    'error' => $formResult['error'],
                ];
            }

            $latestForm = $formResult['form'];
            if ($latestForm === null) {
                continue;
            }

            $historyItem = $this->buildHistoryItemFromBookingRow($bookingRow);

            return [
                'ok' => true,
                'latest_booking_context' => [
                    'booking_id' => (string) ($historyItem['booking_id'] ?? ''),
                    'patient_id' => $patientId,
                    'starts_at' => (string) ($historyItem['starts_at'] ?? ''),
                    'appointment_label' => (string) ($historyItem['appointment_label'] ?? ''),
                    'practitioner_id' => (string) ($historyItem['practitioner_id'] ?? ''),
                    'practitioner_name' => (string) ($historyItem['practitioner_name'] ?? ''),
                    'patient_form_id' => (string) ($latestForm['id'] ?? ''),
                    'patient_form_name' => (string) ($latestForm['name'] ?? ''),
                ],
                'has_matching_booking' => true,
                'error' => '',
            ];
        }

        return [
            'ok' => true,
            'latest_booking_context' => null,
            'has_matching_booking' => $hasMatchingBooking,
            'error' => '',
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
     * @return array<int,string>
     */
    private function findPatientIdsByEmail(string $email, $client): array
    {
        $result = $this->findPatientIdsByEmailWithStatus($email, $client);
        return $result['ok'] ? $result['patient_ids'] : [];
    }

    /**
     * @return array{ok:bool,patient_ids:array<int,string>,error:string}
     */
    private function findPatientIdsByEmailWithStatus(string $email, $client): array
    {
        $query = sprintf(
            '?order=desc&sort=updated_at&per_page=%d&q[]=%s',
            self::MAX_PATIENT_MATCHES,
            rawurlencode('email:=' . $email)
        );

        try {
            $patients = Patient::queryManyByQueryString($query, $client, true);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'patient_ids' => [],
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'patient_ids' => array_values(array_filter(array_map(
                static function (Patient $patient): string {
                    return trim((string) $patient->getId());
                },
                $this->dedupePatients($patients)
            ))),
            'error' => '',
        ];
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
        $result = $this->loadBookingRowsForPatientsWithStatus($patientIds, $client, $appointmentTypeId, $perPatientLimit);
        return $result['ok'] ? $result['rows'] : [];
    }

    /**
     * @param array<int,string> $patientIds
     * @return array{ok:bool,rows:array<int,array<string,mixed>>,error:string}
     */
    private function loadBookingRowsForPatientsWithStatus(
        array $patientIds,
        $client,
        string $appointmentTypeId,
        int $perPatientLimit
    ): array {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '') {
            return [
                'ok' => true,
                'rows' => [],
                'error' => '',
            ];
        }

        $rowsById = [];
        foreach ($patientIds as $patientId) {
            $queryResult = $this->queryBookingsForPatientIdWithStatus($patientId, $client, $appointmentTypeId, $perPatientLimit);
            if (!$queryResult['ok']) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'error' => $queryResult['error'],
                ];
            }

            foreach ($queryResult['rows'] as $row) {
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

        return [
            'ok' => true,
            'rows' => $rows,
            'error' => '',
        ];
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
        $result = $this->queryBookingsForPatientIdWithStatus($patientId, $client, $appointmentTypeId, $limit);
        return $result['ok'] ? $result['rows'] : [];
    }

    /**
     * @return array{ok:bool,rows:array<int,array<string,mixed>>,error:string}
     */
    private function queryBookingsForPatientIdWithStatus(
        string $patientId,
        $client,
        string $appointmentTypeId,
        int $limit
    ): array {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($patientId === '' || $appointmentTypeId === '') {
            return [
                'ok' => true,
                'rows' => [],
                'error' => '',
            ];
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
            return [
                'ok' => false,
                'rows' => [],
                'error' => (string) ($response->error ?? 'Failed to load Cliniko bookings.'),
            ];
        }

        $rows = $response->data['bookings'] ?? [];
        return [
            'ok' => true,
            'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'error' => '',
        ];
    }

    /**
     * @return array{ok:bool,patient_ids:array<int,string>,latest_booking_context:?array<string,mixed>,has_matching_booking:bool,error:string}
     */
    private function resolveRequestLookup(string $email, $client, string $appointmentTypeId): array
    {
        $normalizedEmail = $this->tokens->normalizeEmail($email);
        $normalizedAppointmentTypeId = trim($appointmentTypeId);
        $cacheTtl = $this->tokens->challengeTtl();

        $cached = $this->requestLookupCache->load($normalizedEmail, $normalizedAppointmentTypeId, $cacheTtl);
        if ($cached !== null) {
            return [
                'ok' => true,
                'patient_ids' => $cached['patient_ids'],
                'latest_booking_context' => $cached['latest_booking_context'],
                'patient_prefill' => $cached['patient_prefill'],
                'has_matching_booking' => true,
                'error' => '',
            ];
        }

        $patientLookup = $this->findLatestPatientByEmailForRequestWithStatus($normalizedEmail, $client);
        if (!$patientLookup['ok']) {
            return [
                'ok' => false,
                'patient_ids' => [],
                'latest_booking_context' => null,
                'patient_prefill' => [],
                'has_matching_booking' => false,
                'error' => $patientLookup['error'],
            ];
        }

        $patient = $patientLookup['patient'];
        if ($patient === null) {
            return [
                'ok' => true,
                'patient_ids' => [],
                'latest_booking_context' => null,
                'patient_prefill' => [],
                'has_matching_booking' => false,
                'error' => '',
            ];
        }

        $patientId = trim((string) $patient->getId());
        if ($patientId === '') {
            return [
                'ok' => true,
                'patient_ids' => [],
                'latest_booking_context' => null,
                'patient_prefill' => [],
                'has_matching_booking' => false,
                'error' => '',
            ];
        }

        $patientIds = [$patientId];
        $bookingLookup = $this->findLatestBookingForPatientAndAppointmentTypeWithStatus($patientId, $normalizedAppointmentTypeId, $client);
        if (!$bookingLookup['ok']) {
            return [
                'ok' => false,
                'patient_ids' => $patientIds,
                'latest_booking_context' => null,
                'patient_prefill' => [],
                'has_matching_booking' => false,
                'error' => $bookingLookup['error'],
            ];
        }

        $booking = $bookingLookup['booking'];
        if ($booking === null) {
            return [
                'ok' => true,
                'patient_ids' => $patientIds,
                'latest_booking_context' => null,
                'patient_prefill' => [],
                'has_matching_booking' => false,
                'error' => '',
            ];
        }

        $latestBookingContext = $this->buildLatestBookingContextFromBookingModel($booking, $patientId);
        $patientPrefill = $this->buildPatientPrefillFromPatientModel(
            $patient,
            (string) ($latestBookingContext['practitioner_id'] ?? '')
        );
        $this->requestLookupCache->store(
            $normalizedEmail,
            $normalizedAppointmentTypeId,
            $patientIds,
            $latestBookingContext,
            $patientPrefill,
            $cacheTtl
        );

        return [
            'ok' => true,
            'patient_ids' => $patientIds,
            'latest_booking_context' => $latestBookingContext,
            'patient_prefill' => $patientPrefill,
            'has_matching_booking' => true,
            'error' => '',
        ];
    }

    /**
     * @return array{ok:bool,patient:?Patient,error:string}
     */
    private function findLatestPatientByEmailForRequestWithStatus(string $email, $client): array
    {
        $query = sprintf(
            '?order=desc&sort=updated_at&per_page=1&q[]=%s',
            rawurlencode('email:=' . $email)
        );

        try {
            $patient = Patient::query($query, $client, true);
        } catch (ApiException $e) {
            return [
                'ok' => false,
                'patient' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'patient' => $patient,
            'error' => '',
        ];
    }

    /**
     * @return array{ok:bool,booking:?Booking,error:string}
     */
    private function findLatestBookingForPatientAndAppointmentTypeWithStatus(
        string $patientId,
        string $appointmentTypeId,
        $client
    ): array {
        if ($patientId === '' || $appointmentTypeId === '') {
            return [
                'ok' => true,
                'booking' => null,
                'error' => '',
            ];
        }

        $query = '?' . implode('&', [
            'order=desc',
            'sort=starts_at',
            'per_page=1',
            'q[]=' . rawurlencode('patient_ids:~' . $patientId),
            'q[]=' . rawurlencode('appointment_type_id:=' . $appointmentTypeId),
        ]);

        try {
            $bookings = Booking::queryManyByQueryString($query, $client, true);
        } catch (ApiException $e) {
            return [
                'ok' => false,
                'booking' => null,
                'error' => $e->getMessage(),
            ];
        }

        $booking = $bookings[0] ?? null;
        if (!$booking instanceof Booking || !$this->isEligibleBookingModel($booking, $appointmentTypeId)) {
            return [
                'ok' => true,
                'booking' => null,
                'error' => '',
            ];
        }

        return [
            'ok' => true,
            'booking' => $booking,
            'error' => '',
        ];
    }

    /**
     * @return array{ok:bool,attendee:?Attendee,error:string}
     */
    private function findLatestAttendeeForBookingAndPatientWithStatus(Booking $booking, string $patientId, $client): array
    {
        $bookingId = trim((string) $booking->getId());
        if ($bookingId === '' || $patientId === '') {
            return [
                'ok' => true,
                'attendee' => null,
                'error' => '',
            ];
        }

        $query = '?' . implode('&', [
            'order=desc',
            'sort=created_at',
            'per_page=1',
            'q[]=' . rawurlencode('booking_id:=' . $bookingId),
            'q[]=' . rawurlencode('patient_id:=' . $patientId),
        ]);

        try {
            $attendees = Attendee::queryManyByQueryString($query, $client, true);
        } catch (ApiException $e) {
            return [
                'ok' => false,
                'attendee' => null,
                'error' => $e->getMessage(),
            ];
        }

        $attendee = $attendees[0] ?? null;
        if (!$attendee instanceof Attendee || !$this->isEligibleAttendeeModel($attendee)) {
            return [
                'ok' => true,
                'attendee' => null,
                'error' => '',
            ];
        }

        return [
            'ok' => true,
            'attendee' => $attendee,
            'error' => '',
        ];
    }

    /**
     * @return array{ok:bool,form:?PatientForm,error:string}
     */
    private function findLatestPatientFormForAttendeeWithStatus(Attendee $attendee, $client): array
    {
        $link = $attendee->getPatientFormsLink();
        $url = trim((string) ($link->url ?? ''));
        if ($url === '') {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        try {
            $forms = PatientForm::queryManyByUrl($url, $client, true);
        } catch (ApiException $e) {
            return [
                'ok' => false,
                'form' => null,
                'error' => $e->getMessage(),
            ];
        }

        $forms = array_values(array_filter($forms, function (PatientForm $form): bool {
            return trim((string) ($form->getArchivedAt() ?? '')) === '';
        }));

        if (empty($forms)) {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        usort($forms, static function (PatientForm $a, PatientForm $b): int {
            $aTs = strtotime((string) ($a->getCompletedAt() ?: $a->getUpdatedAt() ?: $a->getCreatedAt() ?: '')) ?: 0;
            $bTs = strtotime((string) ($b->getCompletedAt() ?: $b->getUpdatedAt() ?: $b->getCreatedAt() ?: '')) ?: 0;
            return $bTs <=> $aTs;
        });

        return [
            'ok' => true,
            'form' => $forms[0],
            'error' => '',
        ];
    }

    private function isEligibleBookingModel(Booking $booking, string $appointmentTypeId): bool
    {
        $dto = $booking->getDTO();
        $deletedAt = is_object($dto) && property_exists($dto, 'deletedAt') ? (string) ($dto->deletedAt ?? '') : '';

        return !$booking->isArchived()
            && $deletedAt === ''
            && !$booking->wasCancelled()
            && !$booking->didNotArrive()
            && $booking->getAppointmentTypeId() === $appointmentTypeId;
    }

    private function isEligibleAttendeeModel(Attendee $attendee): bool
    {
        return !$attendee->isArchived()
            && trim((string) ($attendee->getDeletedAt() ?? '')) === ''
            && trim((string) ($attendee->getCancelledAt() ?? '')) === '';
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLatestBookingContextFromBookingModel(
        Booking $booking,
        string $patientId,
        ?PatientForm $latestForm = null
    ): array {
        return [
            'booking_id' => trim((string) $booking->getId()),
            'patient_id' => trim($patientId),
            'starts_at' => trim((string) ($booking->getStartsAt() ?? '')),
            'appointment_label' => trim((string) ($booking->getAppointmentTypeName() ?? '')),
            'practitioner_id' => trim((string) ($booking->getPractitionerId() ?? '')),
            'practitioner_name' => trim((string) ($booking->getPractitionerName() ?? '')),
            'patient_form_id' => $latestForm ? trim((string) ($latestForm->getId() ?? '')) : '',
            'patient_form_name' => $latestForm ? trim((string) ($latestForm->getName() ?? '')) : '',
        ];
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

        return true;
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
        $result = $this->findEligibleAttendeeRowForBookingWithStatus($bookingRow, $patientIds, $client);
        return $result['ok'] ? $result['attendee_row'] : null;
    }

    /**
     * @param array<string,mixed> $bookingRow
     * @param array<int,string> $patientIds
     * @return array{ok:bool,attendee_row:?array<string,mixed>,error:string}
     */
    private function findEligibleAttendeeRowForBookingWithStatus(array $bookingRow, array $patientIds, $client): array
    {
        $bookingId = trim((string) ($bookingRow['id'] ?? ''));
        if ($bookingId === '') {
            return [
                'ok' => true,
                'attendee_row' => null,
                'error' => '',
            ];
        }

        foreach ($this->buildBookingAttendeeCandidatePatientIds($bookingRow, $patientIds) as $patientId) {
            $result = $this->findEligibleAttendeeRowForBookingAndPatientWithStatus($bookingId, $patientId, $client);
            if (!$result['ok']) {
                return $result;
            }

            if ($result['attendee_row'] !== null) {
                return $result;
            }
        }

        return [
            'ok' => true,
            'attendee_row' => null,
            'error' => '',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findEligibleAttendeeRowForBookingAndPatient(
        string $bookingId,
        string $patientId,
        $client
    ): ?array {
        $result = $this->findEligibleAttendeeRowForBookingAndPatientWithStatus($bookingId, $patientId, $client);
        return $result['ok'] ? $result['attendee_row'] : null;
    }

    /**
     * @return array{ok:bool,attendee_row:?array<string,mixed>,error:string}
     */
    private function findEligibleAttendeeRowForBookingAndPatientWithStatus(
        string $bookingId,
        string $patientId,
        $client
    ): array {
        if ($bookingId === '' || $patientId === '') {
            return [
                'ok' => true,
                'attendee_row' => null,
                'error' => '',
            ];
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
            return [
                'ok' => false,
                'attendee_row' => null,
                'error' => (string) ($response->error ?? 'Failed to load Cliniko attendees.'),
            ];
        }

        $rows = $response->data['attendees'] ?? [];
        if (!is_array($rows)) {
            return [
                'ok' => true,
                'attendee_row' => null,
                'error' => '',
            ];
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

            return [
                'ok' => true,
                'attendee_row' => $row,
                'error' => '',
            ];
        }

        return [
            'ok' => true,
            'attendee_row' => null,
            'error' => '',
        ];
    }

    /**
     * @param array<string,mixed> $bookingRow
     * @param array<int,string> $patientIds
     * @return array<int,string>
     */
    private function buildBookingAttendeeCandidatePatientIds(array $bookingRow, array $patientIds): array
    {
        $ordered = [];
        $primaryPatientId = $this->extractLinkedResourceId($bookingRow['patient']['links']['self'] ?? null)
            ?? trim((string) ($bookingRow['patient_id'] ?? ''));

        if ($primaryPatientId !== '' && in_array($primaryPatientId, $patientIds, true)) {
            $ordered[$primaryPatientId] = $primaryPatientId;
        }

        foreach ($patientIds as $patientId) {
            $normalizedPatientId = trim((string) $patientId);
            if ($normalizedPatientId === '' || isset($ordered[$normalizedPatientId])) {
                continue;
            }

            $ordered[$normalizedPatientId] = $normalizedPatientId;
        }

        return array_values($ordered);
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
        $result = $this->fetchLatestPatientFormForAttendeeRowWithStatus($attendeeRow, $client, $includeContent);
        return $result['ok'] ? $result['form'] : null;
    }

    /**
     * @return array{ok:bool,form:?array<string,mixed>,error:string}
     */
    private function fetchLatestPatientFormForAttendeeRowWithStatus(array $attendeeRow, $client, bool $includeContent): array
    {
        $link = $this->extractLinkedResourceUrl($attendeeRow['patient_forms']['links']['self'] ?? null);
        if ($link === '') {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        $response = $client->get($this->withCollectionQuery($link, [
            'order' => 'desc',
            'sort' => 'updated_at',
            'per_page' => '1',
        ]));
        if (!$response->isSuccessful()) {
            return [
                'ok' => false,
                'form' => null,
                'error' => (string) ($response->error ?? 'Failed to load Cliniko patient forms.'),
            ];
        }

        $rows = is_array($response->data['patient_forms'] ?? null) ? $response->data['patient_forms'] : [];
        if (empty($rows)) {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['created_at'] ?? $a['updated_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['created_at'] ?? $b['updated_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });

        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if ($row === null) {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        $formId = trim((string) ($row['id'] ?? ''));
        if ($formId === '') {
            return [
                'ok' => true,
                'form' => null,
                'error' => '',
            ];
        }

        $entry = [
            'id' => $formId,
            'name' => (string) ($row['name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ];

        if ($includeContent) {
            $collectionHasContent = is_array($row['content'] ?? null) && array_key_exists('sections', $row['content']);
            if ($collectionHasContent) {
                $entry['content_sections'] = $this->normalizeContentSections($row['content']['sections'] ?? []);
                $entry['patient_form_template_id'] =
                    $this->extractLinkedResourceId($row['patient_form_template']['links']['self'] ?? null)
                    ?? trim((string) ($row['patient_form_template_id'] ?? ''));
            } else {
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
        }

        return [
            'ok' => true,
            'form' => $entry,
            'error' => '',
        ];
    }

    /**
     * @param array<string,string> $params
     */
    private function withCollectionQuery(string $url, array $params): string
    {
        $value = trim($url);
        if ($value === '' || empty($params)) {
            return $value;
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($value) : parse_url($value);
        if (!is_array($parts)) {
            return $value;
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        foreach ($params as $key => $paramValue) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $query[$normalizedKey] = (string) $paramValue;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $user = isset($parts['user']) && is_string($parts['user']) ? $parts['user'] : '';
        $pass = isset($parts['pass']) && is_string($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        $fragment = isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== ''
            ? '#' . $parts['fragment']
            : '';

        return $scheme
            . $auth
            . $host
            . $port
            . $path
            . '?' . http_build_query($query)
            . $fragment;
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
     * @return array<string,string>
     */
    private function buildPatientPrefillFromPatientModel(Patient $patient, string $practitionerId): array
    {
        return [
            'first_name' => trim($patient->getFirstName()),
            'last_name' => trim($patient->getLastName()),
            'email' => trim((string) $patient->getEmail()),
            'phone' => trim((string) $patient->getPhone()),
            'medicare' => trim((string) $patient->getMedicare()),
            'medicare_reference_number' => trim((string) $patient->getMedicareReferenceNumber()),
            'address_1' => trim((string) $patient->getAddress1()),
            'address_2' => trim((string) $patient->getAddress2()),
            'city' => trim((string) $patient->getCity()),
            'state' => trim((string) $patient->getState()),
            'post_code' => trim((string) $patient->getPostCode()),
            'country' => trim((string) $patient->getCountry()),
            'date_of_birth' => trim((string) $patient->getDateOfBirth()),
            'practitioner_id' => trim($practitionerId),
            'appointment_start' => '',
            'appointment_date' => '',
        ];
    }

    /**
     * @param array<string,mixed>|null $cachedLookup
     * @param array<string,mixed> $historyItem
     * @param array<int,string> $patientIds
     * @return array<string,string>|null
     */
    private function resolveCachedLatestPatientPrefill(
        ?array $cachedLookup,
        array $historyItem,
        string $patientId,
        array $patientIds = []
    ): ?array {
        if ($cachedLookup === null) {
            return null;
        }

        $cachedPatientIds = $this->tokens->normalizePatientIds(
            is_array($cachedLookup['patient_ids'] ?? null) ? $cachedLookup['patient_ids'] : []
        );
        $cachedContext = is_array($cachedLookup['latest_booking_context'] ?? null)
            ? $cachedLookup['latest_booking_context']
            : [];
        $cachedPrefill = is_array($cachedLookup['patient_prefill'] ?? null)
            ? $cachedLookup['patient_prefill']
            : [];

        if (empty($cachedPrefill) || $patientId === '') {
            return null;
        }

        if (!empty($patientIds) && !in_array($patientId, $patientIds, true)) {
            return null;
        }

        if (!empty($cachedPatientIds) && !in_array($patientId, $cachedPatientIds, true)) {
            return null;
        }

        $bookingId = trim((string) ($historyItem['booking_id'] ?? ''));
        if (
            $bookingId === ''
            || !hash_equals($bookingId, trim((string) ($cachedContext['booking_id'] ?? '')))
            || !hash_equals($patientId, trim((string) ($cachedContext['patient_id'] ?? '')))
        ) {
            return null;
        }

        $cachedPrefill['practitioner_id'] = trim((string) ($historyItem['practitioner_id'] ?? ''));
        $cachedPrefill['appointment_start'] = '';
        $cachedPrefill['appointment_date'] = '';

        return array_map(
            static fn($value): string => trim((string) $value),
            $cachedPrefill
        );
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
