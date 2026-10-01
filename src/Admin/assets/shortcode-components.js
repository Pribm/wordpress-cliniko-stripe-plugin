(function () {
  const settings = window.ClinikoComponentSettings || {};

  const review = {
    init(root) {
      if (!root || root.dataset.clinikoReviewInitialized === "1") return;
      root.dataset.clinikoReviewInitialized = "1";
      root.querySelectorAll("[data-question-type] input, [data-question-type] select, [data-question-type] textarea").forEach((control) => {
        control.disabled = true;
        control.dataset.renewalLocked = "1";
      });
      root.querySelectorAll("[data-question-type]").forEach((field) => field.classList.add("is-reviewing"));
      root.querySelectorAll("[data-renewal-edit]").forEach((button) => {
        button.addEventListener("click", () => {
          const field = button.closest("[data-question-type]");
          if (!field) return;
          if (settings.review?.edit_mode === "single") {
            root.querySelectorAll("[data-question-type].is-editing").forEach((other) => {
              if (other === field) return;
              other.querySelectorAll("input,select,textarea").forEach((control) => {
                control.disabled = true;
                control.dataset.renewalLocked = "1";
              });
              other.classList.remove("is-editing");
              other.classList.add("is-reviewing");
              const edit = other.querySelector("[data-renewal-edit]");
              if (edit) edit.hidden = false;
            });
          }
          field.querySelectorAll("input,select,textarea").forEach((control) => {
            control.disabled = false;
            control.removeAttribute("data-renewal-locked");
          });
          button.hidden = true;
          field.classList.add("is-editing");
          field.classList.remove("is-reviewing");
          field.querySelector("input:not([type='hidden']),select,textarea")?.focus();
        });
      });
    },
  };

  const steps = {
    afterChange(root) {
      if (!root) return;
      if (settings.steps?.scroll_to_top && root.dataset.clinikoStepsRendered === "1") {
        root.scrollIntoView({ behavior: "smooth", block: "start" });
      }
      root.dataset.clinikoStepsRendered = "1";
    },
    init(form) {
      if (!form || form.dataset.multistep !== "yes" || form.dataset.stepsInitialized === "1") return;
      const items = Array.from(form.querySelectorAll("[data-booking-step]"));
      if (items.length < 2) return;
      form.dataset.stepsInitialized = "1";
      let index = 0;
      const controls = document.createElement("div");
      const back = document.createElement("button");
      const next = document.createElement("button");
      const submit = form.querySelector("[type='submit']");
      controls.className = "cliniko-booking-step-controls cliniko-component-step-controls";
      controls.dataset.bookingStepControls = "1";
      back.type = next.type = "button";
      back.className = "cliniko-booking-step-controls__back";
      next.className = "cliniko-booking-step-controls__next";
      back.textContent = "Back";
      next.textContent = "Next";
      controls.append(back, next);
      submit?.parentNode?.insertBefore(controls, submit);
      const progress = form.querySelector("[data-booking-progress]");
      let summary = null;
      let track = null;
      let fill = null;
      if (progress) {
        summary = document.createElement("p");
        summary.className = "cliniko-booking-progress-summary";
        progress.parentNode.insertBefore(summary, progress);
        track = document.createElement("div");
        track.className = "cliniko-booking-progress-track";
        fill = document.createElement("span");
        fill.className = "cliniko-booking-progress-fill";
        track.appendChild(fill);
        progress.parentNode.insertBefore(track, progress);
        items.forEach((step, stepIndex) => {
          const item = document.createElement("li");
          item.textContent = step.dataset.stepLabel || `Step ${stepIndex + 1}`;
          item.dataset.step = String(stepIndex);
          progress.appendChild(item);
        });
      }
      const render = () => {
        items.forEach((step, stepIndex) => {
          const active = stepIndex === index;
          step.hidden = !active;
          step.style.display = active ? "" : "none";
          step.setAttribute("aria-hidden", active ? "false" : "true");
        });
        back.hidden = index === 0;
        back.style.display = index === 0 ? "none" : "";
        back.disabled = index === 0;
        next.hidden = index === items.length - 1;
        next.style.display = index === items.length - 1 ? "none" : "";
        if (submit) {
          submit.hidden = index !== items.length - 1;
          submit.style.display = index === items.length - 1 ? "" : "none";
        }
        progress?.querySelectorAll("[data-step]").forEach((item, itemIndex) => {
          item.classList.toggle("is-active", itemIndex === index);
          item.classList.toggle("is-complete", itemIndex < index);
          item.setAttribute("aria-current", itemIndex === index ? "step" : "false");
        });
        if (summary) summary.textContent = `Step ${index + 1} of ${items.length}: ${items[index].dataset.stepLabel || "Current step"}`;
        if (fill) fill.style.width = `${items.length > 1 ? (index / (items.length - 1)) * 100 : 100}%`;
        steps.afterChange(form);
      };
      const valid = (step) => {
        const invalid = Array.from(step.querySelectorAll("input,select,textarea")).find((control) => !control.checkValidity());
        if (!invalid) return true;
        if (invalid.matches("[data-booking-time]")) {
          const hint = form.querySelector("[data-booking-times-hint]");
          if (hint) hint.textContent = "Select an available time to continue.";
          form.querySelector("[data-booking-times-panel]")?.focus();
          return false;
        }
        invalid.reportValidity();
        return false;
      };
      next.addEventListener("click", () => { if (valid(items[index])) { index = Math.min(index + 1, items.length - 1); render(); } });
      back.addEventListener("click", () => { index = Math.max(index - 1, 0); render(); });
      render();
    },
  };

  window.ClinikoComponents = Object.assign(window.ClinikoComponents || {}, { settings, review, steps });
})();
