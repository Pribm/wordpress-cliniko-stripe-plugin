<?php
namespace App\Controller;

use App\Client\Cliniko\Client;
use App\DTO\CreatePatientCaseDTO;
use App\DTO\CreatePatientDTO;
use App\DTO\CreatePatientFormDTO;
use App\Exception\ApiException;
use App\Model\AppointmentType;
use App\Model\IndividualAppointment;
use App\Model\PatientCase;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\Service\ClinikoAttachmentService;
use App\Validator\AppointmentRequestValidator;
use DateInterval;
use DateTimeImmutable;
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
        $payload = [
            'content' => json_decode($request->get_body_params()['content'] ?? '{}', true),
            'patient' => json_decode($request->get_body_params()['patient'] ?? '{}', true),
            'stripeToken' => $request->get_body_params()['stripeToken'] ?? null,
            'moduleId' => $request->get_body_params()['moduleId'] ?? null,
            'patient_form_template_id' => $request->get_body_params()['patient_form_template_id'] ?? null,
        ];

        $errors = AppointmentRequestValidator::validate($payload);

        if (!empty($errors)) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing or invalid fields.',
                'errors' => $errors
            ], 422);
        }

        $client = Client::getInstance();
        $stripeService = new StripeService();

        try {
            // Step 1: Find Appointment Type
            $appointmentType = AppointmentType::find($payload['moduleId'], $client);
            

            // Step 2: Process Payment
            $paymentIntent = $stripeService->createChargeFromToken(
                $payload['stripeToken'],
                $appointmentType->getBillableItemsFinalPrice(),
                $appointmentType->getName(),
                $payload['patient']
            );

            // Step 3: Create or retrieve patient
            $dto = new CreatePatientDTO();
            $dto->firstName = $payload['patient']["first_name"];
            $dto->lastName = $payload['patient']["last_name"];
            $dto->email = $payload['patient']["email"];

            $patient = $this->clinikoService->findOrCreatePatient($dto);
            if (!$patient) {
                throw new ApiException("Unable to find or create patient", ['email' => $dto->email]);
            }

            // Step 4: Get next available time
            $now = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));
            $to = $now->add(new DateInterval('P7D'));

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
                throw new ApiException("No available appointment time found.");
            }

            $startDateTime = new DateTimeImmutable($nextAvailableDTO->appointmentStart);
            $endDateTime = $startDateTime->add(new DateInterval("PT{$appointmentType->getDurationInMinutes()}M"));

            // Step 5: Create patient case
            $createPatientCaseDTO = new CreatePatientCaseDTO();
            $createPatientCaseDTO->name = $appointmentType->getName();
            $createPatientCaseDTO->issueDate = $now->format('Y-m-d');
            $createPatientCaseDTO->patientId = $patient->getId();

            $patientCase = PatientCase::create($createPatientCaseDTO, $client);

            // Step 6: Create appointment
            $createdAppointment = IndividualAppointment::create([
                "appointment_type_id" => $appointmentTypeId,
                "business_id" => $businessId,
                "starts_at" => $startDateTime->format(DATE_ATOM),
                "ends_at" => $endDateTime->format(DATE_ATOM),
                "patient_id" => $patient->getId(),
                "practitioner_id" => $practitionerId,
                "patient_case_id" => $patientCase->getId()
            ], $client);

            // Step 7: Upload signature if the form has a signature question
            if (!empty($_FILES['signature_file']['tmp_name'])) {
                $signaturePath = $_FILES['signature_file']['tmp_name'];
                $attachmentService = new ClinikoAttachmentService();
                $attachmentService->uploadPatientAttachment(
                    $patient->getId(),
                    $signaturePath,
                    'Patient Signature'
                );
            }

            // Step 8: Send form data
            $_form = PatientFormTemplate::find($payload["patient_form_template_id"], $client);

            $appointmentFormatted = $startDateTime->setTimezone(new DateTimeZone('Australia/Sydney'))
                ->format('F j, Y \a\t g:i A (T)');

            $patientFormDTOCreation = new CreatePatientFormDTO();
            $patientFormDTOCreation->completed = true;
            $content_sections = $payload['content'];
            $patientFormDTOCreation->content_sections = $content_sections;
            $patientFormDTOCreation->business_id = $businessId;
            $patientFormDTOCreation->patient_form_template_id = $payload["patient_form_template_id"];
            $patientFormDTOCreation->patient_id = $patient->getId();
            $patientFormDTOCreation->attendee_id = $patient->getId();
            $patientFormDTOCreation->appointment_id = $createdAppointment->getId();
            $patientFormDTOCreation->email_to_patient_on_completion = true;
            $patientFormDTOCreation->name = sprintf('%s - Appointment on %s', $_form->getName(), $appointmentFormatted);

           PatientForm::create($patientFormDTOCreation, $client);

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

        } catch (ApiException $e) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage(),
                'context' => $e->getContext()
            ], 500);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Unexpected error occurred.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }
}


