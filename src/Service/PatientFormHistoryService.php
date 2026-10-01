<?php

namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientFormHistoryService
{
    private ApiClientInterface $client;

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 900)
            : cliniko_client(false));
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,has_more:bool}
     */
    public function forPatient(Patient $patient, int $page = 1, int $perPage = 5): array
    {
        $patientId = trim((string) $patient->getId());
        $page = max(1, $page);
        $perPage = max(1, min(20, $perPage));
        if ($patientId === '') {
            return ['items' => [], 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
        }

        $query = '?' . implode('&', [
            'page=' . $page,
            'per_page=' . $perPage,
            'sort=' . rawurlencode('created_at:desc'),
            'q[]=' . rawurlencode('patient_id:=' . $patientId),
            'q[]=' . rawurlencode('completed_at:?'),
            'q[]=' . rawurlencode('archived_at:*'),
        ]);
        $response = $this->client->get('patient_forms' . $query);
        if (!$response->isSuccessful()) {
            throw new \RuntimeException('Cliniko patient forms could not be loaded.');
        }

        $data = is_array($response->data) ? $response->data : [];
        $rows = is_array($data['patient_forms'] ?? null) ? $data['patient_forms'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->normalizeForm($row);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $next = trim((string) ($data['links']['next'] ?? ''));
        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $next !== '' || count($rows) === $perPage,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function normalizeForm(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        if (!is_array($row['content']['sections'] ?? null)) {
            $detail = $this->client->get('patient_forms/' . rawurlencode($id));
            if ($detail->isSuccessful() && is_array($detail->data)) {
                $row = $detail->data;
            }
        }

        return [
            'id' => $id,
            'name' => trim((string) ($row['name'] ?? 'Patient form')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'archived_at' => (string) ($row['archived_at'] ?? ''),
            'sections' => $this->normalizeSections($row['content']['sections'] ?? []),
        ];
    }

    /** @param mixed $rawSections @return array<int,array<string,mixed>> */
    private function normalizeSections($rawSections): array
    {
        if (!is_array($rawSections)) {
            return [];
        }

        $sections = [];
        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $questions = [];
            foreach (($section['questions'] ?? []) as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $answers = [];
                foreach (($question['answers'] ?? []) as $answer) {
                    if (!is_array($answer) || empty($answer['selected'])) {
                        continue;
                    }
                    $value = trim((string) ($answer['value'] ?? ''));
                    if ($value !== '') {
                        $answers[] = $value;
                    }
                }
                $other = is_array($question['other'] ?? null) ? $question['other'] : [];
                if (!empty($other['selected']) && trim((string) ($other['value'] ?? '')) !== '') {
                    $answers[] = trim((string) $other['value']);
                }

                $questions[] = [
                    'name' => trim((string) ($question['name'] ?? '')),
                    'type' => strtolower(trim((string) ($question['type'] ?? 'text'))),
                    'answer' => is_scalar($question['answer'] ?? null) ? (string) $question['answer'] : '',
                    'selected_answers' => $answers,
                ];
            }
            $sections[] = [
                'name' => trim((string) ($section['name'] ?? '')),
                'description' => (string) ($section['description'] ?? ''),
                'questions' => $questions,
            ];
        }

        return $sections;
    }
}
