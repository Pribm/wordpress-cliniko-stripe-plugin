<?php
namespace App\Controller;

use App\Admin\Modules\Credentials;
use App\Client\Cliniko\Client;
use App\Infra\JobDispatcher;
use App\Model\AppointmentType;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH'))
    exit;

class TyroController
{
    /**
     * POST /wp-json/v1/tyrohealth/sdk-token
     * Returns: { token: '...' }
     */
    public function sdkToken(WP_REST_Request $request): WP_REST_Response
    {
        $apiKey = Credentials::getTyroAdminApiKey();
        $appId = Credentials::getTyroAppId();

        if (!$apiKey || !$appId) {
            return new WP_REST_Response([
                'message' => 'Tyro credentials not configured on server.',
            ], 500);
        }

        $base = Credentials::getTyroApiBaseUrl();
        $url = rtrim($base, '/') . '/v3/auth/token';

        $payload = [
            'audience' => 'aud:business-sdk',
            'expiresIn' => '1h',
        ];

        $res = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'x-appid' => $appId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($res)) {
            return new WP_REST_Response([
                'message' => 'Request error: ' . $res->get_error_message(),
            ], 500);
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);

        if ($code < 200 || $code >= 300 || empty($body['token'])) {
            $msg = $body['message'] ?? ('HTTP ' . $code);
            return new WP_REST_Response([
                'message' => 'Token mint failed: ' . $msg,
            ], 500);
        }

        return new WP_REST_Response([
            'token' => $body['token'],
        ], 200);
    }

    public static function createInvoice(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();

        $moduleId = isset($body['moduleId']) ? sanitize_text_field($body['moduleId']) : '';
        if (!$moduleId) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing moduleId.',
            ], 400);
        }

        // 1) Fetch price from moduleId (Cliniko appointment type)
        try {
            $appointmentType = AppointmentType::find($moduleId, cliniko_client(true));
            if (!$appointmentType) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Appointment type not found for moduleId.',
                ], 404);
            }

            $priceInCents = (int) $appointmentType->getBillableItemsFinalPrice(); // you already have this
            if ($priceInCents <= 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Invalid price for this module.',
                ], 400);
            }

            $chargeAmount = number_format($priceInCents / 100, 2, '.', ''); // "100.00"
        } catch (\Throwable $e) {
            error_log('[TyroHealth] Price lookup failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to calculate price.',
            ], 500);
        }

        //$invoiceReference = 'APT-' . $moduleId . '-' . substr(uniqid(), -8);
        $providerNumber = Credentials::getTyroProviderNumber(); // optional

        // NOTE: We do not call Tyro from backend here.
        // Frontend uses short-lived SDK token + MedipassTransactionSDK.renderCreateTransaction.
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'chargeAmount' => $chargeAmount,
                'invoiceReference' => $appointmentType->getName(),
                'providerNumber' => $providerNumber,
            ],
        ], 200);
    }

    /**
     * POST /wp-json/v1/tyrohealth/charge
     * Mirrors PaymentController::charge but uses Tyro transactionId instead of stripeToken.
     */
    public static function charge(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();

        $moduleId = $body['moduleId'] ?? null;
        $patientFormTemplateId = $body['patient_form_template_id'] ?? null;
        $transactionId = $body['tyroTransactionId'] ?? ($body['transactionId'] ?? null);
        $invoiceReference = $body['invoiceReference'] ?? null;

        $patient = is_array($body['patient'] ?? null) ? $body['patient'] : json_decode($body['patient'] ?? '[]', true);
        $content = is_array($body['content'] ?? null) ? $body['content'] : json_decode($body['content'] ?? '[]', true);
        $signatureAttachmentId = $body['signature_attachment_id'] ?? null;

        if (!$moduleId || !$patientFormTemplateId) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing required fields: moduleId, patient_form_template_id.'
            ], 422);
        }

        $client = Client::getInstance();
        $apptType = AppointmentType::find($moduleId, $client);

        if (!$apptType) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Appointment type not found.'], 404);
        }

        // If no payment is required, schedule directly
        if (!$apptType->requiresPayment()) {
            $payload = [
                'patient' => $patient,
                'content' => $content,
                'signature_attachment_id' => $signatureAttachmentId,
            ];

            $payloadKey = 'cliniko_job_payload_free_' . uniqid();
            if (!add_option($payloadKey, $payload, '', false)) {
                update_option($payloadKey, $payload, false);
            }

            $dispatcher = new JobDispatcher();
            $dispatcher->enqueue(
                'cliniko_schedule_appointment',
                [
                    'moduleId' => $moduleId,
                    'patient_form_template_id' => $patientFormTemplateId,
                    'payment_reference' => null,
                    'amount' => 0,
                    'currency' => 'aud',
                    'payload_key' => $payloadKey,
                    'appointment_label' => $apptType->getName(),
                ],
                5,
                $payloadKey
            );

            return new WP_REST_Response([
                'status' => 'success',
                'payment' => [
                    'id' => null,
                    'amount' => 0,
                    'currency' => 'aud',
                    'receipt_url' => null,
                ],
                'scheduling' => ['status' => 'queued'],
            ], 200);
        }

        if (!$transactionId) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing required field: tyroTransactionId.',
            ], 422);
        }

        $amount = $apptType->getBillableItemsFinalPrice();

        // Store heavy payload server-side; pass only a reference to the worker
        $payload = [
            'patient' => $patient,
            'content' => $content,
            'signature_attachment_id' => $signatureAttachmentId,
        ];

        $payloadKey = 'cliniko_job_payload_' . $transactionId;
        if (!add_option($payloadKey, $payload, '', false)) {
            update_option($payloadKey, $payload, false);
        }

        $dispatcher = new JobDispatcher();
        $dispatcher->enqueue(
            'cliniko_schedule_appointment',
            [
                'moduleId' => $moduleId,
                'patient_form_template_id' => $patientFormTemplateId,
                'payment_reference' => $transactionId,
                'amount' => $amount,
                'currency' => 'aud',
                'payload_key' => $payloadKey,
                'appointment_label' => $apptType->getName(),
            ],
            5,
            $transactionId
        );

        return new WP_REST_Response([
            'status' => 'success',
            'payment' => [
                'id' => $transactionId,
                'amount' => $amount,
                'currency' => 'aud',
                'receipt_url' => null,
            ],
            'scheduling' => ['status' => 'queued'],
        ], 200);
    }

    private static function getSiteOrigin(): string
    {
        $site = get_site_url();
        $parts = wp_parse_url($site);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }
}
