<?php
namespace App\Service;

use ErrorException;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Exception\InvalidRequestException;

if (!defined('ABSPATH')) {
    exit;
}

class StripeService
{
    private string $customerNamespace;

    public function __construct()
    {
        $apiKey = (string) \wp_cliniko_get_secret_option('wp_stripe_secret_key');
        Stripe::setApiKey($apiKey);
        // Keep mappings separate between Stripe accounts and test/live keys.
        $this->customerNamespace = hash('sha256', $apiKey);
    }

    /**
     * Create or retrieve the Stripe Customer associated with a Cliniko patient,
     * then create the card PaymentIntent for this booking attempt.
     *
     * @param array<string,scalar|null> $metadata
     * @param array<string,string> $customerAddress Stripe-format address fields
     */
    public function createPaymentIntentForPatient(
        string $patientId,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        int $amount,
        string $description = '',
        array $metadata = [],
        string $idempotencyKey = '',
        array $customerAddress = []
    ): PaymentIntent {
        $customer = $this->getOrCreateCustomer(
            $patientId,
            $customerName,
            $customerEmail,
            $customerPhone,
            $customerAddress
        );

        $params = [
            'amount' => $amount,
            'currency' => 'aud',
            'customer' => (string) $customer->id,
            'description' => $description,
            'payment_method_types' => ['card'],
            'metadata' => $this->normalizeMetadata($metadata),
        ];

        if ($customerEmail !== '') {
            $params['receipt_email'] = $customerEmail;
        }

        // Future-use setup is intentionally omitted until the checkout collects
        // and records explicit consent for off-session charges.
        $options = $idempotencyKey !== '' ? ['idempotency_key' => $idempotencyKey] : [];

        return PaymentIntent::create($params, $options);
    }

