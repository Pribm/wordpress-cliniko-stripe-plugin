<?php

namespace App\Controller\Api;

use App\Controller\ClinikoController;
use App\Controller\TyroController;
use App\Exception\ApiException;
use App\Service\Api\ApiSettings;
use App\Service\Api\ApiIdempotency;
use App\Service\Api\EndpointCatalog;
use App\Service\Api\OperationLock;
use App\Service\Api\PatientAuthentication;
use App\Service\Api\BookingFlows;
use App\Service\Api\PatientOnboardingApi;
use App\Service\Api\PatientAccountSecurity;
use App\Service\BookingAttemptService;
use App\Service\BookingAttemptStore;
use App\Service\DashboardAppointmentService;
use App\Service\AppointmentCountService;
use App\Controller\PatientAttachmentController;
use App\Controller\PatientCommunicationController;
use App\Support\Auth;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class ManagedController
{
    public function dispatch(WP_REST_Request $request, array $endpoint)
    {
        $previous = get_current_user_id();
        $userId = (int) $request->get_param('_cliniko_api_user');
        $patientOperation = in_array($endpoint['auth'], ['patient', 'attempt'], true);
        if ($patientOperation && $userId <= 0) return self::error('authentication_required', 'Use a verified patient access token.', 401);
        if ($patientOperation) wp_set_current_user($userId);
        try {
            if (($endpoint['key'] ?? '') !== 'attachments_upload' && strlen($request->get_body()) > 262144) return self::error('payload_too_large', 'The maximum JSON body size is 256 KiB.', 413);
            if (in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true) && ($endpoint['key'] ?? '') !== 'attachments_upload') {
                $body = $request->get_body() === '' ? new \stdClass() : json_decode($request->get_body());
                if (!$body instanceof \stdClass) return self::error('invalid_json', 'Use a JSON object body.', 400);
                $allowed = [];
                foreach ($endpoint['fields'] as $name => $field) if ($field['in'] === 'body') $allowed[] = $name;
                if (array_diff(array_keys(get_object_vars($body)), $allowed)) return self::error('unknown_fields', 'The request contains unsupported JSON fields.', 400);
                foreach ($endpoint['fields'] as $name => $field) {
                    if ($field['in'] === 'body' && !empty($field['schema']['required']) && !property_exists($body, $name)) return self::error('json_field_required', 'Required JSON field: ' . $name . '.', 400);
                }
            }
            return $this->execute($request, $endpoint);
        } catch (ApiException $exception) {
            return self::error('cliniko_unavailable', 'Cliniko is temporarily unavailable.', 503);
        } catch (\Throwable $exception) {
            error_log('Cliniko managed API failure (' . $endpoint['key'] . '): ' . get_class($exception));
            return self::error('api_operation_failed', 'The operation could not be completed.', 503);
        } finally { if ($patientOperation) wp_set_current_user($previous); }
    }

    private function execute(WP_REST_Request $request, array $endpoint)
    {
        $key = $endpoint['key'];
        if ($endpoint['group'] === 'authentication') {
            $result = PatientAuthentication::dispatch($request, $key);
            if (is_wp_error($result)) return $result;
            return self::success($result, (int) ($endpoint['success_status'] ?? 200));
        }
        $settings = ApiSettings::get();
        $id = (string) ($request->get_url_params()['id'] ?? '');
        if ($key === 'status') return self::success(['version' => ApiSettings::VERSION, 'available' => true]);
        if ($key === 'capabilities') {
            $active = array_filter(EndpointCatalog::definitions(), static fn($item): bool => $item['group'] !== 'system' && EndpointCatalog::state($item) === 'Active');
            return self::success(['groups' => array_values(array_unique(array_column($active, 'group')))]);
        }
        if ($key === 'appointment_types') {
            $items = [];
            foreach ($settings['appointment_types'] as $typeId) {
                $sub = new WP_REST_Request('GET');
                $sub->set_param('appointment_type_id', $typeId);
                $response = (new ClinikoController())->getAppointmentTypeDetails($sub);
                if ($response->get_status() === 200) $items[] = $response->get_data()['data'];
            }
            return self::success(['items' => $items]);
        }
        if (in_array($key, ['appointment_type', 'practitioners', 'availability', 'template'], true)) {
            $published = $key === 'template' ? $settings['form_templates'] : $settings['appointment_types'];
            if (!in_array($id, $published, true)) return self::error('not_found', 'This resource is not published through the API.', 404);
            $sub = new WP_REST_Request('GET');
            $sub->set_param($key === 'template' ? 'patient_form_template_id' : 'appointment_type_id', $id);
            foreach (['from', 'to', 'page', 'per_page', 'practitioner_id'] as $param) if ($request->get_param($param) !== null) $sub->set_param($param, $request->get_param($param));
            if ($key === 'availability') {
                $from = new \DateTimeImmutable((string) $request->get_param('from'));
                $to = new \DateTimeImmutable((string) $request->get_param('to'));
                if ($to < $from || $to > $from->modify('+93 days')) return self::error('invalid_range', 'Use an ordered date range of no more than 93 days.', 400);
            }
            $methods = ['appointment_type' => 'getAppointmentTypeDetails', 'practitioners' => 'getPractitioners', 'availability' => 'getAvailableTimes', 'template' => 'getPatientFormTemplate'];
            return self::adapt((new ClinikoController())->{$methods[$key]}($sub));
        }
        if ($key === 'booking_flow') {
            $context = BookingFlows::context((string) ($request->get_url_params()['slug'] ?? ''));
            return $context instanceof WP_Error ? $context : self::success($context['definition']);
        }
        if ($key === 'booking_flows') {
            $result = BookingFlows::available();
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'onboarding_schema') {
            $result = PatientOnboardingApi::schema((string) ($request->get_url_params()['slug'] ?? ''), (int) $request->get_param('_cliniko_api_user'));
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'onboarding_session') {
            $result = PatientOnboardingApi::start((string) ($request->get_url_params()['slug'] ?? ''), (int) $request->get_param('_cliniko_api_user'), (string) $request->get_param('_cliniko_api_client'));
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'onboarding_complete') {
            $result = PatientOnboardingApi::complete((string) ($request->get_url_params()['slug'] ?? ''), (int) $request->get_param('_cliniko_api_user'), (string) $request->get_param('_cliniko_api_client'), trim((string) $request->get_header('x-es-onboarding-token')), (string) $request->get_param('definition_revision'), (array) $request->get_param('data'));
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if (in_array($key, ['patient_booking_create', 'booking_flow_create'], true)) {
            $body = [];
            foreach ($endpoint['fields'] as $name => $field) if ($field['in'] === 'body' && $request->get_param($name) !== null) $body[$name] = $request->get_param($name);
            if ($key === 'booking_flow_create') {
                $body = BookingFlows::prepare((string) ($request->get_url_params()['slug'] ?? ''), $body);
                if ($body instanceof WP_Error) return $body;
            }
            if (!in_array((string) $body['moduleId'], $settings['appointment_types'], true) || !in_array((string) $body['patient_form_template_id'], $settings['form_templates'], true)) return self::error('unpublished_booking', 'The appointment type or form template is not published.', 403);
            $service = new BookingAttemptService();
            $result = $service->preflightApiPatient($body);
            if (!empty($result['ok']) && !empty($result['attempt']['id'])) {
                (new BookingAttemptStore())->update($result['attempt']['id'], ['api_namespace' => ApiSettings::namespace(), 'api_client_id' => (string) $request->get_param('_cliniko_api_client')]);
                $result['attempt']['expires_in'] = DAY_IN_SECONDS;
                if ($key === 'booking_flow_create') (new BookingAttemptStore())->update($result['attempt']['id'], ['api_booking_flow' => (string) $request->get_param('slug')]);
            }
            return self::adaptResult($result);
        }
        if ($key === 'sdk_token') return self::adapt((new TyroController())->sdkToken($request));
        if ($key === 'booking_status') return self::adaptResult((new BookingAttemptService())->getStatus($id));
        if (in_array($key, ['stripe', 'tyro', 'finalize'], true)) {
            return (new OperationLock())->run($id, function () use ($request, $key, $id) {
                $store = new BookingAttemptStore();
                $attempt = $store->get($id);
                if (!$attempt || in_array($attempt['status'] ?? '', ['cleaned', 'failed'], true)) return self::error('attempt_unavailable', 'This attempt can no longer be processed.', 409);
                if (($attempt['status'] ?? '') !== 'completed' && (($attempt['status'] ?? '') === 'finalizing' || !empty($attempt['booking']['appointment_id']))) return self::error('attempt_needs_review', 'This attempt already started finalization. Check its status or contact the clinic.', 409);
                $service = new BookingAttemptService($store);
                if ($key === 'stripe') {
                    if (($attempt['gateway'] ?? '') !== 'stripe') return self::error('gateway_mismatch', 'This attempt uses another payment gateway.', 409);
                    return self::adaptResult($service->chargeStripe($id));
                }
                if ($key === 'tyro') {
                    if (($attempt['gateway'] ?? '') !== 'tyrohealth') return self::error('gateway_mismatch', 'This attempt uses another payment gateway.', 409);
                    return self::adaptResult($service->confirmTyroPayment($id, (string) $request->get_param('transactionId')));
                }
                $keyValue = (string) $request->get_header('idempotency-key');
                if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $keyValue)) return self::error('idempotency_key_required', 'Provide an 8–128 character Idempotency-Key header.', 400);
                $hash = hash_hmac('sha256', $keyValue, wp_salt('auth'));
                $identity = (string) $request->get_param('_cliniko_api_client') . ':patient:' . (int) $request->get_param('_cliniko_api_user');
                $claimed = ApiIdempotency::claim($identity, $keyValue, $id);
                if ($claimed instanceof WP_Error) return $claimed;
                if (!empty($attempt['api_finalize_key']) && !hash_equals($attempt['api_finalize_key'], $hash)) return self::error('idempotency_conflict', 'Retry using the original Idempotency-Key.', 409);
                $store->update($id, ['api_finalize_key' => $hash]);
                return self::adaptResult($service->finalize($id));
            });
        }
        if ($key === 'me') {
            $patient = Auth::patientData();
            if ($patient === null) return self::error('patient_not_found', 'The linked patient could not be found.', 404);
            $onboardingProfile = PatientOnboardingApi::profileForUser((int) $request->get_param('_cliniko_api_user'));
            $profile = ['id' => $patient['id'] ?? null];
            if ($onboardingProfile !== null) {
                // The active onboarding definition is the /me allowlist and
                // response shape. It is not nested under another property.
                $profile += $onboardingProfile;
            } else {
                $profile += array_intersect_key($patient, array_flip(['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'address_1', 'address_2', 'city', 'state', 'post_code', 'country']));
            }
            return self::success($profile);
        }
        if ($key === 'me_update') {
            $body = $request->get_json_params();
            $profileData = $body['data'] ?? null;
            if (!is_array($profileData) || array_is_list($profileData)) {
                return self::error('invalid_profile_data', 'Send profile updates inside a JSON object named data.', 400);
            }
            $result = PatientOnboardingApi::updateProfile((int) $request->get_param('_cliniko_api_user'), $profileData);
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'password_update') {
            $body = $request->get_json_params();
            $result = PatientAccountSecurity::updatePassword(
                (int) $request->get_param('_cliniko_api_user'),
                (string) ($body['current_password'] ?? ''),
                (string) ($body['new_password'] ?? '')
            );
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'account_closure_request') {
            $body = $request->get_json_params();
            $result = PatientAccountSecurity::requestClosure(
                (int) $request->get_param('_cliniko_api_user'),
                (bool) ($body['acknowledge'] ?? false),
                (string) ($body['return_url'] ?? '')
            );
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result, 202);
        }
        if ($key === 'account_closure_confirm') {
            $body = $request->get_json_params();
            $result = PatientAccountSecurity::confirmClosure(
                (int) $request->get_param('_cliniko_api_user'),
                (bool) ($body['acknowledge'] ?? false),
                (string) ($body['token'] ?? '')
            );
            return $result instanceof WP_Error ? self::fromError($result) : self::success($result);
        }
        if ($key === 'appointment_counts') {
            return self::success((new AppointmentCountService())->forAuthenticatedPatient());
        }
        if (in_array($key, ['communications', 'communications_unread', 'communications_read', 'communications_create'], true)) {
            $controller = new PatientCommunicationController();
            if ($key === 'communications_unread') return self::adapt($controller->unread());
            $method = ['communications' => 'list', 'communications_read' => 'markRead', 'communications_create' => 'create'][$key];
            return self::adapt($controller->{$method}($request));
        }
        if (in_array($key, ['attachments', 'attachments_presign', 'attachments_create', 'attachments_upload', 'attachments_update', 'attachments_archive'], true)) {
            $controller = new PatientAttachmentController();
            $method = ['attachments' => 'list', 'attachments_presign' => 'presign', 'attachments_create' => 'create', 'attachments_upload' => 'upload', 'attachments_update' => 'update', 'attachments_archive' => 'archive'][$key];
            return self::adapt($controller->{$method}($request));
        }
        if ($key === 'appointments') {
            $result = (new DashboardAppointmentService())->forAuthenticatedPatient((string) ($request->get_param('appointment_type_id') ?: 'all'), (int) ($request->get_param('page') ?: 1), (int) ($request->get_param('per_page') ?: 20), (string) ($request->get_param('order') ?: 'asc'));
            $result['items'] = array_map([self::class, 'appointmentFields'], $result['items']);
            return self::success($result);
        }
        if ($key === 'appointment') {
            $includeFormValue = $request->get_param('include_form');
            $includeForm = filter_var(is_scalar($includeFormValue) ? $includeFormValue : false, FILTER_VALIDATE_BOOLEAN);
            $result = (new DashboardAppointmentService())->findForAuthenticatedPatient($id, $includeForm);
            return $result === null ? self::error('appointment_not_found', 'Appointment not found.', 404) : self::success(self::appointmentFields($result, $includeForm));
        }
        return self::error('not_implemented', 'Operation unavailable.', 404);
    }

    private static function appointmentFields(array $row, bool $includeForm = false): array
    {
        $fields = ['id', 'starts_at', 'ends_at', 'service', 'status', 'doctor', 'duration', 'telehealth_url'];
        if ($includeForm) $fields[] = 'patient_form';
        return array_intersect_key($row, array_flip($fields));
    }

    public static function success(array $data, int $status = 200): WP_REST_Response { return new WP_REST_Response(['ok' => true, 'data' => $data], $status); }
    public static function error(string $code, string $message, int $status, array $fields = []): WP_REST_Response { return new WP_REST_Response(['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields]], $status); }

    private static function fromError(WP_Error $error): WP_REST_Response
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 400) : 400;
        $fields = is_array($data) ? (array) ($data['fields'] ?? []) : [];
        return self::error((string) $error->get_error_code(), (string) $error->get_error_message(), $status, $fields);
    }

    private static function adapt(WP_REST_Response $response): WP_REST_Response
    {
        $data = (array) $response->get_data();
        if ($response->get_status() >= 400) return self::error('upstream_rejected', 'The request could not be completed.', $response->get_status());
        $result = $data['data'] ?? $data;
        unset($result['success']);
        // Do not publish upstream pagination URLs or configuration flags.
        unset($result['links'], $result['restricted_to_practitioner'], $result['email_to_patient_on_completion']);
        return self::success($result, $response->get_status());
    }

    private static function adaptResult(array $result): WP_REST_Response
    {
        $status = (int) ($result['status'] ?? 500);
        if (empty($result['ok'])) return self::error((string) ($result['code'] ?? 'operation_rejected'), (string) ($result['message'] ?? 'The request could not be completed.'), $status, (array) ($result['errors'] ?? []));
        unset($result['ok'], $result['status']);
        return self::success($result, $status);
    }
}
