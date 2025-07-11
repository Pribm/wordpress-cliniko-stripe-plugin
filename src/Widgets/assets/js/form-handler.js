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

(function () {
  setupSignatureCanvas();
  let currentStep = 0;
  let stripeInitStarted = false;

  const steps = document.querySelectorAll(".form-step");
  const indicators = document.querySelectorAll(".progress-step");
  const nextBtn = document.getElementById("step-next");
  const prevBtn = document.getElementById("step-prev");
  const activeColor = formHandlerData.btn_bg;
  const inactiveColor = "white";

  function updateIndicators(index) {
    indicators.forEach((el, i) => {
      el.style.backgroundColor = i === index ? activeColor : inactiveColor;
      el.style.color = i === index ? "white" : activeColor;
      el.style.border = i === index ? "" : "solid "+activeColor+" 1px";
    });
  }

  function showStep(i) {
    window.scrollTo({top: 0, behavior: "smooth"})
    steps.forEach((step, index) => {
      if(index === i){
        step.style.display = "block"
      }else{
        step.style.display = "none"
      }
    });
    prevBtn.style.display = i === 0 ? "none" : "inline-block";
    nextBtn.textContent = i === steps.length - 1 ? "Submit" : "Next";
    updateIndicators(i);
  }

  function isCurrentStepValid() {
    const currentFields = steps[currentStep].querySelectorAll("[required]");
    for (let field of currentFields) {
      if (
        !field.value ||
        (field.type === "radio" &&
          !document.querySelector(`input[name="${field.name}"]:checked`))
      ) {
        field.style.borderColor = "red";
        return false;
      }
    }
    return true;
  }

  nextBtn.addEventListener("click", () => {
    if (currentStep < steps.length - 1) {
      if (!isCurrentStepValid()) {
        alert("Please fill in all required fields.");
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
        alert("Please fill in all required fields.");
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
  });

  showStep(currentStep);
})();

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
