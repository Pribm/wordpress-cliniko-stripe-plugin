<?php

namespace App\Authorization;

use App\Authorization\Contracts\RoutePolicyInterface;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthenticatedPatientPolicy implements RoutePolicyInterface
{
    /** @return bool|mixed */
    public function authorize(WP_REST_Request $request)
    {

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return true;
        }

        if (class_exists('\WP_Error')) {
            return new \WP_Error('rest_forbidden', 'You must be logged in.', ['status' => 403]);
        }

        return false;
    }
}
