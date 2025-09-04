import { submitBookingForm } from "./submit.js";
import { showToast } from "../helpers/toast.js";

let paymentHandlerAttached = false;

/**
 * Attach handler to payment button.
 */
export function attachPaymentHandler(stripe, cardElement, errorEl, formHandlerData) {
  if (paymentHandlerAttached) return;
  paymentHandlerAttached = true;

  const button = document.getElementById("payment-button");
  if (!button) return;

  button.addEventListener("click", async () => {
    if (typeof jQuery !== "undefined" && jQuery.LoadingOverlay) {
      jQuery.LoadingOverlay("show");
    }
    if (errorEl) errorEl.textContent = "";

    try {
      const { token, error } = await stripe.createToken(cardElement);
      if (error) {
        if (errorEl) errorEl.textContent = error.message;
        return;
      }

      await submitBookingForm(token.id, errorEl, formHandlerData);
      showToast("Booking completed successfully", "success");
    } catch (err) {
      console.error("Payment or booking error:", err);
      if (errorEl) {
        errorEl.textContent =
          "An unexpected error occurred. Please try again.";
      }
    } finally {
      if (typeof jQuery !== "undefined" && jQuery.LoadingOverlay) {
        jQuery.LoadingOverlay("hide");
      }
    }
  });
}
