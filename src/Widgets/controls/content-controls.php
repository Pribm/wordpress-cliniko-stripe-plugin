<?php
if (!defined('ABSPATH'))
    exit;
use App\Model\PatientFormTemplate;
use App\Client\ClinikoClient;
use App\Model\AppointmentType;
use Elementor\Controls_Manager;
use Elementor\Repeater;

function register_content_controls($widget)
{

    $widget->start_controls_section('section_content', [
        'label' => 'Payment Form',
        'tab' => Controls_Manager::TAB_CONTENT,
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

function get_cliniko_form_templates()
{
    $client = ClinikoClient::getInstance();
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
        'label' => 'Cliniko Form Integration',
        'tab' => Controls_Manager::TAB_CONTENT,
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

    // Control: Enable Multistep Form
    $widget->add_control('enable_multistep', [
        'label' => 'Enable Multistep Form',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'no',
    ]);

    $widget->end_controls_section();
}
?>