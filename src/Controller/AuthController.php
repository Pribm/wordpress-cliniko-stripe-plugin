<?php
namespace App\Controller;

use App\DTO\CreatePatientDTO;
use App\Service\ClinikoService;
use App\Service\NotificationService;
use App\Validator\PatientRequestValidator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH'))
    exit;

class AuthController
{

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();

        $creds = [
            'user_login' => sanitize_text_field($params['email'] ?? ''),
            'user_password' => $params['password'] ?? '',
            'remember' => true,
        ];

        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $user->get_error_message(),
            ], 401);
        }

        // Fetch Cliniko patient ID from usermeta
        $clinikoId = get_user_meta($user->ID, 'cliniko_patient_id', true);

        return new WP_REST_Response([
            'success' => true,
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'cliniko_patient_id' => $clinikoId ?: null,
        ], 200);
    }

   public function register(WP_REST_Request $request): WP_REST_Response
{
    $payload = $request->get_param('patient');
    $errors = PatientRequestValidator::validate($payload);

    if (!empty($errors)) {
        return new WP_REST_Response([
            'success' => false,
            'errors' => $errors,
        ], 422);
    }

    $email = sanitize_email($payload['email']);
    $firstName = sanitize_text_field($payload['first_name']);
    $lastName = sanitize_text_field($payload['last_name']);
    $password = $payload['password'];
    $password2 = $payload['password_confirmation'];

    if ($password !== $password2) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Passwords do not match.'
        ], 422);
    }

    if (email_exists($email)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'An account with this email already exists.'
        ], 409);
    }

    // Create WP user
    $user_id = wp_create_user($email, $password, $email);
    if (is_wp_error($user_id)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to create user: ' . $user_id->get_error_message()
        ], 500);
    }

    // Save name
    wp_update_user([
        'ID' => $user_id,
        'first_name' => $firstName,
        'last_name'  => $lastName,
    ]);

    // Mark as unverified
    update_user_meta($user_id, 'email_verified', false);

    // Generate confirmation token
    $token = wp_generate_password(32, false);
    update_user_meta($user_id, 'email_verification_token', $token);

    $verifyUrl = site_url("/wp-json/v1/confirm-email?token={$token}");

    // Send confirmation email
   $notifier = new NotificationService();
$notifier->sendConfirmation([
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'email'      => $email,
], $token);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Account created. Please check your email to confirm before continuing.'
    ], 201);
}

public function confirmEmail(WP_REST_Request $request): WP_REST_Response
{
    $token = sanitize_text_field($request->get_param('token'));

    $users = get_users([
        'meta_key'   => 'email_verification_token',
        'meta_value' => $token,
        'number'     => 1,
    ]);

    if (empty($users)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid or expired token.'
        ], 400);
    }

    $user = $users[0];

    // Mark verified
    update_user_meta($user->ID, 'email_verified', true);
    delete_user_meta($user->ID, 'email_verification_token');

    // Create Cliniko patient now
    $dto = new CreatePatientDTO();
    $dto->firstName = $user->first_name;
    $dto->lastName  = $user->last_name;
    $dto->email     = $user->user_email;

    try {
        $clinikoService = new ClinikoService();
        $patient = $clinikoService->findOrCreatePatient($dto);
    } catch (\Throwable $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Cliniko error: ' . $e->getMessage(),
        ], 500);
    }

    if (!$patient) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to create Cliniko patient.'
        ], 500);
    }

    update_user_meta($user->ID, 'cliniko_patient_id', $patient->getId());

    // Auto-login
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Email confirmed and patient created.',
        'user_id' => $user->ID,
        'cliniko_patient_id' => $patient->getId(),
    ], 200);
}


}
