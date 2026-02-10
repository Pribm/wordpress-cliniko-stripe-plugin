(function () {
  if (!saveOnExitData.save_on_exit) return;

  // ===== Config =====
  const FORM_ROOT_SEL = "#cliniko-form-steps";
  const PREV_BTN_SEL = "#step-prev";
  const NEXT_BTN_SEL = "#step-next";

  const formType = String(window.formHandlerData?.form_type || "multi").toLowerCase();
  if (formType === "headless") return;
  const isSingleStep = formType === "single" || formType === "unstyled";

  // ✅ Unique storage key per form/page
  const STORAGE_KEY = `clinikoFormProgress:v9:${window.location.pathname}`;

  const OVERRIDE_NAV_BUTTONS = !isSingleStep;

  // Theme colors (for modal, bar)
  const COLOR = {
    dark: "#2C5848",
    light: "#D7ECE4",
    mid: "#B2D8C8",
    midH: "#A6D0C0",
    ghost: "#e8f2f0",
    ghostH: "#D7ECE4",
    danger: "#f7e7e7",
    dangerH: "#eed3d3",
    txt: "#1e3d32",
    flash: "#fff7cc",
  };

  // ===== Utils =====
  const ready = (fn) =>
    document.readyState === "loading"
      ? document.addEventListener("DOMContentLoaded", fn)
      : fn();
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
  const debounce = (fn, ms = 250) => {
    let t;
    return (...a) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...a), ms);
    };
  };

  function getSteps(root) {
    return $$(".form-step", root);
  }
  function getActiveStepIndex(root) {
    if (isSingleStep) return 0;
    const steps = getSteps(root);
    if (!steps.length) return 0;
    let idx = steps.findIndex(
      (s) => s.style.display !== "none" && !s.classList.contains("is-hidden")
    );
    if (idx < 0) idx = steps.findIndex((s) => s.style.display !== "none");
    if (idx < 0)
      idx = steps.findIndex((s) => !s.classList.contains("is-hidden"));
    if (idx < 0) idx = 0;
    return clamp(idx, 0, steps.length - 1);
  }

  function progressTo(index, total) {
    if (isSingleStep) return;
    const bar = $("#form-progress-indicator .progress-fill");
    if (!bar || !total) return;
    bar.style.width = ((index + 1) / total) * 100 + "%";
  }

  function computeNavState(root) {
    if (isSingleStep) {
      return { idx: 0, total: 1, showPrev: false, showNext: true };
    }
    const idx = getActiveStepIndex(root);
    const total = getSteps(root).length;
    return { idx, total, showPrev: idx > 0, showNext: idx < total - 1 };
  }

  function syncNavButtons(root) {
    if (isSingleStep) return;
    const prev = $(PREV_BTN_SEL, document);
    const next = $(NEXT_BTN_SEL, document);
    const { idx, total } = computeNavState(root);

    if (OVERRIDE_NAV_BUTTONS) {
      if (prev) {
        prev.style.display = idx === 0 ? "none" : "flex";
        prev.setAttribute("aria-hidden", idx === 0 ? "true" : "false");
      }

      if (next && !isClinikoForm) {
        if (idx === total - 1) {
          next.textContent = "Submit";
        } else {
          if (typeof window.nextBtnLabel === "string") {
            next.innerHTML = window.nextBtnLabel;
          }
        }
        next.style.display = "flex";
        next.setAttribute("aria-hidden", "false");
      }
    }

    progressTo(idx, total);
    const evt = new CustomEvent("stepchange", {
      detail: { index: idx, total },
    });
    document.dispatchEvent(evt);
  }

  // ===== Save / Load =====
  function serializeForm(root) {
    const stepIndex =
      isSingleStep
        ? 0
        : typeof window.currentStep === "number"
        ? window.currentStep
        : getActiveStepIndex(root);

    const data = { values: {}, stepIndex, when: Date.now() };

    $$("input, textarea, select", root).forEach((el) => {
      if (!el.name) return;
      if (el.type === "checkbox") {
        const base = el.name.endsWith("[]") ? el.name.slice(0, -2) : el.name;
        data.values[base] ||= [];
        if (el.checked) data.values[base].push(el.value);
        return;
      }
      if (el.type === "radio") {
        if (el.checked) data.values[el.name] = el.value;
        else if (!(el.name in data.values)) data.values[el.name] = null;
        return;
      }
      data.values[el.name] = el.value;
    });

    const canvas = $("#signature-pad", root);
    const hidden = $("#signature-data", root);
    if (canvas && canvas.toDataURL) {
      try {
        data.signatureDataUrl = canvas.toDataURL("image/png");
      } catch (_) {}
    }
    if (hidden) data.values[hidden.name] = hidden.value || "";
    return data;
  }

  function applyValues(root, data) {
    if (!data || !data.values) return;
    const steps = getSteps(root);
    const target = clamp(data.stepIndex || 0, 0, Math.max(0, steps.length - 1));

    // ✅ Restore all saved fields
    const map = data.values;
    $$("input, textarea, select", root).forEach((el) => {
      if (!el.name) return;
      if (el.type === "checkbox") {
        const base = el.name.endsWith("[]") ? el.name.slice(0, -2) : el.name;
        const arr = map[base];
        if (Array.isArray(arr)) el.checked = arr.includes(el.value);
        return;
      }
      if (el.type === "radio") {
        const v = map[el.name];
        if (v != null) el.checked = el.value === v;
        return;
      }
      if (map.hasOwnProperty(el.name)) el.value = map[el.name];
    });

    // ✅ Restore signature if needed
    if (data.signatureDataUrl) {
      const canvas = $("#signature-pad", root),
        hidden = $("#signature-data", root);
      if (canvas) {
        const ctx = canvas.getContext("2d");
        const img = new Image();
        img.onload = () => {
          ctx.clearRect(0, 0, canvas.width, canvas.height);
          const sc = Math.min(
            canvas.width / img.width,
            canvas.height / img.height
          );
          const w = img.width * sc,
            h = img.height * sc;
          ctx.drawImage(
            img,
            (canvas.width - w) / 2,
            (canvas.height - h) / 2,
            w,
            h
          );
          if (hidden) hidden.value = data.signatureDataUrl;
        };
        img.src = data.signatureDataUrl;
      }
    }

    // ✅ Remember last step
    window.currentStep = target;

    // ✅ Tell your multistep form to actually go there
    if (typeof window.showStep === "function") {
      window.showStep(target); // this triggers your navigation logic
    } else {
      // fallback: simulate click if showStep not defined
      const steps = getSteps(root);
      steps.forEach((s, i) => {
        s.style.display = i === target ? "block" : "none";
        s.classList.toggle("is-hidden", i !== target);
      });
    }

    // ✅ Sync navigation and progress
    syncNavButtons(root);
    progressTo(target, steps.length);

    // optional: fire event for tracking
    document.dispatchEvent(
      new CustomEvent("restoreform", { detail: { step: target } })
    );

    setTimeout(() => syncNavButtons(root), 50);
    setTimeout(() => syncNavButtons(root), 300);
  }

  function saveAll(root) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(serializeForm(root)));
      return true;
    } catch (e) {
      return false;
    }
  }
  function loadSaved() {
    try {
      const r = localStorage.getItem(STORAGE_KEY);
      return r ? JSON.parse(r) : null;
    } catch (_) {
      return null;
    }
  }
  function clearSaved() {
    localStorage.removeItem(STORAGE_KEY);
  }

  // ===== Styles =====
  function ensureStyles() {
    if ($("#exit-save-modal-style")) return;
    const style = document.createElement("style");
    style.id = "exit-save-modal-style";
    style.textContent = `
      .exit-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:99999}
      .exit-modal{background:#fff;width:min(520px,92vw);border-radius:14px;padding:18px;box-shadow:0 8px 30px rgba(0,0,0,.2);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
      .exit-modal h3{margin:0 0 8px;font-size:18px;color:${COLOR.txt}}
      .exit-modal p{margin:0 0 16px;color:#333;font-size:14px;line-height:1.5}
      .exit-modal .row{display:flex;gap:8px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap}
      .exit-modal button,#restore-bar button{border:none;border-radius:10px;padding:8px 12px;font-weight:600;cursor:pointer;transition:background-color .15s ease,filter .15s ease}
      .btn-primary{background:${COLOR.mid};color:${COLOR.txt}} .btn-primary:hover{background:${COLOR.midH}}
      .btn-ghost{background:${COLOR.ghost};color:${COLOR.txt}} .btn-ghost:hover{background:${COLOR.ghostH}}
      .btn-danger{background:${COLOR.danger};color:#7a1f1f} .btn-danger:hover{background:${COLOR.dangerH}}
      #restore-bar{position:sticky;top:0;left:0;right:0;background:${COLOR.ghost};padding:10px 12px;z-index:9998;display:flex;align-items:center;justify-content:center;gap:8px;border-bottom:1px solid #dfe9e6;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
      #restore-bar .timestamp{color:${COLOR.dark};font-weight:600}
      .restored-flash{animation:restoredPulse 1.4s ease}
      @keyframes restoredPulse{0%{background-color:${COLOR.flash}}100%{background-color:transparent}}
    `;
    document.head.appendChild(style);
  }

  // ===== Modal =====
  function showExitModal() {
    ensureStyles();
    return new Promise((resolve) => {
      const backdrop = document.createElement("div");
      backdrop.className = "exit-modal-backdrop";
      backdrop.innerHTML = `
        <div class="exit-modal" role="dialog" aria-modal="true" aria-label="Save progress">
          <h3>Do you want to save your appointment details?</h3>
          <p>If you leave now, we can save your answers and the step you’re on so you can finish later.</p>
          <div class="row">
            <button class="btn-ghost"  data-action="cancel"       type="button">Stay on this page</button>
            <button class="btn-danger" data-action="leave-nosave" type="button">Leave without saving</button>
            <button class="btn-primary"data-action="save-leave"   type="button">Save & Leave</button>
          </div>
        </div>`;
      document.body.appendChild(backdrop);
      const finish = (a) => {
        backdrop.remove();
        resolve(a);
      };
      backdrop.addEventListener("click", (e) => {
        if (e.target === backdrop) finish("cancel");
      });
      backdrop
        .querySelectorAll("button")
        .forEach((btn) =>
          btn.addEventListener("click", () =>
            finish(btn.getAttribute("data-action"))
          )
        );
    });
  }

  // ===== Banner =====
  function showRestoreBar(saved, root) {
    ensureStyles();
    const bar = document.createElement("div");
    bar.id = "restore-bar";
    bar.innerHTML = `
      <span>We found your appointment form from 
        <span class="timestamp">${new Date(
          saved.when
        ).toLocaleString()}</span>. 
        You can continue from where you left off.
      </span>
      <button type="button" class="btn-primary" data-action="resume">Continue</button>
      <button type="button" class="btn-ghost"  data-action="discard">Start Over</button>`;
    document.body.insertBefore(bar, document.body.firstChild);

    bar.addEventListener("click", (e) => {
      const btn = e.target.closest("button");
      if (!btn) return;
      const action = btn.getAttribute("data-action");
      if (action === "resume") {
        applyValues(root, saved);
        showToast(
          "Your appointment form has been restored. You can continue where you left off.",
          "success"
        );
        bar.remove();
      }
      if (action === "discard") {
        clearSaved();
        showToast("We’ve cleared the previous details. You can start fresh.");
        bar.remove();
      }
    });
  }

  // ===== Observe step changes =====
  function observeStepChanges(root) {
    const steps = getSteps(root);
    const total = isSingleStep ? 1 : steps.length;
    const update = debounce(() => {
      let raw = loadSaved();
      if (!raw || typeof raw !== "object")
        raw = { values: {}, when: Date.now(), stepIndex: 0 };

      raw.stepIndex =
        typeof window.currentStep === "number"
          ? window.currentStep
          : getActiveStepIndex(root);

      raw.when = Date.now();
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(raw));
      } catch (_) {}
      progressTo(raw.stepIndex, total);
      syncNavButtons(root);
    }, 60);

    const mo = new MutationObserver(() => update());
    mo.observe(root, {
      subtree: true,
      attributes: true,
      attributeFilter: ["style", "class"],
    });

    const prev = $(PREV_BTN_SEL),
      next = $(NEXT_BTN_SEL);
    [prev, next].forEach((btn) => {
      if (!btn) return;
      btn.addEventListener(
        "click",
        () =>
          setTimeout(() => {
            update();
          }, 0),
        true
      );
    });

    update();
    return mo;
  }

  // ===== Boot =====
  ready(() => {
    const root = $(FORM_ROOT_SEL);
    if (!root) {
      console.warn("[ExitSave] Form root not found:", FORM_ROOT_SEL);
      return;
    }
    ensureStyles();

    const saved = loadSaved();
    if (saved) {
      showRestoreBar(saved, root);
      showToast(
        "We found a saved appointment form. You can continue it from the top of the page.",
        "success"
      );
    } else {
      showToast(
        "Don’t worry, your appointment details will be saved automatically if you leave.",
        "success"
      );
    }

    observeStepChanges(root);
    syncNavButtons(root);

    const debouncedSave = debounce(() => saveAll(root), 350);
    $$("input, textarea, select", root).forEach((el) =>
      ["input", "change", "blur"].forEach((evt) =>
        el.addEventListener(evt, debouncedSave, true)
      )
    );

    let pendingNav = null;
    document.addEventListener(
      "click",
      async (e) => {
        if (window.formIsSubmitting) return; // ✅ skip if already submitting

        const a = e.target.closest("a[href]");
        if (!a) return;
        const href = a.getAttribute("href");
        if (
          !href ||
          href.startsWith("#") ||
          a.target === "_blank" ||
          href.startsWith("mailto:") ||
          href.startsWith("tel:") ||
          href.startsWith("javascript:")
        )
          return;
        e.preventDefault();
        pendingNav = { type: "link", href };
        const action = await showExitModal();
        if (action === "cancel") {
          pendingNav = null;
          return;
        }
        if (action === "save-leave") {
          saveAll(root);
          showToast(
            "Your appointment details have been saved. Redirecting…",
            "success"
          );
        }
        window.location.href = href;
      },
      true
    );

    document.addEventListener(
      "submit",
      (e) => {
        if (e.target && root.contains(e.target)) {
          window.formIsSubmitting = true; // ✅ mark submitting
          saveAll(root);
          showToast(
            "We’ve saved your appointment details while confirming your booking.",
            "success"
          );
          clearSaved(); // ✅ clear draft after submit
        }
      },
      true
    );

    // window.addEventListener('beforeunload',(e)=>{
    //   if (window.formIsSubmitting) return; // ✅ skip if submitting
    //   if(pendingNav && pendingNav.type==='link') return;
    //   saveAll(root);
    //   e.preventDefault(); e.returnValue = "";
    // });
  });
})();
