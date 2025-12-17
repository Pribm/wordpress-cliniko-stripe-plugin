// --- Keep Stripe instance globally (singleton pattern) ---
let paymentHandlerAttached = false;
let stripeCardElement = null;
let stripeErrorElement = null;
let stripeElementsInstance = null;

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

  const mountPoint = document.getElementById("payment-element");
  if (!mountPoint) {
    throw new Error("Stripe mount point #payment-element not found.");
  }

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
  cardElement.mount(mountPoint);

  // Create error container right after the card element
  let errorEl = document.getElementById("payment-error-message");
  if (!errorEl) {
    errorEl = document.createElement("div");
    errorEl.id = "payment-error-message";
    errorEl.style.cssText = "margin-top: 1rem; color: #c62828; font-weight: 500;";
    mountPoint.after(errorEl);
  }

  stripeCardElement = cardElement;
  stripeErrorElement = errorEl;
  stripeElementsInstance = elements;

  return { stripe, cardElement, errorEl };
}

/**
 * Ensure the card element is mounted (if the DOM was re-rendered).
 */
async function ensureCardMounted() {
  const mountPoint = document.getElementById("payment-element");
  const mountHasIframe = !!mountPoint?.querySelector("iframe");

  if (!stripeCardElement || !mountHasIframe) {
    await initializeStripeElements();
  }
}

/**
 * Attach click handler to the payment button (only once).
 */
function handlePaymentAndFormSubmission(stripe) {
  if (paymentHandlerAttached) return; // avoid duplicate listener
  paymentHandlerAttached = true;

  const btn = document.getElementById("payment-button");
  if (!btn) {
    console.error("Stripe payment button not found.");
    return;
  }

  btn.addEventListener("click", async () => {
    showPaymentLoader();
    if (stripeErrorElement) stripeErrorElement.textContent = "";

    try {
      await ensureCardMounted();

      const { token, error } = await stripe.createToken(stripeCardElement);
      if (error) {
        if (stripeErrorElement) stripeErrorElement.textContent = error.message;
        return;
      }

      await submitBookingForm(token.id, stripeErrorElement);
    } catch (err) {
      console.error("Payment or booking error:", err);
      if (stripeErrorElement) {
        stripeErrorElement.textContent = "An unexpected error occurred. Please try again.";
      }
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
    const { stripe } = await initializeStripeElements();
    handlePaymentAndFormSubmission(stripe);
  } catch (err) {
    console.error("Stripe init error:", err);
    jQuery.LoadingOverlay("hide");

    const fallbackError = document.createElement("div");
    fallbackError.style.color = "#c62828";
    fallbackError.textContent = "Failed to initialize payment. Please reload the page.";
    document.getElementById("payment-element").after(fallbackError);
  }
}
