<?php
namespace App\Validator;

use Respect\Validation\Validator;


if (!defined('ABSPATH'))
    exit;


class AppointmentRequestValidator
{
    public static function validate($payload): array
    {
        $errors = [];

        if (!is_array($payload)) {
            return ['payload' => 'Invalid JSON payload.'];
        }

        // // Validação do Stripe e campos principais
        // if (empty($payload['stripeToken']) || !Validator::stringType()->startsWith('tok_')->validate($payload['stripeToken'])) {
        //     $errors['stripeToken'] = 'Invalid or missing payment token.';
        // }

        if (empty($payload['moduleId'])) {
            $errors['moduleId'] = 'moduleId is required.';
        }

        if (empty($payload['patient_form_template_id'])) {
            $errors['patient_form_template_id'] = 'patient_form_template_id is required.';
        }

        // Validação do paciente
        $patient = $payload['patient'] ?? null;
        if (!is_array($patient)) {
            $errors['patient'] = 'Patient data is required.';
        } else {
            $rules = [
                'first_name' => Validator::notEmpty()->alpha()->length(2, 100),
                'last_name' => Validator::notEmpty()->alpha()->length(2, 100),
                'email' => Validator::notEmpty()->email(),
            ];

            foreach ($rules as $field => $rule) {
                if (!array_key_exists($field, $patient)) {
                    $errors["patient.$field"] = "$field is required.";
                } elseif (!$rule->validate($patient[$field])) {
                    $errors["patient.$field"] = "$field is invalid.";
                }
            }
        }

  
        $errors += self::validateContentSections($payload['content'] ?? null);

        return $errors;
    }
    public static function validateContentSections($content): array
    {
        $errors = [];

        if (!is_array($content) || !isset($content['sections']) || !is_array($content['sections'])) {
            $errors['content'] = 'Invalid or missing content.';
            return $errors;
        }

        foreach ($content['sections'] as $sectionIndex => $section) {
            if (!isset($section['questions']) || !is_array($section['questions'])) {
                $errors["content.sections.$sectionIndex"] = 'Invalid or missing questions.';
                continue;
            }

            foreach ($section['questions'] as $questionIndex => $question) {
                $qPath = "content.sections.$sectionIndex.questions.$questionIndex";
                $type = $question['type'] ?? null;
                $required = $question['required'] ?? false;

                // ❌ Bloqueia qualquer campo do tipo signature
                if ($type === 'signature') {
                    $errors["$qPath.type"] = 'Signature questions are not allowed.';
                    continue;
                }

                if ($required) {
                    if ($type === 'text' && empty($question['answer'])) {
                        $errors["$qPath.answer"] = 'Answer is required.';
                    }

                    if (in_array($type, ['checkboxes', 'radiobuttons'])) {
                        $selected = array_filter($question['answers'] ?? [], fn($a) => $a['selected'] ?? false);
                        if (count($selected) === 0) {
                            $errors["$qPath.answers"] = 'At least one option must be selected.';
                        }
                    }
                }
            }
        }

        return $errors;
    }

}
