<?php

namespace App\Controller;

use App\DTO\CommunicationDTO;
use App\Exception\ApiException;
use App\Model\Communication;
use App\Service\PatientCommunicationReadStateService;
use App\Support\Auth;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/** Exposes only the authenticated patient's Cliniko communication history. */
final class PatientCommunicationController
{
    private PatientCommunicationReadStateService $readState;

    public function __construct(?PatientCommunicationReadStateService $readState = null)
    {
        $this->readState = $readState ?: new PatientCommunicationReadStateService();
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function () use ($request): array {
            $view = sanitize_key((string) ($request->get_param('view') ?: 'general'));
            $typeCode = $view === 'email' ? 2 : ($view === 'sms' ? 1 : null);
            $result = Communication::forAuthenticatedPatient(null, max(1, (int) $request->get_param('page')), max(1, (int) ($request->get_param('per_page') ?: 20)), $typeCode);
            $items = $result['items'];
            if ($view === 'general') {
                $items = array_values(array_filter($items, static function (Communication $item): bool {
                    return !in_array($item->getCommunicationDTO()?->typeCode, [1, 2], true);
                }));
            }
            $unreadIds = $this->readState->unreadIds($items, get_current_user_id());
            return [
                'communications' => array_values(array_filter(array_map(function (Communication $item) use ($unreadIds): ?array {
                    $dto = $item->getCommunicationDTO();
                    return $dto === null ? null : $this->toArray($dto, isset($unreadIds[(int) $dto->id]));
                }, $items))),
                'pagination' => $result['pagination'],
            ];
        });
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function () use ($request): array {
            $body = json_decode($request->get_body(), true);
            $data = is_array($body) ? $body : $request->get_params();
            $content = sanitize_textarea_field((string) ($data['content'] ?? ''));
            if ($content === '') return ['__error' => 'Please enter a message.', '__status' => 400];
            if ((function_exists('mb_strlen') ? mb_strlen($content) : strlen($content)) > 5000) return ['__error' => 'Your message must be 5,000 characters or fewer.', '__status' => 400];

            $user = wp_get_current_user();
            $from = trim((string) $user->display_name);
            if ($from === '') $from = trim((string) $user->user_email);
            $clinicLabel = sanitize_text_field((string) ($data['clinic_label'] ?? 'Clinic'));
            $created = Communication::createReceivedMemoForAuthenticatedPatient($content, $from, $clinicLabel !== '' ? $clinicLabel : 'Clinic', 4);
            return $created === null
                ? ['__error' => 'Patient not found.', '__status' => 404]
                : ['communication' => $this->toArray($created->getCommunicationDTO())];
        });
    }

    public function unread(): WP_REST_Response
    {
        return $this->respond(fn(): array => [
            'unread' => $this->readState->unreadSummary(Communication::allForAuthenticatedPatient(), get_current_user_id()),
        ]);
    }

    public function markRead(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function () use ($request): array {
            $body = json_decode($request->get_body(), true);
            $data = is_array($body) ? $body : $request->get_params();
            $ids = is_array($data['ids'] ?? null) ? $data['ids'] : [];
            $readIds = $this->readState->markRead($ids, get_current_user_id());
            if (function_exists('cliniko_dashboard_cache_invalidate')) {
                cliniko_dashboard_cache_invalidate();
            }
            return ['read_ids' => $readIds];
        });
    }

    /** @param callable():array<string,mixed> $callback */
    private function respond(callable $callback): WP_REST_Response
    {
        try {
            if (!is_user_logged_in()) return new WP_REST_Response(['ok' => false, 'message' => 'Authentication required.'], 403);
            $patient = Auth::user();
            if ($patient === null || (string) $patient->getId() === '') return new WP_REST_Response(['ok' => false, 'message' => 'Patient not found.'], 404);
            $result = $callback();
            if (isset($result['__error'])) return new WP_REST_Response(['ok' => false, 'message' => $result['__error']], (int) ($result['__status'] ?? 400));
            return new WP_REST_Response(['ok' => true, 'data' => $result], 200);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient communication request failed: ' . $exception->getMessage());
            return new WP_REST_Response([
                'ok' => false,
                'code' => $exception instanceof ApiException ? 'cliniko_connection_unavailable' : 'cliniko_communication_request_failed',
                'message' => $exception instanceof ApiException
                    ? 'Cliniko is temporarily unavailable. Please try again shortly.'
                    : 'Communications are temporarily unavailable.',
            ], $exception instanceof ApiException ? 503 : 502);
        }
    }

    /** @return array<string,mixed>|null */
    private function toArray(?CommunicationDTO $dto, bool $isUnread = false): ?array
    {
        if ($dto === null) return null;
        return [
            'id' => $dto->id,
            'category' => $dto->category,
            'content' => $dto->typeCode === 2 ? self::safeEmailHtml($dto->content) : $dto->content,
            'content_html' => $dto->typeCode === 2,
            'created_at' => $dto->createdAt,
            'direction_code' => $dto->directionCode,
            'direction_description' => $dto->directionDescription,
            'from' => $dto->from,
            'to' => $dto->to,
            'type' => $dto->type,
            'type_code' => $dto->typeCode,
            'is_unread' => $isUnread,
        ];
    }

    private static function safeEmailHtml(string $content): string
    {
        return wp_kses($content, [
            'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'p' => ['role' => true], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
            'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
            'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [], 'div' => ['role' => true],
            'span' => ['role' => true], 'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
            'th' => ['colspan' => true, 'rowspan' => true], 'td' => ['colspan' => true, 'rowspan' => true],
        ]);
    }
}
