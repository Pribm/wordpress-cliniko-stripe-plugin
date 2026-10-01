<?php

namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\DTO\CreatePatientFormDTO;
use App\DTO\PatientFormTemplateQuestionDTO;
use App\Model\Patient;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\Validator\PatientFormValidator;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientFormTemplateSubmissionService
{
    private ApiClientInterface $client;

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 900)
            : cliniko_client(false));
    }

    /**
     * @param array<int|string,mixed> $answers
     * @param array<int|string,mixed> $otherAnswers
     */
    public function submit(
        Patient $patient,
        string $templateId,
        string $formName,
        array $answers,
        array $otherAnswers = []
    ): PatientForm {
        $patientId = trim((string) $patient->getId());
        $templateId = trim($templateId);
        $businessId = trim((string) get_option('wp_cliniko_business_id', ''));
        if ($patientId === '' || $templateId === '' || $businessId === '') {
            throw new \RuntimeException('The patient form configuration is incomplete.');
        }

        $template = PatientFormTemplate::find($templateId, $this->client, true);
        if ($template === null) {
            throw new \RuntimeException('The patient form template could not be found.');
        }

        $content = ['sections' => $this->buildSections($template, $answers, $otherAnswers)];
        $errors = PatientFormValidator::validateContentSections($content);
        if ($errors !== []) {
            $detail = trim((string) ($errors[0]['detail'] ?? 'Please complete all required fields.'));
            throw new \InvalidArgumentException($detail !== '' ? $detail : 'Please complete all required fields.');
        }

        $dto = new CreatePatientFormDTO();
        $dto->business_id = $businessId;
        $dto->completed = true;
        $dto->patient_id = $patientId;
        $dto->patient_form_template_id = $templateId;
        $dto->name = trim($formName) !== '' ? trim($formName) : (string) $template->getName();
        $dto->content_sections = PatientFormPayloadSanitizer::sanitizeContent($content);

        $created = PatientForm::create($dto, $this->client);
        if (!$created instanceof PatientForm) {
            throw new \RuntimeException('Cliniko did not create the patient form.');
        }

        if (function_exists('cliniko_dashboard_cache_invalidate')) {
            cliniko_dashboard_cache_invalidate();
        }

        return $created;
    }

    /**
     * @param array<int|string,mixed> $answers
     * @param array<int|string,mixed> $otherAnswers
     * @return array<int,array<string,mixed>>
     */
    private function buildSections(
        PatientFormTemplate $template,
        array $answers,
        array $otherAnswers
    ): array {
        $sections = [];
        foreach ($template->getSections() as $sectionIndex => $section) {
            $questions = [];
            $sectionAnswers = is_array($answers[$sectionIndex] ?? null) ? $answers[$sectionIndex] : [];
            $sectionOtherAnswers = is_array($otherAnswers[$sectionIndex] ?? null)
                ? $otherAnswers[$sectionIndex]
                : [];
            foreach ($section->questions as $questionIndex => $question) {
                $questions[] = $this->buildQuestion(
                    $question,
                    $sectionAnswers[$questionIndex] ?? null,
                    $sectionOtherAnswers[$questionIndex] ?? null
                );
            }

            $sections[] = [
                'name' => (string) $section->name,
                'description' => (string) $section->description,
                'questions' => $questions,
            ];
        }

        return $sections;
    }

    /**
     * @param mixed $rawAnswer
     * @param mixed $rawOther
     * @return array<string,mixed>
     */
    private function buildQuestion(
        PatientFormTemplateQuestionDTO $question,
        $rawAnswer,
        $rawOther
    ): array {
        $type = strtolower(trim($question->type));
        if ($type === 'signature') {
            throw new \InvalidArgumentException('Signature questions are not supported by this shortcode yet.');
        }

        $payload = [
            'name' => $question->name,
            'type' => $type,
            'required' => $question->required,
        ];

        if (!in_array($type, ['checkboxes', 'radiobuttons'], true)) {
            $payload['answer'] = is_scalar($rawAnswer)
                ? sanitize_textarea_field((string) $rawAnswer)
                : '';
            return $payload;
        }

        $selected = is_array($rawAnswer) ? $rawAnswer : [$rawAnswer];
        $selected = array_values(array_unique(array_map(
            static fn($value): string => is_scalar($value) ? trim((string) $value) : '',
            $selected
        )));

        $payload['answers'] = [];
        foreach ($question->answers as $answer) {
            $value = trim((string) ($answer['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $payload['answers'][] = [
                'value' => $value,
                'selected' => in_array($value, $selected, true),
            ];
        }

        if ($question->other !== null && !empty($question->other->enabled)) {
            $otherSelected = in_array('__other__', $selected, true);
            $payload['other'] = ['enabled' => true, 'selected' => $otherSelected];
            if ($otherSelected) {
                $payload['other']['value'] = is_scalar($rawOther)
                    ? sanitize_text_field((string) $rawOther)
                    : '';
            }
        }

        return $payload;
    }
}
