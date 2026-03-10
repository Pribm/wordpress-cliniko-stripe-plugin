<?php

namespace App\Controller;

use App\Service\BookingAttemptService;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class BookingAttemptController
{
    private BookingAttemptService $service;

    public function __construct(?BookingAttemptService $service = null)
    {
        $this->service = $service ?: new BookingAttemptService();
    }

    public function preflight(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $result = $this->service->preflight(is_array($body) ? $body : []);
        return new WP_REST_Response($this->withoutMeta($result), (int) ($result['status'] ?? 500));
    }

    public function chargeStripe(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $attemptId = trim((string) ($body['attempt_id'] ?? ''));
        $stripeToken = trim((string) ($body['stripeToken'] ?? ''));
        $result = $this->service->chargeStripe($attemptId, $stripeToken);
        return new WP_REST_Response($this->withoutMeta($result), (int) ($result['status'] ?? 500));
    }

    public function confirmTyro(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $attemptId = trim((string) ($body['attempt_id'] ?? ''));
        $transactionId = trim((string) ($body['transactionId'] ?? ($body['tyroTransactionId'] ?? '')));
        $result = $this->service->confirmTyroPayment($attemptId, $transactionId);
        return new WP_REST_Response($this->withoutMeta($result), (int) ($result['status'] ?? 500));
    }

    public function finalize(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();
        $attemptId = trim((string) ($body['attempt_id'] ?? ''));
        $result = $this->service->finalize($attemptId);
        return new WP_REST_Response($this->withoutMeta($result), (int) ($result['status'] ?? 500));
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $attemptId = trim((string) ($request->get_param('attempt_id') ?? ''));
        $result = $this->service->getStatus($attemptId);
        return new WP_REST_Response($this->withoutMeta($result), (int) ($result['status'] ?? 500));
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withoutMeta(array $result): array
    {
        unset($result['status']);
        return $result;
    }
}
