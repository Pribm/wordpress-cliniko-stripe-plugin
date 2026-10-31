<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

/** A single contract drives registration, validation, dashboard docs and OpenAPI. */
final class EndpointCatalog
{
    public static function groups(): array
    {
        return ['discovery' => 'Booking discovery', 'templates' => 'Published form templates', 'booking' => 'Booking payment and progress', 'patient' => 'Patient profile, appointments and account', 'patient_booking' => 'Authenticated patient booking', 'onboarding' => 'Patient profile onboarding'];
    }

    public static function scopes(): array { return ['catalog:read', 'availability:read', 'booking:create', 'booking:read', 'patient:read', 'patient:write', 'patient:onboarding', 'appointments:read', 'communications:read', 'communications:write', 'attachments:read', 'attachments:write', 'account:register']; }

    private static function field(string $type, string $description, bool $required = false, array $extra = []): array
    {
        return array_merge(['type' => $type, 'description' => $description, 'required' => $required], $extra);
    }

    public static function patientSchema(bool $identity = true): array
    {
        $properties = [];
        foreach (['practitioner_id', 'appointment_start'] as $key) $properties[$key] = self::field('string', $key, false, $key === 'practitioner_id' ? ['pattern' => '^[0-9]+$'] : ['format' => 'date-time']);
        $required = ['practitioner_id', 'appointment_start'];
        if ($identity) {
            foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'address_1', 'address_2', 'city', 'state', 'post_code', 'country', 'medicare', 'medicare_reference_number'] as $key) {
                $properties[$key] = self::field('string', $key, false, ['maxLength' => 255]);
            }
            $properties['email']['format'] = 'email';
            $properties['date_of_birth']['pattern'] = '^\\d{4}-\\d{2}-\\d{2}$';
            $required = array_merge($required, ['first_name', 'last_name', 'email', 'phone', 'date_of_birth']);
        }
        // 'required' on nested properties is for display only; JSON Schema uses the list below.
        foreach ($properties as &$property) unset($property['required']);
        unset($property);
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    public static function definitions(): array
    {
        $id = self::field('string', 'Numeric Cliniko resource ID.', true, ['pattern' => '^[0-9]+$']);
        $attemptId = self::field('string', 'Server-issued booking attempt ID.', true, ['pattern' => '^[a-f0-9]{32}$']);
        $page = self::field('integer', 'Page number.', false, ['minimum' => 1, 'default' => 1]);
        $perPage = self::field('integer', 'Page size (maximum 50).', false, ['minimum' => 1, 'maximum' => 50, 'default' => 20]);
        $envelope = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'data' => ['type' => 'object'], 'meta' => ['type' => 'object', 'properties' => ['request_id' => ['type' => 'string']]]]];
        $make = static function (string $key, string $method, string $path, string $group, string $auth, string $scope, string $summary, array $fields = [], array $example = [], array $response = [], string $notes = '') use ($envelope): array {
            return compact('key', 'method', 'path', 'group', 'auth', 'scope', 'summary', 'fields', 'example', 'response', 'notes') + ['response_schema' => $envelope];
        };
        $fields = static fn(string $in, array $schema): array => array_map(static fn($value): array => ['in' => $in, 'schema' => $value], $schema);
        $preflight = $fields('body', [
            'moduleId' => self::field('string', 'Published appointment type ID.', true, ['pattern' => '^[0-9]+$']),
            'patient_form_template_id' => self::field('string', 'Published form template ID.', true, ['pattern' => '^[0-9]+$']),
            'gateway' => self::field('string', 'Required when the appointment has a price.', false, ['enum' => ['stripe', 'tyrohealth', 'free']]),
            'patient' => ['required' => true] + self::patientSchema(false),
            'content' => self::field('object', 'Form answers using the sections returned by the published template endpoint.', true, ['required' => true, 'properties' => ['sections' => ['type' => 'array', 'items' => ['type' => 'object']]], 'additionalProperties' => false]),
        ]);
        $bookingExample = ['moduleId' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'patient' => ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00+11:00'], 'content' => ['sections' => []]];
        $attemptResponse = ['attempt' => ['id' => '0123456789abcdef0123456789abcdef', 'status' => 'preflighted', 'token' => '<one-time-displayed-attempt-secret>', 'expires_in' => 86400], 'payment' => ['required' => true, 'gateway' => 'stripe', 'amount' => 10000, 'currency' => 'aud', 'invoice_reference' => null, 'client_secret' => '<payment-intent-client-secret>'], 'booking' => ['appointment_label' => 'Consultation', 'patient_form_id' => '100']];
        $appointmentIncludeForm = self::field('boolean', 'When true, include the latest patient form attached to this appointment. Defaults to false.', false, ['default' => false]);
        $appointmentResponse = [
            'id' => '100',
            'starts_at' => '2026-11-01T10:00:00+11:00',
            'ends_at' => '2026-11-01T10:30:00+11:00',
            'service' => 'Consultation',
            'status' => 'Upcoming',
            'doctor' => 'Dr Example',
            'duration' => 30,
            'telehealth_url' => '',
            'patient_form' => [
                'id' => '102', 'template_id' => '456', 'name' => 'Intake', 'status' => 'completed',
                'created_at' => '2026-10-01T09:00:00+11:00', 'updated_at' => '2026-10-01T09:05:00+11:00',
                'completed_at' => '2026-10-01T09:05:00+11:00', 'archived_at' => '', 'sections' => [],
            ],
        ];
        $passwordFields = $fields('body', [
            'current_password' => self::field('string', 'The patient’s current WordPress password.', true, ['minLength' => 1, 'maxLength' => 4096, 'writeOnly' => true]),
            'new_password' => self::field('string', 'The new WordPress password. Use 12–128 characters.', true, ['minLength' => 12, 'maxLength' => 128, 'writeOnly' => true]),
        ]);
        $closureAcknowledgement = self::field('boolean', 'Must be true after the app displays that only the portal account closes; the Cliniko health record is retained.', true, ['enum' => [true]]);
        $closureToken = self::field('string', 'The one-time token from the account-closure confirmation email.', true, ['minLength' => 20, 'maxLength' => 256, 'writeOnly' => true]);
        $closureReturnUrl = self::field('string', 'Same-site HTTPS page containing the existing account-closure shortcode. The email button opens this page for final review.', true, ['format' => 'uri']);
        $result = [
            $make('status', 'GET', '/status', 'system', 'public', '', 'API health and contract version', [], [], ['version' => ApiSettings::VERSION, 'available' => true]),
            $make('capabilities', 'GET', '/capabilities', 'system', 'public', '', 'Enabled feature groups', [], [], ['groups' => ['discovery']]),
            $make('appointment_types', 'GET', '/appointment-types', 'discovery', 'public', 'catalog:read', 'List explicitly published appointment types', [], [], ['items' => [['appointment_type_id' => '123', 'name' => 'Consultation', 'description' => '', 'duration_in_minutes' => 30, 'amount_cents' => 10000, 'amount' => '100.00', 'currency' => 'aud', 'payment_required' => true]]]),
            $make('appointment_type', 'GET', '/appointment-types/{id}', 'discovery', 'public', 'catalog:read', 'Appointment type details', $fields('path', ['id' => $id]), [], ['appointment_type_id' => '123', 'name' => 'Consultation', 'description' => '', 'duration_in_minutes' => 30, 'amount_cents' => 10000, 'amount' => '100.00', 'currency' => 'aud', 'payment_required' => true]),
            $make('practitioners', 'GET', '/appointment-types/{id}/practitioners', 'discovery', 'public', 'availability:read', 'Practitioners for a published appointment type', $fields('path', ['id' => $id]), [], ['appointment_type_id' => '123', 'practitioners' => [['id' => '789', 'name' => 'Dr Example']]]),
            $make('availability', 'GET', '/appointment-types/{id}/availability', 'discovery', 'public', 'availability:read', 'Available appointment start times', $fields('path', ['id' => $id]) + $fields('query', [
                'from' => self::field('string', 'First date (YYYY-MM-DD). Maximum range: 93 days.', true, ['format' => 'date']),
                'to' => self::field('string', 'Last date (YYYY-MM-DD).', true, ['format' => 'date']),
                'practitioner_id' => self::field('string', 'Optional practitioner selection.', false, ['pattern' => '^[0-9]+$']),
                'page' => $page, 'per_page' => $perPage,
            ]), [], ['available_times' => [['appointment_start' => '2026-11-01T10:00:00+11:00']], 'total_entries' => 1, 'appointment_type_id' => '123', 'practitioner_id' => '789', 'from' => '2026-11-01', 'to' => '2026-11-07']),
            $make('template', 'GET', '/form-templates/{id}', 'templates', 'public', 'catalog:read', 'An explicitly published form template', $fields('path', ['id' => $id]), [], ['patient_form_template_id' => '456', 'name' => 'Intake', 'sections' => [], 'submission_template' => ['content' => ['sections' => []]]]),
            $make('stripe', 'POST', '/booking-attempts/{id}/stripe-payment', 'booking', 'attempt', 'booking:create', 'Verify a Stripe PaymentIntent for an attempt', $fields('path', ['id' => $attemptId]), [], ['payment' => ['status' => 'verified', 'gateway' => 'stripe', 'reference' => 'ch_example', 'payment_intent_id' => 'pi_example', 'receipt_url' => null, 'amount' => 10000, 'currency' => 'aud', 'card_last4' => '4242', 'brand' => 'visa']], 'Requires the original verified patient Bearer token and X-ES-Attempt-Token. Confirm the client_secret returned by booking preflight with the Stripe SDK, then call this endpoint without card data or a token. The server retrieves and verifies the PaymentIntent.'),
            $make('tyro', 'POST', '/booking-attempts/{id}/tyro-payment', 'booking', 'attempt', 'booking:create', 'Verify a Tyro transaction with the payment provider', $fields('path', ['id' => $attemptId]) + $fields('body', ['transactionId' => self::field('string', 'Paid Tyro transaction matching this attempt amount and invoice reference.', true, ['maxLength' => 191])]), ['transactionId' => 'transaction-example'], ['payment' => ['status' => 'verified', 'gateway' => 'tyrohealth', 'reference' => 'transaction-example', 'receipt_url' => null, 'amount' => 10000, 'currency' => 'aud']], 'Requires the original verified patient Bearer token and X-ES-Attempt-Token. A client assertion of payment is never accepted.'),
            $make('finalize', 'POST', '/booking-attempts/{id}/finalize', 'booking', 'attempt', 'booking:create', 'Finalize after payment is verified or not required', $fields('path', ['id' => $attemptId]), [], ['result' => ['booking' => ['appointment_id' => '100', 'attendee_id' => '101', 'starts_at' => '2026-11-01T10:00:00+11:00', 'ends_at' => '2026-11-01T10:30:00+11:00'], 'payment' => ['status' => 'verified', 'gateway' => 'stripe', 'reference' => 'ch_example'], 'patient_form_id' => '102', 'confirmation' => ['patient' => ['name' => 'Alex Example', 'email' => 'alex@example.test'], 'appointment' => ['starts_at' => '2026-11-01T10:00:00+11:00', 'ends_at' => '2026-11-01T10:30:00+11:00']]]], 'Requires the original verified patient Bearer token, X-ES-Attempt-Token and Idempotency-Key. Retries of a completed attempt return its original result.'),
            $make('booking_status', 'GET', '/booking-attempts/{id}', 'booking', 'attempt', 'booking:read', 'Read booking progress', $fields('path', ['id' => $attemptId]), [], ['attempt' => ['id' => '0123456789abcdef0123456789abcdef', 'status' => 'preflighted', 'progress' => ['code' => 'preflighted', 'message' => 'Form validated.'], 'payment' => ['status' => 'pending'], 'booking' => ['appointment_id' => null], 'error' => null]], 'Requires the original verified patient Bearer token and X-ES-Attempt-Token. No patient record is returned.'),
            $make('sdk_token', 'POST', '/payments/tyro-sdk-token', 'booking', 'patient', 'booking:create', 'Mint a short-lived frontend Tyro SDK token for a verified patient', [], [], ['token' => '<frontend-sdk-token>'], 'Requires a verified patient access token. This is a frontend SDK token, never the administrator API key.'),
            $make('booking_flows', 'GET', '/booking-flows', 'patient_booking', 'patient', 'booking:read', 'List currently available patient booking flows', [], [], ['flows' => [['identifier' => 'initial-consultation', 'name' => 'Initial consultation', 'flow_type' => 'api', 'definition_revision' => str_repeat('a', 64), 'appointment_type' => ['id' => '123', 'name' => 'Consultation', 'duration_in_minutes' => 30], 'form' => ['id' => '456', 'name' => 'Intake'], 'scheduling' => ['mode' => 'manual'], 'payment' => ['required' => true, 'gateway' => 'stripe', 'amount' => 10000, 'currency' => 'aud'], 'endpoints' => ['structure' => '/booking-flows/initial-consultation', 'create_booking' => '/booking-flows/initial-consultation/booking-attempts']]]], 'Only enabled flows whose appointment type and form are currently published and available are returned. This is a discovery summary; fetch each structure endpoint for the complete form contract before creating a booking. Requires a verified patient Bearer token and booking:read.'),
            $make('me', 'GET', '/me', 'patient', 'patient', 'patient:read', 'Current verified patient profile', [], [], ['id' => '100', 'first_name' => 'Alex', 'last_name' => 'Example', 'email' => 'alex@example.test', 'phone' => '0400000000', 'date_of_birth' => '1990-01-01', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'post_code' => '', 'country' => 'Australia'], 'When a patient onboarding scheme is published, its configured profile fields become the /me response allowlist and shape. The response returns the patient values for those fields at the top level; unconfigured profile fields are omitted. If no onboarding scheme is published, the API uses the standard safe profile fallback.'),
            $make('me_update', 'PATCH', '/me', 'patient', 'patient', 'patient:write', 'Update the authenticated patient profile', $fields('body', [
                'data' => self::field('object', 'Profile fields to update. When an onboarding scheme is published, use its field keys and validation rules.', true, ['additionalProperties' => true]),
            ]), ['data' => ['phone' => '0400000000', 'city' => 'Brisbane']], ['id' => '100', 'first_name' => 'Alex', 'last_name' => 'Example', 'email' => 'alex@example.test', 'phone' => '0400000000'], 'Uses the authenticated patient identity and never accepts a patient ID. The active onboarding definition is the allowlist and validation contract when configured. Send only fields being changed; email changes require the separate verified email-change flow. Returns the same profile shape as GET /me.'),
            $make('appointments', 'GET', '/me/appointments', 'patient', 'patient', 'appointments:read', 'Current patient appointments', $fields('query', ['appointment_type_id' => self::field('string', 'Numeric appointment type ID or all.', false, ['pattern' => '^(all|[0-9]+)$', 'default' => 'all']), 'page' => $page, 'per_page' => $perPage, 'order' => self::field('string', 'Chronological order.', false, ['enum' => ['asc', 'desc'], 'default' => 'asc'])]), [], ['items' => [['id' => '100', 'starts_at' => '2026-11-01T10:00:00+11:00', 'ends_at' => '2026-11-01T10:30:00+11:00', 'service' => 'Consultation', 'status' => 'Upcoming', 'doctor' => 'Dr Example', 'duration' => 30, 'telehealth_url' => '']], 'page' => 1, 'per_page' => 20, 'has_more' => false]),
            $make('password_update', 'POST', '/me/password', 'patient', 'patient', 'patient:write', 'Change the authenticated patient password', $passwordFields, ['current_password' => '<current-password>', 'new_password' => '<new-12-to-128-character-password>'], ['password_updated' => true, 'sessions_revoked' => true, 'message' => 'Password updated. Sign in again with the new password.'], 'Requires the current password. A successful change invalidates all existing WordPress and plugin patient sessions because their password fingerprint changes. The app must discard its current access and refresh tokens and sign in again. Rate limited; never log either password.'),
            $make('account_closure_request', 'POST', '/me/account/closure', 'patient', 'patient', 'patient:write', 'Request email confirmation to close the portal account', $fields('body', ['acknowledge' => $closureAcknowledgement, 'return_url' => $closureReturnUrl]), ['acknowledge' => true, 'return_url' => 'https://portal.example.test/account/close/'], ['status' => 'requested', 'message' => 'Check your email to confirm account closure.'], 'This reuses the shortcode account-closure service and its configured request email template. The return_url must be a same-site page containing [cliniko_patient_close_account], where the patient can review the warning and complete confirmation. It sends no token in the API response and does not delete anything.'),
            $make('account_closure_confirm', 'POST', '/me/account/closure/confirm', 'patient', 'patient', 'patient:write', 'Confirm closure of the authenticated portal account', $fields('body', ['acknowledge' => $closureAcknowledgement, 'token' => $closureToken]), ['acknowledge' => true, 'token' => '<token-from-closure-email>'], ['status' => 'closed', 'message' => 'Your patient portal account has been closed.', 'cliniko_record_deleted' => false], 'The token is the 30-minute email confirmation token. The authenticated patient identity is used; no user ID is accepted. This reuses the shortcode closure service and receipt template, destroys sessions, removes the local WordPress portal account, and never deletes Cliniko clinical records. Treat the operation as final.'),
            /*
            $make('appointment', 'GET', '/me/appointments/{id}', 'patient', 'patient', 'appointments:read', 'A patient-owned appointment', $fields('path', ['id' => $id]) + $fields('query', ['include_form' => self::field('boolean', 'When true, include the latest patient form attached to this appointment. Defaults to false.', false, ['default' => false])]), [], ['id' => '100', 'starts_at' => '2026-11-01T10:00:00+11:00', 'ends_at' => '2026-11-01T10:30:00+11:00', 'service' => 'Consultation', 'status' => 'Upcoming', 'doctor' => 'Dr Example', 'duration' => 30, 'telehealth_url' => '', 'patient_form' => ['id' => '102', 'template_id' => '456', 'name' => 'Intake', 'status' => 'completed', 'created_at' => '2026-10-01T09:00:00+11:00', 'updated_at' => '2026-10-01T09:05:00+11:00', 'completed_at' => '2026-10-01T09:05:00+11:00', 'archived_at' => '', 'sections' => []]], 'Use GET /me/appointments/{id} for the appointment summary. Add ?include_form=true only when the app needs the form answers; the default is false to keep the normal response small and avoid an extra Cliniko lookup. The form is resolved through this appointment’s attendee and the authenticated patient identity. If no form is attached, patient_form is null.');
            */
            $make('appointment', 'GET', '/me/appointments/{id}', 'patient', 'patient', 'appointments:read', 'A patient-owned appointment', $fields('path', ['id' => $id]) + $fields('query', ['include_form' => $appointmentIncludeForm]), [], $appointmentResponse, 'Use GET /me/appointments/{id} for the appointment summary. Add ?include_form=true only when the app needs the form answers; the default is false to keep the normal response small and avoid an extra Cliniko lookup. The form is resolved through this appointment attendee and the authenticated patient identity. If no form is attached, patient_form is null.'),
            $make('appointment_counts', 'GET', '/me/appointments/counts', 'patient', 'patient', 'appointments:read', 'Appointment totals for the authenticated patient', $fields('query', []), [], ['total' => 12, 'upcoming' => 3, 'completed' => 9], 'Returns the same total, upcoming and completed counts used by the appointment-count shortcodes. Counts include only appointments linked to the authenticated patient. The result is not a substitute for the appointment list.'),
            $make('communications', 'GET', '/me/communications', 'patient', 'patient', 'communications:read', 'Read the authenticated patient communication timeline', $fields('query', ['view' => self::field('string', 'Timeline view.', false, ['enum' => ['general', 'email', 'sms'], 'default' => 'general']), 'page' => $page, 'per_page' => $perPage]), [], ['communications' => [['id' => '100', 'category' => 'general', 'content' => 'We look forward to seeing you.', 'created_at' => '2026-10-01T09:00:00+11:00', 'direction_code' => 1, 'is_unread' => true]], 'pagination' => ['page' => 1, 'per_page' => 20, 'total_entries' => 1]], 'This is the API equivalent of the patient communications shortcode. Records are filtered to the authenticated patient; no patient ID can be supplied.'),
            $make('communications_unread', 'GET', '/me/communications/unread', 'patient', 'patient', 'communications:read', 'Read unread communication totals', [], [], ['unread' => ['general' => 1, 'email' => 0, 'sms' => 0, 'total' => 1]], 'Returns the unread summary used by the patient communications navigation shortcode. Read state is stored per WordPress patient account.'),
            $make('communications_read', 'POST', '/me/communications/read', 'patient', 'patient', 'communications:write', 'Mark the authenticated patient communications as read', $fields('body', ['ids' => self::field('array', 'Communication IDs to mark read (up to 100).', true, ['items' => ['type' => 'string']])]), ['ids' => ['100']], ['read_ids' => ['100']], 'Only communication IDs supplied by the authenticated patient app are recorded as read. This changes the local unread badge state; it does not alter Cliniko content.'),
            $make('communications_create', 'POST', '/me/communications', 'patient', 'patient', 'communications:write', 'Send a message to the clinic', $fields('body', ['content' => self::field('string', 'Non-urgent message text.', true, ['maxLength' => 5000]), 'clinic_label' => self::field('string', 'Recipient label stored with the memo.', false, ['maxLength' => 191])]), ['content' => 'I have a question about my next appointment.', 'clinic_label' => 'Clinic'], ['communication' => ['id' => '101', 'category' => 'general', 'content' => 'I have a question about my next appointment.']], 'Creates the same received memo as the communications shortcode. It does not send email or SMS and must not be used for urgent clinical care.'),
            $make('attachments', 'GET', '/me/attachments', 'patient', 'patient', 'attachments:read', 'List the authenticated patient documents', $fields('query', ['page' => $page, 'per_page' => $perPage, 'order' => self::field('string', 'Sort by created time.', false, ['enum' => ['asc', 'desc'], 'default' => 'desc'])]), [], ['attachments' => [['id' => '100', 'filename' => 'referral.pdf', 'content_type' => 'application/pdf', 'description' => 'Referral', 'created_at' => '2026-10-01T09:00:00+11:00', 'processing_completed' => true]], 'pagination' => ['page' => 1, 'per_page' => 20, 'total_entries' => 1]], 'Documents are filtered to the authenticated patient. The API never accepts a patient ID for document access.'),
            $make('attachments_presign', 'POST', '/me/attachments/presign', 'patient', 'patient', 'attachments:write', 'Prepare a secure document upload', [], [], ['upload' => ['url' => 'https://storage.example.test/presigned', 'fields' => ['key' => 'private/example'], 'expires_in' => 900]], 'Use the returned upload instructions with a multipart/form-data upload, then call POST /me/attachments with the completed upload_url. Do not expose storage credentials or invent a URL.'),
            $make('attachments_create', 'POST', '/me/attachments', 'patient', 'patient', 'attachments:write', 'Register a completed document upload', $fields('body', ['upload_url' => self::field('string', 'Completed upload URL returned by the presigned upload.', true, ['format' => 'uri']), 'description' => self::field('string', 'Optional document description.', false, ['maxLength' => 500])]), ['upload_url' => 'https://storage.example.test/private/example', 'description' => 'Referral'], ['attachment' => ['id' => '100', 'filename' => 'referral.pdf', 'description' => 'Referral']], 'The URL is checked against the patient upload flow before the Cliniko attachment record is created.'),
            $make('attachments_upload', 'POST', '/me/attachments/upload', 'patient', 'patient', 'attachments:write', 'Upload a document directly', $fields('body', ['file' => self::field('string', 'Multipart file field.', true, ['format' => 'binary']), 'description' => self::field('string', 'Optional document description.', false, ['maxLength' => 500])]), [], ['attachment' => ['id' => '100', 'filename' => 'referral.pdf', 'content_type' => 'application/pdf']], 'Send multipart/form-data with the file field named file. The endpoint reuses the secure Cliniko upload flow and never accepts a patient ID.'),
            $make('attachments_update', 'PATCH', '/me/attachments/{id}', 'patient', 'patient', 'attachments:write', 'Update a patient document description', $fields('path', ['id' => $id]) + $fields('body', ['description' => self::field('string', 'Replacement document description.', true, ['maxLength' => 500])]), ['description' => 'Updated referral'], ['attachment' => ['id' => '100', 'description' => 'Updated referral']], 'The document must already belong to the authenticated patient.'),
            $make('attachments_archive', 'DELETE', '/me/attachments/{id}', 'patient', 'patient', 'attachments:write', 'Archive a patient document', $fields('path', ['id' => $id]), [], ['archived' => true], 'Archiving is limited to documents belonging to the authenticated patient and does not expose another patient’s records.'),
        ];
        $result[] = $make('patient_booking_create', 'POST', '/me/booking-attempts', 'patient_booking', 'patient', 'booking:create', 'Create an attempt for the authenticated linked patient', $preflight, $bookingExample, $attemptResponse, 'Identity comes only from the verified patient account. Supplied identity fields are rejected. No guest booking creation is exposed by this API. No shortcode nonce is required.');
        $flowSlug = self::field('string', 'Published identifier from API Access → Booking flows.', true, ['pattern' => '^[a-z][a-z0-9-]{1,63}$']);
        $flowResponse = ['identifier' => 'initial-consultation', 'appointment_type' => ['id' => '123', 'name' => 'Consultation', 'duration_in_minutes' => 30],
            'form' => ['id' => '456', 'name' => 'Intake', 'sections' => [['name' => 'Contact', 'description' => '', 'questions' => [['name' => 'Preferred contact method', 'type' => 'radiobuttons', 'required' => true, 'options' => ['phone call', 'email'], 'other_allowed' => false, 'validation' => ['max_length' => 5000], 'custom_field_token' => 'ef4c43ec-105d-489c-88cc-0f3c5f67dc70', 'patient_field_connection' => 'custom_field']]]]], 'scheduling' => ['mode' => 'manual', 'required_fields' => ['practitioner_id', 'appointment_start']],
            'payment' => ['required' => true, 'gateway' => 'stripe', 'amount' => 10000, 'currency' => 'aud'], 'definition_revision' => str_repeat('a', 64),
            'submission' => ['method' => 'POST', 'path' => '/booking-flows/initial-consultation/booking-attempts', 'required_scope' => 'booking:create', 'body' => ['definition_revision' => str_repeat('a', 64), 'patient' => ['practitioner_id' => '', 'appointment_start' => ''], 'content' => ['sections' => []]]],
            'resources' => ['practitioners' => '/appointment-types/123/practitioners', 'availability' => '/appointment-types/123/availability']];
        $result[] = $make('booking_flow', 'GET', '/booking-flows/{slug}', 'patient_booking', 'patient', 'booking:read', 'Read a published patient booking structure', $fields('path', ['slug' => $flowSlug]), [], $flowResponse, 'API-only flows are created and edited directly in API Access without a shortcode. Legacy website-linked flows remain explicitly labelled and can be made API-only at the same address. Returns only structure, validation and booking requirements; no HTML, CSS, JavaScript, display options, completed answers or patient identity. A linked Cliniko patient field is identified by custom_field_token and patient_field_connection on the question; these are response metadata only and must not be copied into the submission content. GET never creates an attempt. Resource links require enabled discovery and its catalog/availability scopes.');
        $flowPatient = self::patientSchema(false); $flowPatient['required'] = [];
        $result[] = $make('booking_flow_create', 'POST', '/booking-flows/{slug}/booking-attempts', 'patient_booking', 'patient', 'booking:create', 'Create your booking from the published setup', $fields('path', ['slug' => $flowSlug]) + $fields('body', [
            'definition_revision' => self::field('string', 'Exact revision from the latest structure response. A changed setup returns 409.', true, ['pattern' => '^[a-f0-9]{64}$']),
            'patient' => ['required' => true] + $flowPatient, 'content' => $preflight['content']['schema']]),
            ['definition_revision' => str_repeat('a', 64), 'patient' => ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00+11:00'], 'content' => ['sections' => []]], $attemptResponse,
            'Send the filled submission.body from GET /booking-flows/{slug}. For manual scheduling both scheduling fields are required; next_available requires patient: {} and chooses the slot server-side. Service, form, price and gateway come only from the saved configuration. Answers are checked against the current template and saved rules; identity and configuration overrides are rejected. Payment/progress use the existing protected attempt routes.');
        $onboardingSlug = self::field('string', 'Published patient onboarding identifier from API Access.', true, ['pattern' => '^[a-z][a-z0-9-]{1,63}$']);
        $onboardingRevision = self::field('string', 'Exact revision returned by the onboarding schema.', true, ['pattern' => '^[a-f0-9]{64}$']);
        $onboardingData = ['type' => 'object', 'description' => 'Only fields returned by the onboarding schema may be submitted.', 'required' => true, 'additionalProperties' => true];
        $onboardingResponse = ['slug' => 'patient-profile', 'definition_revision' => str_repeat('a', 64), 'complete' => false, 'missing' => [['key' => 'date_of_birth', 'label' => 'Date of birth']], 'steps' => [['id' => 'api-profile', 'title' => 'Patient details', 'fields' => ['date_of_birth', 'custom_insurance']]], 'fields' => [['key' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'read_only' => false, 'current_value' => null, 'validation' => ['format' => 'date']], ['key' => 'custom_insurance', 'label' => 'Insurance provider', 'type' => 'select', 'required' => false, 'read_only' => false, 'current_value' => null, 'validation' => ['options' => ['Medibank', 'Bupa', 'Other']]]], 'submission' => ['session' => ['method' => 'POST', 'path' => '/patient-onboarding/patient-profile/session'], 'complete' => ['method' => 'POST', 'path' => '/patient-onboarding/patient-profile/complete', 'header' => 'X-ES-Onboarding-Token']], 'rules' => ['existing_values_count_as_complete' => true, 're_onboard_when_revision_changes' => true, 'email_changes_use_confirmation' => true]];
        $result[] = $make('onboarding_schema', 'GET', '/patient-onboarding/{slug}', 'onboarding', 'patient', 'patient:onboarding', 'Read the authenticated patient onboarding schema and completion status', $fields('path', ['slug' => $onboardingSlug]), [], $onboardingResponse, 'This returns structure only: steps, fields, validation rules, current values for the signed-in patient, missing fields and a definition revision. It never accepts a patient ID. Existing Cliniko values count as complete; a changed required definition creates a new revision. A conditional field is required and validated only when its condition matches; this applies equally to conditions that expect true/on and false/off.');
        $result[] = $make('onboarding_session', 'POST', '/patient-onboarding/{slug}/session', 'onboarding', 'patient', 'patient:onboarding', 'Start a short-lived onboarding completion session', $fields('path', ['slug' => $onboardingSlug]), [], ['complete' => false, 'definition_revision' => str_repeat('a', 64), 'onboarding_token' => '<short-lived-private-token>', 'expires_in' => 1800, 'missing' => [['key' => 'date_of_birth', 'label' => 'Date of birth']]], 'Call after reading the schema. If the profile is already complete, no token is issued. The token is bound to the patient, approved app and definition revision and is used only for the completion submission.');
        $result[] = $make('onboarding_complete', 'POST', '/patient-onboarding/{slug}/complete', 'onboarding', 'patient', 'patient:onboarding', 'Submit and save the authenticated patient profile details', $fields('path', ['slug' => $onboardingSlug]) + $fields('body', ['definition_revision' => $onboardingRevision, 'data' => $onboardingData]), ['definition_revision' => str_repeat('a', 64), 'data' => ['date_of_birth' => '1990-01-01']], $onboardingResponse, 'Send Authorization: Bearer and X-ES-Onboarding-Token. The server validates the current schema, updates only the linked Cliniko patient and returns the refreshed schema. On HTTP 422, error.fields contains one entry per missing or invalid field with field, label, code and detail, matching booking validation details. The onboarding token is short-lived and consumed after success. Email changes remain a separate confirmation flow.');
        $clientId = self::field('string', 'Approved Patient app ID from Applications. Public identifier, not a password.', true, ['pattern' => '^[a-f0-9]{24}$']);
        $socialProvider = self::field('string', 'Enabled social sign-in service.', true, ['enum' => ['google', 'apple']]);
        $refreshToken = self::field('string', 'Private rotating refresh token. Never put it in a URL or log.', true, ['pattern' => '^[a-f0-9]{48}\\.[a-f0-9]{64}$', 'writeOnly' => true]);
        $tokens = ['access_token' => '<short-lived-signed-JWT>', 'token_type' => 'Bearer', 'expires_in' => 900, 'refresh_token' => '<private-rotating-refresh-token>', 'refresh_expires_in' => 604800, 'scope' => 'patient:read appointments:read'];
        $resetEmail = self::field('string', 'Email address used by the patient account. The response is identical whether an account exists.', true, ['format' => 'email', 'maxLength' => 254]);
        $resetLogin = self::field('string', 'The login value from the WordPress reset email link.', true, ['maxLength' => 254]);
        $resetKey = self::field('string', 'The one-time key from the WordPress reset email link. Treat it as a secret.', true, ['maxLength' => 512, 'writeOnly' => true]);
        $resetPassword = self::field('string', 'New password. Use 12–128 characters.', true, ['minLength' => 12, 'maxLength' => 128, 'writeOnly' => true]);
        $result[] = $make('auth_login', 'POST', '/auth/login', 'authentication', 'login', '', 'Sign in with an existing WordPress patient account', $fields('body', ['client_id' => $clientId, 'identifier' => self::field('string', 'Patient username or email.', true, ['maxLength' => 254]), 'password' => self::field('string', 'Patient website password. HTTPS only; never store or log it.', true, ['maxLength' => 4096, 'writeOnly' => true])]), ['client_id' => str_repeat('a', 24), 'identifier' => 'alex@example.test', 'password' => '<patient-password>'], $tokens, 'Returns a plugin-only JWT session after WordPress authentication and verified patient checks. No administrator sessions. Known interactive MFA integrations are blocked; custom integrations need compatibility review.');
        $result[] = $make('password_forgot', 'POST', '/auth/password/forgot', 'authentication', 'login', '', 'Send a password-reset email without revealing account existence', $fields('body', ['client_id' => $clientId, 'email' => $resetEmail]), ['client_id' => str_repeat('a', 24), 'email' => 'alex@example.test'], ['status' => 'requested', 'message' => 'If an eligible patient account exists, a password reset email has been sent.'], 'Always returns the same response for unknown, inactive, unverified and valid accounts. WordPress sends its existing one-time reset email and applies the site’s normal password-reset filters. Do not use this response to determine whether an email is registered.');
        $result[] = $make('password_reset', 'POST', '/auth/password/reset', 'authentication', 'login', '', 'Set a new password with a one-time reset key', $fields('body', ['client_id' => $clientId, 'login' => $resetLogin, 'key' => $resetKey, 'new_password' => $resetPassword]), ['client_id' => str_repeat('a', 24), 'login' => 'alex', 'key' => '<one-time-wordpress-reset-key>', 'new_password' => '<new-12-to-128-character-password>'], ['password_updated' => true, 'sessions_revoked' => true, 'message' => 'Password reset. Sign in with your new password.'], 'Use the login and key values from the WordPress reset email link (the link can be opened in the app or browser). The key is one-time and expires under WordPress reset-key rules. Invalid keys are rejected, active verified patient accounts only are eligible, all existing API and WordPress sessions are revoked, and the app must sign in again.');
        $result[] = $make('auth_challenge', 'POST', '/auth/challenge', 'authentication', 'login', '', 'Start a nonce-bound Google or Apple sign-in', $fields('body', ['client_id' => $clientId, 'provider' => $socialProvider, 'purpose' => self::field('string', 'login for existing patients; register for explicit new-account onboarding.', false, ['enum' => ['login', 'register'], 'default' => 'login'])]), ['client_id' => str_repeat('a', 24), 'provider' => 'google'], ['challenge_id' => str_repeat('b', 48), 'nonce' => '<pass-this-exact-value-to-the-provider>', 'expires_in' => 300, 'provider' => 'google', 'audience' => 'your-client.apps.googleusercontent.com'], 'Request before opening the social sign-in SDK. Pass nonce unchanged to its authorization request; Apple nonce is already SHA-256 transformed. SDKs without nonce support are incompatible. A registration challenge requires account:register and cannot be exchanged at the login endpoint, or vice versa.');
        $result[] = $make('auth_social', 'POST', '/auth/social', 'authentication', 'login', '', 'Exchange a Google or Apple ID token for a patient JWT session', $fields('body', ['client_id' => $clientId, 'provider' => $socialProvider, 'challenge_id' => self::field('string', 'Single-use challenge from /auth/challenge.', true, ['pattern' => '^[a-f0-9]{48}$']), 'id_token' => self::field('string', 'Provider-signed ID token containing the supplied nonce. Not an access token.', true, ['maxLength' => 8192, 'writeOnly' => true])]), ['client_id' => str_repeat('a', 24), 'provider' => 'google', 'challenge_id' => str_repeat('b', 48), 'id_token' => '<provider-ID-token>'], $tokens, 'Verifies signature, fixed provider issuer, this app’s audience, expiration and nonce. Requires an explicit provider-subject → existing patient mapping. Never auto-links by email or creates a patient account.');
        $result[] = $make('auth_refresh', 'POST', '/auth/refresh', 'authentication', 'login', '', 'Renew a WordPress-issued patient session', $fields('body', ['client_id' => $clientId, 'refresh_token' => $refreshToken]), ['client_id' => str_repeat('a', 24), 'refresh_token' => str_repeat('b', 48) . '.' . str_repeat('c', 64)], $tokens, 'Rotates the refresh token; replace both tokens together. Reusing a spent token revokes the entire session. Serialize refreshes in the app. Does not refresh externally issued OAuth tokens.');
        $result[] = $make('auth_logout', 'POST', '/auth/logout', 'authentication', 'login', '', 'Revoke a WordPress-issued patient session', $fields('body', ['client_id' => $clientId, 'refresh_token' => $refreshToken]), ['client_id' => str_repeat('a', 24), 'refresh_token' => str_repeat('b', 48) . '.' . str_repeat('c', 64)], ['logged_out' => true], 'Revokes the session including outstanding access tokens. This is not a WordPress browser logout or an external OAuth provider logout.');
        $signup = ['client_id' => $clientId, 'first_name' => self::field('string', 'Patient first name.', true, ['minLength' => 1, 'maxLength' => 120]), 'last_name' => self::field('string', 'Patient last name.', true, ['minLength' => 1, 'maxLength' => 120]), 'accept_privacy_policy' => self::field('boolean', 'Explicit acceptance of the clinic privacy policy.', true, ['enum' => [true]])];
        $signupExample = ['client_id' => str_repeat('a', 24), 'first_name' => 'Alex', 'last_name' => 'Example', 'accept_privacy_policy' => true];
        $pending = ['status' => 'verification_pending', 'message' => 'If registration can proceed, check your email. Existing users should sign in or contact the clinic.', 'registration_token' => 'reg_' . str_repeat('b', 48) . '.' . str_repeat('c', 64), 'expires_in' => 86400];
        $registrationToken = self::field('string', 'Private 24-hour onboarding receipt, not a patient access token. Never put it in a URL or log.', true, ['pattern' => '^reg_[a-f0-9]{48}\\.[a-f0-9]{64}$', 'writeOnly' => true]);
        $result[] = $make('auth_register', 'POST', '/auth/register', 'authentication', 'login', 'account:register', 'Register a pending WordPress patient account', $fields('body', $signup + ['email' => self::field('string', 'Patient email to verify through the existing clinic email flow.', true, ['format' => 'email', 'maxLength' => 254]), 'password' => self::field('string', 'New website password, 12–128 characters.', true, ['minLength' => 12, 'maxLength' => 128, 'writeOnly' => true])]), $signupExample + ['email' => 'alex@example.test', 'password' => '<new-patient-password>'], $pending, 'Server assigns a safe configured patient role. Existing accounts are never overwritten. Starts the existing WordPress/Cliniko email-verification flow; returns an onboarding receipt, never a JWT or patient ID.');
        $result[] = $make('auth_register_social', 'POST', '/auth/register/social', 'authentication', 'login', 'account:register', 'Register a pending patient using verified Google or Apple identity', $fields('body', $signup + ['provider' => $socialProvider, 'challenge_id' => self::field('string', 'Single-use purpose=register challenge.', true, ['pattern' => '^[a-f0-9]{48}$']), 'id_token' => self::field('string', 'Provider-signed ID token with nonce and verified email.', true, ['maxLength' => 8192, 'writeOnly' => true])]), $signupExample + ['provider' => 'google', 'challenge_id' => str_repeat('b', 48), 'id_token' => '<verified-provider-ID-token>'], $pending, 'Request /auth/challenge with purpose=register first. Uses only provider-verified email, creates a new pending WordPress account and binds that proven identity to that new account. Never joins an existing account by email. Clinic verification and approval still required; Apple relay needs working outbound email configuration.');
        $result[] = $make('auth_register_oauth', 'POST', '/auth/register/oauth', 'authentication', 'login', 'account:register', 'Register a pending patient using an external OAuth identity', $fields('body', $signup + ['access_token' => self::field('string', 'Provider access token with account:register and verified email introspection claims.', true, ['maxLength' => 8192, 'writeOnly' => true])]), $signupExample + ['access_token' => '<provider-access-token>'], $pending, 'client_id is this dashboard’s internal OAuth App ID. Introspection must identify the same approved app and include email + email_verified. Creates only a new pending account; never merges existing WordPress users by email or issues a local OAuth token.');
        foreach (['auth_registration_status' => ['/auth/registration/status', 'Check the private onboarding progress'], 'auth_registration_resend' => ['/auth/registration/resend', 'Request another clinic verification email']] as $key => [$path, $summary]) $result[] = $make($key, 'POST', $path, 'authentication', 'login', '', $summary, $fields('body', ['client_id' => $clientId, 'registration_token' => $registrationToken]), ['client_id' => str_repeat('a', 24), 'registration_token' => $pending['registration_token']], ['status' => 'verification_pending', 'message' => 'Complete email verification and clinic approval, then sign in.'], 'Requires the original approved app and private onboarding receipt. No patient data, WordPress IDs or verification links are returned. Resend has a 60-second mail cooldown and three requests per 10 minutes.');
        foreach ($result as &$endpoint) {
            if (in_array($endpoint['key'], ['auth_register', 'auth_register_social', 'auth_register_oauth', 'auth_registration_resend', 'password_forgot'], true)) $endpoint['success_status'] = 202;
            if ($endpoint['auth'] === 'attempt') $endpoint['fields']['X-ES-Attempt-Token'] = ['in' => 'header', 'schema' => self::field('string', 'Secret returned when this attempt was created.', true)];
            if ($endpoint['key'] === 'finalize') $endpoint['fields']['Idempotency-Key'] = ['in' => 'header', 'schema' => self::field('string', 'A unique value per finalize operation, reused on retries.', true, ['minLength' => 8, 'maxLength' => 128])];
            if ($endpoint['key'] === 'onboarding_complete') $endpoint['fields']['X-ES-Onboarding-Token'] = ['in' => 'header', 'schema' => self::field('string', 'Short-lived token returned by the onboarding session route.', true, ['pattern' => '^[a-f0-9]{64}$'])];
            $endpoint['response_schema']['properties']['data'] = self::inferSchema($endpoint['response']);
            if ($endpoint['key'] === 'booking_flow') $endpoint['response_schema']['properties']['data']['properties']['form'] = \App\Service\BookingFormStructure::schema();
        }
        unset($endpoint);
        return $result;
    }

    public static function route(array $endpoint): string
    {
        return str_replace(['{id}', '{slug}'], ['(?P<id>' . ($endpoint['auth'] === 'attempt' ? '[a-f0-9]{32}' : '[0-9]+') . ')', '(?P<slug>[a-z][a-z0-9-]{1,63})'], $endpoint['path']);
    }

    public static function state(array $endpoint): string
    {
        if (!ApiSettings::get()['enabled']) return 'API disabled';
        if ($endpoint['group'] === 'authentication') {
            $s = ApiSettings::get();
            if ($endpoint['key'] === 'auth_register') return $s['registration_password_enabled'] ? 'Active' : 'WordPress registration disabled';
            if ($endpoint['key'] === 'auth_register_social') return $s['registration_social_enabled'] && ($s['google_login_enabled'] || $s['apple_login_enabled']) ? 'Active' : 'Social registration disabled';
            if ($endpoint['key'] === 'auth_register_oauth') return $s['registration_oauth_enabled'] && $s['oauth_enabled'] ? 'Active' : 'OAuth registration disabled';
            if (in_array($endpoint['key'], ['auth_registration_status', 'auth_registration_resend'], true)) return PatientRegistration::enabled() ? 'Active' : 'App registration disabled';
            if ($endpoint['key'] === 'auth_login') return $s['password_login_enabled'] ? 'Active' : 'WordPress app login disabled';
            if (in_array($endpoint['key'], ['password_forgot', 'password_reset'], true)) return $s['password_login_enabled'] ? 'Active' : 'WordPress app login disabled';
            if (in_array($endpoint['key'], ['auth_challenge', 'auth_social'], true)) return ($s['google_login_enabled'] || $s['apple_login_enabled']) ? 'Active' : 'Social login disabled';
            return ($s['password_login_enabled'] || $s['google_login_enabled'] || $s['apple_login_enabled']) ? 'Active' : 'WordPress-issued sessions disabled';
        }
        if (!ApiSettings::groupEnabled($endpoint['group'])) return 'Feature group disabled';
        if ($endpoint['group'] === 'patient_booking' && !ApiSettings::groupEnabled('booking')) return 'Booking payment and progress group must be enabled';
        if ($endpoint['group'] === 'onboarding' && PatientOnboardingApi::configured() === null) return 'No onboarding scheme published';
        if (in_array($endpoint['group'], ['discovery', 'booking', 'patient_booking'], true) && ApiSettings::get()['appointment_types'] === []) return 'No appointment types published';
        if (in_array($endpoint['group'], ['templates', 'booking', 'patient_booking'], true) && ApiSettings::get()['form_templates'] === []) return 'No form templates published';
        return 'Active';
    }

    public static function args(array $endpoint): array
    {
        $args = [];
        foreach ($endpoint['fields'] as $name => $field) {
            if ($field['in'] === 'header') continue;
            $schema = $field['schema'];
            // Multipart files live in WP_REST_Request::get_file_params(),
            // not get_param(), so the route boundary cannot enforce their
            // presence through the normal JSON argument validator. The
            // upload controller performs that check instead; OpenAPI still
            // advertises the file as required.
            $schema['required'] = ($endpoint['key'] === 'attachments_upload' && $name === 'file') ? false : (bool) ($schema['required'] ?? false);
            $validation = $schema;
            unset($validation['required'], $validation['default']);
            if ($name === 'patient') { $validation = self::patientSchema(false); if ($endpoint['key'] === 'booking_flow_create') $validation['required'] = []; }
            if ($name === 'content') $validation['required'] = ['sections'];
            $schema['validate_callback'] = static function ($value) use ($validation, $name) {
                $result = rest_validate_value_from_schema($value, $validation, $name);
                if (is_wp_error($result)) return $result;
                if (($validation['format'] ?? '') === 'date') {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) return new \WP_Error('invalid_date', 'Use a real calendar date in YYYY-MM-DD format.');
                }
                return true;
            };
            $args[$name] = $schema;
        }
        return $args;
    }

    public static function openApi(): array
    {
        $paths = [];
        foreach (self::definitions() as $endpoint) {
            if (self::state($endpoint) !== 'Active') continue;
            $operation = ['operationId' => $endpoint['key'], 'summary' => $endpoint['summary'], 'description' => $endpoint['notes'], 'tags' => [$endpoint['group']], 'parameters' => [], 'responses' => []];
            $body = ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
            foreach ($endpoint['fields'] as $name => $field) {
                $schema = $field['schema'];
                $required = (bool) ($schema['required'] ?? false);
                unset($schema['required']);
                // Restore nested-object required lists from canonical definitions.
                if ($name === 'patient') { $schema = self::patientSchema(false); if ($endpoint['key'] === 'booking_flow_create') $schema['required'] = []; }
                if ($name === 'content') $schema['required'] = ['sections'];
                if ($field['in'] === 'body') {
                    $body['properties'][$name] = $schema;
                    if ($required) $body['required'][] = $name;
                } else $operation['parameters'][] = ['name' => $name, 'in' => $field['in'], 'required' => $required, 'schema' => $schema];
            }
            if ($body['properties'] !== []) {
                $contentType = $endpoint['key'] === 'attachments_upload' ? 'multipart/form-data' : 'application/json';
                $operation['requestBody'] = ['required' => true, 'content' => [$contentType => ['schema' => $body, 'example' => $endpoint['example']]]];
            }
            if ($endpoint['auth'] === 'patient') $operation['security'] = [['oauthBearer' => []]];
            elseif ($endpoint['auth'] === 'attempt') $operation['security'] = [['oauthBearer' => [], 'bookingAttempt' => []]];
            else $operation['security'] = [];
            $operation['x-required-scope'] = $endpoint['scope'];
            $successStatus = (string) ($endpoint['success_status'] ?? 200);
            $operation['responses'][$successStatus] = ['description' => $successStatus === '202' ? 'Accepted; verification/approval is not complete' : 'Success', 'content' => ['application/json' => ['schema' => $endpoint['response_schema'], 'example' => ['ok' => true, 'data' => $endpoint['response'], 'meta' => ['request_id' => 'example-request-id']]]]];
            foreach ([400 => 'Invalid parameters', 401 => 'Authentication required', 402 => 'Payment declined', 403 => 'Access denied', 404 => 'Resource unavailable', 409 => 'Operation conflict', 413 => 'Payload too large', 422 => 'Validation/payment rejected', 429 => 'Rate limit reached', 500 => 'Upstream SDK configuration or request failure', 503 => 'Dependency unavailable'] as $code => $description) {
                $operation['responses'][(string) $code] = ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
            }
            $paths[$endpoint['path']][strtolower($endpoint['method'])] = $operation;
        }
        return ['openapi' => '3.0.3', 'info' => ['title' => 'Cliniko Third-Party API', 'version' => ApiSettings::VERSION], 'servers' => [['url' => rest_url(ApiSettings::namespace())]], 'paths' => (object) $paths, 'components' => ['securitySchemes' => ['oauthBearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Plugin-issued patient JWT or configured-provider OAuth 2.0 access token. Requires an approved app, allowed scope and verified active patient. Social ID tokens must first be exchanged at /auth/social.'], 'bookingAttempt' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-ES-Attempt-Token', 'description' => 'Private booking attempt secret. Must be combined with the original verified patient Bearer token from the same approved app.']], 'schemas' => ['Error' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object', 'additionalProperties' => true]]], 'meta' => ['type' => 'object', 'properties' => ['request_id' => ['type' => 'string']]]]]]]];
    }

    private static function inferSchema($value): array
    {
        if (is_array($value)) {
            if (array_is_list($value) && $value !== []) return ['type' => 'array', 'items' => self::inferSchema($value[0])];
            if ($value === []) return ['type' => 'array', 'items' => ['type' => 'object']];
            return ['type' => 'object', 'properties' => array_map([self::class, 'inferSchema'], $value)];
        }
        if ($value === null) return ['nullable' => true];
        return ['type' => is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string')];
    }
}
