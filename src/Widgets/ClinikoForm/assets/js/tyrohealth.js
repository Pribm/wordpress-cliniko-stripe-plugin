(() => {
  "use strict";

  const TAG = "[TyroHealth]";
  const log = (...a) => console.log(TAG, ...a);
  const warn = (...a) => console.warn(TAG, ...a);
  const err = (...a) => console.error(TAG, ...a);

  // Run only when TyroHealth gateway is selected (defensive)
  const gwRaw = window.formHandlerData?.custom_form_payment ?? "";
  const gwNorm = String(gwRaw).trim().toLowerCase().replace(/\s+/g, "");
  if (gwNorm !== "tyrohealth") return;

  if (typeof window.MedipassTransactionSDK === "undefined") {
    err("MedipassTransactionSDK missing. Ensure Partner SDK is enqueued.");
    return;
  }

  if (!window.TyroHealthData) {
    err("TyroHealthData missing (wp_localize_script not printed?).");
    return;
  }

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
      const btn =
        document.getElementById("tyro-payment-button") ||
        document.getElementById("payment-button");
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

  function getHeadlessPatient() {
    if (!window.formHandlerData?.is_headless) return null;

    let payload = null;
    if (typeof window.clinikoGetHeadlessPayload === "function") {
      try {
        payload = window.clinikoGetHeadlessPayload();
      } catch (e) {
        err("clinikoGetHeadlessPayload error:", e);
      }
    }

    if (!payload || typeof payload !== "object") {
      payload = window.clinikoHeadlessPayload;
    }

    const patient = payload?.patient;
    return patient && typeof patient === "object" ? patient : null;
  }

  function coalesce(...values) {
    for (const v of values) {
      if (v !== null && v !== undefined && String(v).trim() !== "") {
        return String(v).trim();
      }
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
    const headless = getHeadlessPatient();

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

    const resolvedFirstName = coalesce(firstName, headless?.first_name, headless?.firstName);
    const resolvedLastName = coalesce(lastName, headless?.last_name, headless?.lastName);
    const resolvedEmail = coalesce(email, headless?.email);
    const resolvedMobile = coalesce(mobile, headless?.mobile, headless?.phone);
    const resolvedDob = coalesce(dobRaw, headless?.date_of_birth, headless?.dob, headless?.dobString);

    return {
      firstName: resolvedFirstName,
      lastName: resolvedLastName,
      dob: normalizeDob(resolvedDob),
      ...(resolvedEmail ? { email: resolvedEmail } : {}),
      ...(resolvedMobile ? { mobile: resolvedMobile } : {}),
    };
  }

  function normalizeChargeAmount(v) {
    const s = String(v || "").trim();
    if (!s) return "";
    return s.startsWith("$") ? s : `$${s}`;
  }

  async function postJson(url, payload) {
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
    if (!res.ok) throw new Error(data?.message || `Request failed (${res.status})`);
    return data;
  }

  // Short-lived SDK token config
  let configured = false;
  let cachedToken = null;

  async function getSdkToken() {
    if (cachedToken) return cachedToken;
    const url = window.TyroHealthData.sdk_token_url;
    if (!url) throw new Error("TyroHealthData.sdk_token_url missing.");
    const data = await postJson(url, {});
    if (!data?.token) throw new Error("SDK token response missing { token }.");
    cachedToken = data.token;
    return cachedToken;
  }

  async function ensureConfigured() {
    if (configured) return;
    const { env, appId, appVersion } = window.TyroHealthData;
    if (!env || !appId || !appVersion) {
      throw new Error("TyroHealthData missing env/appId/appVersion.");
    }
    const apiKey = await getSdkToken();
    window.MedipassTransactionSDK.setConfig({
      env,
      apiKey,
      appId,
      appVersion,
    });
    configured = true;
    log("SDK configured.");
  }

  async function fetchPricedTransaction() {
    const url = window.TyroHealthData.create_invoice_url;
    const moduleId = window.TyroHealthData.moduleId;
    if (!url) throw new Error("TyroHealthData.create_invoice_url missing.");
    if (!moduleId) throw new Error("TyroHealthData.moduleId missing.");

    const resp = await postJson(url, { moduleId });
    if (!resp?.success || !resp?.data) {
      throw new Error(resp?.message || "Failed to price Tyro transaction.");
    }
    return resp.data; // { chargeAmount, invoiceReference, providerNumber? }
  }

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
        throw new Error(
          "Please complete First name, Last name, and Date of Birth before continuing."
        );
      }

      const priced = await fetchPricedTransaction();
      const chargeAmount = normalizeChargeAmount(priced.chargeAmount);
      const invoiceReference = priced.invoiceReference;

      if (!chargeAmount) throw new Error("Priced chargeAmount missing.");
      if (!invoiceReference) throw new Error("Priced invoiceReference missing.");

      const payload = {
        platform: "virtual-terminal",
        paymentMethod: window.TyroHealthData.paymentMethod || "new-payment-card",
        chargeAmount,
        invoiceReference,
        patient,
        ...(priced.providerNumber ? { providerNumber: priced.providerNumber } : {}),
      };

      hideLoader(); // SDK handles UI

      window.MedipassTransactionSDK.renderCreateTransaction(payload, {
        hideChatBubble: true,
        allowEdit: false,
        disableModifyServiceItems: true,
        onSuccess: async (transaction) => {
          try {
            showLoader();
            setError("");

            const txId =
              transaction?.id ||
              transaction?.transactionId ||
              transaction?.transaction_id;

            if (!txId) throw new Error("Tyro transaction id missing.");

            await window.submitBookingForm(
              {
                gateway: "tyrohealth",
                transactionId: txId,
                invoiceReference,
              },
              ensureErrorEl()
            );
          } catch (e) {
            err("Post-success booking failed:", e);
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

  function attachHandlers() {
    const buttons = qsa("#tyro-payment-button, #payment-button");
    buttons.forEach((btn) => {
      if (btn.dataset.tyroAttached === "1") return;
      btn.dataset.tyroAttached = "1";
      btn.addEventListener("click", (ev) => {
        ev.preventDefault();
        startTransaction();
      });
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    attachHandlers();
    const mo = new MutationObserver(() => attachHandlers());
    mo.observe(document.documentElement, { childList: true, subtree: true });
  });
})();


document.addEventListener("DOMContentLoaded", initTyroHealthCheckout);
