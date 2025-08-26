<?php

use App\Model\AppointmentType;


if (!defined('ABSPATH')) exit;
use App\Widgets\AppointmentTypeCard\Helpers;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;



$this->start_controls_section('section_content', [
  'label' => __('Content', 'plugin-name'),
]);

$client = cliniko_client(true);
$types = AppointmentType::all($client, true);

// $options = array_column($types, 'name', 'id');

$options = [];
foreach ($types as $type) {
    /** @var AppointmentType $type */
    $options[$type->getId()] = $type->getName();
}

$this->add_control('selected_appointment_type', [
  'label' => __('Select Appointment Type', 'plugin-name'),
  'type' => Controls_Manager::SELECT,
  'options' => $options,
  'default' => array_key_first($options),
]);

$this->add_control('button_text', [
  'label' => __('Button Text', 'plugin-name'),
  'type' => Controls_Manager::TEXT,
  'default' => __('Book Now', 'plugin-name'),
]);

$this->add_control('button_link', [
  'label' => __('Button Link', 'plugin-name'),
  'type' => Controls_Manager::URL,
  'show_external' => true,
    'default' => [
    'url' => '#',
    'is_external' => false,
    'nofollow' => false,
  ],
]);

$this->add_control('price_label', [
  'label' => __('Price Label', 'plugin-name'),
  'type' => Controls_Manager::TEXT,
  'default' => __('Price:', 'plugin-name'),
]);

$this->add_control('card_icon', [
  'label' => __('Card Icon', 'plugin-name'),
  'type' => Controls_Manager::ICONS,
  'default' => [
    'library' => 'solid',
    'value' => 'fas fa-stethoscope',
  ],
]);

$this->end_controls_section();

// Layout
$this->start_controls_section('layout_section', [
  'label' => __('Card Layout', 'plugin-name'),
  'tab' => Controls_Manager::TAB_STYLE,
]);

$this->add_control('card_gap', [
  'label' => __('Gap Between Elements', 'plugin-name'),
  'type' => Controls_Manager::SLIDER,
  'default' => ['size' => 16, 'unit' => 'px'],
  'range' => [
    'px' => ['min' => 0, 'max' => 100],
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-card' => 'gap: {{SIZE}}{{UNIT}};',
  ]
]);

$this->add_control('price_position', [
  'label' => __('Price Position', 'plugin-name'),
  'type' => Controls_Manager::SELECT,
  'default' => 'top-right',
  'options' => [
    'top-left' => 'Top Left',
    'top-right' => 'Top Right',
    'bottom-left' => 'Bottom Left',
    'bottom-right' => 'Bottom Right',
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-card-price' => 'position: absolute;',
    '{{WRAPPER}} .appointment-card-price.top-left' => 'top: var(--card-padding-top); left: var(--card-padding-left);',
    '{{WRAPPER}} .appointment-card-price.top-right' => 'top: var(--card-padding-top); right: var(--card-padding-right);',
    '{{WRAPPER}} .appointment-card-price.bottom-left' => 'bottom: var(--card-padding-bottom); left: var(--card-padding-left);',
    '{{WRAPPER}} .appointment-card-price.bottom-right' => 'bottom: var(--card-padding-bottom); right: var(--card-padding-right);',
  ],
]);



$this->end_controls_section();

// Estilo
$this->start_controls_section('card_style_section', [
  'label' => __('Card Style', 'plugin-name'),
  'tab' => Controls_Manager::TAB_STYLE,
]);

$this->add_control('card_background', [
  'label' => __('Card Background', 'plugin-name'),
  'type' => Controls_Manager::COLOR,
   'default' => 'var(--e-global-color-secondary)',
  'selectors' => [
    '{{WRAPPER}} .appointment-card' => 'background-color: {{VALUE}};',
  ],
]);

$this->add_control('card_padding', [
  'label' => __('Card Padding', 'plugin-name'),
  'type' => Controls_Manager::DIMENSIONS,
  'default' => [
    'top' => 20,
    'right' => 20,
    'bottom' => 20,
    'left' => 20,
    'unit' => 'px',
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-card' =>
      '--card-padding-top: {{TOP}}{{UNIT}}; ' .
      '--card-padding-right: {{RIGHT}}{{UNIT}}; ' .
      '--card-padding-bottom: {{BOTTOM}}{{UNIT}}; ' .
      '--card-padding-left: {{LEFT}}{{UNIT}}; ' .
      'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
  ],
]);

$this->add_responsive_control('card_height', [
  'label' => __('Card Height', 'plugin-name'),
  'type' => Controls_Manager::SLIDER,
  'range' => [
    'px' => ['min' => 0, 'max' => 1000],
    '%'  => ['min' => 0, 'max' => 100],
    'vh' => ['min' => 0, 'max' => 100],
  ],
  'size_units' => ['px', '%', 'vh'],
  'default' => [
    'size' => 100,
    'unit' => '%',
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-card' => 'height: {{SIZE}}{{UNIT}};',
  ],
]);

$this->add_control('card_border_radius', [
  'label' => __('Card Border Radius', 'plugin-name'),
  'type' => Controls_Manager::SLIDER,
  'selectors' => [
    '{{WRAPPER}} .appointment-card' => 'border-radius: {{SIZE}}{{UNIT}};',
  ],
]);

$this->add_group_control(Group_Control_Box_Shadow::get_type(), [
  'name' => 'card_box_shadow',
  'selector' => '{{WRAPPER}} .appointment-card',
]);

