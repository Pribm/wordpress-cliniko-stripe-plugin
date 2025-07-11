<?php
namespace App\Service;

use App\Client\ClinikoClient;
use App\Exception\ApiException;

class ClinikoAttachmentService
{
    protected ClinikoClient $client;

    public function __construct()
    {
        $this->client = ClinikoClient::getInstance();
    }

    /**
     * Step 1: Get presigned POST info from Cliniko
     */
    public function getPresignedPost(int $patientId): array
    {
        $response = $this->client->get("patients/{$patientId}/attachment_presigned_post");

        if (empty($response['url']) || empty($response['fields'])) {
            throw new ApiException("Invalid response from presigned URL request", ['response' => $response]);
        }

        return $response;
    }

    /**
     * Step 2: Upload file to S3 using presigned fields
     */
    public function uploadToS3(string $filePath, array $presigned): string
    {
        if (!file_exists($filePath)) {
            throw new ApiException("File does not exist: {$filePath}");
        }

        $fields = $presigned['fields'];
        $url = $presigned['url'];

        // Usa o nome real que será usado no S3 (evita ${filename} quebrado)
        $filename = $_FILES['signature_file']['name'] ?? basename($filePath);
        $mime = mime_content_type($filePath);

        foreach ($fields as $key => $value) {
            $fields[$key] = str_replace('${filename}', $filename, $value);
        }

        $fields['file'] = new \CURLFile($filePath, $mime, $filename);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HEADER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new ApiException("S3 upload failed", [
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'response' => $response,
            ]);
        }

        // Extrai a URL do XML
        preg_match('/<Location>(.*?)<\/Location>/', $response, $matches);
       $uploadUrl = urldecode(html_entity_decode($matches[1] ?? ''));

        if (empty($uploadUrl)) {
            throw new ApiException("Failed to extract upload URL from S3 response", ['xml' => $response]);
        }

        return $uploadUrl;
    }


    /**
     * Step 3: Register uploaded file as an attachment in Cliniko
     */
    public function createAttachmentRecord(int $patientId, string $uploadUrl, string $description = ''): array
    {
            return $this->client->post('patient_attachments', [
            'patient_id' => $patientId,
            'upload_url' => $uploadUrl,
            'description' => $description
        ]);
    }

    /**
     * Upload full process: presign, upload, register
     */
    public function uploadPatientAttachment(int $patientId, string $filePath, string $description = ''): array
    {
        $presigned = $this->getPresignedPost($patientId);
        $uploadUrl = $this->uploadToS3($filePath, $presigned);
        return $this->createAttachmentRecord($patientId, $uploadUrl, $description);
    }
}
