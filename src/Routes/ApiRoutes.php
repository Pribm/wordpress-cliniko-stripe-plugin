<?php
namespace App\Routes;

if (!defined('ABSPATH')) exit;

use App\Controller\StripeController;
use App\Controller\ClinikoController;

class ApiRoutes {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        
        // Rota para agendar no Cliniko após pagamento
        $clinikoController = new ClinikoController();
        register_rest_route('v1', '/book-cliniko', [
            'methods' => 'POST',
            'callback' => [$clinikoController, 'scheduleAppointment'],
            'permission_callback' => '__return_true',
        ]);
    }
}
