// --- Keep Stripe instance globally (singleton pattern) ---
let paymentHandlerAttached = false;

/**
 * Get or initialize Stripe instance.
 */
function getStripe() {
  if (!stripeInstance) {
    stripeInstance = Stripe(ClinikoStripeData.stripe_pk);
  }
  return stripeInstance;
}

/**
 * Initialize the card element and error container.
 */
async function initializeStripeElements() {
  const stripe = getStripe();

  const elements = stripe.elements();
  const style = {
    base: {
      fontSize: "16px",
      color: "#32325d",
      fontFamily: "Arial, sans-serif",
      "::placeholder": { color: "#aab7c4" },
    },
    invalid: {
      color: "#fa755a",
      iconColor: "#fa755a",
    },
  };

  // Mount card element
  const cardElement = elements.create("card", { style });
  cardElement.mount("#payment-element");

  // Create error container right after the card element
  let errorEl = document.getElementById("payment-error-message");
  if (!errorEl) {
    errorEl = document.createElement("div");
    errorEl.id = "payment-error-message";
    errorEl.style.cssText = "margin-top: 1rem; color: #c62828; font-weight: 500;";
    document.getElementById("payment-element").after(errorEl);
  }

  return { stripe, cardElement, errorEl };
}

/**
 * Attach click handler to the payment button (only once).
 */
function handlePaymentAndFormSubmission(stripe, cardElement, errorEl) {
  if (paymentHandlerAttached) return; // avoid duplicate listener
  paymentHandlerAttached = true;

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

/**
 * Main initializer: mounts Stripe Elements and binds the handler.
 */
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
