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

        // LIFO stack of compensations to rollback Cliniko side effects
        $compensations = [];

        // Keep references for response
        $createdAppointment = null;
        $patientCase = null;
        $patient = null;
        $patientForm = null;
        $uploadedAttachmentId = null;

        try {
            // 1) Resolve appointment type (no side effect)
            $appointmentType = AppointmentType::find($payload['moduleId'], $client);
            if (!$appointmentType) {
                throw new ApiException("Appointment type not found", ['moduleId' => $payload['moduleId']]);
            }

            // If payment is required, validate token now (but do NOT charge yet)
            $requiresPayment = $appointmentType->requiresPayment();

            if ($requiresPayment) {
                if (empty($payload['stripeToken']) || !preg_match('/^tok_/', $payload['stripeToken'])) {
                    return new WP_REST_Response([
                        'status' => 'error',
                        'message' => 'Payment is required but token is missing or invalid.',
                        'errors' => ['stripeToken' => 'Missing or invalid payment token.']
                    ], 422);
                }
            }

            // 2) Find or create patient (side effect, but we WON'T delete patients on rollback)
            $dto = new CreatePatientDTO();
            $dto->firstName = $payload['patient']["first_name"] ?? '';
            $dto->lastName = $payload['patient']["last_name"] ?? '';
            $dto->email = $payload['patient']["email"] ?? '';
            
            $patient = $this->clinikoService->findOrCreatePatient($dto);
  
            if (!$patient) {
                throw new ApiException("Unable to find or create patient", ['email' => $dto->email]);
            }

            // 3) Get next available time (no side effect)
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

            // 4) Create patient case (side effect + compensation)
            $createPatientCaseDTO = new CreatePatientCaseDTO();
            $createPatientCaseDTO->name = $appointmentType->getName();
            $createPatientCaseDTO->issueDate = $now->format('Y-m-d');
            $createPatientCaseDTO->patientId = $patient->getId();

            $patientCase = PatientCase::create($createPatientCaseDTO, $client);
            if (!$patientCase) {
                throw new ApiException("Failed to create patient case");
            }
            $compensations[] = function () use ($patientCase, $client) {
                try {
                    PatientCase::delete($patientCase->getId(), $client);
                } catch (\Throwable $e) {
                }
            };
            
      
            // 5) Create appointment (side effect + compensation)
            $createdAppointment = IndividualAppointment::create([
                "appointment_type_id" => $appointmentTypeId,
                "business_id" => $businessId,
                "starts_at" => $startDateTime->format(DATE_ATOM),
                "ends_at" => $endDateTime->format(DATE_ATOM),
                "patient_id" => $patient->getId(),
                "practitioner_id" => $practitionerId,
                "patient_case_id" => $patientCase->getId()
            ], $client);

         

            if (!$createdAppointment) {
                throw new ApiException("Failed to create appointment");
            }
            $compensations[] = function () use ($createdAppointment, $client) {
                try {
                    IndividualAppointment::delete($createdAppointment->getId(), $client);
                } catch (\Throwable $e) {
                        throw new ApiException($e->getMessage());
                }
            };

            // 6) Upload signature (side effect + compensation if we have the ID)
            if (!empty($_FILES['signature_file']['tmp_name'])) {
                $signaturePath = $_FILES['signature_file']['tmp_name'];
                $attachmentService = new ClinikoAttachmentService();
                $uploadedAttachmentId = $attachmentService->uploadPatientAttachment(
                    $patient->getId(),
                    $signaturePath,
                    'Patient Signature'
                );
                if ($uploadedAttachmentId) {
                    $compensations[] = function () use ( $uploadedAttachmentId, $attachmentService, $client) {
                        try {
                            $attachmentService->deletePatientAttachment($uploadedAttachmentId, $client);
                        } catch (\Throwable $e) {
                            throw new ApiException($e->getMessage());
                        }
                    };
                }
            }

            // 7) Create patient form (side effect + compensation)
            $_form = PatientFormTemplate::find($payload["patient_form_template_id"], $client);
            if (!$_form) {
                throw new ApiException("Patient form template not found", ['patient_form_template_id' => $payload["patient_form_template_id"]]);
            }

            $appointmentFormatted = $startDateTime->setTimezone(new DateTimeZone('Australia/Sydney'))
                ->format('F j, Y \a\t g:i A (T)');

            $patientFormDTOCreation = new CreatePatientFormDTO();
            $patientFormDTOCreation->completed = true;
            $patientFormDTOCreation->content_sections = $payload['content'];
            $patientFormDTOCreation->business_id = $businessId;
            $patientFormDTOCreation->patient_form_template_id = $payload["patient_form_template_id"];
            $patientFormDTOCreation->patient_id = $patient->getId();
            $patientFormDTOCreation->attendee_id = $patient->getId();
            $patientFormDTOCreation->appointment_id = $createdAppointment->getId();
            $patientFormDTOCreation->email_to_patient_on_completion = true;
            $patientFormDTOCreation->name = sprintf('%s - Appointment on %s', $_form->getName(), $appointmentFormatted);

            $patientForm = PatientForm::create($patientFormDTOCreation, $client);
            if (!$patientForm) {
                throw new ApiException("Failed to create patient form");
            }
            $compensations[] = function () use ($patientForm, $client) {
                try {
                    PatientForm::delete($patientForm->getId(), $client);
                } catch (\Throwable $e) {
                }
            };

            // 8) CHARGE LAST — only after every Cliniko step succeeded
            $paymentIntent = null;
            if ($requiresPayment) {

                unset($payload['patient']['medicare']);
                unset($payload['patient']['medicare_reference_number']);

                $paymentIntent = $stripeService->createChargeFromToken(
                    $payload['stripeToken'],
                    $appointmentType->getBillableItemsFinalPrice(),
                    $appointmentType->getName(),
                    $payload['patient'],
                    $patient->getEmail()
                );
                
                if (!$paymentIntent || empty($paymentIntent->id)) {
                    // Payment failed — rollback Cliniko side effects
                    throw new ApiException("Payment failed, rolling back Cliniko operations", ['stripe' => 'charge_failed']);
                }
            }

            // Success response
            return new WP_REST_Response([
                'status' => 'success',
                'appointment' => [
                    'id' => $createdAppointment->getId(),
                    'starts_at' => $createdAppointment->getStartsAt(),
                    'ends_at' => $createdAppointment->getEndsAt(),
                    'telehealth_url' => $createdAppointment->getTelehealthUrl(),
                    'payment_reference' => $paymentIntent->id ?? null,
                    'payment_method' => $paymentIntent->payment_method ?? null,
                ],
                'patient' => [
                    'id' => $patient->getId(),
                    'name' => $patient->getFullName(),
                    'email' => $patient->getEmail(),
                ]
            ], 201);

        } catch (ApiException $e) {
            // Rollback any Cliniko side-effects in reverse order
            for ($i = count($compensations) - 1; $i >= 0; $i--) {
                try {
                    ($compensations[$i])();
                } catch (\Throwable $ignored) {
                }
            }

            return new WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage(),
                'context' => $e->getContext()
            ], 500);

        } catch (\Throwable $e) {
            // Rollback any Cliniko side-effects in reverse order
            for ($i = count($compensations) - 1; $i >= 0; $i--) {
                try {
                    ($compensations[$i])();
                } catch (\Throwable $ignored) {
                }
            }

            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Unexpected error occurred.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }
}

