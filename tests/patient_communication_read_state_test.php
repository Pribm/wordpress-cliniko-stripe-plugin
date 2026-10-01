<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');
$GLOBALS['patient_communication_read_state_meta'] = [];

function get_user_meta(int $userId, string $key, bool $single = false)
{
    return $GLOBALS['patient_communication_read_state_meta'][$userId][$key] ?? '';
}

function update_user_meta(int $userId, string $key, $value): bool
{
    $GLOBALS['patient_communication_read_state_meta'][$userId][$key] = $value;
    return true;
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\DTO\CommunicationDTO;
use App\Model\Communication;
use App\Service\PatientCommunicationReadStateService;

function assert_patient_communication_read_state(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$client = new class implements ApiClientInterface {
    public function get(string $url): ClientResponse { throw new RuntimeException('Not used in this test.'); }
    public function post(string $url, array $data): ClientResponse { throw new RuntimeException('Not used in this test.'); }
    public function put(string $url, array $data): ClientResponse { throw new RuntimeException('Not used in this test.'); }
    public function patch(string $url, array $data): ClientResponse { throw new RuntimeException('Not used in this test.'); }
};
$communications = [
    new Communication(new CommunicationDTO(id: '101', directionCode: 1, typeCode: 2), $client),
    new Communication(new CommunicationDTO(id: '102', directionCode: 1, typeCode: 1), $client),
    new Communication(new CommunicationDTO(id: '103', directionCode: 1, typeCode: 4), $client),
    new Communication(new CommunicationDTO(id: '104', directionCode: 2, typeCode: 4), $client),
];

$service = new PatientCommunicationReadStateService();
$summary = $service->unreadSummary($communications, 7);
assert_patient_communication_read_state($summary === ['general' => 1, 'email' => 1, 'sms' => 1, 'total' => 3], 'Only clinic-sent communications should be initially unread.');
assert_patient_communication_read_state($service->unreadIds($communications, 7) === [101 => true, 102 => true, 103 => true], 'Unread IDs must only include clinic-sent communications.');
assert_patient_communication_read_state($service->markRead(['101', '103', 'not-an-id'], 7) === ['101', '103'], 'Valid newly-read IDs were not returned.');
$summary = $service->unreadSummary($communications, 7);
assert_patient_communication_read_state($summary === ['general' => 0, 'email' => 0, 'sms' => 1, 'total' => 1], 'Read state was not kept per user.');
assert_patient_communication_read_state($service->unreadIds($communications, 7) === [102 => true], 'Read communications must no longer be returned as unread.');
assert_patient_communication_read_state($service->markRead(['101'], 7) === [], 'An already-read communication must not be counted again.');

echo "Patient communication read-state tests passed.\n";
