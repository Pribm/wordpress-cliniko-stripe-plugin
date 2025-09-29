function extractNestedFields(form, parentKey) {
  const formData = new FormData(form);
  const result = {};

  for (const [key, value] of formData.entries()) {
    const match = key.match(new RegExp(`^${parentKey}\\[([^\\]]+)\\]$`));

    if (match) {
      const field = match[1];
      if (!result[parentKey]) {
        result[parentKey] = {};
      }
      result[parentKey][field] = value;
    }
  }

  return result;
}

function parseFormToStructuredBody(formEl) {
  const formData = new FormData(formEl);
  const sectionsData = formHandlerData.sections || [];

  const structured = {
    content: {
      sections: sectionsData
        .map((section) => {
          const questions = section.questions
            .map((q) => {
              const question = {
                name: q.name,
                type: q.type,
                required: !!q.required,
              };

              // CHECKBOXES
              if (q.type === "checkboxes" && Array.isArray(q.answers)) {
                const rawSelected = formData.getAll(q.name + "[]") || [];

                // include all options; mark only selected
                question.answers = q.answers.map((opt) => {
                  const entry = { value: opt.value };
                  if (rawSelected.includes(opt.value)) entry.selected = true;
                  return entry;
                });

                if (q.other?.enabled) {
                  const otherChecked =
                    rawSelected.includes("__other__") ||
                    rawSelected.includes("other");
                  const otherValue = (
                    formData.get(q.name + "_other") || ""
                  ).trim();

                  question.other = otherChecked
                    ? { value: otherValue, enabled: true, selected: true } // value may be "" (allowed)
                    : { enabled: true };
                }

                // RADIOBUTTONS
              } else if (
                q.type === "radiobuttons" &&
                Array.isArray(q.answers)
              ) {
                const selected = formData.get(q.name);

                question.answers = q.answers.map((opt) => {
                  const entry = { value: opt.value };
                  if (selected === opt.value) entry.selected = true;
                  return entry;
                });

                if (q.other?.enabled) {
                  const isOther =
                    selected === "__other__" || selected === "other";
                  const otherValue = (
                    formData.get(q.name + "_other") || ""
                  ).trim();
                  question.other = isOther
                    ? { value: otherValue, enabled: true, selected: true }
                    : { enabled: true };
                }

                // SIMPLE INPUTS
              } else {
                question.answer = formData.get(q.name);
              }

              return question;
            })
            .filter((q) => {
              // ✅ keep original behavior: drop signature questions
              if (q.type === "signature") return false;

              // keep original “empty question” guards
              if (
                q.answers &&
                Array.isArray(q.answers) &&
                q.answers.length === 0
              )
                return false;
              if (
                "answer" in q &&
                typeof q.answer === "string" &&
                q.answer.trim() === ""
              )
                return false;

              return true;
            });

          return {
            name: section.name,
            description: section.description,
            questions,
          };
        })
        .filter((section) => section.questions.length > 0),
    },
    ...extractNestedFields(formEl, "patient"),
  };

  return structured;
}

function showToast(message, type = "error") {
  const isSuccess = type === "success";

  const icon = isSuccess
    ? `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#2e7d32" viewBox="0 0 24 24">
         <path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
       </svg>`
    : `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#f44336" viewBox="0 0 24 24">
         <path d="M1 21h22L12 2 1 21zm13-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
       </svg>`;

  const bgColor = isSuccess ? "#f1f8f4" : "#fff";
  const borderColor = isSuccess ? "#c8e6c9" : "#eee";
  const textColor = isSuccess ? "#2e7d32" : "#333";

  Toastify({
    text: `
      <div style="
        display: flex;
        align-items: center;
        gap: 12px;
      ">
        ${icon}
        <span style="
          color: ${textColor};
          font-size: 14px;
          font-weight: 500;
        ">${message}</span>
      </div>
    `,
    duration: 4000,
    gravity: "bottom",
    position: "left",
    stopOnFocus: true,
    escapeMarkup: false,
    style: {
      background: bgColor,
      border: `1px solid ${borderColor}`,
      boxShadow: "0 2px 8px rgba(0,0,0,0.08)",
      borderRadius: "8px",
      padding: "12px 16px",
      minWidth: "260px",
      maxWidth: "360px",
    },
  }).showToast();
}

