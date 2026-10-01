<?php

namespace App\Routes;

use App\Authorization\VerifiedPatientPolicy;
use App\Controller\BookingAttemptController;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthenticatedBookingRoutes
{
    public function register(): void
    {
        $controller = new BookingAttemptController();
        $patientPolicy = new VerifiedPatientPolicy();

        register_rest_route('v2', '/patient-booking-attempts/preflight', [
            'methods' => 'POST',
            'callback' => [$controller, 'preflightAuthenticated'],
            'permission_callback' => [$patientPolicy, 'authorize'],
        ]);

        register_rest_route('v2', '/cliniko-form-booking-attempts/preflight', [
            'methods' => 'POST',
            'callback' => [$controller, 'preflightAuthenticatedWidget'],
            'permission_callback' => [$patientPolicy, 'authorize'],
        ]);

    }
}
