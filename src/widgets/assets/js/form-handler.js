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
        const questions = section.questions.map((q) => {
          let answer = null;

          if (q.type === "checkboxes") {
            answer = formData.getAll(q.name + "[]");
          } else if (q.type === "radiobuttons") {
            answer = formData.get(q.name);
          } else {
            answer = formData.get(q.name);
          }

          return {
            answer: answer,
            name: q.name,
            required: !!q.required,
            type: q.type,
          };
        });

        return {
          name: section.name,
          description: section.description,
          questions: questions,
        };
      }),
    },
    ...extractNestedFields(formEl, "patient")
  };

  return structured;
}
(function () {
  let currentStep = 0;
  let stripeInitStarted = false;

  const steps = document.querySelectorAll(".form-step");
  const indicators = document.querySelectorAll(".progress-step");
  const nextBtn = document.getElementById("step-next");
  const prevBtn = document.getElementById("step-prev");
  const activeColor = formHandlerData.btn_bg;
  const inactiveColor = "#ccc";

  function updateIndicators(index) {
    indicators.forEach((el, i) => {
      el.style.backgroundColor = i === index ? activeColor : inactiveColor;
    });
  }

  function showStep(i) {
    steps.forEach((step, index) => {
      step.style.display = index === i ? "block" : "none";
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
