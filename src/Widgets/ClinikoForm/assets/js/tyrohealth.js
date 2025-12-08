(() => {
  "use strict";

  const TAG = "[TyroHealth]";
  const log = (...a) => console.log(TAG, ...a);
  const warn = (...a) => console.warn(TAG, ...a);
  const err = (...a) => console.error(TAG, ...a);

  // ---------------------------
  // Guard: only run for tyrohealth
  // ---------------------------
  const gwRaw = window.formHandlerData?.custom_form_payment ?? "";
  const gwNorm = String(gwRaw).trim().toLowerCase().replace(/\s+/g, "");

  log("loaded. gateway:", { raw: gwRaw, norm: gwNorm });

  if (gwNorm !== "tyrohealth") {
    log("Not tyrohealth gateway. Exiting.");
    return;
  }

  if (typeof window.MedipassTransactionSDK === "undefined") {
    err("MedipassTransactionSDK missing. Ensure Partner SDK script is enqueued before this file.");
    return;
  }

  if (!window.TyroHealthData) {
    err("TyroHealthData missing (wp_localize_script not printed?).");
    return;
  }

  log("TyroHealthData:", window.TyroHealthData);

  // ---------------------------
  // Basic helpers
  // ---------------------------
  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function showLoader() {
    try {
      if (window.jQuery?.LoadingOverlay) window.jQuery.LoadingOverlay("show");
    } catch (_) {}
  }

  function hideLoader() {
    try {
      if (window.jQuery?.LoadingOverlay) window.jQuery.LoadingOverlay("hide");
    } catch (_) {}
  }

  function ensureErrorEl() {
    let el = document.getElementById("payment-error-message");
    if (!el) {
      el = document.createElement("div");
      el.id = "payment-error-message";
      el.style.cssText = "margin-top: 1rem; color: #c62828; font-weight: 500;";
      const btn = document.getElementById("tyro-payment-button");
      if (btn) btn.after(el);
    }
    return el;
  }

  function setError(message) {
    const el = ensureErrorEl();
    if (el) el.textContent = message || "";
  }

  function readValue(selectors) {
    for (const sel of selectors) {
      const el = qs(sel);
      if (!el) continue;
      const val = (el.value ?? el.textContent ?? "").toString().trim();
      if (val) return val;
    }
    return "";
  }

  function normalizeDob(input) {
    const v = (input || "").trim();
    if (!v) return "";
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return v;
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  function collectPatient() {
    const firstName = readValue([
      "#patient-first-name",
      'input[name="patient[firstName]"]',
      'input[name="patient[first_name]"]',
      'input[name="first_name"]',
    ]);

    const lastName = readValue([
      "#patient-last-name",
      'input[name="patient[lastName]"]',
      'input[name="patient[last_name]"]',
      'input[name="last_name"]',
    ]);

    const email = readValue([
      "#patient-email",
      'input[name="patient[email]"]',
      'input[type="email"]',
    ]);

    const mobile = readValue([
      "#patient-mobile",
      'input[name="patient[mobile]"]',
      'input[type="tel"]',
    ]);

    const dobRaw = readValue([
      "#patient-dob",
      'input[name="patient[dobString]"]',
      'input[type="date"]',
    ]);

    const patient = {
      firstName,
      lastName,
      dob: normalizeDob(dobRaw),
      ...(email ? { email } : {}),
      ...(mobile ? { mobile } : {}),
    };

    log("collectPatient:", patient);
    return patient;
  }

  function normalizeChargeAmount(v) {
    // Accept "$100.00", "100.00", 10000 (cents), etc.
    if (v == null) return "";
    if (typeof v === "number") {
      // assume cents if big
      if (v >= 1000) return `$${(v / 100).toFixed(2)}`;
      return `$${v.toFixed(2)}`;
    }
    const s = String(v).trim();
    if (!s) return "";
    if (s.startsWith("$")) return s;
    // "100" -> "$100.00"
    if (/^\d+$/.test(s)) return `$${Number(s).toFixed(2)}`;
    // "100.00" -> "$100.00"
    if (/^\d+\.\d{1,2}$/.test(s)) return `$${Number(s).toFixed(2)}`;
    return s; // last resort
  }

  async function postJson(url, payload) {
    log("POST", url, payload);
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload || {}),
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (_) {}

    log("Response", url, { status: res.status, body: data || text });

    if (!res.ok) {
      throw new Error(data?.message || `Request failed (${res.status})`);
    }
    return data;
  }

  // ---------------------------
  // SDK token + config (simple)
  // ---------------------------
  let configured = false;
  let cachedToken = null;

  async function getSdkToken() {
    if (cachedToken) return cachedToken;

    const url = window.TyroHealthData.sdk_token_url;
    if (!url) throw new Error("TyroHealthData.sdk_token_url missing.");

    const data = await postJson(url, {}); // your endpoint can ignore body
    if (!data?.token) throw new Error("SDK token response missing { token }");

    cachedToken = data.token;
    log("SDK token received (preview):", String(cachedToken).slice(0, 12) + "...");
    return cachedToken;
  }

  async function ensureConfigured() {
    if (configured) return;

    const { env, appId, appVersion } = window.TyroHealthData;
    if (!env || !appId || !appVersion) {
      throw new Error("TyroHealthData missing env/appId/appVersion.");
    }

    const apiKey = await getSdkToken();

    log("MedipassTransactionSDK.setConfig", { env, appId, appVersion });

    window.MedipassTransactionSDK.setConfig({
      env,
      apiKey,
      appId,
      appVersion,
    });

    configured = true;
    log("SDK configured.");
  }

  // ---------------------------
  // Start transaction
  // ---------------------------
  let running = false;

  async function startTransaction() {
    if (running) return;
    running = true;

    setError("");
    showLoader();

    try {
      await ensureConfigured();

      const patient = collectPatient();
      if (!patient.firstName || !patient.lastName || !patient.dob) {
        throw new Error("Please complete First name, Last name, and Date of Birth before continuing.");
      }

      const chargeAmount = normalizeChargeAmount(window.TyroHealthData.chargeAmount);
      const invoiceReference = window.TyroHealthData.invoiceReference || `ES-${Date.now()}`;

      if (!chargeAmount) throw new Error("TyroHealthData.chargeAmount missing.");
      if (!invoiceReference) throw new Error("TyroHealthData.invoiceReference missing.");

      const payload = {
        // Keep it “proper gateway” (no user-entered amount)
        platform: "virtual-terminal",
        paymentMethod: "new-payment-card",
        chargeAmount,
        invoiceReference,
        patient,

        // optional
        ...(window.TyroHealthData.providerNumber
          ? { providerNumber: window.TyroHealthData.providerNumber }
          : {}),
      };

      log("renderCreateTransaction payload:", payload);

      hideLoader(); // SDK shows UI

      window.MedipassTransactionSDK.renderCreateTransaction(payload, {
        onSuccess: async (tx) => {
          log("onSuccess tx:", tx);

          try {
            showLoader();
            setError("");

            // Prefer your shared booking handler if present
            if (typeof window.submitBookingForm === "function") {
              await window.submitBookingForm(
                {
                  gateway: "tyrohealth",
                  moduleId: window.TyroHealthData.moduleId || window.formHandlerData?.module_id || null,
                  invoiceReference,
                  transaction: tx,
                },
                ensureErrorEl()
              );
              return; // submitBookingForm typically redirects
            }

            // Fallback: call your confirm endpoint
            const confirmUrl = window.TyroHealthData.confirm_booking_url;
            if (!confirmUrl) throw new Error("TyroHealthData.confirm_booking_url missing.");

            await postJson(confirmUrl, {
              moduleId: window.TyroHealthData.moduleId || window.formHandlerData?.module_id || null,
              invoiceReference,
              transaction: tx,
            });

            // Redirect
            const redirectUrl = window.TyroHealthData.redirect_url || window.formHandlerData?.redirect_url;
            if (redirectUrl) window.location.href = redirectUrl;
          } catch (e) {
            err("Post-success handler failed:", e);
            setError(e.message || "Payment succeeded, but booking failed.");
          } finally {
            hideLoader();
          }
        },

        onError: (e) => {
          err("onError:", e);
          setError(e?.message || "Payment failed. Please try again.");
          hideLoader();
        },

        onCancel: () => {
          warn("onCancel");
          setError("Payment was cancelled.");
          hideLoader();
        },
      });
    } catch (e) {
      err("startTransaction error:", e);
      setError(e.message || "Could not start payment.");
      hideLoader();
    } finally {
      running = false;
    }
  }

  // ---------------------------
  // Bind button (Elementor-safe)
  // ---------------------------
  function attachHandlers() {
    const buttons = qsa("#tyro-payment-button");
    log("attachHandlers: found tyro buttons:", buttons.length);

    buttons.forEach((btn) => {
      if (btn.dataset.tyroAttached === "1") return;
      btn.dataset.tyroAttached = "1";
      log("Binding click handler to #tyro-payment-button");

      btn.addEventListener("click", (ev) => {
        ev.preventDefault();
        startTransaction();
      });
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    log("DOMContentLoaded");
    attachHandlers();

    const mo = new MutationObserver((mutations) => {
      const added = mutations.some((m) => m.addedNodes && m.addedNodes.length);
      if (added) log("DOM mutated (nodes added). Re-attaching handlers...");
      attachHandlers();
    });

    mo.observe(document.documentElement, { childList: true, subtree: true });
  });
})();
