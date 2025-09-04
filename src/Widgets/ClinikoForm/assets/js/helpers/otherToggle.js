export function bindOtherToggle(form) {
  if (!form) return;

  form.querySelectorAll("input.other-toggle").forEach(cb => {
    cb.addEventListener("change", () => {
      const targetId = cb.getAttribute("data-other-target");
      const wrap = targetId ? document.getElementById(targetId) : null;
      if (!wrap) return;

      const textInput = wrap.querySelector('input[type="text"]');
      const isRequiredGroup = cb.hasAttribute("data-required");

      if (cb.checked) {
        wrap.style.display = "block";
        cb.setAttribute("aria-expanded", "true");
        if (isRequiredGroup && textInput) textInput.setAttribute("required", "required");
        if (textInput) textInput.focus();
      } else {
        wrap.style.display = "none";
        cb.setAttribute("aria-expanded", "false");
        if (textInput) {
          textInput.removeAttribute("required");
          textInput.value = "";
        }
      }
    });
  });
}
