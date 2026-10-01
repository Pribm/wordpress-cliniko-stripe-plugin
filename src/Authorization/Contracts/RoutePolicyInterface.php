<?php

namespace App\Authorization\Contracts;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

interface RoutePolicyInterface
{
    /**
     * Return true when the request may continue, otherwise a WP_Error or false.
     *
     * @return bool|mixed
     */
    public function authorize(WP_REST_Request $request);
}
