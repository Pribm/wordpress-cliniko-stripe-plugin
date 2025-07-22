<?php
if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;

function register_cliniko_form_style_controls($widget)
{
  error_log('Entrou no form style controls');
  // Seção: Estilo Geral
  $widget->start_controls_section('form_style_section', [
    'label' => 'Form Container',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_background_color', [
    'label' => 'Background Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_text_color', [
    'label' => 'Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_label_color', [
    'label' => 'Label Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#333333',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form label' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_input_border', [
    'label' => 'Input Border',
    'type' => Controls_Manager::TEXT,
    'default' => '1px solid #ccc',
  ]);

  $widget->add_control('form_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
  ]);

  $widget->add_control('form_font_family', [
    'label' => 'Font Family',
    'type' => Controls_Manager::TEXT,
    'default' => 'Arial, sans-serif',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'font-family: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_section();

  // Seção: Botões
  $widget->start_controls_section('form_button_style_section', [
    'label' => 'Buttons',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_button_color', [
    'label' => 'Button Background',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} .multi-form-button.next-button' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} .multi-form-button.prev-button' => 'border-color: {{VALUE}}; color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_button_text_color', [
    'label' => 'Button Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} .multi-form-button.next-button' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_button_padding', [
    'label' => 'Button Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'default' => [
      'top' => 12,
      'right' => 24,
      'bottom' => 12,
      'left' => 24,
      'unit' => 'px',
    ],
    'selectors' => [
      '{{WRAPPER}} .multi-form-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('form_button_border_radius', [
    'label' => 'Button Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
    'selectors' => [
      '{{WRAPPER}} .multi-form-button' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('form_button_icon_prev', [
    'label' => 'Back Button Icon',
    'type' => Controls_Manager::ICONS,
    'default' => ['value' => 'fas fa-arrow-left', 'library' => 'fa-solid'],
  ]);

  $widget->add_control('form_button_icon_next', [
    'label' => 'Next Button Icon',
    'type' => Controls_Manager::ICONS,
    'default' => ['value' => 'fas fa-arrow-right', 'library' => 'fa-solid'],
  ]);

  $widget->add_control('form_button_icon_position', [
    'label' => 'Icon Position',
    'type' => Controls_Manager::SELECT,
    'default' => 'before',
    'options' => [
      'before' => 'Before Text',
      'after' => 'After Text',
    ],
  ]);

  $widget->add_responsive_control('form_button_icon_spacing', [
    'label' => 'Icon Spacing',
    'type' => Controls_Manager::SLIDER,
    'range' => ['px' => ['min' => 0, 'max' => 50]],
    'default' => ['size' => 8],
  ]);

  $widget->end_controls_section();

  // Seção: Layout dos Botões
  $widget->start_controls_section('form_button_layout_section', [
    'label' => 'Button Layout',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_button_layout', [
    'label' => 'Layout Direction',
    'type' => Controls_Manager::SELECT,
    'default' => 'stacked',
    'options' => [
      'stacked' => 'Stacked (Vertical)',
      'row' => 'Row (Horizontal)',
    ],
  ]);

  $widget->add_control('form_button_width', [
    'label' => 'Button Width',
    'type' => Controls_Manager::SELECT,
    'default' => 'full',
    'options' => [
      'full' => 'Full Width',
      'auto' => 'Auto Width',
    ],
  ]);

  $widget->add_control('form_button_alignment', [
    'label' => 'Alignment',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'space-between' => ['title' => 'Space Between', 'icon' => 'eicon-h-align-stretch'],
    ],
    'toggle' => false,
    'default' => 'center',
  ]);

  $widget->end_controls_section();
}
