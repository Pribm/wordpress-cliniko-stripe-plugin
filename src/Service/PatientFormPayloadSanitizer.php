<?php
namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class PatientFormPayloadSanitizer
{
    /**
     * Normalizes full payload so it can be safely sent to Cliniko.
     */
    public static function sanitizePayload(array $payload): array
    {
        $payload['content'] = self::sanitizeContent($payload['content'] ?? null);
        return $payload;
    }

    /**
     * Cliniko choice answers should only send `selected` when true.
     */
    public static function sanitizeContent($content)
    {
        if (!is_array($content) || !isset($content['sections']) || !is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as $sectionIndex => $section) {
            if (!is_array($section) || !isset($section['questions']) || !is_array($section['questions'])) {
                continue;
            }

            foreach ($section['questions'] as $questionIndex => $question) {
                if (!is_array($question)) {
                    continue;
                }

                $type = strtolower((string) ($question['type'] ?? ''));
                if (!in_array($type, ['radiobuttons', 'checkboxes'], true)) {
                    continue;
                }

                if (isset($question['answers']) && is_array($question['answers'])) {
                    foreach ($question['answers'] as $answerIndex => $answer) {
                        if (!is_array($answer)) {
                            continue;
                        }

                        if (array_key_exists('selected', $answer) && $answer['selected'] === false) {
                            unset($answer['selected']);
                            $question['answers'][$answerIndex] = $answer;
                        }
                    }
                }

                if (isset($question['other']) && is_array($question['other'])) {
                    if (array_key_exists('selected', $question['other']) && $question['other']['selected'] === false) {
                        unset($question['other']['selected']);
                    }
                }

                $section['questions'][$questionIndex] = $question;
            }

            $content['sections'][$sectionIndex] = $section;
        }

        return $content;
    }
}
