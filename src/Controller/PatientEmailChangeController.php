<?php

namespace App\Controller;

use App\Service\PatientEmailChangeService;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientEmailChangeController
{
    private PatientEmailChangeService $service;

    public function __construct(?PatientEmailChangeService $service = null)
    {
        $this->service = $service ?: new PatientEmailChangeService();
    }

    public function request(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->requestForCurrentUser(
            sanitize_email((string) $request->get_param('email'))
        );
        return new WP_REST_Response([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['message'],
        ], $result['http_status']);
    }

    public function verify(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->verify(
            max(0, (int) $request->get_param('user_id')),
            sanitize_text_field((string) $request->get_param('token'))
        );
        return new WP_REST_Response([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['message'],
            'redirect_url' => wp_login_url(),
        ], $result['http_status']);
    }
}
