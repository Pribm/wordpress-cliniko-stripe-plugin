<?php
declare(strict_types=1);

define('CLINIKO_API_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/managed_api_test.php';

use App\Service\Api\BookingFlows;
use App\Service\Api\ApiSettings;
use App\Service\Api\ApiClients;
use App\Service\Api\EndpointCatalog;
use App\Service\PatientBookingConfiguration;
use App\Service\BookingFormStructure;
use App\Authorization\Api\AccessPolicy;
use App\Controller\Api\ManagedController;
use App\Admin\Modules\ApiAccess\ApiAccess;
use App\Admin\Modules\ApiAccess\BookingFlowDeveloperBundle;

function flowFixture(): array {
    enableApi();
    $questions = [
        ['name' => 'Symptoms', 'type' => 'text', 'required' => true, 'custom_field_token' => 'ef4c43ec-105d-489c-88cc-0f3c5f67dc70', 'patient_field_connection' => 'custom_field'],
        ['name' => 'Colour', 'type' => 'text', 'required' => true],
        ['name' => 'Count', 'type' => 'text', 'required' => true],
        ['name' => 'Reference', 'type' => 'text', 'required' => true],
        ['name' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'Date', 'type' => 'date', 'required' => true],
        ['name' => 'One choice', 'type' => 'radiobuttons', 'required' => true, 'answers' => [['value' => 'Yes'], ['value' => 'No']], 'other' => ['enabled' => true]],
        ['name' => 'Several choices', 'type' => 'checkboxes', 'required' => true, 'answers' => [['value' => 'A'], ['value' => 'B']]],
    ];
    $GLOBALS['cliniko_pages']['appointment_types/123'] = ['id' => '123', 'name' => 'Consultation', 'duration_in_minutes' => 30];
    $GLOBALS['cliniko_pages']['patient_form_templates/456'] = ['id' => '456', 'name' => 'Intake', 'content' => ['sections' => [['name' => 'Details', 'description' => 'Required information', 'questions' => $questions]]]];
    $config = ['name' => 'Patient booking', 'booking_mode' => 'patient', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'show_calendar' => 'yes',
        'multistep' => 'yes', 'custom_js' => 'PRIVATE_SCRIPT', 'custom_css' => 'PRIVATE_STYLES', 'success_redirect_url' => 'https://private.example.test', 'input_rules' => [
            'question_0_0' => ['max_length' => 5], 'question_0_1' => ['input_type' => 'select', 'select_options' => ['Red', 'Blue']], 'question_0_2' => ['input_format' => 'numbers'],
            'question_0_3' => ['input_format' => 'pattern', 'mask_pattern' => 'AA-##', 'mask_prefix' => 'X'],
        ]];
    update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $config, 'guest-setup' => array_replace($config, ['booking_mode' => 'guest'])]);
    check(BookingFlows::save('initial-consultation', 'patient-setup', true) === true, 'Fixture publication failed');
    return $config;
}

function validFlowBody(): array {
    $context = BookingFlows::context('initial-consultation'); check(!is_wp_error($context), 'Fixture structure failed');
    $body = $context['definition']['submission']['body'];
    $body['patient'] = ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00+11:00'];
    foreach (['Pain', 'Blue', '123', 'XAB-12', 'new@example.test', '2026-11-01'] as $qi => $value) $body['content']['sections'][0]['questions'][$qi]['answer'] = $value;
    $body['content']['sections'][0]['questions'][6]['answers'][0]['selected'] = true;
    $body['content']['sections'][0]['questions'][7]['answers'][0]['selected'] = true;
    return $body;
}

