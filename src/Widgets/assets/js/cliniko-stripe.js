async function initStripe() {
  if (typeof Stripe === "undefined") {
    console.error("Stripe.js not loaded");
    return;
  }

  window.stripe = Stripe(ClinikoStripeData.stripe_pk);
  const { stripe } = window;

  try {
    const res = await fetch(ClinikoStripeData.client_secret_url, {
      method: "POST",
      body: JSON.stringify({ moduleId: ClinikoStripeData.module_id }),
      headers: { "Content-Type": "application/json" },
    });

    const data = await res.json();
    const { name, duration, price, description } = data;

    document.getElementById("summary-name").textContent = name ?? "N/A";
    document.getElementById("summary-description").textContent =
      description ?? "N/A";
    document.getElementById("summary-duration").textContent = duration ?? "--";
    document.getElementById("summary-price").textContent =
      (price / 100).toFixed(2) ?? "--";

    const elements = stripe.elements();

    const style = {
      base: {
        fontSize: "16px",
        color: "#32325d",
        fontFamily: "Arial, sans-serif",
        "::placeholder": {
          color: "#aab7c4",
        },
      },
      invalid: {
        color: "#fa755a",
        iconColor: "#fa755a",
      },
    };

    const cardElement = elements.create("card", { style });
    cardElement.mount("#payment-element");

    document
      .getElementById("payment-button")
      .addEventListener("click", async () => {
        showPaymentLoader();

        const { token, error } = await stripe.createToken(cardElement);

        if (error) {
          alert(error.message);
          return;
        }

        const formElement = document.getElementById("prepayment-form");
        const { content, patient } = parseFormToStructuredBody(formElement);

        const formData = new FormData();

        formData.append("content", JSON.stringify(content));
        formData.append("patient", JSON.stringify(patient));
        formData.append("stripeToken", token.id);
        formData.append("moduleId", ClinikoStripeData.module_id);
        formData.append(
          "patient_form_template_id",
          ClinikoStripeData.patient_form_template_id
        );

        // 👇 Adiciona a assinatura convertida como arquivo
        const signatureData = document.getElementById("signature-data")?.value;
        if (signatureData && signatureData.startsWith("data:image/")) {
          const blob = dataURLToBlob(signatureData);
          const file = new File([blob], "signature.png", { type: "image/png" });
          formData.append("signature_file", file);
        }

        const response = await fetch(ClinikoStripeData.booking_url, {
          method: "POST",
          body: formData,
        });

        const result = await response.json();

        if (result.status === "success") {
          const redirectBase = ClinikoStripeData.redirect_url;

          const queryParams = new URLSearchParams({
            patient_name: result.patient?.name ?? "",
            email: result.patient?.email ?? "",
            start: result.appointment?.starts_at ?? "",
            end: result.appointment?.ends_at ?? "",
            ref: result.appointment?.payment_reference ?? "",
            link: result.appointment?.telehealth_url ?? "",
          });

          const finalUrl = `${redirectBase}?${queryParams.toString()}`;
          window.location.href = finalUrl;
        } else {
          alert("Error booking appointment.");
          jQuery.LoadingOverlay("hide");
        }
      });
  } catch (err) {
    console.error("Stripe init error:", err);
  } finally {
    jQuery.LoadingOverlay("hide");
  }
}

function dataURLToBlob(dataUrl) {
  const arr = dataUrl.split(",");
  const mime = arr[0].match(/:(.*?);/)[1];
  const bstr = atob(arr[1]);
  let n = bstr.length;
  const u8arr = new Uint8Array(n);

  while (n--) {
    u8arr[n] = bstr.charCodeAt(n);
  }

  return new Blob([u8arr], { type: mime });
}

function showPaymentLoader() {
  const styles = ClinikoStripeData.appearance?.variables || {};
  const logo = ClinikoStripeData.logo_url;

  jQuery.LoadingOverlay("show", {
    image: "", // desativa imagem padrão
    background: "rgba(255, 255, 255, 0.85)",
    zIndex: 9999,
    custom: jQuery(`
      <div style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 32px;
        font-family: ${styles.fontFamily || 'sans-serif'};
        color: ${styles.colorText || '#333'};
      ">
        ${logo ? `<img src="${logo}" alt="Logo" style="max-height: 60px; margin-bottom: 20px;" class="pulse-logo" />` : ''}
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
          Processing your secure payment...
        </div>
        <div style="font-size: 14px; color: #666;">
          Please wait while we confirm your appointment with the clinic.
        </div>
      </div>
    `),
  });

  // Estilo de animação pulse (só adiciona uma vez)
  if (!document.getElementById('pulse-logo-style')) {
    const style = document.createElement('style');
    style.id = 'pulse-logo-style';
    style.innerHTML = `
      @keyframes pulseLogo {
        0%   { transform: scale(1); opacity: 1; }
        50%  { transform: scale(1.08); opacity: 0.85; }
        100% { transform: scale(1); opacity: 1; }
      }
      .pulse-logo {
        animation: pulseLogo 1.6s ease-in-out infinite;
      }
    `;
    document.head.appendChild(style);
  }
}
