<?php

namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\Exception\ApiException;
use App\Model\AppointmentType;
use App\Model\Patient;
use App\Model\PatientFormTemplate;
use App\Widgets\ClinikoForm\ReturnPolicySettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class BookingReturnPolicy
{
    /**
     * Resolve a saved widget rule. This only reads Cliniko and can be used
     * before the patient selects a time or creates a booking attempt.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function resolve(array $body, ?string $verifiedPatientId, ApiClientInterface $client): array
    {
        try {
            $context = $body['return_policy_id'] ?? null;
            $policy = is_string($context) ? ReturnPolicySettingsRegistry::find($context) : null;
            if (!$policy
                || $policy['default_type_id'] !== ($body['moduleId'] ?? null)
                || $policy['template_id'] !== ($body['patient_form_template_id'] ?? null)
            ) {
                return self::error(409, 'return_policy_unavailable', 'This booking form has changed. Refresh the page and try again.');
            }

            $template = PatientFormTemplate::find($policy['template_id'], $client, true);
            $question = $template ? (ReturnPolicySettingsRegistry::questions($template)[$policy['question_key']] ?? null) : null;
            if ($template === null || $template->isArchived() || $question === null) {
                return self::error(409, 'return_policy_unavailable', 'This booking form needs to be updated. Please contact the clinic.');
            }

            $answer = self::answer($body['content'] ?? null, $question);
            if ($answer === '') {
                return self::error(422, 'return_policy_answer_required', 'Enter one answer in the booking question to check your price.');
            }

            $typeId = $policy['default_type_id'];
            if (ReturnPolicySettingsRegistry::reservesType($typeId)) {
                return self::error(409, 'return_policy_unavailable', 'This booking form needs to be updated. Please contact the clinic.');
            }

            $guestPatient = $verifiedPatientId === null
                ? (new BookingGuestPatientMatcher())->match($body['patient'] ?? null, $client)
                : null;
            $patientId = $verifiedPatientId ?? $guestPatient?->getId();
            if ($patientId !== null && $this->containsAnswer($client, $patientId, $policy['history_field'], $answer)) {
                $typeId = $policy['return_type_id'];
            }

            $type = AppointmentType::find($typeId, $client, true);
            if ($type === null || $type->isArchived()) {
                return self::error(409, 'return_policy_unavailable', 'The selected appointment type is unavailable.');
            }
            $amount = $type->getBillableItemsFinalPrice(true);
            if ($amount < 0) {
                throw new ApiException('The appointment price cannot be negative.');
            }
        } catch (\Throwable) {
            return self::error(503, 'return_policy_lookup_failed', 'Cliniko could not confirm the booking price. Please try again.');
        }

        return [
            'ok' => true,
            'status' => 200,
            'policy' => $policy,
            'template' => $template,
            'type' => $type,
            'guest_patient' => $guestPatient,
            'history_entry' => ['field_token' => $policy['history_field'], 'answer' => $answer],
            'quote' => [
                'appointment_type_id' => $typeId,
                'name' => $type->getName(),
                'description' => $type->getDescription(),
                'duration_in_minutes' => $type->getDurationInMinutes(),
                'amount_cents' => $amount,
                'currency' => 'aud',
                'return_pricing_applied' => $typeId === $policy['return_type_id'],
                'patient_verified' => $verifiedPatientId !== null,
            ],
        ];
    }

    public function appendHistory(string $patientId, string $fieldToken, string $answer, ApiClientInterface $client): void
    {
        Patient::withCustomFieldsLock($patientId, static function () use ($patientId, $fieldToken, $answer, $client): void {
            $sections = self::patientCustomFieldSections($client, $patientId);
            $location = self::findHistoryField($sections, $fieldToken) ?? self::addEmptyHistoryField($sections, $fieldToken);
            [$sectionIndex, $fieldIndex] = $location;
            $value = self::historyValue($sections[$sectionIndex]['fields'][$fieldIndex]);
            if (self::matchesLine($value, $answer)) {
                return;
            }

            $separator = $value === '' || preg_match('/\R\z/u', $value) === 1 ? '' : "\n";
            $sections[$sectionIndex]['fields'][$fieldIndex]['value'] = $value . $separator . $answer;
            $response = $client->patch('patients/' . rawurlencode($patientId), ['custom_fields' => ['sections' => $sections]]);
            if (!$response->isSuccessful() || isset($response->data['errors'])) {
                throw new ApiException('Cliniko did not accept the patient history update.');
            }

            $savedSections = $response->data['custom_fields']['sections'] ?? self::patientCustomFieldSections($client, $patientId);
            $savedLocation = is_array($savedSections) ? self::findHistoryField($savedSections, $fieldToken) : null;
            if ($savedLocation === null || !self::matchesLine(self::historyValue($savedSections[$savedLocation[0]]['fields'][$savedLocation[1]]), $answer)) {
                throw new ApiException('Could not verify that Cliniko stored the patient history entry.');
            }
        });
    }

    /** @param array{section:string,name:string,type:string} $question */
    private static function answer($content, array $question): string
    {
        $matches = [];
        foreach (is_array($content['sections'] ?? null) ? $content['sections'] : [] as $section) {
            if (($section['name'] ?? null) !== $question['section']) {
                continue;
            }
            foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $input) {
                if (($input['name'] ?? null) === $question['name'] && ($input['type'] ?? null) === $question['type']) {
                    $matches[] = $input['answer'] ?? null;
                }
            }
        }

        $answer = count($matches) === 1 && is_string($matches[0]) ? trim($matches[0]) : '';
        return preg_match('/\R/u', $answer) === 0 ? self::cleanLine($answer) : '';
    }

    private function containsAnswer(ApiClientInterface $client, string $patientId, string $fieldToken, string $answer): bool
    {
        $sections = self::patientCustomFieldSections($client, $patientId);
        $location = self::findHistoryField($sections, $fieldToken);
        if ($location === null) {
            return false;
        }
        [$sectionIndex, $fieldIndex] = $location;
        return self::matchesLine(self::historyValue($sections[$sectionIndex]['fields'][$fieldIndex]), $answer);
    }

    /** @return list<array<string,mixed>> */
    private static function patientCustomFieldSections(ApiClientInterface $client, string $patientId): array
    {
        $response = $client->get('patients/' . rawurlencode($patientId));
        if (!$response->isSuccessful() || (string) ($response->data['id'] ?? '') !== $patientId) {
            throw new ApiException('Could not load the Cliniko patient history.');
        }

        $sections = $response->data['custom_fields']['sections'] ?? [];
        if (!is_array($sections)) {
            throw new ApiException('Cliniko returned invalid patient custom fields.');
        }
        return array_values($sections);
    }

    /**
     * @param list<array<string,mixed>> $sections
     * @return array{int,int}|null
     */
    private static function findHistoryField(array $sections, string $fieldToken): ?array
    {
        foreach ($sections as $sectionIndex => $section) {
            if (!empty($section['archived'])) {
                continue;
            }
            foreach ($section['fields'] ?? [] as $fieldIndex => $field) {
                if (($field['token'] ?? '') === $fieldToken && empty($field['archived'])) {
                    return [$sectionIndex, $fieldIndex];
                }
            }
        }
        return null;
    }

    /**
     * New patients and empty fields may not include the field in the patient
     * response. Use Cliniko's active definition to add it to that record.
     *
     * @param list<array<string,mixed>> $sections
     * @return array{int,int}
     */
    private static function addEmptyHistoryField(array &$sections, string $fieldToken): array
    {
        $definition = PatientCustomFieldService::getPatientFieldDefinitions()['custom_' . sanitize_key($fieldToken)] ?? null;
        if (!$definition || $definition['cliniko_field_token'] !== $fieldToken
            || !in_array($definition['cliniko_field_type'], ['paragraph', 'textarea'], true)
            || $definition['cliniko_section_token'] === ''
        ) {
            throw new ApiException('The configured Cliniko patient history field is unavailable.');
        }

        $sectionToken = $definition['cliniko_section_token'];
        $sectionIndex = count($sections);
        foreach ($sections as $index => $section) {
            if (($section['token'] ?? '') === $sectionToken) {
                $sectionIndex = $index;
                break;
            }
        }
        $sections[$sectionIndex] ??= ['token' => $sectionToken, 'name' => $definition['cliniko_section_name'], 'fields' => []];
        if (!empty($sections[$sectionIndex]['archived'])) {
            throw new ApiException('The configured Cliniko patient history section is archived.');
        }
        $fields = array_values($sections[$sectionIndex]['fields'] ?? []);
        if (in_array($fieldToken, array_column($fields, 'token'), true)) {
            throw new ApiException('The configured Cliniko patient history field is archived.');
        }
        $fieldIndex = count($fields);
        $fields[] = [
            'token' => $fieldToken,
            'name' => $definition['cliniko_field_name'],
            'type' => $definition['cliniko_field_type'],
        ];
        $sections[$sectionIndex]['fields'] = $fields;
        return [$sectionIndex, $fieldIndex];
    }

    /** @param array<string,mixed> $field */
    private static function historyValue(array $field): string
    {
        $value = $field['value'] ?? '';
        if (!in_array(($field['type'] ?? 'paragraph'), ['paragraph', 'textarea'], true) || !is_string($value)) {
            throw new ApiException('The return policy history field is no longer a paragraph.');
        }
        return $value;
    }

    private static function matchesLine(string $value, string $answer): bool
    {
        $lines = preg_split('/\R/u', $value);
        if ($lines === false) {
            throw new ApiException('The Cliniko patient history could not be read as text.');
        }
        return in_array(self::normalize($answer), array_map([self::class, 'normalize'], $lines), true);
    }

    private static function cleanLine(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private static function normalize(string $value): string
    {
        $value = self::cleanLine($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @return array{ok:bool,status:int,code:string,message:string} */
    private static function error(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
