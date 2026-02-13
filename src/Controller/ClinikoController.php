<?php
namespace App\Controller;

use App\Infra\JobDispatcher;
use App\Model\AppointmentType;
use App\Model\AvailableTimes;
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
        $jobDispatcher->enqueue('cliniko_async_create_patient_form', ['payload_key' => $payloadKey], 2);

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
            $request->get_param('appointment_type_id')
            ?? $request->get_param('module_id')
            ?? $request->get_param('moduleId')
        ));
        $from = sanitize_text_field((string) $request->get_param('from'));
        $to = sanitize_text_field((string) $request->get_param('to'));
        $practitionerId = sanitize_text_field((string) $request->get_param('practitioner_id'));
        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');

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
            $request->get_param('appointment_type_id')
            ?? $request->get_param('module_id')
            ?? $request->get_param('moduleId')
        ));
        $refreshParam = strtolower(trim((string) $request->get_param('refresh')));
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
            $request->get_param('appointment_type_id')
            ?? $request->get_param('module_id')
            ?? $request->get_param('moduleId')
        ));
        $practitionerId = sanitize_text_field((string) $request->get_param('practitioner_id'));
        $month = sanitize_text_field((string) $request->get_param('month'));

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

        $context = cliniko_build_appointment_calendar_context([
            'business_id' => (string) $businessId,
            'practitioner_id' => $practitionerId,
            'appointment_type_id' => $appointmentTypeId,
            'month' => $month ?: null,
            'per_page' => 100,
        ]);

        $grid = cliniko_render_appointment_calendar_grid($context);

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
}



