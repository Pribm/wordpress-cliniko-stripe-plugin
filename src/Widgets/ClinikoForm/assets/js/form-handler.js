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
      sections: sectionsData.map((section) => {
        const questions = section.questions
          .map((q) => {
            const question = {
              name: q.name,
              required: !!q.required,
              type: q.type,
            };

            if (q.type === "checkboxes" && Array.isArray(q.answers)) {
              const selectedValues = formData.getAll(q.name + "[]") || [];

              question.answers = q.answers
                .filter((opt) => selectedValues.includes(opt.value))
                .map((opt) => ({
                  value: opt.value,
                  selected: true,
                }));

              if (q.other?.enabled) {
                const otherValue = formData.get(q.name + "_other");
                if (otherValue) {
                  question.other = { value: otherValue };
                }
              }
            } else if (q.type === "radiobuttons" && Array.isArray(q.answers)) {
              const selected = formData.get(q.name);
              question.answers = q.answers
                .filter((opt) => selected === opt.value)
                .map((opt) => ({
                  value: opt.value,
                  selected: true,
                }));

              if (q.other?.enabled) {
                const otherValue = formData.get(q.name + "_other");
                if (otherValue && selected === "other") {
                  question.other = { value: otherValue };
                }
              }
            } else {
              question.answer = formData.get(q.name);
            }

            return question;
          })
          .filter((q) => {
            if (q.type === "signature") return false;
            if (q.answers && Array.isArray(q.answers) && q.answers.length === 0)
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
          questions: questions,
        };
      }),
    },
    ...extractNestedFields(formEl, "patient"),
  };

  return structured;
}

function showToast(message) {
  Toastify({
    text: `
      <div style="
        display: flex;
        align-items: center;
        gap: 12px;
      ">
        <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#f44336" viewBox="0 0 24 24">
          <path d="M1 21h22L12 2 1 21zm13-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
        </svg>
        <span style="
          color: #333;
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
      background: "#fff",
      border: "1px solid #eee",
      boxShadow: "0 2px 8px rgba(0,0,0,0.08)",
      borderRadius: "8px",
      padding: "12px 16px",
      minWidth: "260px",
      maxWidth: "360px",
    },
  }).showToast();
}

function mountForm() {
  setupSignatureCanvas();
  let currentStep = 0;
  let stripeInitStarted = false;

  const steps = document.querySelectorAll(".form-step");
  const indicators = document.querySelectorAll(".progress-step");
  const nextBtn = document.getElementById("step-next");

  let nextBtnLabel = nextBtn.innerHTML;

  const prevBtn = document.getElementById("step-prev");
  const activeColor = formHandlerData.btn_bg;
  const inactiveColor = "white";

  function updateIndicators(index) {
    indicators.forEach((el, i) => {
      el.style.backgroundColor = i === index ? activeColor : inactiveColor;
      el.style.color = i === index ? "white" : activeColor;
      el.style.border = i === index ? "" : "solid " + activeColor + " 1px";
    });
  }

  function showStep(i) {
    window.scrollTo({ top: 0, behavior: "smooth" });
    steps.forEach((step, index) => {
      if (index === i) {
        step.style.display = "block";
      } else {
        step.style.display = "none";
      }
    });
    prevBtn.style.display = i === 0 ? "none" : "flex";

    if (i === steps.length - 1) {
      nextBtn.textContent = "Submit";
    }
    updateIndicators(i);
  }

function isCurrentStepValid() {
  const currentFields = steps[currentStep].querySelectorAll(
    "[required], [data-required-group]"
  );
  let isValid = true;

  for (let field of currentFields) {
    const parent = field.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") || field.parentElement;
    const existingError = parent.querySelector(".field-error");
    if (existingError) existingError.remove();

    // Handle required checkbox group
    if (field.hasAttribute("data-required-group")) {
      const groupName = field.getAttribute("data-required-group");
      const groupInputs = field.querySelectorAll(`input[name="${groupName}[]"]`);
      const isChecked = Array.from(groupInputs).some((input) => input.checked);

      if (!isChecked) {
        groupInputs.forEach((input) => (input.style.outline = "2px solid red"));
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

    // Handle required radio buttons
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

    // Email validation
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

    // Masked phone validation
    if (field.name === "patient[phone]") {
      const clean = value.replace(/\D/g, "");
      if (clean.length < 10) {
        field.style.borderColor = "red";
        isValid = false;

        const msg = document.createElement("div");
        msg.className = "field-error";
        msg.textContent = "Please enter a valid phone number.";
        parent.appendChild(msg);
        continue;
      }
      field.style.borderColor = "";
      continue;
    }

    // Masked postcode validation
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

    // General required validation
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


  nextBtn.addEventListener("click", () => {
    if (currentStep < steps.length - 1) {
      if (!isCurrentStepValid()) {
        showToast("Please review the highlighted fields before continuing.");
        return;
      }

      // ✅ Pré-carrega o Stripe no penúltimo passo
      if (currentStep === steps.length - 2 && !stripeInitStarted) {
        stripeInitStarted = true;
        if (!window.stripe) initStripe();
      }

      currentStep++;
      showStep(currentStep);
    } else {
      if (!isCurrentStepValid()) {
        showToast("Please review the highlighted fields before continuing.");
        return;
      }

      document.getElementById("prepayment-form").style.display = "none";
      document.getElementById("payment_form").style.display = "flex";

      if (!window.stripe) initStripe();

      const backBtn = document.getElementById("go-back-button");

      if (backBtn) {
        backBtn.addEventListener("click", () => {
          document.getElementById("prepayment-form").style.display = "block";
          document.getElementById("payment_form").style.display = "none";
          showStep(currentStep);
        });
      }
    }
  });

  prevBtn.addEventListener("click", () => {
    if (currentStep > 0) {
      currentStep--;
      showStep(currentStep);
    }

    if ((currentStep + 1, steps.length - 1)) {
      nextBtn.innerHTML = nextBtnLabel;
    }
  });

  showStep(currentStep);

function attachValidationListeners() {
  document
    .querySelectorAll(
      "#prepayment-form input[required], #prepayment-form textarea[required]"
    )
    .forEach((input) => {
      input.addEventListener("input", () => {
        input.style.borderColor = "";

        const parent = input.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") || input.parentElement;
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
          const parent = el.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") || el.parentElement;
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
              const parent = c.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") || c.parentElement;
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
