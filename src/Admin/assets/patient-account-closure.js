(function () {
  "use strict";

  const focusableSelector = [
    "a[href]",
    "button:not([disabled])",
    "input:not([disabled]):not([type='hidden'])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    "[tabindex]:not([tabindex='-1'])",
  ].join(",");

  document.querySelectorAll("[data-cliniko-account-closure-modal]").forEach((root) => {
    const trigger = root.querySelector("[data-account-closure-open]");
    const overlay = root.querySelector("[data-account-closure-overlay]");
    const dialog = root.querySelector("[data-account-closure-dialog]");
    const closers = root.querySelectorAll("[data-account-closure-close]");
    if (!trigger || !overlay || !dialog) return;

    let previouslyFocused = null;

    const open = () => {
      previouslyFocused = document.activeElement;
      overlay.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
      document.body.classList.add("cliniko-account-closure-modal-open");
      window.requestAnimationFrame(() => dialog.focus());
    };

    const close = () => {
      overlay.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
      document.body.classList.remove("cliniko-account-closure-modal-open");
      if (previouslyFocused && typeof previouslyFocused.focus === "function") {
        previouslyFocused.focus();
      } else {
        trigger.focus();
      }
    };

    trigger.addEventListener("click", open);
    closers.forEach((closer) => closer.addEventListener("click", close));
    dialog.addEventListener("click", (event) => event.stopPropagation());

    root.addEventListener("keydown", (event) => {
      if (overlay.hidden) return;
      if (event.key === "Escape") {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== "Tab") return;

      const focusable = Array.from(dialog.querySelectorAll(focusableSelector));
      if (focusable.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    if (root.dataset.autoOpen === "true") open();
  });
})();
