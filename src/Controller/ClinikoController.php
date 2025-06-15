<?php
namespace App\Controller;

if (!defined('ABSPATH')) exit;

use App\Service\ClinikoService;
use App\Service\StripeService;

use WP_REST_Request;
use WP_REST_Response;

class ClinikoController
{
    protected ClinikoService $clinikoService;

    public function __construct()
    {
        $this->clinikoService = new ClinikoService();
    }

    public function scheduleAppointment(WP_REST_Request $request): WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);

        if (!is_array($payload)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid JSON body received.'
            ], 400);
        }

        $paymentIntentId = $payload['paymentIntentId'] ?? null;
        if (!$paymentIntentId) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing paymentIntentId.',
            ], 400);
        }

        $stripeService = new StripeService();
        $paymentIntent = $stripeService->retrievePaymentIntent($paymentIntentId);

        if (!$paymentIntent || $paymentIntent->status !== 'succeeded') {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Payment not confirmed or invalid.',
            ], 403);
        }

        $missingFields = [];

        if (empty($payload['moduleId'])) {
            $missingFields[] = 'moduleId';
        }

        if (empty($payload['patient']) || !is_array($payload['patient'])) {
            $missingFields[] = 'patient';
        } else {
            // Verificação de campos esperados no paciente
            foreach (['first_name', 'last_name', 'email', 'appointment_type_id'] as $field) {
                if (empty($payload['patient'][$field])) {
                    $missingFields[] = "patient.$field";
                }
            }
        }

        if (!empty($missingFields)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing or invalid required fields.',
                'missing' => $missingFields
            ], 400);
        }

        try {
            $appointment = $this->clinikoService->createAppointmentWithPatient($payload['patient']);

            return new WP_REST_Response([
                'status' => 'success',
                'appointment' => $appointment,
            ]);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
