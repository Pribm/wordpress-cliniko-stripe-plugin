<?php
namespace App\Facade;

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
use App\Service\ClinikoService;
use App\Service\StripeService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

class AppointmentFacade
{
    public function __construct(
        private ClinikoService $clinikoService,
        private StripeService $stripeService
    ) {}

    /**
     * Orquestra todo o fluxo e retorna os dados para a resposta HTTP.
     * @throws ApiException|\Throwable
     */
    public function schedule(array $payload): array
    {
        $client      = Client::getInstance();
        $businessId  = $this->getBusinessId();
        $apptType    = $this->loadAppointmentType($payload['moduleId'], $client);

        $paymentIntent = $this->maybeCharge(
            $apptType,
            $payload['stripeToken'] ?? null,
            $payload['patient'] ?? []
        );

        $patient = $this->ensurePatient($payload['patient'] ?? []);

        [$now, $from, $to] = $this->makeWindow();
        $practitionerId    = $this->pickPractitionerId($apptType);
        $nextAvailDTO      = $this->findNextAvailable(
            $businessId,
            $practitionerId,
            $apptType->getId(),
            $from,
            $to,
            $client
        );

        [$startAt, $endAt] = $this->buildTimes($nextAvailDTO, $apptType->getDurationInMinutes());

        // Upload da assinatura (se houver) antes do form
        $this->uploadSignatureIfPresent($patient->getId());

        // Obtem o template para compor o nome do form
        $template = $this->loadFormTemplate((string)$payload['patient_form_template_id'], $client);

        // 1) CRIA o PatientForm ANTES do appointment (sem appointment_id)
        $patientForm = $this->createPatientFormPreAppointment(
            businessId: $businessId,
            templateId: (string)$payload['patient_form_template_id'],
            templateName: $template->getName(),
            patientId: $patient->getId(),
            attendeeId: $patient->getId(),
            startsAt: $startAt,               // usamos o horário previsto para compor o nome
            content: $payload['content'] ?? [],
            client: $client
        );

        // 2) Cria Patient Case
        $patientCase = $this->createPatientCase(
            caseName: $apptType->getName(),
            issueDate: $now,
            patientId: $patient->getId(),
            client: $client
        );

        // 3) Cria o Appointment
        $appointment = $this->createAppointment(
            businessId: $businessId,
            appointmentTypeId: $apptType->getId(),
            practitionerId: $practitionerId,
            patientId: $patient->getId(),
            patientCaseId: $patientCase->getId(),
            startsAt: $startAt,
            endsAt: $endAt,
            client: $client
        );

        // 4) Vincula o form ao appointment (atualiza o form)
        $this->linkFormToAppointment(
            formId: $patientForm->getId(),
            appointmentId: $appointment->getId(),
            // opcionalmente atualizamos o nome para garantir consistência
            newName: $this->buildFormName($template->getName(), $startAt),
            client: $client
        );

        return [
            'appointment' => [
                'id'               => $appointment->getId(),
                'starts_at'        => $appointment->getStartsAt(),
                'ends_at'          => $appointment->getEndsAt(),
                'telehealth_url'   => $appointment->getTelehealthUrl(),
                'payment_reference'=> $paymentIntent->id ?? null,
                'payment_method'   => $paymentIntent->payment_method ?? null,
            ],
            'patient' => [
                'id'    => $patient->getId(),
                'name'  => $patient->getFullName(),
                'email' => $patient->getEmail(),
            ],
        ];
    }

    // ---------- Steps pequenos (privates) ----------

    private function getBusinessId()
    {
        $businessId =  get_option('wp_cliniko_business_id');
        if (!$businessId) {
            throw new ApiException('Business ID inválido ou não configurado.');
        }
        return $businessId;
    }

    private function loadAppointmentType(string $moduleId, Client $client): AppointmentType
    {
        $type = AppointmentType::find($moduleId, $client);
        if (!$type) {
            throw new ApiException('Appointment Type não encontrado.', ['moduleId' => $moduleId]);
        }
        return $type;
    }

    private function maybeCharge(AppointmentType $type, ?string $token, array $patient): ?object
    {
        if (!$type->requiresPayment()) {
            return null;
        }

        if (empty($token) || !preg_match('/^tok_/', $token)) {
            throw new ApiException('Pagamento requerido, token ausente ou inválido.', ['stripeToken' => $token]);
        }

        return $this->stripeService->createChargeFromToken(
            $token,
            $type->getBillableItemsFinalPrice(),
            $type->getName(),
            $patient
        );
    }

    private function ensurePatient(array $patientPayload): object
    {
        $dto = new CreatePatientDTO();
        $dto->firstName = $patientPayload['first_name'] ?? '';
        $dto->lastName  = $patientPayload['last_name']  ?? '';
        $dto->email     = $patientPayload['email']      ?? '';

        $patient = $this->clinikoService->findOrCreatePatient($dto);
        if (!$patient) {
            throw new ApiException('Não foi possível localizar/criar o paciente.', ['email' => $dto->email]);
        }
        return $patient;
    }

