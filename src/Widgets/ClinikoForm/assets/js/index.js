import { mountForm } from "./form/mountForm.js";

/**
 * Parse the JSON config injected by Widget.php
 * (script tag with id="formHandlerData")
 */
function loadFormHandlerData() {
  const configEl = document.getElementById("formHandlerData");
  if (!configEl) {
    console.error("formHandlerData script tag not found.");
    return {};
  }

  try {
    return JSON.parse(configEl.textContent);
  } catch (err) {
    console.error("Failed to parse formHandlerData JSON:", err);
    return {};
  }
}

// Bootstrap when DOM is ready
document.addEventListener("DOMContentLoaded", () => {
  const formHandlerData = loadFormHandlerData();
  mountForm(formHandlerData);
});
