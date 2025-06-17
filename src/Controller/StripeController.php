<?php
namespace App\Controller;

use App\Client\ClinikoClient;
use App\Model\AppointmentType;
if (!defined('ABSPATH')) exit;

use App\Service\StripeService;

use WP_REST_Request;

class StripeController
{
     private StripeService $stripe;

    public function __construct()
    {
        $this->stripe = new StripeService();
    }

    public function getClientSecret(WP_REST_Request $payload): array
    {

        $moduleId = $payload['moduleId'] ?? null;

        if (!$moduleId) {
            return ['error' => [
                'params' => [
                    'name' => 'moduleId',
                    'type' => 'string'
                ],
                'message' => 'Inexisting module, the module is null'
            ]];
        }
        
        $client = ClinikoClient::getInstance();
        $module = AppointmentType::find($moduleId, $client);
   
        $clientSecret = $this->stripe->createPaymentIntent(
            $module->getBillableItemsFinalPrice(),
            $module->getName(),
        );

        return [
            'clientSecret' => $clientSecret,
            'price' => $module->getBillableItemsFinalPrice(),
            'name' => $module->getName(),
            'duration' => $module->getDurationInMinutes(),
            'description' => $module->getDescription(),
        ];
    }
}
