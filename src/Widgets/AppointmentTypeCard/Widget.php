<?php
namespace App\Widgets\AppointmentTypeCard;
if (!defined('ABSPATH')) exit;

use Elementor\Plugin;
use Elementor\Widget_Base;


class Widget extends Widget_Base {
  public function get_name() {
    return 'cliniko_appointment_type_card';
  }

  public function get_title() {
    return __('Cliniko: Appointment Type Card', 'plugin-name');
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

    if (Plugin::$instance->editor->is_edit_mode()) {
      $types = get_transient('cliniko_appointment_types_render_data') ?: [];
    } else {
      $types = Helpers::get_appointment_types();
    }

    $selected = $settings['selected_appointment_type'] ?? null;
    $type = array_filter($types, fn($t) => $t['id'] === $selected);
    $type = reset($type);

    if (!$type) {
      echo '<p>No appointment type selected.</p>';
      return;
    }

    include __DIR__ . '/render.phtml';
  }
}