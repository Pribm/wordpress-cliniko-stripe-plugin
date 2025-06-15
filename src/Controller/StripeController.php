<?php
namespace App\Controller;
if (!defined('ABSPATH')) exit;


use App\Config\ModuleConfig;
use App\Service\StripeService;
use App\Validator\ModuleValidator;

use WP_REST_Request;
use WP_REST_Response;

class StripeController
{
     private StripeService $stripe;

    public function __construct()
    {
        $this->stripe = new StripeService();
    }

    public function getClientSecret(WP_REST_Request $payload): array
    {
   
        $modules = ModuleConfig::getModules();
        $moduleId = $payload['moduleId'] ?? null;
        $answers = $payload['answers'] ?? [];

        if (!isset($modules[$moduleId])) {
            return ['error' => [
                'params' => [
                    'name' => 'moduleId',
                    'type' => 'string'
                ],
                'message' => 'Módulo inválido'
            ]];
        }
        
        $module = $modules[$moduleId];

        
        $clientSecret = $this->stripe->createPaymentIntent(
            $module['price'],
            $module['name'],
        );

        return [
            'clientSecret' => $clientSecret,
            'price' => $module['price'],
            'name' => $module['name'],
            'duration' => $module['duration'],
            'description' => $module['description'],
        ];
    }
}
