<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

use App\DTO\TyroHealthTransactionDTO;
use App\Service\TyroHealthService;

/**
 * @return array<string,mixed>
 */
function tyro_transaction_sample(): array
{
    return [
        '_id' => '6a20d1a079662bdafaa8d056',
        'transactionId' => '0000-9261-9423',
        'invoiceReference' => 'ONLINEPRE-196490464174',
        'transactionType' => 'invoice',
        'originatingFlow' => 'virtual-terminal',
        'currencyCode' => 'AUD',
        'status' => 'completed',
        'businessStatus' => 'completed',
        'amountOutstanding' => 0,
        'amountBalance' => 0,
        'amountCharged' => 2500,
        'amountOutstandingString' => '$0.00',
        'amountBalanceString' => '$0.00',
        'amountChargedString' => '$25.00',
        'payments' => [
            [
                '_id' => '6a20d1a295558bf90f0c547f',
                'status' => 'approved',
                'amount' => 2500,
                'amountString' => '$25.00',
                'paymentType' => 'sale',
                'gatewayRefId' => '26060430314',
            ],
        ],
    ];
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_tyro_transaction_dto_maps_fields_and_nested_payments(): void
{
    $transaction = TyroHealthTransactionDTO::fromArray(tyro_transaction_sample());

    assert_same('6a20d1a079662bdafaa8d056', $transaction->id, 'Tyro DTO should map _id');
    assert_same('0000-9261-9423', $transaction->transactionId, 'Tyro DTO should map transactionId');
    assert_same('ONLINEPRE-196490464174', $transaction->invoiceReference, 'Tyro DTO should map invoiceReference');
    assert_same('completed', $transaction->status, 'Tyro DTO should map status');
    assert_same('completed', $transaction->businessStatus, 'Tyro DTO should map businessStatus');
    assert_same(2500, $transaction->amountChargedCents, 'Tyro DTO should map amountCharged');
    assert_same(0, $transaction->amountOutstandingCents, 'Tyro DTO should map amountOutstanding');
    assert_same(0, $transaction->amountBalanceCents, 'Tyro DTO should map amountBalance');
    assert_true(isset($transaction->payments[0]), 'Tyro DTO should map nested payments');
    assert_same('approved', $transaction->payments[0]->status, 'Tyro DTO should map nested payment status');
    assert_same(2500, $transaction->payments[0]->getAmountCents(), 'Tyro DTO should map nested payment amount');
}

function test_tyro_service_accepts_completed_paid_transaction(): void
{
    $transaction = TyroHealthTransactionDTO::fromArray(tyro_transaction_sample());
    $service = new TyroHealthService();

    assert_true(
        $service->isPaidTransaction($transaction, 2500, 'ONLINEPRE-196490464174'),
        'Tyro service should accept the approved, settled transaction'
    );
}

function test_tyro_service_rejects_unapproved_or_mismatched_transaction(): void
{
    $service = new TyroHealthService();

    $statusMismatch = tyro_transaction_sample();
    $statusMismatch['status'] = 'pending';
    $statusMismatch['businessStatus'] = 'pending';
    $statusMismatch['payments'][0]['status'] = 'pending';
    $statusMismatchTransaction = TyroHealthTransactionDTO::fromArray($statusMismatch);
    assert_true(
        !$service->isPaidTransaction($statusMismatchTransaction, 2500, 'ONLINEPRE-196490464174'),
        'Tyro service should reject a transaction that has not completed'
    );

    $invoiceMismatch = TyroHealthTransactionDTO::fromArray(tyro_transaction_sample());
    assert_true(
        !$service->isPaidTransaction($invoiceMismatch, 2500, 'WRONG-REFERENCE'),
        'Tyro service should reject a mismatched invoice reference'
    );

    $amountMismatch = TyroHealthTransactionDTO::fromArray(tyro_transaction_sample());
    assert_true(
        !$service->isPaidTransaction($amountMismatch, 2600, 'ONLINEPRE-196490464174'),
        'Tyro service should reject a mismatched amount'
    );
}

function run_test(string $name, callable $test): bool
{
    try {
        $test();
        echo "[PASS] {$name}\n";
        return true;
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: " . get_class($e) . ': ' . $e->getMessage() . " ({$e->getFile()}:{$e->getLine()})\n";
        return false;
    }
}

$tests = [
    'tyro_transaction_dto_maps_fields_and_nested_payments' => 'test_tyro_transaction_dto_maps_fields_and_nested_payments',
    'tyro_service_accepts_completed_paid_transaction' => 'test_tyro_service_accepts_completed_paid_transaction',
    'tyro_service_rejects_unapproved_or_mismatched_transaction' => 'test_tyro_service_rejects_unapproved_or_mismatched_transaction',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    if (run_test($name, $fn)) {
        $passed++;
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} Tyro health tests passed.\n";

exit($passed === $total ? 0 : 1);
