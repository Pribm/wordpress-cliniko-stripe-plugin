<?php
if (!defined('ABSPATH')) {
  exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

function register_cliniko_form_style_controls($widget)
{
  /*
   * SECTION: Page wrapper
   */
  $widget->start_controls_section('form_page_wrapper_style_section', [
    'label' => 'Page Wrapper',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('page_wrapper_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}}' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_min_height', [
    'label' => 'Min Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh'],
    'range' => [
      'px' => ['min' => 240, 'max' => 1600],
      'vh' => ['min' => 20, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}}' => 'min-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_height', [
    'label' => 'Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh', '%'],
    'range' => [
      'px' => ['min' => 240, 'max' => 1600],
      'vh' => ['min' => 20, 'max' => 100],
      '%' => ['min' => 0, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}}' => 'height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}}' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_margin', [
    'label' => 'Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}}' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('page_wrapper_enable_flex', [
    'label' => 'Use Shell Centering',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
    'selectors' => [
      '{{WRAPPER}}' => 'display: flex; flex-direction: column;',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_horizontal_align', [
    'label' => 'Horizontal Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'stretch' => ['title' => 'Stretch', 'icon' => 'eicon-h-align-stretch'],
    ],
    'selectors' => [
      '{{WRAPPER}}' => 'align-items: {{VALUE}};',
    ],
    'condition' => [
      'page_wrapper_enable_flex' => 'yes',
    ],
  ]);

  $widget->add_responsive_control('page_wrapper_vertical_align', [
    'label' => 'Vertical Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Top', 'icon' => 'eicon-v-align-top'],
      'center' => ['title' => 'Middle', 'icon' => 'eicon-v-align-middle'],
      'flex-end' => ['title' => 'Bottom', 'icon' => 'eicon-v-align-bottom'],
    ],
    'selectors' => [
      '{{WRAPPER}}' => 'justify-content: {{VALUE}};',
    ],
    'condition' => [
      'page_wrapper_enable_flex' => 'yes',
    ],
  ]);

  $widget->end_controls_section();

  /*
   * SECTION: Form container
   */
  $widget->start_controls_section('form_container_style_section', [
    'label' => 'Form Container',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_background_color', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('form_container_max_width', [
    'label' => 'Max Width',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => 320, 'max' => 1400],
      '%' => ['min' => 40, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'max-width: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_container_height', [
    'label' => 'Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh', '%'],
    'range' => [
      'px' => ['min' => 240, 'max' => 1400],
      'vh' => ['min' => 20, 'max' => 100],
      '%' => ['min' => 0, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_container_min_height', [
    'label' => 'Min Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh', '%'],
    'range' => [
      'px' => ['min' => 240, 'max' => 1400],
      'vh' => ['min' => 20, 'max' => 100],
      '%' => ['min' => 0, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'min-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('form_container_overflow', [
    'label' => 'Overflow',
    'type' => Controls_Manager::SELECT,
    'default' => '',
    'options' => [
      '' => 'Default',
      'visible' => 'Visible',
      'hidden' => 'Hidden',
      'auto' => 'Scroll',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'overflow: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('form_container_backdrop_blur', [
    'label' => 'Backdrop Blur',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 30]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'backdrop-filter: blur({{SIZE}}{{UNIT}}); -webkit-backdrop-filter: blur({{SIZE}}{{UNIT}});',
    ],
  ]);

  $widget->add_control('form_actions_stick_bottom', [
    'label' => 'Stick Buttons To Bottom',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'flex: 1 1 auto;',
      '{{WRAPPER}} #prepayment-form .form-actions' => 'margin-top: auto;',
    ],
  ]);

  $widget->add_responsive_control('form_container_alignment', [
    'label' => 'Alignment',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'left' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'right' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
    ],
    'default' => 'center',
    'selectors_dictionary' => [
      'left' => 'margin-left: 0; margin-right: auto;',
      'center' => 'margin-left: auto; margin-right: auto;',
      'right' => 'margin-left: auto; margin-right: 0;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => '{{VALUE}}',
    ],
  ]);

  $widget->add_responsive_control('form_container_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'default' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_container_margin', [
    'label' => 'Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'form_container_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form',
    ]
  );

  $widget->add_responsive_control('form_container_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 60]],
    'default' => ['size' => 6, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'form_container_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form',
    ]
  );

  $widget->end_controls_section();

  /*
   * SECTION: Shell panels
   */
  $widget->start_controls_section('form_shell_panels_style_section', [
    'label' => 'Shell Panels',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('shell_intro_heading', [
    'label' => 'Header Intro',
    'type' => Controls_Manager::HEADING,
  ]);

  $widget->add_control('shell_intro_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('shell_intro_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('step_area_heading', [
    'label' => 'Step Area',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('step_area_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('step_area_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('step_area_overflow', [
    'label' => 'Overflow',
    'type' => Controls_Manager::SELECT,
    'default' => '',
    'options' => [
      '' => 'Default',
      'visible' => 'Visible',
      'auto' => 'Scroll',
      'hidden' => 'Hidden',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'overflow-y: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('step_area_min_height', [
    'label' => 'Min Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh'],
    'range' => [
      'px' => ['min' => 120, 'max' => 1000],
      'vh' => ['min' => 10, 'max' => 90],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'min-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('step_card_heading', [
    'label' => 'Step Card',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('step_card_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('step_card_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'step_card_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step',
    ]
  );

  $widget->add_responsive_control('step_card_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 60]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'step_card_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step',
    ]
  );

  $widget->add_responsive_control('question_block_gap', [
    'label' => 'Question Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 48],
      'em' => ['min' => 0, 'max' => 4],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .inputGroup' => 'margin-bottom: {{SIZE}}{{UNIT}};',
      '{{WRAPPER}} #prepayment-form .patient-grid' => 'gap: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->end_controls_section();

  /*
   * SECTION: Questions container
   * Targets the wrapper around the rendered Cliniko questions/steps.
   */
  $widget->start_controls_section('questions_container_style_section', [
    'label' => 'Questions Container',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('questions_container_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('questions_container_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('questions_container_margin', [
    'label' => 'Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'questions_container_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form #cliniko-form-steps',
    ]
  );

  $widget->add_responsive_control('questions_container_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 80]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'questions_container_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form #cliniko-form-steps',
    ]
  );

  $widget->add_responsive_control('questions_container_min_height', [
    'label' => 'Min Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh', '%'],
    'range' => [
      'px' => ['min' => 0, 'max' => 1200],
      'vh' => ['min' => 0, 'max' => 100],
      '%' => ['min' => 0, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'min-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('questions_container_max_height', [
    'label' => 'Max Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh', '%'],
    'range' => [
      'px' => ['min' => 0, 'max' => 1200],
      'vh' => ['min' => 0, 'max' => 100],
      '%' => ['min' => 0, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'max-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('questions_container_overflow', [
    'label' => 'Overflow',
    'type' => Controls_Manager::SELECT,
    'default' => '',
    'options' => [
      '' => 'Default',
      'visible' => 'Visible',
      'auto' => 'Scroll',
      'hidden' => 'Hidden',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'overflow-y: {{VALUE}};',
    ],
  ]);

  $widget->add_control('question_step_card_heading', [
    'label' => 'Inner Step Card',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('question_step_card_background', [
    'label' => 'Card Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('question_step_card_padding', [
    'label' => 'Card Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'question_step_card_border',
      'label' => 'Card Border',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step',
    ]
  );

  $widget->add_responsive_control('question_step_card_border_radius', [
    'label' => 'Card Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 80]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'question_step_card_box_shadow',
      'label' => 'Card Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step',
    ]
  );

  $widget->end_controls_section();

  /*
   * SECTION: Fields and base type
   */
  $widget->start_controls_section('form_fields_style_section', [
    'label' => 'Fields',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('fields_base_heading', [
    'label' => 'Base Text',
    'type' => Controls_Manager::HEADING,
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_global',
      'label' => 'Global Typography',
      'selector' => '{{WRAPPER}} #prepayment-form',
    ]
  );

  $widget->add_control('form_text_color', [
    'label' => 'Global Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('fields_inputs_heading', [
    'label' => 'Inputs',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('form_input_color', [
    'label' => 'Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#333333',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea,
       {{WRAPPER}} #prepayment-form select' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_input_background_color', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea,
       {{WRAPPER}} #prepayment-form select' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_input_border_color', [
    'label' => 'Border Color',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea,
       {{WRAPPER}} #prepayment-form select' => 'border-color: {{VALUE}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'inputs_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]), {{WRAPPER}} #prepayment-form textarea, {{WRAPPER}} #prepayment-form select',
    ]
  );

  $widget->add_responsive_control('form_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 50]],
    'default' => ['size' => 6, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea,
       {{WRAPPER}} #prepayment-form select' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_input_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea,
       {{WRAPPER}} #prepayment-form select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('fields_options_heading', [
    'label' => 'Checkboxes and Radios',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('form_input_radio_checkbox_color', [
    'label' => 'Selected Color',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input[type="radio"]:checked,
       {{WRAPPER}} #prepayment-form input[type="checkbox"]:checked' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form input[type="radio"],
       {{WRAPPER}} #prepayment-form input[type="checkbox"]' => 'border-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('options_group_gap', [
    'label' => 'Option Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 32],
      'em' => ['min' => 0, 'max' => 3],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group' => 'gap: {{SIZE}}{{UNIT}};',
      '{{WRAPPER}} #prepayment-form .inputGroup' => 'gap: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('option_cards_heading', [
    'label' => 'Option Cards',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('options_card_style', [
    'label' => 'Use Card Options',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'width: 100%; max-width: 100%; text-align: left; display: flex; align-items: center; justify-content: flex-start; gap: 12px; padding: 12px 14px; border-radius: 14px; border: 1px solid rgba(var(--btn-bg-rgb), .32); background: #ffffff; color: var(--btn-bg); transition: border-color 180ms ease, box-shadow 180ms ease, background-color 180ms ease, color 180ms ease;',
      '{{WRAPPER}} #prepayment-form .options-group label:hover, {{WRAPPER}} #prepayment-form .options-group label:has(input:checked)' => 'border-color: var(--btn-bg); background: var(--btn-bg); color: #ffffff; box-shadow: 0 0 0 2px rgba(var(--btn-bg-rgb), .18), 0 8px 18px rgba(var(--btn-bg-rgb), .22);',
      '{{WRAPPER}} #prepayment-form .options-group label:hover span, {{WRAPPER}} #prepayment-form .options-group label:has(input:checked) span' => 'color: #ffffff;',
    ],
  ]);

  $widget->add_control('options_hide_native_inputs', [
    'label' => 'Hide Radio / Checkbox',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'position: relative;',
      '{{WRAPPER}} #prepayment-form .options-group input[type="radio"], {{WRAPPER}} #prepayment-form .options-group input[type="checkbox"]' => 'position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none;',
    ],
    'condition' => [
      'options_card_style' => 'yes',
    ],
  ]);

  $widget->start_controls_tabs('options_card_state_tabs', [
    'condition' => [
      'options_card_style' => 'yes',
    ],
  ]);

  $widget->start_controls_tab('options_card_tab_normal', [
    'label' => 'Normal',
  ]);

  $widget->add_control('options_card_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_text_color', [
    'label' => 'Text',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label, {{WRAPPER}} #prepayment-form .options-group label span' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_border_color', [
    'label' => 'Border',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'border-color: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_tab();

  $widget->start_controls_tab('options_card_tab_hover', [
    'label' => 'Hover',
  ]);

  $widget->add_control('options_card_hover_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:hover' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_hover_text_color', [
    'label' => 'Text',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:hover, {{WRAPPER}} #prepayment-form .options-group label:hover span' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_hover_border_color', [
    'label' => 'Border',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:hover' => 'border-color: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_tab();

  $widget->start_controls_tab('options_card_tab_selected', [
    'label' => 'Selected',
  ]);

  $widget->add_control('options_card_selected_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:has(input:checked)' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_selected_text_color', [
    'label' => 'Text',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:has(input:checked), {{WRAPPER}} #prepayment-form .options-group label:has(input:checked) span' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('options_card_selected_border_color', [
    'label' => 'Border',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label:has(input:checked)' => 'border-color: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_tab();

  $widget->end_controls_tabs();

  $widget->add_responsive_control('options_card_padding', [
    'label' => 'Card Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
    'condition' => [
      'options_card_style' => 'yes',
    ],
  ]);

  $widget->add_responsive_control('options_card_radius', [
    'label' => 'Card Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 40]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .options-group label' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'options_card_style' => 'yes',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'options_card_box_shadow',
      'label' => 'Card Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .options-group label',
      'condition' => [
        'options_card_style' => 'yes',
      ],
    ]
  );

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'options_card_selected_box_shadow',
      'label' => 'Selected Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .options-group label:hover, {{WRAPPER}} #prepayment-form .options-group label:has(input:checked)',
      'condition' => [
        'options_card_style' => 'yes',
      ],
    ]
  );

  $widget->end_controls_section();

  /*
   * SECTION: Progress indicator
   */
  $widget->start_controls_section('form_progress_style_section', [
    'label' => 'Progress',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_responsive_control('progress_type', [
    'label' => 'Type',
    'type' => Controls_Manager::SELECT,
    'default' => 'bar',
    'options' => [
      'none' => 'None',
      'bar' => 'Linear Bar',
      'dots' => 'Dots',
      'steps' => 'Steps',
      'fraction' => 'Fraction',
      'percentage' => 'Percentage',
    ],
  ]);

  $widget->add_responsive_control('progress_label_mode', [
    'label' => 'Progress Text',
    'type' => Controls_Manager::SELECT,
    'default' => 'none',
    'options' => [
      'none' => 'None',
      'percentage' => 'Percentage',
      'fraction' => 'Fraction',
      'step_text' => 'Step Text',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_steps_show_labels', [
    'label' => 'Show Step Labels',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
    'condition' => [
      'progress_type' => 'steps',
    ],
  ]);

  $widget->add_control('progress_text_layout_heading', [
    'label' => 'Progress Text Layout',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_position', [
    'label' => 'Text Position',
    'type' => Controls_Manager::SELECT,
    'default' => 'below',
    'options' => [
      'above' => 'Above Progress',
      'below' => 'Below Progress',
      'left' => 'Left Of Progress',
      'right' => 'Right Of Progress',
      'overlay' => 'Inside / Overlay',
    ],
    'selectors_dictionary' => [
      'above' => 'display: flex; flex-direction: column; align-items: stretch; flex-wrap: nowrap; position: relative; --progress-text-order: 0; --progress-visual-order: 1; --progress-text-flex: 0 1 auto; --progress-text-width: 100%; --progress-text-position: static; --progress-text-inset: auto; --progress-text-z-index: auto; --progress-text-base-x: 0px; --progress-text-base-y: 0px;',
      'below' => 'display: flex; flex-direction: column; align-items: stretch; flex-wrap: nowrap; position: relative; --progress-visual-order: 0; --progress-text-order: 1; --progress-text-flex: 0 1 auto; --progress-text-width: 100%; --progress-text-position: static; --progress-text-inset: auto; --progress-text-z-index: auto; --progress-text-base-x: 0px; --progress-text-base-y: 0px;',
      'left' => 'display: flex; flex-direction: row; align-items: center; flex-wrap: nowrap; position: relative; --progress-text-order: 0; --progress-visual-order: 1; --progress-text-flex: 0 0 auto; --progress-text-width: auto; --progress-text-position: static; --progress-text-inset: auto; --progress-text-z-index: auto; --progress-text-base-x: 0px; --progress-text-base-y: 0px;',
      'right' => 'display: flex; flex-direction: row; align-items: center; flex-wrap: nowrap; position: relative; --progress-visual-order: 0; --progress-text-order: 1; --progress-text-flex: 0 0 auto; --progress-text-width: auto; --progress-text-position: static; --progress-text-inset: auto; --progress-text-z-index: auto; --progress-text-base-x: 0px; --progress-text-base-y: 0px;',
      'overlay' => 'display: flex; flex-direction: column; align-items: stretch; flex-wrap: nowrap; position: relative; --progress-visual-order: 0; --progress-text-order: 1; --progress-text-flex: 0 0 auto; --progress-text-width: auto; --progress-text-position: absolute; --progress-text-inset: 50% auto auto 50%; --progress-text-z-index: 2; --progress-text-base-x: -50%; --progress-text-base-y: -50%;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => '{{VALUE}}',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_gap', [
    'label' => 'Text Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 80],
      'em' => ['min' => 0, 'max' => 6],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => '--progress-content-gap: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_width', [
    'label' => 'Text Width',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => 40, 'max' => 600],
      '%' => ['min' => 10, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .progress-status-text,
       {{WRAPPER}} #prepayment-form .progress-text' => '--progress-text-width: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_align', [
    'label' => 'Text Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'left' => ['title' => 'Left', 'icon' => 'eicon-text-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-text-align-center'],
      'right' => ['title' => 'Right', 'icon' => 'eicon-text-align-right'],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .progress-status-text,
       {{WRAPPER}} #prepayment-form .progress-text' => 'text-align: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_font_size', [
    'label' => 'Text Size',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em', 'rem'],
    'range' => [
      'px' => ['min' => 8, 'max' => 72],
      'em' => ['min' => 0.5, 'max' => 5],
      'rem' => ['min' => 0.5, 'max' => 5],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .progress-status-text,
       {{WRAPPER}} #prepayment-form .progress-text' => 'font-size: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_offset_x', [
    'label' => 'Text Offset X',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => -200, 'max' => 200],
      '%' => ['min' => -100, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => '--progress-text-offset-x: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_text_offset_y', [
    'label' => 'Text Offset Y',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => -200, 'max' => 200],
      '%' => ['min' => -100, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => '--progress-text-offset-y: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_container_heading', [
    'label' => 'Progress Container',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_container_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'background-color: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_container_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'progress_container_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form #form-progress-indicator',
      'condition' => [
        'progress_type!' => 'none',
      ],
    ]
  );

  $widget->add_responsive_control('progress_container_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 60]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_position', [
    'label' => 'Position',
    'type' => Controls_Manager::SELECT,
    'default' => 'top',
    'options' => [
      'top' => 'Above Fields',
      'before_buttons' => 'Above Buttons',
      'bottom' => 'Below Buttons',
    ],
    'selectors_dictionary' => [
      'top' => '--progress-order: 0; --steps-order: 1; --actions-order: 2;',
      'before_buttons' => '--progress-order: 2; --steps-order: 1; --actions-order: 3;',
      'bottom' => '--progress-order: 3; --steps-order: 1; --actions-order: 2;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'display: flex; flex-direction: column; {{VALUE}}',
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'order: var(--progress-order, 0);',
      '{{WRAPPER}} #prepayment-form #cliniko-form-steps' => 'order: var(--steps-order, 1);',
      '{{WRAPPER}} #prepayment-form .form-actions' => 'order: var(--actions-order, 2);',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_alignment', [
    'label' => 'Alignment',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'stretch' => ['title' => 'Stretch', 'icon' => 'eicon-h-align-stretch'],
    ],
    'default' => 'stretch',
    'selectors_dictionary' => [
      'flex-start' => 'align-self: flex-start; justify-content: flex-start;',
      'center' => 'align-self: center; justify-content: center;',
      'flex-end' => 'align-self: flex-end; justify-content: flex-end;',
      'stretch' => 'align-self: stretch; justify-content: center;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => '{{VALUE}}',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_width', [
    'label' => 'Width',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => 120, 'max' => 1200],
      '%' => ['min' => 10, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'width: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_margin', [
    'label' => 'Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form #form-progress-indicator' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_colors_heading', [
    'label' => 'Colors',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_bar_color', [
    'label' => 'Active Color',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-progress--bar .progress-fill' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--dots .progress-dot.is-active' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--steps .progress-step.is-active' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--steps .form-progress__divider' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--fraction .progress-text,
       {{WRAPPER}} #prepayment-form .form-progress--percentage .progress-text' => 'color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress__divider' => '--progress-divider: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_track_color', [
    'label' => 'Track / Inactive Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-progress--bar .progress-track' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--dots .progress-dot' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress--steps .progress-step' => 'border-color: {{VALUE}}; color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .form-progress__divider' => '--progress-divider: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_meta_current_color', [
    'label' => 'Meta Current Text',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .progress-status-current,
       {{WRAPPER}} #prepayment-form .progress-status-text:not(.progress-status-text--split)' => 'color: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_meta_label_color', [
    'label' => 'Meta Label Text',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .progress-status-label' => 'color: {{VALUE}};',
    ],
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_control('progress_size_heading', [
    'label' => 'Size',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
    'condition' => [
      'progress_type!' => 'none',
    ],
  ]);

  $widget->add_responsive_control('progress_bar_height', [
    'label' => 'Bar Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 2, 'max' => 32]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-progress--bar .progress-track' => 'height: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type' => 'bar',
    ],
  ]);

  $widget->add_responsive_control('progress_dot_size', [
    'label' => 'Dot Size',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 6, 'max' => 32]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-progress--dots .progress-dot' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type' => 'dots',
    ],
  ]);

  $widget->add_responsive_control('progress_step_size', [
    'label' => 'Step Size',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 20, 'max' => 64]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-progress--steps .progress-step' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
    ],
    'condition' => [
      'progress_type' => 'steps',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'progress_text_typography',
      'label' => 'Text Typography',
      'selector' => '{{WRAPPER}} #prepayment-form .form-progress--fraction .progress-text, {{WRAPPER}} #prepayment-form .form-progress--percentage .progress-text, {{WRAPPER}} #prepayment-form .form-progress--steps .progress-step, {{WRAPPER}} #prepayment-form .progress-status-text',
      'condition' => [
        'progress_type!' => 'none',
      ],
    ]
  );

  $widget->end_controls_section();

  /*
   * SECTION: Text and titles
   */
  $widget->start_controls_section('form_typography_section', [
    'label' => 'Text & Titles',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('typo_heading_shell_intro', [
    'label' => 'Shell Intro',
    'type' => Controls_Manager::HEADING,
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_shell_intro_title',
      'label' => 'Intro Title',
      'selector' => '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro__title',
    ]
  );

  $widget->add_control('color_shell_intro_title', [
    'label' => 'Intro Title Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro__title' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_shell_intro_subtitle',
      'label' => 'Intro Subtitle',
      'selector' => '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro__subtitle',
    ]
  );

  $widget->add_control('color_shell_intro_subtitle', [
    'label' => 'Intro Subtitle Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .cliniko-form-shell-intro__subtitle' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('typo_heading_section_title', [
    'label' => 'Section Title',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('section_title_display', [
    'label' => 'Display',
    'type' => Controls_Manager::SELECT,
    'default' => 'block',
    'options' => [
      'block' => 'Show',
      'none' => 'Hide',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form h3.multi-form-title-color, {{WRAPPER}} #prepayment-form .form-step > h3' => 'display: {{VALUE}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_section_title',
      'selector' => '{{WRAPPER}} #prepayment-form h3.multi-form-title-color, {{WRAPPER}} #prepayment-form .form-step > h3',
    ]
  );

  $widget->add_responsive_control('color_section_title', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form h3.multi-form-title-color, {{WRAPPER}} #prepayment-form .form-step > h3' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('section_title_spacing', [
    'label' => 'Spacing',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 60],
      'em' => ['min' => 0, 'max' => 4],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step > h3' => 'margin-bottom: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('typo_heading_question', [
    'label' => 'Question Title',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_question_title',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step h4, {{WRAPPER}} #prepayment-form .question-title',
    ]
  );

  $widget->add_responsive_control('color_question_title', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step h4, {{WRAPPER}} #prepayment-form .question-title' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('typo_heading_labels', [
    'label' => 'Field Labels',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_field_labels',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step label, {{WRAPPER}} #prepayment-form .patient-grid label',
    ]
  );

  $widget->add_responsive_control('color_field_labels', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step label, {{WRAPPER}} #prepayment-form .patient-grid label' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('typo_heading_body', [
    'label' => 'Body Text',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_body',
      'selector' => '{{WRAPPER}} #prepayment-form p',
    ]
  );

  $widget->add_responsive_control('body_text_align', [
    'label' => 'Paragraph Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'left' => ['title' => 'Left', 'icon' => 'eicon-text-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-text-align-center'],
      'right' => ['title' => 'Right', 'icon' => 'eicon-text-align-right'],
      'justify' => ['title' => 'Justify', 'icon' => 'eicon-text-align-justify'],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form p' => 'text-align: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('color_body', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form p' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_section();

  /*
   * SECTION: Buttons
   */
  $widget->start_controls_section('form_button_style_section', [
    'label' => 'Buttons',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('button_style_heading', [
    'label' => 'Style',
    'type' => Controls_Manager::HEADING,
  ]);

  $widget->add_control('accent_color', [
    'label' => 'Primary Background',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .multi-form-button.prev-button' => 'border-color: {{VALUE}}; color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_button_text_color', [
    'label' => 'Primary Text',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_secondary_button_background', [
    'label' => 'Secondary Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.prev-button' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_button',
      'label' => 'Typography',
      'selector' => '{{WRAPPER}} #prepayment-form .multi-form-button',
    ]
  );

  $widget->add_responsive_control('form_button_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'default' => ['top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_button_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 40]],
    'default' => ['size' => 6, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'form_button_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .multi-form-button',
    ]
  );

  $widget->add_control('button_layout_heading', [
    'label' => 'Layout',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('buttons_show_disabled_prev', [
    'label' => 'Show Disabled Previous',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => '',
  ]);

  $widget->add_responsive_control('buttons_direction', [
    'label' => 'Direction',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'row' => ['title' => 'Row', 'icon' => 'eicon-navigation-horizontal'],
      'column' => ['title' => 'Stacked', 'icon' => 'eicon-navigation-vertical'],
    ],
    'default' => 'row',
    'selectors_dictionary' => [
      'row' => 'display: flex; flex-direction: row; flex-wrap: wrap;',
      'column' => 'display: flex; flex-direction: column; flex-wrap: wrap;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => '{{VALUE}}',
    ],
  ]);

  $widget->add_responsive_control('buttons_justify', [
    'label' => 'Alignment',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'space-between' => ['title' => 'Between', 'icon' => 'eicon-h-align-stretch'],
    ],
    'default' => 'center',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'justify-content: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_gap', [
    'label' => 'Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 60],
      'em' => ['min' => 0, 'max' => 4],
    ],
    'default' => ['size' => 8, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'gap: {{SIZE}}{{UNIT}}; --btn-gap: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_margin', [
    'label' => 'Actions Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('button_width_mode', [
    'label' => 'Button Width',
    'type' => Controls_Manager::SELECT,
    'default' => 'auto',
    'options' => [
      'auto' => 'Auto',
      'full' => 'Full',
      'half' => 'Half',
      'third' => 'Third',
    ],
    'selectors_dictionary' => [
      'auto' => 'flex: 0 1 auto; width: auto; box-sizing: border-box;',
      'full' => 'flex: 1 1 100%; width: 100%; box-sizing: border-box;',
      'half' => 'flex: 1 1 calc((100% - var(--btn-gap, 0px)) / 2); width: calc((100% - var(--btn-gap, 0px)) / 2); box-sizing: border-box;',
      'third' => 'flex: 1 1 calc((100% - (2 * var(--btn-gap, 0px))) / 3); width: calc((100% - (2 * var(--btn-gap, 0px))) / 3); box-sizing: border-box;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => '{{VALUE}}',
    ],
  ]);

  $widget->add_control('button_container_heading', [
    'label' => 'Actions Container',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('buttons_container_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'buttons_container_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form .form-actions',
    ]
  );

  $widget->add_responsive_control('buttons_container_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 60]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'buttons_container_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .form-actions',
    ]
  );

  $widget->add_responsive_control('buttons_container_width', [
    'label' => 'Container Width',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => 160, 'max' => 1200],
      '%' => ['min' => 10, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'width: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_alignment', [
    'label' => 'Container Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'stretch' => ['title' => 'Stretch', 'icon' => 'eicon-h-align-stretch'],
    ],
    'default' => 'stretch',
    'selectors_dictionary' => [
      'flex-start' => 'align-self: flex-start;',
      'center' => 'align-self: center;',
      'flex-end' => 'align-self: flex-end;',
      'stretch' => 'align-self: stretch;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => '{{VALUE}}',
    ],
  ]);

  $widget->add_control('button_disabled_heading', [
    'label' => 'Disabled State',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_responsive_control('button_disabled_opacity', [
    'label' => 'Opacity',
    'type' => Controls_Manager::NUMBER,
    'min' => 0.1,
    'max' => 1,
    'step' => 0.05,
    'default' => 0.45,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button:disabled' => 'opacity: {{VALUE}};',
    ],
  ]);

  $widget->add_control('button_icons_heading', [
    'label' => 'Icons',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);

  $widget->add_control('form_button_icon_prev', [
    'label' => 'Previous Icon',
    'type' => Controls_Manager::ICONS,
    'fa4compatibility' => 'icon',
    'default' => [
      'value' => 'fas fa-arrow-left',
      'library' => 'fa-solid',
    ],
  ]);

  $widget->add_control('form_button_icon_next', [
    'label' => 'Next Icon',
    'type' => Controls_Manager::ICONS,
    'fa4compatibility' => 'icon',
    'default' => [
      'value' => 'fas fa-arrow-right',
      'library' => 'fa-solid',
    ],
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
    'label' => 'Icon Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 32],
      'em' => ['min' => 0, 'max' => 3],
    ],
    'default' => ['size' => 8, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => 'gap: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->end_controls_section();

  /*
   * SECTION: Buttons container
   * Targets the wrapper around the navigation buttons.
   */
  $widget->start_controls_section('buttons_container_style_section', [
    'label' => 'Buttons Container',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('buttons_container_explicit_background', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_explicit_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_explicit_margin', [
    'label' => 'Margin',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'buttons_container_explicit_border',
      'label' => 'Border',
      'selector' => '{{WRAPPER}} #prepayment-form .form-actions',
    ]
  );

  $widget->add_responsive_control('buttons_container_explicit_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 80]],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_group_control(
    Group_Control_Box_Shadow::get_type(),
    [
      'name' => 'buttons_container_explicit_box_shadow',
      'label' => 'Shadow',
      'selector' => '{{WRAPPER}} #prepayment-form .form-actions',
    ]
  );

  $widget->add_responsive_control('buttons_container_explicit_min_height', [
    'label' => 'Min Height',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'vh'],
    'range' => [
      'px' => ['min' => 0, 'max' => 500],
      'vh' => ['min' => 0, 'max' => 50],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'min-height: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_explicit_width', [
    'label' => 'Width',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range' => [
      'px' => ['min' => 160, 'max' => 1200],
      '%' => ['min' => 10, 'max' => 100],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'width: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_explicit_alignment', [
    'label' => 'Container Align',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'stretch' => ['title' => 'Stretch', 'icon' => 'eicon-h-align-stretch'],
    ],
    'default' => 'stretch',
    'selectors_dictionary' => [
      'flex-start' => 'align-self: flex-start;',
      'center' => 'align-self: center;',
      'flex-end' => 'align-self: flex-end;',
      'stretch' => 'align-self: stretch;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => '{{VALUE}}',
    ],
  ]);

  $widget->add_responsive_control('buttons_container_explicit_content_alignment', [
    'label' => 'Button Alignment',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'flex-start' => ['title' => 'Left', 'icon' => 'eicon-h-align-left'],
      'center' => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
      'flex-end' => ['title' => 'Right', 'icon' => 'eicon-h-align-right'],
      'space-between' => ['title' => 'Between', 'icon' => 'eicon-h-align-stretch'],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-actions' => 'justify-content: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_section();
}