function bindOtherToggle(form) {
  if (!form) return;

  const onToggle = (cb) => {
    const targetId = cb.getAttribute("data-other-target");
    const wrap = targetId ? document.getElementById(targetId) : null;
    console.log("toggle dat wrap", wrap);
    if (!wrap) return;

    const textInput = wrap.querySelector('input[type="text"]');
    const isRequiredGroup = cb.hasAttribute("data-required");

    if (cb.checked) {
      wrap.style.display = "block";
      cb.setAttribute("aria-expanded", "true");
      if (isRequiredGroup && textInput)
        textInput.setAttribute("required", "required");
      if (textInput) textInput.focus();
    } else {
      wrap.style.display = "none";
      cb.setAttribute("aria-expanded", "false");
      if (textInput) {
        textInput.removeAttribute("required");
        textInput.value = "";
      }
    }
  };

  form.querySelectorAll("input.other-toggle").forEach((cb) => {
    cb.addEventListener("change", () => onToggle(cb));
  });
}
function mountForm() {
  const form = document.getElementById("prepayment-form");
  bindOtherToggle(form);
  setupSignatureCanvas();

  window.currentStep = 0;
  let stripeInitStarted = false;

  const steps = document.querySelectorAll(".form-step");
  const nextBtn = document.getElementById("step-next");
  if (nextBtn) {
    window.nextBtnLabel = nextBtn.innerHTML;
  }

  const prevBtn = document.getElementById("step-prev");
  const nextBtnLabel = nextBtn.innerHTML;

  // Keep Stripe references global to this form instance
  let stripeInstance = null;
  let cardElementInstance = null;
  let errorElementInstance = null;

  async function safeInitStripe() {
    // Already initialized and mounted?
    if (stripeInstance && cardElementInstance) {
      return;
    }
    const { stripe, cardElement, errorEl } = await initializeStripeElements();
    stripeInstance = stripe;
    cardElementInstance = cardElement;
    errorElementInstance = errorEl;
    handlePaymentAndFormSubmission(
      stripeInstance,
      cardElementInstance,
      errorElementInstance
    );
  }

  function updateIndicators(index) {
    const type = formHandlerData.appearance.progress_type;

    if (type === "steps") {
      document.querySelectorAll(".progress-step").forEach((el, i) => {
        el.classList.toggle("is-active", i === index);
      });
    } else if (type === "dots") {
      document.querySelectorAll(".progress-dot").forEach((el, i) => {
        el.classList.toggle("is-active", i === index);
      });
    } else if (type === "bar") {
      const total = steps.length;
      const fill = document.querySelector(".progress-fill");
      if (fill) {
        fill.style.width = ((index + 1) / total) * 100 + "%";
      }
    } else if (type === "fraction") {
      const text = document.querySelector(
        ".form-progress--fraction .progress-text"
      );
      if (text) {
        text.textContent = index + 1 + "/" + steps.length;
      }
    } else if (type === "percentage") {
      const text = document.querySelector(
        ".form-progress--percentage .progress-text"
      );
      if (text) {
        text.textContent = Math.round(((index + 1) / steps.length) * 100) + "%";
      }
    }
  }

  function showStep(i) {
    window.scrollTo({ top: 0, behavior: "smooth" });
    steps.forEach((step, index) => {
      step.style.display = index === i ? "block" : "none";
    });
    prevBtn.style.display = i === 0 ? "none" : "flex";
    if (i === steps.length - 1) {
      nextBtn.textContent = "Submit";
    }
    updateIndicators(i);
  }

  function isCurrentStepValid() {
    const currentFields = steps[window.currentStep].querySelectorAll(
      "[required], [data-required-group]"
    );
    let isValid = true;

    for (let field of currentFields) {
      const parent =
        field.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
        field.parentElement;
      const existingError = parent.querySelector(".field-error");
      if (existingError) existingError.remove();

      // Required checkbox group
      if (field.hasAttribute("data-required-group")) {
        const groupName = field.getAttribute("data-required-group");
        const groupInputs = field.querySelectorAll(
          `input[name="${groupName}[]"]`
        );
        const isChecked = Array.from(groupInputs).some(
          (input) => input.checked
        );
        if (!isChecked) {
          groupInputs.forEach(
            (input) => (input.style.outline = "2px solid red")
          );
          isValid = false;
          if (!existingError) {
            const msg = document.createElement("div");
            msg.className = "field-error";
            msg.textContent = "Please select at least one option.";
            field.appendChild(msg);
          }
        } else {
          groupInputs.forEach((input) => (input.style.outline = "none"));
        }
        continue;
      }

      // Required radio
      if (
        field.type === "radio" &&
        !document.querySelector(`input[name="${field.name}"]:checked`)
      ) {
        field.style.borderColor = "red";
        isValid = false;
        if (!existingError) {
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Please select an option.";
          parent.appendChild(msg);
        }
        continue;
      }

      const value = field.value.trim();

      // Email
      if (field.type === "email") {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
          field.style.borderColor = "red";
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Please enter a valid email address.";
          parent.appendChild(msg);
          continue;
        }
        field.style.borderColor = "";
        continue;
      }

      // Phone (AU: 10 digits)
      if (field.name === "patient[phone]") {
        const clean = value.replace(/\D/g, "");
        if (clean.length !== 10) {
          field.style.borderColor = "red";
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Phone number must be 10 digits.";
          parent.appendChild(msg);
          continue;
        }
        field.style.borderColor = "";
        continue;
      }

      // Postcode (4 digits)
      if (field.name === "patient[post_code]") {
        if (!/^\d{4}$/.test(value)) {
          field.style.borderColor = "red";
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Please enter a 4-digit postcode.";
          parent.appendChild(msg);
          continue;
        }
        field.style.borderColor = "";
        continue;
      }

      // Medicare number (10 digits, ignoring spaces)
      if (field.name === "patient[medicare]") {
        const clean = value.replace(/\D/g, "");
        if (clean.length !== 10) {
          field.style.borderColor = "red";
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Medicare number must contain exactly 10 digits.";
          parent.appendChild(msg);
          continue;
        }
        field.style.borderColor = "";
        continue;
      }

      // Medicare reference number (1 digit, 1–9)
      if (field.name === "patient[medicare_reference_number]") {
        const clean = value.replace(/\D/g, "");
        if (!/^[1-9]$/.test(clean)) {
          field.style.borderColor = "red";
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent =
            "Medicare reference number must be a single digit between 1 and 9.";
          parent.appendChild(msg);
          continue;
        }
        field.style.borderColor = "";
        continue;
      }

      // General required
      if (!value) {
        field.style.borderColor = "red";
        isValid = false;
        const msg = document.createElement("div");
        msg.className = "field-error";
        msg.textContent = "This field is required.";
        parent.appendChild(msg);
      } else {
        field.style.borderColor = "";
      }
    }
    return isValid;
  }

  nextBtn.addEventListener("click", async () => {
    const isPaymentEnabled = Boolean(formHandlerData.is_payment_enabled);

    if (window.currentStep < steps.length - 1) {
      if (!isCurrentStepValid()) {
        showToast("Please review the highlighted fields before continuing.");
        return;
      }

      if (
        isPaymentEnabled &&
        window.currentStep === steps.length - 2 &&
        !stripeInitStarted
      ) {
        stripeInitStarted = true;
        await safeInitStripe();
      }

      window.currentStep++;
      showStep(window.currentStep);
    } else {
      if (!isCurrentStepValid()) {
        showToast("Please review the highlighted fields before continuing.");
        return;
      }

      if (isPaymentEnabled) {
        document.getElementById("prepayment-form").style.display = "none";
        document.getElementById("payment_form").style.display = "flex";
        await safeInitStripe();

        const backBtn = document.getElementById("go-back-button");
        if (backBtn) {
          backBtn.addEventListener("click", () => {
            document.getElementById("prepayment-form").style.display = "block";
            document.getElementById("payment_form").style.display = "none";
            showStep(window.currentStep);
          });
        }
      } else {
        showPaymentLoader();
        await submitBookingForm();
      }
    }
  });

  prevBtn.addEventListener("click", () => {
    if (window.currentStep > 0) {
      window.currentStep--;
      showStep(window.currentStep);
    }
    if (window.currentStep + 1 < steps.length) {
      nextBtn.innerHTML = nextBtnLabel;
    }
  });

  showStep(window.currentStep);

  function attachValidationListeners() {
    document
      .querySelectorAll(
        "#prepayment-form input[required], #prepayment-form textarea[required]"
      )
      .forEach((input) => {
        input.addEventListener("input", () => {
          input.style.borderColor = "";
          const parent =
            input.closest(
              ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
            ) || input.parentElement;
          const existingError = parent.querySelector(".field-error");
          if (existingError) existingError.remove();
        });
      });

    // Radio buttons
    document
      .querySelectorAll("#prepayment-form input[type='radio'][required]")
      .forEach((radio) => {
        radio.addEventListener("change", () => {
          const group = document.getElementsByName(radio.name);
          group.forEach((el) => {
            el.style.borderColor = "";
            const parent =
              el.closest(
                ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
              ) || el.parentElement;
            const existingError = parent.querySelector(".field-error");
            if (existingError) existingError.remove();
          });
        });
      });

    // Required checkbox groups
    document
      .querySelectorAll("[data-required-group]")
      .forEach((groupContainer) => {
        const groupName = groupContainer.getAttribute("data-required-group");
        const checkboxes = groupContainer.querySelectorAll(
          `input[name="${groupName}[]"]`
        );
        checkboxes.forEach((cb) => {
          cb.addEventListener("change", () => {
            const hasChecked = [...checkboxes].some((c) => c.checked);
            if (hasChecked) {
              checkboxes.forEach((c) => {
                c.style.outline = "none";
                const parent =
                  c.closest(
                    ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
                  ) || c.parentElement;
                const existingError = parent.querySelector(".field-error");
                if (existingError) existingError.remove();
              });
            }
          });
        });
      });
  }

  attachValidationListeners();
}

