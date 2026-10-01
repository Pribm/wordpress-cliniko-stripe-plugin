<?php

namespace App\Admin\Modules\AccountBuilders\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared input presentation, masking, and validation for form shortcodes.
 */
final class ShortcodeFormInputRules
{
    /** @param array<string,mixed> $config @param array<string,bool> $capabilities @return array<string,mixed> */
    public static function normalise(array $config, array $capabilities = []): array
    {
        $capabilities = array_merge([
            'text' => true,
            'date' => true,
            'select' => true,
            'limit' => true,
            'format' => true,
        ], $capabilities);

        $inputType = sanitize_key((string) ($config['input_type'] ?? 'default'));
        if (!in_array($inputType, ['default', 'text', 'date', 'select'], true)
            || ($inputType !== 'default' && empty($capabilities[$inputType]))) {
            $inputType = 'default';
        }

        $selectOptions = $inputType === 'select'
            ? self::normaliseSelectOptions($config['select_options'] ?? [])
            : [];
        if ($inputType === 'select' && $selectOptions === []) {
            $inputType = 'default';
        }

        $usesTextRules = !in_array($inputType, ['date', 'select'], true);
        $inputFormat = !empty($capabilities['format']) && $usesTextRules
            ? self::normaliseInputFormat($config['input_format'] ?? 'none')
            : 'none';
        $maskPattern = $inputFormat === 'pattern'
            ? self::normaliseText($config['mask_pattern'] ?? '', 80)
            : '';
        $maskPrefix = $inputFormat === 'pattern'
            ? self::normaliseText($config['mask_prefix'] ?? '', 30)
            : '';
        $maskSuffix = $inputFormat === 'pattern'
            ? self::normaliseText($config['mask_suffix'] ?? '', 30)
            : '';
        if ($inputFormat === 'pattern' && !preg_match('/[#A*]/', $maskPattern)) {
            $inputFormat = 'none';
            $maskPattern = '';
            $maskPrefix = '';
            $maskSuffix = '';
        }

        return [
            'input_type' => $inputType,
            'date_format' => $inputType === 'date'
                ? self::normaliseDateFormat($config['date_format'] ?? 'dmy_slash')
                : 'dmy_slash',
            'select_options' => $selectOptions,
            'max_length' => !empty($capabilities['limit']) && $usesTextRules && $inputFormat !== 'pattern'
                ? max(0, min(5000, (int) ($config['max_length'] ?? 0)))
                : 0,
            'input_format' => $inputFormat,
            'mask_pattern' => $maskPattern,
            'mask_prefix' => $maskPrefix,
            'mask_suffix' => $maskSuffix,
        ];
    }

    /** @param mixed $options @return array<int,string> */
    public static function normaliseSelectOptions($options): array
    {
        if (is_string($options)) {
            $options = preg_split('/\r?\n/', $options) ?: [];
        }
        if (!is_array($options)) {
            return [];
        }

        $normalised = [];
        foreach (array_slice($options, 0, 100) as $option) {
            $option = self::normaliseText($option, 150);
            if ($option !== '' && !in_array($option, $normalised, true)) {
                $normalised[] = $option;
            }
        }
        return $normalised;
    }

    public static function normaliseDateFormat($format): string
    {
        $format = sanitize_key((string) $format);
        return in_array($format, ['dmy_slash', 'mdy_slash', 'ymd_dash', 'dmy_dash'], true)
            ? $format
            : 'dmy_slash';
    }

    public static function normaliseInputFormat($format): string
    {
        $format = sanitize_key((string) $format);
        return in_array($format, ['numbers', 'pattern'], true) ? $format : 'none';
    }

