<?php
namespace App\Controller;

use App\Infra\JobDispatcher;
use App\Validator\PatientFormValidator;


if (!defined('ABSPATH'))
    exit;


use WP_REST_Request;
use WP_REST_Response;

class ClinikoController
{
    public function createPatientForm(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true) ?: $request->get_params();

        // 1️⃣ Validação de payload
        $errors = PatientFormValidator::validate($body);
        if (!empty($errors)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $errors,
            ], 400);
        }

        // 2️⃣ Extração dos dados principais
        $templateId = $body['patient_form_template_id'] ?? null;
        $patient = $body['patient'] ?? [];
        $content = $body['content'] ?? [];
        $moduleId = $body['moduleId'] ?? null;
        $bookedTime = $patient['patient_booked_time'] ?? null;

        // 3️⃣ Monta o payload completo (como no Worker)
        $payload = [
            'patient' => $patient,
            'content' => $content,
            'moduleId' => $moduleId,
            'patient_form_template_id' => $templateId,
            'patient_booked_time' => $bookedTime,
        ];

        // 4️⃣ Armazena o payload no banco
        $payloadKey = 'cliniko_pf_job_payload_' . uniqid();
        error_log("[Dispatch] Saving payload $payloadKey...");
        if (!add_option($payloadKey, $payload, '', false)) {
            update_option($payloadKey, $payload, false);
        }
        error_log("[Dispatch] Added option: " . (get_option($payloadKey) ? 'yes' : 'no'));
        $jobDispatcher = new JobDispatcher();
        // 5️⃣ Enfileira o Worker de forma assíncrona
        $jobDispatcher->enqueue('cliniko_async_create_patient_form', ['payload_key' => $payloadKey], 2);

        // 6️⃣ Retorna resposta imediata ao frontend
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Patient form creation has been queued.',
            'queued' => [
                'payload_key' => $payloadKey,
                'status' => 'queued',
            ],
        ], 202);
    }
}



