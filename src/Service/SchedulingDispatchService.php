<?php
namespace App\Service;

use App\Infra\JobDispatcher;

if (!defined('ABSPATH'))
    exit;

class SchedulingDispatchService
{
    private const DEFAULT_DELAY_SECONDS = 0;

    /**
     * Persist heavy payload and enqueue the scheduling worker.
     *
     * @param array<string,mixed> $patient
     * @param array<string,mixed> $content
     * @return array{payload_key:string,unique_key:string}
     */
    public function dispatch(
        string $moduleId,
        string $patientFormTemplateId,
        array $patient,
        array $content,
        $signatureAttachmentId,
        ?string $paymentReference,
        int $amount,
        string $currency,
        string $appointmentLabel,
        int $delaySeconds = self::DEFAULT_DELAY_SECONDS
    ): array {
        $payloadKey = $this->storePayload($patient, $content, $signatureAttachmentId, $paymentReference);
        $uniqueKey = $paymentReference ?: $payloadKey;

        $dispatcher = new JobDispatcher();
        $dispatcher->enqueue(
            'cliniko_schedule_appointment',
            [
                'moduleId' => $moduleId,
                'patient_form_template_id' => $patientFormTemplateId,
                'payment_reference' => $paymentReference,
                'amount' => $amount,
                'currency' => $currency,
                'payload_key' => $payloadKey,
                'appointment_label' => $appointmentLabel,
            ],
            $delaySeconds,
            $uniqueKey
        );

        return [
            'payload_key' => $payloadKey,
            'unique_key' => $uniqueKey,
        ];
    }

    /**
     * @param array<string,mixed> $patient
     * @param array<string,mixed> $content
     */
    private function storePayload(
        array $patient,
        array $content,
        $signatureAttachmentId,
        ?string $paymentReference
    ): string {
        $payload = [
            'patient' => $patient,
            'content' => $content,
            'signature_attachment_id' => $signatureAttachmentId,
        ];

        $payloadKey = $this->buildPayloadKey($paymentReference);

        if (!add_option($payloadKey, $payload, '', false)) {
            update_option($payloadKey, $payload, false);
        }

        return $payloadKey;
    }

    private function buildPayloadKey(?string $paymentReference): string
    {
        if ($paymentReference) {
            return 'cliniko_job_payload_' . $paymentReference;
        }

        return 'cliniko_job_payload_free_' . uniqid();
    }
}
