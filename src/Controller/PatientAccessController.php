<?php

namespace App\Controller;

use App\Service\PatientAccessService;
use App\Service\PatientAccessTokenService;
use App\Service\PublicRequestGuard;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccessController
{
    private PatientAccessService $service;
    private PatientAccessTokenService $tokens;

    public function __construct(
        ?PatientAccessService $service = null,
        ?PatientAccessTokenService $tokens = null
    ) {
        $this->service = $service ?: new PatientAccessService();
        $this->tokens = $tokens ?: new PatientAccessTokenService();
    }

    public function requestCode(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $result = $this->service->requestAccessCode(is_array($body) ? $body : []);
        return new WP_REST_Response($this->withoutStatus($result), (int) ($result['status'] ?? 500));
    }

    public function requestLink(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $result = $this->service->requestAccessCode(is_array($body) ? $body : []);
        return new WP_REST_Response($this->withoutStatus($result), (int) ($result['status'] ?? 500));
    }

    public function verifyCode(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $result = $this->service->verifyAccessCode(is_array($body) ? $body : []);
        return new WP_REST_Response($this->withoutStatus($result), (int) ($result['status'] ?? 500));
    }

    public function requestStatus(WP_REST_Request $request): WP_REST_Response
    {
        $requestId = trim((string) ($request->get_param('request_id') ?? ''));
        $result = $this->service->getRequestStatus($requestId);
        return new WP_REST_Response($this->withoutStatus($result), (int) ($result['status'] ?? 500));
    }

    public function completeRequest(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $bodyParams = is_array($body) ? $body : [];
        $requestId = trim((string) ($bodyParams['request_id'] ?? $request->get_param('request_id') ?? ''));
        $token = $this->readToken($request, $bodyParams);
        $result = $this->service->completeRequest($requestId, $token);
        return new WP_REST_Response($this->withoutStatus($result), (int) ($result['status'] ?? 500));
    }

    public function appointments(WP_REST_Request $request): WP_REST_Response
    {
        $claims = $this->readClaims($request);
        if ($claims === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Invalid or expired patient access token.',
            ], 403);
        }

        $limit = (int) ($request->get_param('limit') ?? 5);
        $appointments = $this->service->getHistory(
            (string) $claims['sub'],
            $limit,
            (string) ($claims['appointment_type_id'] ?? ''),
            is_array($claims['patient_ids'] ?? null) ? $claims['patient_ids'] : []
        );

        return new WP_REST_Response([
            'ok' => true,
            'appointments' => $appointments,
            'expires_in' => max(0, ((int) ($claims['exp'] ?? time())) - time()),
        ], 200);
    }

    public function prefill(WP_REST_Request $request): WP_REST_Response
    {
        $claims = $this->readClaims($request);
        if ($claims === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Invalid or expired patient access token.',
            ], 403);
        }

        $bookingId = trim((string) ($request->get_param('booking_id') ?? ''));
        $prefill = $this->service->getPrefill(
            (string) $claims['sub'],
            $bookingId,
            (string) ($claims['appointment_type_id'] ?? ''),
            is_array($claims['patient_ids'] ?? null) ? $claims['patient_ids'] : []
        );
        if ($prefill === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Appointment history item not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'ok' => true,
            'data' => $prefill,
            'expires_in' => max(0, ((int) ($claims['exp'] ?? time())) - time()),
        ], 200);
    }

    public function latest(WP_REST_Request $request): WP_REST_Response
    {
        $claims = $this->readClaims($request);
        if ($claims === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Invalid or expired patient access token.',
            ], 403);
        }

        $prefill = $this->service->getLatestPrefill(
            (string) $claims['sub'],
            (string) ($claims['appointment_type_id'] ?? ''),
            is_array($claims['patient_ids'] ?? null) ? $claims['patient_ids'] : [],
            [
                'booking_id' => (string) ($claims['latest_booking_id'] ?? ''),
                'patient_id' => (string) ($claims['latest_patient_id'] ?? ''),
                'starts_at' => (string) ($claims['latest_starts_at'] ?? ''),
                'appointment_label' => (string) ($claims['latest_appointment_label'] ?? ''),
                'practitioner_id' => (string) ($claims['latest_practitioner_id'] ?? ''),
                'practitioner_name' => (string) ($claims['latest_practitioner_name'] ?? ''),
            ]
        );
        if ($prefill === null) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'No completed appointment was found for this booking type.',
            ], 404);
        }

        return new WP_REST_Response([
            'ok' => true,
            'data' => $prefill,
            'expires_in' => max(0, ((int) ($claims['exp'] ?? time())) - time()),
        ], 200);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readClaims(WP_REST_Request $request): ?array
    {
        $token = $this->readToken($request);

        if ($token === '') {
            return null;
        }

        return $this->tokens->validate($token);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function readToken(WP_REST_Request $request, array $body = []): string
    {
        return trim((string) (
            $request->get_header(PublicRequestGuard::PATIENT_ACCESS_TOKEN_HEADER)
            ?: ($body['patient_access_token'] ?? '')
            ?: ($body['access_token'] ?? '')
            ?: $request->get_param('patient_access_token')
            ?: $request->get_param('access_token')
            ?: ''
        ));
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withoutStatus(array $result): array
    {
        unset($result['status']);
        return $result;
    }
}
