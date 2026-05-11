<?php
namespace App\Widgets\ClinikoForm;

use App\Admin\Modules\Credentials;
use Elementor\Plugin;
if (!defined('ABSPATH'))
  exit;

use App\Exception\ApiException;
use App\Model\PatientFormTemplate;
use App\Model\AppointmentType;
use App\Service\PatientAccessTokenService;
use App\Service\PatientCustomFieldService;
use App\Service\PublicRequestGuard;
use Elementor\Widget_Base;

class Widget extends Widget_Base
{
  private function readProp($source, string $key, $default = null)
  {
    if (is_array($source)) {
      return $source[$key] ?? $default;
    }
    if (is_object($source) && isset($source->{$key})) {
      return $source->{$key};
    }
    return $default;
  }

  private function build_patient_skeleton(array $customFieldDefaults = []): array
  {
    $skeleton = [
      'first_name' => '',
      'last_name' => '',
      'email' => '',
      'phone' => '',
      'medicare' => '',
      'medicare_reference_number' => '',
      'address_1' => '',
      'address_2' => '',
      'city' => '',
      'state' => '',
      'post_code' => '',
      'country' => '',
      'date_of_birth' => '',
      'appointment_start' => '',
      'practitioner_id' => '',
    ];

    foreach ($customFieldDefaults as $path => $default) {
      $this->set_nested_value($skeleton, (string) $path, $default);
    }

    return $skeleton;
  }

  private function get_core_patient_field_keys(): array
  {
    return [
      'first_name',
      'last_name',
      'email',
      'phone',
      'medicare',
      'medicare_reference_number',
      'address_1',
      'address_2',
      'city',
      'state',
      'post_code',
      'country',
      'date_of_birth',
      'appointment_start',
      'appointment_date',
      'practitioner_id',
      'patient_booked_time',
      'notes',
      'gender_identity',
      'preferred_first_name',
    ];
  }

  private function default_headless_field_value(string $type)
  {
    if (in_array($type, ['checkbox', 'switch', 'toggle'], true)) {
      return false;
    }

    return in_array($type, ['checkboxes', 'multi_checkbox', 'multi_select'], true) ? [] : '';
  }

  private function normalize_headless_field_segment(string $segment): string
  {
    $segment = strtolower(trim($segment));
    $segment = preg_replace('/[^a-z0-9_-]+/', '_', $segment) ?? '';
    $segment = preg_replace('/_+/', '_', $segment) ?? '';
    return trim($segment, '_-');
  }

  private function normalize_headless_field_path(string $path): string
  {
    $path = trim($path);
    if ($path === '') {
      return '';
    }

    $segments = array_filter(array_map(
      fn ($segment) => $this->normalize_headless_field_segment((string) $segment),
      explode('.', $path)
    ));

    $segments = array_values(array_filter($segments, static fn ($segment) => $segment !== ''));
    if (empty($segments)) {
      return '';
    }

    if ($segments[0] === 'patient') {
      array_shift($segments);
    }

    return implode('.', $segments);
  }

