<?php
namespace App\Controller;

use App\Client\Cliniko\Client;
use App\Infra\JobDispatcher;
use App\Model\AppointmentType;
use App\Service\StripeService;
use App\Validator\AppointmentRequestValidator;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH'))
    exit;

class PaymentController
{
    /**
     * POST /wp-json/wp-cliniko/v1/payments/charge
     * Body (JSON):
     * {
     *   "moduleId": "123",
     *   "stripeToken": "tok_xxx",
     *   "patient": { ... },
     *   "content":  { ... }, // patient form content sections
     *   "patient_form_template_id": "999",
     *   "signature_attachment_id": 123  // optional, if you pre-uploaded
     * }
     */
    public function charge(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();

        $moduleId = $body['moduleId'] ?? null;
        $stripeToken = $body['stripeToken'] ?? null;
        $patientFormTemplateId = $body['patient_form_template_id'] ?? null;

        // Forwarded to the background worker (but stored server-side to keep action args tiny):
        $patient = is_array($body['patient'] ?? null) ? $body['patient'] : json_decode($body['patient'] ?? '[]', true);
        $content = is_array($body['content'] ?? null) ? $body['content'] : json_decode($body['content'] ?? '[]', true);
        $signatureAttachmentId = $body['signature_attachment_id'] ?? null;

        $clinikoPatient = $patient;

        // Validate payload early (before any payment attempt)
        $validationPayload = $body;
        $validationPayload['patient'] = $patient;
        $validationPayload['content'] = $content;

        $errors = AppointmentRequestValidator::validate($validationPayload, false);
        
        if (!empty($errors)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid request parameters.',
                'errors' => $errors,
            ], 422);
        }

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

        if (!$apptType->requiresPayment()) {
            // 1) Build payload (patient + form content)
            $payload = [
                'patient' => $clinikoPatient,
                'content' => $content,
                'signature_attachment_id' => $signatureAttachmentId,
            ];

            $payloadKey = 'cliniko_job_payload_free_' . uniqid();
            if (!add_option($payloadKey, $payload, '', false)) {
                update_option($payloadKey, $payload, false);
            }

            // 2) Enqueue background scheduling job without any payment reference
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
                $payloadKey // idempotency key
            );

            return new WP_REST_Response([
                'status' => 'success',
                'payment' => [
                    'id' => null,
                    'amount' => 0,
                    'currency' => 'aud',
                    'receipt_url' => null,
                    'card_last4' => null,
                    'brand' => null,
                ],
                'scheduling' => ['status' => 'queued'],
            ], 200);
        }

        if (!$stripeToken) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing required field: stripeToken.'
            ], 422);
        }

        if (!preg_match('/^(tok_|pm_)/', $stripeToken)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid payment token.'
            ], 422);
        }

        $amount = $apptType->getBillableItemsFinalPrice();
        $description = $apptType->getName();

        // Don’t propagate sensitive values into Stripe metadata:
        $stripeMeta = $patient;
        unset($stripeMeta['medicare'], $stripeMeta['medicare_reference_number']);

        try {
            // 1) Charge Stripe
            $stripe = new StripeService();
            $charge = $stripe->createChargeFromToken(
                $stripeToken,
                $amount,
                $description,
                $stripeMeta,             // metadata
                $patient['email'] ?? null
            );

            if (!$charge || empty($charge->id)) {
                return new WP_REST_Response(['status' => 'error', 'message' => 'Payment failed.'], 402);
            }

            // 2) Store heavy payload server-side; pass only a small reference to the worker
            $payload = [
                'patient' => $clinikoPatient,     // potentially large
                'content' => $content,            // potentially very large
                'signature_attachment_id' => $signatureAttachmentId,
            ];

            $payloadKey = 'cliniko_job_payload_' . $charge->id;
            // Autoload = 'no' so this doesn’t bloat memory on every request
            if (
                !add_option(
                    $payloadKey,
                    $payload,
                    '',
                    false
                )
            ) {
                update_option($payloadKey, $payload, false);
            }

            // 3) Enqueue background scheduling job (idempotent by charge id) with tiny args
            $dispatcher = new JobDispatcher();
            $dispatcher->enqueue(
                'cliniko_schedule_appointment',
                [
                    'moduleId' => $moduleId,
                    'patient_form_template_id' => $patientFormTemplateId,
                    'payment_reference' => $charge->id,
                    'amount' => $amount,
                    'currency' => 'aud',
                    'payload_key' => $payloadKey,   // <-- worker will load the heavy data
                    'appointment_label' => $apptType->getName()
                ],
                5,
                $charge->id
            );

            // 4) Return immediately; scheduling runs in background
            return new WP_REST_RESPONSE([
                'status' => 'success',
                'payment' => [
                    'id' => $charge->id,
                    'amount' => $amount,
                    'currency' => 'aud',
                    'receipt_url' => $charge->receipt_url ?? null,
                    'card_last4' => $charge->payment_method_details->card->last4 ?? null,
                    'brand' => $charge->payment_method_details->card->brand ?? null,
                ],
                'scheduling' => ['status' => 'queued']
            ], 200);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Unexpected error during payment.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }
}
