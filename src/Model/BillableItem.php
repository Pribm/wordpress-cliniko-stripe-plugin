<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;

use App\Model\LinkedResource;

class BillableItem
{
    public string $id;
    public string $name;
    public string $itemCode;
    public string $itemType;
    public float $price;

    public ?string $archivedAt = null;
    public string $createdAt;
    public string $updatedAt;

    public ?LinkedResource $selfLink = null;
    public ?LinkedResource $tax = null;

    public static function fromArray(array $data): self
    {
        $item = new self();

        $item->id = $data['id'];
        $item->name = $data['name'];
        $item->itemCode = $data['item_code'] ?? '';
        $item->itemType = $data['item_type'] ?? '';
        $item->price = isset($data['price']) ? (float) $data['price'] : 0.0;

        $item->archivedAt = $data['archived_at'] ?? null;
        $item->createdAt = $data['created_at'];
        $item->updatedAt = $data['updated_at'];

        $item->selfLink = isset($data['links']['self']) 
            ? new LinkedResource($data['links']['self']) 
            : null;

        $item->tax = isset($data['tax']['links']['self']) 
            ? new LinkedResource($data['tax']['links']['self']) 
            : null;

        return $item;
    }
}
