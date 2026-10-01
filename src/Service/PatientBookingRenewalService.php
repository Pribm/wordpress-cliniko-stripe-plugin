<?php

namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\Model\Booking;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientBookingRenewalService
{
    /**
     * Resolve and authorize the original appointment, then return its latest
     * patient-form content for the authenticated patient.
     *
     * @return array{appointment_id:string,patient_id:string,appointment_type_id:string,patient_form_template_id:string,content:array<string,mixed>}
     */
    public function getAuthorizedPrefill(string $appointmentId, string $patientId, ApiClientInterface $client): array
    {
        $appointmentId = trim($appointmentId);
        $patientId = trim($patientId);
        if ($appointmentId === '' || $patientId === '') {
            throw new \RuntimeException('The appointment could not be found.');
        }

        $booking = Booking::find($appointmentId, $client);
        if (!$booking) {
            throw new \RuntimeException('The appointment could not be found.');
        }

        if (trim((string) $booking->getPatientId()) !== $patientId) {
            throw new \RuntimeException('The appointment could not be found.');
        }

        $attendees = $booking->getAttendees();
        if ($attendees === []) {
            throw new \RuntimeException('The appointment form could not be found.');
        }

        foreach ($attendees as $attendee) {
            $attendeePatient = $attendee->getPatient();
            if ($attendeePatient !== null && trim((string) $attendeePatient->getId()) !== $patientId) {
                continue;
            }

            $attendeeId = trim((string) $attendee->getId());
            if ($attendeeId === '') {
                continue;
            }

            // Cliniko bookings expose the attendee relationship, while the
            // answered form content is listed separately. Query the forms by
            // attendee_id so we do not accidentally prefill from another
            // appointment belonging to the same patient.
            $formsResponse = $client->get('patient_forms?' . implode('&', [
                'per_page=100',
                'sort=' . rawurlencode('created_at:desc'),
                'q[]=' . rawurlencode('attendee_id:=' . $attendeeId),
            ]));
            if (!$formsResponse->isSuccessful()) {
                continue;
            }
            $forms = is_array($formsResponse->data['patient_forms'] ?? null)
                ? $formsResponse->data['patient_forms']
                : [];
            usort($forms, static function (array $a, array $b): int {
                return (strtotime((string) ($b['created_at'] ?? $b['updated_at'] ?? '')) ?: 0)
                    <=> (strtotime((string) ($a['created_at'] ?? $a['updated_at'] ?? '')) ?: 0);
            });

            foreach ($forms as $form) {
                $formId = trim((string) ($form['id'] ?? ''));
                if ($formId === '' || !empty($form['archived_at'])) {
                    continue;
                }
                if (empty($form['completed_at'])) {
                    continue;
                }
                $content = $form['content'] ?? null;
                if (!is_array($content) || !is_array($content['sections'] ?? null)) {
                    $detail = $client->get('patient_forms/' . rawurlencode($formId));
                    if ($detail->isSuccessful()) {
                        $form = is_array($detail->data) ? $detail->data : $form;
                        $content = is_array($form['content'] ?? null) ? $form['content'] : null;
                    }
                }
                if (is_array($content) && is_array($content['sections'] ?? null)) {
                    return [
                        'appointment_id' => $appointmentId,
                        'patient_id' => $patientId,
                        'appointment_type_id' => trim((string) $booking->getAppointmentTypeId()),
                        'patient_form_template_id' => self::linkedResourceId($form['patient_form_template']['links']['self'] ?? null)
                            ?: trim((string) ($form['patient_form_template_id'] ?? '')),
                        'content' => $content,
                    ];
                }
            }
        }

        throw new \RuntimeException('No completed patient form was found for this appointment.');
    }

    /** @param mixed $url */
    private static function linkedResourceId($url): string
    {
        $path = parse_url((string) $url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        return $parts === [] ? '' : (string) end($parts);
    }

}
