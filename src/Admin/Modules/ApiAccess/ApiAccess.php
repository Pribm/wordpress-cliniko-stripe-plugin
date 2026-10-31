<?php

namespace App\Admin\Modules\ApiAccess;

use App\Routes\ManagedApiRoutes;
use App\Service\Api\ApiAudit;
use App\Service\Api\ApiClients;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;
use App\Service\Api\SignInProviders;
use App\Service\Api\PatientSessions;
use App\Service\Api\PatientAuthentication;
use App\Service\Api\PatientRegistration;
use App\Service\Api\BookingFlows;
use App\Service\Api\PatientOnboardingApi;

if (!defined('ABSPATH')) exit;

final class ApiAccess
{
    public const PAGE = 'wp-cliniko-api-access';
    private static array $helpTopics = [];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [ApiAudit::class, 'install']);
        add_action('admin_init', [PatientSessions::class, 'install']);
        add_action('admin_init', [self::class, 'scheduleCleanup']);
        add_action('cliniko_api_audit_cleanup', [ApiAudit::class, 'cleanup']);
        add_action('cliniko_api_audit_cleanup', [PatientSessions::class, 'cleanup']);
        add_action('admin_post_cliniko_api_settings', [self::class, 'save']);
        add_action('admin_post_cliniko_api_booking_flow', [self::class, 'saveBookingFlow']);
        add_action('admin_post_cliniko_api_patient_onboarding', [self::class, 'savePatientOnboarding']);
        add_action('admin_post_cliniko_api_patient_login', [self::class, 'savePatientLogin']);
        add_action('admin_post_cliniko_api_app_login', [self::class, 'saveAppLogin']);
        add_action('admin_post_cliniko_api_session_revoke', [self::class, 'revokeSession']);
        add_action('admin_post_cliniko_api_client', [self::class, 'client']);
        add_action('admin_post_cliniko_api_mapping', [self::class, 'mapping']);
        add_action('admin_post_cliniko_api_openapi', [self::class, 'download']);
        add_action('admin_post_cliniko_api_test_provider', [self::class, 'testProvider']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        add_submenu_page('wp-cliniko-stripe-settings', 'API Access', 'API Access', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function scheduleCleanup(): void
    {
        if (current_user_can('manage_options') && !wp_next_scheduled('cliniko_api_audit_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'cliniko_api_audit_cleanup');
    }

    public static function assets(): void
    {
        if (($_GET['page'] ?? '') !== self::PAGE) return;
        wp_enqueue_style('cliniko-admin-workspace', plugins_url('../../assets/admin-workspace.css', __FILE__), [], (string) filemtime(dirname(__DIR__, 2) . '/assets/admin-workspace.css'));
        wp_enqueue_style('cliniko-api-admin', plugins_url('assets/api-access.css', __FILE__), ['cliniko-admin-workspace'], (string) filemtime(__DIR__ . '/assets/api-access.css'));
        wp_enqueue_script('cliniko-api-admin', plugins_url('assets/api-access.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/api-access.js'), true);
    }

    private static function authorize(string $action): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to manage API Access.', '', ['response' => 403]);
        check_admin_referer($action);
    }

    private static function url(string $tab = 'getting-started', string $section = ''): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => $tab] + ($section !== '' ? ['section' => $section] : []), admin_url('admin.php'));
    }

    private static function finish(string $message, string $tab = 'settings', bool $error = false, string $section = ''): void
    {
        set_transient('cliniko_api_notice_' . get_current_user_id(), ['message' => $message, 'error' => $error], 60);
        wp_safe_redirect(self::url($tab, $section));
        exit;
    }

    public static function save(): void
    {
        self::authorize('cliniko_api_settings');
        $input = wp_unslash($_POST);
        $result = ApiSettings::validateGeneral($input, rest_get_server()->get_namespaces());
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'settings', true);
        update_option(ApiSettings::OPTION, $result, false);
        ApiAudit::record('settings_saved', 'configuration', '', get_current_user_id());
        self::finish('API settings saved. Patient-login settings are unchanged.');
    }

    public static function saveBookingFlow(): void
    {
        self::authorize('cliniko_api_booking_flow');
        $input = wp_unslash($_POST);
        $slug = trim((string) ($input['flow_slug'] ?? ''));
        if (($input['operation'] ?? '') === 'remove') {
            BookingFlows::remove($slug);
            ApiAudit::record('booking_flow_removed', 'configuration', '', get_current_user_id());
            self::finish('API flow removed. Website booking forms are unchanged.', 'booking-flows');
        }
        $operation = (string) ($input['operation'] ?? '');
        if (!in_array($operation, ['create', 'save', 'legacy-save', 'make-api-only', 'disable'], true)) self::finish('Choose a supported booking-flow action.', 'booking-flows', true);
        if ($operation === 'create' && isset(BookingFlows::all()[$slug])) self::finish('That identifier already exists. Edit its existing flow instead.', 'booking-flows', true);
        if ($operation === 'disable') $result = BookingFlows::disable($slug);
        elseif ($operation === 'make-api-only') $result = BookingFlows::makeApiOnly($slug);
        elseif ($operation === 'legacy-save') {
            $existing = BookingFlows::all()[$slug] ?? null;
            if (!$existing || $existing['flow_type'] !== 'web') self::finish('Choose an existing website-linked flow.', 'booking-flows', true);
            $result = BookingFlows::save($slug, (string) ($existing['configuration_id'] ?? ''), !empty($input['flow_enabled']));
        } else $result = BookingFlows::saveApi($slug, array_intersect_key($input, array_flip(['name', 'appointment_type_id', 'patient_form_template_id', 'gateway', 'scheduling_mode', 'input_rules', 'rules_template_id'])), !empty($input['flow_enabled']));
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'booking-flows', true);
        ApiAudit::record('booking_flow_saved', 'configuration', '', get_current_user_id());
        if ($operation === 'disable') self::finish('API flow disabled. Existing booking attempts keep their protected payment and progress routes.', 'booking-flows');
        if ($operation === 'legacy-save') self::finish('Legacy website-linked publication saved. Its website form is unchanged.', 'booking-flows');
        if ($operation === 'make-api-only') self::finish('This flow is now API-only at the same address. The website form is unchanged. Apps must reload its new structure before submitting.', 'booking-flows', false, $slug);
        self::finish('Booking flow saved. Website forms are unchanged. If you changed the form template, its previous answer rules were reset; review the new questions below.', 'booking-flows', false, $slug);
    }

    public static function savePatientOnboarding(): void
    {
        self::authorize('cliniko_api_patient_onboarding');
        $input = wp_unslash($_POST);
        $fields = [];
        foreach ((array) ($input['fields'] ?? []) as $key => $field) {
            if (!is_array($field) || empty($field['selected'])) continue;
            $fields[sanitize_key((string) $key)] = $field;
        }
        $steps = json_decode((string) ($input['steps_config'] ?? ''), true);
        if (!is_array($steps)) $steps = [];
        $result = PatientOnboardingApi::save((string) ($input['slug'] ?? 'patient-profile'), [
            'name' => (string) ($input['name'] ?? ''),
            'enabled' => !empty($input['enabled']),
            'source' => (string) ($input['source'] ?? 'api'),
            'shortcode_id' => (string) ($input['shortcode_id'] ?? ''),
            'require_before_booking' => !empty($input['require_before_booking']),
            'fields' => $fields,
            'steps' => $steps,
        ]);
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'patient-onboarding', true);
        ApiAudit::record('patient_onboarding_saved', 'configuration', '', get_current_user_id());
        if (!in_array('onboarding', ApiSettings::get()['groups'], true)) {
            set_transient('cliniko_api_onboarding_group_notice_' . get_current_user_id(), true, 60);
        }
        self::finish('Patient API onboarding saved. Existing shortcode onboarding forms were not changed.', 'patient-onboarding');
    }

    public static function savePatientLogin(): void
    {
        self::authorize('cliniko_api_patient_login');
        $input = wp_unslash($_POST);
        $result = ApiSettings::validatePatientLogin($input);
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'patient-login', true, 'oauth');
        if (!empty($input['introspection_secret'])) {
            $encrypted = \wp_cliniko_secret_option_encrypt((string) $input['introspection_secret']);
            if (!\wp_cliniko_secret_option_is_encrypted($encrypted)) self::finish('Secret encryption is unavailable. Configure OpenSSL before saving OAuth credentials.', 'patient-login', true, 'oauth');
            update_option(ApiSettings::SECRET, $encrypted, false);
        }
        update_option(ApiSettings::OPTION, $result, false);
        ApiAudit::record('patient_login_settings_saved', 'configuration', '', get_current_user_id());
        self::finish('OAuth settings saved. API publishing and WordPress/social login settings are unchanged.', 'patient-login', false, 'oauth');
    }

    public static function saveAppLogin(): void
    {
        self::authorize('cliniko_api_app_login');
        $input = wp_unslash($_POST);
        $section = sanitize_key((string) ($input['section'] ?? 'wordpress'));
        $result = ApiSettings::validateAppLogin($input);
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'patient-login', true, $section);
        if ($result['password_login_enabled'] || $result['google_login_enabled'] || $result['apple_login_enabled']) {
            PatientSessions::install();
            $key = PatientSessions::ensureKey();
            if (is_wp_error($key)) self::finish($key->get_error_message(), 'patient-login', true, $section);
            if (!PatientSessions::ready()) self::finish('Patient session storage is not ready. Check database permissions before enabling app login.', 'patient-login', true, $section);
        }
        update_option(ApiSettings::OPTION, $result, false);
        ApiAudit::record('app_login_settings_saved', 'authentication', '', get_current_user_id());
        self::finish('Login settings saved. Other authentication methods and API publishing are unchanged.', 'patient-login', false, $section);
    }

    public static function revokeSession(): void
    {
        self::authorize('cliniko_api_session_revoke');
        $id = (string) wp_unslash($_POST['session_id'] ?? '');
        if (!PatientSessions::revoke($id)) self::finish('Could not revoke this session. Check database access.', 'patient-login', true, 'sessions');
        ApiAudit::record('patient_session_revoked', 'authentication', '', get_current_user_id());
        self::finish('The app session has been revoked. Its access and refresh tokens are no longer accepted.', 'patient-login', false, 'sessions');
    }

    public static function client(): void
    {
        self::authorize('cliniko_api_client');
        $input = wp_unslash($_POST);
        $operation = sanitize_key((string) ($input['operation'] ?? 'create'));
        $id = sanitize_key((string) ($input['client_id'] ?? ''));
        if ($operation === 'revoke') {
            ApiClients::revoke($id);
            ApiAudit::record('client_revoked', 'client', $id, get_current_user_id());
            self::finish('Application revoked.', 'clients');
        }
        if ($operation === 'rotate') {
            $result = ApiClients::rotate($id);
            if (is_wp_error($result)) self::finish($result->get_error_message(), 'clients', true);
            ApiAudit::record('client_rotated', 'client', $id, get_current_user_id());
            self::showSecret($result, 'Credential rotated. The old key is immediately invalid.');
        }
        if ($operation === 'update') {
            $expiry = strtotime((string) ($input['expires_at'] ?? '') . ' 23:59:59 UTC');
            $result = ApiClients::update($id, (array) ($input['scopes'] ?? []), (int) $expiry, $input);
            if (is_wp_error($result)) self::finish($result->get_error_message(), 'clients', true);
            ApiAudit::record('client_scopes_updated', 'client', $id, get_current_user_id());
            self::finish('Application scopes and expiry updated.', 'clients');
        }
        $expires = strtotime((string) ($input['expires_at'] ?? '') . ' 23:59:59 UTC');
        $result = ApiClients::create((string) ($input['name'] ?? ''), (string) ($input['type'] ?? ''), trim((string) ($input['external_id'] ?? '')), (array) ($input['scopes'] ?? []), (int) $expires, $input);
        if (is_wp_error($result)) self::finish($result->get_error_message(), 'clients', true);
        ApiAudit::record('client_created', 'client', $result['client']['id'], get_current_user_id());
        if ($result['secret'] !== '') self::showSecret($result['secret'], 'Server application created. Save this credential now; it will never be shown again.');
        self::finish($result['client']['type'] === 'patient' ? 'Patient app registered. Copy its App ID into your mobile/browser app and choose a login method under Patient login.' : 'OAuth application registered. Use its provider client ID and granted scopes.', 'clients');
    }

    private static function showSecret(string $secret, string $message): void
    {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        wp_die('<p>' . esc_html($message) . '</p><pre style="white-space:pre-wrap;word-break:break-all">' . esc_html($secret) . '</pre><p>Use <code>Authorization: Bearer &lt;credential&gt;</code> only from your trusted server.</p><p><a href="' . esc_url(self::url('clients')) . '">Return to API Access</a></p>', 'API credential', ['response' => 200]);
    }

    public static function mapping(): void
    {
        self::authorize('cliniko_api_mapping');
        $input = wp_unslash($_POST);
        $issuer = trim((string) ($input['issuer'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $id = absint($input['user_id'] ?? 0);
        if (sanitize_key((string) ($input['operation'] ?? '')) !== 'remove') {
            $issuers = array_column(PatientAuthentication::socialProviders(), 'issuer');
            if (ApiSettings::get()['issuer'] !== '') $issuers[] = ApiSettings::get()['issuer'];
            if (!in_array($issuer, $issuers, true) || $subject === '' || strlen($subject) > 255 || !get_userdata($id)) self::finish('Choose a configured sign-in service, its exact subject ID and an existing WordPress patient account.', 'mappings', true);
            if (empty($input['confirm_patient_link'])) self::finish('Confirm that the sign-in identity and selected patient account belong to the same person.', 'mappings', true);
            $allowed = PatientAuthentication::patientAllowed($id);
            if ($allowed !== true) self::finish(is_wp_error($allowed) ? $allowed->get_error_message() : 'This account is not an active verified patient.', 'mappings', true);
        } else $id = 0;
        $saved = ApiClients::map($issuer, $subject, $id);
        if (is_wp_error($saved)) self::finish($saved->get_error_message(), 'mappings', true);
        ApiAudit::record('subject_mapping_changed', 'mapping', '', get_current_user_id());
        self::finish('Patient sign-in link updated.', 'mappings');
    }

    public static function download(): void
    {
        self::authorize('cliniko_api_openapi');
        rest_get_server();
        $document = EndpointCatalog::openApi();
        if (in_array(ApiSettings::namespace(), ManagedApiRoutes::$collisions, true)) $document['paths'] = (object) [];
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cliniko-api-openapi.json"');
        echo wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function testProvider(): void
    {
        self::authorize('cliniko_api_test_provider');
        $result = PatientLoginSetup::checkSavedConnection();
        ApiAudit::record('provider_test', 'authentication', '', get_current_user_id(), is_wp_error($result) ? 503 : 200);
        self::finish(is_wp_error($result) ? PatientLoginSetup::failureMessage($result->get_error_code()) : 'The saved website connection passed its check. This is not a patient-login test: next approve the app, link a test patient and try their actual app login.', 'patient-login', is_wp_error($result), 'oauth');
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', '', ['response' => 403]);
        self::$helpTopics = [];
        rest_get_server(); // Register once so collision/active status reflects this site's REST server.
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'getting-started'));
        $tabs = ['getting-started' => 'Overview', 'settings' => 'Publish API', 'booking-flows' => 'Booking flows', 'patient-onboarding' => 'Patient onboarding', 'patient-login' => 'Patient login', 'clients' => 'Applications', 'mappings' => 'Patient links', 'flows' => 'Requests & responses', 'documentation' => 'Endpoint documentation', 'audit' => 'Activity', 'tutorials' => 'Tutorials', 'guide' => 'Dashboard guide'];
        if (!isset($tabs[$tab])) $tab = 'getting-started';
        $settings = ApiSettings::get();
        echo '<div class="wrap cliniko-admin-ui cliniko-api-admin"><header class="cliniko-api-topbar"><div><p class="cliniko-api-eyebrow">Developer platform</p><h1>API Access</h1><p>Your website. Your patient accounts. Your connected apps.</p></div><span class="cliniko-api-badge ' . ($settings['enabled'] ? 'is-active' : '') . '">' . ($settings['enabled'] ? 'API enabled' : 'API is off') . '</span></header><div class="cliniko-api-workspace"><aside class="cliniko-api-sidebar"><p class="cliniko-api-nav-label">Workspace</p><nav class="nav-tab-wrapper" aria-label="API Access navigation">';
        $icons = ['getting-started' => 'dashboard', 'settings' => 'admin-settings', 'booking-flows' => 'calendar-alt', 'patient-onboarding' => 'forms', 'patient-login' => 'lock', 'clients' => 'smartphone', 'mappings' => 'admin-users', 'flows' => 'randomize', 'documentation' => 'media-document', 'audit' => 'list-view', 'tutorials' => 'welcome-learn-more', 'guide' => 'editor-help'];
        foreach ($tabs as $key => $label) echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" ' . ($key === $tab ? 'aria-current="page"' : '') . ' href="' . esc_url(self::url($key)) . '"><span aria-hidden="true" class="dashicons dashicons-' . esc_attr($icons[$key]) . '"></span><span>' . esc_html($label) . '</span></a>';
        echo '</nav><div class="cliniko-api-sidebar-note"><span class="dashicons dashicons-shield" aria-hidden="true"></span><p>Publishing a route never removes its patient permission checks.</p></div></aside><main class="cliniko-api-content"><header class="cliniko-api-page-heading"><p class="cliniko-api-eyebrow">API Access / ' . esc_html($tabs[$tab]) . '</p><h2>' . esc_html($tabs[$tab]) . '</h2></header>';
        $notice = get_transient('cliniko_api_notice_' . get_current_user_id());
        if (is_array($notice)) {
            echo '<div class="notice ' . (!empty($notice['error']) ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>';
            delete_transient('cliniko_api_notice_' . get_current_user_id());
        }
        if (get_transient('cliniko_api_onboarding_group_notice_' . get_current_user_id())) {
            echo '<div class="notice notice-warning"><p><strong>Patient onboarding is saved but not published yet.</strong> Enable the <em>Patient profile onboarding</em> route group so the onboarding endpoints are registered. <a href="' . esc_url(self::url('settings') . '#cliniko-api-route-groups') . '">Open Publish API route groups</a>.</p></div>';
            delete_transient('cliniko_api_onboarding_group_notice_' . get_current_user_id());
        }
        if (ManagedApiRoutes::$collisions !== []) echo '<div class="notice notice-error"><p>Namespace collision: ' . esc_html(implode(', ', ManagedApiRoutes::$collisions)) . '. These API namespaces were not registered. Choose another slug.</p></div>';
        if ($tab === 'getting-started') DashboardExperience::overview();
        elseif ($tab === 'settings') self::settings();
        elseif ($tab === 'booking-flows') self::bookingFlows();
        elseif ($tab === 'patient-onboarding') PatientOnboardingDashboard::render();
        elseif ($tab === 'patient-login') self::patientLogin();
        elseif ($tab === 'clients') self::clients();
        elseif ($tab === 'mappings') self::mappings();
        elseif ($tab === 'flows') DashboardExperience::flows();
        elseif ($tab === 'tutorials') self::tutorials();
        elseif ($tab === 'guide') self::guide();
        elseif ($tab === 'documentation') self::documentation();
        else self::audit();
        echo '<p id="cliniko-api-copy-status" class="cliniko-api-copy-status" role="status" aria-live="polite"></p></main></div>';
        self::helpModals();
        echo '</div>';
    }

    private static function input(string $name, string $label, $value, string $type = 'text', string $help = '', ?string $providerHint = null): void
    {
        echo '<tr><th><label for="api-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
        self::info($label, self::fieldHelp()[$name] ?? $help, $name);
        echo '</th><td><input class="regular-text" id="api-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr((string) $value) . '" autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '">';
        if ($help !== '') echo '<p class="description">' . esc_html($help) . '</p>';
        if ($providerHint !== null) echo '<p class="description cliniko-api-provider-hint" data-login-provider-hint="' . esc_attr($name) . '">' . esc_html($providerHint) . '</p>';
        if ($name === 'slug') echo '<p class="description">URL preview: <code id="cliniko-api-url-preview" data-rest-root="' . esc_attr(rest_url()) . '">' . esc_html(rest_url(ApiSettings::namespace())) . '</code></p>';
        echo '</td></tr>';
    }

    private static function info(string $label, string $explanation, string $key = ''): void
    {
        if ($explanation === '') return;
        $key = $key !== '' ? $key : sanitize_key(str_replace(' ', '-', $label));
        self::$helpTopics[$key] = ['title' => $label, 'explanation' => $explanation];
        echo '<button type="button" class="cliniko-api-help-trigger" data-api-help="' . esc_attr($key) . '" aria-haspopup="dialog" aria-controls="cliniko-api-help-dialog" aria-label="' . esc_attr('Help: ' . $label) . '" title="' . esc_attr('Help: ' . $label) . '"><span aria-hidden="true">?</span></button>';
    }

    private static function helpModals(): void
    {
        if (self::$helpTopics === []) return;
        foreach (self::$helpTopics as $key => $topic) {
            $content = HelpContent::field($key);
            echo '<template id="cliniko-api-help-' . esc_attr($key) . '" data-title="' . esc_attr($topic['title']) . '" data-tutorial-url="' . esc_url(self::url('tutorials') . '#' . $content['tutorial']) . '"><p class="cliniko-api-help-intro">' . esc_html($content['intro'] !== '' ? $content['intro'] : $topic['explanation']) . '</p>';
            HelpContent::renderBlocks($content['sections']);
            HelpContent::renderBlocks([['title' => 'For your developer: technical reference', 'developer' => [['title' => $topic['title'], 'text' => $topic['explanation']]]]]);
            echo '</template>';
        }
        echo '<dialog id="cliniko-api-help-dialog" class="cliniko-api-help-dialog" aria-labelledby="cliniko-api-help-title"><div class="cliniko-api-help-header"><div><p class="cliniko-api-eyebrow">API Access / Field help</p><h2 id="cliniko-api-help-title" tabindex="-1"></h2></div><button type="button" class="cliniko-api-help-close" data-api-help-close aria-label="Close field help"><span aria-hidden="true">&times;</span></button></div><div id="cliniko-api-help-body" class="cliniko-api-help-body"></div><div class="cliniko-api-help-footer"><a id="cliniko-api-help-tutorial" class="button button-primary" href="' . esc_url(self::url('tutorials')) . '">Open step-by-step tutorial</a><button type="button" class="button" data-api-help-close>Done</button></div></dialog>';
        echo '<noscript><p>Enable JavaScript to open field help, or read the <a href="' . esc_url(self::url('guide')) . '">Dashboard guide</a> and <a href="' . esc_url(self::url('tutorials')) . '">Tutorials</a>.</p></noscript>';
    }

    private static function tutorials(): void
    {
        echo '<h2>Integration tutorials</h2><p>Choose what you want your app to do. These guides explain the setup in everyday language, what clinic staff can do and what to ask your developer for. Technical instructions and code examples are kept in a separate developer reference at the end of each guide. Test with a test patient and payment test accounts before using real records or payments.</p><div class="cliniko-api-tutorial-grid">';
        foreach (HelpContent::tutorials() as $key => $tutorial) {
            echo '<a class="cliniko-api-tutorial-card" href="#' . esc_attr($key) . '"><span class="cliniko-api-eyebrow">' . esc_html($tutorial['category']) . ' / ' . (int) $tutorial['steps'] . ' steps</span><h3>' . esc_html($tutorial['title']) . '</h3><p>' . esc_html($tutorial['subtitle']) . '</p><span class="cliniko-api-tutorial-action">Read tutorial <span aria-hidden="true">&rarr;</span></span></a>';
        }
        echo '</div>';
        foreach (HelpContent::tutorials() as $key => $tutorial) {
            if ($key === 'oauth') echo '<p><a class="button" href="' . esc_url(self::url('patient-login', 'oauth')) . '">Open the Patient login guided setup</a></p>';
            echo '<article class="cliniko-api-card cliniko-api-tutorial" id="' . esc_attr($key) . '"><p class="cliniko-api-eyebrow">' . esc_html($tutorial['category']) . '</p><h2>' . esc_html($tutorial['title']) . '</h2><p class="cliniko-api-tutorial-subtitle">' . esc_html($tutorial['subtitle']) . '</p>';
            HelpContent::renderBlocks(HelpContent::tutorial($key));
            echo '<p class="cliniko-api-tutorial-links"><a href="' . esc_url(self::url('settings')) . '">Open settings</a> &middot; <a href="' . esc_url(self::url('clients')) . '">Manage applications</a> &middot; <a href="' . esc_url(self::url('documentation')) . '">Endpoint documentation</a> &middot; <a href="#wpbody-content">Back to tutorial index</a></p></article>';
        }
    }

    private static function bookingFlows(): void
    {
        BookingFlowDashboard::render(self::url('booking-flows'), self::url('documentation'), static function (string $label, string $message, string $key): void {
            self::info($label, $message, $key);
        });
    }

    private static function fieldHelp(): array
    {
        return [
            'slug' => 'The name between /wp-json/ and /v1/ in your API address. For example, cliniko-api produces /wp-json/cliniko-api/v1. Choose it before distributing the app. Changing it later requires migrating clients; the old name remains available for 30 days.',
            'appointment_types' => 'The admin picker loads paginated active Cliniko appointment types and stores only checked IDs in the exposure allowlist. Fetching the list does not publish services. Previously approved IDs missing from the list remain checked for explicit review. Reference data is cached for five minutes per Cliniko credential.',
            'form_templates' => 'The admin picker loads paginated active Cliniko patient-form templates and stores only checked IDs in the exposure allowlist. A booking needs an approved template even if public template browsing is disabled. Browsing exposes blank questions and submission structure, never completed answers. Names and IDs are cached for five minutes per Cliniko credential.',
            'read_limit' => 'Maximum GET requests within a fixed 10-minute window, enforced for each connection IP and each identified application. A shared office or proxy can share a budget. Configure the web server to supply the real REMOTE_ADDR; caller-supplied forwarding headers are ignored.',
            'write_limit' => 'Maximum POST requests within a fixed 10-minute window. POST operations can create drafts, charge payments or finalize appointments, so their limit is lower. Exceeding it returns HTTP 429 and a Retry-After header.',
            'audit_days' => 'How many days to keep the API audit records. Cleanup runs daily through WordPress cron. Choose a period that supports your support and privacy requirements. Only operation/client/user IDs and result metadata are stored by this audit feature.',
            'issuer' => 'The exact identity-provider URL in the access token’s iss claim. Matching is exact, including a trailing slash. This identifies who issued the token; it is not the patient’s email address or your public API address.',
            'audience' => 'The resource identifier that the identity provider places in the token’s aud claim. It identifies this API as the intended recipient. Ask the provider administrator for the exact value; it is not necessarily the mobile app’s client ID.',
            'introspection_url' => 'The provider’s RFC 7662 HTTPS endpoint used to check a bearer access token. This must be a public HTTPS URL. The plugin requires active, token_type, expiry, issuer, audience, subject, client_id and scopes. Login/authorization/token endpoints are different URLs.',
            'introspection_client' => 'The confidential resource-server client ID used by WordPress to authenticate to the introspection endpoint. This is usually different from the mobile app’s public client ID. Obtain it from your provider administrator.',
            'introspection_secret' => 'The private credential for WordPress’s introspection client. It stays on this server and is stored encrypted. Never embed it in a mobile app. Leave the field empty when editing settings to retain the stored secret.',
            'name' => 'A human-readable application name for staff, such as Patient Android App or Partner Booking Backend. This label does not grant permission by itself.',
            'external_id' => 'For OAuth applications, enter the exact client_id registered with your identity provider. The plugin checks this against each introspected token. Leave it empty for server-key applications.',
            'expires_at' => 'Date when this local application approval expires, at 23:59:59 UTC. The provider’s access-token expiry is checked separately. A valid provider token cannot override an expired local application approval.',
            'subject' => 'The exact sub claim identifying a patient at your provider. It is not an email address unless your provider explicitly uses emails as subjects. Combine this value with the issuer to map to one verified WordPress patient.',
            'user_id' => 'The numeric WordPress user ID of the patient account. This is different from the Cliniko patient ID. The account must be active, have an authorized patient role and already have a verified Cliniko link.',
        ];
    }

    private static function scopeLabel(string $scope): string
    {
        return [
            'catalog:read' => 'View approved services and blank questionnaires',
            'availability:read' => 'View clinicians and available appointment times',
            'booking:create' => 'Start bookings, process payment and confirm appointments',
            'booking:read' => 'Check booking progress',
            'patient:read' => 'View the signed-in patient\'s profile',
            'patient:write' => 'Update the signed-in patient\'s profile',
            'appointments:read' => 'View the signed-in patient\'s appointments',
            'account:register' => 'Register new patient accounts (verification and approval still required)',
            'patient:onboarding' => 'Read and complete the signed-in patient profile onboarding',
            'communications:read' => 'Read the signed-in patient communications and unread totals',
            'communications:write' => 'Send messages and update communication read state',
            'attachments:read' => 'View the signed-in patient documents',
            'attachments:write' => 'Upload, describe and archive the signed-in patient documents',
        ][$scope] ?? $scope;
    }

    private static function gettingStarted(): void
    {
        $settings = ApiSettings::get();
        $checks = [
            'API storage initialized' => get_option('wp_cliniko_api_schema') === '2',
            'API master switch enabled' => (bool) $settings['enabled'],
            'At least one feature group selected' => $settings['groups'] !== [],
            'Appointment types explicitly published' => $settings['appointment_types'] !== [],
            'Form templates explicitly published (needed for booking)' => $settings['form_templates'] !== [],
            'Namespace is available' => ManagedApiRoutes::$collisions === [],
            'OAuth configured (needed only for patient endpoints)' => (bool) $settings['oauth_enabled'],
        ];
        echo '<div class="cliniko-api-card"><h2>Start with a simple connection check</h2><p>An API lets another app use selected features of your clinic website. Start by checking the connection, then choose whether the app should show services, make bookings or let patients see their own records. You can make those choices here; your developer builds the app that uses them.</p><p>Connection address to give your developer (Base URL): <code>' . esc_html(rest_url(ApiSettings::namespace())) . '</code></p><p><a class="button button-primary" href="' . esc_url(self::url('settings')) . '">Open API settings</a> <a class="button" href="' . esc_url(self::url('guide')) . '">Read the dashboard guide</a></p></div>';
        echo '<h2>Setup checklist</h2><ul class="cliniko-api-checklist">';
        foreach ($checks as $label => $ready) echo '<li><span class="' . ($ready ? 'is-ready' : 'is-pending') . '">' . ($ready ? 'Ready' : 'Pending') . '</span> ' . esc_html($label) . '</li>';
        echo '</ul><p class="description">You can check the connection before setting up bookings or patient login. Ready means a setting has been filled in, not that the complete app has been tested.</p><ol class="cliniko-api-steps">';
        echo '<li><h3>Choose the API address</h3><p>In Publish API, keep <code>cliniko-api</code> or choose a unique slug. The plugin appends <code>/v1</code>. Confirm the resulting URL before sharing it with developers.</p></li>';
        echo '<li><h3>Enable and test the connection</h3><p>Tick Enable API Access in Settings and save. Then <a href="' . esc_url(rest_url(ApiSettings::namespace() . '/status')) . '" target="_blank" rel="noopener noreferrer">open the connection check</a>. This does not read patient records or make bookings. The result is written for software; ask your developer to confirm it shows the API is available.</p><p>If the check fails, confirm the switch is enabled and you saved your settings, then ask your developer to check the website setup.</p></li>';
        echo '<li><h3>Choose which services the app can show</h3><p>Enable Booking discovery and tick the services you want to offer under Published appointment types. The names come from your Cliniko account; no service numbers need to be entered. Save and have the developer check that the app shows only the approved services and times. Patient login is not needed for this.</p></li>';
        echo '<li><h3>Choose how the app connects</h3><p>A patient app that shows personal records needs a separate sign-in service and a link to each patient\'s existing account. Open <a href="' . esc_url(self::url('patient-login')) . '">Patient login</a> for the guided setup and follow the tutorial with your developer. A trusted partner server can instead use a private access key for services and bookings, but not patient-account records. Never put that server key in a phone app.</p></li>';
        echo '<li><h3>Complete the patient setup before bookings</h3><p>After patient sign-in is ready, open <a href="' . esc_url(self::url('patient-onboarding')) . '">Patient onboarding</a> if your app needs to collect missing profile details. You can reuse an existing shortcode onboarding scheme or create an API-only field list. Enable Require completion before booking when every new booking must have the configured profile details.</p></li>';
        echo '<li><h3>Add bookings when the service list works</h3><p>Tick the required questionnaires under Published form templates and set up patient sign-in, then enable Authenticated patient booking and Booking payment and progress. Every booking step requires a verified patient access token. Follow the booking tutorial with your developer and test payment and confirmation before launch.</p></li>';
        echo '<li><h3>Give your developer the connection details</h3><p>Share the Base URL above and the features you want the app to use. Endpoint documentation lists the exact information the app sends and receives. Download active OpenAPI JSON after saving settings to give the developer an up-to-date copy.</p></li></ol>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('tutorials')) . '">Explore step-by-step tutorials</a> <a class="button" href="' . esc_url(self::url('documentation')) . '">View endpoint documentation</a> <a class="button" href="' . esc_url(self::url('clients')) . '">Manage applications</a></p>';
    }

    private static function guide(): void
    {
        echo '<h2>API Access dashboard guide</h2><p>This guide explains the dashboard in everyday language. You can make setup choices here and use the tutorials to find out what to ask your developer for. The exact connection formats are in Endpoint documentation.</p>';
        $sections = [
            'Getting started' => 'A guided setup path and live readiness checklist. Start with the API master switch and GET /status; add discovery, applications and bookings in that order. Patient endpoints require OAuth, while public discovery can be tested without a login provider.',
            'Field help and tutorials' => 'Select a question-mark icon beside a field to open a detailed help dialog with examples, where to find the value and how to use it. Close with Done, the close button or Escape. Each dialog links to a related step-by-step tutorial. The Tutorials tab covers OAuth patient login, backend credentials, booking/payment, browser origins, namespace migration and troubleshooting.',
            'API master switch' => 'The global switch for the managed API namespace. Disabled means its operations are not registered. Existing website /v1 and /v2 routes have their own contracts and are not enabled or disabled by this switch. The retired unsafe legacy operations stay retired.',
            'API slug, version and namespace migration' => 'The slug identifies the API within WordPress. The complete address includes /wp-json/{slug}/v1. Contract version 1 is managed by the plugin. Changing an enabled slug requires confirmation and creates a 30-day alias under the old name. The old alias calls the same handlers and emits deprecation/sunset headers. Applications must change their base URL before the alias expires.',
            'Booking discovery group' => 'Exposes the approved appointment-type list, individual type details, practitioners and availability. It reveals booking metadata such as descriptions, names, durations and prices. Only IDs saved by the Published appointment types picker are available. Unselected IDs are treated as unpublished, even if they exist in Cliniko.',
            'Published form templates group' => 'Allows reading the approved template questions and submission structure. It does not expose a completed patient form. Templates must be listed explicitly. This group controls template browsing; a listed template may still be used in booking when browsing is disabled.',
            'Booking payment and progress group' => 'Lets an active verified patient pay, finalize or check their own booking attempt. Every attempt request requires both their Bearer access token and X-ES-Attempt-Token, bound to the original patient and application. Tyro SDK token requests also require patient authentication. Prices and payment verification are server-controlled. No guest booking creation is exposed.',
            'Patient profile and appointments group' => 'Allows an authenticated verified patient to read /me and their own appointments. The OAuth token must be active, intended for this API, issued to a locally approved application and mapped by issuer + subject to an active WordPress patient account. Current release responses omit Medicare numbers, raw custom fields and internal appointment notes.',
            'Authenticated patient booking group' => 'Creates a booking attempt using the linked patient resolved on the server. Submitted identity fields cannot replace that patient. Booking payment and progress must also be enabled for the payment, finalization and status routes. A website shortcode nonce is not used for this mobile flow.',
            'Patient profile onboarding' => 'After sign-in, the app reads the configured schema, starts a short-lived completion session and submits only missing profile details. Existing Cliniko values count as complete. If Require completion before booking is enabled, booking creation returns onboarding_required until the schema reports complete. The temporary onboarding token is not a login token and is bound to the patient, app and definition revision.',
            'Published Cliniko IDs' => 'These are exposure allowlists, not credentials. The appointment-type and form-template pickers load paginated active references from Cliniko and store only selected numeric IDs. Missing saved IDs remain checked for explicit review. Empty lists publish nothing. They do not grant access to any completed patient record.',
            'Read/write limits' => 'Read limits apply to GET requests and write limits to POST requests. Each uses a fixed 10-minute window. The database maintains atomic counters shared across site servers. Limits apply to the connection IP, and also to a valid identified application. Requests above the limit return 429 and Retry-After. Forwarded headers or changing User-Agent cannot reset the counter.',
            'Allowed browser origins' => 'Controls browser JavaScript access through CORS. List exact HTTPS origins, without paths or wildcards. HTTP is also accepted when WordPress is in local or development mode; staging and production still require HTTPS. Native mobile applications generally send no Origin header and do not need this setting. CORS does not authenticate users or applications. OAuth credentials/scopes and patient ownership are still checked.',
            'OAuth provider fields' => 'Issuer is the exact iss claim. Audience is the identifier of this API in aud. Introspection endpoint is the provider’s RFC 7662 token-validation URL, not the login or token-issuance URL. Introspection client ID/secret authenticate WordPress as a confidential resource server. The provider must return active, token_type=Bearer, exp, iss, aud, sub, client_id and scope. Configure PKCE, authorized redirect URIs and refresh-token rules at the provider.',
            'Test saved OAuth provider' => 'Sends an intentionally invalid synthetic token to the configured introspection endpoint using the saved credentials. Success means the endpoint accepted those credentials and correctly reported the token as inactive. It does not verify a real patient login, issuer/audience claims, scopes or subject mapping. Use a staging patient to test those separately.',
            'OAuth application registration' => 'Register the provider’s exact public client ID and select locally approved scopes. The effective permission is the intersection of these local scopes and the scopes on the provider access token. No permanent mobile secret is created. A valid provider token from an unregistered, revoked or expired local application is rejected.',
            'Trusted server integration' => 'A separate backend service may use a generated bearer credential. The plaintext is displayed once; the plugin stores a keyed hash. Server credentials are limited to discovery and booking scopes and cannot access patient-account endpoints. Set an expiry date, rotate leaked credentials and revoke unused applications.',
            'Scopes and application expiry' => 'Scopes define the operations a token may perform, for example catalog:read, booking:create, patient:read or appointments:read. They do not replace patient ownership checks. Local approval expiry is separate from token expiry; both must be valid. Revocation disables the application immediately for its next API request. Rotation replaces a server credential and invalidates the old key.',
            'Patient links' => 'Explicitly connects a provider issuer + sub pair to a WordPress patient user ID. The subject is the provider’s stable user identifier. The WordPress user ID is not a Cliniko patient ID. Token email addresses are not used for automatic linking. Removing a mapping blocks patient access for that subject; it does not delete either account.',
            'Endpoint documentation' => 'The catalog is generated from the same endpoint definitions as registration and validation. It shows disabled operations and their reason, full route URLs, methods, authentication, scopes, parameter locations and constraints, nested patient schemas and synthetic response examples. Expand a route to inspect it or use the search field. The page never fetches a patient record to populate examples.',
            'OpenAPI download' => 'Exports the current enabled operations, server base URL, request schemas and response schemas in OpenAPI JSON. Disabled operations are excluded, and namespace collisions produce an empty contract. Generate a fresh download after changing enabled groups, published IDs or the namespace. The export contains no credentials or real patient data.',
            'Audit events and retention' => 'Shows the latest 100 events. API events record the UTC time, operation identifier, local client/user ID, response status and a generated request ID. Administration changes such as settings, client creation and revocation are recorded. Full bearer tokens, request bodies and patient answers are excluded. Daily WordPress cron cleanup applies the configured retention period.',
            'Booking retry and idempotency rules' => 'Payment/finalization operations are serialized per attempt. Finalization requires an Idempotency-Key that must be reused on retries. An already completed attempt returns its existing result. A different key or attempt bound to a previously used key is rejected. If a process stops after finalization has started, the attempt needs reconciliation instead of blindly creating another appointment.',
            'Disabled routes, dependencies and errors' => 'Disabling a group removes its operations from registration. Booking needs published type/template IDs; patient booking additionally needs the booking group. OAuth misconfiguration makes bearer authentication unavailable. Invalid credentials are never silently treated as anonymous requests. Consult endpoint documentation for HTTP statuses and use X-Request-ID to find the corresponding audit event.',
            'Legacy website compatibility' => 'The new namespace is separate from existing website routes and shortcode forms. The unsafe public send-patient-form, unverified legacy Tyro charge and patient-history token handoff operations return 410 Gone. Website embeds using those operations require migration to the verified booking flow or email-code verification. Guest booking no longer overwrites an existing patient profile.',
        ];
        foreach (HelpContent::dashboardGuide() as $title => $text) echo '<section class="cliniko-api-card"><h3>' . esc_html($title) . '</h3><p>' . esc_html($text) . '</p></section>';
        $technical = [];
        foreach ($sections as $title => $text) $technical[] = ['title' => $title, 'text' => $text];
        HelpContent::renderBlocks([['title' => 'For your developer: technical reference', 'developer' => $technical]]);
        echo '<p><a class="button" href="' . esc_url(self::url('settings')) . '">Open settings</a> <a class="button" href="' . esc_url(self::url('documentation')) . '">Open endpoint catalog</a></p>';
    }

    private static function publishedPicker(string $field, array $selected): void
    {
        $forms = $field === 'form_templates';
        $result = $forms ? FormTemplateChoices::load() : AppointmentTypeChoices::load();
        $title = $forms ? 'Published form templates' : 'Published appointment types';
        $noun = $forms ? 'form template' : 'service';
        $plural = $forms ? 'form templates' : 'services';
        $prefix = $forms ? 'form-template' : 'service';
        $selected = array_map('strval', $selected);
        $known = array_column($result['items'], 'id');
        $missing = array_diff($selected, $known);
        echo '<tr><th>' . esc_html($title);
        self::info($title, $forms ? 'Select the blank Cliniko questionnaires applications may use for bookings. Reading their questions also requires the Published form templates route group. This does not expose completed patient forms.' : 'Select the Cliniko services applications may display and book. Only checked services are published. Saved selections that are missing from the latest list remain checked until you remove them.', $field);
        echo '</th><td><fieldset class="cliniko-api-services" data-cliniko-picker><legend>' . ($forms ? 'Choose the blank questionnaires apps may use' : 'Choose the services apps may show and book') . '</legend><p class="description">Tick ' . esc_html($plural) . ' by name, then Save API settings. Leaving every ' . esc_html($noun) . ' unticked publishes none.</p>';
        if ($forms) echo '<p class="description">Bookings need an approved questionnaire. Enable the Published form templates route group if apps should also read its questions. Completed patient answers are not shared.</p>';
        if ($result['error'] !== '') echo '<p class="cliniko-api-picker-warning" role="status">' . esc_html($result['error']) . '</p>';
        if ($result['items'] !== []) {
            echo '<p><label for="cliniko-api-' . esc_attr($prefix) . '-search">Find a ' . esc_html($noun) . '</label> <input type="search" data-cliniko-picker-search id="cliniko-api-' . esc_attr($prefix) . '-search" placeholder="Search by name"></p>';
        } elseif ($result['error'] === '') echo '<p>No active ' . esc_html($plural) . ' were returned by Cliniko. Add or check them in Cliniko, then return here.</p>';
        if ($missing !== [] && $result['error'] === '') echo '<p class="cliniko-api-picker-warning">Some previously selected ' . esc_html($plural) . ' are not in the latest Cliniko list. They stay approved until you untick them; check whether they were removed or archived.</p>';
        echo '<div class="cliniko-api-service-list">';
        foreach ($result['items'] as $item) {
            echo '<label class="cliniko-api-service-choice" data-picker-search="' . esc_attr(strtolower($item['name'] . ' ' . $item['id'])) . '"><input type="checkbox" name="' . esc_attr($field) . '[]" value="' . esc_attr($item['id']) . '" ' . checked(in_array($item['id'], $selected, true), true, false) . '><span><span class="cliniko-api-service-name">' . esc_html($item['name']) . '</span><span class="cliniko-api-service-meta">' . (!empty($item['minutes']) && $item['minutes'] > 0 ? (int) $item['minutes'] . ' minutes &middot; ' : '') . 'Cliniko ID ' . esc_html($item['id']) . '</span></span></label>';
        }
        foreach ($missing as $id) {
            echo '<label class="cliniko-api-service-choice is-missing" data-picker-search="' . esc_attr(strtolower('Previously selected ' . $noun . ' ' . $id)) . '"><input type="checkbox" name="' . esc_attr($field) . '[]" value="' . esc_attr($id) . '" checked><span><span class="cliniko-api-service-name">Previously selected ' . esc_html($noun) . ' (Cliniko ID ' . esc_html($id) . ')</span><span class="cliniko-api-service-meta">Not returned by Cliniko. Untick only if you want to stop publishing it.</span></span></label>';
        }
        echo '</div><p data-cliniko-picker-no-matches id="cliniko-api-' . esc_attr($prefix) . '-no-matches" hidden>No ' . esc_html($plural) . ' match your search. Existing selections are unchanged.</p><p class="description">Saved settings decide what is published; searching does not change selections. The Cliniko list is cached for up to five minutes.</p></fieldset></td></tr>';
    }

    private static function renderProviderGuide(array $guide): void
    {
        echo '<div class="cliniko-api-provider-guide"><p class="cliniko-api-eyebrow">' . esc_html($guide['status']) . '</p><h4>' . esc_html($guide['name']) . ' setup</h4><p>' . esc_html($guide['notice']) . '</p><p>' . ($guide['can_enable'] ? 'These steps are for your sign-in administrator or developer in the service\'s own dashboard. Once they supply verified connection values, continue through this wizard to enter them here.' : 'These steps help your administrator and developer plan the missing integration. Collected information is for your developer, not for these five connection fields. Leave patient sign-in off until the additional work is implemented and tested.') . '</p><ol>';
        foreach ($guide['steps'] as $step) echo '<li>' . esc_html($step) . '</li>';
        echo '</ol>';
        if (!empty($guide['collect'])) {
            echo '<h4>Information to collect for your developer</h4><ul>';
            foreach ($guide['collect'] as $item) echo '<li>' . esc_html($item) . '</li>';
            echo '</ul>';
        }
        echo '<p>Official references (checked 8 October 2026; screens and service versions may change):</p><ul>';
        foreach ($guide['links'] as $link) echo '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link['label']) . '</a></li>';
        echo '</ul></div>';
    }

    private static function signInProviderPicker(array $settings): void
    {
        echo '<p><label for="api-oauth-provider"><strong>Which sign-in service are you using?</strong></label></p><select id="api-oauth-provider" name="oauth_provider" class="regular-text">';
        foreach (SignInProviders::catalog() as $id => $guide) echo '<option value="' . esc_attr($id) . '"' . ($id === $settings['oauth_provider'] ? ' selected' : '') . '>' . esc_html($guide['name']) . '</option>';
        echo '</select><p class="description">Choose a service to see its own setup steps and where to find each value. Choosing a name does not create an account or verify compatibility.</p><div data-login-provider-guide>';
        self::renderProviderGuide(SignInProviders::guide($settings['oauth_provider']));
        echo '</div><p class="cliniko-api-picker-warning">Existing connection values and the saved password are kept when you choose a different guide. If you are connecting to a different service, replace its addresses, IDs and website password before checking. Do not send an old service\'s password to the new service.</p>';
        foreach (SignInProviders::catalog() as $id => $guide) {
            $metadata = ['can_enable' => $guide['can_enable'], 'name' => $guide['name'], 'locations' => $guide['locations'], 'endpoint_hint' => $guide['endpoint_hint'] ?? null, 'request' => PatientLoginSetup::requestText($id)];
            echo '<template id="cliniko-api-provider-' . esc_attr($id) . '" data-provider-config="' . esc_attr(wp_json_encode($metadata)) . '">';
            self::renderProviderGuide($guide);
            echo '</template>';
        }
        echo '<noscript><p>After choosing a different service, leave patient sign-in off and save to display that service\'s guide and hints.</p></noscript>';
    }

    private static function patientLoginWizard(array $settings): void
    {
        $state = PatientLoginSetup::state();
        $titles = ['Choose a service', 'Your sign-in service', 'Website connection', 'Review & save', 'Check & finish'];
        echo '<section class="cliniko-api-login" id="cliniko-api-patient-login" data-login-setup data-login-start="' . ($state['configured'] ? '4' : '0') . '" data-saved-secret="' . ($state['secret_saved'] ? '1' : '0') . '"><header class="cliniko-api-login-header"><p class="cliniko-api-eyebrow">Guided setup</p><h2>Patient app login (OAuth)';
        self::info('Patient app login', 'A patient app sends a temporary login pass issued by a separate sign-in service. The plugin checks it before allowing access to the matching verified patient account.', 'bearer-authentication');
        echo '</h2><p>Help patients sign in to your app and see only their own information. You do not need this for public services and available times.</p><p class="cliniko-api-login-saved-status">Saved setting: <strong>' . ($settings['oauth_enabled'] ? 'Patient app sign-in is enabled' : 'Patient app sign-in is off') . '</strong>. Changes below take effect only when you save.</p></header><nav class="cliniko-api-login-navigation" aria-label="Patient login setup steps" data-login-navigation hidden>';
        foreach ($titles as $index => $title) echo '<button type="button" data-login-go="' . (int) $index . '" aria-controls="cliniko-api-login-step-' . (int) $index . '"><span>' . ($index + 1) . '</span> ' . esc_html($title) . '</button>';
        echo '</nav><div class="cliniko-api-login-panel" id="cliniko-api-login-step-0" data-login-panel aria-labelledby="cliniko-api-login-heading-0"><h3 id="cliniko-api-login-heading-0" tabindex="-1">1. Choose your sign-in service</h3><p>You need a separate sign-in service and a developer to connect the patient app. This plugin checks logins; it does not create the login service or build the app login screen.</p>';
        self::signInProviderPicker($settings);
        echo '<div class="cliniko-api-login-paths"><div><h4>No sign-in service yet?</h4><p>Send the checklist below to your developer first. Leave patient sign-in off while they prepare a compatible service. Do not guess values or paste your WordPress account password.</p></div><div><h4>Already have the setup details?</h4><p>Continue to the next steps and copy the supplied values. The website connection and mobile app have separate IDs; the app ID is approved after the website connection is saved.</p></div></div><p><label for="cliniko-api-login-request"><strong>Checklist to send your developer — no saved passwords included</strong></label></p><textarea id="cliniko-api-login-request" class="large-text" rows="8" readonly>' . esc_textarea(PatientLoginSetup::requestText()) . '</textarea><p><button type="button" class="button" data-login-copy>Copy developer checklist</button> <a href="' . esc_url(self::url('tutorials') . '#oauth') . '">Read the complete tutorial</a></p><p class="description" data-login-copy-status role="status"></p></div>';
        $fields = PatientLoginSetup::fields();
        $providerGuide = SignInProviders::guide($settings['oauth_provider']);
        foreach ([1 => ['issuer', 'audience'], 2 => ['introspection_url', 'introspection_client', 'introspection_secret']] as $index => $names) {
            echo '<div class="cliniko-api-login-panel" id="cliniko-api-login-step-' . $index . '" data-login-panel aria-labelledby="cliniko-api-login-heading-' . $index . '"><h3 id="cliniko-api-login-heading-' . $index . '" tabindex="-1">' . ($index + 1) . '. ' . esc_html($titles[$index]) . '</h3><p>' . ($index === 1 ? 'These two values identify the service handling sign-in and the API it is allowed to reach. Copy them exactly from your developer.' : 'These values let your WordPress website check patient login passes. This private connection password belongs on the website, never in the mobile app.') . '</p><table class="form-table">';
            foreach ($names as $name) {
                $field = $fields[$name];
                self::input($name, $field['label'], $name === 'introspection_secret' ? '' : $settings[$name], $field['type'], $field['help'] . ' Developer name: ' . $field['technical'] . '.', $providerGuide['locations'][$name] ?? '');
            }
            echo '</table>';
            echo '<p class="cliniko-api-picker-warning" data-login-direct-warning' . ($providerGuide['can_enable'] ? ' hidden' : '') . '>' . esc_html($providerGuide['name']) . ' direct validation is not implemented here. These five fields are for compatible token-checking connections, not a way to configure this direct service. Use the chosen service guide to collect information for your developer; keep existing connection values unchanged while planning that work.</p>';
            if ($index === 2) {
                echo '<p><button type="button" class="button" data-login-build-endpoint hidden>Suggest checking address from service address</button></p><p class="description" data-login-endpoint-status role="status"></p>';
                echo '<p class="description">' . ($state['secret_saved'] ? 'A website connection password is already saved. Leave the field empty to keep it.' : 'No website connection password is saved yet. Obtain it securely from your developer.') . '</p><p><button type="button" class="button" data-login-show-secret aria-pressed="false" hidden>Show entered password</button></p>';
            }
            echo '</div>';
        }
        echo '<div class="cliniko-api-login-panel" id="cliniko-api-login-step-3" data-login-panel aria-labelledby="cliniko-api-login-heading-3"><h3 id="cliniko-api-login-heading-3" tabindex="-1">4. Review &amp; save</h3><p>Check the connection details below. If you are still waiting for values, leave patient sign-in off and save your progress. Do not enable it until all five items are present.</p><ul class="cliniko-api-login-checklist">';
        foreach ($fields as $key => $field) {
            $ready = !isset($state['missing'][$key]);
            echo '<li data-login-required="' . esc_attr($key) . '" data-login-label="' . esc_attr($field['label']) . '"><span class="cliniko-api-login-indicator ' . ($ready ? 'is-ready' : 'is-pending') . '">' . ($ready ? 'Present' : 'Needed') . '</span> ' . esc_html($field['label']) . ' <button type="button" class="button-link" data-login-edit="' . esc_attr($key) . '" hidden>Edit</button></li>';
        }
        echo '</ul><p><label><input type="checkbox" id="api-oauth-enabled" name="oauth_enabled" value="1" ' . checked($settings['oauth_enabled'], true, false) . '> Enable patient app sign-in</label></p><p class="description">This does not automatically enable patient routes or approve an application. Those are explicit choices in Route groups and Applications.</p><p data-login-error role="alert" hidden></p><p><button type="submit" class="button button-primary">Save patient-login settings</button></p><p class="description">Only patient-login settings are saved here. API addresses, route groups, published services/forms and traffic limits are unchanged. Leave the password field blank to keep the saved website password.</p></div>';
        echo '<div class="cliniko-api-login-panel" id="cliniko-api-login-step-4" data-login-panel aria-labelledby="cliniko-api-login-heading-4"><h3 id="cliniko-api-login-heading-4" tabindex="-1">5. Check the connection, then finish the app setup</h3><p>Save first. This check uses only the saved website details and a made-up invalid login pass, never a real patient login.</p><div class="cliniko-api-login-connection-status" data-login-saved-check>';
        if ($state['check_passed']) echo '<strong>Saved connection check passed</strong><p>Checked ' . esc_html(gmdate('Y-m-d H:i', $state['checked_at'])) . ' UTC. This does not confirm the app login, permissions or patient links.</p>';
        elseif ($state['check_current']) echo '<strong>Saved connection check did not pass</strong><p>' . esc_html(PatientLoginSetup::failureMessage($state['error_code'])) . '</p>';
        else echo '<strong>Connection not checked for these saved settings</strong><p>Complete the connection details, enable patient sign-in, save, and then check below. Changing saved details or the website password requires a new check.</p>';
        echo '</div><p data-login-unsaved role="status" hidden>You have unsaved login changes. Save before checking: the button checks the saved connection, not the values you are currently editing.</p><p><button type="submit" class="button button-secondary" form="cliniko-api-provider-test-form" data-login-check ' . (!$state['configured'] || !$settings['oauth_enabled'] ? 'disabled' : '') . '>Check saved connection</button></p><ol class="cliniko-api-login-finish">';
        echo '<li><strong>Enable the features you want.</strong> In <a href="' . esc_url(self::url('settings')) . '">Publish API</a>, choose Patient profile and appointments for personal records. The API master switch must also be on. For patient bookings, also choose Authenticated patient booking and Booking payment and progress.</li>';
        echo '<li><strong>Approve the patient app.</strong> Use its separate provider client ID, select the tasks it needs and choose an expiry. <a href="' . esc_url(self::url('clients') . '#cliniko-api-add-application') . '">Add or review applications</a>.<br><span class="description">' . (int) $state['apps'] . ' active OAuth application approval(s) currently saved. This is not a test of provider permissions.</span></li>';
        echo '<li><strong>Link a test patient.</strong> Match the supplied sign-in subject ID to the correct existing WordPress patient account. Do not guess using an email address. <a href="' . esc_url(self::url('mappings')) . '">Add or review patient links</a>.<br><span class="description">' . (int) $state['mappings'] . ' patient link(s) saved for this sign-in service. Account verification is checked separately when the app connects.</span></li>';
        echo '<li><strong>Try an actual login in the app.</strong> Ask your developer to use a verified test patient and check they see only their own records. An unlinked account must not see personal records. Only then plan a patient rollout.</li></ol></div>';
        echo '<div class="cliniko-api-login-footer" data-login-navigation hidden><button type="button" class="button" data-login-previous>Back</button><span data-login-position role="status"></span><button type="button" class="button button-primary" data-login-next>Continue</button></div><noscript><p>JavaScript is off: all five setup sections are shown below each other. You can still fill in the fields, save and check the saved connection.</p></noscript></section>';
    }

    private static function settings(): void
    {
        $s = ApiSettings::get();
        echo '<div class="cliniko-api-card"><h2>API overview</h2><p>Status: <strong>' . ($s['enabled'] ? 'Enabled' : 'Disabled') . '</strong></p><p>Base URL: <code>' . esc_html(rest_url(ApiSettings::namespace())) . '</code></p><p>Contract: ' . esc_html(ApiSettings::VERSION) . '. Default installation: all new endpoints disabled.</p></div>';
        echo '<div class="notice notice-warning inline"><p>Legacy security containment: the old unauthenticated form submission, unverified Tyro charge, and cross-device patient token handoff are retired. Existing embeds using those operations must migrate. Other website v1/v2 routes remain separate from these controls.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_settings">';
        wp_nonce_field('cliniko_api_settings');
        echo '<table class="form-table"><tr><th>API master switch';
        self::info('API master switch', 'Globally enables this managed API. Existing website namespaces remain separate. Start with this switch and test GET /status before enabling booking or patient features.');
        echo '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked($s['enabled'], true, false) . '> Enable API Access</label></td></tr>';
        self::input('slug', 'API base slug', $s['slug'], 'text', 'Examples: cliniko-api, api, patient-api. The plugin manages the /v1 suffix.');
        echo '<tr><th>Namespace migration</th><td><label><input type="checkbox" name="confirm_namespace_change" value="1"> Confirm changing an active base URL</label><p class="description">The previous slug stays as a deprecated alias for 30 days. Update installed clients during this period.</p>';
        foreach ($s['aliases'] as $slug => $expiry) if ($expiry > time()) echo '<p><code>' . esc_html($slug . '/v1') . '</code> expires ' . esc_html(gmdate('Y-m-d', $expiry)) . ' UTC.</p>';
        echo '</td></tr><tr><th>Route groups';
        self::info('Route groups', 'Enable only the features required by your applications. Disabled groups have no registered endpoints. Patient booking depends on the shared booking group; booking also needs published type/template IDs.');
        echo '</th><td id="cliniko-api-route-groups">';
        foreach (EndpointCatalog::groups() as $group => $label) echo '<label class="cliniko-api-choice"><input type="checkbox" name="groups[]" value="' . esc_attr($group) . '" ' . checked(in_array($group, $s['groups'], true), true, false) . '> ' . esc_html($label) . '</label>';
        echo '<p class="description">Authenticated patient booking also requires Booking payment and progress. Guest booking creation is not exposed by this API; all booking operations require a verified patient login. Profile edits use PATCH /me with the patient:write permission; attachments and messages remain outside this initial release.</p></td></tr>';
        self::publishedPicker('appointment_types', $s['appointment_types']);
        self::publishedPicker('form_templates', $s['form_templates']);
        self::input('read_limit', 'Read requests / 10 minutes', $s['read_limit'], 'number', 'How often apps may ask for information. Keep 180 to start; maximum 1,000.');
        self::input('write_limit', 'Write requests / 10 minutes', $s['write_limit'], 'number', 'How often apps may perform actions such as bookings and payments. Keep 20 to start; maximum 100.');
        self::input('audit_days', 'Audit retention (days)', $s['audit_days'], 'number', '1–90 days; cleaned daily through WordPress cron.');
        echo '<tr><th><label for="api-origins">Allowed browser origins</label>';
        self::info('Allowed browser origins', 'Controls CORS for browser applications. An origin is scheme + host + optional port, with no path. This does not grant API permission. Native mobile apps do not need CORS; authentication and scopes still apply.');
        echo '</th><td><textarea id="api-origins" name="origins" rows="4" class="large-text">' . esc_textarea(implode("\n", $s['origins'])) . '</textarea><p class="description">One approved website origin per line, for example https://portal.example.com, without a page path. HTTP origins such as http://localhost:3000 are allowed only when WordPress is in local or development mode. Staging and production require HTTPS. Native phone apps generally do not need this setting.</p></td></tr></table>';
        submit_button('Save API settings');
        echo '</form>';
        echo '<div class="cliniko-api-card"><h2>Patient app sign-in</h2><p>The guided setup, sign-in service choices, website credentials and connection check are managed in their own tab. Saving this page does not change those login settings.</p><p><a class="button" href="' . esc_url(self::url('patient-login')) . '">Open Patient login</a></p></div>';
    }

    private static function patientLogin(): void
    {
        $section = sanitize_key((string) ($_GET['section'] ?? 'wordpress'));
        $sections = ['wordpress' => 'WordPress login · JWT', 'oauth' => 'External OAuth 2.0', 'social' => 'Social sign-in', 'registration' => 'Registration', 'sessions' => 'Sessions & expiry'];
        if (!isset($sections[$section])) $section = 'wordpress';
        echo '<p class="cliniko-api-lead">Choose how patients prove who they are. Every method still checks the app’s permissions and the patient’s verified Cliniko account.</p><nav class="cliniko-api-subnav" aria-label="Patient authentication methods">';
        foreach ($sections as $key => $label) echo '<a ' . ($key === $section ? 'aria-current="page"' : '') . ' href="' . esc_url(self::url('patient-login', $key)) . '">' . esc_html($label) . '</a>';
        echo '</nav>';
        if ($section !== 'oauth') { self::appLoginSettings($section); return; }
        echo '<div class="cliniko-api-callout"><h3>Use a separate sign-in service</h3><p>The service handles login and social buttons. Your app sends its access token here; this website checks it before returning patient data. Provider login, social connections, consent and token renewal are configured at that service—not created by this dashboard.</p></div>';
        echo '<p>General API settings and published services/forms are managed in <a href="' . esc_url(self::url('settings')) . '">Publish API</a>. Approve the app under <a href="' . esc_url(self::url('clients')) . '">Applications</a> and connect external identities under <a href="' . esc_url(self::url('mappings')) . '">Patient links</a>.</p>';
        echo '<form id="cliniko-api-patient-login-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_patient_login">';
        wp_nonce_field('cliniko_api_patient_login');
        self::patientLoginWizard(ApiSettings::get());
        submit_button('Save patient-login settings');
        echo '</form>';
        echo '<form id="cliniko-api-provider-test-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" hidden><input type="hidden" name="action" value="cliniko_api_test_provider">';
        wp_nonce_field('cliniko_api_test_provider');
        echo '</form>';
    }

    private static function appLoginSettings(string $section): void
    {
        $s = ApiSettings::get();
        echo '<form class="cliniko-api-card cliniko-api-settings-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_app_login"><input type="hidden" name="section" value="' . esc_attr($section) . '">';
        wp_nonce_field('cliniko_api_app_login');
        if ($section === 'wordpress') {
            echo '<p class="cliniko-api-eyebrow">Your own app login screen</p><h3>Sign in with the patient’s website account</h3><p>The app sends the patient’s username/email and password to your website. WordPress checks them, then this plugin returns a short-lived JWT. No separate sign-in service or permanent password inside the app is needed.</p>';
            DashboardExperience::journey(['App login screen', 'WordPress checks credentials + patient approval', 'Website returns JWT session', 'App requests /me with Bearer token']);
            echo '<label class="cliniko-api-setting-toggle"><input type="checkbox" name="password_login_enabled" value="1" ' . checked($s['password_login_enabled'], true, false) . '><span><strong>Allow WordPress password login in approved apps</strong><small>Off by default. Enabling adds /auth/login, /auth/refresh and /auth/logout when the API is published.</small></span></label><div class="cliniko-api-callout is-warning"><h4>Check your website’s login rules first</h4><p>A custom API login does not automatically run a two-factor screen, CAPTCHA, membership approval screen or a social-login plugin. Known interactive MFA integrations are blocked. Your developer must verify any other login restrictions before enabling this option. Do not bypass two-factor requirements.</p><label><input type="checkbox" name="password_login_reviewed" value="1" ' . checked($s['password_login_reviewed'], true, false) . '> My developer has reviewed the patient login requirements and confirmed compatibility.</label></div><p>Use only with apps you trust to receive patients’ website passwords. Independently operated third-party apps should use external OAuth login instead.</p><ol class="cliniko-api-compact-steps"><li>Create a <strong>Patient app · WordPress / social JWT</strong> under Applications.</li><li>Give it only the patient-data permissions it needs; copy its App ID.</li><li>Enable this login method, then publish the required patient routes.</li><li>Ask your app developer to implement login, safe token storage, renewal and logout.</li></ol><p><a class="button" href="' . esc_url(self::url('clients')) . '">Create a patient app</a> <a href="' . esc_url(self::url('flows')) . '">See exactly what the app sends</a></p>';
        } elseif ($section === 'social') {
            echo '<p class="cliniko-api-eyebrow">Google and Apple</p><h3>Let patients use a social sign-in button</h3><p>The app opens Google or Apple’s sign-in screen. Your website verifies the returned proof of identity and exchanges it for a patient JWT. The app never receives the social account password.</p>';
            DashboardExperience::journey(['Website creates one-use challenge', 'App signs in with Google / Apple + nonce', 'Website verifies ID token + patient link', 'App receives patient JWT']);
            foreach (PatientAuthentication::socialProviders() as $key => $provider) {
                echo '<div class="cliniko-api-provider-card"><div><h4>' . esc_html($provider['name']) . '</h4><span class="cliniko-api-badge ' . ($s[$key . '_login_enabled'] ? 'is-active' : '') . '">' . ($s[$key . '_login_enabled'] ? 'Enabled' : 'Off') . '</span></div><label class="cliniko-api-choice"><input type="checkbox" name="' . esc_attr($key . '_login_enabled') . '" value="1" ' . checked($s[$key . '_login_enabled'], true, false) . '> Allow ' . esc_html($provider['name']) . ' sign-in for configured patient apps</label><ol class="cliniko-api-compact-steps">';
                $steps = $key === 'google' ? ['Register the app in Google Cloud / Google Auth Platform. Configure the consent screen and web origins or native application details as required by your SDK.', 'Copy the client ID used as the ID-token audience. Enter it in that patient application’s Google client ID field here. Do not paste a Google client secret.', 'Your developer calls /auth/challenge, passes its nonce to Google’s supported sign-in SDK, and exchanges the returned ID token at /auth/social. An SDK flow without nonce support will be rejected.', 'Under Patient links, link the verified Google subject ID to the existing patient account. Do not use email matching.'] : ['In your Apple Developer account, enable Sign in with Apple for the app. Web flows also need a Services ID, website domain and approved return URL.', 'Enter the exact ID-token audience (app bundle ID or Services ID) in the patient application’s Apple application ID field here. Apple private keys stay in your app backend when required; never put one in a mobile app.', 'Your developer requests /auth/challenge and passes its nonce unchanged to Apple. It is already SHA-256 transformed. Exchange the returned identityToken at /auth/social.', 'Link Apple’s verified subject ID to the existing patient under Patient links. An Apple private-relay email is not an account-linking credential.'];
                foreach ($steps as $step) echo '<li>' . esc_html($step) . '</li>';
                echo '</ol><p><a href="' . esc_url($key === 'google' ? 'https://developers.google.com/identity/gsi/web/guides/verify-google-id-token' : 'https://developer.apple.com/documentation/signinwithapple') . '" target="_blank" rel="noopener noreferrer">Official ' . esc_html($provider['name']) . ' setup documentation</a></p></div>';
            }
            echo '<div class="cliniko-api-callout is-warning"><strong>Social login identifies a person. It does not create or approve a patient.</strong><p>An explicit patient link is required. This feature never creates Cliniko records or joins accounts just because their emails match.</p></div><p><a class="button" href="' . esc_url(self::url('clients')) . '">Configure app IDs</a> <a class="button" href="' . esc_url(self::url('mappings')) . '">Manage patient links</a> <a href="' . esc_url(self::url('flows')) . '">Social request walkthrough</a></p><p>Other social services can be brokered through your configured external OAuth service. They are not direct Google/Apple connections.</p>';
        } elseif ($section === 'registration') {
            echo '<p class="cliniko-api-eyebrow">New patients in approved apps</p><h3>Reuse your website’s patient verification flow</h3><p>The app submits signup details. WordPress creates a pending patient account and sends the same verification email your website uses. Only after the patient confirms email ownership and the clinic’s approval requirements are met can the app sign in and request private data.</p>';
            DashboardExperience::journey(['Patient signs up + accepts privacy policy', 'WordPress creates a pending patient account', 'Existing clinic verification email is sent', 'Patient verifies, then signs in separately']);
            $methods = ['registration_password_enabled' => ['Website-account registration', 'App sends email, first/last names and a new password to /auth/register.'], 'registration_social_enabled' => ['Google and Apple registration', 'App proves the social identity with a registration challenge and verified-email ID token at /auth/register/social.'], 'registration_oauth_enabled' => ['External OAuth registration', 'The provider token must allow account:register and supply a verified email at /auth/register/oauth.']];
            foreach ($methods as $key => [$title, $description]) echo '<label class="cliniko-api-setting-toggle"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($s[$key], true, false) . '><span><strong>' . esc_html($title) . '</strong><small>' . esc_html($description) . '</small></span></label>';
            echo '<table class="form-table"><tr><th><label for="api-registration_role">Patient role for new accounts</label>';
            self::info('Patient role for new accounts', 'The server assigns this role. Only configured patient-sync roles with read/level_0 capabilities are eligible. Clients cannot choose a role, privileges or a Cliniko patient ID.', 'registration_role');
            echo '</th><td><select id="api-registration_role" name="registration_role"><option value="">Choose a configured patient role</option>';
            foreach (PatientRegistration::eligibleRoles() as $key => $name) echo '<option value="' . esc_attr($key) . '" ' . ($s['registration_role'] === $key ? 'selected' : '') . '>' . esc_html($name) . '</option>';
            echo '</select><p class="description">Patient sync must be enabled. If the role is missing, configure a low-privilege patient role in the existing patient-sync settings. Administrator/editor/elevated roles cannot be assigned by app registration.</p></td></tr></table><div class="cliniko-api-callout is-warning"><h4>Review signup requirements before enabling</h4><p>Your developer must implement an explicit privacy-consent checkbox and check membership, anti-spam, CAPTCHA and clinic approval requirements. API signup does not execute your website’s registration form. A server-side integration can block it with wp_cliniko_api_registration_allowed.</p><label><input type="checkbox" name="registration_reviewed" value="1" ' . checked($s['registration_reviewed'], true, false) . '> My developer has reviewed signup, consent, spam protection and approval requirements.</label></div><ol class="cliniko-api-compact-steps"><li>Give the approved application <strong>Register new patient accounts</strong> permission. For external OAuth, approve account:register at the provider too.</li><li>Select the patient role and signup methods here; save deliberately. Patient sync and email delivery must already work.</li><li>The app stores the private onboarding receipt to check /auth/registration/status or request /auth/registration/resend. This is not an access token.</li><li>The patient opens the existing WordPress verification email. The website completes Cliniko binding or new-patient creation according to the existing rules.</li><li>When verification and approval are complete, the app signs in through the chosen login method. No patient JWT is issued by signup itself.</li></ol><p>Existing emails never cause an account merge or password/role update. Social registration uses the service’s verified email; Apple relay addresses need outbound relay configuration and may not match an existing clinic email. Existing patients should sign in or ask the clinic to link identities.</p><p><a class="button" href="' . esc_url(self::url('clients')) . '">Approve registration permissions</a> <a href="' . esc_url(self::url('flows')) . '">See registration requests</a></p>';
        } else {
            echo '<p class="cliniko-api-eyebrow">WordPress-issued JWT sessions</p><h3>How long can the app stay signed in?</h3><p>An <strong>access token</strong> lets the app request data for a short time. A <strong>refresh token</strong> renews it without asking for the password again. Refresh tokens rotate after every renewal; the app must store the new pair together.</p><table class="form-table">';
            self::input('access_minutes', 'Access token lifetime (minutes)', $s['access_minutes'], 'number', '5–30 minutes. Shorter limits the time a stolen access token can be used.');
            self::input('refresh_days', 'Maximum session lifetime (days)', $s['refresh_days'], 'number', '1–30 days, starting from the original login. Renewal does not extend this deadline.');
            echo '</table><p>Logging out or revoking a session stops its access and refresh tokens immediately. Password changes, account suspension, application revocation and removal of a social patient link also stop access. Existing session deadlines are not extended when you change these settings.</p><p>External OAuth tokens follow the external service’s lifetime and revocation rules. This table only lists sessions issued by this website.</p>';
        }
        submit_button($section === 'sessions' ? 'Save session lifetimes' : ($section === 'registration' ? 'Save registration settings' : 'Save login method'));
        echo '</form>';
        if ($section !== 'registration' && !PatientSessions::ready()) echo '<div class="cliniko-api-callout"><p>WordPress-issued sessions are not initialized yet. Saving an enabled WordPress or social method creates the encrypted signing key and checks database storage. No login method is enabled automatically.</p></div>';
        if ($section === 'sessions') {
            echo '<div class="cliniko-api-card"><h3>Recent app sessions</h3><p>The latest 100 sessions. No token values or passwords are displayed.</p><div class="cliniko-api-table-scroll"><table class="widefat"><thead><tr><th>Patient account</th><th>Application</th><th>Sign-in</th><th>Expires (UTC)</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            $sessions = PatientSessions::recent();
            foreach ($sessions as $row) {
                $active = !$row['revoked'] && $row['expires_at'] > time() && ApiClients::patient($row['client_id']);
                $client = ApiClients::all()[$row['client_id']] ?? null;
                echo '<tr><td>' . esc_html((string) $row['user_id']) . '</td><td>' . esc_html($client['name'] ?? 'Removed application') . '</td><td>' . esc_html($row['method']) . '</td><td>' . esc_html(gmdate('Y-m-d H:i', (int) $row['expires_at'])) . '</td><td>' . ($active ? 'Recorded · permission checks still apply' : 'Revoked / expired / app disabled') . '</td><td>';
                if ($active) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_session_revoke"><input type="hidden" name="session_id" value="' . esc_attr($row['id']) . '">';
                    wp_nonce_field('cliniko_api_session_revoke');
                    echo '<button class="button" data-api-confirm="Revoke this app session? The patient will need to sign in again.">Revoke session</button></form>';
                }
                echo '</td></tr>';
            }
            if ($sessions === []) echo '<tr><td colspan="6">No WordPress-issued app sessions yet. Complete a test login after enabling your chosen method.</td></tr>';
            echo '</tbody></table></div></div>';
        }
    }

    private static function clients(): void
    {
        echo '<p class="cliniko-api-lead">An app’s permissions define what it may do—not which patient it can pretend to be. Patient identity always comes from a verified login.</p><div class="cliniko-api-card"><h3>Approved applications</h3><p>Use a <strong>Patient app</strong> for WordPress / Google / Apple JWT sessions, an <strong>OAuth app</strong> for your external service, or a <strong>Trusted server</strong> for non-patient integration keys.</p><div class="cliniko-api-table-scroll"><table class="widefat striped"><thead><tr><th>Name / ID</th><th>Login method</th><th>Permissions</th><th>Status / expiry (UTC)</th><th>Last used (UTC)</th><th>Actions</th></tr></thead><tbody>';
        foreach (ApiClients::all() as $client) {
            echo '<tr><td>' . esc_html($client['name']) . '<br><code>' . esc_html($client['id']) . '</code><br><button type="button" class="button button-small" data-copy-value="' . esc_attr($client['id']) . '">Copy App ID</button></td><td>' . esc_html(['patient' => 'WordPress / social JWT', 'oauth' => 'External OAuth 2.0', 'server' => 'Trusted server key'][$client['type']]) . '<br>' . esc_html($client['external_id']) . '</td><td>' . esc_html(implode(', ', $client['scopes'])) . '</td><td>' . (ApiClients::active($client) ? 'Active' : 'Revoked / expired') . '<br>' . esc_html(gmdate('Y-m-d', $client['expires_at'])) . '</td><td>' . ($client['last_used_at'] ? esc_html(gmdate('Y-m-d H:i', $client['last_used_at'])) : 'Never') . '</td><td>';
            if (ApiClients::active($client)) {
                echo '<details><summary>Edit scopes / expiry</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_client"><input type="hidden" name="operation" value="update"><input type="hidden" name="client_id" value="' . esc_attr($client['id']) . '">';
                wp_nonce_field('cliniko_api_client');
                foreach (EndpointCatalog::scopes() as $scope) {
                    if ($client['type'] === 'server' && in_array($scope, ['patient:read', 'patient:write', 'patient:onboarding', 'appointments:read', 'communications:read', 'communications:write', 'attachments:read', 'attachments:write', 'booking:create', 'booking:read', 'account:register'], true)) continue;
                    echo '<label class="cliniko-api-choice"><input type="checkbox" name="scopes[]" value="' . esc_attr($scope) . '" ' . checked(in_array($scope, $client['scopes'], true), true, false) . '> ' . esc_html(self::scopeLabel($scope)) . ' <code>' . esc_html($scope) . '</code></label>';
                }
                if ($client['type'] === 'patient') echo '<p><label>Google client ID <input class="regular-text" name="google_client_id" value="' . esc_attr($client['google_client_id'] ?? '') . '"></label></p><p><label>Apple application ID <input class="regular-text" name="apple_client_id" value="' . esc_attr($client['apple_client_id'] ?? '') . '"></label></p><p class="description">Public audience IDs, not secrets. Changes affect new sign-ins; revoke existing sessions separately if required.</p>';
                if ($client['type'] === 'patient') echo '<p><label>Google native app ID (optional) <input class="regular-text" name="google_presenter_id" value="' . esc_attr($client['google_presenter_id'] ?? '') . '"></label></p><p class="description">For a hybrid SDK whose authorized presenter (azp) differs from the token audience. Leave empty when they are the same.</p>';
                echo '<label>Approval expiry (UTC) <input name="expires_at" type="date" value="' . esc_attr(gmdate('Y-m-d', $client['expires_at'])) . '"></label><p><button class="button">Save permissions</button></p></form></details>';
                foreach ($client['type'] === 'server' ? ['rotate' => 'Rotate key', 'revoke' => 'Revoke'] : ['revoke' => 'Revoke'] as $operation => $label) {
                    echo '<form class="cliniko-api-inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_client"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="client_id" value="' . esc_attr($client['id']) . '">';
                    wp_nonce_field('cliniko_api_client');
                    echo '<button class="button" data-api-confirm="' . esc_attr($operation === 'revoke' ? 'Revoke this app? Its API access will stop.' : 'Rotate the key? The old key will stop immediately.') . '">' . esc_html($label) . '</button></form>';
                }
            }
            echo '</td></tr>';
        }
        if (ApiClients::all() === []) echo '<tr><td colspan="6">No approved applications yet. Register your first app below.</td></tr>';
        echo '</tbody></table></div></div><div class="cliniko-api-card"><h3 id="cliniko-api-add-application">Register an application</h3><form data-api-client-form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_client"><input type="hidden" name="operation" value="create">';
        wp_nonce_field('cliniko_api_client');
        echo '<table class="form-table">';
        self::input('name', 'Application name', '');
        echo '<tr><th>Application type';
        self::info('Application type', 'Patient apps use this website’s WordPress/social JWT sessions. OAuth apps use externally issued tokens. Both receive a public App ID, not a permanent shared password. Trusted servers receive a private key for published service information only; it cannot access patient accounts or bookings.');
        echo '</th><td><select name="type"><option value="patient">Patient app · WordPress / social JWT</option><option value="oauth">OAuth mobile/browser application</option><option value="server">Trusted server integration</option></select><p class="description" data-client-type-description></p></td></tr>';
        self::input('external_id', 'Provider client ID (OAuth only)', '', 'text', 'The app ID supplied by your sign-in developer. Leave empty for a Trusted server integration.');
        self::input('google_client_id', 'Google client ID (optional)', '', 'text', 'For Google: this app’s ID-token audience. Ends in .apps.googleusercontent.com. Not a secret.');
        self::input('google_presenter_id', 'Google native app ID (optional)', '', 'text', 'For hybrid mobile flows only: ask for the native client ID in azp when different from the audience above. Leave empty otherwise.');
        self::input('apple_client_id', 'Apple application ID (optional)', '', 'text', 'For Apple: the app bundle ID or Services ID matching the token audience. Not a private key.');
        self::input('expires_at', 'Local approval expiry (UTC)', gmdate('Y-m-d', time() + 90 * DAY_IN_SECONDS), 'date');
        echo '<tr><th>Approved scopes';
        self::info('Approved scopes', 'A scope grants a specific operation category. For OAuth, local approval and the provider token must both contain the required scope. Server credentials cannot receive patient-profile or appointment-history scopes.');
        echo '</th><td>';
        foreach (EndpointCatalog::scopes() as $scope) echo '<label class="cliniko-api-choice"><input type="checkbox" name="scopes[]" value="' . esc_attr($scope) . '"> ' . esc_html(self::scopeLabel($scope)) . ' <code>' . esc_html($scope) . '</code></label>';
        echo '<p class="description">Scopes mean permissions: choose only the tasks this app needs. Your sign-in developer must allow the same tasks at the sign-in service. Trusted server keys cannot access patient data, booking creation, payments or booking progress.</p></td></tr></table>';
        submit_button('Register application');
        echo '</form></div><div class="cliniko-api-callout"><p>WordPress password login uses the existing patient account directly. Google, Apple and external OAuth need a separate <a href="' . esc_url(self::url('mappings')) . '">Patient link</a>.</p></div>';
    }

    private static function mappings(): void
    {
        echo '<p class="cliniko-api-lead">Connect a verified external sign-in identity to the correct existing patient. WordPress password login does not need this extra link.</p><div class="cliniko-api-callout is-warning"><strong>Confirm both identities belong to the same person before linking.</strong><p>Use the provider’s stable subject ID—not an email or a name. This does not register, approve or verify a patient. The existing account must pass all patient checks.</p></div><div class="cliniko-api-card"><h3 id="cliniko-api-patient-mappings">Patient sign-in links</h3><div class="cliniko-api-table-scroll"><table class="widefat striped"><thead><tr><th>Sign-in service</th><th>Subject ID</th><th>WordPress patient</th><th>Action</th></tr></thead><tbody>';
        foreach (ApiClients::mappings() as $mapping) {
            echo '<tr><td>' . esc_html($mapping['issuer']) . '</td><td><code>' . esc_html($mapping['subject']) . '</code></td><td>' . esc_html((string) $mapping['user_id']) . '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_mapping"><input type="hidden" name="operation" value="remove"><input type="hidden" name="issuer" value="' . esc_attr($mapping['issuer']) . '"><input type="hidden" name="subject" value="' . esc_attr($mapping['subject']) . '">';
            wp_nonce_field('cliniko_api_mapping');
            echo '<button class="button">Remove mapping</button></form></td></tr>';
        }
        if (ApiClients::mappings() === []) echo '<tr><td colspan="4">No external identities linked yet.</td></tr>';
        echo '</tbody></table></div></div><div class="cliniko-api-card"><h3>Link an existing patient</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cliniko_api_mapping">';
        wp_nonce_field('cliniko_api_mapping');
        echo '<table class="form-table">';
        echo '<tr><th><label for="api-mapping-issuer">Sign-in service</label></th><td><select id="api-mapping-issuer" name="issuer">';
        foreach (PatientAuthentication::socialProviders() as $provider) echo '<option value="' . esc_attr($provider['issuer']) . '">' . esc_html($provider['name']) . '</option>';
        if (ApiSettings::get()['issuer'] !== '') echo '<option value="' . esc_attr(ApiSettings::get()['issuer']) . '">External OAuth — ' . esc_html(ApiSettings::get()['issuer']) . '</option>';
        echo '</select><p class="description">Use the subject from verified provider claims. Google issuer variants are normalized.</p></td></tr>';
        self::input('subject', 'Exact token subject', '');
        echo '<tr><th><label for="api-user_id">Existing patient account</label>';
        self::info('Existing patient account', 'Choose the existing active verified WordPress patient. The website rechecks the patient role, account status and Cliniko verification when the link is saved and on protected requests.', 'user_id');
        echo '</th><td><select id="api-user_id" name="user_id" required><option value="">Select a patient</option>';
        $roles = get_option('wp_cliniko_patient_sync_roles', []);
        $patients = is_array($roles) && $roles !== [] ? get_users(['role__in' => $roles, 'number' => 200, 'orderby' => 'display_name', 'order' => 'ASC', 'meta_query' => ['relation' => 'AND', ['key' => \App\Service\PatientService::META_ACCOUNT_STATUS, 'value' => \App\Service\PatientService::ACCOUNT_ACTIVE], ['key' => \App\Service\PatientLinkService::META_STATUS, 'value' => \App\Service\PatientLinkService::STATUS_VERIFIED]]]) : [];
        foreach ($patients as $patient) if (!user_can($patient, 'manage_options')) echo '<option value="' . (int) $patient->ID . '">' . esc_html($patient->display_name) . ' · WordPress #' . (int) $patient->ID . '</option>';
        echo '</select><p class="description">Shows up to 200 active verified accounts with a configured patient role. If missing, check patient approval and Cliniko verification first. Ask the developer to extend the picker for larger clinics.</p></td></tr></table><p><label><input type="checkbox" name="confirm_patient_link" value="1" required> I have confirmed that this external sign-in identity belongs to the selected patient.</label></p>';
        submit_button('Save patient mapping');
        echo '</form></div>';
    }

    private static function documentation(): void
    {
        echo '<h2>Live endpoint catalog</h2><p>Browse by category and check the access label before using a route. These labels describe what is required when the route is active; the separate status shows whether it is currently enabled.</p><p>Generated from the route definitions and current saved settings. Examples use synthetic values. No Cliniko records are fetched to render this page.</p>';
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg('action', 'cliniko_api_openapi', admin_url('admin-post.php')), 'cliniko_api_openapi')) . '">Download active OpenAPI JSON</a></p>';
        echo '<details class="cliniko-api-card cliniko-api-shared-rules"><summary>Shared authentication rules &amp; response format</summary><p>Published services, availability and blank form templates need no patient login. Creating bookings, payment and booking progress always require a verified patient login. POST /booking-attempts is not exposed; create your own booking at POST /me/booking-attempts. Supplied Authorization credentials are still checked. Patient routes accept <code>Authorization: Bearer &lt;patient JWT or provider access token&gt;</code>, approved app permissions and an active verified patient. External/social identities also need an explicit patient link. Social ID tokens must first be exchanged at /auth/social; they do not directly authorize /me. Login routes validate their JSON credentials/challenge/refresh proof instead of requiring an access token before login.</p><p>Attempt routes require both <code>Authorization: Bearer &lt;patient access token&gt;</code> and <code>X-ES-Attempt-Token</code>, with the original active verified patient and approved application. Guest attempts and server API keys are rejected. Attempt access expires after 24 hours.</p><p>All operations return <code>{ ok, data, meta: { request_id } }</code> on success and <code>{ ok: false, error: { code, message, fields }, meta: { request_id } }</code> on failure. Limits use a 10-minute window. Login also has stricter IP, app and password-identifier budgets. Production requires HTTPS. Unknown JSON fields are rejected.</p><p>Error statuses: 400 invalid input; 401 invalid authentication; 402 payment declined; 403 missing scope or ownership; 404 disabled/unpublished/not found; 409 busy or idempotency conflict; 413 oversized body; 422 booking/payment rejected; 429 rate limited; 500 upstream SDK/configuration failure; 503 provider unavailable.</p><p><a href="' . esc_url(self::url('flows')) . '">Explore the request and response walkthroughs</a></p></details>';
        $categories = EndpointDocumentation::categories();
        $grouped = [];
        foreach (EndpointCatalog::definitions() as $endpoint) $grouped[EndpointDocumentation::category($endpoint)][] = $endpoint;
        echo '<div class="cliniko-api-doc-controls"><label for="cliniko-api-search">Search endpoints<input type="search" id="cliniko-api-search" placeholder="Route, category, method or permission"></label><label for="cliniko-api-access-filter">Access required<select id="cliniko-api-access-filter"><option value="all">All access types</option><option value="public">Public — no credentials required</option><option value="patient">Patient access token</option><option value="attempt">Patient token + booking secret</option><option value="credentials">Patient password</option><option value="password_reset">Password recovery key</option><option value="social">Social sign-in proof</option><option value="oauth">OAuth token for registration</option><option value="refresh">Private refresh token</option><option value="receipt">Private registration receipt</option><option value="registration">New account details</option><option value="app">App ID only — no patient proof yet</option></select></label><p id="cliniko-api-doc-count" role="status" aria-live="polite">Showing ' . count(EndpointCatalog::definitions()) . ' endpoints.</p></div>';
        echo '<nav class="cliniko-api-doc-jumps" aria-label="Endpoint categories">';
        foreach ($categories as $category => $info) {
            if (empty($grouped[$category])) continue;
            echo '<a href="#cliniko-api-category-' . esc_attr($category) . '" data-doc-jump="' . esc_attr($category) . '">' . esc_html($info['title']) . ' <span>(' . count($grouped[$category]) . ')</span></a>';
        }
        echo '</nav><p id="cliniko-api-doc-empty" hidden>No endpoints match your search and access filter. Clear the search or choose All access types.</p>';
        foreach ($categories as $category => $info) {
            if (empty($grouped[$category])) continue;
            $count = count($grouped[$category]);
            echo '<section class="cliniko-api-doc-category" id="cliniko-api-category-' . esc_attr($category) . '" data-doc-category="' . esc_attr($category) . '" aria-labelledby="cliniko-api-category-title-' . esc_attr($category) . '"><h3 id="cliniko-api-category-title-' . esc_attr($category) . '">' . esc_html($info['title']) . ' <span class="cliniko-api-category-count">' . $count . ($count === 1 ? ' endpoint' : ' endpoints') . '</span></h3><p>' . esc_html($info['description']) . '</p>';
            if ($category === 'booking_flows') {
                echo '<h4>Your configured identifiers</h4><p>Saved-state status only; live Cliniko validation and patient permissions still apply. No patient records or templates are fetched to render this list.</p><div class="cliniko-api-table-scroll"><table class="widefat striped"><thead><tr><th>Identifier / saved status</th><th>Read structure · booking:read</th><th>Create booking · booking:create</th><th>Authentication</th></tr></thead><tbody>';
                foreach (BookingFlows::all() as $slug => $binding) {
                    echo '<tr><td>' . esc_html($slug) . '<br>' . ($binding['flow_type'] === 'api' ? 'API-only flow' : 'Legacy website-linked flow') . '<br>' . esc_html(BookingFlows::publicationState($slug)) . '</td><td><code>GET ' . esc_html(rest_url(ApiSettings::namespace() . '/booking-flows/' . $slug)) . '</code></td><td><code>POST ' . esc_html(rest_url(ApiSettings::namespace() . '/booking-flows/' . $slug . '/booking-attempts')) . '</code></td><td>Verified patient Bearer token on both routes.</td></tr>';
                }
                if (BookingFlows::all() === []) echo '<tr><td colspan="4">No booking flows created yet. Create one in Booking flows.</td></tr>';
                echo '</tbody></table></div>';
            }
            foreach ($grouped[$category] as $endpoint) {
                $state = EndpointCatalog::state($endpoint);
                if (in_array(ApiSettings::namespace(), ManagedApiRoutes::$collisions, true)) $state = 'Namespace collision';
                $url = rest_url(ApiSettings::namespace() . $endpoint['path']);
                $access = EndpointDocumentation::access($endpoint);
                echo '<details class="cliniko-api-endpoint" data-access="' . esc_attr($access['kind']) . '" data-search="' . esc_attr(strtolower($endpoint['method'] . ' ' . $endpoint['path'] . ' ' . $endpoint['summary'] . ' ' . $endpoint['scope'] . ' ' . $info['title'] . ' ' . $access['label'] . ' ' . $access['send'])) . '"><summary><span class="cliniko-api-method">' . esc_html($endpoint['method']) . '</span> <code>' . esc_html($endpoint['path']) . '</code> <span class="cliniko-api-access-badge is-' . esc_attr($access['kind']) . '">' . esc_html($access['label']) . '</span> <span class="cliniko-api-status ' . ($state === 'Active' ? 'is-active' : '') . '">' . esc_html($state) . '</span></summary><div class="cliniko-api-endpoint-body"><h4>' . esc_html($endpoint['summary']) . '</h4><p><code>' . esc_html($url) . '</code></p><div class="cliniko-api-access-details"><h4>Access requirements</h4><p>' . esc_html($access['proof']) . '</p><p><strong>Where to send it:</strong> <code>' . esc_html($access['send']) . '</code></p><p>' . esc_html($access['note']) . '</p></div>';
                if ($endpoint['scope'] !== '') {
                    $scopeLabel = $endpoint['auth'] === 'public' ? 'Application permission (only when a Bearer credential is supplied)' : 'Required application permission';
                    echo '<p>' . esc_html($scopeLabel) . ': <code>' . esc_html($endpoint['scope']) . '</code>.</p>';
                } else echo '<p>No additional operation-specific permission is required; the access requirements above still apply.</p>';
                $auth = ApiSettings::get();
                if ($endpoint['auth'] === 'patient' && !$auth['oauth_enabled'] && !$auth['password_login_enabled'] && !$auth['google_login_enabled'] && !$auth['apple_login_enabled']) echo '<p class="cliniko-api-warning">No patient login method is enabled. This route remains protected and will not return patient data until a supported login is configured.</p>';
                if ($endpoint['notes']) echo '<p>' . esc_html($endpoint['notes']) . '</p>';
                if (in_array($endpoint['key'], ['booking_flow', 'booking_flow_create'], true)) {
                    echo '<div class="cliniko-api-access-details"><h4>Structure-only booking contract</h4><p>Create API-only booking definitions directly in <a href="' . esc_url(self::url('booking-flows')) . '">Booking flows</a>. No shortcode is required. Choose only appointment type, form, scheduling, payment and optional answer checks. Records are marked API-only; existing web links are labelled Legacy and can be detached with Make API-only without changing their address or web form. The slug is your chosen API identifier, not a Cliniko ID. Neither route accepts guest access or server keys.</p><p>The response contains appointment_type, form.sections[].questions[], scheduling, payment, definition_revision, submission and resources. Questions expose name, type, required, options, other_allowed and validation (max_length and optional enum, format or pattern). When Cliniko linked a question to a patient field, the question also includes <code>custom_field_token</code> and <code>patient_field_connection</code>; these are read-only mapping metadata for the app and may be null when no link exists. No CSS, JavaScript, layout options, redirects or patient details are returned.</p><p>Copy submission.body and fill its answers. Text/date/email answers use answer. Choice answers use the complete answers array with value and selected; an enabled other option uses other.selected and other.value. Keep section/question order and metadata unchanged. Do not send custom_field_token or patient_field_connection back in content; the server rebuilds those values from the current Cliniko template. The server rechecks required answers, options and configured rules, and rebuilds metadata itself. Date answers use YYYY-MM-DD.</p><p>Manual scheduling requires practitioner_id and appointment_start. Automatic next_available requires patient: {} and selects the next slot on the server at submission time. Reading a structure does not reserve a slot or create Cliniko records. Service, form, gateway and price cannot be overridden by the app.</p><p>Errors: 404 unavailable/disabled/deleted/guest/unpublished source; 409 booking_definition_changed (reload the structure); 400 invalid selection or unknown request fields; 422 invalid_booking_answers, unsupported_booking_question, invalid_booking_options, invalid payment configuration or no automatic slot; 503 upstream unavailable. All usual token/scope/limit errors still apply. A successful submission returns the usual attempt token and payment requirements; later attempt routes require both patient and booking credentials.</p></div>';
                }
                if ($endpoint['group'] === 'onboarding') {
                    echo '<div class="cliniko-api-access-details"><h4>How patient onboarding works</h4><p>This feature runs after the patient has signed in. The app first calls <code>GET /patient-onboarding/{slug}</code> with the normal patient Bearer token. The response is the app screen contract: steps, field names, labels, types, required flags, validation rules, current values, missing fields and a definition revision. For native dropdown fields, <code>validation.options</code> contains the possible values to display and submit. It contains no HTML or presentation instructions.</p><p>If <code>complete</code> is false, call <code>POST /patient-onboarding/{slug}/session</code>. The server returns a short-lived onboarding token bound to the patient, the approved app and this exact definition revision. Put it in <code>X-ES-Onboarding-Token</code> only on the completion request; do not store it as a long-term login token.</p><p>Submit <code>POST /patient-onboarding/{slug}/complete</code> with the same Bearer token, the onboarding header, the revision and <code>{ data: { field: value } }</code>. The server rejects unknown fields, rechecks rules, updates only the linked Cliniko patient and returns the refreshed schema. Existing Cliniko values count as complete, so patients do not repeat onboarding. If staff change required fields, the revision changes and the app completes only the newly missing fields.</p><p>If a submission fails validation, HTTP 422 <code>onboarding_validation_failed</code> includes <code>error.fields</code> entries with the field key, configured label, short error code and correction detail. The app can show these beside the matching inputs without reproducing the rules locally.</p><p>If booking enforcement is enabled in Patient onboarding, <code>POST /me/booking-attempts</code> and booking-flow creation return <code>403 onboarding_required</code> with the onboarding route until the profile is complete. Reading appointments and the profile is not blocked. Email changes remain a separate email-confirmation operation. Never trust a client-side completed flag or accept a patient ID from the app.</p><p>Common errors: <code>403 invalid_onboarding_token</code> or <code>onboarding_required</code>; <code>409 onboarding_definition_changed</code> (reload schema and start again); <code>422 onboarding_validation_failed</code>; <code>503 onboarding_update_failed</code>.</p></div>';
                }
                if ($endpoint['key'] === 'me') {
                    echo '<div class="cliniko-api-access-details"><h4>Onboarding controls the /me response</h4><p>When a patient onboarding scheme is published, its configured profile fields become the allowlist and shape of <code>GET /me</code>. The values are returned at the top level using the same field keys as the onboarding form. Fields that are not selected in onboarding are omitted from <code>/me</code>, so the frontend can use the onboarding schema as the source of truth for its profile screen. If no onboarding scheme is published, the API returns the standard safe profile fallback.</p></div>';
                }
                echo '<h4>Request fields</h4><table class="widefat striped"><thead><tr><th>Field</th><th>Location</th><th>Type</th><th>Required</th><th>Rules</th></tr></thead><tbody>';
                foreach ($endpoint['fields'] as $name => $field) {
                    $schema = $field['schema'];
                    echo '<tr><td><code>' . esc_html($name) . '</code></td><td>' . esc_html($field['in']) . '</td><td>' . esc_html($schema['type']) . '</td><td>' . (!empty($schema['required']) ? 'Yes' : 'No') . '</td><td>' . esc_html($schema['description'] ?? '') . '<br><code>' . esc_html(wp_json_encode(array_diff_key($schema, array_flip(['description', 'type', 'required', 'properties'])))) . '</code></td></tr>';
                }
                if ($endpoint['fields'] === []) echo '<tr><td colspan="5">No input fields.</td></tr>';
                echo '</tbody></table>';
                if (isset($endpoint['fields']['patient'])) { echo '<h4>Patient object schema</h4>'; $patientSchema = EndpointCatalog::patientSchema(false); if ($endpoint['key'] === 'booking_flow_create') $patientSchema['required'] = []; self::json($patientSchema); }
                if ($endpoint['example'] !== []) { echo '<h4>Example JSON request</h4>'; self::json($endpoint['example']); }
                echo '<h4>Success response schema</h4>'; self::json($endpoint['response_schema']);
                echo '<h4>Example success response (' . (int) ($endpoint['success_status'] ?? 200) . ')</h4>'; self::json(['ok' => true, 'data' => $endpoint['response'], 'meta' => ['request_id' => 'example-request-id']]);
                echo '<h4>Example error response</h4>'; self::json(['ok' => false, 'error' => ['code' => 'insufficient_scope', 'message' => 'The token does not grant this operation.', 'fields' => (object) []], 'meta' => ['request_id' => 'example-request-id']]);
                echo '</div></details>';
            }
            echo '</section>';
        }
        echo '<p>Legacy /v1 and /v2 website routes are separate contracts. Their unsafe retired operations are not available through this module. This catalog describes the managed API only.</p>';
    }

    private static function json(array $value): void { echo '<pre>' . esc_html(wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'; }

    private static function audit(): void
    {
        echo '<h2>Recent audit events</h2><p>Last 100 events. Only operation IDs, local client/user IDs, response statuses and generated request IDs are stored. No tokens, request bodies, medical answers or patient contact information are logged here.</p><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Event</th><th>Operation</th><th>Client</th><th>User</th><th>Status</th><th>Request ID</th></tr></thead><tbody>';
        foreach (ApiAudit::recent() as $row) {
            echo '<tr>';
            foreach (['created_at', 'event', 'operation', 'client_id', 'user_id', 'status', 'request_id'] as $key) echo '<td>' . esc_html((string) $row[$key]) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
