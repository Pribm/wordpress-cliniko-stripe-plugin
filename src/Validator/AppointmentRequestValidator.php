<?php
namespace App\Validator;

use Respect\Validation\Validator;

if (!defined('ABSPATH')) exit;

class AppointmentRequestValidator
{
    /**
     * Helper para padronizar erros
     */
    private static function makeError(string $field, string $label, string $code, string $detail): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'code'  => $code,
            'detail'=> $detail,
        ];
    }

    public static function validate($payload): array
    {
        $errors = [];

        if (!is_array($payload)) {
            return [
                self::makeError('payload', 'Payload', 'invalid', 'Invalid JSON payload.')
            ];
        }

        if (empty($payload['moduleId'])) {
            $errors[] = self::makeError('moduleId', 'Module', 'required', 'Module ID is required.');
        }

        if (empty($payload['patient_form_template_id'])) {
            $errors[] = self::makeError('patient_form_template_id', 'Patient Form Template', 'required', 'Patient form template ID is required.');
        }

        // Patient validation
        $patient = $payload['patient'] ?? null;
        if (!is_array($patient)) {
            $errors[] = self::makeError('patient', 'Patient', 'required', 'Patient data is required.');
        } else {
            $rules = [
                'first_name' => ['rule' => Validator::notEmpty()->alpha()->length(2, 100), 'label' => 'First Name'],
                'last_name'  => ['rule' => Validator::notEmpty()->alpha()->length(2, 100), 'label' => 'Last Name'],
                'email'      => ['rule' => Validator::notEmpty()->email(), 'label' => 'Email'],
            ];

            foreach ($rules as $field => $cfg) {
                $rule  = $cfg['rule'];
                $label = $cfg['label'];

                if (!array_key_exists($field, $patient)) {
                    $errors[] = self::makeError("patient.$field", $label, 'required', "$label is required.");
                } elseif (!$rule->validate($patient[$field])) {
                    $errors[] = self::makeError("patient.$field", $label, 'invalid', "$label is invalid.");
                }
            }

            // --- Medicare fields ---
            if (!array_key_exists('medicare', $patient) || $patient['medicare'] === '' || $patient['medicare'] === null) {
                $errors[] = self::makeError('patient.medicare', 'Medicare', 'required', 'Medicare number is required.');
            } else {
                $medicareDigits = preg_replace('/\D+/', '', (string)$patient['medicare']);
                $medicareValid  = Validator::digit()->length(10, 10)->validate($medicareDigits);

                if (!$medicareValid) {
                    $errors[] = self::makeError(
                        'patient.medicare',
                        'Medicare',
                        'invalid',
                        'Medicare must contain exactly 10 digits (e.g. 1234 56789 1).'
                    );
                }
            }

            if (!array_key_exists('medicare_reference_number', $patient)
                || $patient['medicare_reference_number'] === ''
                || $patient['medicare_reference_number'] === null) {
                $errors[] = self::makeError(
                    'patient.medicare_reference_number',
                    'Medicare Reference Number',
                    'required',
                    'Medicare reference number is required.'
                );
            } else {
                $ref = (string)$patient['medicare_reference_number'];
                $refValid = Validator::regex('/^[1-9]$/')->validate($ref);
                if (!$refValid) {
                    $errors[] = self::makeError(
                        'patient.medicare_reference_number',
                        'Medicare Reference Number',
                        'invalid',
                        'Medicare reference number must be a single digit between 1 and 9.'
                    );
                }
            }
            // --- end Medicare fields ---
        }

        $errors = array_merge($errors, self::validateContentSections($payload['content'] ?? null));

        return $errors;
    }

    public static function validateContentSections($content): array
    {
        $errors = [];

        if (!is_array($content) || !isset($content['sections']) || !is_array($content['sections'])) {
            $errors[] = self::makeError('content', 'Content', 'invalid', 'Invalid or missing content.');
            return $errors;
        }

        foreach ($content['sections'] as $sectionIndex => $section) {
            if (!isset($section['questions']) || !is_array($section['questions'])) {
                $errors[] = self::makeError("content.sections.$sectionIndex", 'Content Section', 'invalid', 'Invalid or missing questions.');
                continue;
            }

            foreach ($section['questions'] as $questionIndex => $question) {
                $qPath    = "content.sections.$sectionIndex.questions.$questionIndex";
                $type     = $question['type'] ?? null;
                $required = $question['required'] ?? false;

                if ($type === 'signature') {
                    $errors[] = self::makeError("$qPath.type", 'Question Type', 'not_allowed', 'Signature questions are not allowed.');
                    continue;
                }

                if ($required) {
                    if ($type === 'text' && empty($question['answer'])) {
                        $errors[] = self::makeError("$qPath.answer", 'Answer', 'required', 'Answer is required.');
                    }

                    if (in_array($type, ['checkboxes', 'radiobuttons'], true)) {
                        $selected = array_filter($question['answers'] ?? [], fn ($a) => $a['selected'] ?? false);
                        if (count($selected) === 0) {
                            $errors[] = self::makeError("$qPath.answers", 'Answers', 'required', 'At least one option must be selected.');
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
