<?php
namespace App\Widgets\AppointmentTypePriceTag;

use App\Model\AppointmentType;
if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;


class Widget extends Widget_Base {
  public function get_name() {
    return 'cliniko_appointment_type_price_tag';
  }

  public function get_title() {
    return __('Cliniko: Appointment Type Price Tag', 'plugin-name');
  }

  public function get_icon() {
    return 'eicon-post';
  }

  public function get_categories() {
    return ['cliniko-widgets'];
  }

  public function _register_controls() {
    require __DIR__ . '/controls.php';
  }

protected function render() {
    $settings = $this->get_settings_for_display();

    $client = cliniko_client(true);
    $types = AppointmentType::all($client, true);

    $selected = $settings['selected_appointment_type'] ?? null;
    $type = array_filter($types, fn(AppointmentType $t) => $t->getId() === $selected);
    $type = reset($type);

    if (!$type) {
      echo '<p>No appointment type selected.</p>';
      return;
    }

    include __DIR__ . '/render.phtml';
}

}