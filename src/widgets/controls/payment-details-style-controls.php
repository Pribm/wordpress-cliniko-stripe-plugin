<?php
if (!defined('ABSPATH')) exit;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

function register_details_style_controls($widget) {
  $widget->start_controls_section('details_style_section', [
    'label' => 'Payment Details Style',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('details_background', [
    'label' => 'Details Background Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#f9f9f9',
  ]);

  $widget->add_control('details_border_color', [
    'label' => 'Details Border Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ccc',
  ]);

  $widget->add_control('details_border_radius', [
    'label' => 'Details Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
  ]);

  $widget->add_control('gap_between_columns', [
    'label' => 'Gap Between Columns',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 80]],
    'default' => ['size' => 40],
  ]);

  $widget->add_control('summary_heading_color', [
    'label' => 'Summary Heading Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
  ]);

  $widget->add_group_control(Group_Control_Typography::get_type(), [
    'name' => 'summary_heading_typography',
    'label' => 'Heading Typography',
    'selector' => '{{WRAPPER}} .summary-heading',
  ]);

  $widget->add_control('summary_text_color', [
    'label' => 'Summary Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
  ]);

  $widget->add_group_control(Group_Control_Typography::get_type(), [
    'name' => 'summary_text_typography',
    'label' => 'Text Typography',
    'selector' => '{{WRAPPER}} .summary-text',
  ]);

  $widget->add_control('summary_price_color', [
    'label' => 'Price Highlight Color',
    'type' => Controls_Manager::COLOR,
    'default' => 'green',
  ]);

  $widget->add_group_control(Group_Control_Typography::get_type(), [
    'name' => 'summary_price_typography',
    'label' => 'Price Typography',
    'selector' => '{{WRAPPER}} .summary-price',
  ]);

  $widget->end_controls_section();
}
