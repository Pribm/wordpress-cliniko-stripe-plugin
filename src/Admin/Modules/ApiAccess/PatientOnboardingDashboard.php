<?php

namespace App\Admin\Modules\ApiAccess;

use App\Admin\Modules\AccountBuilders\Forms\PatientOnboarding;
use App\Service\Api\PatientOnboardingApi;

if (!defined('ABSPATH')) exit;

final class PatientOnboardingDashboard
{
    public static function render(): void
    {
        $all = PatientOnboardingApi::all();
        $current = $all['patient-profile'] ?? (reset($all) ?: []);
        if (!is_array($current)) $current = [];
        $source = (string) ($current['source'] ?? 'api');
        $available = PatientOnboarding::apiAvailableFieldDefinitions();
        $modules = PatientOnboarding::apiSourceModules();
        $shortcodeSchemes = [];
        foreach ($modules as $moduleId => $module) {
            $shortcodeSchemes[(string) $moduleId] = PatientOnboarding::apiSourceSteps($module);
        }
        $steps = [];
        if ($source === 'shortcode') {
            $shortcodeModule = $modules[(string) ($current['shortcode_id'] ?? '')] ?? null;
            if (is_array($shortcodeModule)) $steps = PatientOnboarding::apiSourceSteps($shortcodeModule);
        } else {
            $storedSteps = (array) ($current['steps'] ?? []);
            $steps = PatientOnboarding::apiNormaliseSteps($storedSteps);
            if ($steps === []) {
                $legacyFields = [];
                foreach ($storedSteps as $key => $field) if (is_array($field)) $legacyFields[$key] = $field;
                if ($legacyFields !== []) $steps = PatientOnboarding::apiNormaliseSteps([['id' => 'api-profile', 'title' => 'Patient details', 'fields' => array_values($legacyFields)]]);
            }
        }
        if ($steps === []) $steps = [['id' => 'api-profile', 'title' => 'Patient details', 'subtitle' => 'Complete the missing details in your patient profile.', 'fields' => []]];
        $style = dirname(__DIR__, 3) . '/assets/patient-onboarding-builder.css';
        $script = dirname(__DIR__, 3) . '/assets/patient-onboarding-builder.js';
        if (function_exists('wp_enqueue_style')) wp_enqueue_style('cliniko-patient-onboarding-builder', plugins_url('../../assets/patient-onboarding-builder.css', __FILE__), [], file_exists($style) ? (string) filemtime($style) : null);
        if (function_exists('wp_enqueue_script')) wp_enqueue_script('cliniko-patient-onboarding-builder', plugins_url('../../assets/patient-onboarding-builder.js', __FILE__), [], file_exists($script) ? (string) filemtime($script) : null, true);
        echo '<div class="cliniko-api-card"><h3>What this does</h3><p>After a patient signs in, the app can ask this API which profile details are missing. The app then sends those details through a short-lived onboarding session. Existing Cliniko values count as complete, so a patient does not repeat onboarding unless you change the required fields or rules.</p><p>This is profile completion, not a second login. The patient still sends their normal Bearer access token. The onboarding token is temporary permission for one completion submission and is never a permanent credential.</p></div>'; 
        echo '<div class="cliniko-api-card"><h3>Choose the onboarding source</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cliniko-form-builder" data-cliniko-onboarding-builder><input type="hidden" name="action" value="cliniko_api_patient_onboarding">';
        wp_nonce_field('cliniko_api_patient_onboarding');
        echo '<table class="form-table"><tr><th><label for="api-onboarding-name">Internal name</label></th><td><input class="regular-text" id="api-onboarding-name" name="name" value="' . esc_attr((string) ($current['name'] ?? 'Patient profile setup')) . '"><p class="description">Only staff see this name.</p></td></tr><tr><th><label for="api-onboarding-slug">API identifier</label></th><td><input class="regular-text" id="api-onboarding-slug" name="slug" value="' . esc_attr((string) (($current['slug'] ?? '') ?: 'patient-profile')) . '" pattern="[a-z][a-z0-9-]{1,63}" required><p class="description">This becomes part of the route: <code>/patient-onboarding/{identifier}</code>.</p></td></tr><tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($current['enabled']), true, false) . '> Publish this onboarding schema for apps</label></td></tr><tr><th>Booking rule</th><td><label><input type="checkbox" name="require_before_booking" value="1" ' . checked(!empty($current['require_before_booking']), true, false) . '> Require completion before a patient can create a booking</label><p class="description">If enabled, booking creation returns <code>onboarding_required</code> until the profile is complete.</p></td></tr><tr><th>Use an existing website onboarding</th><td><label><input type="radio" name="source" value="shortcode" data-onboarding-source="shortcode" ' . checked(($current['source'] ?? '') === 'shortcode', true, false) . '> Reuse a shortcode onboarding scheme</label><select name="shortcode_id" class="regular-text"><option value="">Select a scheme</option>';
        foreach ($modules as $id => $module) echo '<option value="' . esc_attr((string) $id) . '" ' . selected((string) ($current['shortcode_id'] ?? ''), (string) $id, false) . '>' . esc_html((string) ($module['name'] ?? $id)) . '</option>';
        echo '</select> <button type="button" class="button" data-api-load-shortcode>Load into API editor</button><script type="application/json" data-api-shortcode-schemes>' . wp_json_encode($shortcodeSchemes) . '</script><p class="description">Use a shortcode onboarding as a starting point. Loading creates an independent API-only copy; later shortcode edits will not change it.</p></td></tr><tr><th>API-only profile scheme</th><td><label><input type="radio" name="source" value="api" data-onboarding-source="api" ' . checked(($current['source'] ?? 'api') === 'api', true, false) . '> Select fields below</label></td></tr></table>';
        echo '<div class="cliniko-api-card cliniko-api-onboarding-builder" data-api-onboarding-editor data-api-onboarding-source="' . esc_attr($source) . '"><h3>API fields</h3><p>Select only the profile details the app should collect. Email is shown by the patient profile but changes must use the separate email-confirmation process.</p><p class="description">API-only onboarding now uses the same section, field, conditional-choice, pattern and validation structure as the shortcode onboarding builder. The API receives the structure and rules only—not website presentation settings.</p>';
        echo '<textarea name="steps_config" data-onboarding-steps-config hidden>' . esc_textarea((string) wp_json_encode($steps)) . '</textarea><script type="application/json" data-onboarding-available-fields>' . wp_json_encode(array_map(static function (array $definition, string $key): array { return ['key' => $key, 'label' => (string) ($definition['label'] ?? $key), 'type' => (string) ($definition['type'] ?? 'text'), 'cliniko_field_type' => (string) ($definition['cliniko_field_type'] ?? ''), 'options' => is_array($definition['options'] ?? null) ? array_values($definition['options']) : [], 'custom' => !empty($definition['custom'])]; }, $available, array_keys($available))) . '</script><div class="cliniko-onboarding-builder-steps" data-onboarding-steps></div><p><button type="button" class="button" data-onboarding-add-step>Add section</button></p></div><p><button class="button button-primary" type="submit">Save patient onboarding</button></p></form></div>';
        echo '<div class="cliniko-api-card"><h3>How the app uses it</h3><ol><li>Sign in and keep the patient Bearer access token.</li><li><code>GET /patient-onboarding/' . esc_html((string) (($current['slug'] ?? '') ?: 'patient-profile')) . '</code> to read fields, current values, missing fields and the revision.</li><li><code>POST .../session</code> to receive a short-lived <code>X-ES-Onboarding-Token</code> when details are missing.</li><li>Send <code>POST .../complete</code> with the Bearer token, onboarding token, revision and <code>{"data": {"field": "value"}}</code>.</li><li>Reload the schema. When <code>complete</code> is true, normal authenticated patient routes can be used. If booking enforcement is enabled, booking creation is blocked until this point.</li></ol><p>The server validates every field, writes only to the linked Cliniko patient, and rechecks the profile. Never accept a patient ID or a client-side completed flag as proof.</p></div>';
    }
}
