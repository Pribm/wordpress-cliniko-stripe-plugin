<?php

namespace App\Authorization;

use App\Authorization\Contracts\RoutePolicyInterface;
use App\Exception\ApiException;
use App\Support\Auth;
use App\Service\PatientLinkService;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifiedPatientPolicy implements RoutePolicyInterface
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

        $userId = (int) get_current_user_id();
        if (!$this->links->userHasConfiguredRole($userId)) {
            return $this->deny('This account does not have an authorized patient role.');
        }
        if (!$this->links->isVerifiedUser($userId)) {
            return $this->deny('Patient email verification is required.', 'patient_email_verification_required');
        }

        try {
            if (Auth::user() === null) {
                return $this->deny('The linked Cliniko patient no longer exists.', 'cliniko_patient_not_found');
            }
        } catch (ApiException $exception) {
            return $this->deny('Cliniko is temporarily unavailable. Please try again shortly.', 'cliniko_connection_unavailable', 503);
        }

        return true;
    }

    /** @return mixed */
    private function deny(string $message, string $code = 'rest_forbidden', int $status = 403)
    {
        return class_exists('\WP_Error')
            ? new \WP_Error($code, $message, ['status' => $status])
            : false;
    }
}
