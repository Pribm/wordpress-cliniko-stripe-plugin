<?php
namespace App\Routes;

use App\Controller\AuthController;

if (!defined('ABSPATH'))
    exit;

use App\Controller\ClinikoController;

class ApiRoutes
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {

        // Rota para agendar no Cliniko após pagamento
        // register_rest_route('v1', '/book-cliniko', [
        //     'methods' => 'POST',
        //     'callback' => [$clinikoController, 'scheduleAppointment'],
        //     'permission_callback' => '__return_true',
        // ]);

        $authController = new AuthController();

        register_rest_route('v1', '/register', [
            'methods' => 'POST',
            'callback' => [$authController, 'register'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/login', [
            'methods' => 'POST',
            'callback' => [$authController, 'login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/confirm-email', [
            'methods' => 'GET',
            'callback' => [$authController, 'confirmEmail'],
            'permission_callback' => '__return_true',
        ]);

        $clinikoController = new ClinikoController();
        register_rest_route('v1', '/get-patient', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getPatient'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);


        register_rest_route('v1', '/payments/charge', [
            'methods' => 'POST',
            'callback' => [new \App\Controller\PaymentController(), 'charge'],
            'permission_callback' => '__return_true',
        ]);
    }
}
