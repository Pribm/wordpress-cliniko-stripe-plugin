<?php

namespace App\Admin\Modules\ApiAccess;

use App\Model\PatientFormTemplate;
use App\Service\Api\ApiSettings;
use App\Service\Api\BookingFlows;
use App\Service\BookingFormStructure;
use App\Service\PatientBookingConfiguration;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** Whitelisted development snapshot, not an import bundle or a live API response. */
final class BookingFlowDeveloperBundle
{
    public static function build(array $flows, array $appointments, array $forms, array $templates)
    {
        if (!current_user_can('manage_options')) return new WP_Error('developer_bundle_forbidden', 'Only administrators can prepare the booking-flow developer bundle.');
        $settings = ApiSettings::get();
        $base = rtrim(rest_url(ApiSettings::namespace()), '/');
        $items = [];
        foreach ($flows as $slug => $flow) {
            $slug = (string) $slug;
            $config = BookingFlows::configuration($flow);
            $appointmentId = (string) ($config['appointment_type_id'] ?? '');
            $formId = (string) ($config['patient_form_template_id'] ?? '');
            $appointment = $appointments[$appointmentId] ?? null;
            $structure = null;
            $issues = [];
            if (!$config) $issues[] = 'The saved booking configuration is missing or invalid.';
            elseif (!$appointment) $issues[] = 'Appointment reference details are unavailable; the saved ID is retained.';
            $template = $templates[$formId] ?? null;
            if ($config && $template instanceof PatientFormTemplate && !$template->isArchived()) {
                try {
                    $result = BookingFormStructure::describe($template, is_array($config['input_rules'] ?? null) ? $config['input_rules'] : []);
                    if ($result instanceof WP_Error) $issues[] = $result->get_error_message();
                    else $structure = $result;
                } catch (\Throwable $error) { $issues[] = 'The blank form structure could not be checked.'; }
            } elseif ($config) $issues[] = 'Blank form questions are unavailable or archived. No questions have been invented.';
            $mode = $config ? PatientBookingConfiguration::schedulingMode($config) : null;
            $readPath = '/booking-flows/' . $slug;
            $createPath = $readPath . '/booking-attempts';
            $items[] = [
                'identifier' => $slug,
                'name' => (string) ($config['name'] ?? $slug),
                'flow_type' => (string) ($flow['flow_type'] ?? 'web'),
                'enabled' => !empty($flow['enabled']),
                'saved_status' => BookingFlows::publicationState($slug),
                'preview_complete' => $config !== null && $appointment !== null && $structure !== null,
                'warnings' => $issues,
                'authentication' => ['scheme' => 'Bearer', 'header' => 'Authorization', 'verified_patient_required' => true, 'approved_application_required' => true],
                'endpoints' => [
                    'read_structure' => ['method' => 'GET', 'path' => $readPath, 'url' => $base . $readPath, 'required_scope' => 'booking:read'],
                    'create_booking' => ['method' => 'POST', 'path' => $createPath, 'url' => $base . $createPath, 'required_scope' => 'booking:create'],
                ],
                'appointment_type' => $config ? ['id' => $appointmentId, 'name' => $appointment['name'] ?? null, 'duration_in_minutes' => $appointment['minutes'] ?? null] : null,
                'form_template_reference' => $config ? ['id' => $formId, 'name' => $structure['name'] ?? $forms[$formId]['name'] ?? null] : null,
                'form' => $structure,
                'scheduling' => $config ? ['mode' => $mode, 'required_fields' => $mode === 'manual' ? ['practitioner_id', 'appointment_start'] : []] : null,
                'payment' => $config ? ['configured_gateway' => $config['gateway'] ?? 'stripe', 'amount' => null, 'required' => null, 'currency' => 'aud', 'price_source' => 'Fetch payment from the authenticated read_structure endpoint; amount is in cents.'] : null,
                'submission_template' => $structure ? [
                    'method' => 'POST', 'path' => $createPath, 'url' => $base . $createPath, 'required_scope' => 'booking:create',
                    'body' => ['definition_revision' => '<latest definition_revision from the authenticated GET>',
                        'patient' => $mode === 'manual' ? ['practitioner_id' => '', 'appointment_start' => ''] : (object) [],
                        'content' => BookingFormStructure::emptyContent($structure)],
                ] : null,
                'resources' => $config ? ['practitioners' => $base . '/appointment-types/' . $appointmentId . '/practitioners', 'availability' => $base . '/appointment-types/' . $appointmentId . '/availability'] : null,
            ];
        }
        return [
            'format' => 'cliniko-booking-flow-developer-bundle', 'format_version' => 1, 'purpose' => 'frontend_mocking_and_scaffolding',
            'generated_at' => gmdate('c'), 'api_base_url' => $base, 'api_enabled' => $settings['enabled'],
            'route_groups' => ['patient_booking' => ApiSettings::groupEnabled('patient_booking'), 'booking' => ApiSettings::groupEnabled('booking')],
            'notes' => [
                'Read-only snapshot of saved flows at page load, including disabled drafts and legacy website-linked flows. Unsaved editor changes are excluded.',
                'This is not an API response, import file, live availability check, price quotation or patient authorization.',
                'Patient records, completed answers, credentials, tokens, website custom code and redirects are not included.',
                'Form questions and validation use the same structural helper as the API. Reference service names and durations may be cached.',
                'Payment amount and required are unknown (null), not zero or free. Fetch current payment and definition_revision from the authenticated GET before submitting.',
                'Submission bodies contain empty answers and placeholders for development. Do not submit them unchanged to a live clinic.',
                'preview_complete describes available reference structure only, not publication eligibility. Review each flow’s warnings and saved_status.',
                'Practitioner and availability links also need enabled discovery routes and the corresponding app permissions.',
            ],
            'flows' => $items,
        ];
    }
}
