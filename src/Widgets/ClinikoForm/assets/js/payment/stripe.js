import { loadStripe } from "@stripe/stripe-js";

let stripeInstance = null;
let elementsInstance = null;

/**
 * Initialize or reuse a Stripe instance.
 */
export async function getStripe(formHandlerData) {
  if (!stripeInstance) {
    stripeInstance = await loadStripe(formHandlerData.stripe_pk);
    elementsInstance = stripeInstance.elements();
  }
  return { stripe: stripeInstance, elements: elementsInstance };
}

/**
 * Create + mount card element and error container.
 */
export function createCardElement() {
  if (!elementsInstance) {
    throw new Error("Stripe elements not initialized");
  }

  const style = {
    base: {
      fontSize: "16px",
      color: "#32325d",
      fontFamily: "Arial, sans-serif",
      "::placeholder": { color: "#aab7c4" },
    },
    invalid: { color: "#fa755a", iconColor: "#fa755a" },
  };

  const cardElement = elementsInstance.create("card", { style });
  cardElement.mount("#payment-element");

  let errorEl = document.getElementById("payment-error-message");
  if (!errorEl) {
    errorEl = document.createElement("div");
    errorEl.id = "payment-error-message";
    errorEl.style.cssText =
      "margin-top:1rem;color:#c62828;font-weight:500;";
    document.getElementById("payment-element").after(errorEl);
  }

  return { cardElement, errorEl };
}
