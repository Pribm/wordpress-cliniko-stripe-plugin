<?php

namespace App\Widgets\AppointmentTypeCard;
if (!defined('ABSPATH')) exit;

use App\Model\AppointmentType;

class Helpers {

    public function __construct(){}

static function get_appointment_types() {
  $client = cliniko_client(true);
  $types = AppointmentType::all($client);

    $mapped = array_map(function (AppointmentType $type) {
    $cents = $type->getBillableItemsFinalPrice();
    $price = is_numeric($cents) ? number_format($cents / 100, 2, '.', '') : 0;

    return [
      'id' => $type->getId(),
      'name' => $type->getName(),
      'description' => $type->getDescription(),
      'price' => $price,
    ];
  }, $types);

  return $mapped;
}

}

