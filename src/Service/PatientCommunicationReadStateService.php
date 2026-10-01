<?php

namespace App\Service;

use App\Model\Communication;

if (!defined('ABSPATH')) exit;

/** Stores the current WordPress user's locally-read Cliniko communication IDs. */
final class PatientCommunicationReadStateService
{
    private const META_KEY = 'wp_cliniko_patient_read_communication_ids';
    private const MAX_IDS = 2000;

    /** @param list<Communication> $communications @return array{general:int,email:int,sms:int,total:int} */
    public function unreadSummary(array $communications, int $userId): array
    {
        $read = $this->readIds($userId);
        $summary = ['general' => 0, 'email' => 0, 'sms' => 0, 'total' => 0];
        foreach ($communications as $communication) {
            $dto = $communication->getCommunicationDTO();
            if ($dto === null || $dto->id === '' || $dto->directionCode !== 1 || isset($read[$dto->id])) continue;
            if ($dto->typeCode === 2) {
                $summary['email']++;
            } elseif ($dto->typeCode === 1) {
                $summary['sms']++;
            } else {
                $summary['general']++;
            }
            $summary['total']++;
        }
        return $summary;
    }

    /** @param list<Communication> $communications @return array<int,true> */
    public function unreadIds(array $communications, int $userId): array
    {
        $read = $this->readIds($userId);
        $unread = [];
        foreach ($communications as $communication) {
            $dto = $communication->getCommunicationDTO();
            if ($dto === null || $dto->id === '' || $dto->directionCode !== 1 || isset($read[$dto->id])) continue;
            $unread[(int) $dto->id] = true;
        }
        return $unread;
    }

    /** @param array<int,mixed> $ids @return list<string> IDs that became read during this request. */
    public function markRead(array $ids, int $userId): array
    {
        if ($userId <= 0) return [];
        $read = $this->readIds($userId);
        $newlyRead = [];
        foreach (array_slice($ids, 0, 100) as $id) {
            $id = sanitize_text_field((string) $id);
            if ($id !== '' && ctype_digit($id)) {
                if (!isset($read[$id])) $newlyRead[] = $id;
                $read[$id] = time();
            }
        }
        arsort($read, SORT_NUMERIC);
        update_user_meta($userId, self::META_KEY, array_slice($read, 0, self::MAX_IDS, true));
        return $newlyRead;
    }

    /** @return array<int,int> */
    private function readIds(int $userId): array
    {
        $stored = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($stored)) return [];
        $read = [];
        foreach ($stored as $id => $timestamp) if (ctype_digit((string) $id)) $read[(string) $id] = (int) $timestamp;
        return $read;
    }
}
