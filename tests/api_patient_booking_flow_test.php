<?php
declare(strict_types=1);

namespace App\Support {
    // Isolate identity resolution; route/token verification is exercised in managed_api_test.php.
    final class Auth {
        public static function patientData(): ?array { return $GLOBALS['api_booking_linked_patient'] ?? null; }
    }
}

namespace {
    define('CLINIKO_BOOKING_TEST_BOOTSTRAP_ONLY', true);
    define('DAY_IN_SECONDS', 86400);
    require __DIR__ . '/booking_attempt_flow_test.php';

    function get_current_user_id(): int { return $GLOBALS['api_booking_user'] ?? 0; }
    function wp_set_current_user(int $id): void { $GLOBALS['api_booking_user'] = $id; }
    function is_user_logged_in(): bool { return get_current_user_id() > 0; }
    function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }

    class WP_Error {
        public function __construct(private string $code, private string $message, private array $data = []) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): array { return $this->data; }
    }

    class WP_REST_Request {
        private array $params = [];
        private string $body = '';
        private array $urlParams = [];
        public function get_method(): string { return 'POST'; }
        public function get_body(): string { return $this->body; }
        public function get_url_params(): array { return $this->urlParams; }
        public function set_url_params(array $params): void { $this->urlParams = $params; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        public function set_param(string $key, $value): void { $this->params[$key] = $value; }
        public function set_body(string $body): void { $this->body = $body; $this->params = array_merge($this->params, json_decode($body, true)); }
    }
    class WP_REST_Response {
        public function __construct(private array $data, private int $status = 200) {}
        public function get_status(): int { return $this->status; }
        public function get_data(): array { return $this->data; }
    }

    $tests = [
        'controller refuses anonymous and cookie-only creation before any Cliniko call' => function (): void {
            $endpoint = array_values(array_filter(\App\Service\Api\EndpointCatalog::definitions(), static fn($item) => $item['key'] === 'patient_booking_create'))[0];
            $request = new WP_REST_Request(); $request->set_body(json_encode(base_payload()));
            wp_set_current_user(7);
            $response = (new \App\Controller\Api\ManagedController())->dispatch($request, $endpoint);
            assert_same(401, $response->get_status(), 'Cookie identity must not substitute for API authorization');
            assert_same([], $GLOBALS['__attempt_fake_client']->calls, 'Rejected creation must not touch Cliniko');
        },
        'authenticated API creation uses linked identity and persists original ownership without guest matching' => function (): void {
            $settings = array_replace(\App\Service\Api\ApiSettings::defaults(), ['enabled' => true, 'groups' => ['booking', 'patient_booking'], 'appointment_types' => ['222'], 'form_templates' => ['999']]);
            update_option(\App\Service\Api\ApiSettings::OPTION, $settings);
            $payload = base_payload();
            $GLOBALS['api_booking_linked_patient'] = $payload['patient'] + ['id' => 'patient_1'];
            $client = $GLOBALS['__attempt_fake_client']; $client->responses['GET patients/patient_1'] = patient_payload();
            $payload['patient'] = ['practitioner_id' => $payload['patient']['practitioner_id'], 'appointment_start' => $payload['patient']['appointment_start']];
            // Trusted policy identity is injected here; separate policy tests verify the actual credentials.
            $request = new WP_REST_Request(); $request->set_body(json_encode($payload));
            $request->set_param('_cliniko_api_user', 7); $request->set_param('_cliniko_api_client', 'approved-patient-app');
            $endpoint = array_values(array_filter(\App\Service\Api\EndpointCatalog::definitions(), static fn($item) => $item['key'] === 'patient_booking_create'))[0];
            $response = (new \App\Controller\Api\ManagedController())->dispatch($request, $endpoint);
            assert_same(200, $response->get_status(), 'Authenticated creation failed: ' . json_encode($response->get_data()));
            $data = $response->get_data()['data'];
            $attempt = (new \App\Service\BookingAttemptStore())->get($data['attempt']['id']);
            assert_same('account', $attempt['patient_mode'], 'Attempt must belong to an account');
            assert_same(7, $attempt['user_id'], 'Original WordPress patient not stored');
            assert_same('patient_1', $attempt['patient_id'], 'Linked Cliniko patient not stored');
            assert_same('approved-patient-app', $attempt['api_client_id'], 'Original application not stored');
            assert_same(\App\Service\Api\ApiSettings::namespace(), $attempt['api_namespace'], 'Managed namespace not stored');
            assert_same(false, $attempt['patient_was_created'], 'API booking must not create a guest patient');
            assert_same('john@example.com', $attempt['patient']['email'], 'Patient identity must come from the account');
            assert_same('patient_1', $client->requests['POST patient_forms'][0]['patient_id'], 'Form attached to wrong patient');
            assert_true(!in_array('POST patients', $client->calls, true), 'Guest patient creation called');
            assert_true(!in_array('PUT patients/patient_1', $client->calls, true), 'Booking overwrote profile');
            foreach ($client->calls as $call) assert_true(!str_starts_with($call, 'GET patients?'), 'Guest identity matching called');
            assert_same(0, get_current_user_id(), 'Controller leaked patient user context');
        },
        'API-only booking submission reaches the shared service without a web form and keeps patient ownership' => function (): void {
            $settings = array_replace(\App\Service\Api\ApiSettings::defaults(), ['enabled' => true, 'groups' => ['booking', 'patient_booking'], 'appointment_types' => ['222'], 'form_templates' => ['999']]);
            update_option(\App\Service\Api\ApiSettings::OPTION, $settings);
            update_option(\App\Service\PatientBookingConfiguration::OPTION, []);
            assert_same(true, \App\Service\Api\BookingFlows::saveApi('my-consultation', ['name' => 'App consultation', 'appointment_type_id' => '222', 'patient_form_template_id' => '999', 'gateway' => 'stripe', 'scheduling_mode' => 'manual'], true), 'Independent flow publication failed');
            assert_same([], \App\Service\PatientBookingConfiguration::all(), 'API flow created a web form');
            $payload = base_payload(); $GLOBALS['api_booking_linked_patient'] = $payload['patient'] + ['id' => 'patient_1'];
            $client = $GLOBALS['__attempt_fake_client']; $client->responses['GET patients/patient_1'] = patient_payload();
            $context = \App\Service\Api\BookingFlows::context('my-consultation');
            $body = $context['definition']['submission']['body'];
            $body['patient'] = array_intersect_key($payload['patient'], array_flip(['practitioner_id', 'appointment_start']));
            $request = new WP_REST_Request(); $request->set_body(json_encode($body)); $request->set_url_params(['slug' => 'my-consultation']);
            $request->set_param('_cliniko_api_user', 7); $request->set_param('_cliniko_api_client', 'approved-patient-app'); $request->set_param('slug', 'my-consultation');
            $endpoint = array_values(array_filter(\App\Service\Api\EndpointCatalog::definitions(), static fn($item) => $item['key'] === 'booking_flow_create'))[0];
            $response = (new \App\Controller\Api\ManagedController())->dispatch($request, $endpoint);
            assert_same(200, $response->get_status(), 'Flow submission failed: ' . json_encode($response->get_data()));
            $attempt = (new \App\Service\BookingAttemptStore())->get($response->get_data()['data']['attempt']['id']);
            assert_same('my-consultation', $attempt['api_booking_flow'], 'Flow identity not stored');
            assert_same('account', $attempt['patient_mode'], 'Flow opened guest mode');
            assert_same('patient_1', $attempt['patient_id'], 'Flow used wrong patient');
            assert_same('222', $attempt['module_id'], 'Flow used wrong service');
            assert_same('999', $attempt['patient_form_template_id'], 'Flow used wrong template');
            assert_same('approved-patient-app', $attempt['api_client_id'], 'Flow app binding missing');
            assert_true(!in_array('POST patients', $client->calls, true), 'Flow created guest patient');
            assert_same(0, get_current_user_id(), 'Flow controller leaked user context');
        },
        'authenticated service ignores caller identity even if a caller bypasses the input schema' => function (): void {
            $payload = base_payload(); $GLOBALS['api_booking_linked_patient'] = $payload['patient'] + ['id' => 'patient_1'];
            $payload['patient']['email'] = 'someone-else@example.test'; $payload['patient']['first_name'] = 'Someone';
            $payload['patient']['id'] = 'other-patient';
            $client = $GLOBALS['__attempt_fake_client']; $client->responses['GET patients/patient_1'] = patient_payload();
            wp_set_current_user(7);
            $result = (new \App\Service\BookingAttemptService())->preflightApiPatient($payload);
            assert_same(true, $result['ok'], 'Authenticated service preflight failed');
            $attempt = (new \App\Service\BookingAttemptStore())->get($result['attempt']['id']);
            assert_same('patient_1', $attempt['patient_id'], 'Submitted patient ID substituted linked identity');
            assert_same('john@example.com', $attempt['patient']['email'], 'Submitted email substituted linked identity');
            assert_same('John', $attempt['patient']['first_name'], 'Submitted name substituted linked identity');
            assert_true(!in_array('POST patients', $client->calls, true), 'Caller identity created a guest patient');
        },
    ];
    $failed = 0;
    foreach ($tests as $name => $test) {
        reset_state(); wp_set_current_user(0); $GLOBALS['api_booking_linked_patient'] = null;
        try { $test(); echo "[PASS] {$name}\n"; }
        catch (\Throwable $error) { $failed++; echo "[FAIL] {$name}: " . $error->getMessage() . "\n"; }
    }
    exit($failed ? 1 : 0);
}
