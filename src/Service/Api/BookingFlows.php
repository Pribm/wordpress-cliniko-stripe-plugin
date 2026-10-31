<?php

namespace App\Service\Api;

use App\Model\AppointmentType;
use App\Model\PatientFormTemplate;
use App\Service\BookingFormStructure;
use App\Service\PatientBookingConfiguration;
use App\Service\PatientBookingScheduling;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** API-owned booking definitions; legacy website links remain compatible, never implicit. */
final class BookingFlows
{
    public const OPTION = 'wp_cliniko_api_booking_flows';

    public static function all(): array
    {
        $flows = get_option(self::OPTION, []);
        if (!is_array($flows)) return [];
        return array_map(static fn(array $flow): array => $flow + ['flow_type' => 'web'], array_filter($flows, 'is_array'));
    }

    /**
     * Return only flows that are currently usable through the patient API.
     * The individual flow resolver is intentionally reused so this list
     * cannot advertise a disabled, unpublished, archived or malformed flow.
     *
     * @return array{flows:list<array<string,mixed>>}|WP_Error
     */
    public static function available(): array|WP_Error
    {
        $items = [];
        foreach (self::all() as $slug => $binding) {
            $slug = (string) $slug;
            if (empty($binding['enabled'])) continue;
            $context = self::context($slug);
            if ($context instanceof WP_Error) {
                $errorData = $context->get_error_data();
                $status = is_array($errorData) ? (int) ($errorData['status'] ?? 0) : 0;
                if (in_array($status, [404, 409, 422], true)) continue;
                return $context;
            }
            $definition = $context['definition'];
            $config = $context['configuration'];
            $items[] = [
                'identifier' => $slug,
                'name' => (string) ($config['name'] ?? $slug),
                'flow_type' => (string) ($binding['flow_type'] ?? 'web'),
                'definition_revision' => (string) ($definition['definition_revision'] ?? ''),
                'appointment_type' => $definition['appointment_type'],
                'form' => [
                    'id' => (string) ($config['patient_form_template_id'] ?? ''),
                    'name' => (string) ($definition['form']['name'] ?? ''),
                ],
                'scheduling' => $definition['scheduling'],
                'payment' => $definition['payment'],
                'endpoints' => [
                    'structure' => ['method' => 'GET', 'path' => '/booking-flows/' . $slug, 'required_scope' => 'booking:read'],
                    'create_booking' => ['method' => 'POST', 'path' => '/booking-flows/' . $slug . '/booking-attempts', 'required_scope' => 'booking:create'],
                ],
            ];
        }
        return ['flows' => $items];
    }