    /** @param array<string,mixed> $config */
    public static function inputAttributes(array $config): string
    {
        $attributes = '';
        $maxLength = max(0, min(5000, (int) ($config['max_length'] ?? 0)));
        if ($maxLength > 0) {
            $attributes .= ' maxlength="' . esc_attr((string) $maxLength) . '" data-cliniko-max-length="' . esc_attr((string) $maxLength) . '"';
        }

        $format = self::normaliseInputFormat($config['input_format'] ?? 'none');
        if ($format === 'numbers') {
            $attributes .= ' inputmode="numeric" data-cliniko-input-rule="numbers"';
        } elseif ($format === 'pattern') {
            $pattern = (string) ($config['mask_pattern'] ?? '');
            if ($pattern !== '' && preg_match('/[#A*]/', $pattern)) {
                $prefix = (string) ($config['mask_prefix'] ?? '');
                $suffix = (string) ($config['mask_suffix'] ?? '');
                $example = self::maskExample($pattern, $prefix, $suffix);
                $inputMode = preg_match('/[A*]/', $pattern) ? 'text' : 'numeric';
                $attributes .= ' inputmode="' . esc_attr($inputMode) . '" data-cliniko-input-rule="pattern"';
                $attributes .= ' data-cliniko-mask="' . esc_attr($pattern) . '"';
                $attributes .= ' data-cliniko-mask-prefix="' . esc_attr($prefix) . '"';
                $attributes .= ' data-cliniko-mask-suffix="' . esc_attr($suffix) . '"';
                $attributes .= ' placeholder="' . esc_attr($example) . '"';
            }
        }
        return $attributes;
    }

    /** @param array<string,mixed> $config */
    public static function hint(array $config): string
    {
        $format = self::normaliseInputFormat($config['input_format'] ?? 'none');
        if ($format === 'numbers') {
            return 'Numbers only.';
        }
        if ($format === 'pattern') {
            return 'Format: ' . self::maskExample(
                (string) ($config['mask_pattern'] ?? ''),
                (string) ($config['mask_prefix'] ?? ''),
                (string) ($config['mask_suffix'] ?? '')
            );
        }
        return '';
    }

    public static function datePlaceholder(string $format): string
    {
        return [
            'dmy_slash' => 'DD/MM/YYYY',
            'mdy_slash' => 'MM/DD/YYYY',
            'ymd_dash' => 'YYYY-MM-DD',
            'dmy_dash' => 'DD-MM-YYYY',
        ][self::normaliseDateFormat($format)];
    }

    public static function dateValueForStorage(string $value, string $format): ?string
    {
        $phpFormats = [
            'dmy_slash' => 'd/m/Y',
            'mdy_slash' => 'm/d/Y',
            'ymd_dash' => 'Y-m-d',
            'dmy_dash' => 'd-m-Y',
        ];
        $phpFormat = $phpFormats[self::normaliseDateFormat($format)];
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!' . $phpFormat, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format($phpFormat) !== $value) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    /** @param mixed $value */
    public static function displayDate($value, string $format): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = substr(trim((string) $value), 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            return $value;
        }
        $formats = [
            'dmy_slash' => 'd/m/Y',
            'mdy_slash' => 'm/d/Y',
            'ymd_dash' => 'Y-m-d',
            'dmy_dash' => 'd-m-Y',
        ];
        return $date->format($formats[self::normaliseDateFormat($format)]);
    }

    /** @param mixed $value @param array<string,mixed> $config @return array{value:mixed,errors:array<int,string>} */
    public static function validateAndNormaliseValue($value, array $config, string $label): array
    {
        if (!is_scalar($value)) {
            return ['value' => $value, 'errors' => []];
        }
        $value = (string) $value;
        if (trim($value) === '') {
            return ['value' => $value, 'errors' => []];
        }

        $errors = [];
        if ((string) ($config['input_type'] ?? 'default') === 'date') {
            $normalisedDate = self::dateValueForStorage($value, (string) ($config['date_format'] ?? 'dmy_slash'));
            if ($normalisedDate === null) {
                $errors[] = $label . ' is not a valid date.';
            } else {
                $value = $normalisedDate;
            }
        }

        $maxLength = max(0, (int) ($config['max_length'] ?? 0));
        $actualLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($maxLength > 0 && $actualLength > $maxLength) {
            $errors[] = $label . ' must be no more than ' . $maxLength . ' characters.';
        }

        $format = self::normaliseInputFormat($config['input_format'] ?? 'none');
        if ($format === 'numbers' && preg_match('/^[0-9]+$/', $value) !== 1) {
            $errors[] = $label . ' can contain numbers only.';
        } elseif ($format === 'pattern') {
            $regex = self::maskRegex(
                (string) ($config['mask_pattern'] ?? ''),
                (string) ($config['mask_prefix'] ?? ''),
                (string) ($config['mask_suffix'] ?? '')
            );
            if ($regex === '' || preg_match($regex, $value) !== 1) {
                $errors[] = $label . ' does not match the requested format.';
            }
        }

        if ((string) ($config['input_type'] ?? 'default') === 'select'
            && !in_array($value, array_map('strval', (array) ($config['select_options'] ?? [])), true)) {
            $errors[] = $label . ' must use one of the configured options.';
        }

        return ['value' => $value, 'errors' => $errors];
    }

