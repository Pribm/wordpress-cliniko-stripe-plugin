<?php
namespace App\Validator;

use Respect\Validation\Validator as v;

if (!defined('ABSPATH')) exit;

class PatientRequestValidator
{
    private static function makeError(string $field, string $label, string $code, string $detail): array
    {
        return [
            'field'  => $field,
            'label'  => $label,
            'code'   => $code,
            'detail' => $detail,
        ];
    }

    public static function validate(array $patient): array
    {
        $errors = [];

        if (!is_array($patient)) {
            return [
                self::makeError('patient', 'Patient', 'invalid', 'Patient payload must be an object.')
            ];
        }

        // 🔑 Required rules
        $rules = [
            'first_name' => ['rule' => v::notEmpty()->alpha()->length(2, 100), 'label' => 'First Name'],
            'last_name'  => ['rule' => v::notEmpty()->alpha()->length(2, 100), 'label' => 'Last Name'],
            'email'      => ['rule' => v::notEmpty()->email(), 'label' => 'Email'],
            'phone'      => ['rule' => v::notEmpty()->digit()->length(10, 10), 'label' => 'Phone Number'],
            'address_1'  => ['rule' => v::notEmpty()->stringType()->length(2, 255), 'label' => 'Address Line 1'],
            'city'       => ['rule' => v::notEmpty()->stringType()->length(2, 100), 'label' => 'City'],
            'state'      => ['rule' => v::notEmpty()->stringType()->length(2, 100), 'label' => 'State'],
            'post_code'  => ['rule' => v::notEmpty()->digit()->length(4, 4), 'label' => 'Post Code'],
            'country'    => ['rule' => v::notEmpty()->stringType()->length(2, 100), 'label' => 'Country'],
            'date_of_birth' => ['rule' => v::date('Y-m-d'), 'label' => 'Date of Birth'],
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

        // Medicare
        if (empty($patient['medicare'])) {
            $errors[] = self::makeError('patient.medicare', 'Medicare Number', 'required', 'Medicare number is required.');
        } else {
            $medicareDigits = preg_replace('/\D+/', '', (string)$patient['medicare']);
            if (!v::digit()->length(10, 10)->validate($medicareDigits)) {
                $errors[] = self::makeError('patient.medicare', 'Medicare Number', 'invalid', 'Must contain exactly 10 digits.');
            }
        }

        if (empty($patient['medicare_reference_number'])) {
            $errors[] = self::makeError('patient.medicare_reference_number', 'Medicare Reference Number', 'required', 'Reference number is required.');
        } else {
            if (!v::regex('/^[1-9]$/')->validate((string)$patient['medicare_reference_number'])) {
                $errors[] = self::makeError('patient.medicare_reference_number', 'Medicare Reference Number', 'invalid', 'Must be a single digit between 1–9.');
            }
        }

        // Passwords
        if (empty($patient['password'])) {
            $errors[] = self::makeError('patient.password', 'Password', 'required', 'Password is required.');
        } elseif (!v::regex('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/')->validate($patient['password'])) {
            $errors[] = self::makeError(
                'patient.password',
                'Password',
                'weak',
                'Password must be at least 8 chars long and include uppercase, lowercase, number, and special character.'
            );
        }

        if (empty($patient['password_confirmation'])) {
            $errors[] = self::makeError('patient.password_confirmation', 'Password Confirmation', 'required', 'Password confirmation is required.');
        } elseif ($patient['password'] !== $patient['password_confirmation']) {
            $errors[] = self::makeError('patient.password_confirmation', 'Password Confirmation', 'mismatch', 'Passwords do not match.');
        }

        return $errors;
    }
}
