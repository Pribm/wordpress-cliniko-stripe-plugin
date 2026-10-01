<?php

namespace App\Routes;

use App\Authorization\Contracts\RoutePolicyInterface;
use App\Authorization\PatientLinkPolicy;
use App\Controller\PatientLinkController;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientLinkRoutes
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
        $policy = $this->policy ?: new PatientLinkPolicy();

        register_rest_route('v2', '/patient-link/request', [
            'methods' => 'POST',
            'callback' => [$controller, 'request'],
            'permission_callback' => [$policy, 'authorize'],
        ]);
        register_rest_route('v2', '/patient-link/status', [
            'methods' => 'GET',
            'callback' => [$controller, 'status'],
            'permission_callback' => [$policy, 'authorize'],
        ]);
    }
}
