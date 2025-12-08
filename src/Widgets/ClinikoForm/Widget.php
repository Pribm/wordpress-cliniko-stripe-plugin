<?php
namespace App\Widgets\ClinikoForm;

use App\Admin\Modules\Credentials;
use Elementor\Plugin;
if (!defined('ABSPATH'))
  exit;

use App\Exception\ApiException;
use App\Model\PatientFormTemplate;
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

  $appointment_source = $settings['appointment_source'] ?? 'custom_form'; // cliniko_embed | custom_form
  $gateway = $settings['custom_form_payment'] ?? 'stripe';                // stripe | tyrohealth
  $payment_enabled = ($settings['enable_payment'] ?? 'no') === 'yes';

  wp_enqueue_script(
    'loading-overlay',
    'https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.7/dist/loadingoverlay.min.js',
    ['jquery'],
    null,
    ['strategy' => 'defer']
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
    ['jquery'],
    null,
    []
  );

  $appearance = [
    'theme' => $settings['theme'] ?? 'flat',
    'progress_type' => $settings['progress_type'] ?? null,
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

  $pad = $settings['form_button_padding'] ?? null;
  $btn_pad = isset($pad['top'], $pad['right'], $pad['bottom'], $pad['left'], $pad['unit'])
    ? "{$pad['top']}{$pad['unit']} {$pad['right']}{$pad['unit']} {$pad['bottom']}{$pad['unit']} {$pad['left']}{$pad['unit']}"
    : '12px 24px';

  wp_localize_script(
    'form-handler-js',
    'formHandlerData',
    [
      'sections' => $sections ?? [],
      'btn_bg' => esc_attr($settings['form_button_color'] ?? '#0073e6'),
      'btn_text' => esc_attr($settings['form_button_text_color'] ?? '#ffffff'),
      'btn_pad' => $btn_pad,
      'border_radius' => esc_attr($settings['form_border_radius']['size'] ?? 6) . 'px',
      'is_payment_enabled' => $payment_enabled ? 'yes' : 'no',
      'module_id' => esc_attr($settings['module_id'] ?? ''),
      'patient_form_template_id' => $form_template_id,
      'payment_url' => get_site_url() . '/wp-json/v1/payments/charge',
      'cliniko_embeded_form_sync_patient_form_url' => get_site_url() . '/wp-json/v1/send-patient-form',
      'cliniko_embeded_host' => "https://" . Credentials::getEmbedHost(),
      'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect'] ?? ''),
      'appearance' => $appearance,
      'logo_url' => $logo_url,
      'appointment_source' => $appointment_source,
      'custom_form_payment' => $gateway,
    ]
  );

  wp_localize_script("save-on-exit", "saveOnExitData", [
    'save_on_exit' => ($settings['save_on_exit'] ?? 'no') === 'yes',
  ]);

  // ------- Gateway scripts (ONLY for custom_form + enabled) -------
  if ($appointment_source === 'custom_form' && $payment_enabled) {
   
    if ($gateway === 'Stripe') {
      echo "loading stripe";
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

      wp_localize_script('cliniko-stripe-js', 'ClinikoStripeData', [
        'stripe_pk' => get_option('wp_stripe_public_key'),
        'module_id' => esc_attr($settings['module_id'] ?? ''),
        'client_secret_url' => get_site_url() . '/wp-json/v1/client-secret',
      ]);
    }

    if ($gateway === 'Tyrohealth') {
        echo "loading tyro";
      wp_enqueue_script(
        'medipass-checkout-sdk',
        'https://unpkg.com/@medipass/partner-sdk@1.10.1/umd/@medipass/partner-sdk.min.js',
        [],
        null,
        ['strategy' => 'defer']
      );

      wp_enqueue_script(
        'cliniko-tyrohealth-js',
        plugin_dir_url(__FILE__) . 'assets/js/tyrohealth.js',
        ['jquery', 'medipass-checkout-sdk'],
        null,
        ['strategy' => 'defer']
      );

      

      wp_localize_script('cliniko-tyrohealth-js', 'TyroHealthData', [
        'env' => get_option('tyrohealth_env', 'stg'),
        'module_id' => esc_attr($settings['module_id'] ?? ''),
        'create_invoice_url' => get_site_url() . '/wp-json/v1/tyrohealth/invoices',
        'confirm_booking_url' => get_site_url() . '/wp-json/v1/tyrohealth/confirm-booking',
        'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect'] ?? ''),
        'send_receipt' => true,
        'apiKey' => '691e910a630c2738dc68b210:Wvki5e6Jc88DDTlqyzp70QrITaagTyqg-asmrtol1qQ',
        'appId' => 'easyscripts-web',
        'appVersion' => '1.0'
      ]);
    }
  }

  require_once __DIR__ . '/templates/cliniko_multistep_form.phtml';

  // FINAL STEP
  if ($appointment_source === "cliniko_embed") return;

  if ($is_editor) {
    require __DIR__ . '/templates/card_form_mock.phtml';
    return;
  }

  // Choose gateway template correctly
  if ($payment_enabled && $gateway === 'Tyrohealth') {
      echo "loading tyro payment";
    require __DIR__ . '/templates/card_form_tyrohealth.phtml';
  } else {
      echo "loading stripe payment";
    require __DIR__ . '/templates/card_form_real.phtml'; // Stripe
  }
}


}
