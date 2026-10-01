<?php

namespace App\Routes;

use App\Authorization\VerifiedPatientPolicy;
use App\Authorization\Contracts\RoutePolicyInterface;
use App\Controller\PatientController;
use App\Controller\PatientEmailChangeController;
use App\Controller\PatientAttachmentController;
use App\Controller\PatientCommunicationController;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthenticatedPatientRoutes
{
    private ?PatientController $controller;
    private ?RoutePolicyInterface $policy;

    public function __construct(
        ?PatientController $controller = null,
        ?RoutePolicyInterface $policy = null
    ) {
        $this->controller = $controller;
        $this->policy = $policy;
    }

    public function register(): void
    {
        $controller = $this->controller ?: new PatientController();
        $policy = $this->policy ?: new VerifiedPatientPolicy();
        $attachments = new PatientAttachmentController();
        $communications = new PatientCommunicationController();

        register_rest_route('v2', '/patient/me', [
            'methods' => 'GET',
            'callback' => [$controller, 'getPatient'],
            'permission_callback' => [$policy, 'authorize'],
        ]);

        register_rest_route('v2', '/patient/me/appointments', [
            'methods' => 'GET',
            'callback' => [$controller, 'appointments'],
            'permission_callback' => [$policy, 'authorize'],
        ]);

        register_rest_route('v2', '/patient/me/appointments/(?P<appointment_id>[\w-]+)', [
            'methods' => 'GET',
            'callback' => [$controller, 'appointment'],
            'permission_callback' => [$policy, 'authorize'],
        ]);

        register_rest_route('v2', '/patient/me', [
            'methods' => ['PUT', 'PATCH'],
            'callback' => [$controller, 'updatePatient'],
            'permission_callback' => [$policy, 'authorize'],
        ]);

        $emailChanges = new PatientEmailChangeController();
        register_rest_route('v2', '/patient/me/email-change', [
            'methods' => 'POST',
            'callback' => [$emailChanges, 'request'],
            'permission_callback' => [$policy, 'authorize'],
            'args' => [
                'email' => ['required' => true, 'type' => 'string', 'format' => 'email'],
            ],
        ]);

        register_rest_route('v2', '/patient/me/attachments', [
            'methods' => 'GET', 'callback' => [$attachments, 'list'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/attachments/presign', [
            'methods' => 'POST', 'callback' => [$attachments, 'presign'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/attachments', [
            'methods' => 'POST', 'callback' => [$attachments, 'create'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/attachments/upload', [
            'methods' => 'POST', 'callback' => [$attachments, 'upload'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/attachments/(?P<attachment_id>[0-9]+)', [
            'methods' => ['PATCH'], 'callback' => [$attachments, 'update'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/attachments/(?P<attachment_id>[0-9]+)', [
            'methods' => ['DELETE'], 'callback' => [$attachments, 'archive'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/communications', [
            'methods' => 'GET', 'callback' => [$communications, 'list'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/communications/unread', [
            'methods' => 'GET', 'callback' => [$communications, 'unread'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/communications/read', [
            'methods' => 'POST', 'callback' => [$communications, 'markRead'], 'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient/me/communications', [
            'methods' => 'POST', 'callback' => [$communications, 'create'], 'permission_callback' => [$policy, 'authorize'],
        ]);
    }
}
