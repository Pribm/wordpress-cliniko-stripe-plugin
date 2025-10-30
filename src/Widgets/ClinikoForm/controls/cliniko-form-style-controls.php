<?php
if (!defined('ABSPATH'))
  exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
// If you ever want a gradient/image: use Elementor\Group_Control_Background;

function register_cliniko_form_style_controls($widget)
{
  /**
   * SECTION: Container & Inputs (layout + base styles)
   */
  $widget->start_controls_section('form_style_section', [
    'label' => 'Form Container & Inputs',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  // Background
  $widget->add_control('form_background_color', [
    'label' => 'Background',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'background-color: {{VALUE}};',
    ],
  ]);

  // Global typography (also sets base font family/size/weight/line-height)
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_global',
      'label' => 'Global Typography',
      'selector' => '{{WRAPPER}} #prepayment-form',
    ]
  );

  // Global text color (fallback for anything not explicitly styled below)
  $widget->add_control('form_text_color', [
    'label' => 'Global Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#000000',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'color: {{VALUE}};',
    ],
  ]);

  // Input text color
  $widget->add_control('form_input_color', [
    'label' => 'Input Text',
    'type' => Controls_Manager::COLOR,
    'default' => '#333333',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_input_border_color', [
    'label' => 'Input Border',
    'type' => Controls_Manager::COLOR,
      'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea' => 'border-color: {{VALUE}};',
    ],
  ]);

  // Inputs border (color, style, width) — excludes radios/checkboxes
  $widget->add_group_control(
    Group_Control_Border::get_type(),
    [
      'name' => 'inputs_border',
      'label' => 'Inputs Border',
      'selector' => '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]), {{WRAPPER}} #prepayment-form textarea',
    ]
  );

  // Inputs radius
  $widget->add_responsive_control('form_border_radius', [
    'label' => 'Inputs Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 50]],
    'default' => ['size' => 6],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('progress_type', [
  'label'   => 'Progress Bar Type',
  'type'    => Controls_Manager::SELECT,
  'default' => 'bar',
  'options' => [
    'none'       => 'None',
    'bar'        => 'Linear Bar',
    'dots'       => 'Dots',
    'steps'      => 'Steps (labels)',
    'fraction'   => 'Fraction (3/10)',
    'percentage' => 'Percentage (30%)',
  ],
]);

$widget->add_control('progress_bar_color', [
  'label' => 'Progress Color',
  'type' => Controls_Manager::COLOR,
  'default' => 'var(--e-global-color-primary)',
  'selectors' => [
    // ✅ Progress bar fill
    '{{WRAPPER}} #prepayment-form .form-progress--bar .progress-fill' => 'background-color: {{VALUE}};',
    // ✅ Dots (active)
    '{{WRAPPER}} #prepayment-form .form-progress--dots .progress-dot.is-active' => 'background-color: {{VALUE}};',
    // ✅ Steps (divider active color)
    '{{WRAPPER}} #prepayment-form .form-progress--steps .form-progress__divider' => 'background-color: {{VALUE}};',
    // ✅ Fraction + Percentage text
    '{{WRAPPER}} #prepayment-form .form-progress--fraction .progress-text,
     {{WRAPPER}} #prepayment-form .form-progress--percentage .progress-text' => 'color: {{VALUE}};',
    // ✅ Divider accent when using var
    '{{WRAPPER}} #prepayment-form .form-progress__divider' => '--progress-divider: {{VALUE}};',
    // ✅ Inputs (radio/checkbox checked)
    '{{WRAPPER}} #prepayment-form input[type="radio"]:checked,
     {{WRAPPER}} #prepayment-form input[type="checkbox"]:checked' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
  ],
]);


  $widget->add_responsive_control('progress_margin', [
  'label' => 'Progress Margin',
  'type'  => Controls_Manager::DIMENSIONS,
  'size_units' => ['px', 'em', '%'],
  'selectors' => [
    '{{WRAPPER}} .form-progress' =>
      'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
  ],
]);

  $widget->end_controls_section();

  /**
   * SECTION: Text & Titles (typography + color together)
   */
  $widget->start_controls_section('form_typography_section', [
    'label' => 'Text & Titles',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

  // Section Title (H3)
  $widget->add_control('typo_heading_section_title', [
    'label' => 'Section Title (H3)',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_section_title',
      'selector' => '{{WRAPPER}} #prepayment-form h3.multi-form-title-color',
    ]
  );
  $widget->add_responsive_control('color_section_title', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form h3.multi-form-title-color' => 'color: {{VALUE}};',
    ],
  ]);

  // Question Title (H4)
  $widget->add_control('typo_heading_question', [
    'label' => 'Question Title (H4)',
    'type' => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_question_title',
      'selector' => '{{WRAPPER}} #prepayment-form .form-step h4',
    ]
  );
  $widget->add_responsive_control('color_question_title', [
    'label' => 'Color',
    'type' => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step h4' => 'color: {{VALUE}};',
    ],
  ]);

  // Field Labels (includes radios/checkboxes + patient grid)
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

  // Body Text (paragraphs)
  $widget->add_control('typo_heading_body', [
    'label' => 'Body Text (Paragraphs)',
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

  // SECTION: Buttons (style + layout incl. size & row/stack)
  $widget->start_controls_section('form_button_style_section', [
    'label' => 'Buttons',
    'tab' => Controls_Manager::TAB_STYLE,
  ]);

    $widget->add_control('accent_color', [
    'label' => 'Buttons Background Color',
    'type' => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      // Buttons (see button section for text color)
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .multi-form-button.prev-button' => 'border-color: {{VALUE}}; color: {{VALUE}};',
    ],
  ]);

  // Primary button text color (next); prev uses Accent for text/border
  $widget->add_control('form_button_text_color', [
    'label' => 'Buttons Text Color',
    'type' => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'color: {{VALUE}};',
    ],
  ]);

  // Button typography (covers font-size = part of "size")
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_button',
      'label' => 'Button Typography',
      'selector' => '{{WRAPPER}} #prepayment-form .multi-form-button',
    ]
  );

  // Padding = button “size” (height/width feel)
  $widget->add_responsive_control('form_button_padding', [
    'label' => 'Padding',
    'type' => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'default' => ['top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' =>
        'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('form_button_border_radius', [
    'label' => 'Border Radius',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  /**
   * Layout (stacked/row + width presets)
   */
  $widget->add_responsive_control('buttons_direction', [
    'label' => 'Direction',
    'type' => Controls_Manager::CHOOSE,
    'options' => [
      'row' => ['title' => 'Row', 'icon' => 'eicon-navigation-horizontal'],
      'column' => ['title' => 'Stacked', 'icon' => 'eicon-navigation-vertical'],
    ],
    'default' => 'row',
    'selectors_dictionary' => [
      'row' => 'display:flex; flex-direction: row; flex-wrap: wrap;',
      'column' => 'display:flex; flex-direction: column; flex-wrap: wrap;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form > div:last-of-type' => '{{VALUE}}',
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
      '{{WRAPPER}} #prepayment-form > div:last-of-type' => 'justify-content: {{VALUE}};',
    ],
  ]);

  $widget->add_responsive_control('buttons_gap', [
    'label' => 'Gap',
    'type' => Controls_Manager::SLIDER,
    'size_units' => ['px', 'em'],
    'range' => [
      'px' => ['min' => 0, 'max' => 40],
      'em' => ['min' => 0, 'max' => 3],
    ],
    'default' => ['size' => 8, 'unit' => 'px'],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form > div:last-of-type' => 'gap: {{SIZE}}{{UNIT}}; --btn-gap: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_responsive_control('button_width_mode', [
    'label' => 'Button Width',
    'type' => Controls_Manager::SELECT,
    'default' => 'auto',
    'options' => [
      'auto' => 'Auto',
      'full' => 'Full (100%)',
      'half' => 'Half (50%)',
      'third' => 'Third (33%)',
    ],
    'selectors_dictionary' => [
      'auto' => 'flex: 0 1 auto; width: auto; box-sizing: border-box;',
      'full' => 'flex: 1 1 100%; width: 100%; box-sizing: border-box;',
      // (100% - gap)/2 : two per row
      'half' => 'flex: 1 1 calc((100% - var(--btn-gap, 0px)) / 2); width: calc((100% - var(--btn-gap, 0px)) / 2); box-sizing: border-box;',
      // (100% - 2*gap)/3 : three per row
      'third' => 'flex: 1 1 calc((100% - (2 * var(--btn-gap, 0px))) / 3); width: calc((100% - (2 * var(--btn-gap, 0px))) / 3); box-sizing: border-box;',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => '{{VALUE}}',
    ],
  ]);

  $widget->end_controls_section();
}