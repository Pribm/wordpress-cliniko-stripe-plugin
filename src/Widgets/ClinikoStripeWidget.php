<?php
namespace App\Widgets;

use App\Client\ClinikoClient;
use App\Exception\ApiException;
use App\Model\PatientFormTemplate;
if (!defined('ABSPATH'))
  exit;


use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class ClinikoStripeWidget extends Widget_Base
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
    require_once plugin_dir_path(__FILE__) . './controls/index.php';
    register_cliniko_form_controls($this);
    register_content_controls($this);
    register_style_controls($this);
    register_details_style_controls($this);
    register_cliniko_form_style_controls($this);

  }

  public function render()
  {
    $settings = $this->get_settings();
    $is_editor = \Elementor\Plugin::$instance->editor->is_edit_mode();
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
      plugin_dir_url(__DIR__) . '/Widgets/assets/css/cliniko-stripe.css',
      ["jquery"],
      '1.0.0'
    );

    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);


    $client = ClinikoClient::getInstance();
    try {
  $template = PatientFormTemplate::find($form_template_id, $client);
  $sections = $template ? $template->getSections() : [];
} catch (\Exception $e) {
  new ApiException($e->getMessage());
}

    wp_enqueue_script(
      'form-handler-js',
      plugin_dir_url(__DIR__) . '/Widgets/assets/js/form-handler.js',
      [],
      null,
      ["strategy" => "defer"]
    );

    wp_enqueue_script(
      'cliniko-stripe-js',
      plugin_dir_url(__DIR__) . '/Widgets/assets/js/cliniko-stripe.js',
      ["jquery"],
      null,
      ["strategy" => "defer"]
    );

    $template = isset($settings['booking_html_template']) ? ltrim($settings['booking_html_template']) : '';
    $field_mapping_array = $settings['field_mapping'] ?? [];

    $appearance = [
      'theme' => $settings['theme'],
      'variables' => [
        'colorPrimary' => esc_attr($settings['color_primary']),
        'colorText' => esc_attr($settings['color_text']),
        'colorBackground' => esc_attr($settings['color_background']),
        'borderRadius' => esc_attr($settings['border_radius']['size']) . 'px',
        'fontFamily' => esc_attr($settings['font_family']),
        'input_border' => esc_attr($settings['input_border'])
      ],
      'rules' => [
        '.Input' => [
          'border' => esc_attr($settings['input_border']),
        ]
      ]
    ];

    $logo_url = has_site_icon() ? get_site_icon_url() : '';

    wp_localize_script('cliniko-stripe-js', 'ClinikoStripeData', [
      'stripe_pk' => get_option('wp_stripe_public_key'),
      'client_secret_url' => get_site_url() . '/wp-json/v1/client-secret',
      'patient_form_template_id' => $form_template_id,
      'booking_url' => get_site_url() . '/wp-json/v1/book-cliniko',
      'module_id' => esc_attr($settings['module_id']),
      'template' => $template,
      'field_map' => array_reduce($field_mapping_array, function ($carry, $map) {
        if (!empty($map['raw_name']) && !empty($map['mapped_name'])) {
          $carry[$map['raw_name']] = $map['mapped_name'];
        }
        return $carry;
      }, []),
      'redirect_url' => get_site_url() . esc_url($settings['onpayment_success_redirect']),
      'appearance' => $appearance,
      'layout' => esc_js($settings['layout']),
      'logo_url' => $logo_url,
    ]);

    

    wp_localize_script(
      'form-handler-js',
      'formHandlerData',
      [
        'sections' => $sections ?? [],
        'btn_bg' => esc_attr($settings['form_button_color']),
        'btn_text' => esc_attr($settings['form_button_text_color']),
        'btn_pad' => esc_attr($settings['form_button_padding']),
        'border_radius' => esc_attr($settings['form_border_radius']['size']),
      ]
    );

    require_once __DIR__ . '/templates/cliniko_multistep_form.phtml';

    if ($is_editor) {
      require __DIR__ . '/templates/card_form_mock.phtml';
      return;
    }

    require __DIR__ . '/templates/card_form_real.phtml';
  }

}