$this->end_controls_section();

$this->start_controls_section('card_icon_style_section', [
  'label' => __('Icon Style', 'plugin-name'),
  'tab' => Controls_Manager::TAB_STYLE,
]);

$this->add_control('card_icon_color', [
  'label' => __('Icon Color', 'plugin-name'),
  'type' => Controls_Manager::COLOR,
  'selectors' => [
    '{{WRAPPER}} .appointment-card-icon i' => 'color: {{VALUE}};',
    '{{WRAPPER}} .appointment-card-icon svg' => 'fill: {{VALUE}};',
  ],
]);

$this->add_responsive_control('card_icon_size', [
  'label' => __('Icon Size', 'plugin-name'),
  'type' => Controls_Manager::SLIDER,
  'range' => [
    'px' => ['min' => 8, 'max' => 120],
  ],
  'default' => ['size' => 32, 'unit' => 'px'],
  'selectors' => [
    '{{WRAPPER}} .appointment-card-icon i' => 'font-size: {{SIZE}}{{UNIT}};',
    '{{WRAPPER}} .appointment-card-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
  ],
]);


$this->end_controls_section();

$this->start_controls_section('button_style_section', [
  'label' => __('Button Style', 'plugin-name'),
  'tab' => Controls_Manager::TAB_STYLE,
]);

$this->add_control('button_text_color', [
  'label' => __('Text Color', 'plugin-name'),
  'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' => 'color: {{VALUE}};',
  ],
]);

$this->add_control('button_background', [
  'label' => __('Background Color', 'plugin-name'),
  'type' => Controls_Manager::COLOR,
  'default' => 'var(--e-global-color-primary)',
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' => 'background-color: {{VALUE}};',
  ],
]);

$this->add_group_control(Group_Control_Typography::get_type(), [
  'name' => 'button_typography',
  'selector' => '{{WRAPPER}} .appointment-action-button',
]);

$this->add_responsive_control('button_text_align', [
  'label' => __('Button Text Align', 'plugin-name'),
  'type' => Controls_Manager::CHOOSE,
  'options' => [
    'left' => [
      'title' => __('Left', 'plugin-name'),
      'icon' => 'eicon-text-align-left',
    ],
    'center' => [
      'title' => __('Center', 'plugin-name'),
      'icon' => 'eicon-text-align-center',
    ],
    'right' => [
      'title' => __('Right', 'plugin-name'),
      'icon' => 'eicon-text-align-right',
    ],
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' => 'text-align: {{VALUE}};',
  ],
  'default' => 'center',
]);

$this->add_control('button_show_icon', [
  'label' => __('Show Button Icon', 'plugin-name'),
  'type' => Controls_Manager::SWITCHER,
  'label_on' => __('Yes', 'plugin-name'),
  'label_off' => __('No', 'plugin-name'),
  'default' => 'yes',
]);

$this->add_control('button_icon', [
  'label' => __('Button Icon', 'plugin-name'),
  'type' => Controls_Manager::ICONS,
  'condition' => ['button_show_icon' => 'yes'],
  'default' => [
    'library' => 'solid',
    'value' => 'fas fa-arrow-right',
  ],
]);

$this->add_control('button_margin', [
  'label' => __('Button Margin', 'plugin-name'),
  'type' => Controls_Manager::DIMENSIONS,
  'default' => [
    'top' => 0,
    'right' => 0,
    'bottom' => 0,
    'left' => 0,
    'unit' => 'px',
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' =>
      'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
  ],
]);


$this->add_control('button_padding', [
  'label' => __('Padding', 'plugin-name'),
  'type' => Controls_Manager::DIMENSIONS,
   'default' => [
    'top' => 12,
    'right' => 24,
    'bottom' => 12,
    'left' => 24,
    'unit' => 'px',
   ],
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
  ],
]);

$this->add_control('button_border_radius', [
  'label' => __('Border Radius', 'plugin-name'),
  'type' => Controls_Manager::SLIDER,
    'default' => [
    'size' => 8,
    'unit' => 'px',
  ],
  'selectors' => [
    '{{WRAPPER}} .appointment-action-button' => 'border-radius: {{SIZE}}{{UNIT}};',
  ],
]);

$this->add_control('button_custom_class', [
  'label' => __('Button Custom Class', 'plugin-name'),
  'type' => Controls_Manager::TEXT,
  'placeholder' => 'ex: my-hover-class',
  'description' => __('You can define a custom CSS class to target the button in your stylesheet.'),
]);

$this->end_controls_section();


// Tipografia
$this->start_controls_section('typography_section', [
  'label' => __('Typography', 'plugin-name'),
  'tab' => Controls_Manager::TAB_STYLE,
]);

$this->add_group_control(Group_Control_Typography::get_type(), [
  'name' => 'title_typography',
  'label' => __('Title Typography', 'plugin-name'),
  'selector' => '{{WRAPPER}} .appointment-card h3',
]);

$this->add_group_control(Group_Control_Typography::get_type(), [
  'name' => 'description_typography',
  'label' => __('Description Typography', 'plugin-name'),
  'selector' => '{{WRAPPER}} .appointment-card p',
]);

$this->add_group_control(Group_Control_Typography::get_type(), [
  'name' => 'price_typography',
  'label' => __('Price Typography', 'plugin-name'),
  'selector' => '{{WRAPPER}} .appointment-card-price',
]);

$this->end_controls_section();
