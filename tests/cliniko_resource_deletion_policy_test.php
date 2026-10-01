<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Model\AppointmentType;
use App\Model\PatientFormTemplate;

final class DeletionPolicyFakeClient implements ApiClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function get(string $url): ClientResponse
    {
        $this->calls[] = 'GET ' . $url;
        return new ClientResponse([]);
    }

    public function post(string $url, array $data): ClientResponse
    {
        $this->calls[] = 'POST ' . $url;
        return new ClientResponse([]);
    }

    public function put(string $url, array $data): ClientResponse
    {
        $this->calls[] = 'PUT ' . $url;
        return new ClientResponse([]);
    }

    public function patch(string $url, array $data): ClientResponse
    {
        $this->calls[] = 'PATCH ' . $url;
        return new ClientResponse([]);
    }
}

function assert_deletion_blocked(string $modelClass, string $id): void
{
    $client = new DeletionPolicyFakeClient();
    $blocked = false;

    try {
        $modelClass::delete($id, $client);
    } catch (LogicException $exception) {
        $blocked = true;
    }

    if (!$blocked) {
        throw new RuntimeException("Expected {$modelClass} deletion to be blocked.");
    }
    if ($client->calls !== []) {
        throw new RuntimeException("Deletion policy for {$modelClass} made a Cliniko API request.");
    }
}

$resources = [
    PatientFormTemplate::class => 'template-1',
    AppointmentType::class => 'appointment-type-1',
];

$passed = 0;
foreach ($resources as $modelClass => $id) {
    try {
        assert_deletion_blocked($modelClass, $id);
        echo "[PASS] {$modelClass} deletion blocked\n";
        $passed++;
    } catch (Throwable $exception) {
        echo "[FAIL] {$modelClass}: {$exception->getMessage()}\n";
    }
}

$total = count($resources);
echo "\n{$passed}/{$total} Cliniko resource deletion policy tests passed.\n";

exit($passed === $total ? 0 : 1);
