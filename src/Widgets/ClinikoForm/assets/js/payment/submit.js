import { parseFormToStructuredBody } from "../helpers/parser.js";
import { showToast } from "../helpers/toast.js";
import { showPaymentLoader } from "../helpers/overlay.js";

export async function submitBookingForm(stripeToken, errorEl, formHandlerData) {
  const formElement = document.getElementById("prepayment-form");
  const { content, patient } = parseFormToStructuredBody(formElement, formHandlerData);

  const payload = {
    content,
    patient,
    moduleId: formHandlerData.module_id,
    patient_form_template_id: formHandlerData.patient_form_template_id,
    stripeToken,
  };

  try {
    const response = await fetch(formHandlerData.payment_url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await response.json();

    if (result.status === "success" && result.payment?.id) {
      showToast("Payment received! We’re scheduling your appointment now…", "success");
      window.location.href = `${formHandlerData.redirect_url}?ref=${result.payment.id}&status=scheduling_queued&receipt=${result.payment.receipt_url || ""}`;
    } else {
      handleChargeErrors(result, errorEl);
    }
  } catch (err) {
    console.error("Payment request failed", err);
    showToast("Unexpected error. Please try again.");
  } finally {
    jQuery.LoadingOverlay("hide");
  }
}

function handleChargeErrors(result, errorEl) {
  const message = result?.message || "Payment failed. Please try again.";
  if (errorEl) {
    errorEl.innerHTML = "";
    if (Array.isArray(result?.errors) && result.errors.length > 0) {
      const ul = document.createElement("ul");
      ul.style.marginTop = "0.5rem";
      ul.style.fontSize = "0.8rem";
      ul.style.paddingLeft = "1.2rem";
      ul.style.color = "#c62828";
      ul.style.fontWeight = "500";
      result.errors.forEach(e => {
        const li = document.createElement("li");
        li.textContent = `${e.label || "Error"}: ${e.detail || e.code || "Unknown"}`;
        ul.appendChild(li);
      });
      errorEl.appendChild(ul);
    } else {
      errorEl.textContent = message;
    }
  } else {
    showToast(message);
  }
}
