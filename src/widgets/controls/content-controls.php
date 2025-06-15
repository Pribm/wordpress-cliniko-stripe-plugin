<?php
if (!defined('ABSPATH')) exit;
use Elementor\Controls_Manager;
use App\Config\ModuleConfig;

function register_content_controls($widget) {
  $widget->start_controls_section('section_content', [
    'label' => 'Payment Form',
    'tab' => Controls_Manager::TAB_CONTENT,
  ]);

  $module_options = ['' => 'Select an appointment type'];
  $modules = ModuleConfig::getModules();
  foreach ($modules as $id => $mod) {
    $module_options[$id] = $mod['name'] . ' (' . $mod['duration'] . ' min)';
  }

  $widget->add_control('module_id', [
    'label' => 'Appointment Type',
    'type' => Controls_Manager::SELECT,
    'options' => $module_options,
    'default' => '',
    'description' => 'Select the appointment type for this payment form'
  ]);

  $widget->add_control('button_label', [
    'label' => 'Button Label',
    'type' => Controls_Manager::TEXT,
    'default' => 'Pay Now',
  ]);

    $widget->add_control('has_pre_form', [
    'label' => 'Has Pre-Form?',
    'type' => Controls_Manager::SWITCHER,
    'label_on' => 'Yes',
    'label_off' => 'No',
    'return_value' => 'yes',
    'default' => 'No',
    ]);

    $widget->add_control('pre_form_selector', [
        'label' => 'Pre-Form Selector',
        'type' => Controls_Manager::TEXT,
        'default' => '#form form',
        'condition' => [
            'has_pre_form' => 'yes',
        ],
    ]);

  $widget->add_control('after_submit_js', [
    'label' => 'JS After Submit',
    'type' => Controls_Manager::TEXTAREA,
    'description' => 'JavaScript code to run after a successful payment (without <script> tags)'
  ]);

  $widget->end_controls_section();


  //FIELDS PRÉ FORM
    $widget->start_controls_section('section_pre_form_fields', [
    'label' => 'Pre Form Fields',
    'tab' => Controls_Manager::TAB_CONTENT,
    'condition' => [
            'has_pre_form' => 'yes',
        ]
    ]);
  $widget->add_control('is_elementor_form', [
  'label' => 'Is the pre-form from Elementor?',
  'type' => Controls_Manager::SWITCHER,
  'default' => 'yes',
]);

$widget->add_control('first_name_field', [
  'label' => 'First Name Field',
  'type' => Controls_Manager::TEXT,
  'default' => 'first_name',
      'condition' => [
            'is_elementor_form' => 'no',
        ]
]);

$widget->add_control('last_name_field', [
  'label' => 'Last Name Field',
  'type' => Controls_Manager::TEXT,
  'default' => 'last_name',
        'condition' => [
            'is_elementor_form' => 'no',
        ]
]);

$widget->add_control('email_field', [
  'label' => 'Email Field',
  'type' => Controls_Manager::TEXT,
  'default' => 'email',
        'condition' => [
            'is_elementor_form' => 'no',
        ]
]);
$widget->end_controls_section();

  // BACK BUTTON

  $widget->start_controls_section('section_back_button', [
  'label' => 'Back Button',
  'tab' => Controls_Manager::TAB_CONTENT,
]);


$widget->add_control('show_back_button', [
  'label' => 'Show Go Back Button',
  'type' => Controls_Manager::SWITCHER,
  'label_on' => 'Yes',
  'label_off' => 'No',
  'return_value' => 'yes',
  'default' => 'yes',
]);

$widget->add_control('back_button_align', [
  'label' => 'Alignment',
  'type' => Controls_Manager::CHOOSE,
  'options' => [
    'left' => ['title' => 'Left', 'icon' => 'eicon-text-align-left'],
    'center' => ['title' => 'Center', 'icon' => 'eicon-text-align-center'],
    'right' => ['title' => 'Right', 'icon' => 'eicon-text-align-right'],
  ],
  'default' => 'center',
  'toggle' => true,
  'condition' => ['show_back_button' => 'yes'],
]);

$widget->add_control('back_button_color', [
  'label' => 'Text Color',
  'type' => Controls_Manager::COLOR,
  'default' => '#005fcc',
  'condition' => ['show_back_button' => 'yes'],
]);

$widget->add_control('back_button_bg', [
  'label' => 'Background on Hover',
  'type' => Controls_Manager::COLOR,
  'default' => '#005fcc',
  'condition' => ['show_back_button' => 'yes'],
]);

$widget->add_control('back_button_hover_text', [
  'label' => 'Text Color on Hover',
  'type' => Controls_Manager::COLOR,
  'default' => '#ffffff',
  'condition' => ['show_back_button' => 'yes'],
]);

$widget->add_control('back_button_margin_top', [
  'label' => 'Top Margin',
  'type' => Controls_Manager::SLIDER,
  'size_units' => ['px'],
  'range' => ['px' => ['min' => 0, 'max' => 100]],
  'default' => ['size' => 30],
  'condition' => ['show_back_button' => 'yes'],
]);
$widget->end_controls_section();
}
