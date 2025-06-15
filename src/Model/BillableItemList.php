<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;

use App\Model\BillableItem;
use App\Model\LinkedResource;

class BillableItemList
{
    /** @var BillableItem[] */
    public array $billableItems;

    public int $totalEntries;

    public ?LinkedResource $self = null;
    public ?LinkedResource $previous = null;
    public ?LinkedResource $next = null;

    public static function fromArray(array $data): self
    {
        $list = new self();

        $list->billableItems = array_map(
            fn(array $item) => BillableItem::fromArray($item),
            $data['billable_items'] ?? []
        );

        $list->totalEntries = $data['total_entries'] ?? count($list->billableItems);

        $list->self = isset($data['links']['self']) ? new LinkedResource($data['links']['self']) : null;
        $list->previous = isset($data['links']['previous']) ? new LinkedResource($data['links']['previous']) : null;
        $list->next = isset($data['links']['next']) ? new LinkedResource($data['links']['next']) : null;

        return $list;
    }
}
