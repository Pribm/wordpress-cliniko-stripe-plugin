<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');
require __DIR__ . '/../vendor/autoload.php';

use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;

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
if (!function_exists('esc_attr')) {
    function esc_attr($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function assert_shortcode_rule_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$select = ShortcodeFormInputRules::normalise([
    'input_type' => 'select',
    'select_options' => "Red\nBlue\nRed\n\nGreen",
]);
assert_shortcode_rule_same(['Red', 'Blue', 'Green'], $select['select_options'], 'Dropdown options must use one line per unique option.');
assert_shortcode_rule_same([], ShortcodeFormInputRules::validateAndNormaliseValue('Blue', $select, 'Colour')['errors'], 'A configured dropdown option must validate.');
assert_shortcode_rule_same(['Colour must use one of the configured options.'], ShortcodeFormInputRules::validateAndNormaliseValue('Purple', $select, 'Colour')['errors'], 'An unknown dropdown option must be rejected.');

$date = ShortcodeFormInputRules::normalise(['input_type' => 'date', 'date_format' => 'dmy_slash']);
$validDate = ShortcodeFormInputRules::validateAndNormaliseValue('29/02/2024', $date, 'Date of birth');
assert_shortcode_rule_same('2024-02-29', $validDate['value'], 'A displayed date must be converted to Cliniko ISO format.');
assert_shortcode_rule_same([], $validDate['errors'], 'A real leap-day date must validate.');
assert_shortcode_rule_same(['Date of birth is not a valid date.'], ShortcodeFormInputRules::validateAndNormaliseValue('29/02/2023', $date, 'Date of birth')['errors'], 'An impossible displayed date must be rejected.');

$phone = ShortcodeFormInputRules::normalise([
    'input_format' => 'pattern',
    'mask_prefix' => '+61',
    'mask_pattern' => '4## ### ###',
]);
assert_shortcode_rule_same([], ShortcodeFormInputRules::validateAndNormaliseValue('+61412 345 678', $phone, 'Phone')['errors'], 'A fixed starting digit must be treated as a literal, not a repeated entered digit.');
assert_shortcode_rule_same(['Phone does not match the requested format.'], ShortcodeFormInputRules::validateAndNormaliseValue('+61512 345 678', $phone, 'Phone')['errors'], 'A value that changes the fixed starting digit must fail the mask.');
assert_shortcode_rule_same(true, str_contains(ShortcodeFormInputRules::inputAttributes($phone), 'data-cliniko-mask="4## ### ###"'), 'The frontend mask attributes must contain the configured pattern.');
$alternateLiteral = ShortcodeFormInputRules::normalise(['input_format' => 'pattern', 'mask_prefix' => '+1', 'mask_pattern' => '7##-##']);
assert_shortcode_rule_same([], ShortcodeFormInputRules::validateAndNormaliseValue('+1712-34', $alternateLiteral, 'Alternate phone')['errors'], 'Fixed-literal handling must work with any configured starting number.');

$numbers = ShortcodeFormInputRules::normalise(['input_format' => 'numbers', 'max_length' => 4]);
assert_shortcode_rule_same([], ShortcodeFormInputRules::validateAndNormaliseValue('1234', $numbers, 'Reference')['errors'], 'A number-only value within its limit must validate.');
assert_shortcode_rule_same(['Reference must be no more than 4 characters.', 'Reference can contain numbers only.'], ShortcodeFormInputRules::validateAndNormaliseValue('1234A', $numbers, 'Reference')['errors'], 'Character limits and number-only validation must both be enforced.');

echo "Shared shortcode input rule tests passed.\n";
