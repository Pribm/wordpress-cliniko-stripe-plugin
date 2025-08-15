<?php
namespace App\Controller;

use App\Exception\ApiException;
use App\Facade\AppointmentFacade;
use App\Validator\AppointmentRequestValidator;


if (!defined('ABSPATH'))
    exit;

use App\Service\ClinikoService;
use App\Service\StripeService;
use WP_REST_Request;
use WP_REST_Response;

class ClinikoController
{
    private AppointmentFacade $facade;

    public function __construct()
    {
        $this->facade = new AppointmentFacade(
            new ClinikoService(),
            new StripeService()
        );
    }

    public function scheduleAppointment(WP_REST_Request $request): WP_REST_Response
    {
        $payload = [
            'content'                  => json_decode($request->get_body_params()['content'] ?? '{}', true),
            'patient'                  => json_decode($request->get_body_params()['patient'] ?? '{}', true),
            'stripeToken'              => $request->get_body_params()['stripeToken'] ?? null,
            'moduleId'                 => $request->get_body_params()['moduleId'] ?? null,
            'patient_form_template_id' => $request->get_body_params()['patient_form_template_id'] ?? null,
        ];

        $errors = AppointmentRequestValidator::validate($payload);
        if (!empty($errors)) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Missing or invalid fields.',
                'errors'  => $errors
            ], 422);
        }

        try {
            $result = $this->facade->schedule($payload);

            return new WP_REST_Response([
                'status'      => 'success',
                'appointment' => $result['appointment'],
                'patient'     => $result['patient'],
            ], 201);

        } catch (ApiException $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'context' => $e->getContext()
            ], 500);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Unexpected error occurred.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }
}