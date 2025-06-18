<?php

if (!defined('ABSPATH'))
    exit;
use App\Client\ClinikoClient;
use App\Model\AppointmentType;
use Elementor\Controls_Manager;

function register_content_controls($widget)
{

    // SECTION: Payment Form (Main)
    $widget->start_controls_section('section_content', [
        'label' => 'Payment Form',
        'tab' => Controls_Manager::TAB_CONTENT,
    ]);

    // Appointment Types
    $module_options = ['' => 'Select an appointment type'];
    $client = ClinikoClient::getInstance();
    $modules = AppointmentType::all($client);
    foreach ($modules as $mod) {
        $module_options[$mod->getId()] = $mod->getName() . ' (' . $mod->getDurationInMinutes() . ' min)';
    }

    $widget->add_control('module_id', [
        'label' => 'Appointment Type',
        'type' => Controls_Manager::SELECT,
        'options' => $module_options,
        'default' => '2',
        'description' => 'Select the appointment type for this payment form'
    ]);

    // Payment Settings
    $widget->add_control('button_label', [
        'label' => 'Button Label',
        'type' => Controls_Manager::TEXT,
        'default' => 'Pay Now',
    ]);

    $widget->add_control('onpayment_success_redirect', [
        'label' => 'Redirect on Success',
        'type' => Controls_Manager::TEXT,
        'placeholder' => '/thank-you',
        'description' => 'Page URL to redirect after successful payment',
    ]);

    $widget->add_control('after_submit_js', [
        'label' => 'JS After Submit',
        'type' => Controls_Manager::TEXTAREA,
        'description' => 'JavaScript code to run after a successful payment (without <script> tags)',
    ]);

    // Pre-form Toggle
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

    $widget->add_control('expected_fields_notice', [
        'label' => 'Expected Pre-Form Fields',
        'type' => \Elementor\Controls_Manager::RAW_HTML,
        'raw' => '
      <strong>To ensure your pre-form works correctly, make sure your inputs have the following <code>name</code> attributes:</strong>
      <ul style="padding-left: 18px; margin-top: 10px;">
        <li><code>form_fields[first_name]</code> → First name</li>
        <li><code>form_fields[last_name]</code> → Last name</li>
        <li><code>form_fields[email_address]</code> → Email</li>
        <li><code>form_fields[phone_number]</code> → Phone</li>
        <li><code>form_fields[dob]</code> → Date of birth</li>
      </ul>
      <em>You can change the field names via JavaScript if needed, or follow these conventions directly in your Elementor form.</em>
    ',
        'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        'condition' => [
            'has_pre_form' => 'yes',
            'is_elementor_form' => 'yes'
        ],
    ]);

    $widget->end_controls_section();



    // SECTION: Pre-Form Fields
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
        ],
    ]);

    $widget->add_control('last_name_field', [
        'label' => 'Last Name Field',
        'type' => Controls_Manager::TEXT,
        'default' => 'last_name',
        'condition' => [
            'is_elementor_form' => 'no',
        ],
    ]);

    $widget->add_control('email_field', [
        'label' => 'Email Field',
        'type' => Controls_Manager::TEXT,
        'default' => 'email',
        'condition' => [
            'is_elementor_form' => 'no',
        ],
    ]);

    $widget->end_controls_section();

    $widget->start_controls_section(
        'section_template',
        [
            'label' => __('Booking HTML Template', 'plugin-name'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]
    );

    $widget->add_control(
        'booking_plaintext_notes',
        [
            'label' => 'Plain Text for Notes',
            'type' => Controls_Manager::SWITCHER,
            'label_on' => 'Yes',
            'label_off' => 'No',
            'return_value' => 'yes',
            'default' => 'yes',
            'description' => 'If enabled, the [all_fields] tag will be converted to plain text with line breaks for Cliniko notes.',
        ]
    );

    $widget->add_control(
        'booking_html_template',
        [
            'label' => 'Booking HTML Template',
            'type' => Controls_Manager::WYSIWYG,
            'default' => '<h2>Novo agendamento</h2><p><strong>Nome:</strong> [nome]</p><p><strong>Telefone:</strong> [telefone]</p><p>[all_fields]</p>',
            'description' => 'Use os shortcodes como [nome], [telefone] ou [all_fields] para incluir automaticamente os dados do formulário.',
        ]
    );

    $widget->end_controls_section();


    // SECTION: Back Button
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

