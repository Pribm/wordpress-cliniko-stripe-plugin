<?php

namespace App\Admin\Modules\ApiAccess;

use App\Model\PatientFormTemplate;
use App\Service\Api\ApiSettings;
use App\Service\Api\BookingFlows;
use App\Service\BookingFormStructure;
use App\Service\PatientBookingConfiguration;

if (!defined('ABSPATH')) exit;

/** API settings only; the website editor is not executed or embedded here. */
final class BookingFlowDashboard
{
    public static function render(string $url, string $docsUrl, callable $help): void
    {
        $flows = BookingFlows::all();
        $selected = sanitize_key((string) ($_GET['section'] ?? ''));
        $editing = $flows[$selected] ?? null;
        $config = $editing && $editing['flow_type'] === 'api' ? BookingFlows::configuration($editing) : null;
        echo '<p class="cliniko-api-lead">Create booking flows just for your app. No shortcode or website form is required. The app chooses how to display the returned structure.</p>';
        echo '<div class="cliniko-api-flow-toolbar"><a class="button button-primary" href="' . esc_url($url . '#api-booking-flow-editor') . '">Create API booking flow</a><div><button type="button" class="button" data-copy-target="cliniko-api-booking-flow-json" data-copy-status-target="cliniko-api-booking-flow-copy-status" data-copy-success="Booking flow JSON copied. Share it with your developer; prices and revisions must be fetched from the live API.">Copy flow list (JSON)</button> <button type="button" class="button" data-download-json-target="cliniko-api-booking-flow-json" data-copy-status-target="cliniko-api-booking-flow-copy-status">Download JSON</button></div></div><p id="cliniko-api-booking-flow-copy-status" class="cliniko-api-copy-status" role="status" aria-live="polite"></p>';
        echo '<details class="cliniko-api-card cliniko-api-flow-intro"><summary>Getting started <span>Setup steps and booking requirements</span></summary><ol><li>Create an API booking flow below: choose its appointment type, form, scheduling and payment method.</li><li>Save a draft, then open it to review the form questions and optional answer checks.</li><li>In Publish API, approve its appointment type and form template; enable Authenticated patient booking and Booking payment and progress.</li><li>Configure patient login, approve booking:read and booking:create for the app, and enable this flow.</li><li>The signed-in app first reads GET /booking-flows to discover available flows, then reads GET /booking-flows/{identifier}, fills the returned body, and POSTs /booking-flows/{identifier}/booking-attempts.</li></ol><p>Every booking still requires a verified patient. Prices and appointment availability are checked on the server. The app handles screens, success messages and errors; there are no website layout, redirect or custom-code settings here.</p><a class="button" href="' . esc_url($docsUrl . '#cliniko-api-category-booking_flows') . '">Open endpoint documentation</a></details>';
        $appointmentChoices = AppointmentTypeChoices::load();
        $templateChoices = FormTemplateChoices::load();
        $appointmentIndex = array_column($appointmentChoices['items'], null, 'id');
        $templateIndex = array_column($templateChoices['items'], null, 'id');
        $templates = [];
        echo '<div class="cliniko-api-booking-flow-list" aria-label="Saved booking flows">';
        foreach ($flows as $slug => $flow) {
            self::flowRow((string) $slug, $flow, $url, $appointmentIndex, $templateIndex, $templates);
        }
        if ($flows === []) echo '<p class="cliniko-api-flow-empty">No booking flows yet. Create your first API-only flow below.</p>';
        echo '</div>';
        $bundle = BookingFlowDeveloperBundle::build($flows, $appointmentIndex, $templateIndex, $templates);
        if (!is_wp_error($bundle)) {
            $json = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json)) echo '<details class="cliniko-api-card cliniko-api-flow-export"><summary>View developer JSON <span>Copy or download the saved flow list for frontend mocks</span></summary><p>This includes drafts, resource details, blank questions, validation, endpoints and empty submission bodies. It contains no patient records or credentials. Prices are unknown and the revision is a placeholder: fetch both from the live authenticated API before booking. This is not a live API response or an import file.</p><label class="screen-reader-text" for="cliniko-api-booking-flow-json">Booking flow developer JSON</label><textarea readonly spellcheck="false" class="large-text code" id="cliniko-api-booking-flow-json" rows="12">' . esc_textarea($json) . '</textarea></details>';
        }
        echo '<section class="cliniko-api-card" id="api-booking-flow-editor"><h3>' . ($config ? 'Edit API booking flow' : 'Create API booking flow') . '</h3>';
        if ($selected !== '' && !$config) echo '<p class="cliniko-api-warning">That API-only flow could not be opened. You can create a new flow below; a legacy flow must first be made API-only.</p>';
        foreach (array_unique(array_filter([$appointmentChoices['error'], $templateChoices['error']])) as $error) echo '<p class="cliniko-api-picker-warning">' . esc_html($error) . ' Saved selections are retained.</p>';
        self::startForm($config ? $selected : '', $config ? 'save' : 'create', 'data-api-booking-flow');
        if (!$config) {
            echo '<p><label for="api-booking-identifier"><strong>API identifier</strong></label>';
            $help('API identifier', 'This is the name in your app’s booking address, such as initial-consultation. Use lowercase letters, numbers and hyphens, 2–64 characters. It is fixed once created so app URLs do not change accidentally.', 'api-booking-identifier');
            echo '<br><input id="api-booking-identifier" name="flow_slug" class="regular-text" required minlength="2" maxlength="64" pattern="[a-z][a-z0-9\x2d]{1,63}" placeholder="initial-consultation"></p>';
        } else echo '<p>Identifier: <code>' . esc_html($selected) . '</code> · API only <a href="' . esc_url($url) . '#api-booking-flow-editor">Create another flow</a></p>';
        echo '<table class="form-table"><tr><th><label for="api-booking-name">Flow name</label></th><td><input id="api-booking-name" class="regular-text" name="name" required value="' . esc_attr((string) ($config['name'] ?? '')) . '" placeholder="Initial consultation"><p class="description">A name for your team to recognise this setup.</p></td></tr>';
        self::choice('appointment_type_id', 'Appointment type', (string) ($config['appointment_type_id'] ?? ''), $appointmentChoices['items'], $help, 'Choose what the patient will book. Duration and price come from Cliniko, not the app. Selecting it here does not automatically publish it.');
        self::choice('patient_form_template_id', 'Form template', (string) ($config['patient_form_template_id'] ?? ''), $templateChoices['items'], $help, 'Choose the questions the app should receive. This is a blank Cliniko form, not a completed patient form. Changing it resets the old question checks; save and review the new questions.');
        echo '<tr><th><label for="api-booking-scheduling">Appointment time</label>';
        $help('Appointment time', 'Let the app ask the patient to choose a practitioner and available time, or have the website select the earliest available appointment when the app submits the booking.', 'api-booking-scheduling');
        echo '</th><td><select id="api-booking-scheduling" name="scheduling_mode"><option value="manual" ' . selected($config['scheduling_mode'] ?? 'manual', 'manual', false) . '>Patient chooses practitioner and time</option><option value="next_available" ' . selected($config['scheduling_mode'] ?? '', 'next_available', false) . '>Automatically choose the next available appointment</option></select></td></tr>';
        echo '<tr><th><label for="api-booking-payment">Payment method</label>';
        $help('Payment method', 'Choose the payment service the app will use. No payment is valid only for a zero-priced appointment type. The website checks the actual price and never accepts a price sent by the app.', 'api-booking-payment');
        echo '</th><td><select id="api-booking-payment" name="gateway">';
        foreach (['stripe' => 'Stripe', 'tyrohealth' => 'Tyro Health', 'none' => 'No payment (free appointments only)'] as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($config['gateway'] ?? 'stripe', $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></td></tr></table>';
        if ($config) self::answerRules($config, $help);
        else echo '<p class="description">Save the draft first to load its form questions and configure optional answer checks.</p>';
        echo '<p><label><input type="checkbox" name="flow_enabled" value="1" ' . checked($config && !empty($editing['enabled']), true, false) . '> Enable this API flow</label>';
        $help('Enable this API flow', 'Leave this off while setting up. Before enabling, publish its appointment type and form template, enable patient booking and payment routes, and configure an approved patient app. Enabling never permits guest bookings.', 'api-booking-enable');
        echo '</p>';
        submit_button($config ? 'Save API booking flow' : 'Create API booking flow'); echo '</form></section>';
    }

    /** Compact summary and actions; business handlers and nonce protection are unchanged. */
    private static function flowRow(string $slug, array $flow, string $url, array $appointments, array $forms, array &$templates): void
    {
        $source = BookingFlows::configuration($flow);
        $apiOnly = $flow['flow_type'] === 'api';
        $name = (string) ($source['name'] ?? $slug);
        $panelId = 'cliniko-api-booking-flow-' . $slug;
        $state = BookingFlows::publicationState($slug);
        echo '<article class="cliniko-api-booking-flow" data-booking-flow-row><header class="cliniko-api-booking-flow-header">';
        echo '<button type="button" class="cliniko-api-booking-flow-toggle" data-booking-flow-toggle aria-expanded="true" aria-controls="' . esc_attr($panelId) . '" disabled><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="cliniko-api-booking-flow-copy"><span class="cliniko-api-booking-flow-name">' . esc_html($name) . '</span><span class="cliniko-api-booking-flow-meta"><code>' . esc_html($slug) . '</code><span class="cliniko-api-flow-kind">' . ($apiOnly ? 'API-only booking flow' : 'Legacy website-linked flow') . '</span><span class="cliniko-api-flow-state">' . esc_html($state) . '</span></span></span></button>';
        echo '<div class="cliniko-api-booking-flow-actions" role="group" aria-label="' . esc_attr('Actions for ' . $name) . '">';
        if ($apiOnly) echo '<a class="button" aria-label="' . esc_attr('Edit API flow: ' . $name) . '" href="' . esc_url(add_query_arg('section', $slug, $url)) . '#api-booking-flow-editor">Edit</a>';
        else {
            self::startForm($slug, 'make-api-only');
            echo '<button type="submit" class="button" ' . (!$source ? 'disabled title="Restore the missing website setup first."' : '') . ' data-api-confirm="Make this flow API-only at the same address? The website form stays unchanged; apps must reload the booking structure.">Make API-only</button></form>';
        }
        self::startForm($slug, 'disable');
        echo '<button type="submit" class="button" aria-label="' . esc_attr('Disable API flow: ' . $name) . '" ' . (empty($flow['enabled']) ? 'disabled title="This API flow is already disabled."' : '') . '>Disable</button></form>';
        self::startForm($slug, 'remove');
        echo '<button type="submit" class="button cliniko-api-flow-remove" aria-label="' . esc_attr('Remove API flow: ' . $name) . '" data-api-confirm="Remove this API flow? Website forms and existing booking attempts will remain unchanged.">Remove</button></form></div></header>';
        echo '<div class="cliniko-api-booking-flow-body" id="' . esc_attr($panelId) . '"><p class="cliniko-api-flow-endpoint"><strong>Read structure</strong><code>GET ' . esc_html(rest_url(ApiSettings::namespace() . '/booking-flows/' . $slug)) . '</code></p><p class="cliniko-api-flow-endpoint"><strong>Create booking</strong><code>POST ' . esc_html(rest_url(ApiSettings::namespace() . '/booking-flows/' . $slug . '/booking-attempts')) . '</code></p><p class="description">Both routes require a verified patient access token and approved app permissions. Saved status does not replace live resource checks.</p>';
        if (!$source) echo '<p class="cliniko-api-picker-warning">The saved source is missing or invalid. Calls fail closed; remove the flow or restore the original source.</p>';
        else {
            $appointmentId = (string) ($source['appointment_type_id'] ?? '');
            $formId = (string) ($source['patient_form_template_id'] ?? '');
            $appointment = $appointments[$appointmentId] ?? ['id' => $appointmentId, 'name' => 'Previously selected appointment type'];
            $formName = (string) ($forms[$formId]['name'] ?? 'Previously selected form template');
            echo '<div class="cliniko-api-flow-detail-grid"><details class="cliniko-api-flow-resource" data-flow-appointment><summary><span>Appointment type</span><strong>' . esc_html($appointment['name']) . '</strong></summary><div class="cliniko-api-flow-resource-body"><dl class="cliniko-api-flow-facts"><dt>Cliniko ID</dt><dd>' . esc_html($appointmentId) . '</dd>';
            if (isset($appointment['minutes'])) echo '<dt>Duration</dt><dd>' . esc_html((string) $appointment['minutes']) . ' minutes</dd>';
            echo '<dt>Appointment time</dt><dd>' . (PatientBookingConfiguration::schedulingMode($source) === 'next_available' ? 'Next available appointment, selected on the server' : 'Patient chooses practitioner and available time') . '</dd><dt>Payment method</dt><dd>' . esc_html(['stripe' => 'Stripe', 'tyrohealth' => 'Tyro Health', 'none' => 'No payment (free appointments only)'][$source['gateway'] ?? 'stripe'] ?? 'Review payment configuration') . '</dd></dl><p class="description">Price is checked against Cliniko when the app requests the structure or submits a booking.</p></div></details>';
            echo '<details class="cliniko-api-flow-resource" data-flow-template><summary><span>Form template</span><strong>' . esc_html($formName) . '</strong></summary><div class="cliniko-api-flow-resource-body"><p>Cliniko ID: <code>' . esc_html($formId) . '</code>. Blank questions only; no completed patient answers.</p>';
            self::formPreview($source, $templates);
            echo '</div></details></div>';
        }
        if (!$apiOnly) {
            echo '<div class="cliniko-api-flow-legacy"><p>This historical publication reads website setup <code>' . esc_html((string) ($flow['configuration_id'] ?? '')) . '</code>. Make API-only copies only its booking settings and answer rules to this address, leaving the website form unchanged. Future website edits will no longer affect the detached API flow.</p>';
            self::startForm($slug, 'legacy-save');
            echo '<label><input type="checkbox" name="flow_enabled" value="1" ' . checked(!empty($flow['enabled']), true, false) . '> Enable this legacy API publication</label>';
            submit_button('Save legacy publication'); echo '</form></div>';
        }
        echo '</div></article>';
    }

    /** Load each distinct blank template only once per page; reuse structural validation. */
    private static function formPreview(array $config, array &$templates): void
    {
        $id = (string) ($config['patient_form_template_id'] ?? '');
        if (!array_key_exists($id, $templates)) {
            $templates[$id] = null;
            if (preg_match('/^[1-9][0-9]*$/D', $id) && \wp_cliniko_get_secret_option('wp_cliniko_api_key') !== '') {
                try { $templates[$id] = PatientFormTemplate::find($id, cliniko_client(false), true); }
                catch (\Throwable $error) { /* Keep saved IDs visible during an outage. */ }
            }
        }
        $template = $templates[$id];
        if (!$template || $template->isArchived()) {
            echo '<p class="cliniko-api-picker-warning">Form questions are unavailable. The saved form ID is retained; check the Cliniko connection or whether the template was archived.</p>';
            return;
        }
        $structure = BookingFormStructure::describe($template, is_array($config['input_rules'] ?? null) ? $config['input_rules'] : []);
        if (is_wp_error($structure)) {
            echo '<p class="cliniko-api-picker-warning">' . esc_html($structure->get_error_message()) . '</p>';
            return;
        }
        if ($structure['sections'] === []) echo '<p>This form currently has no questions.</p>';
        foreach ($structure['sections'] as $section) {
            echo '<details class="cliniko-api-flow-section"><summary>' . esc_html((string) ($section['name'] ?: 'Questions')) . '<span>' . count($section['questions']) . ' questions</span></summary><div>';
            if (!empty($section['description'])) echo '<p>' . esc_html($section['description']) . '</p>';
            echo '<ul class="cliniko-api-flow-questions">';
            foreach ($section['questions'] as $question) {
                $types = ['text' => 'Short text', 'textarea' => 'Long text', 'paragraph' => 'Long text', 'email' => 'Email address', 'date' => 'Date', 'radiobuttons' => 'Choose one answer', 'checkboxes' => 'Choose one or more answers'];
                echo '<li><strong>' . esc_html($question['name']) . '</strong><span class="cliniko-api-flow-question-meta">' . ($question['required'] ? 'Required' : 'Optional') . ' · ' . esc_html($types[$question['type']] ?? $question['type']) . '</span>';
                if ($question['options'] !== []) echo '<p>Choices: ' . esc_html(implode(', ', $question['options'])) . ($question['other_allowed'] ? ' · Other answer allowed' : '') . '</p>';
                $checks = $question['validation'];
                if (!empty($checks['enum'])) echo '<p>Allowed answers: ' . esc_html(implode(', ', $checks['enum'])) . '</p>';
                if (!empty($checks['format'])) echo '<p>Answer format: ' . esc_html($checks['format'] === 'date' ? 'Date (YYYY-MM-DD)' : 'Email address') . '</p>';
                echo '<p>Maximum characters: ' . esc_html((string) $checks['max_length']) . '</p>';
                if (($checks['pattern'] ?? '') === '^[0-9]*$') echo '<p>Numbers only</p>';
                elseif (!empty($checks['pattern'])) echo '<details class="cliniko-api-flow-pattern"><summary>Fixed-format answer check</summary><p>Developer validation rule: <code>' . esc_html($checks['pattern']) . '</code></p></details>';
                echo '</li>';
            }
            echo '</ul></div></details>';
        }
    }

    private static function startForm(string $slug, string $operation, string $attributes = ''): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" ' . $attributes . '>';
        wp_nonce_field('cliniko_api_booking_flow');
        echo '<input type="hidden" name="action" value="cliniko_api_booking_flow"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        if ($slug !== '') echo '<input type="hidden" name="flow_slug" value="' . esc_attr($slug) . '">';
    }

    private static function choice(string $field, string $label, string $saved, array $items, callable $help, string $explanation): void
    {
        $id = 'api-booking-' . $field;
        echo '<tr><th><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        $help($label, $explanation, $id);
        echo '</th><td><select id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" required><option value="">Choose ' . esc_html(strtolower($label)) . '</option>';
        $found = false;
        foreach ($items as $item) {
            $found = $found || $saved === $item['id'];
            echo '<option value="' . esc_attr($item['id']) . '" ' . selected($saved, $item['id'], false) . '>' . esc_html($item['name']) . ' (' . esc_html($item['id']) . ')</option>';
        }
        if ($saved !== '' && !$found) echo '<option value="' . esc_attr($saved) . '" selected>Previously selected (' . esc_html($saved) . ') — check availability</option>';
        echo '</select><p class="description">Publish this resource separately in Publish API before enabling the flow.</p></td></tr>';
    }

    private static function answerRules(array $config, callable $help): void
    {
        echo '<h4>Form questions &amp; answer checks';
        $help('Answer checks', 'You can shorten text answers, allow only numbers, require a date, or restrict answers to a list. Leave the defaults to follow the Cliniko form. The app receives these checks in validation and the server enforces them again. Dates use YYYY-MM-DD. Changing the form resets its old question checks.', 'api-booking-rules');
        echo '</h4><p>Required answers and choice lists come from Cliniko. Optional checks below reuse the website booking rules. These are data checks, not instructions for how your app should look.</p>';
        $template = null;
        try {
            $template = PatientFormTemplate::find((string) $config['patient_form_template_id'], cliniko_client(false), true);
            $structure = $template && !$template->isArchived() ? BookingFormStructure::describe($template, $config['input_rules'] ?? []) : null;
        } catch (\Throwable $error) { $structure = null; }
        if (!$structure || is_wp_error($structure)) {
            echo '<p class="cliniko-api-picker-warning">' . esc_html(is_wp_error($structure) ? $structure->get_error_message() : 'Questions could not be loaded. Existing checks will be kept when saving the same form. Changing the form resets them; reload to review its questions.') . '</p>';
            return;
        }
        $rules = PatientBookingConfiguration::questionRules($template, $config['input_rules'] ?? []);
        echo '<input type="hidden" name="rules_template_id" value="' . esc_attr((string) $config['patient_form_template_id']) . '">';
        foreach ($structure['sections'] as $si => $section) {
            echo '<h4>' . esc_html((string) ($section['name'] ?? 'Questions')) . '</h4>';
            foreach ($section['questions'] as $qi => $question) {
                $key = 'question_' . $si . '_' . $qi;
                echo '<details class="cliniko-api-developer-notes"><summary>' . esc_html($question['name']) . ($question['required'] ? ' · Required' : ' · Optional') . '</summary><div class="cliniko-api-developer-body">';
                if (in_array($question['type'], ['checkboxes', 'radiobuttons'], true)) {
                    echo '<p>Choices follow the Cliniko form: ' . esc_html(implode(', ', $question['options'])) . '. ' . ($question['other_allowed'] ? 'An other answer is allowed.' : '') . '</p></div></details>';
                    continue;
                }
                $rule = $rules[$key] ?? [];
                $prefix = 'input_rules[' . $key . ']';
                $caps = PatientBookingConfiguration::ruleCapabilities($question['type']);
                echo '<p><label for="api-rule-' . esc_attr($key) . '-max">Maximum characters (0 = default limit)</label><br><input id="api-rule-' . esc_attr($key) . '-max" type="number" min="0" max="5000" name="' . esc_attr($prefix . '[max_length]') . '" value="' . esc_attr((string) ($rule['max_length'] ?? 0)) . '"></p>';
                echo '<p><label for="api-rule-' . esc_attr($key) . '-type">Accepted answer</label><br><select id="api-rule-' . esc_attr($key) . '-type" name="' . esc_attr($prefix . '[input_type]') . '">';
                foreach (['default' => 'Use the form’s answer type', 'text' => 'Text', 'date' => 'Date (YYYY-MM-DD)', 'select' => 'Only the answers listed below'] as $value => $label) {
                    if ($value !== 'default' && !$caps[$value]) continue;
                    echo '<option value="' . esc_attr($value) . '" ' . selected($rule['input_type'] ?? 'default', $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select></p>';
                if ($caps['select']) echo '<p><label for="api-rule-' . esc_attr($key) . '-options">Allowed answers (one per line; used for “Only the answers listed below”)</label><br><textarea id="api-rule-' . esc_attr($key) . '-options" class="large-text" rows="3" name="' . esc_attr($prefix . '[select_options]') . '">' . esc_textarea(implode("\n", $rule['select_options'] ?? [])) . '</textarea></p>';
                if ($caps['format']) {
                    echo '<p><label for="api-rule-' . esc_attr($key) . '-format">Additional text check</label><br><select id="api-rule-' . esc_attr($key) . '-format" name="' . esc_attr($prefix . '[input_format]') . '">';
                    foreach (['none' => 'None', 'numbers' => 'Numbers only', 'pattern' => 'Fixed-format reference'] as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($rule['input_format'] ?? 'none', $value, false) . '>' . esc_html($label) . '</option>';
                    echo '</select></p><details><summary>Fixed-format reference settings</summary><p>Used only when Fixed-format reference is selected. # means a digit, A a letter and * a letter or digit. Example: AA-## accepts AB-12.</p>';
                    foreach (['mask_pattern' => 'Reference format', 'mask_prefix' => 'Required beginning', 'mask_suffix' => 'Required ending'] as $field => $label) echo '<p><label for="api-rule-' . esc_attr($key . '-' . $field) . '">' . esc_html($label) . '</label><br><input id="api-rule-' . esc_attr($key . '-' . $field) . '" class="regular-text" name="' . esc_attr($prefix . '[' . $field . ']') . '" value="' . esc_attr((string) ($rule[$field] ?? '')) . '"></p>';
                    echo '</details>';
                }
                echo '</div></details>';
            }
        }
    }
}
