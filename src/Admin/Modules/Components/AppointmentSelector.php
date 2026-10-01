<?php

namespace App\Admin\Modules\Components;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared appointment-selection view used by booking shortcodes.
 *
 * Its props control the variant while the legacy classes, data attributes, and
 * input names remain part of the public shortcode contract.
 */
final class AppointmentSelector
{
    /** @param array<string,mixed> $props */
    public static function render(array $props): string
    {
        $automatic = !empty($props['automatic']);
        $showCalendar = !array_key_exists('show_calendar', $props) || !empty($props['show_calendar']);
        $showPractitioner = !array_key_exists('show_practitioner', $props) || !empty($props['show_practitioner']);
        $isRenewal = !empty($props['is_renewal']);
        $nextSlot = is_array($props['next_slot'] ?? null) ? $props['next_slot'] : [];

        if (!$automatic && !$showCalendar) {
            return '';
        }

        ob_start();
        ?>
        <div class="cliniko-booking-step cliniko-component cliniko-component-appointment-selector" data-booking-step data-step-label="<?php echo esc_attr($isRenewal ? 'Calendar and practitioner selection' : 'Appointment'); ?>">
            <?php if ($automatic) : ?>
                <div class="cliniko-patient-booking-form__appointment-summary">
                    <p>Next available appointment: <?php echo esc_html((string) ($nextSlot['label'] ?? '')); ?></p>
                    <input type="hidden" name="practitioner_id" value="<?php echo esc_attr((string) ($nextSlot['practitioner_id'] ?? '')); ?>">
                    <input type="hidden" name="appointment_date" value="<?php echo esc_attr((string) ($nextSlot['appointment_date'] ?? '')); ?>">
                    <input type="hidden" name="appointment_start" value="<?php echo esc_attr((string) ($nextSlot['appointment_start'] ?? '')); ?>">
                </div>
            <?php else : ?>
                <div class="cliniko-patient-booking-form__appointment">
                    <div class="appointment-selection cliniko-booking-appointment-selection" data-booking-appointment-layout>
                        <div class="cliniko-booking-calendar cliniko-component-calendar appointment-calendar" data-booking-calendar>
                            <div class="cliniko-booking-calendar__header appointment-calendar__header">
                                <div class="appointment-calendar__nav">
                                    <button type="button" class="calendar-nav calendar-nav--prev" data-calendar-prev aria-label="Previous month"><span aria-hidden="true">&#8249;</span></button>
                                    <strong class="appointment-calendar__month" data-calendar-month>Loading calendar...</strong>
                                    <button type="button" class="calendar-nav calendar-nav--next" data-calendar-next aria-label="Next month"><span aria-hidden="true">&#8250;</span></button>
                                </div>
                                <div class="cliniko-booking-calendar__legend appointment-calendar__legend" aria-label="Availability periods">
                                    <span class="calendar-legend-item"><span class="calendar-legend-dot calendar-legend-dot--morning"></span>Morning</span>
                                    <span class="calendar-legend-item"><span class="calendar-legend-dot calendar-legend-dot--afternoon"></span>Afternoon</span>
                                    <span class="calendar-legend-item"><span class="calendar-legend-dot calendar-legend-dot--evening"></span>Evening</span>
                                </div>
                            </div>
                            <?php if ($showPractitioner) : ?>
                                <div class="appointment-calendar__filters" data-booking-practitioner-filter>
                                    <label class="appointment-calendar__label">Practitioner<select class="appointment-calendar__select" name="practitioner_id" required data-booking-practitioner><option value="">Loading practitioners...</option></select></label>
                                </div>
                            <?php else : ?><input type="hidden" name="practitioner_id" value=""><?php endif; ?>
                            <div class="appointment-calendar__weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                            <div class="cliniko-booking-calendar__loader" data-calendar-loader hidden role="status" aria-live="polite"><span class="cliniko-booking-calendar__spinner" aria-hidden="true"></span> Loading availability...</div>
                            <div class="appointment-calendar__grid" data-calendar-grid aria-live="polite"></div>
                            <p class="cliniko-booking-calendar__status" data-calendar-status role="status">Loading available dates...</p>
                        </div>
                        <div class="appointment-day-times" data-booking-times-panel tabindex="-1">
                            <div class="appointment-day-times__title" data-booking-times-title>Select a date first</div>
                            <div class="appointment-day-times__hint" data-booking-times-hint>Choose a date to see available times.</div>
                            <div class="appointment-day-times__placeholder" data-booking-times-placeholder>
                                <span class="appointment-day-times__placeholder-icon" aria-hidden="true">&#128197;</span>
                                <div class="appointment-day-times__placeholder-title">Select a date and time</div>
                                <div class="appointment-day-times__placeholder-text">Pick a day on the calendar to view available times.</div>
                            </div>
                            <div class="appointment-day-times__loading" data-booking-times-loader hidden aria-live="polite"><span class="appointment-day-times__spinner" aria-hidden="true"></span><span>Loading available times...</span></div>
                            <div class="appointment-day-times__groups" data-booking-time-groups hidden>
                                <?php foreach (['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'] as $period => $label) : ?>
                                    <div class="appointment-day-times__group" data-booking-time-group="<?php echo esc_attr($period); ?>">
                                        <div class="appointment-day-times__group-title"><?php echo esc_html($label); ?></div>
                                        <div class="appointment-day-times__group-slots" data-booking-time-slots="<?php echo esc_attr($period); ?>"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="appointment-day-times__empty" data-booking-times-empty hidden>No times available.</div>
                            <label class="cliniko-booking-time-select">Available time<select name="appointment_start" required data-booking-time><option value="">Choose a date first</option></select></label>
                        </div>
                    </div>
                    <input type="hidden" name="appointment_date" required data-booking-date>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
