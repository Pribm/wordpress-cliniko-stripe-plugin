<?php
namespace App\Validator;
if (!defined('ABSPATH')) exit;


class ModuleValidator
{
    public static function validateFields(array $answers, array $requiredFields): array
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (empty($answers[$field])) {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}
