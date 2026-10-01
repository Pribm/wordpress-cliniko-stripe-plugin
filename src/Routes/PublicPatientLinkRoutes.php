<?php

namespace App\Routes;

use App\Authorization\Contracts\RoutePolicyInterface;
use App\Authorization\PatientLinkVerificationPolicy;
use App\Controller\PatientLinkController;
use App\Controller\PatientEmailChangeController;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicPatientLinkRoutes
{
    private ?PatientLinkController $controller;
    private ?RoutePolicyInterface $policy;

    public function __construct(
        ?PatientLinkController $controller = null,
        ?RoutePolicyInterface $policy = null
    ) {
        $this->controller = $controller;
        $this->policy = $policy;
    }

    public function register(): void
    {
        $controller = $this->controller ?: new PatientLinkController();
        $policy = $this->policy ?: new PatientLinkVerificationPolicy();

        register_rest_route('v2', '/patient-link/verify', [
            'methods' => ['GET', 'POST'],
            'callback' => [$controller, 'verify'],
            'permission_callback' => [$policy, 'authorize'],
            'args' => [
                'user_id' => ['required' => true, 'type' => 'integer'],
                'token' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        $emailChanges = new PatientEmailChangeController();
        register_rest_route('v2', '/patient/email-change/verify', [
            'methods' => ['GET', 'POST'],
            'callback' => [$emailChanges, 'verify'],
            'permission_callback' => [$policy, 'authorize'],
            'args' => [
                'user_id' => ['required' => true, 'type' => 'integer'],
                'token' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }
}
