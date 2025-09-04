import { bindOtherToggle } from "../helpers/otherToggle.js";
import { setupSignatureCanvas } from "../helpers/signature.js";
import { showToast } from "../helpers/toast.js";
import { initStripe } from "../payment/initStripe.js";
import { submitBookingForm } from "../payment/submit.js";
import { showStep } from "./steps.js";         // keep it here
import { showPaymentLoader } from "../helpers/overlay.js";
import { attachValidationListeners, isCurrentStepValid } from "../helpers/validation.js";
import { goToStep } from "./navigation.js";

/**
 * Mounts the multistep form.
 */
export function mountForm(formHandlerData) {
  console.log(formHandlerData)
  const form = document.getElementById("prepayment-form");
  if (!form) return;

  bindOtherToggle(form);
  setupSignatureCanvas();
  attachValidationListeners(form);

  let currentStep = 0;
  let stripeInitStarted = false;

  const steps = document.querySelectorAll(".form-step");
  const nextBtn = document.getElementById("step-next");
  const prevBtn = document.getElementById("step-prev");
  const nextBtnLabel = nextBtn ? nextBtn.innerHTML : "";

  // Stripe refs
  let stripeInstance = null;
  let cardElementInstance = null;
  let errorElementInstance = null;

  function showStepWrapper(i) {

    currentStep = i;
    // ✅ use the imported showStep directly
    showStep(
      i,
      steps,
      prevBtn,
      nextBtn,
      nextBtnLabel,
      formHandlerData.appearance?.progress_type || "bar"
    );
  }

  // NEXT button
  if (nextBtn) {
    nextBtn.addEventListener("click", async () => {
      const isPaymentEnabled = Boolean(formHandlerData.is_payment_enabled);

      if (currentStep < steps.length - 1) {
        if (!isCurrentStepValid(steps, currentStep)) {
          showToast("Please review the highlighted fields before continuing.");
          return;
        }

        // Special: account choice
        const choiceInput = steps[currentStep].querySelector("[name=has_account]:checked");
        if (choiceInput) {
          const nextIndex = choiceInput.value === "yes" ? currentStep + 1 : currentStep + 2;
          currentStep = nextIndex;
          showStepWrapper(currentStep);
          return;
        }

        if (isPaymentEnabled && currentStep === steps.length - 2 && !stripeInitStarted) {
          stripeInitStarted = true;
          try {
            const res = await initStripe(formHandlerData);
            stripeInstance = res?.stripe || null;
            cardElementInstance = res?.cardElement || null;
            errorElementInstance = res?.errorEl || null;
          } catch (err) {
            console.error("Stripe init failed:", err);
          }
        }

        currentStep++;
        showStepWrapper(currentStep);
      } else {
        // Final step
        if (!isCurrentStepValid(steps, currentStep)) {
          showToast("Please review the highlighted fields before continuing.");
          return;
        }

        if (isPaymentEnabled) {
          document.getElementById("prepayment-form").style.display = "none";
          document.getElementById("payment_form").style.display = "flex";

          try {
            const res = await initStripe(formHandlerData);
            stripeInstance = res?.stripe || null;
            cardElementInstance = res?.cardElement || null;
            errorElementInstance = res?.errorEl || null;
          } catch (err) {
            console.error("Stripe init failed:", err);
          }

          const backBtn = document.getElementById("go-back-button");
          if (backBtn) {
            backBtn.addEventListener("click", () => {
              document.getElementById("prepayment-form").style.display = "block";
              document.getElementById("payment_form").style.display = "none";
              showStepWrapper(currentStep);
            });
          }
        } else {
          showPaymentLoader(formHandlerData);
          await submitBookingForm(form, formHandlerData);
        }
      }
    });
  }

  // PREV button
  if (prevBtn) {
    prevBtn.addEventListener("click", () => {
      if (currentStep > 0) {
        const isLoginOrRegister =
          steps[currentStep].querySelector("#login-email") ||
          steps[currentStep].querySelector("#patient-password");

        currentStep = isLoginOrRegister ? currentStep - 2 : currentStep - 1;
        showStepWrapper(currentStep);
      }
    });
  }

  // Initial render
  showStepWrapper(currentStep);
}
