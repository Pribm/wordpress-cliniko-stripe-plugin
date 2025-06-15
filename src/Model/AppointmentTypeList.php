<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;

use App\Model\AppointmentType;
use App\Model\LinkedResource;

class AppointmentTypeList
{
    /** @var AppointmentType[] */
    public array $appointmentTypes;
    public int $totalEntries;
    public ?LinkedResource $self;
    public ?LinkedResource $previous;
    public ?LinkedResource $next;

    public static function fromArray(array $data): self
    {
        $list = new self();
        $list->appointmentTypes = array_map(
            fn($item) => AppointmentType::fromArray($item),
            $data['appointment_types'] ?? []
        );

        $list->totalEntries = $data['total_entries'] ?? count($list->appointmentTypes);

        $list->self = isset($data['links']['self']) ? new LinkedResource($data['links']['self']) : null;
        $list->previous = isset($data['links']['previous']) ? new LinkedResource($data['links']['previous']) : null;
        $list->next = isset($data['links']['next']) ? new LinkedResource($data['links']['next']) : null;

        return $list;
    }
}
