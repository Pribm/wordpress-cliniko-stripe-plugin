<?php
if (!defined('ABSPATH'))
    exit;

use App\Model\PatientFormTemplate;
use App\Model\AppointmentType;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if (!function_exists('cliniko_headless_option_field_types')) {
    function cliniko_headless_option_field_types(): array
    {
        return ['select', 'radio', 'checkbox', 'checkboxes', 'multi_select'];
    }
}

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

    $widget->add_control('enable_patient_history_access', [
        'label' => 'Enable Returning Patient Access',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'no',
        'separator' => 'before',
        'condition' => ['appointment_source' => 'custom_form'],
        'description' => 'Shows a secure "retrieve my past appointments" entry point that emails the patient a short-lived access link.',
    ]);

    $widget->add_control('patient_history_access_placement_mode', [
        'label' => 'Returning Patient Access Placement',
        'type' => Controls_Manager::SELECT,
        'default' => 'first_question',
        'options' => [
            'first_question' => 'Before First Question',
            'before_specific_question' => 'Before Specific Question',
            'after_specific_question' => 'After Specific Question',
        ],
        'condition' => [
            'appointment_source' => 'custom_form',
            'enable_patient_history_access' => 'yes',
        ],
        'description' => 'Defaults to the first questionnaire block. Use a specific question anchor if you want to place it deeper in the form.',
    ]);

    $widget->add_control('patient_history_access_anchor_question', [
        'label' => 'Question Anchor',
        'type' => Controls_Manager::TEXT,
        'placeholder' => 'Section Name :: Question Name',
        'condition' => [
            'appointment_source' => 'custom_form',
            'enable_patient_history_access' => 'yes',
            'patient_history_access_placement_mode!' => 'first_question',
        ],
        'description' => 'Use the exact question label, or "Section Name :: Question Name" to disambiguate duplicates. If blank or unmatched, the CTA falls back to the first question.',
    ]);

    $widget->add_control('patient_history_access_limit', [
        'label' => 'Past Appointments Limit',
        'type' => Controls_Manager::NUMBER,
        'min' => 1,
        'max' => 10,
        'step' => 1,
        'default' => 5,
        'condition' => [
            'appointment_source' => 'custom_form',
            'enable_patient_history_access' => 'yes',
        ],
        'description' => 'Maximum number of past appointments to load from Cliniko for the secure retrieval panel.',
    ]);

    $widget->end_controls_section();

    // ===============================
    // Email notifications
    // ===============================
    $widget->start_controls_section('section_email_notifications', [
        'label' => 'Email Notifications',
        'tab' => Controls_Manager::TAB_CONTENT,
    ]);

    $widget->add_control('send_email_on_success', [
        'label' => 'Send Email on Success',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'no',
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
        ],
    ]);

    $widget->end_controls_section();
}

