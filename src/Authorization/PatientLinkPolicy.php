<?php

namespace App\Authorization;

use App\Authorization\Contracts\RoutePolicyInterface;
use App\Service\PatientLinkService;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientLinkPolicy implements RoutePolicyInterface
{
    private RoutePolicyInterface $authentication;
    private PatientLinkService $links;

    public function __construct(
        ?RoutePolicyInterface $authentication = null,
        ?PatientLinkService $links = null
    ) {
        $this->authentication = $authentication ?: new AuthenticatedPatientPolicy();
        $this->links = $links ?: new PatientLinkService();
    }

    /** @return bool|mixed */
    public function authorize(WP_REST_Request $request)
    {
        $authenticated = $this->authentication->authorize($request);
        if ($authenticated !== true) {
            return $authenticated;
        }
        if ($this->links->userHasConfiguredRole((int) get_current_user_id())) {
            return true;
        }

        return class_exists('\WP_Error')
            ? new \WP_Error('rest_forbidden', 'This account does not have an authorized patient role.', ['status' => 403])
            : false;
    }
}
