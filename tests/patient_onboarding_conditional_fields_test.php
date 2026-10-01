<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

use App\Admin\Modules\AccountBuilders\Forms\PatientOnboarding;

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $value, string $fallback = ''): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $value): string
    {
        return trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value)), '-');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea($value): string
    {
        return esc_html($value);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($value): string
    {
        return esc_attr($value);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action): void
    {
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action) . '">';
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $echo = true): string
    {
        $result = $checked == $current ? 'checked="checked"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, bool $echo = true): string
    {
        $result = $selected == $current ? 'selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

function assert_onboarding_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function onboarding_private(string $method, array $arguments)
{
    $reflection = new ReflectionMethod(PatientOnboarding::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs(null, $arguments);
}

$available = [
    'medicare_number' => [
        'label' => 'Medicare number',
        'type' => 'text',
        'editable' => true,
    ],
    'ihi_number' => [
        'label' => 'Individual healthcare identifier',
        'type' => 'text',
        'editable' => true,
    ],
];

$steps = onboarding_private('normaliseSteps', [[[
    'id' => 'identifiers',
    'title' => 'Health identifiers',
    'fields' => [
        [
            'kind' => 'switch',
            'key' => 'switch_medicare',
            'label' => 'Do you have a Medicare number?',
            'on_label' => 'I have Medicare',
            'off_label' => 'I do not have Medicare',
            'default_value' => 'on',
            'required' => true,
            'width' => 100,
        ],
        [
            'key' => 'medicare_number',
            'label' => 'Medicare number',
            'required' => true,
            'condition_switch' => 'switch_medicare',
            'condition_value' => 'on',
            'input_format' => 'numbers',
        ],
        [
            'key' => 'ihi_number',
            'label' => 'IHI number',
            'required' => false,
            'condition_switch' => 'switch_medicare',
            'condition_value' => 'off',
        ],
    ],
]], $available]);

$fields = $steps[0]['fields'] ?? [];
assert_onboarding_same('switch', $fields[0]['kind'] ?? null, 'The switch must survive configuration normalisation.');
assert_onboarding_same('I have Medicare', $fields[0]['on_label'] ?? null, 'The first option label must survive configuration normalisation.');
assert_onboarding_same('I do not have Medicare', $fields[0]['off_label'] ?? null, 'The second option label must survive configuration normalisation.');
assert_onboarding_same('on', $fields[0]['default_value'] ?? null, 'The configured default option must survive configuration normalisation.');
assert_onboarding_same('switch_medicare', $fields[1]['condition_switch'] ?? null, 'The dependent field must retain its controlling switch.');
assert_onboarding_same('off', $fields[2]['condition_value'] ?? null, 'Off-state conditions must survive normalisation.');

$effectiveDefaults = onboarding_private('effectiveSwitchValues', [$steps, []]);
assert_onboarding_same(['switch_medicare' => true], $effectiveDefaults, 'An unanswered choice must initially use its configured default.');
$missingBeforeAnswer = onboarding_private('missingRequiredFields', [$steps, [], $effectiveDefaults, []]);
assert_onboarding_same(
    [
        ['key' => 'switch_medicare', 'label' => 'Do you have a Medicare number?'],
        ['key' => 'medicare_number', 'label' => 'Medicare number'],
    ],
    $missingBeforeAnswer,
    'The default must control initial dependent-field visibility without counting the required choice as submitted.'
);

$missingWhenOff = onboarding_private('missingRequiredFields', [$steps, [], ['switch_medicare' => false]]);
assert_onboarding_same([], $missingWhenOff, 'An Off switch must hide and waive the On-only required field.');

$missingWhenOn = onboarding_private('missingRequiredFields', [$steps, [], ['switch_medicare' => true]]);
assert_onboarding_same(
    [['key' => 'medicare_number', 'label' => 'Medicare number']],
    $missingWhenOn,
    'An On switch must activate the dependent required field.'
);

$completeWhenOn = onboarding_private('missingRequiredFields', [
    $steps,
    ['medicare_number' => '1234 56789 1'],
    ['switch_medicare' => true],
]);
assert_onboarding_same([], $completeWhenOn, 'A populated active dependent field must complete the flow.');

$errorsWhenOff = onboarding_private('validateRequiredFields', [$steps, [], ['switch_medicare' => false]]);
assert_onboarding_same([], $errorsWhenOff, 'Server validation must ignore hidden dependent fields.');

$errorsWhenOn = onboarding_private('validateRequiredFields', [$steps, [], ['switch_medicare' => true]]);
assert_onboarding_same(['Medicare number is required.'], $errorsWhenOn, 'Server validation must require visible dependent fields.');

$formatErrorsWhenHidden = onboarding_private('validateInputRules', [
    $steps,
    ['medicare_number' => 'not-a-number'],
    ['switch_medicare' => false],
]);
assert_onboarding_same([], $formatErrorsWhenHidden, 'Input rules must not reject a stale value from a hidden field.');

$formatErrorsWhenVisible = onboarding_private('validateInputRules', [
    $steps,
    ['medicare_number' => 'not-a-number'],
    ['switch_medicare' => true],
]);
assert_onboarding_same(['Medicare number can contain numbers only.'], $formatErrorsWhenVisible, 'Input rules must still validate visible conditional fields.');

$offConditionSteps = onboarding_private('normaliseSteps', [[[
    'id' => 'alternative-identifier',
    'title' => 'Alternative identifier',
    'fields' => [
        ['kind' => 'switch', 'key' => 'switch_ihi', 'label' => 'Do you have an IHI?', 'required' => true],
        [
            'key' => 'ihi_number',
            'label' => 'IHI number',
            'required' => true,
            'condition_switch' => 'switch_ihi',
            'condition_value' => 'off',
        ],
    ],
]], $available]);
assert_onboarding_same(
    [['key' => 'ihi_number', 'label' => 'IHI number']],
    onboarding_private('missingRequiredFields', [$offConditionSteps, [], ['switch_ihi' => false]]),
    'An Off-state rule must activate when its switch is Off.'
);
assert_onboarding_same(
    [],
    onboarding_private('missingRequiredFields', [$offConditionSteps, [], ['switch_ihi' => true]]),
    'An Off-state required field must be waived while its switch is On.'
);

$renderOnboarding = static function (array $renderSwitchValues) use ($steps, $available): string {
    $id = 'identifier-flow';
    $module = ['mode' => 'multistep', 'placement' => 'inline'];
    $definitions = $available;
    $values = [];
    $switchValues = $renderSwitchValues;
    $missing = [['key' => 'switch_medicare', 'label' => 'Do you have a Medicare number?']];
    $messageState = '';
    $patientSaveAction = 'save_patient';
    $returnTo = 'https://example.test/account';
    $welcomeContent = '';
    $instanceId = 'identifier-flow-instance';
    ob_start();
    require __DIR__ . '/../src/Admin/Modules/AccountBuilders/Forms/PatientOnboarding/ShortCodeTemplates/patient-onboarding.phtml';
    return (string) ob_get_clean();
};

$renderedOff = $renderOnboarding(['switch_medicare' => false]);
assert_onboarding_same(true, str_contains($renderedOff, 'data-onboarding-switch="switch_medicare"'), 'The template must render the switch controller.');
assert_onboarding_same(true, str_contains($renderedOff, '>I have Medicare</span>'), 'The template must render the configured first option label.');
assert_onboarding_same(true, str_contains($renderedOff, '>I do not have Medicare</span>'), 'The template must render the configured second option label.');
assert_onboarding_same(true, str_contains($renderedOff, 'data-onboarding-condition-switch="switch_medicare"'), 'The template must identify the controlling switch.');
assert_onboarding_same(true, str_contains($renderedOff, 'data-onboarding-condition-required="1" hidden aria-hidden="true"'), 'An inactive dependent required field must start hidden and non-blocking.');
preg_match('/<input[^>]+name="medicare_number"[^>]*>/', $renderedOff, $offInput);
assert_onboarding_same(false, str_contains($offInput[0] ?? '', ' required'), 'A hidden conditional input must not retain native required validation.');

$renderedOn = $renderOnboarding(['switch_medicare' => true]);
assert_onboarding_same(false, str_contains($renderedOn, 'data-onboarding-condition-required="1" hidden'), 'An active dependent field must render visibly.');
assert_onboarding_same(true, str_contains($renderedOn, 'name="medicare_number" type="text"'), 'The active dependent patient input must be rendered.');
preg_match('/<input[^>]+name="medicare_number"[^>]*>/', $renderedOn, $onInput);
assert_onboarding_same(true, str_contains($onInput[0] ?? '', ' required'), 'A visible conditional required input must retain native required validation.');
preg_match('/<input[^>]+value="1"[^>]+data-onboarding-switch="switch_medicare"[^>]*>/', $renderedOn, $defaultOnInput);
assert_onboarding_same(true, str_contains($defaultOnInput[0] ?? '', 'checked="checked"'), 'The effective default selection must be rendered as selected.');

echo "Patient onboarding conditional field tests passed.\n";
