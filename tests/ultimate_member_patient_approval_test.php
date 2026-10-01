<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

$GLOBALS['um_patient_test_meta'] = [];

function update_user_meta(int $userId, string $key, $value): bool
{
    $GLOBALS['um_patient_test_meta'][$userId][$key] = $value;
    return true;
}

final class UltimateMemberUsersFake
{
    /** @var array<int,string> */
    public array $statuses = [];
    /** @var list<array{user_id:int,force:bool}> */
    public array $pendingCalls = [];
    /** @var list<array{user_id:int,force:bool}> */
    public array $approvalCalls = [];

    public function get_status(int $userId): string
    {
        return $this->statuses[$userId] ?? '';
    }

    public function set_as_pending(int $userId, bool $force = false): bool
    {
        $this->pendingCalls[] = ['user_id' => $userId, 'force' => $force];
        $this->statuses[$userId] = 'awaiting_admin_review';
        return true;
    }

    public function approve(int $userId, bool $force = false): bool
    {
        $this->approvalCalls[] = ['user_id' => $userId, 'force' => $force];
        $this->statuses[$userId] = 'approved';
        return true;
    }
}

final class UltimateMemberCommonFake
{
    public function __construct(private UltimateMemberUsersFake $users)
    {
    }

    public function users(): UltimateMemberUsersFake
    {
        return $this->users;
    }
}

final class UltimateMemberFake
{
    public function __construct(private UltimateMemberCommonFake $common)
    {
    }

    public function common(): UltimateMemberCommonFake
    {
        return $this->common;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use App\Service\PatientService;
use App\Service\UltimateMemberAccountService;

function assert_um_patient(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$users = new UltimateMemberUsersFake();
$GLOBALS['ultimatemember'] = new UltimateMemberFake(new UltimateMemberCommonFake($users));

PatientService::markAccountPendingReview(21);
assert_um_patient(
    $users->statuses[21] === UltimateMemberAccountService::STATUS_PENDING_REVIEW,
    'Patient registration did not enter Ultimate Member admin review.'
);
assert_um_patient(count($users->pendingCalls) === 1, 'Ultimate Member pending operation was not called exactly once.');
assert_um_patient($users->pendingCalls[0]['force'], 'Ultimate Member pending operation was not forced for registration.');
assert_um_patient(
    $GLOBALS['um_patient_test_meta'][21][PatientService::META_ACCOUNT_STATUS] === PatientService::ACCOUNT_PENDING_REVIEW,
    'Plugin patient status did not remain aligned with Ultimate Member pending review.'
);

$activated = PatientService::markAccountActive(21);
assert_um_patient($activated, 'Synchronized patient was not activated.');
assert_um_patient(
    $users->statuses[21] === UltimateMemberAccountService::STATUS_APPROVED,
    'Synchronized patient was not approved through Ultimate Member.'
);
assert_um_patient(count($users->approvalCalls) === 1, 'Ultimate Member approval operation was not called exactly once.');
assert_um_patient($users->approvalCalls[0]['force'], 'Ultimate Member approval did not use the supported forced transition.');
assert_um_patient(
    $GLOBALS['um_patient_test_meta'][21][PatientService::META_ACCOUNT_STATUS] === PatientService::ACCOUNT_ACTIVE,
    'Plugin patient status did not become active after Ultimate Member approval.'
);

$users->statuses[22] = UltimateMemberAccountService::STATUS_REJECTED;
$rejectedActivated = PatientService::markAccountActive(22);
assert_um_patient(!$rejectedActivated, 'An administrator-rejected Ultimate Member account was incorrectly approved.');
assert_um_patient(
    $users->statuses[22] === UltimateMemberAccountService::STATUS_REJECTED,
    'The administrator-rejected Ultimate Member status was overwritten.'
);
assert_um_patient(
    $GLOBALS['um_patient_test_meta'][22][PatientService::META_ACCOUNT_STATUS] === PatientService::ACCOUNT_PENDING_REVIEW,
    'Rejected patient did not remain blocked in the plugin.'
);

echo "Ultimate Member patient approval tests passed.\n";