    /**
     * Retrieve a PaymentIntent and expand its successful Charge for receipt and
     * card details. The Charge object is retained as the booking payment ref so
     * existing refund handling can continue to refund by charge ID.
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId, ['expand' => ['latest_charge']]);
    }

    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::cancel($paymentIntentId);
    }

    public function retrieveCharge(string $chargeId): Charge
    {
        return Charge::retrieve($chargeId);
    }

    /**
     * Legacy v1 compatibility. The active booking-attempt flow uses
     * createPaymentIntentForPatient(); this remains for the deprecated v1 route.
     */
    public function createChargeFromToken(
        string $token,
        int $amount,
        string $description = '',
        array $metadata = [],
        string $receiptEmail = '',
        string $idempotencyKey = ''
    ) {
        try {
            $params = [
                'amount' => $amount,
                'currency' => 'aud',
                'source' => $token,
                'description' => $description,
            ];

            if (!empty($metadata)) {
                $params['metadata'] = $metadata;
            }

            if (!empty($receiptEmail)) {
                $params['receipt_email'] = $receiptEmail;
            }

            return Charge::create($params, $idempotencyKey !== '' ? ['idempotency_key' => $idempotencyKey] : []);
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage());
        }
    }

    /**
     * Full or partial refund.
     * @param string      $chargeId  The Stripe charge id (e.g., ch_xxx)
     * @param int|null    $amount    Amount in cents; null = full refund
     * @param string|null $reason    'requested_by_customer' | 'duplicate' | 'fraudulent'
     * @param array       $metadata  Extra context
     * @return \Stripe\Refund
     */
    public function refundCharge(string $chargeId, ?int $amount = null, ?string $reason = null, array $metadata = [])
    {
        $params = ['charge' => $chargeId];
        if ($amount !== null) {
            $params['amount'] = $amount;
        }
        if ($reason !== null) {
            $params['reason'] = $reason;
        }
        if (!empty($metadata)) {
            $params['metadata'] = $metadata;
        }

        return Refund::create($params);
    }

    private function getOrCreateCustomer(
        string $patientId,
        string $name,
        string $email,
        string $phone,
        array $address
    ): Customer
    {
        $patientId = trim($patientId);
        $name = trim($name);
        $email = trim($email);
        $phone = trim($phone);

        if ($patientId === '') {
            throw new \InvalidArgumentException('A Cliniko patient ID is required for a Stripe Customer.');
        }

        $patientReference = hash_hmac('sha256', $patientId, $this->customerNamespace);
        $mappingKey = $this->customerMappingKey($patientId);
        $customer = null;

        if (function_exists('get_option')) {
            $mappedId = trim((string) get_option($mappingKey, ''));
            if (strncmp($mappedId, 'cus_', 4) === 0) {
                try {
                    $candidate = Customer::retrieve($mappedId);
                    if (empty($candidate->deleted)) {
                        $customer = $candidate;
                    }
                } catch (InvalidRequestException $e) {
                    // A stale/deleted mapping can be repaired from Stripe's email lookup below.
                }
            }
        }

        if (!$customer) {
            $customer = $this->findExistingCustomer($email, $name, $patientReference);
        }

        $customerParams = [
            'metadata' => ['cliniko_patient_ref' => $patientReference],
        ];
        if ($name !== '') {
            $customerParams['name'] = $name;
        }
        if ($email !== '') {
            $customerParams['email'] = $email;
        }
        if ($phone !== '') {
            $customerParams['phone'] = $phone;
        }
        $address = $this->normalizeCustomerAddress($address);
        if ($address !== []) {
            $customerParams['address'] = $address;
        }

        if ($customer) {
            $updates = [];
            foreach (['name', 'email', 'phone'] as $field) {
                if (isset($customerParams[$field]) && (string) ($customer->{$field} ?? '') !== (string) $customerParams[$field]) {
                    $updates[$field] = $customerParams[$field];
                }
            }
            $currentPatientReference = (string) ($customer->metadata->cliniko_patient_ref ?? '');
            if (!hash_equals($patientReference, $currentPatientReference)) {
                $updates['metadata'] = $customerParams['metadata'];
            }
            if ($address !== []) {
                $currentAddress = $customer->address ?? null;
                $addressUpdates = [];
                foreach ($address as $field => $value) {
                    if (trim((string) ($currentAddress->{$field} ?? '')) !== $value) {
                        $addressUpdates[$field] = $value;
                    }
                }
                if ($addressUpdates !== []) {
                    $updates['address'] = $addressUpdates;
                }
            }
            if ($updates !== []) {
                $customer = Customer::update((string) $customer->id, $updates);
            }
        } else {
            $customer = Customer::create(
                $customerParams,
                ['idempotency_key' => 'cliniko-customer-' . $patientReference]
            );
        }

        if (function_exists('update_option')) {
            update_option($mappingKey, (string) $customer->id, false);
        }

        return $customer;
    }

    /**
     * Keep only the address fields Stripe supports and never send an empty
     * address object that could clear a previously saved Customer address.
     * @param array<string,mixed> $address
     * @return array<string,string>
     */
    private function normalizeCustomerAddress(array $address): array
    {
        $result = [];
        foreach (['line1', 'line2', 'city', 'state', 'postal_code'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if ($value !== '') {
                $result[$field] = $value;
            }
        }

        $country = PatientSubmissionSanitizer::countryCode($address['country'] ?? null);
        if ($country !== null) {
            $result['country'] = $country;
        }

        return $result;
    }

    private function findExistingCustomer(string $email, string $name, string $patientReference): ?Customer
    {
        if ($email === '') {
            return null;
        }

        $emailQueries = array_values(array_unique([$email, strtolower($email)]));
        $customerById = [];
        foreach ($emailQueries as $emailQuery) {
            $params = ['email' => $emailQuery, 'limit' => 100];
            do {
                $customers = Customer::all($params);
                foreach (($customers->data ?? []) as $candidate) {
                    $customerById[(string) $candidate->id] = $candidate;
                }

                $data = $customers->data ?? [];
                $lastCustomer = $data !== [] ? end($data) : null;
                if (!empty($customers->has_more) && is_object($lastCustomer) && !empty($lastCustomer->id)) {
                    $params['starting_after'] = (string) $lastCustomer->id;
                } else {
                    break;
                }
            } while (true);
        }

        $nameMatches = [];

        foreach ($customerById as $candidate) {
            if (strtolower(trim((string) ($candidate->email ?? ''))) !== strtolower($email)) {
                continue;
            }

            if ((string) ($candidate->metadata->cliniko_patient_ref ?? '') === $patientReference) {
                return $candidate;
            }

            if ($name !== '' && strtolower(trim((string) ($candidate->name ?? ''))) === strtolower($name)) {
                $nameMatches[] = $candidate;
            }
        }

        if ($nameMatches !== []) {
            // Stripe lists newest customers first; reuse the newest exact name/email match.
            return $nameMatches[0];
        }

        return null;
    }

    /** @param array<string,mixed> $metadata
     *  @return array<string,string>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $result = [];
        $priorityKeys = ['booking_attempt_id', 'patient_form_id', 'module_id'];
        $ordered = [];
        foreach ($priorityKeys as $key) {
            if (array_key_exists($key, $metadata)) {
                $ordered[$key] = $metadata[$key];
                unset($metadata[$key]);
            }
        }
        $ordered += $metadata;

        foreach ($ordered as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 40 || strpbrk($key, '[]') !== false || !is_scalar($value)) {
                continue;
            }

            $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $result[$key] = function_exists('mb_substr')
                ? mb_substr($stringValue, 0, 500)
                : substr($stringValue, 0, 500);

            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }

    private function customerMappingKey(string $patientId): string
    {
        return 'wp_cliniko_stripe_customer_' . hash('sha256', $this->customerNamespace . ':' . $patientId);
    }
}
