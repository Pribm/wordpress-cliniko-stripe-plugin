<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Model\IndividualAppointment;
use App\Model\AppointmentType;
use App\Model\Practitioner;
use App\Support\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class DashboardAppointmentService
{
    private ApiClientInterface $client;
    /** @var array<string,string> */
    private array $practitionerNames = [];
    /** @var array<string,AppointmentType|null> */
    private array $appointmentTypes = [];
    /** @var array<string,array<string,mixed>|null> */
    private array $patientFormEntries = [];

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 900)
            : Client::getInstance());
    }

    /** @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,has_more:bool} */
    public function forAuthenticatedPatient(
        string $appointmentTypeId,
        int $page = 1,
        int $perPage = 5,
        string $order = 'asc',
        string $patientFormTemplateId = '',
        array $columns = []
    ): array {
        $patient = Auth::user();
        $patientId = $patient ? (string) $patient->getId() : '';
        if ($patientId === '' || $appointmentTypeId === '') {
            return ['items' => [], 'page' => 1, 'per_page' => max(1, $perPage), 'has_more' => false];
        }

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';
        $queryParts = [
            'page=' . $page,
            'per_page=' . $perPage,
            'sort=starts_at',
            'order=' . $order,
            'q[]=' . rawurlencode('patient_id:=' . $patientId),
        ];
        if ($appointmentTypeId !== 'all') {
            $queryParts[] = 'q[]=' . rawurlencode('appointment_type_id:=' . $appointmentTypeId);
        }
        $query = '?' . implode('&', $queryParts);

        $appointments = IndividualAppointment::queryManyByQueryString($query, $this->client, true);
        $hasMore = count($appointments) === $perPage;
        $appointmentType = $appointmentTypeId !== 'all'
            ? $this->appointmentType($appointmentTypeId)
            : null;

        return [
            'items' => array_map(fn(IndividualAppointment $appointment): array => $this->toRow($appointment, $appointmentType, $patientFormTemplateId, $columns), $appointments),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string,mixed>|null */
    public function findForAuthenticatedPatient(string $appointmentId): ?array
    {
        $patient = Auth::user();
        $patientId = $patient ? (string) $patient->getId() : '';
        if ($patientId === '' || $appointmentId === '') {
            return null;
        }

        $appointment = IndividualAppointment::find($appointmentId, $this->client);
        if ($appointment === null || $appointment->getPatientId() !== $patientId) {
            return null;
        }

        $appointmentTypeId = (string) $appointment->getAppointmentTypeId();
        $appointmentType = $appointmentTypeId !== '' ? AppointmentType::find($appointmentTypeId, $this->client) : null;
        return $this->toRow($appointment, $appointmentType);
    }

    /** @return array<string,mixed> */
    private function toRow(IndividualAppointment $appointment, ?AppointmentType $appointmentType, string $patientFormTemplateId = '', array $columns = []): array
    {
        if ($appointmentType === null) {
            $appointmentType = $this->appointmentType((string) $appointment->getAppointmentTypeId());
        }
        $endsAt = $appointment->getEndsAt();

        $formValues = [];
        $needsFormValues = array_filter($columns, static fn($column): bool => is_array($column) && ($column['key'] ?? '') === 'patient_form_field');
        if ($needsFormValues !== []) {
            $formValues = $this->patientFormValues((string) $appointment->getId(), (string) $appointment->getPatientId(), $patientFormTemplateId);
        }

        return [
            'id' => (string) $appointment->getId(),
            'starts_at' => $appointment->getStartsAt(),
            'ends_at' => $endsAt,
            'service' => $appointmentType ? (string) $appointmentType->getName() : '',
            'appointment_type' => $appointmentType ? (string) $appointmentType->getName() : '',
            'status' => (strtotime($endsAt) ?: 0) < time() ? 'Completed' : 'Upcoming',
            'doctor' => $this->practitionerName((string) $appointment->getPractitionerUrl()),
            'duration' => $appointmentType ? $appointmentType->getDurationInMinutes() : null,
            'category' => $appointmentType ? $appointmentType->getCategory() : '',
            'description' => $appointmentType ? $appointmentType->getDescription() : '',
            'price' => $appointmentType ? $appointmentType->getBillableItemsFinalPrice() : null,
            'telehealth_url' => $appointment->getTelehealthUrl(),
            'notes' => $appointment->getNotes(),
            'patient_form' => $this->patientFormStatus((string) $appointment->getId(), (string) $appointment->getPatientId(), $patientFormTemplateId),
            'patient_form_fields' => $formValues,
        ];
    }

    /** @return array<string,string> */
    private function patientFormValues(string $appointmentId, string $patientId, string $templateId): array
    {
        $row = $this->patientFormEntry($appointmentId, $patientId, $templateId);
        if ($row === null) return [];
        $content = $row['content'] ?? null;
        $formId = trim((string) ($row['id'] ?? ''));
        if (!is_array($content) || !is_array($content['sections'] ?? null)) {
            if ($formId === '') return [];
            $detail = $this->client->get('patient_forms/' . rawurlencode($formId));
            $content = $detail->isSuccessful() && is_array($detail->data['content'] ?? null) ? $detail->data['content'] : [];
        }

        $values = [];
        foreach (($content['sections'] ?? []) as $sectionIndex => $section) {
            foreach (($section['questions'] ?? []) as $questionIndex => $question) {
                if (!is_array($question)) continue;
                $answer = trim((string) ($question['answer'] ?? ''));
                if ($answer === '' && is_array($question['answers'] ?? null)) {
                    $selected = [];
                    foreach ($question['answers'] as $choice) {
                        if (is_array($choice) && !empty($choice['selected']) && trim((string) ($choice['value'] ?? '')) !== '') $selected[] = trim((string) $choice['value']);
                    }
                    $answer = implode(', ', $selected);
                }
                $values['s' . $sectionIndex . '_q' . $questionIndex] = $answer;
            }
        }
        return $values;
    }

    private function patientFormStatus(string $appointmentId, string $patientId, string $templateId): string
    {
        $row = $this->patientFormEntry($appointmentId, $patientId, $templateId);
        if ($row === null) return 'Not submitted';
        $date = (string) ($row['completed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
        $timestamp = strtotime($date);
        return $timestamp ? 'Completed ' . wp_date((string) get_option('date_format'), $timestamp) : 'Submitted';
    }

    /** The form is resolved through the appointment's attendee, never patient-wide. */
    private function patientFormEntry(string $appointmentId, string $patientId, string $templateId): ?array
    {
        $appointmentId = trim($appointmentId);
        $patientId = trim($patientId);
        $templateId = trim($templateId);
        if ($appointmentId === '' || $patientId === '' || $templateId === '') return null;
        $cacheKey = $appointmentId . ':' . $templateId;
        if (array_key_exists($cacheKey, $this->patientFormEntries)) return $this->patientFormEntries[$cacheKey];

        $attendeeResponse = $this->client->get('attendees?' . implode('&', [
            'per_page=1',
            'q[]=' . rawurlencode('booking_id:=' . $appointmentId),
            'q[]=' . rawurlencode('patient_id:=' . $patientId),
        ]));
        $attendees = $attendeeResponse->isSuccessful() && is_array($attendeeResponse->data['attendees'] ?? null) ? $attendeeResponse->data['attendees'] : [];
        $attendee = is_array($attendees[0] ?? null) ? $attendees[0] : null;
        $formsUrl = is_array($attendee['patient_forms']['links'] ?? null) ? trim((string) ($attendee['patient_forms']['links']['self'] ?? '')) : '';
        if ($formsUrl === '') return $this->patientFormEntries[$cacheKey] = null;

        $separator = str_contains($formsUrl, '?') ? '&' : '?';
        $formsResponse = $this->client->get($formsUrl . $separator . 'per_page=20&sort=updated_at&order=desc');
        $rows = $formsResponse->isSuccessful() && is_array($formsResponse->data['patient_forms'] ?? null) ? $formsResponse->data['patient_forms'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rowTemplateId = trim((string) ($row['patient_form_template_id'] ?? ''));
            if ($rowTemplateId === '' && is_array($row['patient_form_template']['links'] ?? null)) {
                $rowTemplateId = $this->linkedResourceId((string) ($row['patient_form_template']['links']['self'] ?? ''));
            }
            if ($rowTemplateId === $templateId) return $this->patientFormEntries[$cacheKey] = $row;
        }
        return $this->patientFormEntries[$cacheKey] = null;
    }

    private function linkedResourceId(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        return $parts === [] ? '' : (string) end($parts);
    }

    private function practitionerName(string $url): string
    {
        if ($url === '') return '';
        if (array_key_exists($url, $this->practitionerNames)) return $this->practitionerNames[$url];
        $practitioner = Practitioner::findFromUrl($url, $this->client, false);
        return $this->practitionerNames[$url] = $practitioner instanceof Practitioner
            ? $practitioner->getDisplayName()
            : '';
    }

    private function appointmentType(string $appointmentTypeId): ?AppointmentType
    {
        if ($appointmentTypeId === '') return null;
        if (array_key_exists($appointmentTypeId, $this->appointmentTypes)) {
            return $this->appointmentTypes[$appointmentTypeId];
        }
        return $this->appointmentTypes[$appointmentTypeId] = AppointmentType::find($appointmentTypeId, $this->client);
    }

}
