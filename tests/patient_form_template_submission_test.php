<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\DTO\PatientDTO;
use App\Model\Patient;
use App\Service\PatientFormTemplateSubmissionService;

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $name === 'wp_cliniko_business_id' ? 'business-1' : $default;
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

final class PatientFormTemplateSubmissionFakeClient implements ApiClientInterface
{
    /** @var array<string,mixed>|null */
    public ?array $createdPayload = null;

    public function get(string $url): ClientResponse
    {
        if ($url === 'patient_form_templates/template-empty') {
            return new ClientResponse([
                'id' => 'template-empty',
                'name' => 'Empty template',
                'email_to_patient_on_completion' => false,
                'restricted_to_practitioner' => true,
                'archived_at' => '2026-01-01T00:00:00Z',
                'content' => ['sections' => []],
                'links' => ['self' => 'https://example.test/patient_form_templates/template-empty'],
            ]);
        }
        if ($url !== 'patient_form_templates/template-1') {
            return new ClientResponse(null, 'Unexpected GET ' . $url, 404);
        }

        return new ClientResponse([
            'id' => 'template-1',
            'name' => 'Health questionnaire',
            'email_to_patient_on_completion' => false,
            'restricted_to_practitioner' => true,
            'archived_at' => '2026-01-01T00:00:00Z',
            'content' => [
                'sections' => [[
                    'name' => 'Medical history',
                    'description' => 'Tell us about your health.',
                    'questions' => [
                        [
                            'name' => 'Current medication',
                            'type' => 'text',
                            'required' => true,
                        ],
                        [
                            'name' => 'Allergies',
                            'type' => 'checkboxes',
                            'required' => true,
                            'answers' => [
                                ['value' => 'Peanuts'],
                                ['value' => 'Penicillin'],
                            ],
                            'other' => ['enabled' => true],
                        ],
                    ],
                ]],
            ],
            'links' => ['self' => 'https://example.test/patient_form_templates/template-1'],
        ]);
    }

    public function post(string $url, array $data): ClientResponse
    {
        if ($url !== 'patient_forms') {
            return new ClientResponse(null, 'Unexpected POST ' . $url, 404);
        }

        $this->createdPayload = $data;
        return new ClientResponse([
            'id' => 'patient-form-1',
            'name' => $data['name'] ?? '',
            'content' => $data['content'] ?? ['sections' => []],
        ]);
    }

    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Unexpected PUT ' . $url, 405);
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Unexpected PATCH ' . $url, 405);
    }
}

function assert_submission_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$client = new PatientFormTemplateSubmissionFakeClient();
$patient = new Patient(PatientDTO::fromArray([
    'id' => 'patient-1',
    'first_name' => 'Test',
    'last_name' => 'Patient',
]), $client);

try {
    $created = (new PatientFormTemplateSubmissionService($client))->submit(
        $patient,
        'template-1',
        'Health questionnaire',
        [
            0 => [
                0 => '<b>Aspirin</b>',
                1 => ['Peanuts', '__other__', 'Injected option'],
            ],
        ],
        [
            0 => [
                1 => '<script>Latex</script>',
            ],
        ]
    );

    $payload = $client->createdPayload ?? [];
    assert_submission_same('patient-form-1', $created->getId(), 'Created patient form ID mismatch.');
    assert_submission_same('patient-1', $payload['patient_id'] ?? null, 'Authenticated patient ID was not used.');
    assert_submission_same('template-1', $payload['patient_form_template_id'] ?? null, 'Configured template ID was not used.');
    assert_submission_same('business-1', $payload['business_id'] ?? null, 'Configured business ID was not used.');
    assert_submission_same(true, $payload['completed'] ?? null, 'Patient form should be completed on submission.');
    assert_submission_same(
        'Aspirin',
        $payload['content']['sections'][0]['questions'][0]['answer'] ?? null,
        'Text answer was not sanitized.'
    );
    assert_submission_same(
        true,
        $payload['content']['sections'][0]['questions'][1]['answers'][0]['selected'] ?? null,
        'Allowed checkbox answer was not selected.'
    );
    assert_submission_same(
        null,
        $payload['content']['sections'][0]['questions'][1]['answers'][1]['selected'] ?? null,
        'Unselected checkbox answer should not be sent as selected.'
    );
    assert_submission_same(
        'Latex',
        $payload['content']['sections'][0]['questions'][1]['other']['value'] ?? null,
        'Other answer was not sanitized.'
    );

    (new PatientFormTemplateSubmissionService($client))->submit(
        $patient,
        'template-empty',
        'Empty template',
        []
    );
    assert_submission_same(
        [],
        $client->createdPayload['content']['sections'] ?? null,
        'An empty patient form template should submit with an empty sections collection.'
    );

    echo "Patient form template submission test passed.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Patient form template submission test failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
