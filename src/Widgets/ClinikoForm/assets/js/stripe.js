async function initStripe() {
  if (typeof Stripe === "undefined") {
    console.error("Stripe.js not loaded");
    return;
  }

  try {
    const { stripe, cardElement, errorEl } = await initializeStripeElements();
    handlePaymentAndFormSubmission(stripe, cardElement, errorEl);
  } catch (err) {
    console.error("Stripe init error:", err);
    jQuery.LoadingOverlay("hide");

    const fallbackError = document.createElement("div");
    fallbackError.style.color = "#c62828";
    fallbackError.textContent = "Failed to initialize payment. Please reload the page.";
    document.getElementById("payment-element").after(fallbackError);
  }
}

async function initializeStripeElements() {
  const stripe = Stripe(ClinikoStripeData.stripe_pk);

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

  const errorEl = document.createElement("div");
  errorEl.id = "payment-error-message";
  errorEl.style.cssText = "margin-top: 1rem; color: #c62828; font-weight: 500;";
  document.getElementById("payment-element").after(errorEl);

  return { stripe, cardElement, errorEl };
}

function handlePaymentAndFormSubmission(stripe, cardElement, errorEl) {
  document.getElementById("payment-button").addEventListener("click", async () => {
    showPaymentLoader();
    errorEl.textContent = "";

    try {
      const { token, error } = await stripe.createToken(cardElement);
      if (error) {
        errorEl.textContent = error.message;
        return;
      }

      await submitBookingForm(token.id, errorEl);
    } catch (err) {
      console.error("Payment or booking error:", err);
      errorEl.textContent = "An unexpected error occurred. Please try again.";
    } finally {
      jQuery.LoadingOverlay("hide");
    }
  });
}