<?php
if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

function register_cliniko_form_style_controls($widget)
{
  // SECTION: Form Container
  $widget->start_controls_section('form_style_section', [
    'label' => 'Form Container',
    'tab'   => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_background_color', [
    'label' => 'Background',
    'type'  => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'background-color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_text_color', [
    'label' => 'Text',
    'type'  => Controls_Manager::COLOR,
    'default' => '#000000',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('titles_color', [
    'label' => 'Headers (All H1–H6)',
    'type'  => Controls_Manager::COLOR,
    'default' => '#181818ff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form h1,
      {{WRAPPER}} #prepayment-form h2,
      {{WRAPPER}} #prepayment-form h3,
      {{WRAPPER}} #prepayment-form h4,
      {{WRAPPER}} #prepayment-form h5,
      {{WRAPPER}} #prepayment-form h6' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_label_color', [
    'label' => 'Labels (Global)',
    'type'  => Controls_Manager::COLOR,
    'default' => '#333333',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form label' => 'color: {{VALUE}};',
    ],
  ]);

  // Input text color
  $widget->add_control('form_input_color', [
    'label' => 'Input Text Color',
    'type'  => Controls_Manager::COLOR,
    'default' => '#333333',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea' => 'color: {{VALUE}};',
    ],
  ]);

  // Input border color (+ checked for radios/checkboxes)
  $widget->add_control('form_input_border_color', [
    'label' => 'Input Border Color',
    'type'  => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea' => 'border-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form input[type="radio"]:checked,
       {{WRAPPER}} #prepayment-form input[type="checkbox"]:checked' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
    ],
  ]);

  // Input border radius (RESPONSIVE) — exclude radios/checkboxes
  $widget->add_responsive_control('form_border_radius', [
    'label' => 'Input Border Radius',
    'type'  => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 50]],
    'default' => ['size' => 6],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input:not([type="radio"]):not([type="checkbox"]),
       {{WRAPPER}} #prepayment-form textarea' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  // Input border width (RESPONSIVE)
  $widget->add_responsive_control('form_input_border_width', [
    'label' => 'Input Border Width',
    'type'  => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 1],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form input,
       {{WRAPPER}} #prepayment-form textarea' => 'border-width: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('form_font_family', [
    'label' => 'Font Family (Global)',
    'type'  => Controls_Manager::TEXT,
    'default' => 'Arial, sans-serif',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form' => 'font-family: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_section();

  /**
   * SECTION: Text & Titles — precise, responsive controls per text group
   */
  $widget->start_controls_section('form_typography_section', [
    'label' => 'Text & Titles',
    'tab'   => Controls_Manager::TAB_STYLE,
  ]);

  // SECTION TITLES (H3 .multi-form-title-color)
  $widget->add_control('typo_heading_section_title', [
    'label' => 'Section Title (H3)',
    'type'  => Controls_Manager::HEADING,
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
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form h3.multi-form-title-color' => 'color: {{VALUE}};',
    ],
  ]);

  // QUESTION TITLES (H4 in steps)
  $widget->add_control('typo_heading_question', [
    'label' => 'Question Title (H4)',
    'type'  => Controls_Manager::HEADING,
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
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step h4' => 'color: {{VALUE}};',
    ],
  ]);

  // FIELD LABELS (including radios/checkboxes + patient grid)
  $widget->add_control('typo_heading_labels', [
    'label' => 'Field Labels',
    'type'  => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_field_labels',
      'selector' => '
        {{WRAPPER}} #prepayment-form .form-step label,
        {{WRAPPER}} #prepayment-form .patient-grid label
      ',
    ]
  );
  $widget->add_responsive_control('color_field_labels', [
    'label' => 'Color',
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .form-step label,
       {{WRAPPER}} #prepayment-form .patient-grid label' => 'color: {{VALUE}};',
    ],
  ]);

  // BODY TEXT / DESCRIPTIONS
  $widget->add_control('typo_heading_body', [
    'label' => 'Body Text (Paragraphs)',
    'type'  => Controls_Manager::HEADING,
    'separator' => 'before',
  ]);
  $widget->add_group_control(
    Group_Control_Typography::get_type(),
    [
      'name' => 'typo_body',
      'selector' => '{{WRAPPER}} #prepayment-form p',
    ]
  );
  // Paragraph alignment (RESPONSIVE)
  $widget->add_responsive_control('body_text_align', [
    'label' => 'Paragraph Align',
    'type'  => Controls_Manager::CHOOSE,
    'options' => [
      'left'   => [ 'title' => 'Left',   'icon' => 'eicon-text-align-left' ],
      'center' => [ 'title' => 'Center', 'icon' => 'eicon-text-align-center' ],
      'right'  => [ 'title' => 'Right',  'icon' => 'eicon-text-align-right' ],
      'justify'=> [ 'title' => 'Justify','icon' => 'eicon-text-align-justify' ],
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form p' => 'text-align: {{VALUE}};',
    ],
  ]);
  $widget->add_responsive_control('color_body', [
    'label' => 'Color',
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} #prepayment-form p' => 'color: {{VALUE}};',
    ],
  ]);

  $widget->end_controls_section();

  // SECTION: Buttons (style)
  $widget->start_controls_section('form_button_style_section', [
    'label' => 'Buttons',
    'tab'   => Controls_Manager::TAB_STYLE,
  ]);

  $widget->add_control('form_button_color', [
    'label' => 'Button Background',
    'type'  => Controls_Manager::COLOR,
    'default' => 'var(--e-global-color-primary)',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'background-color: {{VALUE}};',
      '{{WRAPPER}} #prepayment-form .multi-form-button.prev-button' => 'border-color: {{VALUE}}; color: {{VALUE}};',
    ],
  ]);

  $widget->add_control('form_button_text_color', [
    'label' => 'Button Text Color',
    'type'  => Controls_Manager::COLOR,
    'default' => '#ffffff',
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button.next-button' => 'color: {{VALUE}};',
    ],
  ]);

  // Button padding (RESPONSIVE)
  $widget->add_responsive_control('form_button_padding', [
    'label' => 'Button Padding',
    'type'  => Controls_Manager::DIMENSIONS,
    'size_units' => ['px', 'em', '%'],
    'default' => [
      'top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24, 'unit' => 'px',
    ],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' =>
        'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
    ],
  ]);

  // Button border radius (RESPONSIVE)
  $widget->add_responsive_control('form_button_border_radius', [
    'label' => 'Button Border Radius',
    'type'  => Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range' => ['px' => ['min' => 0, 'max' => 20]],
    'default' => ['size' => 6],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button' => 'border-radius: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->add_control('form_button_icon_prev', [
    'label' => 'Back Button Icon',
    'type'  => Controls_Manager::ICONS,
    'default' => ['value' => 'fas fa-arrow-left', 'library' => 'fa-solid'],
  ]);

  $widget->add_control('form_button_icon_next', [
    'label' => 'Next Button Icon',
    'type'  => Controls_Manager::ICONS,
    'default' => ['value' => 'fas fa-arrow-right', 'library' => 'fa-solid'],
  ]);

  $widget->add_control('form_button_icon_position', [
    'label' => 'Icon Position',
    'type'  => Controls_Manager::SELECT,
    'default' => 'before',
    'options' => [
      'before' => 'Before Text',
      'after'  => 'After Text',
    ],
  ]);

  // Icon spacing (RESPONSIVE)
  $widget->add_responsive_control('form_button_icon_spacing', [
    'label' => 'Icon Spacing',
    'type'  => Controls_Manager::SLIDER,
    'range' => ['px' => ['min' => 0, 'max' => 50]],
    'default' => ['size' => 8],
    'selectors' => [
      '{{WRAPPER}} #prepayment-form .multi-form-button i:first-child' => 'margin-right: {{SIZE}}{{UNIT}};',
      '{{WRAPPER}} #prepayment-form .multi-form-button i:last-child'  => 'margin-left: {{SIZE}}{{UNIT}};',
    ],
  ]);

  $widget->end_controls_section();

  // SECTION: Button Layout (RESPONSIVE, no overflow; wraps neatly)
$widget->start_controls_section('form_button_layout_section', [
  'label' => 'Button Layout',
  'tab'   => Controls_Manager::TAB_STYLE,
]);

// Direction (row / column) — force override of inline styles, allow wrapping
$widget->add_responsive_control('buttons_direction', [
  'label' => 'Direction',
  'type'  => Controls_Manager::CHOOSE,
  'options' => [
    'row'    => ['title' => 'Row',    'icon' => 'eicon-navigation-horizontal'],
    'column' => ['title' => 'Column', 'icon' => 'eicon-navigation-vertical'],
  ],
  'default' => 'row',
  'selectors_dictionary' => [
    'row'    => 'display:flex !important; flex-direction: row !important; flex-wrap: wrap !important;',
    'column' => 'display:flex !important; flex-direction: column !important; flex-wrap: wrap !important;',
  ],
  'selectors' => [
    '{{WRAPPER}} #prepayment-form > div:last-of-type' => '{{VALUE}}',
  ],
]);

// Horizontal alignment
$widget->add_responsive_control('buttons_justify', [
  'label' => 'Horizontal Align',
  'type'  => Controls_Manager::CHOOSE,
  'options' => [
    'flex-start'    => ['title' => 'Left',   'icon' => 'eicon-h-align-left'],
    'center'        => ['title' => 'Center', 'icon' => 'eicon-h-align-center'],
    'flex-end'      => ['title' => 'Right',  'icon' => 'eicon-h-align-right'],
    'space-between' => ['title' => 'Between','icon' => 'eicon-h-align-stretch'],
  ],
  'default' => 'center',
  'selectors' => [
    '{{WRAPPER}} #prepayment-form > div:last-of-type' => 'justify-content: {{VALUE}} !important;',
  ],
]);

// Gap — use flex gap + set a CSS variable for width calculations
$widget->add_responsive_control('buttons_gap', [
  'label' => 'Buttons Gap',
  'type'  => Controls_Manager::SLIDER,
  'size_units' => ['px','em'],
  'range' => [
    'px' => ['min' => 0, 'max' => 40],
    'em' => ['min' => 0, 'max' => 3],
  ],
  'default' => ['size' => 8, 'unit' => 'px'],
  'selectors' => [
    '{{WRAPPER}} #prepayment-form > div:last-of-type' => 'gap: {{SIZE}}{{UNIT}} !important; --btn-gap: {{SIZE}}{{UNIT}};',
  ],
]);

// Width presets — AUTO by default; half/third account for the gap
$widget->add_responsive_control('button_width_mode', [
  'label' => 'Button Width',
  'type'  => Controls_Manager::SELECT,
  'default' => 'auto',
  'options' => [
    'auto'  => 'Auto',
    'full'  => 'Full (100%)',
    'half'  => 'Half (50%)',
    'third' => 'Third (33%)',
  ],
  'selectors_dictionary' => [
    'auto'  => 'flex: 0 1 auto; width: auto; box-sizing: border-box;',
    'full'  => 'flex: 1 1 100%; width: 100%; box-sizing: border-box;',
    // two per row: each is (100% - gap)/2
    'half'  => 'flex: 1 1 calc((100% - var(--btn-gap, 0px)) / 2); width: calc((100% - var(--btn-gap, 0px)) / 2); box-sizing: border-box;',
    // three per row: each is (100% - 2*gap)/3
    'third' => 'flex: 1 1 calc((100% - (2 * var(--btn-gap, 0px))) / 3); width: calc((100% - (2 * var(--btn-gap, 0px))) / 3); box-sizing: border-box;',
  ],
  'selectors' => [
    '{{WRAPPER}} #prepayment-form .multi-form-button' => '{{VALUE}}',
  ],
]);

// Button text alignment (inside button)
$widget->add_responsive_control('button_text_align', [
  'label' => 'Button Text Align',
  'type'  => Controls_Manager::CHOOSE,
  'options' => [
    'left'   => ['title' => 'Left',   'icon' => 'eicon-text-align-left'],
    'center' => ['title' => 'Center', 'icon' => 'eicon-text-align-center'],
    'right'  => ['title' => 'Right',  'icon' => 'eicon-text-align-right'],
  ],
  'default' => 'center',
  'selectors' => [
    '{{WRAPPER}} #prepayment-form .multi-form-button' => 'text-align: {{VALUE}};',
  ],
]);

$widget->end_controls_section();

}
