<?php

if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class Cliniko_Stripe_Widget extends Widget_Base {

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
    require_once plugin_dir_path(__FILE__) . './controls/index.php';
    register_content_controls($this);
    register_style_controls($this);
    register_details_style_controls($this);
  }

  public function render() {
    $settings = $this->get_settings();
    $stripe_pk = esc_js(get_option('wp_stripe_public_key'));
    $module_id = esc_attr($settings['module_id']);
    $button_label = esc_html($settings['button_label']);
    $is_editor = \Elementor\Plugin::$instance->editor->is_edit_mode();

    $color_background = esc_attr($settings['color_background']);
    $color_text = esc_attr($settings['color_text']);
    $color_primary = esc_attr($settings['color_primary']);
    $font_family = esc_attr($settings['font_family']);
    $border_radius = esc_attr($settings['border_radius']['size']);
    $input_border = esc_attr($settings['input_border']);
    $after_submit_js = trim($settings['after_submit_js']);
    $button_font_size = esc_attr($settings['button_font_size']['size']);
    $button_text_color = esc_attr($settings['button_text_color']);
    $button_padding = esc_attr($settings['button_padding']);
    $button_css = esc_attr($settings['button_css']);

    $style_input = "width: 100%; padding: 10px; border: {$input_border}; border-radius: {$border_radius}px; font-family: {$font_family}; color: {$color_text}; background: {$color_background};";
    $style_label = "font-weight: bold; display: block; margin-bottom: 4px; color: {$color_text}; font-family: {$font_family};";
    $style_button = "margin-top: 16px; width: 100%; background: {$color_primary}; color: {$button_text_color}; padding: {$button_padding}; font-size: {$button_font_size}px; border: none; border-radius: {$border_radius}px; font-family: {$font_family}; {$button_css}";

    if ($is_editor) {
      require_once __DIR__ . "/templates/card_form_mock.phtml";
      return;
    }

    require_once __DIR__ . "/templates/card_form_real.phtml";

    $appearance = [
      'theme' => $settings['theme'],
      'variables' => [
        'colorPrimary' => $color_primary,
        'colorText' => $color_text,
        'colorBackground' => $color_background,
        'borderRadius' => $border_radius . 'px',
        'fontFamily' => $font_family,
      ],
      'rules' => [
        '.Input' => [
          'border' => $input_border
        ]
      ]
    ];

    ?>
    <script src="https://js.stripe.com/v3/"></script>
    <script>
    async function initStripe() {
      if (typeof Stripe === 'undefined') {
        console.error('Stripe.js not loaded');
        return;
      }

      const stripe = Stripe("<?php echo $stripe_pk; ?>");

      try {
        const res = await fetch("/wp-json/v1/client-secret", {
          method: "POST",
          body: JSON.stringify({ moduleId: "<?php echo $module_id; ?>" }),
          headers: { "Content-Type": "application/json" },
        });


        const data = await res.json();

                console.log(data)

        const { clientSecret, name, duration, price, description } = data;

        document.getElementById('summary-name').textContent = name ?? 'N/A';
        document.getElementById('summary-description').textContent = description ?? 'N/A';
        document.getElementById('summary-duration').textContent = duration ?? '--';
        document.getElementById('summary-price').textContent = (price / 100).toFixed(2) ?? '--';

        const appearance = <?php echo json_encode($appearance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        const elements = stripe.elements({ clientSecret, appearance });
        const paymentElement = elements.create('payment', { layout: '<?php echo esc_js($settings["layout"]); ?>' });
        paymentElement.mount('#payment-element');

        document.getElementById('submit-button').addEventListener('click', async () => {

          const cleanedData = {};
          const isElementor = <?php echo json_encode($settings['is_elementor_form'] === 'yes'); ?>;
          const rawData = window.preFormData;

          for (const [key, value] of Object.entries(rawData)) {
            if (isElementor && key.startsWith('form_fields[')) {
              // Remove prefix and brackets: form_fields[first_name] => first_name
                const cleanKey = key.replace(/^form_fields\[(.+)\]$/, '$1');
                cleanedData[cleanKey] = value;
              } else {
                cleanedData[key] = value;
              }
            }

              const patient = {
              ...cleanedData,
              appointment_type_id: "<?php echo $module_id; ?>"
            };

          const { error, paymentIntent } = await stripe.confirmPayment({
            elements,
            redirect: 'if_required'
          });

          if (error) {
            alert(error.message);
          } else if (paymentIntent && paymentIntent.status === 'succeeded') {
            // Após o pagamento, envia os dados do formulário anterior
            const response = await fetch('/wp-json/v1/book-cliniko', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                moduleId: "<?php echo $module_id; ?>",
                patient,
                paymentIntentId: paymentIntent.id
              })
            });

            const result = await response.json();
            if (result.success) {
              alert("Pagamento e agendamento concluídos!");
              window.location.href = "/sucesso";
            } else {
              alert("Pagamento foi concluído, mas houve um erro ao agendar.");
            }
          }
        });
      } catch (err) {
        console.error('Stripe init error:', err);
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(() => {
        initStripe();
      }, 5000);

      <?php if (!empty($settings['has_pre_form']) && !empty($settings['pre_form_selector'])): ?>
      const preForm = document.querySelector('<?php echo esc_js($settings['pre_form_selector']); ?>');
      const paymentForm = document.querySelector("#payment_form");
      const backButton = document.getElementById("go-back-button");

      if (preForm && paymentForm) {
        paymentForm.style.display = "none";

        const realSubmitButton = preForm.querySelector('button[type="submit"]');
        if (realSubmitButton) {
          realSubmitButton.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();

            const formData = new FormData(preForm);
            window.preFormData = Object.fromEntries(formData.entries());
            preForm.parentElement.style.display = "none";
            paymentForm.style.display = "block";
            return false;
          });
        }

        if (backButton) {
          backButton.addEventListener("click", function (e) {
            e.preventDefault();
            paymentForm.style.display = "none";
            preForm.parentElement.style.display = "block";
          });
        }
      }
      <?php endif; ?>
    });
    </script>
    <?php
  }
}
