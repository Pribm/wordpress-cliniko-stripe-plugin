<?php

namespace App\Service;

use App\Model\AppointmentType;

if (!defined('ABSPATH')) exit;

/** The same next-available scheduling policy for shortcode and API bookings. */
final class PatientBookingScheduling
{
    /** @return array{practitioner_id:string,appointment_start:string,appointment_date:string,label:string}|null */
    public static function nextAvailableSlot(string $appointmentTypeId): ?array
    {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '') {
            return null;
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $fromDate = new \DateTimeImmutable('now', $timezone);
            $toDate = $fromDate->add(new \DateInterval('P90D'));
            $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(false));
            if (!$appointmentType) {
                return null;
            }

            $client = cliniko_client(false);
            $service = new ClinikoService();
            $items = [];
            $practitioners = $appointmentType->getPractitioners();
            $visiblePractitioners = array_values(array_filter($practitioners, static function ($practitioner): bool {
                $dto = $practitioner->getDTO();
                return !($dto && property_exists($dto, 'active') && $dto->active === false)
                    && !($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false);
            }));
            if ($visiblePractitioners === []) {
                $visiblePractitioners = $practitioners;
            }

            foreach ($visiblePractitioners as $practitioner) {
                $dto = $practitioner->getDTO();
                if ($dto && property_exists($dto, 'active') && $dto->active === false) {
                    continue;
                }
                if ($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false) {
                    continue;
                }

                $start = $service->getNextAvailableTime(
                    (string) get_option('wp_cliniko_business_id'),
                    (string) $practitioner->getId(),
                    $appointmentTypeId,
                    $fromDate->format('Y-m-d'),
                    $toDate->format('Y-m-d'),
                    $client
                );
                if (!$start || empty($start->appointmentStart)) {
                    continue;
                }
                $items[] = [
                    'practitioner_id' => (string) $practitioner->getId(),
                    'appointment_start' => (string) $start->appointmentStart,
                ];
            }

            usort($items, static fn(array $a, array $b): int => strtotime($a['appointment_start']) <=> strtotime($b['appointment_start']));
            $selected = $items[0] ?? null;
            if (!$selected) {
                return null;
            }

            $start = new \DateTimeImmutable($selected['appointment_start']);
            return [
                'practitioner_id' => $selected['practitioner_id'],
                'appointment_start' => $selected['appointment_start'],
                'appointment_date' => $start->format('Y-m-d'),
                'label' => $start->setTimezone($timezone)->format('F j, Y \a\t g:i A (T)'),
            ];
        } catch (\Throwable $exception) {
            error_log('Cliniko patient booking next-available lookup failed: ' . $exception->getMessage());
            return null;
        }
    }

}
