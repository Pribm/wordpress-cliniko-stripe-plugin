<?php

use App\Service\ClinikoService;

if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('cliniko_get_calendar_timezone')) {
  function cliniko_get_calendar_timezone(): DateTimeZone
  {
    if (function_exists('wp_timezone')) {
      return wp_timezone();
    }

    $tzString = get_option('timezone_string');
    if (is_string($tzString) && $tzString !== '') {
      try {
        return new DateTimeZone($tzString);
      } catch (Throwable $e) {
        // fall through
      }
    }

    return new DateTimeZone('UTC');
  }
}

if (!function_exists('cliniko_build_appointment_calendar_context')) {
  /**
   * @param array{
   *   business_id?: string,
   *   practitioner_id?: string,
   *   appointment_type_id?: string,
   *   timezone?: DateTimeZone|string,
   *   per_page?: int,
   *   month?: string
   * } $args
   *
   * @return array{
   *   month_label: string,
   *   month_key: string,
   *   month_start: DateTimeImmutable,
   *   month_end: DateTimeImmutable,
   *   today: DateTimeImmutable,
   *   summary: array<string, array{morning:int, afternoon:int, evening:int}>,
   *   availability_failed: bool,
   *   timezone: DateTimeZone
   * }
   */
  function cliniko_build_appointment_calendar_context(array $args): array
  {
    $businessId = (string) ($args['business_id'] ?? '');
    $practitionerId = (string) ($args['practitioner_id'] ?? '');
    $appointmentTypeId = (string) ($args['appointment_type_id'] ?? '');

    $tz = $args['timezone'] ?? null;
    if (is_string($tz)) {
      try {
        $tz = new DateTimeZone($tz);
      } catch (Throwable $e) {
        $tz = null;
      }
    }
    if (!$tz instanceof DateTimeZone) {
      $tz = cliniko_get_calendar_timezone();
    }

    $today = new DateTimeImmutable('today', $tz);
    $targetMonth = null;
    $monthArg = isset($args['month']) ? trim((string) $args['month']) : '';
    if ($monthArg !== '') {
      $candidate = DateTimeImmutable::createFromFormat('Y-m', $monthArg, $tz);
      if ($candidate instanceof DateTimeImmutable) {
        $targetMonth = $candidate;
      }
    }
    if (!$targetMonth) {
      $targetMonth = $today;
    }

    $monthStart = $targetMonth->modify('first day of this month');
    $monthEnd = $targetMonth->modify('last day of this month');

    $summary = [];
    $availabilityFailed = false;

    $fromDate = $monthStart;
    if ($monthStart->format('Y-m') === $today->format('Y-m')) {
      $fromDate = $today;
    } elseif ($monthStart < $today) {
      $fromDate = $today;
    }

    if ($businessId !== '' && $practitionerId !== '' && $appointmentTypeId !== '' && $fromDate <= $monthEnd) {
      $perPage = isset($args['per_page']) ? max(1, (int) $args['per_page']) : 100;

      try {
        $service = new ClinikoService();
        $summary = $service->getAvailabilitySummaryForRange(
          $businessId,
          $practitionerId,
          $appointmentTypeId,
          $fromDate->format('Y-m-d'),
          $monthEnd->format('Y-m-d'),
          cliniko_client(false),
          $tz,
          $perPage
        );
      } catch (Throwable $e) {
        $availabilityFailed = true;
        $summary = [];
      }
    } else {
      $availabilityFailed = true;
    }

    return [
      'month_label' => $monthStart->format('F Y'),
      'month_key' => $monthStart->format('Y-m'),
      'month_start' => $monthStart,
      'month_end' => $monthEnd,
      'today' => $today,
      'summary' => is_array($summary) ? $summary : [],
      'availability_failed' => $availabilityFailed,
      'timezone' => $tz,
    ];
  }
}

if (!function_exists('cliniko_render_appointment_calendar_grid')) {
  /**
   * @param array{
   *   month_start: DateTimeImmutable,
   *   month_end: DateTimeImmutable,
   *   today: DateTimeImmutable,
   *   summary: array<string, array{morning:int, afternoon:int, evening:int}>,
   *   availability_failed?: bool
   * } $context
   */
  function cliniko_render_appointment_calendar_grid(array $context): string
  {
    $monthStart = $context['month_start'] ?? null;
    $monthEnd = $context['month_end'] ?? null;
    $today = $context['today'] ?? null;

    if (!$monthStart instanceof DateTimeImmutable || !$monthEnd instanceof DateTimeImmutable || !$today instanceof DateTimeImmutable) {
      return '';
    }

    $summary = is_array($context['summary'] ?? null) ? $context['summary'] : [];
    $availabilityFailed = !empty($context['availability_failed']);

    $firstDow = (int) $monthStart->format('w'); // 0 = Sunday
    $daysInMonth = (int) $monthEnd->format('j');
    $todayKey = $today->format('Y-m-d');
    $year = (int) $monthStart->format('Y');
    $month = (int) $monthStart->format('m');

    $periodLabels = [
      'morning' => 'Morning',
      'afternoon' => 'Afternoon',
      'evening' => 'Evening',
    ];

    ob_start();

    for ($i = 0; $i < $firstDow; $i++) {
      echo '<div class="calendar-day is-blank" aria-hidden="true"></div>';
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
      $date = $monthStart->setDate($year, $month, $day);
      $dateKey = $date->format('Y-m-d');
      $isPast = $date < $today;

      $daySummary = $summary[$dateKey] ?? ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
      if (!is_array($daySummary)) {
        $daySummary = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
      }

      $totalForDay = (int) ($daySummary['morning'] ?? 0)
        + (int) ($daySummary['afternoon'] ?? 0)
        + (int) ($daySummary['evening'] ?? 0);

      $hasAvailability = $totalForDay > 0;
      $isDisabled = $isPast || (!$availabilityFailed && !$hasAvailability);

      $classes = ['calendar-day'];
      if ($isDisabled) $classes[] = 'is-disabled';
      if ($dateKey === $todayKey) $classes[] = 'is-today';
      if (!$hasAvailability && !$availabilityFailed) $classes[] = 'is-empty';

      $label = $date->format('l, F j');
      echo '<div class="' . esc_attr(implode(' ', $classes)) . '" data-date="' . esc_attr($dateKey) . '"';
      echo ' aria-label="' . esc_attr($label) . '"';
      if ($isDisabled) {
        echo ' aria-disabled="true"';
      }
      echo '>';
      echo '<div class="calendar-day__number">' . esc_html((string) $day) . '</div>';
      echo '<div class="calendar-day__periods">';

      foreach ($periodLabels as $key => $title) {
        $periodClasses = ['calendar-period', 'calendar-period--' . $key];
        if (!empty($daySummary[$key])) {
          $periodClasses[] = 'is-active';
        }
        echo '<span class="' . esc_attr(implode(' ', $periodClasses)) . '" data-period="' . esc_attr($key) . '" title="' . esc_attr($title) . '"></span>';
      }

      echo '</div></div>';
    }

    return ob_get_clean();
  }
}
