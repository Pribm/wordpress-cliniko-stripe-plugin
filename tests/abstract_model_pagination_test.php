<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Model\AppointmentType;
use App\Model\PatientFormTemplate;

final class PagingFakeApiClient implements ApiClientInterface
{
    /** @var list<string> */
    public array $seen = [];

    /** @var array<string,array<string,mixed>> */
    private array $responses;

    /**
     * @param array<string,array<string,mixed>> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url): ClientResponse
    {
        $this->seen[] = $url;

        if (!array_key_exists($url, $this->responses)) {
            return new ClientResponse(null, 'No fake response configured for ' . $url);
        }

        return new ClientResponse($this->responses[$url]);
    }

    public function post(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used in this test.');
    }

    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used in this test.');
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used in this test.');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$appointmentClient = new PagingFakeApiClient([
    'appointment_types?per_page=100' => [
        'appointment_types' => [
            ['id' => 'apt_1', 'name' => 'Initial consult', 'duration_in_minutes' => 30],
        ],
        'links' => [
            'next' => 'https://api.au1.cliniko.com/v1/appointment_types?page=2&per_page=100',
        ],
    ],
    'https://api.au1.cliniko.com/v1/appointment_types?page=2&per_page=100' => [
        'appointment_types' => [
            ['id' => 'apt_2', 'name' => 'Review', 'duration_in_minutes' => 15],
        ],
        'links' => [
            'next' => null,
        ],
    ],
]);

$appointmentTypes = AppointmentType::all($appointmentClient, true);
assert_true(count($appointmentTypes) === 2, 'Expected appointment type pagination to return both pages.');
assert_true(
    $appointmentClient->seen === [
        'appointment_types?per_page=100',
        'https://api.au1.cliniko.com/v1/appointment_types?page=2&per_page=100',
    ],
    'Appointment type pagination did not request the expected URLs.'
);

$templateClient = new PagingFakeApiClient([
    'patient_form_templates?per_page=100' => [
        'patient_form_templates' => [
            ['id' => 'tpl_1', 'name' => 'Intake', 'content' => ['sections' => []]],
        ],
        'links' => [
            'next' => 'https://api.au1.cliniko.com/v1/patient_form_templates?page=2&per_page=100',
        ],
    ],
    'https://api.au1.cliniko.com/v1/patient_form_templates?page=2&per_page=100' => [
        'patient_form_templates' => [
            ['id' => 'tpl_2', 'name' => 'Review form', 'content' => ['sections' => []]],
        ],
        'links' => [
            'next' => null,
        ],
    ],
]);

$templates = PatientFormTemplate::all($templateClient, true);
assert_true(count($templates) === 2, 'Expected patient form template pagination to return both pages.');
assert_true(
    $templateClient->seen === [
        'patient_form_templates?per_page=100',
        'https://api.au1.cliniko.com/v1/patient_form_templates?page=2&per_page=100',
    ],
    'Patient form template pagination did not request the expected URLs.'
);

echo "AbstractModel pagination tests passed.\n";
