<?php

namespace App\Controller;

use App\Service\PatientService;
use App\Service\PatientEmailChangeService;
use App\Service\DashboardAppointmentService;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class PatientController
{
    private PatientService $patientService;
    private DashboardAppointmentService $dashboardAppointments;
    private PatientEmailChangeService $emailChanges;

    public function __construct(
        ?PatientService $patientService = null,
        ?DashboardAppointmentService $dashboardAppointments = null,
        ?PatientEmailChangeService $emailChanges = null
    ) {
        $this->patientService = $patientService ?: new PatientService();
        $this->dashboardAppointments = $dashboardAppointments ?: new DashboardAppointmentService();
        $this->emailChanges = $emailChanges ?: new PatientEmailChangeService();
    }

    public function getPatient(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return $this->unauthorized();
        }

        $patient = $this->patientService->getPatientForCurrentUser();
        if ($patient === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Patient not found.',
            ], 404);
        }

        return new WP_REST_Response(['ok' => true, 'data' => $patient], 200);
    }

    public function updatePatient(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return $this->unauthorized();
        }

        $body = json_decode($request->get_body(), true);
        $data = is_array($body) ? $body : $request->get_params();
        $emailChange = null;
        if (isset($data['email']) && is_scalar($data['email'])) {
            $requestedEmail = sanitize_email((string) $data['email']);
            unset($data['email']);
            $user = wp_get_current_user();
            $currentEmail = strtolower(trim((string) $user->user_email));
            if ($requestedEmail !== '' && !hash_equals($currentEmail, strtolower($requestedEmail))) {
                $emailChange = $this->emailChanges->requestForCurrentUser($requestedEmail);
            }
        }
        $patient = $this->patientService->updatePatientForCurrentUser($data);

        if ($patient === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Patient not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'ok' => $emailChange === null || $emailChange['ok'],
            'data' => $patient,
            'email_change' => $emailChange,
        ], is_array($emailChange) ? $emailChange['http_status'] : 200);
    }

    public function appointments(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) return $this->unauthorized();
        $result = $this->dashboardAppointments->forAuthenticatedPatient(
            sanitize_text_field((string) $request->get_param('appointment_type_id')),
            max(1, (int) $request->get_param('page')),
            max(1, (int) ($request->get_param('per_page') ?: 5)),
            (string) ($request->get_param('order') ?: 'asc')
        );
        return new WP_REST_Response(['ok' => true, 'data' => $result], 200);
    }

    public function appointment(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) return $this->unauthorized();
        $appointment = $this->dashboardAppointments->findForAuthenticatedPatient(
            sanitize_text_field((string) $request->get_param('appointment_id'))
        );
        if ($appointment === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Appointment not found.'], 404);
        }
        return new WP_REST_Response(['ok' => true, 'data' => $appointment], 200);
    }

    private function unauthorized(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => false,
            'message' => 'Invalid or expired patient access token.',
        ], 403);
    }
}
