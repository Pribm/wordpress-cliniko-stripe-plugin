<?php

namespace App\Admin\Modules\ApiAccess;

if (!defined('ABSPATH')) exit;

/** Presentation only: the route catalog and access policy remain authoritative. */
final class EndpointDocumentation
{
    public static function categories(): array
    {
        return [
            'system' => ['title' => 'System & capabilities', 'description' => 'Check whether the API is available and which features are enabled.'],
            'discovery' => ['title' => 'Services & availability', 'description' => 'Browse published appointment types, practitioners and available times.'],
            'templates' => ['title' => 'Blank form templates', 'description' => 'Get published intake forms, not completed patient answers.'],
            'booking' => ['title' => 'Booking payment & progress', 'description' => 'Pay, finish or check your own booking. Every request requires the original verified patient’s login token and, for an attempt, its private booking secret. Guest bookings are not exposed by this API.'],
            'patient' => ['title' => 'Patient profile & appointments', 'description' => 'Read and update only the signed-in, verified patient’s own profile, or read their appointments.'],
            'patient_booking' => ['title' => 'Patient booking', 'description' => 'Start a booking using the signed-in patient’s verified identity.'],
            'booking_flows' => ['title' => 'Published booking structures', 'description' => 'Create API-only setups in Booking flows, read their structure-only JSON, then submit answers through the protected booking route. No shortcode, presentation settings or patient identity is required or returned. Legacy website links remain explicitly labelled.'],
            'onboarding' => ['title' => 'Patient profile onboarding', 'description' => 'Read the authenticated patient profile schema, start a short-lived completion session and save missing details before booking.'],
            'sessions' => ['title' => 'Login, password recovery & sessions', 'description' => 'Sign in, recover a forgotten password, exchange social sign-in proof, renew a session or sign out.'],
            'registration' => ['title' => 'Registration & verification', 'description' => 'Create a pending account and follow its email-verification progress. Registration does not grant access to patient data.'],
            'other' => ['title' => 'Other endpoints', 'description' => 'Additional managed API operations.'],
        ];
    }

    public static function category(array $endpoint): string
    {
        if (in_array($endpoint['key'], ['booking_flow', 'booking_flow_create'], true)) return 'booking_flows';
        if ($endpoint['group'] === 'onboarding') return 'onboarding';
        if ($endpoint['group'] === 'authentication') {
            return str_starts_with($endpoint['key'], 'auth_register') || str_starts_with($endpoint['key'], 'auth_registration') ? 'registration' : 'sessions';
        }
        return isset(self::categories()[$endpoint['group']]) ? $endpoint['group'] : 'other';
    }