async function submitBookingForm(stripeToken = null, errorEl = null) {
  const formElement = document.getElementById("prepayment-form");
  const { content, patient } = parseFormToStructuredBody(formElement);

  // Build JSON payload for the new charge route
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

    if (result.status === "success") {
      const amount = result.payment?.amount ?? 0;

      if (amount > 0 && result.payment?.id) {
        showToast(
          "Payment received! We’re scheduling your appointment now…",
          "success"
        );
      } else {
        showToast(
          "No payment required. We’re scheduling your appointment now…",
          "success"
        );
      }

      window.formIsSubmitting = true;

      const redirectBase = formHandlerData.redirect_url;
      const queryParams = new URLSearchParams({
        patient_name:
          patient?.first_name && patient?.last_name
            ? `${patient.first_name} ${patient.last_name}`
            : "",
        email: patient?.email ?? "",
        ref: result.payment?.id ?? "free", // Stripe charge id or "free"
        status: "scheduling_queued",
        receipt: result.payment?.receipt_url ?? "",
      });

      window.location.href = `${redirectBase}?${queryParams.toString()}`;
    } else {
      // Errors from payment route
      handleChargeErrors(result, errorEl);
    }
  } catch (err) {
    console.error("Payment request failed", err);
    const message = "Unexpected error. Please try again.";
    if (errorEl) errorEl.textContent = message;
    else showToast(message);
  } finally {
    jQuery.LoadingOverlay("hide");
  }
}

