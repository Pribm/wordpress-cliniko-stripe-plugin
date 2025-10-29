<?php
namespace App\Validator;

use Respect\Validation\Validator;

if (!defined('ABSPATH')) exit;

class PatientFormValidator
{
    private static function makeError(
        string $field,
        string $label,
        string $code,
        string $detail,
        array $expected = []
    ): array {
        $error = [
            'field'  => $field,
            'label'  => $label,
            'code'   => $code,
            'detail' => $detail,
        ];

        // ✅ Adiciona metadados descritivos se existirem
        if (!empty($expected)) {
            $error['expected'] = $expected;
        }

        return $error;
    }

    /**
     * Define o mapa de campos esperados (com exemplos e tipos)
     */
    private static function expectedSchema(): array
    {
        return [
            'patient_form_template_id' => [
                'type' => 'string',
                'description' => 'Cliniko patient form template ID.',
                'example' => '1547537768740012345'
            ],
            'patient.first_name' => [
                'type' => 'string',
                'example' => 'John',
                'description' => 'Patient’s first name.'
            ],
            'patient.last_name' => [
                'type' => 'string',
                'example' => 'Doe',
                'description' => 'Patient’s last name.'
            ],
            'patient.email' => [
                'type' => 'string',
                'example' => 'john.doe@example.com',
                'description' => 'Patient email address.'
            ],
            'patient.date_of_birth' => [
                'type' => 'string',
                'examples' => ['19900521', '1990-05-21'],
                'description' => 'Date in format YYYYMMDD or YYYY-MM-DD.'
            ],
            'patient.patient_booked_time' => [
                'type' => 'string',
                'examples' => ['2025-10-20T23:45:29.351Z', '2025-10-20T05:11:44Z'],
                'description' => 'ISO UTC timestamp of booked time.'
            ],
            'content.sections[].questions[].label' => [
                'type' => 'string',
                'example' => 'Do you have allergies?',
                'description' => 'Question label as shown to the user.'
            ],
            'content.sections[].questions[].type' => [
                'type' => 'string',
                'enum' => ['text', 'checkboxes', 'radiobuttons', 'textarea'],
                'example' => 'checkboxes'
            ],
            'content.sections[].questions[].required' => [
                'type' => 'boolean',
                'example' => true
            ],
            'content.sections[].questions[].answers[].label' => [
                'type' => 'string',
                'example' => 'Penicillin'
            ],
            'content.sections[].questions[].answers[].selected' => [
                'type' => 'boolean',
                'example' => false
            ],
            'content.sections[].questions[].answer' => [
                'type' => 'string',
                'example' => 'No allergies'
            ],
        ];
    }

    public static function validate($payload): array
    {
        $errors = [];
        $expected = self::expectedSchema();

        if (!is_array($payload)) {
            return [
                self::makeError(
                    'payload',
                    'Payload',
                    'invalid',
                    'Invalid JSON payload.',
                    $expected
                )
            ];
        }

        // === Basic identifiers ===
        if (empty($payload['patient_form_template_id'])) {
            $errors[] = self::makeError(
                'patient_form_template_id',
                'Patient Form Template',
                'required',
                'Patient form template ID is required.',
                $expected['patient_form_template_id']
            );
        }

        // === Patient ===
        $patient = $payload['patient'] ?? null;
        if (!is_array($patient)) {
            $errors[] = self::makeError(
                'patient',
                'Patient',
                'required',
                'Patient object is required.',
                [
                    'type' => 'object',
                    'required' => ['first_name', 'last_name', 'email', 'date_of_birth', 'patient_booked_time'],
                ]
            );
        } else {
            $rules = [
                'first_name' => ['rule' => Validator::notEmpty()->alpha(' ')->length(2, 100), 'label' => 'First Name'],
                'last_name'  => ['rule' => Validator::notEmpty()->alpha(' ')->length(2, 100), 'label' => 'Last Name'],
                'email'      => ['rule' => Validator::notEmpty()->email(), 'label' => 'Email'],
                'date_of_birth' => [
                    'rule' => Validator::oneOf(
                        Validator::regex('/^\d{8}$/'),
                        Validator::date('Y-m-d')
                    ),
                    'label' => 'Date of Birth'
                ],
                'patient_booked_time' => [
                    'rule' => Validator::regex('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/'),
                    'label' => 'Booked Time'
                ]
            ];

            foreach ($rules as $field => $cfg) {
                $rule  = $cfg['rule'];
                $label = $cfg['label'];
                if (!isset($patient[$field]) || !$rule->validate($patient[$field])) {
                    $errors[] = self::makeError(
                        "patient.$field",
                        $label,
                        'invalid',
                        "$label is invalid or missing.",
                        $expected["patient.$field"] ?? []
                    );
                }
            }
        }

        // === Content validation ===
        $errors = array_merge($errors, self::validateContentSections($payload['content'] ?? null, $expected));
        return $errors;
    }

    public static function validateContentSections($content, $expected = []): array
    {
        $errors = [];

        if (!is_array($content) || !isset($content['sections']) || !is_array($content['sections'])) {
            $errors[] = self::makeError(
                'content',
                'Content',
                'invalid',
                'Invalid or missing content.',
                ['type' => 'object', 'required' => ['sections']]
            );
            return $errors;
        }

        foreach ($content['sections'] as $sectionIndex => $section) {
            if (!isset($section['questions']) || !is_array($section['questions'])) {
                $errors[] = self::makeError(
                    "content.sections.$sectionIndex",
                    'Content Section',
                    'invalid',
                    'Invalid or missing questions.',
                    $expected['content.sections[].questions[].label'] ?? []
                );
                continue;
            }

            foreach ($section['questions'] as $questionIndex => $question) {
                $path = "content.sections.$sectionIndex.questions.$questionIndex";
                $type = $question['type'] ?? null;
                $required = $question['required'] ?? false;

                if ($type === 'signature') {
                    $errors[] = self::makeError("$path.type", 'Question Type', 'not_allowed', 'Signature questions are not allowed.');
                    continue;
                }

                if ($required) {
                    if ($type === 'text' && empty($question['answer'])) {
                        $errors[] = self::makeError(
                            "$path.answer",
                            'Answer',
                            'required',
                            'Answer is required.',
                            $expected['content.sections[].questions[].answer'] ?? []
                        );
                    }
                    if (in_array($type, ['checkboxes', 'radiobuttons'], true)) {
                        $selected = array_filter($question['answers'] ?? [], fn($a) => $a['selected'] ?? false);
                        if (count($selected) === 0) {
                            $errors[] = self::makeError(
                                "$path.answers",
                                'Answers',
                                'required',
                                'At least one option must be selected.',
                                $expected['content.sections[].questions[].answers[].label'] ?? []
                            );
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
