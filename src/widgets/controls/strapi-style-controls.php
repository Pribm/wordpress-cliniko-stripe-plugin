<?php
if (!defined('ABSPATH')) exit;
use Elementor\Controls_Manager;

function register_style_controls($widget) {
  $widget->start_controls_section('stripe_style_section', [
    'label' => 'Stripe Appearance',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('theme', [
    'label' => 'Theme',
    'type' => Controls_Manager::SELECT,
    'default' => 'stripe',
    'options' => [
      'stripe' => 'Stripe Default',
      'night' => 'Night',
      'flat' => 'Flat',
    ]
  ]);

  $widget->add_control('layout', [
    'label' => 'Layout',
    'type' => Controls_Manager::SELECT,
    'default' => 'accordion',
    'options' => [
      'accordion' => 'Accordion',
      'tabs' => 'Tabs',
    ]
  ]);

  $widget->add_control('color_primary', [
    'label' => 'Primary Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
  ]);

  $widget->add_control('color_text', [
    'label' => 'Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
  ]);

  $widget->add_control('color_background', [
    'label' => 'Background Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
  ]);

  $widget->add_control('input_border', [
    'label' => 'Input Border',
    'type' => Controls_Manager::TEXT,
    'default' => '1px solid #ccc',
  ]);

  $widget->add_control('border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 4],
  ]);

  $widget->add_control('font_family', [
    'label' => 'Font Family',
    'type' => Controls_Manager::TEXT,
    'default' => 'Arial, sans-serif',
  ]);

  $widget->add_control('button_text_color', [
    'label' => 'Button Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
  ]);

  $widget->add_control('button_font_size', [
    'label' => 'Button Font Size',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 10, 'max' => 32]],
    'default' => ['size' => 16],
  ]);

  $widget->add_control('button_padding', [
    'label' => 'Button Padding',
    'type' => Controls_Manager::TEXT,
    'default' => '12px',
  ]);

  $widget->add_control('button_css', [
    'label' => 'Button Extra CSS',
    'type' => Controls_Manager::TEXTAREA,
    'default' => '',
  ]);

  $widget->end_controls_section();
}
