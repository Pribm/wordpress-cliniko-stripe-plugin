<?php

namespace App\Controller;

use App\Service\PatientLinkService;
use App\Service\PatientService;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientLinkController
{
    private PatientService $patients;
    private PatientLinkService $links;

    public function __construct(
        ?PatientService $patients = null,
        ?PatientLinkService $links = null
    ) {
        $this->patients = $patients ?: new PatientService();
        $this->links = $links ?: new PatientLinkService();
    }

    public function request(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->patients->syncUser((int) get_current_user_id(), true);
        return new WP_REST_Response([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['error'] !== '' ? $result['error'] : 'Verification email sent.',
        ], $result['status'] === PatientLinkService::STATUS_PENDING ? 202 : ($result['ok'] ? 200 : 400));
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'data' => $this->links->status((int) get_current_user_id()),
        ], 200);
    }

    public function verify(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->links->verify(
            max(0, (int) $request->get_param('user_id')),
            sanitize_text_field((string) $request->get_param('token'))
        );

        return new WP_REST_Response([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['message'],
            'redirect_url' => home_url('/'),
        ], $result['http_status']);
    }
}