    /** @param array<string,mixed> $config @param array<string,bool> $capabilities */
    public static function renderAdminControls(string $namePrefix, array $config, array $capabilities = []): void
    {
        $capabilities = array_merge(['text' => true, 'date' => true, 'select' => true, 'limit' => true, 'format' => true], $capabilities);
        $config = self::normalise($config, $capabilities);
        ?>
        <details class="cliniko-shortcode-input-rules" data-cliniko-rule-builder>
            <summary><span>Input behaviour <small>Optional</small></span><em data-rule-summary>Default input</em></summary>
            <div class="cliniko-shortcode-input-rules__body">
                <label>Input type
                    <select name="<?php echo esc_attr($namePrefix . '[input_type]'); ?>" data-rule-input-type>
                        <option value="default" <?php selected($config['input_type'], 'default'); ?>>Use the original field type</option>
                        <?php if (!empty($capabilities['text'])) : ?><option value="text" <?php selected($config['input_type'], 'text'); ?>>Text input</option><?php endif; ?>
                        <?php if (!empty($capabilities['date'])) : ?><option value="date" <?php selected($config['input_type'], 'date'); ?>>Formatted date with calendar</option><?php endif; ?>
                        <?php if (!empty($capabilities['select'])) : ?><option value="select" <?php selected($config['input_type'], 'select'); ?>>Dropdown with custom options</option><?php endif; ?>
                    </select>
                </label>
                <div data-rule-date-settings>
                    <label>Date display format
                        <select name="<?php echo esc_attr($namePrefix . '[date_format]'); ?>" data-rule-date-format>
                            <option value="dmy_slash" <?php selected($config['date_format'], 'dmy_slash'); ?>>DD/MM/YYYY</option>
                            <option value="mdy_slash" <?php selected($config['date_format'], 'mdy_slash'); ?>>MM/DD/YYYY</option>
                            <option value="ymd_dash" <?php selected($config['date_format'], 'ymd_dash'); ?>>YYYY-MM-DD</option>
                            <option value="dmy_dash" <?php selected($config['date_format'], 'dmy_dash'); ?>>DD-MM-YYYY</option>
                        </select>
                    </label>
                </div>
                <div data-rule-select-settings>
                    <label>Dropdown options
                        <textarea name="<?php echo esc_attr($namePrefix . '[select_options]'); ?>" rows="5" placeholder="One option per line" data-rule-select-options><?php echo esc_textarea(implode("\n", (array) $config['select_options'])); ?></textarea>
                        <small>Enter one option per line.</small>
                    </label>
                </div>
                <?php if (!empty($capabilities['limit']) || !empty($capabilities['format'])) : ?>
                    <div class="cliniko-shortcode-input-rules__validation">
                        <?php if (!empty($capabilities['limit'])) : ?>
                            <label>Maximum characters
                                <input type="number" min="0" max="5000" name="<?php echo esc_attr($namePrefix . '[max_length]'); ?>" value="<?php echo esc_attr((string) $config['max_length']); ?>" data-rule-max-length>
                                <small>Use 0 for no limit.</small>
                            </label>
                        <?php endif; ?>
                        <?php if (!empty($capabilities['format'])) : ?>
                            <label>Allowed input
                                <select name="<?php echo esc_attr($namePrefix . '[input_format]'); ?>" data-rule-format>
                                    <option value="none" <?php selected($config['input_format'], 'none'); ?>>Any characters</option>
                                    <option value="numbers" <?php selected($config['input_format'], 'numbers'); ?>>Numbers only</option>
                                    <option value="pattern" <?php selected($config['input_format'], 'pattern'); ?>>Custom pattern</option>
                                </select>
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($capabilities['format'])) : ?>
                    <div class="cliniko-shortcode-mask-builder" data-rule-mask-settings>
                        <div class="cliniko-shortcode-mask-builder__guide">
                            <strong>Pattern guide</strong>
                            <p><code>#</code> number, <code>A</code> letter, <code>*</code> letter or number. Fixed characters stay in place. Example: <code>4## ### ###</code>.</p>
                        </div>
                        <div class="cliniko-shortcode-mask-builder__fields">
                            <label>Fixed prefix <input type="text" name="<?php echo esc_attr($namePrefix . '[mask_prefix]'); ?>" value="<?php echo esc_attr((string) $config['mask_prefix']); ?>" data-rule-mask-prefix placeholder="+61"></label>
                            <label>Pattern <input type="text" name="<?php echo esc_attr($namePrefix . '[mask_pattern]'); ?>" value="<?php echo esc_attr((string) $config['mask_pattern']); ?>" data-rule-mask-pattern placeholder="4## ### ###"></label>
                            <label>Fixed suffix <input type="text" name="<?php echo esc_attr($namePrefix . '[mask_suffix]'); ?>" value="<?php echo esc_attr((string) $config['mask_suffix']); ?>" data-rule-mask-suffix></label>
                        </div>
                        <p class="cliniko-shortcode-mask-builder__preview">Example: <output data-rule-mask-preview>—</output></p>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    public static function enqueueFrontendAssets(): void
    {
        $script = dirname(__DIR__, 3) . '/assets/shortcode-form-input-rules.js';
        $style = dirname(__DIR__, 3) . '/assets/shortcode-form-input-rules.css';
        wp_enqueue_style(
            'cliniko-shortcode-form-input-rules',
            plugins_url('../../../assets/shortcode-form-input-rules.css', __FILE__),
            [],
            file_exists($style) ? (string) filemtime($style) : null
        );
        wp_enqueue_script(
            'cliniko-shortcode-form-input-rules',
            plugins_url('../../../assets/shortcode-form-input-rules.js', __FILE__),
            [],
            file_exists($script) ? (string) filemtime($script) : null,
            true
        );
    }

