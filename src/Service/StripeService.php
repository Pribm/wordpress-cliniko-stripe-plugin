<?php
namespace App\Service;

use ErrorException;
use Stripe\Charge;
use Stripe\Refund;
if (!defined('ABSPATH'))
    exit;

use Stripe\Stripe;


class StripeService
{
    private $stripe;
    public function __construct()
    {
        $api_key = get_option('wp_stripe_secret_key');
        $this->stripe = new Stripe();
        $this->stripe::setApiKey($api_key);
    }

    public function createChargeFromToken(
        string $token,
        int $amount,
        string $description = '',
        array $metadata = [],
        string $receiptEmail = ''
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

            return Charge::create($params);
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
        if ($amount !== null)
            $params['amount'] = $amount;     // cents
        if ($reason !== null)
            $params['reason'] = $reason;
        if (!empty($metadata))
            $params['metadata'] = $metadata;

        return Refund::create($params);
    }

}
