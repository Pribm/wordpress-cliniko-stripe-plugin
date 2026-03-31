<?php
namespace App\Routes;

use App\Controller\BookingAttemptController;
use App\Controller\TyroController;
use App\Controller\PatientAccessController;
use App\Service\PublicRequestGuard;

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
        $bookingAttemptController = new BookingAttemptController();
        $patientAccessController = new PatientAccessController();
        $guard = new PublicRequestGuard();

        // Legacy scheduling / payment routes
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
            'permission_callback' => [$guard, 'allowPublicMutation'],
        ]);

        register_rest_route('v1', '/available-times', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getAvailableTimes'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);

        register_rest_route('v1', '/practitioners', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getPractitioners'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);

        register_rest_route('v1', '/appointment-type', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getAppointmentTypeDetails'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);

        register_rest_route('v1', '/patient-form-template', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getPatientFormTemplate'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);

        register_rest_route('v1', '/appointment-calendar', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getAppointmentCalendar'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);

        register_rest_route('v1', '/next-available-times', [
            'methods' => 'GET',
            'callback' => [$clinikoController, 'getNextAvailableTimes'],
            'permission_callback' => [$guard, 'allowPublicRead'],
        ]);


        register_rest_route('v1', '/payments/charge', [
            'methods' => 'POST',
            'callback' => [new \App\Controller\PaymentController(), 'charge'],
            'permission_callback' => [$guard, 'allowPublicMutation'],
        ]);

        // TyroHealth SDK token (short-lived) used by frontend Partner SDK
        register_rest_route('v1', '/tyrohealth/sdk-token', [
            'methods' => 'POST',
            'callback' => [new \App\Controller\TyroController(), 'sdkToken'],
            'permission_callback' => [$guard, 'allowSdkToken'],
        ]);

        register_rest_route('v1', '/tyrohealth/charge', [
            'methods' => 'POST',
            'callback' => [TyroController::class, 'charge'],
            'permission_callback' => [$guard, 'allowPublicMutation'],
        ]);

      register_rest_route('v1', '/tyrohealth/invoice', [
      'methods' => 'POST',
      'callback' => [TyroController::class, 'createInvoice'],
      'permission_callback' => [$guard, 'allowPublicMutation'],
    ]);

        // New attempt-based booking flow
        register_rest_route('v2', '/booking-attempts/preflight', [
            'methods' => 'POST',
            'callback' => [$bookingAttemptController, 'preflight'],
            'permission_callback' => [$guard, 'allowPublicMutation'],
        ]);

        register_rest_route('v2', '/booking-attempts/charge-stripe', [
            'methods' => 'POST',
            'callback' => [$bookingAttemptController, 'chargeStripe'],
            'permission_callback' => [$guard, 'allowAttemptMutation'],
        ]);

        register_rest_route('v2', '/booking-attempts/confirm-tyro', [
            'methods' => 'POST',
            'callback' => [$bookingAttemptController, 'confirmTyro'],
            'permission_callback' => [$guard, 'allowAttemptMutation'],
        ]);

        register_rest_route('v2', '/booking-attempts/finalize', [
            'methods' => 'POST',
            'callback' => [$bookingAttemptController, 'finalize'],
            'permission_callback' => [$guard, 'allowAttemptMutation'],
        ]);

        register_rest_route('v2', '/booking-attempts/status', [
            'methods' => 'GET',
            'callback' => [$bookingAttemptController, 'status'],
            'permission_callback' => [$guard, 'allowAttemptMutation'],
        ]);

        register_rest_route('v2', '/patient-access/request', [
            'methods' => 'POST',
            'callback' => [$patientAccessController, 'requestLink'],
            'permission_callback' => [$guard, 'allowPatientAccessRequest'],
        ]);

        register_rest_route('v2', '/patient-access/verify', [
            'methods' => 'POST',
            'callback' => [$patientAccessController, 'verifyCode'],
            'permission_callback' => [$guard, 'allowPatientAccessRequest'],
        ]);

        register_rest_route('v2', '/patient-access/request-status', [
            'methods' => 'GET',
            'callback' => [$patientAccessController, 'requestStatus'],
            'permission_callback' => [$guard, 'allowPatientAccessRequest'],
        ]);

        register_rest_route('v2', '/patient-access/request-complete', [
            'methods' => 'POST',
            'callback' => [$patientAccessController, 'completeRequest'],
            'permission_callback' => [$guard, 'allowPatientAccessRead'],
        ]);

        register_rest_route('v2', '/patient-access/appointments', [
            'methods' => 'GET',
            'callback' => [$patientAccessController, 'appointments'],
            'permission_callback' => [$guard, 'allowPatientAccessRead'],
        ]);

        register_rest_route('v2', '/patient-access/appointments/(?P<booking_id>[\w-]+)/prefill', [
            'methods' => 'GET',
            'callback' => [$patientAccessController, 'prefill'],
            'permission_callback' => [$guard, 'allowPatientAccessRead'],
        ]);

        register_rest_route('v2', '/patient-access/latest', [
            'methods' => 'GET',
            'callback' => [$patientAccessController, 'latest'],
            'permission_callback' => [$guard, 'allowPatientAccessRead'],
        ]);
    }
}