// Helper to render payment errors (mirrors your existing UI pattern)
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
      result.errors.forEach((e) => {
        const li = document.createElement("li");
        li.textContent = `${e.label || "Error"}: ${
          e.detail || e.code || "Unknown"
        }`;
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

function showPaymentLoader() {
  const styles = formHandlerData.appearance?.variables || {};
  const logo = formHandlerData.logo_url;

  jQuery.LoadingOverlay("show", {
    image: "",
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
        font-family: ${styles.fontFamily || "sans-serif"};
        color: ${styles.colorText || "#333"};
      ">
        ${
          logo
            ? `<img src="${logo}" alt="Logo" style="max-height: 60px; margin-bottom: 20px;" class="pulse-logo" />`
            : ""
        }
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
          Processing your secure payment...
        </div>
        <div style="font-size: 14px; color: #666;">
          Please wait while we confirm your appointment with the clinic.
        </div>
      </div>
    `),
  });

  if (!document.getElementById("pulse-logo-style")) {
    const style = document.createElement("style");
    style.id = "pulse-logo-style";
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

function setupSignatureCanvas() {
  const canvas = document.getElementById("signature-pad");
  const clearBtn = document.getElementById("clear-signature");
  const signatureDataInput = document.getElementById("signature-data");

  if (!canvas || !clearBtn || !signatureDataInput) return;

  const ctx = canvas.getContext("2d");
  let drawing = false;

  ctx.strokeStyle = "#000";
  ctx.lineWidth = 2;
  ctx.lineCap = "round";

  // Desktop: mouse
  canvas.addEventListener("mousedown", (e) => {
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(getX(e), getY(e));
  });

  canvas.addEventListener("mousemove", (e) => {
    if (!drawing) return;
    ctx.lineTo(getX(e), getY(e));
    ctx.stroke();
  });

  canvas.addEventListener("mouseup", () => {
    drawing = false;
    ctx.closePath();
    signatureDataInput.value = canvas.toDataURL("image/png");
  });

  canvas.addEventListener("mouseleave", () => {
    if (drawing) {
      drawing = false;
      ctx.closePath();
      signatureDataInput.value = canvas.toDataURL("image/png");
    }
  });

  // Mobile: touch
  canvas.addEventListener("touchstart", (e) => {
    e.preventDefault();
    drawing = true;
    const touch = e.touches[0];
    ctx.beginPath();
    ctx.moveTo(getTouchX(touch), getTouchY(touch));
  });

  canvas.addEventListener("touchmove", (e) => {
    e.preventDefault();
    if (!drawing) return;
    const touch = e.touches[0];
    ctx.lineTo(getTouchX(touch), getTouchY(touch));
    ctx.stroke();
  });

  canvas.addEventListener("touchend", () => {
    drawing = false;
    ctx.closePath();
    signatureDataInput.value = canvas.toDataURL("image/png");
  });

  clearBtn.addEventListener("click", () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    signatureDataInput.value = "";
  });

  function getX(e) {
    const rect = canvas.getBoundingClientRect();
    return e.clientX - rect.left;
  }

  function getY(e) {
    const rect = canvas.getBoundingClientRect();
    return e.clientY - rect.top;
  }

  function getTouchX(touch) {
    const rect = canvas.getBoundingClientRect();
    return touch.clientX - rect.left;
  }

  function getTouchY(touch) {
    const rect = canvas.getBoundingClientRect();
    return touch.clientY - rect.top;
  }
}

document.addEventListener("DOMContentLoaded", () => mountForm());
