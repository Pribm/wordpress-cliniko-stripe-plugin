<?php

namespace App\Widgets\ClinikoForm;

use App\Model\PatientFormTemplate;

if (!defined('ABSPATH')) {
    exit;
}

final class ReturnPolicySettingsRegistry
{
    private const OPTION_KEY = 'wp_cliniko_form_return_policies';

    /** @param array<string,mixed> $settings */
    public static function isEnabled(array $settings): bool
    {
        return ($settings['appointment_source'] ?? 'custom_form') === 'custom_form'
            && ($settings['enable_payment'] ?? 'yes') === 'yes'
            && ($settings['return_policy_enabled'] ?? '') === 'yes';
    }

    public static function questionControl(string $templateId): string
    {
        return 'return_policy_question_' . $templateId;
    }

    /** @return array<string,array{section:string,name:string,type:string}> */
    public static function questions(PatientFormTemplate $template): array
    {
        $questions = [];
        $nameCounts = [];
        foreach ($template->getSections() as $section) {
            foreach ($section->questions as $question) {
                $name = (string) $question->name;
                $type = strtolower((string) $question->type);
                if ($type !== 'signature') {
                    $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
                }
                if ($name === '' || !in_array($type, ['text', 'textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true)) {
                    continue;
                }
                $reference = ['section' => (string) $section->name, 'name' => $name, 'type' => $type];
                $key = hash('sha256', implode("\0", $reference));
                $questions[$key] = $reference;
            }
        }

        // The rendered wizard reads native form controls by question name.
        return array_filter($questions, static fn (array $question): bool => $nameCounts[$question['name']] === 1);
    }

    /** @param array<int,array<string,mixed>> $elements */
    public static function syncElementorPage(int $postId, array $elements): void
    {
        if (wp_is_post_revision($postId)) {
            return;
        }

        $registry = self::all();
        $updated = array_filter($registry, static fn (array $policy): bool => $policy['post_id'] !== $postId);
        self::collectPolicies($postId, $elements, $updated);
        if ($updated !== $registry) {
            update_option(self::OPTION_KEY, $updated, false);
        }
    }

    public static function contextId(int $postId, string $widgetId): string
    {
        return hash('sha256', $postId . '|' . $widgetId);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $contextId): ?array
    {
        return self::all()[$contextId] ?? null;
    }

    public static function reservesType(string $appointmentTypeId): bool
    {
        return in_array($appointmentTypeId, array_column(self::all(), 'return_type_id'), true);
    }

    /** @return list<string> */
    public static function protectedFieldTokens(): array
    {
        return array_values(array_unique(array_column(self::all(), 'history_field')));
    }

    /** @return array<string,array<string,mixed>> */
    private static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<int,array<string,mixed>> $elements
     * @param array<string,array<string,mixed>> $policies
     */
    private static function collectPolicies(int $postId, array $elements, array &$policies): void
    {
        foreach ($elements as $element) {
            $settings = $element['settings'] ?? [];
            if (($element['widgetType'] ?? '') === 'cliniko_stripe_payment' && self::isEnabled($settings)) {
                $widgetId = (string) ($element['id'] ?? '');
                $templateId = (string) ($settings['cliniko_form_template_id'] ?? '');
                $defaultType = (string) ($settings['module_id'] ?? '2');
                $returnType = (string) ($settings['return_policy_appointment_type'] ?? '');
                $questionKey = (string) ($settings[self::questionControl($templateId)] ?? '');
                $historyField = (string) ($settings['return_policy_history_field'] ?? '');
                if ($widgetId !== '' && ctype_digit($templateId) && ctype_digit($defaultType)
                    && ctype_digit($returnType) && $returnType !== $defaultType && $historyField !== ''
                    && preg_match('/^[a-f0-9]{64}$/D', $questionKey)
                ) {
                    $policies[self::contextId($postId, $widgetId)] = [
                        'post_id' => $postId,
                        'widget_id' => $widgetId,
                        'template_id' => $templateId,
                        'default_type_id' => $defaultType,
                        'return_type_id' => $returnType,
                        'question_key' => $questionKey,
                        'history_field' => $historyField,
                        'gateway' => strtolower((string) ($settings['custom_form_payment'] ?? 'stripe')),
                    ];
                }
            }

            if (!empty($element['elements'])) {
                self::collectPolicies($postId, $element['elements'], $policies);
            }
        }
    }
}