    public static function enqueueBuilderAssets(): void
    {
        $script = dirname(__DIR__, 3) . '/assets/shortcode-form-rule-builder.js';
        $style = dirname(__DIR__, 3) . '/assets/shortcode-form-rule-builder.css';
        wp_enqueue_style(
            'cliniko-shortcode-form-rule-builder',
            plugins_url('../../../assets/shortcode-form-rule-builder.css', __FILE__),
            [],
            file_exists($style) ? (string) filemtime($style) : null
        );
        wp_enqueue_script(
            'cliniko-shortcode-form-rule-builder',
            plugins_url('../../../assets/shortcode-form-rule-builder.js', __FILE__),
            [],
            file_exists($script) ? (string) filemtime($script) : null,
            true
        );
    }

    private static function normaliseText($value, int $maxLength): string
    {
        $value = sanitize_text_field((string) $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private static function maskRegex(string $pattern, string $prefix, string $suffix): string
    {
        if ($pattern === '' || !preg_match('/[#A*]/', $pattern)) {
            return '';
        }
        $characters = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            $characters = str_split($pattern);
        }
        $body = '';
        foreach ($characters as $character) {
            $body .= match ($character) {
                '#' => '[0-9]',
                'A' => '[A-Za-z]',
                '*' => '[A-Za-z0-9]',
                default => preg_quote($character, '~'),
            };
        }
        return '~^' . preg_quote($prefix, '~') . $body . preg_quote($suffix, '~') . '$~uD';
    }

    public static function maskExample(string $pattern, string $prefix, string $suffix): string
    {
        $digit = 1;
        $letter = 0;
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $characters = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            $characters = str_split($pattern);
        }
        $body = '';
        foreach ($characters as $character) {
            if ($character === '#') {
                $body .= (string) $digit;
                $digit = $digit === 9 ? 1 : $digit + 1;
            } elseif ($character === 'A') {
                $body .= $letters[$letter % strlen($letters)];
                $letter++;
            } elseif ($character === '*') {
                $body .= $letter % 2 === 0 ? $letters[$letter % strlen($letters)] : (string) $digit;
                $letter++;
                $digit = $digit === 9 ? 1 : $digit + 1;
            } else {
                $body .= $character;
            }
        }
        return $prefix . $body . $suffix;
    }
}
