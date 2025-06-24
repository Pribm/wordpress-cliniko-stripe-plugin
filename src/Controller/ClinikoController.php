<?php
namespace App\Controller;

use App\Client\ClinikoClient;
use App\DTO\CreatePatientCaseDTO;
use App\DTO\CreatePatientDTO;
use App\Model\AppointmentType;
use App\Model\IndividualAppointment;
use App\Model\PatientCase;
use DateInterval;
use DateTimeZone;

if (!defined('ABSPATH'))
    exit;

use App\Service\ClinikoService;
use App\Service\StripeService;

use WP_REST_Request;
use WP_REST_Response;

class ClinikoController
{
    protected ClinikoService $clinikoService;

    public function __construct()
    {
        $this->clinikoService = new ClinikoService();
    }

    public function scheduleAppointment(WP_REST_Request $request): WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);

        if (!is_array($payload)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid JSON body received.'
            ], 400);
        }

        $paymentIntentId = $payload['paymentIntentId'] ?? null;
        if (!$paymentIntentId) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing paymentIntentId.',
            ], 400);
        }

        $stripeService = new StripeService();
        $paymentIntent = $stripeService->retrievePaymentIntent($paymentIntentId);
    
        if (!$paymentIntent || $paymentIntent->status !== 'succeeded') {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Payment not confirmed or invalid.',
            ], 403);
        }

        $missingFields = [];

        if (empty($payload['moduleId'])) {
            $missingFields[] = 'moduleId';
        }

        $client = ClinikoClient::getInstance();
        $appointmentType = AppointmentType::find($payload['moduleId'], $client);


        if (empty($payload['patient']) || !is_array($payload['patient'])) {
            $missingFields[] = 'patient';
        } else {
            foreach (['first_name', 'last_name', 'email'] as $field) {
                if (empty($payload['patient'][$field])) {
                    $missingFields[] = "patient.$field";
                }
            }
        }

        if (!empty($missingFields)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing or invalid required fields.',
                'missing' => $missingFields
            ], 400);
        }

        $dto = new CreatePatientDTO();
        $dto->firstName = $payload['patient']["first_name"];
        $dto->lastName = $payload['patient']["last_name"];
        $dto->email = $payload['patient']["email"];

        $patient = $this->clinikoService->findOrCreatePatient($dto);

        // Horário disponível
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Australia/Sydney'));
        $to = $now->add(new \DateInterval('P7D'));

        $practitionerId = $appointmentType->getPractitioners()[0]->getId();
        $appointmentTypeId = $appointmentType->getId();
        $businessId = get_option('wp_cliniko_business_id');

        $nextAvailableDTO = $this->clinikoService->getNextAvailableTime(
            $businessId,
            $practitionerId,
            $appointmentTypeId,
            $now->format('Y-m-d'),
            $to->format('Y-m-d'),
            $client
        );

        if (!$nextAvailableDTO || empty($nextAvailableDTO->appointmentStart)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'No available appointment time found.'
            ], 404);
        }

        $startDateTime = new \DateTimeImmutable($nextAvailableDTO->appointmentStart);
        $endDateTime = $startDateTime->add(new \DateInterval("PT{$appointmentType->getDurationInMinutes()}M"));


        $createPatientCaseDTO = new CreatePatientCaseDTO();
        $createPatientCaseDTO->name = $appointmentType->getName();
        $createPatientCaseDTO->issueDate = $now->format('Y-m-d');
        $createPatientCaseDTO->patientId = $patient->getId();
        $createPatientCaseDTO->notes = $payload['notes'];
        $patientCase = PatientCase::create($createPatientCaseDTO, $client);

        $createdAppointment = IndividualAppointment::create([
            "appointment_type_id" => $appointmentTypeId,
            "business_id" => $businessId,
            "starts_at" => $startDateTime->format(DATE_ATOM),
            "ends_at" => $endDateTime->format(DATE_ATOM),
            "patient_id" => $patient->getId(),
            "practitioner_id" => $practitionerId,
            "patient_case_id" => $patientCase->getId()
        ], $client);

        return new WP_REST_Response([
            'status' => 'success',
            'appointment' => [
                'id' => $createdAppointment->getId(),
                'starts_at' => $createdAppointment->getStartsAt(),
                'ends_at' => $createdAppointment->getEndsAt(),
                'telehealth_url' => $createdAppointment->getTelehealthUrl(),
                'payment_reference' => $paymentIntent->id,
                'payment_method' => $paymentIntent->payment_method,
                
            ],
            'patient' => [
                'id' => $patient->getId(),
                'name' => $patient->getFullName(),
                'email' => $patient->getEmail(),
            ]
        ], 201);
    }

}
