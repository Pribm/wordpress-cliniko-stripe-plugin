<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Exception\ApiException;

if (!defined('ABSPATH')) {
    exit;
}

/** Patient-scoped implementation of Cliniko's three-step attachment flow. */
final class ClinikoAttachmentService
{
    private ApiClientInterface $client;

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: Client::getInstance();
    }

    /** @return array<string,mixed> */
    public function getPresignedPost(string $patientId): array
    {
        $response = $this->client->get('patients/' . rawurlencode($patientId) . '/attachment_presigned_post');
        if (!$response->isSuccessful() || !is_array($response->data) || empty($response->data['url']) || !is_array($response->data['fields'] ?? null)) {
            throw new ApiException('Cliniko could not prepare the file upload.', [
                'error' => $response->error,
                'status_code' => $response->statusCode,
            ]);
        }

        return $response->data;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForPatient(string $patientId): array
    {
        $query = '?per_page=100&q[]=' . rawurlencode('patient_id:=' . $patientId);
        $response = $this->client->get('patient_attachments' . $query);
        if (!$response->isSuccessful() || !is_array($response->data)) {
            throw new ApiException('Cliniko could not load patient attachments.', ['error' => $response->error]);
        }

        $attachments = $response->data['patient_attachments'] ?? $response->data;
        return is_array($attachments) ? array_values(array_filter($attachments, 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    public function createAttachmentRecord(string $patientId, string $uploadUrl, string $description = ''): array
    {
        $this->assertUploadBelongsToPatient($patientId, $uploadUrl);
        $response = $this->client->post('patient_attachments', [
            'patient_id' => $patientId,
            'upload_url' => $uploadUrl,
            'description' => $description,
        ]);

        if (!$response->isSuccessful() || !is_array($response->data)) {
            throw new ApiException('Cliniko could not create the patient attachment.', ['error' => $response->error]);
        }

        return $response->data;
    }

    /** @param array<string,mixed> $presigned */
    public function uploadToS3(string $filePath, string $filename, array $presigned): string
    {
        if (!is_file($filePath)) {
            throw new ApiException('The uploaded file could not be found.');
        }

        $fields = [];
        foreach (($presigned['fields'] ?? []) as $key => $value) {
            $fields[(string) $key] = str_replace('${filename}', $filename, (string) $value);
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($filePath) : 'application/octet-stream';
        $fields['file'] = new \CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $filename);

        $handle = curl_init((string) ($presigned['url'] ?? ''));
        if ($handle === false) {
            throw new ApiException('The S3 upload could not be started.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $caBundle = $this->caBundlePath();
        if ($caBundle !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $caBundle);
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $status !== 201) {
            throw new ApiException('The file could not be uploaded to Cliniko storage.', ['status_code' => $status, 'error' => $error]);
        }

        preg_match('/<Location>(.*?)<\/Location>/s', (string) $body, $matches);
        $uploadUrl = urldecode(html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($uploadUrl === '') {
            throw new ApiException('The S3 upload response did not contain a file location.');
        }
        return $uploadUrl;
    }

    private function caBundlePath(): string
    {
        $configured = [
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
        ];
        if (defined('ABSPATH')) {
            $configured[] = rtrim(ABSPATH, '/\\') . '/wp-includes/certificates/ca-bundle.crt';
        }
        foreach ($configured as $path) {
            if ($path !== '' && is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    public function uploadPatientAttachment(string $patientId, string $filePath, string $filename, string $description = ''): array
    {
        $presigned = $this->getPresignedPost($patientId);
        $uploadUrl = $this->uploadToS3($filePath, $filename, $presigned);
        return $this->createAttachmentRecord($patientId, $uploadUrl, $description);
    }

    private function assertUploadBelongsToPatient(string $patientId, string $uploadUrl): void
    {
        $parts = wp_parse_url($uploadUrl);
        $path = (string) ($parts['path'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || $path === '' || !str_contains(rawurldecode($path), '/patients/' . $patientId . '/attachments/')) {
            throw new ApiException('The uploaded file does not belong to this patient.');
        }
    }
}
