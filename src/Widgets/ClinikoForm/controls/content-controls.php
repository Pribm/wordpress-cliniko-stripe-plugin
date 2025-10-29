<?php
if (!defined('ABSPATH'))
    exit;

use App\Model\PatientFormTemplate;
use App\Client\Cliniko\Client;
use App\Model\AppointmentType;
use Elementor\Controls_Manager;
use Elementor\Repeater;

function register_content_controls($widget)
{

     // ===============================
    // FORM CUSTOM
    // ===============================
    $widget->start_controls_section('section_content', [
        'label' => 'Custom Appointment Form',
        'tab' => Controls_Manager::TAB_CONTENT,
        'condition' => ['appointment_source' => 'custom_form'],
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
        'default' => 'var(--e-global-color-primary)',
        'condition' => ['show_back_button' => 'yes'],
    ]);

    $widget->add_control('back_button_bg', [
        'label' => 'Background on Hover',
        'type' => Controls_Manager::COLOR,
        'default' => 'var(--e-global-color-primary)',
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

    // ===============================
    // Email notifications (new)
    // ===============================
    $widget->add_control('send_email_on_success', [
        'label' => 'Send Email on Success',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'no',
        'separator' => 'before',
        'description' => 'If enabled, an email is sent after successful payment/creation.',
    ]);

    $widget->add_control('success_email_template', [
        'label' => 'Success Email Template',
        'type' => Controls_Manager::TEXTAREA,
        'rows' => 10,
        'default' =>
            '<p>Hi {first_name},</p>' .
            '<p>Thanks for your payment of {amount} {currency}. Your appointment is being scheduled.</p>' .
            '<p>Reference: {payment_reference}</p>' .
            '<p>We will send you the appointment details shortly.</p>' .
            '<p>— Support</p>',
        'description' => 'Enter raw HTML. Supported placeholders: {first_name}, {last_name}, {email}, {amount}, {currency}, {payment_reference}, {appointment_label}.',
        'condition' => [
            'send_email_on_success' => 'yes',
            'appointment_source' => 'custom_form',
        ],
    ]);

    $widget->add_control('send_email_on_failure', [
        'label' => 'Send Email on Failure',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'yes',
        'separator' => 'before',
        'description' => 'If enabled, an email is sent if scheduling fails and a refund is initiated.',
    ]);

    $widget->add_control('failure_email_template', [
        'label' => 'Failure Email Template',
        'type' => Controls_Manager::TEXTAREA,
        'rows' => 10,
        'default' =>
            '<p>Hi {first_name},</p>' .
            '<p>We were unable to schedule your appointment.</p>' .
            '<p>A refund of {amount} {currency} has been initiated.</p>' .
            '<p>Reference: {payment_reference}</p>' .
            '<p>— Support</p>',
        'description' => 'Enter raw HTML. Supported placeholders: {first_name}, {last_name}, {email}, {amount}, {currency}, {payment_reference}, {appointment_label}.',
        'condition' => [
            'send_email_on_failure' => 'yes',
            'appointment_source' => 'custom_form',
        ],
    ]);

    $widget->end_controls_section();
}

function get_cliniko_form_templates()
{
    $client = Client::getInstance();
    $patientFormTemplates = PatientFormTemplate::all($client);

    $templates = [];
    if (!empty($patientFormTemplates)) {
        foreach ($patientFormTemplates as $patientFormTemplate) {
            $templates[$patientFormTemplate->getId()] = $patientFormTemplate->getName();
        }
    }
    return $templates;
}

function register_cliniko_form_controls($widget)
{
    $templates = get_cliniko_form_templates();

    $widget->start_controls_section('cliniko_form_section', [
        'label' => 'Settings',
        'tab' => Controls_Manager::TAB_CONTENT,
    ]);

      $widget->add_control('appointment_source', [
        'label' => 'Appointment Source',
        'type' => Controls_Manager::CHOOSE,
        'options' => [
            'cliniko_embed' => [
                'title' => 'Cliniko Embed',
                'icon'  => 'eicon-globe',
            ],
            'custom_form' => [
                'title' => 'Custom Form',
                'icon'  => 'eicon-form-horizontal',
            ],
        ],
        'default' => 'custom_form',
        'toggle'  => false,
    ]);

    $widget->add_control('cliniko_form_template_id', [
        'label' => 'Select Form Template',
        'type' => Controls_Manager::SELECT,
        'options' => $templates,
        'default' => '',
        'description' => 'Choose a patient form template from Cliniko.',
    ]);

    // Appointment Types
    $module_options = ['' => 'Select an appointment type'];
    $client = Client::getInstance();
    $modules = AppointmentType::all($client);
    foreach ($modules as $mod) {
        $module_options[$mod->getId()] = $mod->getName() . ' (' . $mod->getDurationInMinutes() . ' min)';
    }

    $widget->add_control('module_id', [
        'label' => 'Appointment Type',
        'type' => Controls_Manager::SELECT,
        'options' => $module_options,
        'default' => '2',
        'description' => 'Select the appointment type for this booking iframe',
    ]);

    $widget->add_control('enable_payment', [
        'label' => 'Enable Payment Step',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'yes',
             'condition' => ['appointment_source' => 'custom_form'], // só aparece se for custom_form
    ]);

    $widget->add_control('save_on_exit', [
        'label'        => __('Save on Exit', 'plugin-name'),
        'type'         => Controls_Manager::SWITCHER,
        'label_on'     => __('Yes', 'plugin-name'),
        'label_off'    => __('No', 'plugin-name'),
        'return_value' => 'yes',
        'default'      => 'no',
    ]);

    $widget->end_controls_section();
}
?>