    /** Legacy website publication only. New admin creation uses saveApi(). */
    public static function save(string $slug, string $configurationId, bool $enabled)
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $slug)) return new WP_Error('invalid_booking_flow_slug', 'Choose a 2–64 character identifier using lowercase letters, numbers and hyphens.');
        $config = PatientBookingConfiguration::patients()[$configurationId] ?? null;
        if (!$config) return new WP_Error('invalid_booking_configuration', 'Select an existing patient booking setup. Guest setups cannot be published.');
        foreach (['appointment_type_id', 'patient_form_template_id'] as $field) if (!preg_match('/^[0-9]+$/D', (string) ($config[$field] ?? ''))) return new WP_Error('invalid_booking_configuration', 'This patient setup needs a valid appointment type and form template.');
        if ($enabled && !self::published($config)) return new WP_Error('unpublished_booking_configuration', 'Publish this setup’s appointment type and form template in Publish API before enabling the flow.');
        $flows = self::all();
        if (isset($flows[$slug]) && ($flows[$slug]['flow_type'] ?? '') !== 'web') return new WP_Error('invalid_booking_configuration', 'An API-only flow cannot be replaced with a website link.');
        $flows[$slug] = ['flow_type' => 'web', 'configuration_id' => $configurationId, 'enabled' => $enabled];
        update_option(self::OPTION, $flows, false);
        return true;
    }

    /** Settings shared with the web builder; no web layout, identity or code fields. */
    public static function saveApi(string $slug, array $input, bool $enabled)
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $slug)) return new WP_Error('invalid_booking_flow_slug', 'Choose a 2–64 character identifier using lowercase letters, numbers and hyphens.');
        if (array_diff(array_keys($input), ['name', 'appointment_type_id', 'patient_form_template_id', 'gateway', 'scheduling_mode', 'input_rules', 'rules_template_id'])) return new WP_Error('invalid_booking_configuration', 'API flows accept booking settings and answer rules only, not website presentation or patient fields.');
        $parameters = PatientBookingConfiguration::parameters($input);
        if ($parameters instanceof WP_Error) return $parameters;
        $previous = self::all()[$slug] ?? null;
        if ($previous && $previous['flow_type'] !== 'api') return new WP_Error('invalid_booking_configuration', 'Use Make API-only to detach an existing website-linked flow.');
        $rules = [];
        $old = $previous ? self::configuration($previous) : null;
        if ($old && $old['patient_form_template_id'] === $parameters['patient_form_template_id']) $rules = $old['input_rules'] ?? [];
        if (array_key_exists('input_rules', $input)) {
            if (!is_array($input['input_rules']) || !is_scalar($input['rules_template_id'] ?? null)) return new WP_Error('invalid_booking_rules', 'Answer rules must belong to the selected form template.');
            // Changing a template resets index-based rules; never apply old rules to new questions.
            if ((string) $input['rules_template_id'] === $parameters['patient_form_template_id']) {
                try { $template = PatientFormTemplate::find($parameters['patient_form_template_id'], cliniko_client(false), true); }
                catch (\Throwable $error) { return new WP_Error('booking_template_unavailable', 'The form could not be checked. No changes were saved; try again when Cliniko is available.'); }
                if (!$template || $template->isArchived()) return new WP_Error('invalid_booking_rules', 'Choose an available form template before saving answer rules.');
                foreach ($input['input_rules'] as $rule) {
                    if (!is_array($rule) || array_diff(array_keys($rule), ['input_type', 'select_options', 'max_length', 'input_format', 'mask_pattern', 'mask_prefix', 'mask_suffix'])) return new WP_Error('invalid_booking_rules', 'Only answer checks can be configured here.');
                    foreach ($rule as $field => $value) {
                        if ($field === 'select_options') {
                            if (!is_string($value) && (!is_array($value) || array_filter($value, static fn($item): bool => !is_string($item)))) return new WP_Error('invalid_booking_rules', 'Allowed answers must be a list of text values.');
                        } elseif (!is_scalar($value)) return new WP_Error('invalid_booking_rules', 'Answer checks must be simple values.');
                    }
                }
                $rules = PatientBookingConfiguration::questionRules($template, $input['input_rules']);
                if (array_diff(array_keys($input['input_rules']), array_keys($rules))) return new WP_Error('invalid_booking_rules', 'Some answer rules do not match the current form questions. Reload the editor.');
            }
        }
        return self::persistApi($slug, $parameters, $rules, $enabled);
    }

    /** Explicitly detach this identifier without editing or deleting the original web form. */
    public static function makeApiOnly(string $slug)
    {
        $previous = self::all()[$slug] ?? null;
        if (!$previous || $previous['flow_type'] !== 'web') return new WP_Error('invalid_booking_configuration', 'Choose an existing website-linked flow.');
        $config = self::configuration($previous);
        if (!$config) return new WP_Error('invalid_booking_configuration', 'The original patient booking setup is missing.');
        $parameters = PatientBookingConfiguration::parameters([
            'name' => $config['name'] ?? $slug, 'appointment_type_id' => $config['appointment_type_id'] ?? '',
            'patient_form_template_id' => $config['patient_form_template_id'] ?? '', 'gateway' => $config['gateway'] ?? 'stripe',
            'scheduling_mode' => PatientBookingConfiguration::schedulingMode($config),
        ]);
        if ($parameters instanceof WP_Error) return $parameters;
        $rules = array_filter(is_array($config['input_rules'] ?? null) ? $config['input_rules'] : [], static fn($key): bool => preg_match('/^question_[0-9]+_[0-9]+$/D', (string) $key) === 1, ARRAY_FILTER_USE_KEY);
        return self::persistApi($slug, $parameters, $rules, !empty($previous['enabled']));
    }

    private static function persistApi(string $slug, array $parameters, array $rules, bool $enabled)
    {
        $config = ['flow_type' => 'api', 'booking_mode' => 'patient'] + $parameters + ['input_rules' => $rules];
        $flow = ['flow_type' => 'api', 'enabled' => $enabled, 'configuration' => $config];
        if ($enabled) {
            if (!self::published($config)) return new WP_Error('unpublished_booking_configuration', 'Publish this appointment type and form template in Publish API before enabling the flow.');
            try { $context = self::describe($slug, $flow, $config); }
            catch (\Throwable $error) { return new WP_Error('booking_template_unavailable', 'The booking setup could not be checked. No changes were saved; try again when Cliniko is available.'); }
            if ($context instanceof WP_Error) return $context;
        }
        $flows = self::all(); $flows[$slug] = $flow;
        update_option(self::OPTION, $flows, false);
        return true;
    }

    /** Read-only resolver: unmarked historical records are website links. */
    public static function configuration(array $flow): ?array
    {
        if (($flow['flow_type'] ?? 'web') === 'web') return PatientBookingConfiguration::patients()[(string) ($flow['configuration_id'] ?? '')] ?? null;
        if (($flow['flow_type'] ?? '') !== 'api') return null;
        $config = $flow['configuration'] ?? null;
        if (!is_array($config) || ($config['flow_type'] ?? '') !== 'api' || ($config['booking_mode'] ?? '') !== 'patient') return null;
        if (PatientBookingConfiguration::parameters($config) instanceof WP_Error || !is_array($config['input_rules'] ?? [])) return null;
        return $config;
    }

    public static function remove(string $slug): void
    {
        $flows = self::all(); unset($flows[$slug]); update_option(self::OPTION, $flows, false);
    }

    /** Always available, including during a Cliniko outage. */
    public static function disable(string $slug)
    {
        $flows = self::all();
        if (!isset($flows[$slug])) return new WP_Error('invalid_booking_configuration', 'That booking flow does not exist.');
        $flows[$slug]['enabled'] = false;
        update_option(self::OPTION, $flows, false);
        return true;
    }

    private static function published(array $config): bool
    {
        $settings = ApiSettings::get();
        return in_array((string) $config['appointment_type_id'], $settings['appointment_types'], true)
            && in_array((string) $config['patient_form_template_id'], $settings['form_templates'], true);
    }

    /** Saved-state status only; rendering the admin docs never contacts Cliniko. */
    public static function publicationState(string $slug): string
    {
        $flow = self::all()[$slug] ?? null;
        if (!$flow || empty($flow['enabled'])) return 'Draft / disabled';
        $config = self::configuration($flow);
        if (!$config) return 'Source missing or not a patient setup';
        if (!self::published($config)) return 'Appointment type or form template unpublished';
        if (!ApiSettings::get()['enabled']) return 'API disabled';
        if (!ApiSettings::groupEnabled('patient_booking') || !ApiSettings::groupEnabled('booking')) return 'Patient booking and payment groups required';
        return 'Published — verified patient required';
    }

    public static function context(string $slug)
    {
        $binding = self::all()[$slug] ?? null;
        $config = is_array($binding) && !empty($binding['enabled']) ? self::configuration($binding) : null;
        if (!$config || !self::published($config)) return new WP_Error('booking_flow_not_found', 'This booking flow is not available.', ['status' => 404]);
        return self::describe($slug, $binding, $config);
    }

    private static function describe(string $slug, array $binding, array $config)
    {
        $client = cliniko_client(false);
        $type = AppointmentType::find((string) $config['appointment_type_id'], $client, true);
        $template = PatientFormTemplate::find((string) $config['patient_form_template_id'], $client, true);
        if (!$type || !$template || $template->isArchived() || !empty($type->getDTO()->archivedAt)) return new WP_Error('booking_flow_not_found', 'This booking flow is not available.', ['status' => 404]);
        $form = BookingFormStructure::describe($template, is_array($config['input_rules'] ?? null) ? $config['input_rules'] : []);
        if ($form instanceof WP_Error) return $form;
        $amount = max(0, (int) $type->getBillableItemsFinalPrice());
        $gateway = (string) ($config['gateway'] ?? 'stripe');
        if (!in_array($gateway, ['stripe', 'tyrohealth', 'none'], true) || ($amount > 0 && $gateway === 'none')) return new WP_Error('invalid_booking_payment_configuration', 'This booking setup does not have a valid payment method for its price. Ask the clinic to review it.', ['status' => 422]);
        $mode = PatientBookingConfiguration::schedulingMode($config);
        $definition = ['identifier' => $slug, 'appointment_type' => ['id' => (string) $type->getId(), 'name' => $type->getName(), 'duration_in_minutes' => $type->getDurationInMinutes()],
            'form' => $form, 'scheduling' => ['mode' => $mode, 'required_fields' => $mode === 'manual' ? ['practitioner_id', 'appointment_start'] : []],
            'payment' => ['required' => $amount > 0, 'gateway' => $amount > 0 ? $gateway : 'free', 'amount' => $amount, 'currency' => 'aud']];
        $source = ($binding['flow_type'] ?? 'web') === 'api' ? ['api', $slug] : $binding['configuration_id'];
        $revision = hash('sha256', wp_json_encode([$source, $definition]));
        $definition['definition_revision'] = $revision;
        $definition['submission'] = ['method' => 'POST', 'path' => '/booking-flows/' . $slug . '/booking-attempts', 'required_scope' => 'booking:create',
            'body' => ['definition_revision' => $revision, 'patient' => $mode === 'manual' ? ['practitioner_id' => '', 'appointment_start' => ''] : (object) [], 'content' => BookingFormStructure::emptyContent($form)]];
        $definition['resources'] = ['practitioners' => '/appointment-types/' . $type->getId() . '/practitioners', 'availability' => '/appointment-types/' . $type->getId() . '/availability'];
        return ['definition' => $definition, 'configuration' => $config, 'rules' => PatientBookingConfiguration::questionRules($template, is_array($config['input_rules'] ?? null) ? $config['input_rules'] : [])];
    }

    /** Resolve again at submission time, before any booking writes. */
    public static function prepare(string $slug, array $body)
    {
        if (array_diff(array_keys($body), ['definition_revision', 'patient', 'content'])) return new WP_Error('unknown_fields', 'Only definition_revision, patient and content are accepted.', ['status' => 400]);
        $context = self::context($slug);
        if ($context instanceof WP_Error) return $context;
        $definition = $context['definition'];
        if (!hash_equals($definition['definition_revision'], (string) ($body['definition_revision'] ?? ''))) return new WP_Error('booking_definition_changed', 'The booking setup changed. Reload its structure before submitting.', ['status' => 409]);
        $patient = $body['patient'] ?? [];
        if (!is_array($patient) || array_diff(array_keys($patient), ['practitioner_id', 'appointment_start'])) return new WP_Error('invalid_booking_selection', 'Only scheduling fields may be supplied.', ['status' => 400]);
        if ($definition['scheduling']['mode'] === 'next_available') {
            if ($patient !== []) return new WP_Error('invalid_booking_selection', 'This setup chooses the next available appointment on the server; do not send a practitioner or time.', ['status' => 400]);
            $slot = PatientBookingScheduling::nextAvailableSlot((string) $context['configuration']['appointment_type_id']);
            if (!$slot) return new WP_Error('booking_slot_unavailable', 'No next available appointment could be found.', ['status' => 422]);
            $patient = array_intersect_key($slot, array_flip(['practitioner_id', 'appointment_start']));
        } else {
            if (!preg_match('/^[0-9]+$/D', (string) ($patient['practitioner_id'] ?? '')) || !is_string($patient['appointment_start'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T.+(?:Z|[+-]\d{2}:\d{2})$/D', $patient['appointment_start'])) return new WP_Error('invalid_booking_selection', 'A numeric practitioner ID and available ISO appointment time are required.', ['status' => 400]);
        }
        if (!is_array($body['content'] ?? null)) return new WP_Error('invalid_booking_answers', 'Form answers are required.', ['status' => 400]);
        $content = BookingFormStructure::validateAnswers($definition['form'], $body['content'], $context['rules']);
        if ($content instanceof WP_Error) return $content;
        return ['moduleId' => (string) $context['configuration']['appointment_type_id'], 'patient_form_template_id' => (string) $context['configuration']['patient_form_template_id'],
            'gateway' => $definition['payment']['gateway'], 'patient' => $patient, 'content' => $content];
    }
}
