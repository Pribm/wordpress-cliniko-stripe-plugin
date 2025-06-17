<?php
namespace App\Service;
if (!defined('ABSPATH')) exit;

use Stripe\Stripe;
use Stripe\PaymentIntent;

class StripeService
{
    private $stripe;
    public function __construct()
    {
        $api_key = get_option('wp_stripe_secret_key');
        $this->stripe = new Stripe();
        $this->stripe::setApiKey($api_key);
    }

    public function createPaymentIntent(int $amount, string $description): string
    {
        $intent = PaymentIntent::create([
            'amount' =>  $amount,
            'currency' => 'aud',
            'payment_method_types' => ['card'],
            'description' => $description
        ]);
        

        return $intent->client_secret;
    }

    public function retrievePaymentIntent(string $id): ?\Stripe\PaymentIntent
    {
        try {
            \Stripe\Stripe::setApiKey(get_option('wp_stripe_secret_key'));
            return \Stripe\PaymentIntent::retrieve($id);
        } catch (\Exception $e) {
            error_log('[StripeService] Failed to retrieve PaymentIntent: ' . $e->getMessage());
            return null;
        }
    }

    public function getStripe(){
        return $this->stripe;
    }
} 
