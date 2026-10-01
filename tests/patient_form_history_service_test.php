<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\DTO\PatientDTO;
use App\Model\Patient;
use App\Service\PatientFormHistoryService;

final class PatientFormHistoryFakeClient implements ApiClientInterface
{
    /** @var list<string> */
    public array $gets = [];

    public function get(string $url): ClientResponse
    {
        $this->gets[] = $url;
        if ($url === 'patient_forms?page=1&per_page=2&sort=created_at%3Adesc&q[]=patient_id%3A%3Dpatient-44&q[]=completed_at%3A%3F&q[]=archived_at%3A%2A') {
            return new ClientResponse([
                'patient_forms' => [
                    [
                        'id' => 'form-2',
                        'name' => 'Recent form',
                        'completed_at' => '2026-07-20T10:00:00Z',
                        'content' => [
                            'sections' => [[
                                'name' => 'Medical history',
                                'questions' => [[
                                    'name' => 'Allergies',
                                    'type' => 'checkboxes',
                                    'answers' => [
                                        ['value' => 'Peanuts', 'selected' => true],
                                        ['value' => 'Penicillin'],
                                    ],
                                    'other' => ['selected' => true, 'value' => 'Latex'],
                                ]],
                            ]],
                        ],
                    ],
                    [
                        'id' => 'form-1',
                        'name' => 'Older form',
                        'created_at' => '2026-06-01T10:00:00Z',
                    ],
                ],
                'links' => ['next' => 'https://example.test/patient_forms?page=2'],
            ]);
        }
        if ($url === 'patient_forms/form-1') {
            return new ClientResponse([
                'id' => 'form-1',
                'name' => 'Older form',
                'created_at' => '2026-06-01T10:00:00Z',
                'content' => [
                    'sections' => [[
                        'name' => 'Details',
                        'questions' => [[
                            'name' => 'Medication',
                            'type' => 'text',
                            'answer' => 'Aspirin',
                        ]],
                    ]],
                ],
            ]);
        }

        return new ClientResponse(null, 'Unexpected GET ' . $url, 404);
    }

    public function post(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Unexpected POST', 405);
    }

    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Unexpected PUT', 405);
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Unexpected PATCH', 405);
    }
}

function assert_history_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$client = new PatientFormHistoryFakeClient();
$patient = new Patient(PatientDTO::fromArray([
    'id' => 'patient-44',
    'first_name' => 'History',
    'last_name' => 'Patient',
]), $client);

try {
    $result = (new PatientFormHistoryService($client))->forPatient($patient, 1, 2);

    assert_history_same(
        'patient_forms?page=1&per_page=2&sort=created_at%3Adesc&q[]=patient_id%3A%3Dpatient-44&q[]=completed_at%3A%3F&q[]=archived_at%3A%2A',
        $client->gets[0] ?? null,
        'History request was not scoped to the authenticated patient ID.'
    );
    assert_history_same(2, count($result['items']), 'Expected both patient forms.');
    assert_history_same(true, $result['has_more'], 'Expected API next-page metadata to be preserved.');
    assert_history_same(
        ['Peanuts', 'Latex'],
        $result['items'][0]['sections'][0]['questions'][0]['selected_answers'] ?? null,
        'Choice answers were not normalized.'
    );
    assert_history_same(
        'Aspirin',
        $result['items'][1]['sections'][0]['questions'][0]['answer'] ?? null,
        'Detailed form content was not loaded.'
    );

    echo "Patient form history service test passed.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Patient form history service test failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
