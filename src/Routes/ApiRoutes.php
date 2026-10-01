<?php

namespace App\Routes;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiRoutes
{
    private PublicGuestRoutes $publicGuestRoutes;
    private AuthenticatedPatientRoutes $authenticatedPatientRoutes;
    private PatientLinkRoutes $patientLinkRoutes;
    private PublicPatientLinkRoutes $publicPatientLinkRoutes;
    private AuthenticatedBookingRoutes $authenticatedBookingRoutes;

    public function __construct(
        ?PublicGuestRoutes $publicGuestRoutes = null,
        ?AuthenticatedPatientRoutes $authenticatedPatientRoutes = null,
        ?PatientLinkRoutes $patientLinkRoutes = null,
        ?PublicPatientLinkRoutes $publicPatientLinkRoutes = null
    ) {
        $this->publicGuestRoutes = $publicGuestRoutes ?: new PublicGuestRoutes();
        $this->authenticatedPatientRoutes = $authenticatedPatientRoutes ?: new AuthenticatedPatientRoutes();
        $this->patientLinkRoutes = $patientLinkRoutes ?: new PatientLinkRoutes();
        $this->publicPatientLinkRoutes = $publicPatientLinkRoutes ?: new PublicPatientLinkRoutes();
        $this->authenticatedBookingRoutes = new AuthenticatedBookingRoutes();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $this->publicGuestRoutes->register();
        $this->publicPatientLinkRoutes->register();
        $this->patientLinkRoutes->register();
        $this->authenticatedPatientRoutes->register();
        $this->authenticatedBookingRoutes->register();
    }
}
