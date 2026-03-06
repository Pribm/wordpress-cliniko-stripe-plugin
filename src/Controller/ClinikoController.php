<?php
namespace App\Controller;

use App\Infra\JobDispatcher;
use App\Model\AppointmentType;
use App\Model\AvailableTimes;
use App\Service\ClinikoService;
use App\Service\PatientFormPayloadSanitizer;
use App\Validator\PatientFormValidator;


if (!defined('ABSPATH'))
    exit;


use WP_REST_Request;
use WP_REST_Response;

class ClinikoController
{
    public function createPatientForm(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        if (is_array($body)) {
            $body = PatientFormPayloadSanitizer::sanitizePayload($body);
        }

        // 1️⃣ Validação de payload
        $errors = PatientFormValidator::validate($body);
        if (!empty($errors)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $errors,
            ], 400);
        }

        // 2️⃣ Extração dos dados principais
        $templateId = $body['patient_form_template_id'] ?? null;
        $patient = $body['patient'] ?? [];
        $content = $body['content'] ?? [];
        $moduleId = $body['moduleId'] ?? null;
        $bookedTime = $patient['patient_booked_time'] ?? null;

        // 3️⃣ Monta o payload completo (como no Worker)
        $payload = [
            'patient' => $patient,
            'content' => $content,
            'moduleId' => $moduleId,
            'patient_form_template_id' => $templateId,
            'patient_booked_time' => $bookedTime,
        ];

        // 4️⃣ Armazena o payload no banco
        $payloadKey = 'cliniko_pf_job_payload_' . uniqid();
        error_log("[Dispatch] Saving payload $payloadKey...");
        if (!add_option($payloadKey, $payload, '', false)) {
            update_option($payloadKey, $payload, false);
        }
        error_log("[Dispatch] Added option: " . (get_option($payloadKey) ? 'yes' : 'no'));
        $jobDispatcher = new JobDispatcher();
        // 5️⃣ Enfileira o Worker de forma assíncrona
        $jobDispatcher->enqueue('cliniko_async_create_patient_form', ['payload_key' => $payloadKey], 0);

        // 6️⃣ Retorna resposta imediata ao frontend
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Patient form creation has been queued.',
            'queued' => [
                'payload_key' => $payloadKey,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function getAvailableTimes(WP_REST_Request $request): WP_REST_Response
    {
        $appointmentTypeId = sanitize_text_field((string) (
            $this->requestParam($request, 'appointment_type_id')
            ?? $this->requestParam($request, 'module_id')
            ?? $this->requestParam($request, 'moduleId')
        ));
        $from = sanitize_text_field((string) $this->requestParam($request, 'from'));
        $to = sanitize_text_field((string) $this->requestParam($request, 'to'));
        $practitionerId = sanitize_text_field((string) $this->requestParam($request, 'practitioner_id'));
        $page = $this->requestParam($request, 'page');
        $perPage = $this->requestParam($request, 'per_page');

        if (empty($appointmentTypeId) || empty($from) || empty($to)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing required fields: appointment_type_id, from, to.',
            ], 422);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD for from/to.',
            ], 422);
        }

        $businessId = get_option('wp_cliniko_business_id');
        if (empty($businessId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Cliniko business ID is not configured.',
            ], 400);
        }

        // Resolve practitioner if not provided (use first for appointment type)
        if (empty($practitionerId)) {
            $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(false));
            if (!$appointmentType) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Appointment type not found.',
                ], 404);
            }

            $practitioners = $appointmentType->getPractitioners();
            if (empty($practitioners)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No practitioners available for this appointment type.',
                ], 404);
            }

            $practitionerId = $practitioners[0]->getId();
        }

        $available = AvailableTimes::findForPractitionerAppointmentType(
            (string) $businessId,
            $practitionerId,
            $appointmentTypeId,
            $from,
            $to,
            cliniko_client(false),
            $page !== null ? (int) $page : null,
            $perPage !== null ? (int) $perPage : null
        );

        if (!$available) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unable to fetch available times from Cliniko.',
            ], 500);
        }

        $availableTimes = array_map(
            fn($dto) => ['appointment_start' => $dto->appointmentStart],
            $available->getAvailableTimes()
        );

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'available_times' => $availableTimes,
                'total_entries' => $available->getTotalEntries(),
                'links' => [
                    'self' => $available->getSelfUrl(),
                    'next' => $available->getNextUrl(),
                    'previous' => $available->getPreviousUrl(),
                ],
                'appointment_type_id' => $appointmentTypeId,
                'practitioner_id' => $practitionerId,
                'from' => $from,
                'to' => $to,
            ],
        ], 200);
    }

    public function getPractitioners(WP_REST_Request $request): WP_REST_Response
    {
        $appointmentTypeId = sanitize_text_field((string) (
            $this->requestParam($request, 'appointment_type_id')
            ?? $this->requestParam($request, 'module_id')
            ?? $this->requestParam($request, 'moduleId')
        ));
        $refreshParam = strtolower(trim((string) $this->requestParam($request, 'refresh')));
        $forceRefresh = in_array($refreshParam, ['1', 'true', 'yes'], true);

        if (empty($appointmentTypeId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing required field: appointment_type_id.',
            ], 422);
        }

        $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(!$forceRefresh));
        if (!$appointmentType) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment type not found.',
            ], 404);
        }

        $practitioners = $appointmentType->getPractitioners();
        if (empty($practitioners)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No practitioners available for this appointment type.',
            ], 404);
        }

        $items = [];
        foreach ($practitioners as $practitioner) {
            $dto = $practitioner->getDTO();
            if ($dto && property_exists($dto, 'active') && $dto->active === false) {
                continue;
            }
            if ($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false) {
                continue;
            }

            $display = '';
            if ($dto && (property_exists($dto, 'firstName') || property_exists($dto, 'lastName'))) {
                $display = trim(($dto->firstName ?? '') . ' ' . ($dto->lastName ?? ''));
            }
            if ($display === '' && $dto && property_exists($dto, 'displayName') && $dto->displayName) {
                $display = $dto->displayName;
            }
            if ($display === '') {
                $display = $practitioner->getId();
            }

            $items[] = [
                'id' => $practitioner->getId(),
                'name' => $display,
            ];
        }

        if (empty($items)) {
            foreach ($practitioners as $practitioner) {
                $dto = $practitioner->getDTO();
            $display = '';
            if ($dto && (property_exists($dto, 'firstName') || property_exists($dto, 'lastName'))) {
                $display = trim(($dto->firstName ?? '') . ' ' . ($dto->lastName ?? ''));
            }
            if ($display === '' && $dto && property_exists($dto, 'displayName') && $dto->displayName) {
                $display = $dto->displayName;
            }
            if ($display === '') {
                $display = $practitioner->getId();
            }

                $items[] = [
                    'id' => $practitioner->getId(),
                    'name' => $display,
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'appointment_type_id' => $appointmentTypeId,
                'practitioners' => $items,
            ],
        ], 200);
    }

    public function getAppointmentCalendar(WP_REST_Request $request): WP_REST_Response
    {
        $appointmentTypeId = sanitize_text_field((string) (
            $this->requestParam($request, 'appointment_type_id')
            ?? $this->requestParam($request, 'module_id')
            ?? $this->requestParam($request, 'moduleId')
        ));
        $practitionerId = sanitize_text_field((string) $this->requestParam($request, 'practitioner_id'));
        $month = sanitize_text_field((string) $this->requestParam($request, 'month'));

        if (empty($appointmentTypeId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing required field: appointment_type_id.',
            ], 422);
        }

        if (empty($practitionerId)) {
            $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(false));
            if (!$appointmentType) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Appointment type not found.',
                ], 404);
            }
            $practitioners = $appointmentType->getPractitioners();
            if (empty($practitioners)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No practitioners available for this appointment type.',
                ], 404);
            }
            $practitionerId = $practitioners[0]->getId();
        }

        $businessId = get_option('wp_cliniko_business_id');
        if (empty($businessId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Cliniko business ID is not configured.',
            ], 400);
        }

        $helperPath = plugin_dir_path(__DIR__) . 'Widgets/ClinikoForm/helpers/appointment_calendar.php';
        if (file_exists($helperPath)) {
            require_once $helperPath;
        } else {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Calendar helper not found.',
            ], 500);
        }

        if (!\function_exists('cliniko_build_appointment_calendar_context') || !\function_exists('cliniko_render_appointment_calendar_grid')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Calendar helper functions are unavailable.',
            ], 500);
        }

        $context = \cliniko_build_appointment_calendar_context([
            'business_id' => (string) $businessId,
            'practitioner_id' => $practitionerId,
            'appointment_type_id' => $appointmentTypeId,
            'month' => $month ?: null,
            'per_page' => 100,
        ]);

        $grid = \cliniko_render_appointment_calendar_grid($context);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'month_label' => $context['month_label'],
                'month_key' => $context['month_key'],
                'grid_html' => $grid,
                'practitioner_id' => $practitionerId,
                'appointment_type_id' => $appointmentTypeId,
            ],
        ], 200);
    }

    public function getNextAvailableTimes(WP_REST_Request $request): WP_REST_Response
    {
        $appointmentTypeId = sanitize_text_field((string) (
            $this->requestParam($request, 'appointment_type_id')
            ?? $this->requestParam($request, 'module_id')
            ?? $this->requestParam($request, 'moduleId')
        ));
        $from = sanitize_text_field((string) $this->requestParam($request, 'from'));
        $to = sanitize_text_field((string) $this->requestParam($request, 'to'));
        $refreshParam = strtolower(trim((string) $this->requestParam($request, 'refresh')));
        $forceRefresh = in_array($refreshParam, ['1', 'true', 'yes'], true);
        $requestedPractitionerIdsRaw = $this->requestParam($request, 'practitioner_ids');

        if (empty($appointmentTypeId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing required field: appointment_type_id.',
            ], 422);
        }

        $clinicTz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('now', $clinicTz);
        if ($from === '') {
            $from = $today->format('Y-m-d');
        }
        if ($to === '') {
            $to = $today->add(new \DateInterval('P90D'))->format('Y-m-d');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD for from/to.',
            ], 422);
        }

        try {
            $fromDate = new \DateTimeImmutable($from, $clinicTz);
            $toDate = new \DateTimeImmutable($to, $clinicTz);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid date value. Use real calendar dates.',
            ], 422);
        }

        if ($toDate < $fromDate) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid date range. `to` must be on or after `from`.',
            ], 422);
        }

        $businessId = get_option('wp_cliniko_business_id');
        if (empty($businessId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Cliniko business ID is not configured.',
            ], 400);
        }

        $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(!$forceRefresh));
        if (!$appointmentType) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment type not found.',
            ], 404);
        }

        $practitioners = $appointmentType->getPractitioners();
        if (empty($practitioners)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No practitioners available for this appointment type.',
            ], 404);
        }

        $requestedPractitionerIds = [];
        if (is_array($requestedPractitionerIdsRaw)) {
            foreach ($requestedPractitionerIdsRaw as $rawId) {
                $id = sanitize_text_field((string) $rawId);
                if ($id !== '') {
                    $requestedPractitionerIds[$id] = true;
                }
            }
        } elseif (is_string($requestedPractitionerIdsRaw) && trim($requestedPractitionerIdsRaw) !== '') {
            foreach (explode(',', $requestedPractitionerIdsRaw) as $rawId) {
                $id = sanitize_text_field(trim((string) $rawId));
                if ($id !== '') {
                    $requestedPractitionerIds[$id] = true;
                }
            }
        }

        $filteredPractitioners = [];
        foreach ($practitioners as $practitioner) {
            $dto = $practitioner->getDTO();
            if ($dto && property_exists($dto, 'active') && $dto->active === false) {
                continue;
            }
            if ($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false) {
                continue;
            }
            $filteredPractitioners[] = $practitioner;
        }
        if (empty($filteredPractitioners)) {
            $filteredPractitioners = $practitioners;
        }

        if (!empty($requestedPractitionerIds)) {
            $filteredPractitioners = array_values(array_filter(
                $filteredPractitioners,
                static function ($practitioner) use ($requestedPractitionerIds): bool {
                    return isset($requestedPractitionerIds[(string) $practitioner->getId()]);
                }
            ));
        }

        $clinikoService = new ClinikoService();
        $freshClient = cliniko_client(false); // always bypass cache for next-available lookups
        $items = [];

        foreach ($filteredPractitioners as $practitioner) {
            $practitionerId = (string) $practitioner->getId();
            $dto = $practitioner->getDTO();

            $display = '';
            if ($dto && (property_exists($dto, 'firstName') || property_exists($dto, 'lastName'))) {
                $display = trim(($dto->firstName ?? '') . ' ' . ($dto->lastName ?? ''));
            }
            if ($display === '' && $dto && property_exists($dto, 'displayName') && $dto->displayName) {
                $display = $dto->displayName;
            }
            if ($display === '') {
                $display = $practitionerId;
            }

            $next = null;
            try {
                $next = $clinikoService->getNextAvailableTime(
                    (string) $businessId,
                    $practitionerId,
                    $appointmentTypeId,
                    $from,
                    $to,
                    $freshClient
                );
            } catch (\Throwable $e) {
                $next = null;
            }

            $items[] = [
                'practitioner_id' => $practitionerId,
                'practitioner_name' => $display,
                'appointment_start' => ($next && !empty($next->appointmentStart)) ? $next->appointmentStart : null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $aStart = $a['appointment_start'] ?? null;
            $bStart = $b['appointment_start'] ?? null;
            $aName = (string) $a['practitioner_name'];
            $bName = (string) $b['practitioner_name'];

            // Keep practitioners without availability at the end.
            if (empty($aStart) && empty($bStart)) {
                return strcmp($aName, $bName);
            }
            if (empty($aStart)) return 1;
            if (empty($bStart)) return -1;

            $aTs = strtotime((string) $aStart);
            $bTs = strtotime((string) $bStart);
            if ($aTs === false && $bTs === false) return 0;
            if ($aTs === false) return 1;
            if ($bTs === false) return -1;

            if ($aTs === $bTs) {
                return strcmp($aName, $bName);
            }

            return $aTs <=> $bTs;
        });

        $response = new WP_REST_Response([
            'success' => true,
            'data' => [
                'appointment_type_id' => $appointmentTypeId,
                'from' => $from,
                'to' => $to,
                'next_available_times' => $items,
            ],
        ], 200);

        // Next-available values should be as fresh as possible.
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');

        return $response;
    }

    /**
     * @return mixed|null
     */
    private function requestParam(WP_REST_Request $request, string $key)
    {
        $params = $request->get_params();
        return array_key_exists($key, $params) ? $params[$key] : null;
    }
}



