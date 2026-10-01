<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Service\AppointmentCountService;

final class AppointmentCountFakeClient implements ApiClientInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function get(string $url): ClientResponse
    {
        $this->seen[] = $url;
        if (count($this->seen) === 1) {
            return new ClientResponse([
                'individual_appointments' => [
                    [
                        'starts_at' => gmdate('c', time() - 7200),
                        'ends_at' => gmdate('c', time() - 3600),
                        'did_not_arrive' => false,
                    ],
                    [
                        'starts_at' => gmdate('c', time() - 7200),
                        'ends_at' => gmdate('c', time() - 3600),
                        'did_not_arrive' => true,
                    ],
                ],
                'links' => ['next' => 'https://api.au1.cliniko.com/v1/individual_appointments?page=2'],
            ]);
        }

        return new ClientResponse([
            'individual_appointments' => [
                [
                    'starts_at' => gmdate('c', time() + 3600),
                    'ends_at' => gmdate('c', time() + 7200),
                    'did_not_arrive' => false,
                ],
                [
                    'starts_at' => gmdate('c', time() - 600),
                    'ends_at' => gmdate('c', time() + 600),
                    'did_not_arrive' => false,
                ],
            ],
            'links' => ['next' => null],
        ]);
    }

    public function post(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used.');
    }

    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used.');
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used.');
    }
}

function assert_appointment_count(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$client = new AppointmentCountFakeClient();
$counts = (new AppointmentCountService($client))->forPatient('patient_1');

assert_appointment_count($counts['total'] === 4, 'Expected all appointments to be counted.');
assert_appointment_count($counts['upcoming'] === 1, 'Expected only future appointments to be upcoming.');
assert_appointment_count($counts['completed'] === 1, 'Expected completed appointments to exclude did-not-arrive records.');
assert_appointment_count(count($client->seen) === 2, 'Expected all Cliniko pagination links to be followed.');

echo "Appointment count service tests passed.\n";