function get_cliniko_form_templates()
{
    $client = cliniko_client(true, 21600);
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
                'icon' => 'eicon-globe',
            ],
            'custom_form' => [
                'title' => 'Custom Form',
                'icon' => 'eicon-form-horizontal',
            ],
        ],
        'default' => 'custom_form',
        'toggle' => false,
    ]);

    $widget->add_control('custom_form_payment', [
        'label' => 'Payment Gateway',
        'type' => Controls_Manager::SELECT,
        'options' => [
            "Stripe" => "stripe",
            "Tyrohealth" => "tyrohealth"
        ],
        'default' => 'stripe',
        'condition' => ['appointment_source' => 'custom_form'],
    ]);

    $widget->add_control('appointment_time_selection', [
        'label' => 'Appointment Scheduling',
        'type' => Controls_Manager::SELECT,
        'options' => [
            'next_available' => 'Next Available Time',
            'calendar' => 'Choose From Calendar',
        ],
        'default' => 'calendar',
        'condition' => ['appointment_source' => 'custom_form'],
    ]);

    $widget->add_control('form_type', [
        'label' => 'Form Type',
        'type' => Controls_Manager::SELECT,
        'default' => 'multi',
        'options' => [
            'multi' => 'Multi-step (current)',
            'single' => 'Single-step (all fields)',
            'unstyled' => 'Unstyled (no theme CSS)',
            'headless' => 'Headless (JSON only)',
        ],
    ]);

    $widget->add_control('cliniko_cache_ttl', [
        'label' => 'Cliniko Cache TTL (seconds)',
        'type' => Controls_Manager::NUMBER,
        'min' => 60,
        'max' => 604800, // 7 days
        'step' => 60,
        'default' => 21600, // 6 hours
        'description' => 'How long to cache Cliniko templates/appointment data for this widget.',
    ]);

    $widget->add_control('cliniko_cache_refresh', [
        'label' => 'Refresh Cliniko Cache',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'no',
        'description' => 'Admin-only: forces a cache refresh on next render. Turn off after refreshing.',
    ]);

    $widget->add_control('unstyled_css_help', [
        'type' => Controls_Manager::RAW_HTML,
        'raw' =>
            '<strong>Unstyled CSS hooks</strong><br>' .
            'Unstyled disables the default theme CSS and shows the form as a single step (all fields visible).<br><br>' .
            '<strong>Primary wrappers</strong><br>' .
            '<code>.cliniko-form--unstyled</code> (form root), <code>#prepayment-form</code> (main container)<br><br>' .
            '<strong>Sections & questions</strong><br>' .
            '<code>.form-step</code> (each section/patient/embed block), <code>.inputGroup</code> (question block), ' .
            '<code>.question-title</code>, <code>.req</code>, <code>.options-group</code>, <code>.other-input-wrap</code><br><br>' .
            '<strong>Patient grid</strong><br>' .
            '<code>.patient-grid</code>, <code>.field-col</code>, <code>.field-label</code>, <code>.col-span-*</code><br><br>' .
            '<strong>Actions & errors</strong><br>' .
            '<code>.form-actions</code>, <code>.multi-form-button</code>, <code>.prev-button</code>, <code>.next-button</code>, ' .
            '<code>.field-error</code><br><br>' .
            '<em>Tip:</em> You can scope all custom styles to <code>.cliniko-form--unstyled</code> to avoid affecting other forms.',
        'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        'condition' => ['form_type' => 'unstyled'],
    ]);

    $widget->add_control('headless_help', [
        'type' => Controls_Manager::RAW_HTML,
        'raw' =>
            '<strong>Headless mode</strong><br>' .
            'No form UI is rendered. The Cliniko template is exposed via <code>formHandlerData.sections</code> so you can build your own UI.<br><br>' .
            '<strong>Custom patient fields</strong><br>' .
            'Register only the field label, type, and whether it is required. The widget will infer the local path, validation, and Cliniko field mapping from the label by default. Open <strong>Advanced Settings</strong> only when you need to override the inferred path, Cliniko mapping, or validation rules.<br><br>' .
            '<strong>Submitting</strong><br>' .
            'Provide a payload with <code>patient</code> and <code>content</code> and expose it as <code>window.clinikoHeadlessPayload</code> or <code>window.clinikoGetHeadlessPayload()</code>.<br>' .
            'The payment step will call <code>submitBookingForm(...)</code> and use that payload automatically. Headless submissions will fail fast if a registered custom field is invalid.<br><br>' .
            '<strong>Submission template</strong><br>' .
            'A backend-ready skeleton is available at <code>formHandlerData.submission_template</code> or in the <code>.cliniko-form-submission-template-json</code> script tag. The custom field registry is exposed at <code>formHandlerData.headless_patient_fields</code>.<br><br>' .
            '<strong>Headless calendar helpers</strong><br>' .
            'Use <code>window.ClinikoHeadlessCalendar</code> to call the same endpoints used by the standard form (practitioners, calendar, available times), plus fresh next-available lookups per practitioner. It also exposes helpers to update and validate headless patient fields.<br><br>' .
            '<strong>Payment UI</strong><br>' .
            'Payment markup is still rendered (hidden by default). Show <code>#payment_form</code> when you’re ready to collect payment.',
        'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        'condition' => ['form_type' => 'headless'],
    ]);

    $headlessPatientFieldsRepeater = new Repeater();
    $headlessPatientFieldsRepeater->add_control('field_label', [
        'label' => 'Field Label',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'Cliniko custom field label',
    ]);
    $headlessPatientFieldsRepeater->add_control('field_type', [
        'label' => 'Field Type',
        'type' => Controls_Manager::SELECT,
        'default' => 'text',
        'options' => [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'email' => 'Email',
            'tel' => 'Phone',
            'date' => 'Date',
            'number' => 'Number',
            'select' => 'Select',
            'radio' => 'Radio',
            'checkbox' => 'Checkbox',
            'checkboxes' => 'Checkboxes',
            'multi_select' => 'Multi select',
            'hidden' => 'Hidden',
        ],
        'description' => 'Validation and Cliniko mapping are inferred from the label and type by default.',
    ]);
    $headlessPatientFieldsRepeater->add_control('required', [
        'label' => 'Required',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => '',
    ]);
    $headlessPatientFieldsRepeater->add_control('field_options', [
        'label' => 'Field Options',
        'type' => Controls_Manager::TEXTAREA,
        'rows' => 4,
        'placeholder' => "Option A\nOption B\nOption C",
        'description' => 'Shown only for select, radio, checkbox, checkboxes, and multi select fields.',
        'condition' => [
            'field_type' => cliniko_headless_option_field_types(),
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('placeholder', [
        'label' => 'Placeholder',
        'type' => Controls_Manager::TEXT,
        'default' => '',
    ]);
    $headlessPatientFieldsRepeater->add_control('help_text', [
        'label' => 'Help Text',
        'type' => Controls_Manager::TEXTAREA,
        'rows' => 3,
        'default' => '',
    ]);
    $headlessPatientFieldsRepeater->add_control('advanced_settings', [
        'label' => 'Advanced Settings',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => '',
        'separator' => 'before',
        'description' => 'Show manual path, Cliniko mapping, and validation overrides.',
    ]);
    $headlessPatientFieldsRepeater->add_control('field_path', [
        'label' => 'Field Path',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'custom_fields.your_key',
        'description' => 'Optional. Leave blank to derive the path from the label.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('cliniko_section_name', [
        'label' => 'Cliniko Section Name',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'Private health',
        'description' => 'Optional. Leave blank to let the backend search all Cliniko sections for a matching field name.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('cliniko_field_name', [
        'label' => 'Cliniko Field Name',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'Allergies',
        'description' => 'Optional. Leave blank to use the field label.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('cliniko_section_token', [
        'label' => 'Cliniko Section Token',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'section token',
        'description' => 'Optional manual override. Usually safe to leave blank so the backend can resolve it from Cliniko settings.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('cliniko_field_token', [
        'label' => 'Cliniko Field Token',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'placeholder' => 'field token',
        'description' => 'Optional manual override. Leave blank to auto-resolve from Cliniko settings when the section and field names match.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('cliniko_field_type', [
        'label' => 'Cliniko Field Type',
        'type' => Controls_Manager::SELECT,
        'default' => 'text',
        'options' => [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'email' => 'Email',
            'tel' => 'Phone',
            'date' => 'Date',
            'number' => 'Number',
            'select' => 'Select',
            'radio' => 'Radio',
            'checkbox' => 'Checkbox',
            'checkboxes' => 'Checkboxes',
            'multi_select' => 'Multi select',
            'hidden' => 'Hidden',
        ],
        'description' => 'Optional. Defaults to the local field type.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('validation_type', [
        'label' => 'Validation Override',
        'type' => Controls_Manager::SELECT,
        'default' => 'none',
        'options' => [
            'none' => 'None',
            'regex' => 'Regex',
            'email' => 'Email',
            'phone_au' => 'Australian phone',
            'postcode_au' => 'Australian postcode',
            'medicare' => 'Medicare number',
            'date_iso' => 'ISO date',
            'length' => 'Length',
            'number_range' => 'Number range',
            'enum' => 'Allowed values',
        ],
        'condition' => ['advanced_settings' => 'yes'],
    ]);
    $headlessPatientFieldsRepeater->add_control('validation_pattern', [
        'label' => 'Regex Pattern',
        'type' => Controls_Manager::TEXT,
        'placeholder' => '^\\\\d{4}$',
        'condition' => [
            'advanced_settings' => 'yes',
            'validation_type' => 'regex',
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('min_length', [
        'label' => 'Min Length',
        'type' => Controls_Manager::NUMBER,
        'step' => 1,
        'condition' => [
            'advanced_settings' => 'yes',
            'validation_type' => 'length',
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('max_length', [
        'label' => 'Max Length',
        'type' => Controls_Manager::NUMBER,
        'step' => 1,
        'condition' => [
            'advanced_settings' => 'yes',
            'validation_type' => 'length',
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('min_value', [
        'label' => 'Min Value',
        'type' => Controls_Manager::NUMBER,
        'step' => 0.01,
        'condition' => [
            'advanced_settings' => 'yes',
            'validation_type' => 'number_range',
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('max_value', [
        'label' => 'Max Value',
        'type' => Controls_Manager::NUMBER,
        'step' => 0.01,
        'condition' => [
            'advanced_settings' => 'yes',
            'validation_type' => 'number_range',
        ],
    ]);
    $headlessPatientFieldsRepeater->add_control('validation_message', [
        'label' => 'Validation Message',
        'type' => Controls_Manager::TEXT,
        'default' => '',
        'description' => 'Optional custom error message. If blank, a default message is used.',
        'condition' => ['advanced_settings' => 'yes'],
    ]);

    $widget->add_control('headless_patient_fields', [
        'label' => 'Headless Patient Fields',
        'type' => Controls_Manager::REPEATER,
        'condition' => ['form_type' => 'headless'],
        'title_field' => '{{{ field_label }}}',
        'fields' => $headlessPatientFieldsRepeater->get_controls(),
        'default' => [],
        'description' => 'Headless-only patient fields that are added to the payload, merged into the submission template, and validated before submit.',
    ]);

    $widget->add_control(
        'hr',
        [
            'type' => Controls_Manager::DIVIDER,
        ]
    );

    $widget->add_control('cliniko_form_template_id', [
        'label' => 'Select Form Template',
        'type' => Controls_Manager::SELECT,
        'options' => $templates,
        'default' => '',
        'description' => 'Choose a patient form template from Cliniko.',
    ]);

    // Appointment Types
    $module_options = ['' => 'Select an appointment type'];
    $client = cliniko_client(true, 21600);
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

    $widget->add_control('onpayment_success_redirect', [
        'label' => 'Redirect on Success',
        'type' => Controls_Manager::TEXT,
        'placeholder' => '/thank-you',
        'description' => 'Page URL to redirect after successful payment',
    ]);

    $widget->add_control('enable_payment', [
        'label' => 'Enable Payment Step',
        'type' => Controls_Manager::SWITCHER,
        'label_on' => 'Yes',
        'label_off' => 'No',
        'return_value' => 'yes',
        'default' => 'yes',
        'condition' => ['appointment_source' => 'custom_form'],
    ]);

    $widget->add_control('save_on_exit', [
        'label' => __('Save on Exit', 'plugin-name'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => __('Yes', 'plugin-name'),
        'label_off' => __('No', 'plugin-name'),
        'return_value' => 'yes',
        'default' => 'no',
    ]);

    $widget->end_controls_section();
}
?>
