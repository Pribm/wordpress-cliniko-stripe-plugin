<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;

use App\Model\LinkedResource;

class AppointmentType
{
    public string $id;
    public string $name;
    public ?string $description;
    public ?string $category;
    public string $color;
    public int $durationInMinutes;
    public int $maxAttendees;
    public ?string $depositPrice;
    public ?string $archivedAt;
    public string $createdAt;
    public string $updatedAt;

    public bool $addDepositToAccountCredit;
    public bool $showInOnlineBookings;
    public bool $telehealthEnabled;
    public bool $onlinePaymentsEnabled;
    public ?string $onlinePaymentsMode;
    public ?int $onlineBookingsLeadTimeHours;

    /** @var string[] */
    public array $confirmationTemplateIds;
    /** @var string[] */
    public array $reminderTemplateIds;
    /** @var string[] */
    public array $followUpTemplateIds;

    public ?LinkedResource $billableItem;
    public ?LinkedResource $billableItems;
    public ?LinkedResource $products;
    public ?LinkedResource $product;
    public ?LinkedResource $practitioners;
    public ?LinkedResource $treatmentNoteTemplate;
    public ?LinkedResource $selfLink;

    public static function fromArray(array $data): self
    {
        $obj = new self();

        $obj->id = $data['id'];
        $obj->name = $data['name'];
        $obj->description = $data['description'] ?? null;
        $obj->category = $data['category'] ?? null;
        $obj->color = $data['color'];
        $obj->durationInMinutes = $data['duration_in_minutes'];
        $obj->maxAttendees = $data['max_attendees'];
        $obj->depositPrice = $data['deposit_price'] ?? null;
        $obj->archivedAt = $data['archived_at'] ?? null;
        $obj->createdAt = $data['created_at'];
        $obj->updatedAt = $data['updated_at'];

        $obj->addDepositToAccountCredit = $data['add_deposit_to_account_credit'] ?? false;
        $obj->showInOnlineBookings = $data['show_in_online_bookings'] ?? false;
        $obj->telehealthEnabled = $data['telehealth_enabled'] ?? false;
        $obj->onlinePaymentsEnabled = $data['online_payments_enabled'] ?? false;
        $obj->onlinePaymentsMode = $data['online_payments_mode'] ?? null;
        $obj->onlineBookingsLeadTimeHours = $data['online_bookings_lead_time_hours'] ?? null;

        $obj->confirmationTemplateIds = $data['appointment_confirmation_template_ids'] ?? [];
        $obj->reminderTemplateIds = $data['appointment_reminder_template_ids'] ?? [];
        $obj->followUpTemplateIds = $data['appointment_follow_up_template_ids'] ?? [];

        $obj->billableItem = isset($data['billable_item']['links']['self']) ? new LinkedResource($data['billable_item']['links']['self']) : null;
        $obj->billableItems = isset($data['appointment_type_billable_items']['links']['self']) ? new LinkedResource($data['appointment_type_billable_items']['links']['self']) : null;
        $obj->products = isset($data['appointment_type_products']['links']['self']) ? new LinkedResource($data['appointment_type_products']['links']['self']) : null;
        $obj->product = isset($data['product']['links']['self']) ? new LinkedResource($data['product']['links']['self']) : null;
        $obj->practitioners = isset($data['practitioners']['links']['self']) ? new LinkedResource($data['practitioners']['links']['self']) : null;
        $obj->treatmentNoteTemplate = isset($data['treatment_note_template']['links']['self']) ? new LinkedResource($data['treatment_note_template']['links']['self']) : null;
        $obj->selfLink = isset($data['links']['self']) ? new LinkedResource($data['links']['self']) : null;

        return $obj;
    }
}
