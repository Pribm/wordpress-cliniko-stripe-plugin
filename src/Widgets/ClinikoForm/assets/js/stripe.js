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

  // Stripe must receive an empty mount node. Keep any loading UI beside it,
  // including when an older rendered template still placed it inside.
  const inlineStatus = mountPoint.querySelector("[data-es-stripe-status]");
  if (inlineStatus) {
    mountPoint.after(inlineStatus);
  }
  mountPoint.replaceChildren();
  mountPoint.setAttribute("aria-busy", "true");

  const status = mountPoint.parentElement?.querySelector(
    "#payment-element-status, [data-es-stripe-status]"
  );
  const setStatus = (mode, message = "") => {
    if (!status) return;
    status.hidden = mode === "ready";
    status.style.display = mode === "ready" ? "none" : "flex";
    if (mode === "error") {
      status.replaceChildren();
      status.textContent = message || "The secure payment field could not be loaded.";
    }
  };

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
  let resolveReady;
  let rejectReady;
  const readyPromise = new Promise((resolve, reject) => {
    resolveReady = resolve;
    rejectReady = reject;
  });

  cardElement.on("ready", () => {
    mountPoint.setAttribute("aria-busy", "false");
    setStatus("ready");
    resolveReady();
  });
  cardElement.on("loaderror", (event) => {
    mountPoint.setAttribute("aria-busy", "true");
    const message = event?.error?.message || "The secure payment field could not be loaded.";
    setStatus("error", message);
    rejectReady(new Error(message));
  });
  cardElement.mount(mountPoint);

  // Keep payment errors outside the visual Stripe shell. If an older render
  // already placed the element inside the shell, this also moves it out.
  let errorEl = document.getElementById("payment-error-message");
  if (!errorEl) {
    errorEl = document.createElement("div");
    errorEl.id = "payment-error-message";
    errorEl.style.cssText = "margin-top: 1rem; color: #c62828; font-weight: 500;";
  }
  const paymentShell = mountPoint.closest("#payment-element-shell");
  (paymentShell || mountPoint).after(errorEl);

  stripeCardElement = cardElement;
  stripeErrorElement = errorEl;
  stripeElementsInstance = elements;

  return { stripe, cardElement, errorEl, readyPromise };
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
      if (typeof window.hidePaymentLoader === "function") {
        window.hidePaymentLoader();
      } else {
        jQuery.LoadingOverlay("hide");
      }
    }
  });
}

/**
 * Main initializer: mounts Stripe Elements and binds the handler.
 */
async function initStripe() {
  if (typeof Stripe === "undefined") {
    console.error("Stripe.js not loaded");
    return false;
  }

  try {
    const { stripe, readyPromise } = await initializeStripeElements();
    handlePaymentAndFormSubmission(stripe);

    // The ready event is the source of truth for the secure iframe. There is
    // deliberately no timeout here.
    await readyPromise;
    return true;
  } catch (err) {
    console.error("Stripe init error:", err);
    if (typeof window.hidePaymentLoader === "function") {
      window.hidePaymentLoader();
    } else {
      jQuery.LoadingOverlay("hide");
    }

    const fallbackError = document.createElement("div");
    fallbackError.style.color = "#c62828";
    fallbackError.textContent = "Failed to initialize payment. Please reload the page.";
    const fallbackMount = document.getElementById("payment-element");
    const fallbackShell = fallbackMount?.closest("#payment-element-shell");
    (fallbackShell || fallbackMount)?.after(fallbackError);
    return false;
  }
}

// Explicit public entry point for the headless shell. The shell reveals the
// payment panel after page load, so it cannot rely only on the initial load
// watcher to mount Stripe Elements.
window.ClinikoInitStripe = initStripe;