    public static function access(array $endpoint): array
    {
        if ($endpoint['auth'] === 'public') {
            return ['kind' => 'public', 'label' => 'Public · no credentials required',
                'proof' => 'None. A patient does not need to sign in for this operation.',
                'send' => 'No Authorization header is required.',
                'note' => 'If you choose to send a Bearer credential, it must be valid and include the permission listed below. Public does not mean unrestricted: enabled features, publication settings, HTTPS, request limits and browser-origin rules still apply.'];
        }
        if ($endpoint['auth'] === 'patient') {
            return ['kind' => 'patient', 'label' => 'Required · patient access token',
                'proof' => 'A valid patient JWT issued by this plugin, or an access token from the configured external OAuth sign-in service. The patient must be active, verified and linked to Cliniko.',
                'send' => 'Authorization: Bearer <patient-access-token>',
                'note' => 'The token and approved application must grant the permission listed below. Server API keys, social ID tokens, registration receipts and a WordPress browser login alone cannot authorize this route. Social ID tokens must first be exchanged at /auth/social; external identities need an approved patient link.'];
        }
        if ($endpoint['auth'] === 'attempt') {
            return ['kind' => 'attempt', 'label' => 'Required · patient token + booking secret',
                'proof' => 'Both the original verified patient’s access token and the private attempt token returned when this booking was created. Knowing the booking ID or holding the booking secret alone is not enough. Attempt access expires after 24 hours.',
                'send' => "Authorization: Bearer <patient-access-token>\nX-ES-Attempt-Token: <attempt-token>",
                'note' => 'Use the same approved application and the original active, verified patient. The linked Cliniko patient must still match this booking. The token and application need the permission listed below. Guest attempts, server API keys and website-only attempts are rejected. ' . ($endpoint['key'] === 'finalize' ? 'Also send Idempotency-Key to prevent duplicate completion; this retry key is not authentication.' : '')];
        }

        $bodyNote = 'Send these fields in the JSON request body, not in a URL. Do not send an Authorization header: these operations reject it. Use an enabled, approved application and login or registration method.';
        $access = match ($endpoint['key']) {
            'auth_login' => ['kind' => 'credentials', 'label' => 'Required · patient password',
                'proof' => 'The existing patient’s WordPress username or email and password, plus the approved Patient app ID. No existing patient access token is needed.',
                'send' => 'JSON: client_id, identifier, password'],
            'password_forgot' => ['kind' => 'password_reset', 'label' => 'Required · patient app ID + email',
                'proof' => 'An approved Patient app ID and the email address supplied by the patient. No patient access token is needed. The server gives the same answer for an existing or unknown account.',
                'send' => 'JSON: client_id, email'],
            'password_reset' => ['kind' => 'password_reset', 'label' => 'Required · one-time reset key',
                'proof' => 'An approved Patient app ID, plus the login and one-time key from the WordPress password-reset email. No existing patient access token is needed.',
                'send' => 'JSON: client_id, login, key, new_password'],
            'auth_challenge' => ['kind' => 'app', 'label' => 'App ID only · no patient proof yet',
                'proof' => 'An approved Patient app ID and enabled Google or Apple provider. The app ID is public, not a secret or proof of patient identity. This step issues a challenge, not patient access.',
                'send' => 'JSON: client_id, provider, purpose (optional; login or register)'],
            'auth_social' => ['kind' => 'social', 'label' => 'Required · social sign-in proof',
                'proof' => 'A provider-signed Google or Apple ID token tied to a single-use login challenge from /auth/challenge. The provider identity must already be linked to a verified patient.',
                'send' => 'JSON: client_id, provider, challenge_id, id_token'],
            'auth_refresh', 'auth_logout' => ['kind' => 'refresh', 'label' => 'Required · private refresh token',
                'proof' => 'The private refresh token from a plugin-issued patient session, plus its original approved app ID. An access token alone is not enough. These routes do not renew or revoke an external OAuth provider session.',
                'send' => 'JSON: client_id, refresh_token'],
            'auth_register' => ['kind' => 'registration', 'label' => 'Registration · new account details',
                'proof' => 'No existing patient session is needed. Supply the approved Patient app ID, new account details and explicit privacy consent. The app needs account:register permission. Email verification is still required before patient access.',
                'send' => 'JSON: client_id, first_name, last_name, email, password, accept_privacy_policy: true'],
            'auth_register_social' => ['kind' => 'social', 'label' => 'Required · social sign-in proof',
                'proof' => 'A Google or Apple ID token with a verified email and a single-use registration challenge. Request /auth/challenge with purpose: register first. The approved app needs account:register permission. Clinic email verification is still required.',
                'send' => 'JSON: client_id, first_name, last_name, accept_privacy_policy: true, provider, challenge_id, id_token'],
            'auth_register_oauth' => ['kind' => 'oauth', 'label' => 'Required · OAuth access token',
                'proof' => 'A valid external OAuth access token with a verified email, from the configured provider. Both the provider token and approved OAuth app need account:register permission. Clinic email verification is still required.',
                'send' => 'JSON: client_id (internal app ID from this dashboard), first_name, last_name, accept_privacy_policy: true, access_token'],
            'auth_registration_status', 'auth_registration_resend' => ['kind' => 'receipt', 'label' => 'Required · private registration receipt',
                'proof' => 'The private registration_token returned at signup and the original approved app ID. The receipt lasts 24 hours. It only lets this app follow onboarding; it cannot access patient data.',
                'send' => 'JSON: client_id, registration_token'],
            default => ['kind' => 'unknown', 'label' => 'Access requirements · review details',
                'proof' => 'Check the request fields and route implementation before using this operation.', 'send' => 'See the request fields below.'],
        };
        if ($endpoint['key'] === 'auth_challenge') $bodyNote .= ' A registration challenge also requires account:register permission and enabled social registration.';
        return $access + ['note' => $endpoint['group'] === 'authentication' ? $bodyNote : ''];
    }
}
