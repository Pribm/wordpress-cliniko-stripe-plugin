export function attachValidationListeners(form) {
  if (!form) return;

  // Text / textarea inputs
  form.querySelectorAll("input[required], textarea[required]").forEach(input => {
    input.addEventListener("input", () => {
      input.style.borderColor = "";
      const parent =
        input.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
        input.parentElement;
      const existingError = parent.querySelector(".field-error");
      if (existingError) existingError.remove();
    });
  });

  // Radio buttons
  form.querySelectorAll("input[type='radio'][required]").forEach(radio => {
    radio.addEventListener("change", () => {
      const group = form.querySelectorAll(`input[name="${radio.name}"]`);
      group.forEach(el => {
        el.style.borderColor = "";
        const parent =
          el.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
          el.parentElement;
        const existingError = parent.querySelector(".field-error");
        if (existingError) existingError.remove();
      });
    });
  });

  // Required checkbox groups
  form.querySelectorAll("[data-required-group]").forEach(groupContainer => {
    const groupName = groupContainer.getAttribute("data-required-group");
    const checkboxes = groupContainer.querySelectorAll(
      `input[name="${groupName}[]"]`
    );
    checkboxes.forEach(cb => {
      cb.addEventListener("change", () => {
        const hasChecked = [...checkboxes].some(c => c.checked);
        if (hasChecked) {
          checkboxes.forEach(c => {
            c.style.outline = "none";
            const parent =
              c.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
              c.parentElement;
            const existingError = parent.querySelector(".field-error");
            if (existingError) existingError.remove();
          });
        }
      });
    });
  });
}


export function isCurrentStepValid(steps, currentStep) {
  const currentFields = steps[currentStep].querySelectorAll(
    "[required], [data-required-group]"
  );
  let isValid = true;

  for (let field of currentFields) {
    const parent =
      field.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
      field.parentElement;
    const existingError = parent.querySelector(".field-error");
    if (existingError) existingError.remove();

    // Checkbox groups
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

    // Radio groups
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

    // Medicare number (10 digits)
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

    // Medicare reference number (1–9)
    if (field.name === "patient[medicare_reference_number]") {
      const clean = value.replace(/\D/g, "");
      if (!/^[1-9]$/.test(clean)) {
        field.style.borderColor = "red";
        isValid = false;
        const msg = document.createElement("div");
        msg.className = "field-error";
        msg.textContent = "Medicare reference number must be a single digit between 1 and 9.";
        parent.appendChild(msg);
        continue;
      }
      field.style.borderColor = "";
      continue;
    }

    // Password + confirmation
    if (
      field.name === "patient[password]" ||
      field.name === "patient[password_confirmation]"
    ) {
      const passField = steps[currentStep].querySelector('input[name="patient[password]"]');
      const confirmField = steps[currentStep].querySelector('input[name="patient[password_confirmation]"]');
      if (passField && confirmField) {
        const passVal = passField.value.trim();
        const confirmVal = confirmField.value.trim();

        if (!passVal || !confirmVal) {
          [passField, confirmField].forEach((f) => (f.style.borderColor = "red"));
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Password and confirmation are required.";
          if (!parent.querySelector(".field-error")) parent.appendChild(msg);
        } else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(passVal)) {
          [passField, confirmField].forEach((f) => (f.style.borderColor = "red"));
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Password must be at least 8 chars, include uppercase, lowercase, number and special character.";
          if (!parent.querySelector(".field-error")) parent.appendChild(msg);
        } else if (passVal !== confirmVal) {
          [passField, confirmField].forEach((f) => (f.style.borderColor = "red"));
          isValid = false;
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Passwords do not match.";
          if (!parent.querySelector(".field-error")) parent.appendChild(msg);
        } else {
          [passField, confirmField].forEach((f) => (f.style.borderColor = ""));
        }
      }
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

