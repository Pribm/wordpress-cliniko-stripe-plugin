<?php
if (!defined('ABSPATH')) exit;
use Elementor\Controls_Manager;

function register_cliniko_form_style_controls($widget) {
  $widget->start_controls_section('form_style_section', [
    'label' => 'Multistep Form Style',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_background_color', [
    'label' => 'Background Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
  ]);

  $widget->add_control('form_text_color', [
    'label' => 'Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
  ]);

  $widget->add_control('form_label_color', [
    'label' => 'Label Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#333333',
  ]);

  $widget->add_control('form_input_border', [
    'label' => 'Input Border',
    'type' => Controls_Manager::TEXT,
    'default' => '1px solid #ccc',
  ]);

  $widget->add_control('form_border_radius', [
    'label' => 'Input Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
  ]);

  $widget->add_control('form_font_family', [
    'label' => 'Font Family',
    'type' => Controls_Manager::TEXT,
    'default' => 'Arial, sans-serif',
  ]);

  $widget->add_control('form_button_color', [
    'label' => 'Button Background',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)'
  ]);


  $widget->add_control('form_button_text_color', [
    'label' => 'Button Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
  ]);

  $widget->add_control('form_button_padding', [
    'label' => 'Button Padding',
    'type' => Controls_Manager::TEXT,
    'default' => '12px 24px',
  ]);

  $widget->end_controls_section();
}
