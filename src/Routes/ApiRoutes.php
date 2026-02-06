<?php
namespace App\Routes;

use App\Controller\TyroController;

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
        $clinikoController = new ClinikoController();
        // register_rest_route('v1', '/book-cliniko', [
        //     'methods' => 'POST',
        //     'callback' => [$clinikoController, 'scheduleAppointment'],
        //     'permission_callback' => '__return_true',
        // ]);

        // register_rest_route('v1', '/get-patient', [
        //     'methods' => 'GET',
        //     'callback' => [$clinikoController, 'getPatient'],
        //     'permission_callback' => '__return_true',
        // ]);

        register_rest_route('v1', '/send-patient-form', [
            'methods' => 'POST',
            'callback' => [$clinikoController, 'createPatientForm'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/available-times', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getAvailableTimes'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/practitioners', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getPractitioners'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/appointment-calendar', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getAppointmentCalendar'],
            'permission_callback' => '__return_true',
        ]);


        register_rest_route('v1', '/payments/charge', [
            'methods' => 'POST',
            'callback' => [new \App\Controller\PaymentController(), 'charge'],
            'permission_callback' => '__return_true', 
        ]);

        // TyroHealth SDK token (short-lived) used by frontend Partner SDK
        register_rest_route('v1', '/tyrohealth/sdk-token', [
            'methods' => 'POST',
            'callback' => [new \App\Controller\TyroController(), 'sdkToken'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('v1', '/tyrohealth/charge', [
            'methods' => 'POST',
            'callback' => [TyroController::class, 'charge'],
            'permission_callback' => '__return_true',
        ]);

      register_rest_route('v1', '/tyrohealth/invoice', [
      'methods' => 'POST',
      'callback' => [TyroController::class, 'createInvoice'],
      'permission_callback' => '__return_true', // TODO: protect with nonce/auth
    ]);
    }
}
