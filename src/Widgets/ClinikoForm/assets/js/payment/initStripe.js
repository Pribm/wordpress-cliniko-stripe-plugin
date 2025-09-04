import { getStripe, createCardElement } from "./stripe.js";
import { attachPaymentHandler } from "./stripeHandlers.js";

/**
 * Initialize Stripe + card element + bind handler.
 */
export async function initStripe(formHandlerData) {
  try {
    const { stripe } = await getStripe(formHandlerData);
    const { cardElement, errorEl } = createCardElement();
    attachPaymentHandler(stripe, cardElement, errorEl, formHandlerData);

    return { stripe, cardElement, errorEl };
  } catch (err) {
    console.error("Stripe init error:", err);
    if (typeof jQuery !== "undefined" && jQuery.LoadingOverlay) {
      jQuery.LoadingOverlay("hide");
    }

    const fallback = document.createElement("div");
    fallback.style.color = "#c62828";
    fallback.textContent =
      "Failed to initialize payment. Please reload the page.";
    document.getElementById("payment-element")?.after(fallback);

    // 👇 Always return full object so destructuring won't explode
    return {
      stripe: null,
      cardElement: null,
      errorEl: null,
    };
  }
}
