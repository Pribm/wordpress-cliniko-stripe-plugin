<?php

namespace App\Service;

use App\Model\PatientFormTemplate;
use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** Structural projection of shared booking answer rules, without presentation settings. */
final class BookingFormStructure
{
    public static function schema(): array
    {
        $string = ['type' => 'string'];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name', 'sections'], 'properties' => ['id' => $string, 'name' => $string, 'sections' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'description', 'questions'], 'properties' => ['name' => ['type' => 'string', 'nullable' => true], 'description' => ['type' => 'string', 'nullable' => true], 'questions' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'type', 'required', 'options', 'other_allowed', 'validation', 'custom_field_token', 'patient_field_connection'], 'properties' => [
                    'name' => $string, 'type' => ['type' => 'string', 'enum' => ['text', 'textarea', 'paragraph', 'email', 'date', 'checkboxes', 'radiobuttons']], 'required' => ['type' => 'boolean'], 'options' => ['type' => 'array', 'items' => $string], 'other_allowed' => ['type' => 'boolean'],
                    'custom_field_token' => ['type' => 'string', 'nullable' => true], 'patient_field_connection' => ['type' => 'string', 'nullable' => true],
                    'validation' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['max_length'], 'properties' => ['max_length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000], 'enum' => ['type' => 'array', 'items' => $string], 'format' => ['type' => 'string', 'enum' => ['date', 'email']], 'pattern' => $string]],
                ],
            ]]],
        ]]]];
    }

    public static function describe(PatientFormTemplate $template, array $rawRules)
    {
        $rules = PatientBookingConfiguration::questionRules($template, $rawRules);
        $sections = [];
        foreach ($template->getSections() as $si => $section) {
            $questions = [];
            foreach ($section->questions as $qi => $question) {
                $type = strtolower($question->type);
                if (!in_array($type, ['text', 'textarea', 'paragraph', 'email', 'date', 'checkboxes', 'radiobuttons'], true)) return new WP_Error('unsupported_booking_question', 'This booking form contains a question type not supported by the API.', ['status' => 422]);
                $rule = $rules['question_' . $si . '_' . $qi] ?? [];
                $validation = ['max_length' => (int) ($rule['max_length'] ?? 0) ?: 5000];
                if (($rule['input_type'] ?? '') === 'select') $validation['enum'] = $rule['select_options'];
                elseif (($rule['input_type'] ?? '') === 'date' || $type === 'date') $validation['format'] = 'date';
                elseif ($type === 'email') $validation['format'] = 'email';
                elseif (($rule['input_format'] ?? '') === 'numbers') $validation['pattern'] = '^[0-9]*$';
                elseif (($rule['input_format'] ?? '') === 'pattern') {
                    $validation['pattern'] = ShortcodeFormInputRules::maskConstraint($rule['mask_pattern'], $rule['mask_prefix'], $rule['mask_suffix']);
                }
                $options = [];
                foreach ($question->answers as $answer) {
                    $value = $answer['value'] ?? '';
                    if (!is_string($value) || $value === '' || in_array($value, $options, true)) return new WP_Error('invalid_booking_options', 'This booking form has invalid or duplicate answer options.', ['status' => 422]);
                    $options[] = $value;
                }
                $questions[] = [
                    'name' => $question->name,
                    'type' => $type,
                    'required' => $question->required,
                    'options' => $options,
                    'other_allowed' => (bool) ($question->other->enabled ?? false),
                    'validation' => $validation,
                    // These identify Cliniko-linked patient fields. They are descriptive
                    // response metadata and are deliberately not accepted in submissions.
                    'custom_field_token' => $question->customFieldToken,
                    'patient_field_connection' => $question->patientFieldConnection,
                ];
            }
            $sections[] = ['name' => $section->name, 'description' => $section->description, 'questions' => $questions];
        }
        return ['id' => (string) $template->getId(), 'name' => $template->getName(), 'sections' => $sections];
    }

    public static function emptyContent(array $structure): array
    {
        $sections = [];
        foreach ($structure['sections'] as $section) {
            $questions = [];
            foreach ($section['questions'] as $question) {
                $item = array_intersect_key($question, array_flip(['name', 'type', 'required']));
                if (in_array($question['type'], ['checkboxes', 'radiobuttons'], true)) {
                    $item['answers'] = array_map(static fn($value): array => ['value' => $value, 'selected' => false], $question['options']);
                    if ($question['other_allowed']) $item['other'] = ['enabled' => true, 'selected' => false, 'value' => ''];
                } else $item['answer'] = '';
                $questions[] = $item;
            }
            $sections[] = ['name' => $section['name'], 'description' => $section['description'], 'questions' => $questions];
        }
        return ['sections' => $sections];
    }

    /** Rebuild all metadata from the template; never trust client-required flags or labels. */
    public static function validateAnswers(array $structure, array $content, array $rules = [])
    {
        $submitted = $content['sections'] ?? null;
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) !== count($structure['sections']) || array_diff(array_keys($content), ['sections'])) return self::invalid('content.sections');
        $canonical = self::emptyContent($structure);
        foreach ($structure['sections'] as $si => $section) {
            $rawSection = $submitted[$si];
            if (!is_array($rawSection) || array_diff(array_keys($rawSection), ['name', 'description', 'questions'])) return self::invalid('content.sections.' . $si);
            foreach (['name', 'description'] as $field) if (array_key_exists($field, $rawSection) && $rawSection[$field] !== $section[$field]) return self::invalid('content.sections.' . $si . '.' . $field);
            $rawQuestions = $rawSection['questions'] ?? null;
            if (!is_array($rawQuestions) || !array_is_list($rawQuestions) || count($rawQuestions) !== count($section['questions'])) return self::invalid('content.sections.' . $si . '.questions');
            foreach ($section['questions'] as $qi => $question) {
                $path = 'content.sections.' . $si . '.questions.' . $qi;
                $raw = $rawQuestions[$qi];
                if (!is_array($raw) || array_diff(array_keys($raw), ['name', 'type', 'required', 'answer', 'answers', 'other'])) return self::invalid($path);
                foreach (['name', 'type', 'required'] as $field) if (array_key_exists($field, $raw) && $raw[$field] !== $question[$field]) return self::invalid($path . '.' . $field);
                $target = &$canonical['sections'][$si]['questions'][$qi];
                if (in_array($question['type'], ['checkboxes', 'radiobuttons'], true)) {
                    if (isset($raw['answer'])) return self::invalid($path . '.answer');
                    $answers = $raw['answers'] ?? [];
                    if (!is_array($answers) || !array_is_list($answers) || count($answers) !== count($question['options'])) return self::invalid($path . '.answers');
                    $selected = 0;
                    foreach ($answers as $ai => $answer) {
                        if (!is_array($answer) || array_diff(array_keys($answer), ['value', 'selected']) || ($answer['value'] ?? null) !== $question['options'][$ai] || !is_bool($answer['selected'] ?? false)) return self::invalid($path . '.answers.' . $ai);
                        $target['answers'][$ai]['selected'] = $answer['selected'] ?? false;
                        if ($target['answers'][$ai]['selected']) $selected++;
                    }
                    if (isset($raw['other'])) {
                        $other = $raw['other'];
                        if (!$question['other_allowed'] || !is_array($other) || array_diff(array_keys($other), ['enabled', 'selected', 'value']) || (isset($other['enabled']) && $other['enabled'] !== true) || !is_bool($other['selected'] ?? false) || !is_string($other['value'] ?? '')) return self::invalid($path . '.other');
                        if ($other['selected'] ?? false) {
                            if (trim($other['value'] ?? '') === '' || mb_strlen($other['value']) > 5000) return self::invalid($path . '.other.value');
                            $selected++; $target['other'] = ['enabled' => true, 'selected' => true, 'value' => $other['value']];
                        }
                    }
                    if (($question['required'] && $selected === 0) || ($question['type'] === 'radiobuttons' && $selected > 1)) return self::invalid($path . '.answers');
                } else {
                    if (isset($raw['answers']) || isset($raw['other'])) return self::invalid($path);
                    $answer = $raw['answer'] ?? '';
                    if (!is_string($answer) || ($question['required'] && trim($answer) === '')) return self::invalid($path . '.answer');
                    $validation = $question['validation'];
                    if (mb_strlen($answer) > $validation['max_length']) return self::invalid($path . '.answer');
                    $rule = $rules['question_' . $si . '_' . $qi] ?? [];
                    // API dates use ISO, not the shortcode's display date format.
                    if (($validation['format'] ?? '') === 'date') $rule = array_replace($rule, ['input_type' => 'date', 'date_format' => 'ymd_dash']);
                    $checked = ShortcodeFormInputRules::validateAndNormaliseValue($answer, $rule, $question['name']);
                    if ($checked['errors'] !== []) return self::invalid($path . '.answer');
                    if ($answer !== '') {
                        if (isset($validation['enum']) && !in_array($answer, $validation['enum'], true)) return self::invalid($path . '.answer');
                        if (isset($validation['pattern']) && preg_match('~' . $validation['pattern'] . '~D', $answer) !== 1) return self::invalid($path . '.answer');
                        if (($validation['format'] ?? '') === 'email' && !is_email($answer)) return self::invalid($path . '.answer');
                        if (($validation['format'] ?? '') === 'date') {
                            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $answer);
                            if (!$date || $date->format('Y-m-d') !== $answer) return self::invalid($path . '.answer');
                        }
                    }
                    $target['answer'] = $checked['value'];
                }
                unset($target);
            }
        }
        return $canonical;
    }

    private static function invalid(string $field): WP_Error
    {
        return new WP_Error('invalid_booking_answers', 'Answers must match the current booking structure and its validation rules.', ['status' => 422, 'field' => $field]);
    }
}
