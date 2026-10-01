<?php

namespace App\Authorization;

use App\Authorization\Contracts\RoutePolicyInterface;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientLinkVerificationPolicy implements RoutePolicyInterface
{
    /** @return bool|mixed */
    public function authorize(WP_REST_Request $request)
    {
        $userId = (int) $request->get_param('user_id');
        $token = (string) $request->get_param('token');
        if ($userId > 0 && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1) {
            return true;
        }

        return class_exists('\WP_Error')
            ? new \WP_Error('invalid_patient_verification', 'A valid verification token is required.', ['status' => 400])
            : false;
    }
}