  private function set_nested_value(array &$target, string $path, $value): void
  {
    $normalizedPath = $this->normalize_headless_field_path($path);
    if ($normalizedPath === '') {
      return;
    }

    $segments = explode('.', $normalizedPath);
    $cursor =& $target;
    $lastIndex = count($segments) - 1;

    foreach ($segments as $index => $segment) {
      if ($index === $lastIndex) {
        $cursor[$segment] = $value;
        return;
      }

      if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
        $cursor[$segment] = [];
      }

      $cursor =& $cursor[$segment];
    }
  }

  private function build_headless_patient_field_registry(array $settings): array
  {
    $rawFields = $settings['headless_patient_fields'] ?? [];
    if (!is_array($rawFields) || empty($rawFields)) {
      return [];
    }

    $reserved = array_fill_keys($this->get_core_patient_field_keys(), true);
    $allowedTypes = [
      'text',
      'textarea',
      'email',
      'tel',
      'date',
      'number',
      'select',
      'radio',
      'checkbox',
      'checkboxes',
      'multi_checkbox',
      'multi_select',
      'hidden',
    ];
    $allowedValidationTypes = [
      'none',
      'regex',
      'email',
      'phone_au',
      'postcode_au',
      'medicare',
      'date_iso',
      'length',
      'number_range',
      'enum',
    ];
    $registry = [];
    $seenPaths = [];

    foreach ($rawFields as $rawField) {
      $fieldLabel = trim((string) $this->readProp($rawField, 'field_label', ''));
      $fieldType = strtolower(trim((string) $this->readProp($rawField, 'field_type', 'text')));

      if (!in_array($fieldType, $allowedTypes, true)) {
        $fieldType = 'text';
      }

      $advancedSettings = in_array(
        $this->readProp($rawField, 'advanced_settings', false),
        [true, 'true', 'yes', 1, '1'],
        true
      );

      $fieldKey = $this->normalize_headless_field_segment((string) $this->readProp($rawField, 'field_key', ''));
      if ($fieldKey === '') {
        $fieldKey = $this->normalize_headless_field_segment($fieldLabel);
      }

      if ($fieldKey === '') {
        continue;
      }

      $fieldPath = '';
      if ($advancedSettings) {
        $fieldPath = $this->normalize_headless_field_path((string) $this->readProp($rawField, 'field_path', ''));
      }

      if ($fieldPath === '') {
        $fieldPath = 'custom_fields.' . $fieldKey;
      }

      $fieldPath = $this->normalize_headless_field_path($fieldPath);
      if ($fieldPath === '') {
        continue;
      }

      if (isset($reserved[$fieldPath])) {
        continue;
      }

      if (isset($seenPaths[$fieldPath])) {
        continue;
      }

      $required = in_array($this->readProp($rawField, 'required', false), [true, 'true', 'yes', 1, '1'], true);
      $placeholder = trim((string) $this->readProp($rawField, 'placeholder', ''));
      $helpText = trim((string) $this->readProp($rawField, 'help_text', ''));

      $optionsRaw = trim((string) $this->readProp($rawField, 'field_options', ''));
      $options = [];
      if ($optionsRaw !== '') {
        $chunks = preg_split('/[\r\n,]+/', $optionsRaw) ?: [];
        foreach ($chunks as $chunk) {
          $chunk = trim((string) $chunk);
          if ($chunk === '') {
            continue;
          }
          $options[] = $chunk;
        }
      }

      $validationType = $advancedSettings
        ? strtolower(trim((string) $this->readProp($rawField, 'validation_type', 'none')))
        : 'none';
      if (!in_array($validationType, $allowedValidationTypes, true) || $validationType === 'none') {
        $validationType = PatientCustomFieldService::inferValidationType($fieldType, [
          'label' => $fieldLabel,
          'name' => $fieldLabel,
          'key' => $fieldKey,
          'path' => 'custom_fields.' . $fieldKey,
          'options' => $options,
        ]);
      }

      $pattern = $advancedSettings ? trim((string) $this->readProp($rawField, 'validation_pattern', '')) : '';
      $validationMessage = $advancedSettings ? trim((string) $this->readProp($rawField, 'validation_message', '')) : '';
      $clinikoSectionName = $advancedSettings ? trim((string) $this->readProp($rawField, 'cliniko_section_name', '')) : '';
      $clinikoSectionToken = $advancedSettings ? trim((string) $this->readProp($rawField, 'cliniko_section_token', '')) : '';
      $clinikoFieldName = $advancedSettings ? trim((string) $this->readProp($rawField, 'cliniko_field_name', '')) : '';
      if ($clinikoFieldName === '') {
        $clinikoFieldName = $fieldLabel !== '' ? $fieldLabel : $fieldKey;
      }
      $clinikoFieldToken = $advancedSettings ? trim((string) $this->readProp($rawField, 'cliniko_field_token', '')) : '';
      $clinikoFieldType = $advancedSettings
        ? strtolower(trim((string) $this->readProp($rawField, 'cliniko_field_type', $fieldType)))
        : $fieldType;
      if (!in_array($clinikoFieldType, $allowedTypes, true)) {
        $clinikoFieldType = $fieldType;
      }
      $defaultValue = $this->default_headless_field_value($fieldType);

      $validation = [
        'type' => $validationType,
      ];

      if ($pattern !== '') {
        $validation['pattern'] = $pattern;
      }
      if ($validationMessage !== '') {
        $validation['message'] = $validationMessage;
      }

      foreach (['min_length', 'max_length', 'min_value', 'max_value'] as $numericKey) {
        $numericValue = $this->readProp($rawField, $numericKey, null);
        if ($numericValue === null || $numericValue === '') {
          continue;
        }
        if (is_numeric($numericValue)) {
          $validation[$numericKey] = (float) $numericValue;
        }
      }

      if (!empty($options)) {
        $validation['options'] = $options;
      }

      $registry[] = [
        'key' => $fieldKey,
        'label' => $fieldLabel !== '' ? $fieldLabel : $fieldKey,
        'path' => $fieldPath,
        'type' => $fieldType,
        'required' => $required,
        'placeholder' => $placeholder,
        'help_text' => $helpText,
        'options' => $options,
        'default' => $defaultValue,
        'validation' => $validation,
        'advanced_settings' => $advancedSettings,
        'cliniko_section_name' => $clinikoSectionName,
        'cliniko_section_token' => $clinikoSectionToken,
        'cliniko_field_name' => $clinikoFieldName,
        'cliniko_field_token' => $clinikoFieldToken,
        'cliniko_field_type' => $clinikoFieldType,
      ];

      $seenPaths[$fieldPath] = true;
    }

    return $registry;
  }

  private function build_submission_template($sections, array $headlessPatientFields = []): array
  {
    $outputSections = [];
    if (!is_array($sections)) {
      $sections = [];
    }

    $customPatientDefaults = [];
    foreach ($headlessPatientFields as $field) {
      $path = (string) ($field['path'] ?? '');
      if ($path === '') {
        continue;
      }
      $customPatientDefaults[$path] = $field['default'] ?? '';
    }

    foreach ($sections as $section) {
      $sectionName = (string)($this->readProp($section, 'name', '') ?? '');
      $sectionDescription = (string)($this->readProp($section, 'description', '') ?? '');
      $questions = [];

      $rawQuestions = $this->readProp($section, 'questions', []);
      if (!is_array($rawQuestions)) {
        $rawQuestions = [];
      }

      foreach ($rawQuestions as $q) {
        $type = (string)($this->readProp($q, 'type', 'text') ?? 'text');
        if ($type === 'signature') {
          continue;
        }

        $question = [
          'name' => (string)($this->readProp($q, 'name', '') ?? ''),
          'type' => $type,
          'required' => (bool)$this->readProp($q, 'required', false),
        ];

        $rawAnswers = $this->readProp($q, 'answers', []);
        if (!is_array($rawAnswers)) {
          $rawAnswers = [];
        }

        $otherEnabled = false;
        $other = $this->readProp($q, 'other', null);
        if (is_object($other) && isset($other->enabled)) {
          $otherEnabled = (bool)$other->enabled;
        } elseif (is_array($other) && isset($other['enabled'])) {
          $otherEnabled = (bool)$other['enabled'];
        }

        if (in_array($type, ['checkboxes', 'radiobuttons'], true)) {
          $answers = [];
          foreach ($rawAnswers as $opt) {
            $value = null;
            if (is_array($opt)) {
              $value = $opt['value'] ?? null;
            } elseif (is_object($opt) && isset($opt->value)) {
              $value = $opt->value;
            }
            if ($value === null || $value === '') {
              continue;
            }
            $answers[] = ['value' => $value];
          }

          if (count($answers) === 0 && !$otherEnabled) {
            continue;
          }

          $question['answers'] = $answers;
          if ($otherEnabled) {
            $question['other'] = ['enabled' => true, 'selected' => false, 'value' => ''];
          }
        } else {
          $question['answer'] = '';
        }

        $questions[] = $question;
      }

      if (count($questions) === 0) {
        continue;
      }

      $outputSections[] = [
        'name' => $sectionName,
        'description' => $sectionDescription,
        'questions' => $questions,
      ];
    }

    return [
      'patient' => $this->build_patient_skeleton($customPatientDefaults),
      'headless_patient_fields' => $headlessPatientFields,
      'content' => [
        'sections' => $outputSections,
      ],
    ];
  }

  public function get_name()
  {
    return 'cliniko_stripe_payment';
  }

  public function get_title()
  {
    return 'Cliniko + Stripe Payment';
  }

  public function get_icon()
  {
    return 'eicon-lock-user';
  }

  public function get_categories()
  {
    return ['general'];
  }

  public function _register_controls()
  {

    require plugin_dir_path(__FILE__) . 'controls/index.php';
    register_cliniko_form_controls($this);
    register_content_controls($this);
    register_style_controls($this);
    register_details_style_controls($this);
    register_cliniko_form_style_controls($this);
  }

  public function render()
  {
    $asset_version = defined('WP_CLINIKO_PLUGIN_VERSION') ? WP_CLINIKO_PLUGIN_VERSION : null;

    wp_enqueue_style(
      'font-awesome-5',
      'https://use.fontawesome.com/releases/v5.15.4/css/all.css',
      [],
      null
    );

    $settings = $this->get_settings();
    $cliniko_cache_ttl = isset($settings['cliniko_cache_ttl']) ? (int)$settings['cliniko_cache_ttl'] : 21600;
    if ($cliniko_cache_ttl < 60) {
      $cliniko_cache_ttl = 60;
    } elseif ($cliniko_cache_ttl > 604800) {
      $cliniko_cache_ttl = 604800;
    }

    $form_type = isset($settings['form_type']) ? strtolower(trim($settings['form_type'])) : 'multi';
    $form_type = in_array($form_type, ['multi', 'single', 'unstyled', 'headless'], true) ? $form_type : 'multi';
    $is_headless = $form_type === 'headless';

    $cliniko_cache_refresh = ($settings['cliniko_cache_refresh'] ?? '') === 'yes';
    if ($cliniko_cache_refresh && current_user_can('manage_options') && empty($GLOBALS['cliniko_cache_busted'])) {
      global $wpdb;
      if (isset($wpdb) && $wpdb instanceof \wpdb) {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cliniko_api_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_cliniko_api_%'");
      }
      $GLOBALS['cliniko_cache_busted'] = true;
    }

    $is_editor = Plugin::$instance->editor->is_edit_mode();
    $form_template_id = $settings['cliniko_form_template_id'] ?? null;


    wp_enqueue_script(
      'loading-overlay',
      'https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.7/dist/loadingoverlay.min.js',
      ['jquery'],
      null,
      ["strategy" => "defer"]
    );

    wp_enqueue_style(
      'cliniko-stripe-style',
      plugin_dir_url(__FILE__) . 'assets/css/cliniko-stripe.css',
      [],
      $asset_version
    );

    $sections = [];
    $sections_loaded = false;
    try {
      $templateModel = PatientFormTemplate::find($form_template_id, cliniko_client(true, $cliniko_cache_ttl), false);
      $sections = $templateModel ? $templateModel->getSections() : [];
      $sections_loaded = true;
    } catch (\Exception $e) {
      new ApiException($e->getMessage());
      $sections = [];
      $sections_loaded = false;
    }

    wp_enqueue_script(
      'helpers-js',
      plugin_dir_url(__FILE__) . 'assets/js/helpers.js',
      [],
      $asset_version,
      []
    );

    wp_enqueue_script(
      'form-handler-js',
      plugin_dir_url(__FILE__) . 'assets/js/form-handler.js',
      [],
      $asset_version,
      []
    );

    $save_on_exit_enabled = ($settings['save_on_exit'] ?? '') === 'yes' && !$is_headless;
    if ($save_on_exit_enabled) {
      wp_enqueue_script(
        'save-on-exit',
        plugin_dir_url(__FILE__) . 'assets/js/save-on-exit.js',
        ['jquery', 'form-handler-js'],
        $asset_version,
        []
      );
    }

    $requestToken = PublicRequestGuard::issueRequestToken();
    $appointment_source = $settings['appointment_source'] ?? '';
    if ($appointment_source === 'custom_form' && $settings['enable_payment'] === 'yes') {

      // elementor control stores lowercase values; normalize for comparisons
      $gateway = isset($settings['custom_form_payment']) ? strtolower(trim($settings['custom_form_payment'])) : '';

      if ($gateway === 'stripe') {
        wp_enqueue_script(
          'stripe-js',
          'https://js.stripe.com/v3/',
          [],
          null,
          ['strategy' => 'async']
        );

        wp_enqueue_script(
          'cliniko-stripe-js',
          plugin_dir_url(__FILE__) . 'assets/js/stripe.js',
          ["jquery"],
          $asset_version,
          []
        );
      } elseif ($gateway === 'tyrohealth') {
        wp_enqueue_script(
          'medipass-transaction-sdk',
          'https://unpkg.com/@medipass/partner-sdk@1.10.1/umd/@medipass/partner-sdk.min.js',
          [],
          null,
          ['strategy' => 'defer']
        );

        wp_enqueue_script(
          'cliniko-tyrohealth-js',
          plugin_dir_url(__FILE__) . 'assets/js/tyrohealth.js',
          ['jquery', 'medipass-transaction-sdk'],
          $asset_version,
          ['strategy' => 'defer']
        );

        // Pull from your Credentials module (NO hardcoded api keys in JS)
        $tyroEnv = Credentials::getTyroEnv();                // stg|prod
        $tyroAppId = Credentials::getTyroAppId();            // required
        $tyroAppVersion = Credentials::getTyroAppVersion();  // required
        $tyroProviderNo = Credentials::getTyroProviderNumber(); // optional

        wp_localize_script('cliniko-tyrohealth-js', 'TyroHealthData', [
          'env' => $tyroEnv,
          'appId' => $tyroAppId,
          'appVersion' => $tyroAppVersion,
          'providerNumber' => Credentials::getTyroProviderNumber(),
          'request_token' => $requestToken,
          // short-lived token endpoint (your server uses Business Admin key)
          'sdk_token_url' => get_site_url() . '/wp-json/v1/tyrohealth/sdk-token',
          'create_invoice_url' => get_site_url() . '/wp-json/v1/tyrohealth/invoice',
          'attempt_preflight_url' => get_site_url() . '/wp-json/v2/booking-attempts/preflight',
          'attempt_confirm_url' => get_site_url() . '/wp-json/v2/booking-attempts/confirm-tyro',
          'attempt_finalize_url' => get_site_url() . '/wp-json/v2/booking-attempts/finalize',
          'moduleId' => esc_attr($settings['module_id'] ?? ''),
          'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect'] ?? ''),
          // optional: choose default THOP method in JS ("new-payment-card" or "mobile")
          'paymentMethod' => 'new-payment-card',
        ]);
      }
    }



    $template = isset($settings['booking_html_template']) ? ltrim($settings['booking_html_template']) : '';
    $field_mapping_array = $settings['field_mapping'] ?? [];
    $headlessPatientFields = $is_headless ? $this->build_headless_patient_field_registry($settings) : [];


    $appearance = [
      'theme' => $settings['theme'] ?? 'flat',
      'progress_type' => $settings['progress_type'],
      'variables' => [
        'colorPrimary' => esc_attr($settings['color_primary'] ?? '#0073e6'),
        'colorText' => esc_attr($settings['color_text'] ?? '#333'),
        'colorBackground' => esc_attr($settings['color_background'] ?? '#ffffff'),
        'borderRadius' => esc_attr($settings['border_radius']['size'] ?? 6) . 'px',
        'fontFamily' => esc_attr($settings['font_family'] ?? 'Arial, sans-serif'),
        'input_border' => esc_attr($settings['input_border'] ?? '1px solid #ccc')
      ],
      'rules' => [
        '.Input' => [
          'border' => esc_attr($settings['input_border'] ?? '1px solid #ccc'),
        ]
      ]
    ];

    $logo_url = has_site_icon() ? get_site_icon_url() : '';

    wp_localize_script('cliniko-stripe-js', 'ClinikoStripeData', [
      'stripe_pk' => get_option('wp_stripe_public_key'),
      'module_id' => esc_attr($settings['module_id']),
      'client_secret_url' => get_site_url() . '/wp-json/v1/client-secret',
    ]);

    $pad = $settings['form_button_padding'] ?? null;
    $btn_pad = isset($pad['top'], $pad['right'], $pad['bottom'], $pad['left'], $pad['unit']) ?
      "{$pad['top']}{$pad['unit']} {$pad['right']}{$pad['unit']} {$pad['bottom']}{$pad['unit']} {$pad['left']}{$pad['unit']}" :
      '12px 24px';

    $patientHistoryAccessEnabled =
      ($settings['appointment_source'] ?? '') === 'custom_form'
      && (($settings['enable_patient_history_access'] ?? '') === 'yes');
    $patientHistoryAccessPlacementMode = $settings['patient_history_access_placement_mode'] ?? 'first_question';
    $patientHistoryAccessAnchorQuestion = trim((string) ($settings['patient_history_access_anchor_question'] ?? ''));
    $patientHistoryAccessLimit = isset($settings['patient_history_access_limit'])
      ? (int) $settings['patient_history_access_limit']
      : 5;
    $patientHistoryAccessLimit = max(1, min(10, $patientHistoryAccessLimit));
    $patientHistoryReturnUrl = '';
    if (function_exists('get_permalink')) {
      $patientHistoryReturnUrl = (string) get_permalink();
    }
    if ($patientHistoryReturnUrl === '') {
      $patientHistoryReturnUrl = (string) get_site_url();
    }

    $submission_template = $this->build_submission_template($sections ?? [], $headlessPatientFields);
    $submission_template['moduleId'] = $settings['module_id'] ?? '';
    $submission_template['patient_form_template_id'] = $form_template_id ?? '';

    wp_localize_script(
      'form-handler-js',
      'formHandlerData',
      [
        'sections' => $sections ?? [],
        'submission_template' => $submission_template,
        'headless_patient_fields' => $headlessPatientFields,
        'btn_bg' => esc_attr($settings['form_button_color'] ?? '#0073e6'),
        'btn_text' => esc_attr($settings['form_button_text_color'] ?? '#ffffff'),
        'btn_pad' => $btn_pad,
        'border_radius' => esc_attr($settings['form_border_radius']['size'] ?? 6) . 'px',
        'is_payment_enabled' => $settings['enable_payment'],
        'module_id' => esc_attr($settings['module_id']),
        'patient_form_template_id' => $form_template_id,
        // 'booking_url' => get_site_url() . '/wp-json/v1/book-cliniko',
        'payment_url' => get_site_url() . '/wp-json/v1/payments/charge',
        'booking_attempt_preflight_url' => get_site_url() . '/wp-json/v2/booking-attempts/preflight',
        'booking_attempt_charge_stripe_url' => get_site_url() . '/wp-json/v2/booking-attempts/charge-stripe',
        'booking_attempt_confirm_tyro_url' => get_site_url() . '/wp-json/v2/booking-attempts/confirm-tyro',
        'booking_attempt_finalize_url' => get_site_url() . '/wp-json/v2/booking-attempts/finalize',
        'booking_attempt_status_url' => get_site_url() . '/wp-json/v2/booking-attempts/status',
        'request_token' => $requestToken,
        'available_times_url' => get_site_url() . '/wp-json/v1/available-times',
        'next_available_times_url' => get_site_url() . '/wp-json/v1/next-available-times',
        'practitioners_url' => get_site_url() . '/wp-json/v1/practitioners',
        'appointment_type_url' => get_site_url() . '/wp-json/v1/appointment-type',
        'patient_form_template_url' => get_site_url() . '/wp-json/v1/patient-form-template',
        'appointment_calendar_url' => get_site_url() . '/wp-json/v1/appointment-calendar',
        'available_times_per_page' => 100,
        'cliniko_embeded_form_sync_patient_form_url' => get_site_url() . '/wp-json/v1/send-patient-form',
        'cliniko_embeded_host' => "https://" . Credentials::getEmbedHost(),
        'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect']),
        'appearance' => $appearance,
        'logo_url' => $logo_url,
        'cliniko_embed' => $settings['appointment_source'],
        'form_type' => $form_type,
        'is_headless' => $is_headless,
        // expose gateway selection for frontend handlers (keeps original casing if present)
        'custom_form_payment' => $settings['custom_form_payment'] ?? 'stripe',
        'appointment_time_selection' => $settings['appointment_time_selection'] ?? 'calendar',
        'patient_history_access' => [
          'enabled' => $patientHistoryAccessEnabled,
          'position' => $patientHistoryAccessPlacementMode,
          'placement_mode' => $patientHistoryAccessPlacementMode,
          'anchor_question' => $patientHistoryAccessAnchorQuestion,
          'limit' => $patientHistoryAccessLimit,
          'return_url' => $patientHistoryReturnUrl,
          'request_url' => get_site_url() . '/wp-json/v2/patient-access/request',
          'request_status_url' => get_site_url() . '/wp-json/v2/patient-access/request-status',
          'request_complete_url' => get_site_url() . '/wp-json/v2/patient-access/request-complete',
          'latest_url' => get_site_url() . '/wp-json/v2/patient-access/latest',
          'verify_url' => get_site_url() . '/wp-json/v2/patient-access/verify',
          'appointments_url' => get_site_url() . '/wp-json/v2/patient-access/appointments',
          'prefill_url_template' => get_site_url() . '/wp-json/v2/patient-access/appointments/__BOOKING_ID__/prefill',
          'token_ttl' => (new PatientAccessTokenService())->ttl(),
          'challenge_ttl' => (new PatientAccessTokenService())->challengeTtl(),
          'query_key' => PatientAccessTokenService::QUERY_PARAM_KEY,
          'hash_key' => PatientAccessTokenService::HASH_FRAGMENT_KEY,
        ],
      ]
    );

    if ($save_on_exit_enabled) {
      wp_localize_script('save-on-exit', 'saveOnExitData', [
        'save_on_exit' => true,
      ]);
    }

    if ($is_headless && empty($sections)) {
      echo '<p style="color: red;">No sections found in the Cliniko form template.</p>';
      return;
    }

    // ------------------------------------------------------------
    // Render multistep template (main form) unless headless
    // ------------------------------------------------------------
    if ($is_headless) {
      $assets_url = plugin_dir_url(__FILE__) . 'assets/';
      $assets_path = plugin_dir_path(__FILE__) . 'assets/';
      $input_masks_ver = file_exists($assets_path . 'js/input-masks.js') ? filemtime($assets_path . 'js/input-masks.js') : null;

      if (empty($GLOBALS['cliniko_form_assets_printed'])) {
        $GLOBALS['cliniko_form_assets_printed'] = true;
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />';
        echo '<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>';
        echo '<script src="https://unpkg.com/imask"></script>';
        echo '<script src="' . esc_url($assets_url . 'js/input-masks.js' . ($input_masks_ver ? '?ver=' . $input_masks_ver : '')) . '"></script>';
      }

      echo '<div class="cliniko-form-headless" data-cliniko-headless="1" data-form-template-id="' . esc_attr($form_template_id ?? '') . '">';
      echo '<script type="application/json" class="cliniko-form-template-json">' . wp_json_encode($sections ?? []) . '</script>';
      echo '<script type="application/json" class="cliniko-form-headless-fields-json">' . wp_json_encode($headlessPatientFields) . '</script>';
      echo '<script type="application/json" class="cliniko-form-submission-template-json">' . wp_json_encode($submission_template) . '</script>';

      if ($is_editor) {
        echo '<p style="margin: 8px 0; color: #6b7280;">Headless mode: render your form UI with JS using formHandlerData.sections.</p>';
      }
      echo '</div>';
    } else {
      require_once __DIR__ . '/templates/cliniko_multistep_form.phtml';
    }


    //FINAL STEP
    if ($settings['appointment_source'] === "cliniko_embed")
      return;

    if ($is_editor) {
      require __DIR__ . '/templates/card_form_mock.phtml';
      return;
    } 
    
    $selectedGateway = isset($settings['custom_form_payment']) ? strtolower($settings['custom_form_payment']) : 'stripe';
    if ($settings['enable_payment'] === 'yes' && $selectedGateway === 'stripe') {
      require __DIR__ . '/templates/card_form_real.phtml';
    } else {
      require __DIR__ . '/templates/card_form_tyrohealth.phtml';
    }


  }
}