$tests = [
    'publication stores only links and never auto-publishes guest or unapproved resources' => function (): void {
        $config = flowFixture();
        check(BookingFlows::all()['initial-consultation'] === ['flow_type' => 'web', 'configuration_id' => 'patient-setup', 'enabled' => true], 'Legacy publication lost its website origin');
        check(is_wp_error(BookingFlows::save('guest-flow', 'guest-setup', true)), 'Guest setup published');
        check(is_wp_error(BookingFlows::save('../invalid', 'patient-setup', true)), 'Unsafe identifier accepted');
        $s = ApiSettings::get(); $s['form_templates'] = []; update_option(ApiSettings::OPTION, $s);
        check(is_wp_error(BookingFlows::save('another-flow', 'patient-setup', true)), 'Unpublished template enabled');
        check(BookingFlows::save('draft-flow', 'patient-setup', false) === true, 'Disabled draft should be allowed');
        check(BookingFlows::context('draft-flow')->get_error_code() === 'booking_flow_not_found', 'Disabled flow disclosed');
        check(BookingFlows::context('initial-consultation')->get_error_code() === 'booking_flow_not_found', 'Removed publication allowlist ignored');
        BookingFlows::remove('initial-consultation');
        check(PatientBookingConfiguration::all()['patient-setup'] === $config, 'Removing API link changed the source');
    },
    'GET returns only structure and requires a verified patient and booking-read scope' => function (): void {
        flowFixture(); $request = oauthRequest(); $request->set_url_params(['slug' => 'initial-consultation']);
        $policy = new AccessPolicy(); $endpoint = endpoint('booking_flow');
        check($policy->authorize($request, $endpoint) === true, 'Verified patient structure denied');
        $response = (new ManagedController())->dispatch($request, $endpoint);
        check($response->get_status() === 200, 'Structure response failed'); $data = $response->get_data()['data'];
        check(array_keys($data) === ['identifier', 'appointment_type', 'form', 'scheduling', 'payment', 'definition_revision', 'submission', 'resources'], 'Unexpected top-level presentation or identity data');
        $json = json_encode($data);
        foreach (['PRIVATE_SCRIPT', 'PRIVATE_STYLES', 'private.example.test', 'multistep', 'show_calendar', 'input_type', 'date_format', 'custom_css', 'custom_js', 'alex@example.test', 'medicare'] as $secret) check(!str_contains($json, $secret), 'Forbidden response field: ' . $secret);
        check($data['form']['sections'][0]['questions'][0]['validation']['max_length'] === 5, 'Saved rule not shared');
        check($data['form']['sections'][0]['questions'][0]['custom_field_token'] === 'ef4c43ec-105d-489c-88cc-0f3c5f67dc70', 'Cliniko custom field token was dropped');
        check($data['form']['sections'][0]['questions'][0]['patient_field_connection'] === 'custom_field', 'Cliniko patient field connection was dropped');
        check($data['form']['sections'][0]['questions'][1]['custom_field_token'] === null && $data['form']['sections'][0]['questions'][1]['patient_field_connection'] === null, 'Unlinked question metadata is not explicit');
        check($data['form']['sections'][0]['questions'][1]['validation']['enum'] === ['Red', 'Blue'], 'Saved dropdown constraints missing');
        check($data['form']['sections'][0]['questions'][3]['validation']['pattern'] === '^X[A-Za-z][A-Za-z]\-[0-9][0-9]$', 'Shared mask constraint differs');
        check(get_current_user_id() === 0, 'GET leaked user context');
        $request->set_header('authorization', ''); check($policy->authorize($request, $endpoint)->get_error_data()['status'] === 401, 'Anonymous structure accepted');
        $server = ApiClients::create('Metadata', 'server', '', ['catalog:read'], time() + 3600); $request->set_header('authorization', 'Bearer ' . $server['secret']);
        check($policy->authorize($request, $endpoint)->get_error_code() === 'patient_required', 'Server key opened structure');
        $request->set_header('authorization', 'Bearer opaque-test-token'); $GLOBALS['claims']['scope'] = 'patient:read';
        check($policy->authorize($request, $endpoint)->get_error_code() === 'insufficient_scope', 'Missing booking-read scope accepted');
        foreach ($GLOBALS['cliniko_calls'] as $url) check(!str_starts_with($url, 'patients'), 'Structure fetched patient content');
    },
    'submission resolves authoritative setup and validates answers before booking writes' => function (): void {
        flowFixture(); $body = validFlowBody();
        $prepared = BookingFlows::prepare('initial-consultation', $body);
        check(!is_wp_error($prepared) && $prepared['moduleId'] === '123' && $prepared['patient_form_template_id'] === '456' && $prepared['gateway'] === 'free', 'Authoritative preparation failed');
        foreach ([0 => '', 1 => 'Purple', 2 => 'abc', 3 => 'INVALID', 4 => 'not-email', 5 => '2026-02-31'] as $qi => $bad) {
            $invalid = $body; $invalid['content']['sections'][0]['questions'][$qi]['answer'] = $bad;
            check(BookingFlows::prepare('initial-consultation', $invalid)->get_error_code() === 'invalid_booking_answers', 'Invalid scalar answer accepted: ' . $qi);
        }
        $invalid = $body; $invalid['content']['sections'][0]['questions'][0]['answer'] = '123456';
        check(is_wp_error(BookingFlows::prepare('initial-consultation', $invalid)), 'Max length bypassed');
        $invalid = $body; $invalid['content']['sections'][0]['questions'][0]['required'] = false;
        check(is_wp_error(BookingFlows::prepare('initial-consultation', $invalid)), 'Client required flag trusted');
        $invalid = $body; $invalid['content']['sections'][0]['questions'][6]['answers'][1]['selected'] = true;
        check(is_wp_error(BookingFlows::prepare('initial-consultation', $invalid)), 'Multiple radio answers accepted');
        $invalid = $body; $invalid['content']['sections'][0]['questions'][6]['answers'][0]['value'] = 'Forged';
        check(is_wp_error(BookingFlows::prepare('initial-consultation', $invalid)), 'Forged choice accepted');
        $invalid = $body; $invalid['content']['sections'][0]['questions'][7]['answers'][0]['selected'] = false;
        check(is_wp_error(BookingFlows::prepare('initial-consultation', $invalid)), 'Missing required choice accepted');
        $invalid = $body; $invalid['patient']['email'] = 'other@example.test';
        check(BookingFlows::prepare('initial-consultation', $invalid)->get_error_code() === 'invalid_booking_selection', 'Patient identity override accepted');
        $auth = oauthRequest(); $request = new WP_REST_Request('POST'); $request->set_header('authorization', $auth->get_header('authorization')); $request->set_body(json_encode($body + ['moduleId' => '999', 'gateway' => 'free'])); $request->set_url_params(['slug' => 'initial-consultation']);
        check((new AccessPolicy())->authorize($request, endpoint('booking_flow_create')) === true, 'Submission policy failed');
        check((new ManagedController())->dispatch($request, endpoint('booking_flow_create'))->get_status() === 400, 'Configuration override accepted at route boundary');
    },
    'live source changes invalidate revisions and deleted or guest sources fail closed' => function (): void {
        $config = flowFixture(); $body = validFlowBody();
        $cosmetic = $config; $cosmetic['custom_css'] = 'CHANGED_STYLE'; $cosmetic['multistep'] = 'no';
        update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $cosmetic]);
        check(BookingFlows::context('initial-consultation')['definition']['definition_revision'] === $body['definition_revision'], 'Presentation-only edits changed the structural contract');
        $config['input_rules']['question_0_0']['max_length'] = 4; update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $config]);
        check(BookingFlows::prepare('initial-consultation', $body)->get_error_code() === 'booking_definition_changed', 'Stale revision accepted');
        $config['booking_mode'] = 'guest'; update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $config]);
        check(BookingFlows::context('initial-consultation')->get_error_code() === 'booking_flow_not_found', 'Mode change reopened guests');
        update_option(PatientBookingConfiguration::OPTION, []);
        check(BookingFlows::context('initial-consultation')->get_error_code() === 'booking_flow_not_found', 'Deleted source returned');
    },
    'template updates invalidate structure and unsupported questions never get a writable contract' => function (): void {
        flowFixture(); $body = validFlowBody();
        $GLOBALS['cliniko_pages']['patient_form_templates/456']['content']['sections'][0]['questions'][0]['name'] = 'Changed';
        check(BookingFlows::prepare('initial-consultation', $body)->get_error_code() === 'booking_definition_changed', 'Template mutation ignored');
        $GLOBALS['cliniko_pages']['patient_form_templates/456']['content']['sections'][0]['questions'][0]['type'] = 'signature';
        check(BookingFlows::context('initial-consultation')->get_error_code() === 'unsupported_booking_question', 'Unsupported signature exposed');
    },
    'automatic scheduling shares next-slot policy and refuses client scheduling overrides' => function (): void {
        $config = flowFixture(); $config['show_calendar'] = 'no';
        update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $config]);
        $body = validFlowBody(); $body['patient'] = [];
        $definition = BookingFlows::context('initial-consultation')['definition'];
        check($definition['scheduling'] === ['mode' => 'next_available', 'required_fields' => []], 'Automatic mode differs from shortcode policy');
        check((array) $definition['submission']['body']['patient'] === [], 'Automatic structure asks for client identity or scheduling');
        $override = $body; $override['patient'] = ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00Z'];
        check(BookingFlows::prepare('initial-consultation', $override)->get_error_code() === 'invalid_booking_selection', 'Automatic selection override accepted');
        $GLOBALS['cliniko_pages']['appointment_types/123']['practitioners'] = ['links' => ['self' => 'fixture-practitioners']];
        $GLOBALS['cliniko_pages']['fixture-practitioners'] = ['practitioners' => [['id' => '789', 'first_name' => 'Doctor', 'last_name' => 'Example', 'active' => true, 'show_in_online_bookings' => true, 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z']]];
        update_option('wp_cliniko_business_id', 'clinic');
        $from = new DateTimeImmutable('now', new DateTimeZone('UTC')); $to = $from->add(new DateInterval('P90D'));
        $query = http_build_query(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        $GLOBALS['cliniko_pages']['businesses/clinic/practitioners/789/appointment_types/123/next_available_time?' . $query] = ['appointment_start' => '2026-11-01T10:00:00Z'];
        $prepared = BookingFlows::prepare('initial-consultation', $body);
        check(!is_wp_error($prepared) && $prepared['patient'] === ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00Z'], 'Shared automatic scheduling did not prepare the selected slot');
    },
    'flow dashboard creates API-only definitions and labels legacy sources; docs are read-only' => function (): void {
        flowFixture(); $GLOBALS['cliniko_calls'] = []; $GLOBALS['current_user'] = 7; $_GET['tab'] = 'booking-flows';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Create API booking flow') && str_contains($html, 'Legacy website-linked flow') && str_contains($html, 'initial-consultation') && str_contains($html, 'patient-setup'), 'API-only UI or legacy origin missing');
        check(!str_contains($html, 'value="guest-setup"'), 'Guest setup shown as eligible');
        check(str_contains($html, 'cliniko_api_booking_flow') && str_contains($html, '_wpnonce') && !str_contains($html, 'name="configuration_id"'), 'New API flow still requires a shortcode or lacks form protection');
        foreach ($GLOBALS['cliniko_calls'] as $call) check(!str_starts_with($call, 'patients'), 'Editor loaded patient records');
        $GLOBALS['cliniko_calls'] = []; $_GET['tab'] = 'documentation'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check($GLOBALS['cliniko_calls'] === [], 'Endpoint documentation contacted Cliniko');
        foreach (['Published booking structures', '/booking-flows/{slug}', 'Structure-only booking contract', 'definition_revision', 'other_allowed', 'booking_definition_changed', 'Your configured identifiers', '/booking-flows/initial-consultation'] as $term) check(str_contains($html, $term), 'Endpoint documentation missing ' . $term);
        $export = (array) EndpointCatalog::openApi()['paths'];
        check($export['/booking-flows/{slug}']['get']['security'] === [['oauthBearer' => []]], 'Read structure is not bearer protected in export');
        check($export['/booking-flows/{slug}/booking-attempts']['post']['requestBody']['content']['application/json']['schema']['properties']['patient']['required'] === [], 'Automatic scheduling request schema incorrect');
    },
    'API-only registration stores minimal marked settings and never creates a web shortcode' => function (): void {
        $source = flowFixture();
        $input = ['name' => 'App consultation', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'scheduling_mode' => 'manual'];
        $web = PatientBookingConfiguration::all();
        check(BookingFlows::saveApi('app-consultation', $input, false) === true, 'Independent draft registration failed');
        $saved = BookingFlows::all()['app-consultation'];
        check($saved['flow_type'] === 'api' && $saved['configuration']['flow_type'] === 'api' && $saved['configuration']['booking_mode'] === 'patient', 'API origin or patient mode not recorded');
        check(array_keys($saved['configuration']) === ['flow_type', 'booking_mode', 'name', 'appointment_type_id', 'patient_form_template_id', 'gateway', 'scheduling_mode', 'input_rules'], 'API definition includes unnecessary presentation');
        check(PatientBookingConfiguration::all() === $web && !isset(PatientBookingConfiguration::patients()['app-consultation']), 'API registration modified web forms');
        check(BookingFlows::context('app-consultation')->get_error_code() === 'booking_flow_not_found', 'Draft exposed');
        check(BookingFlows::saveApi('app-consultation', $input, true) === true, 'Independent publication failed');
        update_option(PatientBookingConfiguration::OPTION, []);
        $context = BookingFlows::context('app-consultation');
        check(!is_wp_error($context) && $context['definition']['scheduling']['mode'] === 'manual', 'API-only flow needs a website form');
        foreach (['custom_js', 'success_redirect_url', 'multistep', 'show_patient_details_review', 'booking_mode'] as $field) check(is_wp_error(BookingFlows::saveApi('app-consultation', $input + [$field => 'anything'], true)), 'Presentation or identity field accepted: ' . $field);
        foreach (['appointment_type_id' => 'x123', 'patient_form_template_id' => '0', 'gateway' => 'free', 'scheduling_mode' => 'invalid'] as $field => $value) check(is_wp_error(BookingFlows::saveApi('app-consultation', array_replace($input, [$field => $value]), true)), 'Invalid API setting accepted: ' . $field);
        $settings = ApiSettings::get(); $settings['form_templates'] = []; update_option(ApiSettings::OPTION, $settings);
        check(is_wp_error(BookingFlows::saveApi('app-consultation', $input, true)), 'Resource publication requirement bypassed');
        check(BookingFlows::all()['app-consultation']['enabled'] === true, 'Rejected edit mutated the saved definition');
        check(BookingFlows::context('app-consultation')->get_error_code() === 'booking_flow_not_found', 'Unpublished API-only resources exposed');
        BookingFlows::remove('app-consultation'); check(!isset(BookingFlows::all()['app-consultation']) && PatientBookingConfiguration::all() === [], 'Removal changed web storage');
    },
    'explicit detachment preserves web setup, identifier and checks but breaks future coupling' => function (): void {
        $source = flowFixture(); $body = validFlowBody();
        $web = PatientBookingConfiguration::all();
        check(BookingFlows::makeApiOnly('initial-consultation') === true, 'Make API-only failed');
        check(PatientBookingConfiguration::all() === $web, 'Conversion modified the original website form');
        $saved = BookingFlows::all()['initial-consultation'];
        check($saved['flow_type'] === 'api' && !isset($saved['configuration_id']), 'Conversion still links to website storage');
        check(!isset($saved['configuration']['custom_js'], $saved['configuration']['show_calendar'], $saved['configuration']['multistep']), 'Conversion retained web settings');
        check(BookingFlows::prepare('initial-consultation', $body)->get_error_code() === 'booking_definition_changed', 'Conversion did not invalidate the prior contract');
        update_option(PatientBookingConfiguration::OPTION, []);
        $body = validFlowBody();
        check(!is_wp_error(BookingFlows::prepare('initial-consultation', $body)), 'Copied shared rules or independent execution broken');
        check(BookingFlows::context('initial-consultation')['definition']['form']['sections'][0]['questions'][0]['validation']['max_length'] === 5, 'Copied answer constraint lost');
        check(is_wp_error(BookingFlows::save('initial-consultation', 'patient-setup', true)), 'Independent flow relinked');
        check(is_wp_error(BookingFlows::makeApiOnly('initial-consultation')), 'Repeated conversion permitted');
        $auto = $source; $auto['show_calendar'] = 'no'; update_option(PatientBookingConfiguration::OPTION, ['automatic' => $auto]);
        check(BookingFlows::save('automatic-web', 'automatic', true) === true && BookingFlows::makeApiOnly('automatic-web') === true, 'Automatic conversion failed');
        check(BookingFlows::context('automatic-web')['definition']['scheduling']['mode'] === 'next_available', 'Web calendar policy not mapped to API scheduling');
    },
    'API answer checks reuse shared normalization, reject tampering and reset on template change' => function (): void {
        flowFixture();
        $input = ['name' => 'App booking', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'scheduling_mode' => 'manual', 'rules_template_id' => '456', 'input_rules' => ['question_0_0' => ['max_length' => 4], 'question_0_1' => ['input_type' => 'select', 'select_options' => "Red\nBlue"]]];
        check(BookingFlows::saveApi('app-booking', $input, true) === true, 'API rule save failed');
        $context = BookingFlows::context('app-booking');
        check($context['definition']['form']['sections'][0]['questions'][0]['validation']['max_length'] === 4 && $context['definition']['form']['sections'][0]['questions'][1]['validation']['enum'] === ['Red', 'Blue'], 'API rules do not match shared validation');
        $before = BookingFlows::all()['app-booking'];
        $bad = $input; $bad['input_rules']['patient_email'] = ['max_length' => 1];
        check(is_wp_error(BookingFlows::saveApi('app-booking', $bad, true)), 'Patient identity rule accepted');
        $bad = $input; $bad['input_rules']['question_0_0']['custom_js'] = 'x';
        check(is_wp_error(BookingFlows::saveApi('app-booking', $bad, true)), 'Code accepted in answer rule');
        $bad = $input; $bad['input_rules']['question_0_0']['max_length'] = ['bad'];
        check(is_wp_error(BookingFlows::saveApi('app-booking', $bad, true)), 'Malformed scalar rule accepted');
        check(BookingFlows::all()['app-booking'] === $before, 'Rejected rule save changed the flow');
        $GLOBALS['cliniko_pages']['patient_form_templates/457'] = ['id' => '457', 'name' => 'Different form', 'content' => ['sections' => [['name' => 'New', 'questions' => [['name' => 'Different question', 'type' => 'text', 'required' => true]]]]]];
        $settings = ApiSettings::get(); $settings['form_templates'][] = '457'; update_option(ApiSettings::OPTION, $settings);
        $input['patient_form_template_id'] = '457';
        check(BookingFlows::saveApi('app-booking', $input, true) === true, 'Template replacement failed');
        check(BookingFlows::all()['app-booking']['configuration']['input_rules'] === [], 'Old question-index rules applied to a different form');
        $input = array_diff_key($input, array_flip(['input_rules', 'rules_template_id'])); $input['scheduling_mode'] = 'next_available';
        check(BookingFlows::saveApi('app-booking', $input, true) === true && BookingFlows::context('app-booking')['definition']['scheduling']['mode'] === 'next_available', 'API scheduling depends on a calendar presentation flag');
        $_GET = ['tab' => 'booking-flows', 'section' => 'app-booking']; $GLOBALS['current_user'] = 7;
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Edit API booking flow') && str_contains($html, 'Different question') && str_contains($html, 'name="rules_template_id"'), 'Question editor missing');
        foreach (['name="multistep"', 'name="custom_js"', 'name="success_message"', 'name="failure_message"', 'name="show_patient_details_review"', 'name="date_format"'] as $field) check(!str_contains($html, $field), 'API editor includes web presentation: ' . $field);
    },
    'outages, archived templates and corrupt origins fail closed; disabling needs no upstream call' => function (): void {
        flowFixture();
        $input = ['name' => 'API booking', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'scheduling_mode' => 'manual'];
        check(BookingFlows::saveApi('safe-app-flow', $input, true) === true, 'API fixture failed');
        $before = BookingFlows::all()['safe-app-flow'];
        unset($GLOBALS['cliniko_pages']['patient_form_templates/456']);
        check(is_wp_error(BookingFlows::saveApi('safe-app-flow', $input, true)) && BookingFlows::all()['safe-app-flow'] === $before, 'Upstream failure overwrote enabled settings');
        $rules = $input + ['input_rules' => ['question_0_0' => ['max_length' => 3]], 'rules_template_id' => '456'];
        check(is_wp_error(BookingFlows::saveApi('safe-app-flow', $rules, false)) && BookingFlows::all()['safe-app-flow'] === $before, 'Unverifiable answer checks were saved');
        $GLOBALS['cliniko_calls'] = [];
        check(BookingFlows::disable('safe-app-flow') === true && $GLOBALS['cliniko_calls'] === [], 'Emergency disable contacted Cliniko');
        check(BookingFlows::context('safe-app-flow')->get_error_code() === 'booking_flow_not_found', 'Disabled flow exposed');
        $stored = BookingFlows::all(); $stored['safe-app-flow'] = $before; $stored['safe-app-flow']['configuration']['booking_mode'] = 'guest'; update_option(BookingFlows::OPTION, $stored);
        check(BookingFlows::context('safe-app-flow')->get_error_code() === 'booking_flow_not_found', 'Corrupt guest definition accepted');
        $stored['safe-app-flow'] = $before; $stored['safe-app-flow']['flow_type'] = 'other'; update_option(BookingFlows::OPTION, $stored);
        check(BookingFlows::context('safe-app-flow')->get_error_code() === 'booking_flow_not_found', 'Unknown origin treated as API');
        $stored['safe-app-flow'] = $before; update_option(BookingFlows::OPTION, $stored);
        $GLOBALS['cliniko_pages']['patient_form_templates/456'] = ['id' => '456', 'name' => 'Archived', 'archived_at' => '2026-01-01T00:00:00Z'];
        check(is_wp_error(BookingFlows::saveApi('safe-app-flow', $input, true)), 'Archived template enabled');
        update_option(BookingFlows::OPTION, ['old-link' => ['configuration_id' => 'patient-setup', 'enabled' => false]]);
        check(BookingFlows::all()['old-link']['flow_type'] === 'web' && !isset(get_option(BookingFlows::OPTION)['old-link']['flow_type']), 'Reading an old link changed storage');
        $GLOBALS['current_user'] = 0; $_POST = ['operation' => 'remove', 'flow_slug' => 'old-link'];
        try { ApiAccess::saveBookingFlow(); throw new RuntimeException('Non-admin save accepted'); }
        catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'permission'), 'Unexpected admin authorization failure'); }
        check(isset(BookingFlows::all()['old-link']), 'Unauthorized action changed flows');
    },
    'compact flow details escape blank form content, share template reads and never change storage' => function (): void {
        flowFixture();
        check(BookingFlows::save('second-flow', 'patient-setup', false) === true, 'Second row fixture failed');
        $GLOBALS['cliniko_pages']['patient_form_templates/456']['content']['sections'][0]['questions'][0]['name'] = '<img src=x onerror=alert(1)>';
        $before = $GLOBALS['options']; $GLOBALS['cliniko_calls'] = []; $GLOBALS['current_user'] = 7; $_GET = ['tab' => 'booking-flows'];
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(substr_count($html, 'data-booking-flow-row') === 2 && substr_count($html, 'data-flow-appointment') === 2 && substr_count($html, 'data-flow-template') === 2, 'Rows or resource sub-accordions missing');
        check(str_contains($html, 'aria-expanded="true"') && str_contains($html, 'aria-controls="cliniko-api-booking-flow-initial-consultation"'), 'Progressively enhanced flow accessibility missing');
        check(!str_contains($html, '<img src=x') && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'), 'Form question was not escaped');
        check(count(array_filter($GLOBALS['cliniko_calls'], static fn($call): bool => $call === 'patient_form_templates/456')) === 1, 'Shared form template fetched repeatedly for multiple flows');
        check($GLOBALS['options'] === $before, 'Rendering accordion details changed saved settings');
        foreach ($GLOBALS['cliniko_calls'] as $call) check(!str_starts_with($call, 'patients') && !str_starts_with($call, 'patient_forms/'), 'Flow details fetched completed patient records');
        unset($GLOBALS['cliniko_pages']['patient_form_templates/456']);
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Form questions are unavailable') && str_contains($html, 'data-booking-flow-row'), 'Template outage broke the compact list');
    },
    'developer JSON contains only saved structural data and empty bodies without writes or credentials' => function (): void {
        $config = flowFixture();
        $GLOBALS['current_user'] = 7;
        check(BookingFlows::save('draft-flow', 'patient-setup', false) === true, 'Draft fixture failed');
        $automatic = $config; $automatic['show_calendar'] = 'no';
        update_option(PatientBookingConfiguration::OPTION, ['patient-setup' => $config, 'automatic-setup' => $automatic]);
        check(BookingFlows::save('automatic-flow', 'automatic-setup', false) === true, 'Automatic fixture failed');
        $settings = ApiSettings::get(); $settings['introspection_client'] = 'PRIVATE_CLIENT_ID'; update_option(ApiSettings::OPTION, $settings);
        update_option(ApiSettings::SECRET, 'PRIVATE_PROVIDER_PASSWORD');
        $GLOBALS['options']['wp_cliniko_api_patient_signing_key'] = 'PRIVATE_SIGNING_KEY';
        $label = '</textarea><script>window.unsafeDeveloperExport = true</script> & Cliniko';
        $GLOBALS['cliniko_pages']['patient_form_templates/456']['content']['sections'][0]['questions'][0]['name'] = $label;
        $before = $GLOBALS['options']; $GLOBALS['cliniko_calls'] = []; $_GET = ['tab' => 'booking-flows'];
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(preg_match('/<textarea[^>]+id="cliniko-api-booking-flow-json"[^>]*>(.*?)<\/textarea>/s', $html, $match) === 1, 'Developer JSON view missing');
        $json = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        check($bundle['format'] === 'cliniko-booking-flow-developer-bundle' && $bundle['format_version'] === 1 && $bundle['purpose'] === 'frontend_mocking_and_scaffolding', 'Bundle identity missing');
        check(count($bundle['flows']) === 3 && $bundle['flows'][1]['enabled'] === false, 'Disabled drafts excluded');
        $flow = $bundle['flows'][0];
        check($flow['preview_complete'] && $flow['form']['sections'][0]['questions'][0]['name'] === $label, 'Structural labels altered or lost');
        check($flow['form']['sections'][0]['questions'][0]['validation']['max_length'] === 5, 'Shared answer validation absent');
        check($flow['payment']['amount'] === null && $flow['payment']['required'] === null && $flow['payment']['configured_gateway'] === 'stripe', 'Unknown price was invented or interpreted as free');
        check(str_contains($flow['submission_template']['body']['definition_revision'], 'latest definition_revision'), 'Real-revision requirement missing');
        check($flow['endpoints']['read_structure']['required_scope'] === 'booking:read' && $flow['authentication']['verified_patient_required'], 'Protected access requirements missing');
        foreach (['PRIVATE_SCRIPT', 'PRIVATE_STYLES', 'private.example.test', 'PRIVATE_CLIENT_ID', 'PRIVATE_PROVIDER_PASSWORD', 'PRIVATE_SIGNING_KEY', 'alex@example.test', 'configuration_id', 'custom_js', 'custom_css', 'success_redirect_url', 'input_type', 'date_format'] as $value) check(!str_contains($json, $value), 'Excluded data was exported: ' . $value);
        foreach ($flow['submission_template']['body']['content']['sections'][0]['questions'] as $question) {
            if (isset($question['answer'])) check($question['answer'] === '', 'Completed answer exported');
            if (isset($question['answers'])) foreach ($question['answers'] as $answer) check($answer['selected'] === false, 'Choice answer preselected');
        }
        $automaticFlow = $bundle['flows'][2];
        check($automaticFlow['scheduling']['mode'] === 'next_available' && str_contains($json, '"patient": {}'), 'Automatic mock asks for client scheduling');
        check(!str_contains($html, '<script>window.unsafeDeveloperExport') && str_contains($html, 'data-copy-target="cliniko-api-booking-flow-json"'), 'Unsafe JSON embedding or missing copy control');
        check($GLOBALS['options'] === $before && count(array_filter($GLOBALS['cliniko_calls'], static fn($call): bool => $call === 'patient_form_templates/456')) === 1, 'Export changed settings or refetched the shared form');
        foreach ($GLOBALS['cliniko_calls'] as $call) check(!str_starts_with($call, 'patients') && !str_starts_with($call, 'patient_forms/'), 'Export fetched patient data');
    },
    'developer bundle reports unavailable structures, supports empty lists and rejects non-admins' => function (): void {
        $GLOBALS['current_user'] = 7;
        check(BookingFlowDeveloperBundle::build([], [], [], [])['flows'] === [], 'Empty list is not a JSON array');
        flowFixture(); $GLOBALS['current_user'] = 7;
        $beforeCalls = $GLOBALS['cliniko_calls'];
        $bundle = BookingFlowDeveloperBundle::build(BookingFlows::all(), [], [], []);
        check($bundle['flows'][0]['preview_complete'] === false && $bundle['flows'][0]['form'] === null && $bundle['flows'][0]['submission_template'] === null && count($bundle['flows'][0]['warnings']) >= 1, 'Incomplete form presented as usable');
        check($bundle['flows'][0]['form_template_reference']['id'] === '456' && $GLOBALS['cliniko_calls'] === $beforeCalls, 'Projection lost saved IDs or made network requests');
        $GLOBALS['current_user'] = 0;
        check(BookingFlowDeveloperBundle::build(BookingFlows::all(), [], [], [])->get_error_code() === 'developer_bundle_forbidden', 'Non-admin generated private draft list');
    },
];
$failed = 0;
foreach ($tests as $name => $test) {
    resetApi();
    try { $test(); echo "[PASS] {$name}\n"; }
    catch (Throwable $error) { $failed++; echo "[FAIL] {$name}: " . $error->getMessage() . "\n"; }
}
exit($failed ? 1 : 0);
