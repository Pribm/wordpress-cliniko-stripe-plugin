<?php

namespace App\Widgets\AppointmentTypeCard;
if (!defined('ABSPATH')) exit;

use App\Client\Cliniko\Client;
use App\Model\AppointmentType;

class Helpers {

    public function __construct(){}

   static function get_appointment_types(): array {
  $cache_key = 'cliniko_appointment_types_render_data';
  $cached = get_transient($cache_key);
  if ($cached !== false) return $cached;

  $types = AppointmentType::all(cliniko_client(true));
  $mapped = array_map(function (AppointmentType $type) {
    $cents = $type->getBillableItemsFinalPrice();
    $price = is_numeric($cents) ? number_format($cents / 100, 2, '.', '') : null;

    return [
      'id' => $type->getId(),
      'name' => $type->getName(),
      'description' => $type->getDescription(),
      'price' => $price,
    ];
  }, $types);

  set_transient($cache_key, $mapped, 3600); // cache do mapeamento também
  return $mapped;
}

}

