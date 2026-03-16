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
      if (typeof window.showPaymentLoader === "function") {
        window.showPaymentLoader();
        if (typeof window.updatePaymentLoader === "function") {
          window.updatePaymentLoader(
            "Preparing your payment...",
            "Securing your booking details before checkout.",
            10
          );
        }
        return;
      }
      if (window.jQuery?.LoadingOverlay) window.jQuery.LoadingOverlay("show");
    } catch (_) {}
  }
  function hideLoader() {
    try {
      if (typeof window.hidePaymentLoader === "function") {
        window.hidePaymentLoader();
        return;
      }
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

  function buildRequestHeaders(attemptToken = "") {
    const headers = { "Content-Type": "application/json" };
    const requestToken = String(window.TyroHealthData?.request_token || "").trim();
    const attempt = String(attemptToken || "").trim();

    if (requestToken) {
      headers["X-ES-Request-Token"] = requestToken;
    }

    if (attempt) {
      headers["X-ES-Attempt-Token"] = attempt;
    }

    return headers;
  }

  async function postJson(url, payload, attemptToken = "") {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: buildRequestHeaders(attemptToken),
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
  let cachedAttempt = null;
  let cachedAttemptModuleId = "";

  window.clinikoResetTyroAttempt = function clinikoResetTyroAttempt() {
    cachedAttempt = null;
    cachedAttemptModuleId = "";
  };

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

  function buildAttemptPayload() {
    const formElement = document.getElementById("prepayment-form");
    const headlessPayload =
      (window.formHandlerData?.is_headless || !formElement) &&
      typeof normalizeHeadlessPayload === "function" &&
      typeof getHeadlessPayload === "function"
        ? normalizeHeadlessPayload(getHeadlessPayload())
        : null;

    const parsed =
      headlessPayload ||
      (typeof parseFormToStructuredBody === "function"
        ? parseFormToStructuredBody(formElement)
        : null);

    if (!parsed) {
      throw new Error("Could not read form payload.");
    }

    const content =
      typeof normalizeContentForSubmission === "function"
        ? normalizeContentForSubmission(parsed?.content || {})
        : parsed?.content || {};
    const patient =
      typeof normalizePatientForSubmission === "function"
        ? normalizePatientForSubmission(parsed?.patient || {})
        : parsed?.patient || {};

    return {
      gateway: "tyrohealth",
      moduleId: String(window.formHandlerData?.module_id || ""),
      patient_form_template_id: String(
        window.formHandlerData?.patient_form_template_id || ""
      ),
      patient,
      content,
    };
  }

  async function createBookingAttempt() {
    const activeModuleId = String(window.formHandlerData?.module_id || "").trim();
    if (
      cachedAttempt?.attempt?.id &&
      cachedAttemptModuleId !== "" &&
      cachedAttemptModuleId === activeModuleId
    ) {
      return cachedAttempt;
    }

    const url = window.TyroHealthData.attempt_preflight_url;
    if (!url) throw new Error("TyroHealthData.attempt_preflight_url missing.");

    const resp = await postJson(url, buildAttemptPayload());
    if (!resp?.ok || !resp?.attempt?.id || !resp?.attempt?.token || !resp?.payment) {
      throw new Error(resp?.message || "Failed to prepare booking attempt.");
    }

    cachedAttempt = resp;
    cachedAttemptModuleId = activeModuleId;
    return cachedAttempt;
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

      const attempt = await createBookingAttempt();
      const chargeAmount = normalizeChargeAmount(
        ((attempt?.payment?.amount || 0) / 100).toFixed(2)
      );
      const invoiceReference =
        attempt?.payment?.invoice_reference || attempt?.payment?.invoiceReference;

      if (!chargeAmount) throw new Error("Priced chargeAmount missing.");
      if (!invoiceReference) throw new Error("Priced invoiceReference missing.");

      const payload = {
        platform: "virtual-terminal",
        paymentMethod: window.TyroHealthData.paymentMethod || "new-payment-card",
        chargeAmount,
        invoiceReference,
        patient,
        ...(window.TyroHealthData.providerNumber
          ? { providerNumber: window.TyroHealthData.providerNumber }
          : {}),
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
              ensureErrorEl(),
              false,
              {
                attemptId: attempt?.attempt?.id || "",
                attemptToken: attempt?.attempt?.token || "",
              }
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
