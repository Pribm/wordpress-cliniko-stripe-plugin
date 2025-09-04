<?php
namespace App\Widgets\ClinikoForm;

use Elementor\Plugin;
use Elementor\Widget_Base;
use App\Exception\ApiException;
use App\Model\PatientFormTemplate;

if (!defined('ABSPATH')) exit;

class Widget extends Widget_Base
{
    public function get_name() {
        return 'cliniko_stripe_payment';
    }

    public function get_title() {
        return 'Cliniko + Stripe Payment';
    }

    public function get_icon() {
        return 'eicon-lock-user';
    }

    public function get_categories() {
        return ['general'];
    }

    public function _register_controls() {
        require plugin_dir_path(__FILE__) . 'controls/index.php';
        register_cliniko_form_controls($this);
        register_content_controls($this);
        register_style_controls($this);
        register_details_style_controls($this);
        register_cliniko_form_style_controls($this);
    }

    public function render() {
        $settings         = $this->get_settings_for_display();
        $is_editor        = Plugin::$instance->editor->is_edit_mode();
        $form_template_id = $settings['cliniko_form_template_id'] ?? null;

        // Enqueue base styles & vendor scripts
        $this->enqueue_widget_styles();
        $this->enqueue_vendor_scripts();

        // Fetch Cliniko form template sections
        try {
            $templateModel = PatientFormTemplate::find($form_template_id, cliniko_client(true), false);
            $sections = $templateModel ? $templateModel->getSections() : [];
        } catch (\Exception $e) {
            new ApiException($e->getMessage());
            $sections = [];
        }

        // Enqueue compiled bundle (Vite/Rollup build)
        $this->enqueue_bundle();

        // Inject config JSON for index.js
        $this->inject_form_data($settings, $sections, $form_template_id);

        // Render templates
        require_once __DIR__ . '/templates/cliniko_multistep_form.phtml';

        if ($is_editor) {
            require __DIR__ . '/templates/card_form_mock.phtml';
            return;
        }

        if ($settings['enable_payment'] === 'yes') {
            require __DIR__ . '/templates/card_form_real.phtml';
        }
    }

    /** Enqueue base styles */
    private function enqueue_widget_styles() {
        wp_enqueue_style(
            'font-awesome-5',
            'https://use.fontawesome.com/releases/v5.15.4/css/all.css',
            [],
            null
        );

        wp_enqueue_style(
            'cliniko-stripe-style',
            plugin_dir_url(__FILE__) . 'assets/css/cliniko-stripe.css',
            [],
            null
        );
    }

    /** Enqueue vendor JS like overlays (no Stripe anymore) */
    private function enqueue_vendor_scripts() {
        wp_enqueue_script(
            'loading-overlay',
            'https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.7/dist/loadingoverlay.min.js',
            ['jquery'],
            null,
            true
        );
    }

    /** Enqueue the compiled form bundle (Vite/Rollup build) */
    private function enqueue_bundle() {
        $bundle_url = plugin_dir_url(__FILE__) . 'assets/dist/form.bundle.js';

        wp_enqueue_script(
            'cliniko-form-bundle',
            $bundle_url,
            [],
            null,
            true
        );
        wp_script_add_data('cliniko-form-bundle', 'type', 'module');
    }

    /** Inject form config into DOM as JSON */
    private function inject_form_data(array $settings, array $sections, ?string $form_template_id) {
        $appearance = [
            'theme'         => $settings['theme'] ?? 'flat',
            'progress_type' => $settings['progress_type'],
            'variables'     => [
                'colorPrimary'    => esc_attr($settings['color_primary'] ?? '#0073e6'),
                'colorText'       => esc_attr($settings['color_text'] ?? '#333'),
                'colorBackground' => esc_attr($settings['color_background'] ?? '#ffffff'),
                'borderRadius'    => esc_attr($settings['border_radius']['size'] ?? 6) . 'px',
                'fontFamily'      => esc_attr($settings['font_family'] ?? 'Arial, sans-serif'),
                'input_border'    => esc_attr($settings['input_border'] ?? '1px solid #ccc'),
            ],
            'rules' => [
                '.Input' => [
                    'border' => esc_attr($settings['input_border'] ?? '1px solid #ccc'),
                ],
            ],
        ];

        $logo_url = has_site_icon() ? get_site_icon_url() : '';

        $pad     = $settings['form_button_padding'] ?? null;
        $btn_pad = isset($pad['top'], $pad['right'], $pad['bottom'], $pad['left'], $pad['unit'])
            ? "{$pad['top']}{$pad['unit']} {$pad['right']}{$pad['unit']} {$pad['bottom']}{$pad['unit']} {$pad['left']}{$pad['unit']}"
            : '12px 24px';

        $formHandlerData = [
            'sections'                 => $sections ?? [],
            'btn_bg'                   => esc_attr($settings['form_button_color'] ?? '#0073e6'),
            'btn_text'                 => esc_attr($settings['form_button_text_color'] ?? '#ffffff'),
            'btn_pad'                  => $btn_pad,
            'border_radius'            => esc_attr($settings['form_border_radius']['size'] ?? 6) . 'px',
            'is_payment_enabled'       => $settings['enable_payment'],
            'module_id'                => esc_attr($settings['module_id']),
            'patient_form_template_id' => $form_template_id,
            'payment_url'              => get_site_url() . '/wp-json/v1/payments/charge',
            'redirect_url'             => get_site_url() . esc_url($settings['onpayment_success_redirect']),
            'appearance'               => $appearance,
            'logo_url'                 => $logo_url,
            'stripe_pk'                => get_option('wp_stripe_public_key'), // added for bundle
        ];

        echo '<script id="formHandlerData" type="application/json">'
            . wp_json_encode($formHandlerData)
            . '</script>';
    }
}
