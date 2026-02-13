<?php
namespace App\Validator;

use DateTimeImmutable;
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

    /**
     * Medicare number without reference number:
     * - 9 digits total (often displayed as 4 + 5)
     */
    private static function isValidMedicareNumber(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value);
        return Validator::digit()->length(9, 9)->validate($digits);
    }

    private static function normalizeString($value): string
    {
        return trim((string) $value);
    }

    private static function isValidClinikoId(string $value): bool
    {
        return Validator::regex('/^\d+$/')->validate($value);
    }

    private static function isValidDateYmd(string $value): bool
    {
        if (!Validator::regex('/^\d{4}-\d{2}-\d{2}$/')->validate($value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    private static function isValidIsoDateTime(string $value): bool
    {
        if (!Validator::regex('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/')->validate($value)) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function validate($payload, bool $validateContent = true): array
    {
        $errors = [];

        if (!is_array($payload)) {
            return [
                self::makeError('payload', 'Payload', 'invalid', 'Invalid JSON payload.')
            ];
        }

        if (empty($payload['moduleId'])) {
            $errors[] = self::makeError('moduleId', 'Module', 'required', 'Module ID is required.');
        } elseif (!self::isValidClinikoId(self::normalizeString($payload['moduleId']))) {
            $errors[] = self::makeError('moduleId', 'Module', 'invalid', 'Module ID must be a numeric Cliniko ID.');
        }

        if (empty($payload['patient_form_template_id'])) {
            $errors[] = self::makeError('patient_form_template_id', 'Patient Form Template', 'required', 'Patient form template ID is required.');
        } elseif (!self::isValidClinikoId(self::normalizeString($payload['patient_form_template_id']))) {
            $errors[] = self::makeError(
                'patient_form_template_id',
                'Patient Form Template',
                'invalid',
                'Patient form template ID must be a numeric Cliniko ID.'
            );
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
                } elseif (!$rule->validate(self::normalizeString($patient[$field]))) {
                    $errors[] = self::makeError("patient.$field", $label, 'invalid', "$label is invalid.");
                }
            }

            // Practitioner is required for scheduling flow.
            if (!array_key_exists('practitioner_id', $patient) || self::normalizeString($patient['practitioner_id']) === '') {
                $errors[] = self::makeError(
                    'patient.practitioner_id',
                    'Practitioner',
                    'required',
                    'Practitioner ID is required.'
                );
            } else {
                $practitionerId = self::normalizeString($patient['practitioner_id']);
                if (!self::isValidClinikoId($practitionerId)) {
                    $errors[] = self::makeError(
                        'patient.practitioner_id',
                        'Practitioner',
                        'invalid',
                        'Practitioner ID must be a numeric Cliniko ID.'
                    );
                }
            }

            // --- Medicare fields ---
            if (!array_key_exists('medicare', $patient) || $patient['medicare'] === '' || $patient['medicare'] === null) {
                $errors[] = self::makeError('patient.medicare', 'Medicare', 'required', 'Medicare number is required.');
            } else {
                $medicareValid = self::isValidMedicareNumber((string)$patient['medicare']);
                if (!$medicareValid) {
                    $errors[] = self::makeError(
                        'patient.medicare',
                        'Medicare',
                        'invalid',
                        'Medicare must contain exactly 9 digits (reference number is separate).'
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

            // Date of birth is sent to Cliniko as YYYY-MM-DD (Patient.date_of_birth).
            if (array_key_exists('date_of_birth', $patient)
                && $patient['date_of_birth'] !== ''
                && $patient['date_of_birth'] !== null) {
                $dob = self::normalizeString($patient['date_of_birth']);
                if (!self::isValidDateYmd($dob)) {
                    $errors[] = self::makeError(
                        'patient.date_of_birth',
                        'Date of Birth',
                        'invalid',
                        'Date of birth must be in YYYY-MM-DD format (e.g. 1992-02-23).'
                    );
                }
            }

            // appointment_start is optional but, when provided, should be ISO8601.
            if (array_key_exists('appointment_start', $patient)
                && $patient['appointment_start'] !== ''
                && $patient['appointment_start'] !== null) {
                $start = self::normalizeString($patient['appointment_start']);
                if (!self::isValidIsoDateTime($start)) {
                    $errors[] = self::makeError(
                        'patient.appointment_start',
                        'Appointment Start',
                        'invalid',
                        'Appointment start must be an ISO8601 datetime (e.g. 2026-02-12T05:50:00Z).'
                    );
                }
            }

            // appointment_date is optional but should be YYYY-MM-DD when sent.
            if (array_key_exists('appointment_date', $patient)
                && $patient['appointment_date'] !== ''
                && $patient['appointment_date'] !== null) {
                $appointmentDate = self::normalizeString($patient['appointment_date']);
                if (!self::isValidDateYmd($appointmentDate)) {
                    $errors[] = self::makeError(
                        'patient.appointment_date',
                        'Appointment Date',
                        'invalid',
                        'Appointment date must be in YYYY-MM-DD format.'
                    );
                }
            }

            if (array_key_exists('phone', $patient)
                && $patient['phone'] !== ''
                && $patient['phone'] !== null) {
                $phone = self::normalizeString($patient['phone']);
                if (!Validator::regex('/^\+?[0-9().\-\s]{6,20}$/')->validate($phone)) {
                    $errors[] = self::makeError(
                        'patient.phone',
                        'Phone',
                        'invalid',
                        'Phone format is invalid.'
                    );
                }
            }

            if (array_key_exists('post_code', $patient)
                && $patient['post_code'] !== ''
                && $patient['post_code'] !== null) {
                $postCode = self::normalizeString($patient['post_code']);
                if (!Validator::regex('/^[A-Za-z0-9\- ]{3,10}$/')->validate($postCode)) {
                    $errors[] = self::makeError(
                        'patient.post_code',
                        'Post Code',
                        'invalid',
                        'Post code format is invalid.'
                    );
                }
            }

            if (array_key_exists('country', $patient)
                && $patient['country'] !== ''
                && $patient['country'] !== null) {
                $country = self::normalizeString($patient['country']);
                if (!Validator::length(2, 100)->validate($country)) {
                    $errors[] = self::makeError(
                        'patient.country',
                        'Country',
                        'invalid',
                        'Country is invalid.'
                    );
                }
            }
        }

        if ($validateContent) {
            $errors = array_merge($errors, self::validateContentSections($payload['content'] ?? null));
        }

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
