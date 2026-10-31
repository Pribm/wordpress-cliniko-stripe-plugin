<?php
declare(strict_types=1);

/** Memory-only regression for the real web save handler and shared API parameters. */
define('CLINIKO_API_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/managed_api_test.php';

use App\Admin\Modules\PatientBookingForms;
use App\Service\Api\BookingFlows;
use App\Service\PatientBookingConfiguration;

final class BookingFixtureRedirect extends RuntimeException {}
function check_admin_referer(string $action): void {
    if ($action !== 'save_patient_booking_form' || empty($GLOBALS['booking_fixture_nonce'])) throw new RuntimeException('Invalid nonce');
}
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
function esc_url_raw(string $value): string { return $value; }
function wp_safe_redirect(string $url): void { throw new BookingFixtureRedirect($url); }

resetApi();
$GLOBALS['current_user'] = 7;
$GLOBALS['booking_fixture_nonce'] = true;
$GLOBALS['cliniko_pages']['patient_form_templates/456'] = ['id' => '456', 'name' => 'Questions', 'content' => ['sections' => [['name' => 'Details', 'questions' => [['name' => 'Symptoms', 'type' => 'text', 'required' => true]]]]]];
$common = ['name' => 'Independent app flow', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'scheduling_mode' => 'manual'];
check(BookingFlows::saveApi('app-flow', $common, false) === true, 'API draft failed');
$apiBefore = BookingFlows::all();
$failed = 0;
try {
    foreach (['patient', 'guest'] as $mode) {
        $_POST = array_replace($common, [
            'id' => 'website-' . $mode, 'name' => 'Website ' . $mode, 'booking_mode' => $mode,
            'multistep' => 'no', 'show_calendar' => 'no', 'show_practitioner' => 'no', 'show_patient_details_review' => 'yes',
            'success_action' => 'redirect', 'success_message' => 'Booked', 'success_redirect_url' => 'https://example.test/success',
            'failure_action' => 'message', 'failure_message' => 'Try again', 'custom_css' => '.form {color:red}', 'custom_js' => 'console.log("web only")',
            'input_rules' => ['question_0_0' => ['max_length' => 20]],
        ]);
        try { PatientBookingForms::save(); throw new RuntimeException('Web save did not redirect'); }
        catch (BookingFixtureRedirect $redirect) { check(str_contains($redirect->getMessage(), 'booking_section=' . $mode), 'Original web redirect changed'); }
        $saved = PatientBookingConfiguration::all()['website-' . $mode];
        check($saved['flow_type'] === 'web' && $saved['booking_mode'] === $mode, 'Web origin or patient/guest mode missing');
        foreach (['multistep', 'show_calendar', 'show_practitioner', 'show_patient_details_review', 'success_action', 'success_message', 'success_redirect_url', 'failure_action', 'failure_message', 'custom_css', 'custom_js'] as $field) check($saved[$field] === $_POST[$field], 'Web setting lost: ' . $field);
        check($saved['input_rules']['question_0_0']['max_length'] === 20, 'Shared answer rules lost');
        check(BookingFlows::all() === $apiBefore, 'Web save altered independent API storage');
    }
    $before = PatientBookingConfiguration::all();
    $GLOBALS['booking_fixture_nonce'] = false;
    try { PatientBookingForms::save(); throw new RuntimeException('Invalid nonce accepted'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Invalid nonce', 'Unexpected nonce failure'); }
    check(PatientBookingConfiguration::all() === $before, 'Nonce rejection wrote settings');
    $GLOBALS['current_user'] = 0;
    try { PatientBookingForms::save(); throw new RuntimeException('Non-admin accepted'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Unauthorized', 'Unexpected capability failure'); }
    check(PatientBookingConfiguration::all() === $before && BookingFlows::all() === $apiBefore, 'Unauthorized save wrote settings');
    echo "[PASS] Real web save handler marks web origin, preserves presentation and shared rules, leaves API flows independent, and rejects nonce/capability failures\n";
} catch (Throwable $error) { $failed++; echo '[FAIL] ' . $error->getMessage() . "\n"; }
exit($failed ? 1 : 0);