    /** @return array{0:DateTimeImmutable,1:string,2:string} [$now, $from, $to] */
    private function makeWindow(): array
    {
        $nowTz = new DateTimeZone('Australia/Sydney');
        $now   = new DateTimeImmutable('now', $nowTz);
        $to    = $now->add(new DateInterval('P7D'));

        return [$now, $now->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function pickPractitionerId(AppointmentType $type)
    {
        $practitioners = $type->getPractitioners();
        if (empty($practitioners)) {
            throw new ApiException('Nenhum profissional vinculado ao Appointment Type.');
        }
        return $practitioners[0]->getId();
    }

    private function findNextAvailable(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from,
        string $to,
        Client $client
    ): object {
        $dto = $this->clinikoService->getNextAvailableTime(
            $businessId,
            $practitionerId,
            $appointmentTypeId,
            $from,
            $to,
            $client
        );

        if (!$dto || empty($dto->appointmentStart)) {
            throw new ApiException('Nenhum horário disponível encontrado.');
        }
        return $dto;
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} [$startAt, $endAt] */
    private function buildTimes(object $nextAvailDTO, int $durationMinutes): array
    {
        $startAt = new DateTimeImmutable($nextAvailDTO->appointmentStart);
        $endAt   = $startAt->add(new DateInterval("PT{$durationMinutes}M"));
        return [$startAt, $endAt];
    }

    private function loadFormTemplate(string $templateId, Client $client): PatientFormTemplate
    {
        $tpl = PatientFormTemplate::find($templateId, $client);
        if (!$tpl) {
            throw new ApiException('Patient Form Template não encontrado.', ['template_id' => $templateId]);
        }
        return $tpl;
    }

    private function buildFormName(string $templateName, DateTimeImmutable $startAt): string
    {
        $tz  = new DateTimeZone('Australia/Sydney');
        $fmt = $startAt->setTimezone($tz)->format('F j, Y \a\t g:i A (T)');
        return sprintf('%s - Appointment on %s', $templateName, $fmt);
    }

    private function createPatientFormPreAppointment(
        string $businessId,
        string $templateId,
        string $templateName,
        string $patientId,
        string $attendeeId,
        DateTimeImmutable $startsAt,
        array $content,
        Client $client
    ): PatientForm {
        $dto = new CreatePatientFormDTO();
        $dto->completed                     = true;
        $dto->content_sections              = $content;
        $dto->business_id                   = $businessId;
        $dto->patient_form_template_id      = $templateId;
        $dto->patient_id                    = $patientId;
        $dto->attendee_id                   = $attendeeId;
        $dto->appointment_id                = null; // será vinculado depois
        $dto->email_to_patient_on_completion= true;
        $dto->name                          = $this->buildFormName($templateName, $startsAt);

        return PatientForm::create($dto, $client);
    }

    private function createPatientCase(
        string $caseName,
        DateTimeImmutable $issueDate,
        string $patientId,
        Client $client
    ): PatientCase {
        $dto = new CreatePatientCaseDTO();
        $dto->name      = $caseName;
        $dto->issueDate = $issueDate->format('Y-m-d');
        $dto->patientId = $patientId;

        return PatientCase::create($dto, $client);
    }

    private function createAppointment(
        string $businessId,
        string $appointmentTypeId,
        string $practitionerId,
        string $patientId,
        string $patientCaseId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        Client $client
    ): IndividualAppointment {
        return IndividualAppointment::create([
            'appointment_type_id' => $appointmentTypeId,
            'business_id'         => $businessId,
            'starts_at'           => $startsAt->format(DATE_ATOM),
            'ends_at'             => $endsAt->format(DATE_ATOM),
            'patient_id'          => $patientId,
            'practitioner_id'     => $practitionerId,
            'patient_case_id'     => $patientCaseId,
        ], $client);
    }

    private function uploadSignatureIfPresent(int $patientId): void
    {
        if (empty($_FILES['signature_file']['tmp_name'])) {
            return;
        }

        $path = $_FILES['signature_file']['tmp_name'];
        (new ClinikoAttachmentService())->uploadPatientAttachment(
            $patientId,
            $path,
            'Patient Signature'
        );
    }

    private function linkFormToAppointment(
        string $formId,
        string $appointmentId,
        string $newName,
        Client $client
        ): void
    {
        // Garanta que existe um método update na sua Model:
        // PatientForm::update(int $id, array $payload, Client $client)
        PatientForm::update($formId, [
            'appointment_id' => $appointmentId,
            'name'           => $newName,
        ], $client);
    }
}
