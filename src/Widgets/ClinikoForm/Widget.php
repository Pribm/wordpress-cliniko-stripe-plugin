<?php
namespace App\Widgets\ClinikoForm;

use App\Admin\Modules\Credentials;
use Elementor\Plugin;
if (!defined('ABSPATH'))
  exit;

use App\Exception\ApiException;
use App\Model\PatientFormTemplate;
use App\Model\AppointmentType;
use Elementor\Widget_Base;

class Widget extends Widget_Base
{
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
    wp_enqueue_style(
      'font-awesome-5',
      'https://use.fontawesome.com/releases/v5.15.4/css/all.css',
      [],
      null
    );

    $settings = $this->get_settings();

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
      null
    );

    try {
      $templateModel = PatientFormTemplate::find($form_template_id, cliniko_client(true), false);
      $sections = $templateModel ? $templateModel->getSections() : [];
    } catch (\Exception $e) {
      new ApiException($e->getMessage());
      $sections = [];
    }

    wp_enqueue_script(
      'helpers-js',
      plugin_dir_url(__FILE__) . 'assets/js/helpers.js',
      [],
      null,
      []
    );

    wp_enqueue_script(
      'form-handler-js',
      plugin_dir_url(__FILE__) . 'assets/js/form-handler.js',
      [],
      null,
      []
    );

    wp_enqueue_script(
      'save-on-exit',
      plugin_dir_url(__FILE__) . 'assets/js/save-on-exit.js',
      ["jquery"],
      null,
      []
    );

    if ($settings['enable_payment'] === 'yes') {

      // elementor control stores lowercase values; normalize for comparisons
      $gateway = isset($settings['custom_form_payment']) ? strtolower($settings['custom_form_payment']) : '';

      if ($gateway === 'stripe' || $settings['custom_form_payment'] === 'Stripe') {
      
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
          null,
          []
        );
      }

      if ($gateway === 'tyrohealth' || $settings['custom_form_payment'] === 'Tyrohealth') {
     
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
          null,
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
          // short-lived token endpoint (your server uses Business Admin key)
          'sdk_token_url' => get_site_url() . '/wp-json/v1/tyrohealth/sdk-token',
          'create_invoice_url' => get_site_url() . '/wp-json/v1/tyrohealth/invoice',
          // Tyro-specific charge endpoint (processes booking + scheduling)
          'confirm_booking_url' => get_site_url() . '/wp-json/v1/tyrohealth/charge',
          'moduleId' => esc_attr($settings['module_id'] ?? ''),
          'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect'] ?? ''),
          // optional: choose default THOP method in JS ("new-payment-card" or "mobile")
          'paymentMethod' => 'new-payment-card',
        ]);
      }
    }



    $template = isset($settings['booking_html_template']) ? ltrim($settings['booking_html_template']) : '';
    $field_mapping_array = $settings['field_mapping'] ?? [];


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

    wp_localize_script(
      'form-handler-js',
      'formHandlerData',
      [
        'sections' => $sections ?? [],
        'btn_bg' => esc_attr($settings['form_button_color'] ?? '#0073e6'),
        'btn_text' => esc_attr($settings['form_button_text_color'] ?? '#ffffff'),
        'btn_pad' => $btn_pad,
        'border_radius' => esc_attr($settings['form_border_radius']['size'] ?? 6) . 'px',
        'is_payment_enabled' => $settings['enable_payment'],
        'module_id' => esc_attr($settings['module_id']),
        'patient_form_template_id' => $form_template_id,
        // 'booking_url' => get_site_url() . '/wp-json/v1/book-cliniko',
        'payment_url' => get_site_url() . '/wp-json/v1/payments/charge',
        'cliniko_embeded_form_sync_patient_form_url' => get_site_url() . '/wp-json/v1/send-patient-form',
        'cliniko_embeded_host' => "https://" . Credentials::getEmbedHost(),
        'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect']),
        'appearance' => $appearance,
        'logo_url' => $logo_url,
        'cliniko_embed' => $settings['appointment_source'],
        // expose gateway selection for frontend handlers (keeps original casing if present)
        'custom_form_payment' => $settings['custom_form_payment'] ?? 'stripe',
      ]
    );

    wp_localize_script("save-on-exit", "saveOnExitData", [
      'save_on_exit' => $settings['save_on_exit'] === 'yes',
    ]);

    // ------------------------------------------------------------
    // Render multistep template always (this is your main form)
    // ------------------------------------------------------------
    require_once __DIR__ . '/templates/cliniko_multistep_form.phtml';


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
