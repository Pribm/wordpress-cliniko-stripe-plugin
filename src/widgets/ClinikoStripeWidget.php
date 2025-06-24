<?php
namespace App\Widgets;

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
    register_content_controls($this);
    register_style_controls($this);
    register_details_style_controls($this);
  }

  public function render()
  {
    $settings = $this->get_settings();
    $stripe_pk = esc_js(get_option('wp_stripe_public_key'));
    $module_id = esc_attr($settings['module_id']);

    $redirect_page = esc_attr($settings['onpayment_success_redirect']);
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

    $template = ltrim($settings['booking_html_template'])  ?? '';

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

    <?php
    $field_mapping_array = $settings['field_mapping'] ?? [];
    ?>

    <script>
      window.bookingHtmlTemplate = <?php echo json_encode($template); ?>;

      const fieldMap = <?php echo json_encode(
        array_reduce($field_mapping_array, function ($carry, $map) {
            if (!empty($map['raw_name']) && !empty($map['mapped_name'])) {
              $carry[$map['raw_name']] = $map['mapped_name'];
            }
            return $carry;
          }, []),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
      ); ?>;
    </script>

    <style>
      #loader-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(255, 255, 255, 0.96);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 99999;
      }

      .loader-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        font-family: 'Segoe UI', sans-serif;
      }

      .spinner {
        width: 50px;
        height: 50px;
        border: 4px solid #e0f2ec;
        border-top: 4px solid #5EB192;
        border-radius: 50%;
        animation: spin 1s linear infinite;
      }

      .loader-wrapper p {
        margin-top: 20px;
        color: #2F5D50;
        font-size: 1rem;
        font-weight: 500;
        text-align: center;
        min-height: 1.2em;
        max-width: 300px;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }
    </style>

    <div id="loader-overlay" style="display: none;">
      <div class="loader-wrapper">
        <div class="spinner"></div>
        <p id="loader-text">Processing payment…</p>
      </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
      function showLoaderWithMessages() {
        const loader = document.getElementById('loader-overlay');
        const loaderText = document.getElementById('loader-text');

        const phrases = [
          "Processing payment…",
          "Confirming with the clinic…",
          "Scheduling your appointment…",
          "Almost there…",
          "Finalizing details…"
        ];

        let index = 0;
        loader.style.display = 'flex';

        const interval = setInterval(() => {
          index = (index + 1) % phrases.length;
          loaderText.textContent = phrases[index];
        }, 8000);

        return interval;
      }

      async function initStripe() {
        if (typeof Stripe === 'undefined') {
          console.error('Stripe.js not loaded');
          return;
        }

        const stripe = Stripe("<?php echo $stripe_pk; ?>");

        const clientSecretEndpoint = "<?php echo get_site_url(); ?>/wp-json/v1/client-secret";

        try {
          const res = await fetch(clientSecretEndpoint, {
            method: "POST",
            body: JSON.stringify({ moduleId: "<?php echo $module_id; ?>" }),
            headers: { "Content-Type": "application/json" },
          });

          const data = await res.json();
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
            const loaderInterval = showLoaderWithMessages();

            const cleanedData = {};
            const isElementor = <?php echo json_encode($settings['is_elementor_form'] === 'yes'); ?>;
            const rawData = window.preFormData;

            for (const [key, value] of Object.entries(rawData)) {
              if (isElementor && key.startsWith('form_fields[')) {
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


            const template = window.bookingHtmlTemplate || '';

            const ignoreMatches = [...template.matchAll(/\[ignore_field=([^\]]+)\]/g)];
            const ignoreFields = ignoreMatches.map(m => m[1]);

            // Remove os marcadores do template antes de usá-lo
            let bookingHtml = template.replace(/\[ignore_field=[^\]]+\]/g, '');

            const isPlainText = <?php echo json_encode($settings['booking_plaintext_notes'] === 'yes'); ?>;

            // let bookingHtml = template;

            const allFieldsBlock = Object.entries(cleanedData)
              .filter(([key]) => !ignoreFields.includes(key))
              .map(([key, value]) => {
                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                return isPlainText ? `• ${label}:\n${value}\n` : `${label}: ${value}`;
              })
              .join(isPlainText ? '---------------------------------------\n' : '<br>');

            bookingHtml = bookingHtml.replaceAll('[all_fields]', allFieldsBlock);

            for (const [key, value] of Object.entries(cleanedData)) {
              const tag = `[${key}]`;
              bookingHtml = bookingHtml.replaceAll(tag, value);
            }

            // Aplica transformações finais para texto puro
            if (isPlainText) {
              bookingHtml = bookingHtml
                .replace(/<br\s*\/?>/gi, '\n')
                .replace(/<\/p>/gi, '\n')
                .replace(/<[^>]+>/g, '').trimStart(); // remove tags HTML restantes (sem normalizar quebras)
            }

            //TEST
            // if (bookingHtml.includes('[all_fields]')) {
            //   let allFieldsHtml = '<ul style="list-style: none; padding: 0;">';
            //   for (const [key, value] of Object.entries(cleanedData)) {
            //     const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            //     allFieldsHtml += `<li><strong>${label}:</strong> ${value}</li>`;
            //   }
            //   allFieldsHtml += '</ul>';
            //   bookingHtml = bookingHtml.replaceAll('[all_fields]', allFieldsHtml);
            // }

            const { error, paymentIntent } = await stripe.confirmPayment({
              elements,
              redirect: 'if_required'
            });

            if (error) {
              alert(error.message);
            } else if (paymentIntent && paymentIntent.status === 'succeeded') {
              const bookingEndpoint = "<?php echo get_site_url(); ?>/wp-json/v1/book-cliniko";

              const response = await fetch(bookingEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  moduleId: "<?php echo $module_id; ?>",
                  patient,
                  paymentIntentId: paymentIntent.id,
                  notes: bookingHtml
                })
              });

              const result = await response.json();
              if (result.status === "success") {
                const query = new URLSearchParams({
                  status: result.status,
                  appointment: JSON.stringify(result.appointment),
                  patient: JSON.stringify(result.patient),
                }).toString();

                window.location.href = "<?php echo get_site_url() . esc_url($redirect_page); ?>" + "?" + query;
              } else {
                clearInterval(loaderInterval);
                alert("Payment successful, but there was an error scheduling your appointment.");
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
                paymentForm.style.display = "flex";
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
