let isPaymentEnabled = Boolean(formHandlerData.is_payment_enabled);
let stripeInitStarted = false;
let steps = document.querySelectorAll(".form-step");
let stripeInstance = null;
let cardElementInstance = null;
let errorElementInstance = null;
let nextBtn;
let prevBtn;
let isClinikoForm;
let formType = "multi";
let isHeadless = false;
let progressEl;
let paymentLoaderProgress = null;
let paymentLoaderHeadline = "";
let paymentLoaderDetail = "";
let patientHistoryAccessToken = "";
let patientHistoryChallengeToken = "";
let patientHistoryStandaloneActive = false;
let patientHistoryHandoffListenerBound = false;
let patientHistoryRequestStatusStop = null;
let patientHistoryAttentionTitle = "";
const patientHistoryTabId = `esph_${Math.random().toString(36).slice(2)}_${Date.now()}`;

document.addEventListener("DOMContentLoaded", () => {
  isPaymentEnabled = Boolean(formHandlerData.is_payment_enabled);
  stripeInitStarted = false;
  steps = document.querySelectorAll(".form-step");
  nextBtn = document.getElementById("step-next");
  prevBtn = document.getElementById("step-prev");
  isClinikoForm = formHandlerData.cliniko_embed === "cliniko_embed";
  formType = String(formHandlerData?.form_type || "multi").toLowerCase();
  isHeadless = formType === "headless";
  progressEl = document.getElementById("form-progress-indicator");

  if (!isHeadless) {
    mountForm();
  } else {
    window.currentStep = 0;
    initHeadlessCalendarHelpers();
    initHeadlessPaymentWatcher();
  }

  initPatientHistoryAccess();
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) {
    clearPatientHistoryAttention();
  }
});

function getSelectedGateway() {
  return String(formHandlerData?.custom_form_payment || "stripe").toLowerCase();
}

function isStripeSelected() {
  return getSelectedGateway() === "stripe";
}

function isTyroSelected() {
  return getSelectedGateway() === "tyrohealth";
}

function shouldUseCalendarTimes() {
  const selection = String(
    formHandlerData?.appointment_time_selection || "calendar"
  )
    .trim()
    .toLowerCase();
  return !isClinikoForm && (isStripeSelected() || isTyroSelected()) && selection === "calendar";
}

function shouldUsePractitionerSelection() {
  return !isClinikoForm && isPaymentEnabled && (isStripeSelected() || isTyroSelected());
}

function getCalendarFrontendCacheStore() {
  if (!window.__clinikoCalendarCacheStore) {
    window.__clinikoCalendarCacheStore = {
      values: new Map(),
      pending: new Map(),
    };
  }
  return window.__clinikoCalendarCacheStore;
}

function readCalendarCacheValue(store, key) {
  const entry = store.values.get(key);
  if (!entry) return null;
  if (!entry.expiresAt || entry.expiresAt < Date.now()) {
    store.values.delete(key);
    return null;
  }
  return entry.value;
}

function writeCalendarCacheValue(store, key, value, ttlMs) {
  const ttl = Math.max(1, Number(ttlMs || 1));
  store.values.set(key, {
    value,
    expiresAt: Date.now() + ttl,
  });
}

function withPendingCalendarRequest(store, key, loader) {
  if (store.pending.has(key)) {
    return store.pending.get(key);
  }

  const promise = Promise.resolve()
    .then(loader)
    .finally(() => {
      store.pending.delete(key);
    });

  store.pending.set(key, promise);
  return promise;
}

function initHeadlessCalendarHelpers() {
  if (!isHeadless) return;

  const endpoints = {
    appointmentType: formHandlerData?.appointment_type_url,
    patientFormTemplate: formHandlerData?.patient_form_template_url,
    practitioners: formHandlerData?.practitioners_url,
    calendar: formHandlerData?.appointment_calendar_url,
    availableTimes: formHandlerData?.available_times_url,
    nextAvailableTimes: formHandlerData?.next_available_times_url,
  };

  let currentAppointmentTypeId = String(formHandlerData?.module_id || "").trim();
  let currentPatientFormTemplateId = String(
    formHandlerData?.patient_form_template_id || ""
  ).trim();
  const defaultPerPage = Math.min(
    100,
    Math.max(1, Number(formHandlerData?.available_times_per_page || 100))
  );
  const cacheStore = getCalendarFrontendCacheStore();
  const cacheTtlMs = {
    practitioners: 5 * 60 * 1000,
    calendar: 2 * 60 * 1000,
    timesPage: 30 * 1000,
  };

  const buildUrl = (base, params) => {
    if (!base) throw new Error("Endpoint not configured.");
    const url = new URL(base, window.location.origin);
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null && String(v) !== "") {
        url.searchParams.set(k, String(v));
      }
    });
    return url.toString();
  };

const fetchJson = async (url) => {
  const res = await fetch(url, {
    method: "GET",
    headers: {
      Accept: "application/json",
      ...(formHandlerData?.request_token
        ? { "X-ES-Request-Token": String(formHandlerData.request_token).trim() }
        : {}),
    },
    credentials: "same-origin",
  });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || "Request failed.");
    }
    return data?.data ?? data ?? {};
  };

  const cloneJson = (value, fallback = null) => {
    if (value === undefined) return fallback;
    if (value === null) return null;
    try {
      return JSON.parse(JSON.stringify(value));
    } catch (_) {
      return fallback;
    }
  };

  const clearCalendarCache = () => {
    cacheStore.values.clear();
    cacheStore.pending.clear();
  };

  const mergePatientWithSkeleton = (targetPatient, patientSkeleton) => {
    const base = patientSkeleton && typeof patientSkeleton === "object"
      ? cloneJson(patientSkeleton, {})
      : {};
    const current = targetPatient && typeof targetPatient === "object"
      ? cloneJson(targetPatient, {})
      : {};
    return { ...base, ...current };
  };

  const normalizeSectionsForSubmissionTemplate = (rawSections) => {
    if (!Array.isArray(rawSections)) return [];
    return rawSections
      .map((section) => {
        const sectionName = String(section?.name || "");
        const sectionDescription = String(section?.description || "");
        const rawQuestions = Array.isArray(section?.questions)
          ? section.questions
          : [];

        const questions = rawQuestions
          .map((q) => {
            const type = String(q?.type || "text");
            if (type === "signature") return null;

            const out = {
              name: String(q?.name || ""),
              type,
              required: !!q?.required,
            };

            const otherEnabled = !!q?.other?.enabled;
            if (type === "checkboxes" || type === "radiobuttons") {
              const answers = (Array.isArray(q?.answers) ? q.answers : [])
                .map((opt) => ({ value: String(opt?.value || "") }))
                .filter((opt) => opt.value !== "");

              if (answers.length === 0 && !otherEnabled) {
                return null;
              }

              out.answers = answers;
              if (otherEnabled) {
                out.other = { enabled: true, selected: false, value: "" };
              }
            } else {
              out.answer = "";
            }

            return out;
          })
          .filter(Boolean);

        if (!questions.length) return null;
        return {
          name: sectionName,
          description: sectionDescription,
          questions,
        };
      })
      .filter(Boolean);
  };

  const buildSubmissionTemplateFromSections = (sections) => {
    const basePatient =
      formHandlerData?.submission_template?.patient &&
      typeof formHandlerData.submission_template.patient === "object"
        ? cloneJson(formHandlerData.submission_template.patient, {})
        : {
            first_name: "",
            last_name: "",
            email: "",
            phone: "",
            medicare: "",
            medicare_reference_number: "",
            address_1: "",
            address_2: "",
            city: "",
            state: "",
            post_code: "",
            country: "",
            date_of_birth: "",
            appointment_start: "",
            practitioner_id: "",
          };

    return {
      patient: basePatient,
      content: {
        sections: normalizeSectionsForSubmissionTemplate(sections),
      },
    };
  };

  const updateHeadlessScripts = (sections, submissionTemplate, templateId) => {
    const root =
      document.querySelector(".cliniko-form-headless[data-cliniko-headless='1']") ||
      document.querySelector(".cliniko-form-headless");
    if (root && templateId) {
      root.setAttribute("data-form-template-id", String(templateId));
    }

    const templateNode =
      root?.querySelector(".cliniko-form-template-json") ||
      document.querySelector(".cliniko-form-template-json");
    if (templateNode) {
      templateNode.textContent = JSON.stringify(sections || []);
    }

    const submissionNode =
      root?.querySelector(".cliniko-form-submission-template-json") ||
      document.querySelector(".cliniko-form-submission-template-json");
    if (submissionNode) {
      submissionNode.textContent = JSON.stringify(submissionTemplate || {});
    }
  };

  const updatePaymentSummary = (appointmentTypeData) => {
    if (!appointmentTypeData || typeof appointmentTypeData !== "object") return false;

    const nameEl = document.getElementById("summary-name");
    if (nameEl) {
      nameEl.textContent = String(appointmentTypeData.name || "");
    }

    const descriptionEl = document.getElementById("summary-description");
    if (descriptionEl) {
      descriptionEl.textContent = String(appointmentTypeData.description || "");
    }

    const priceEl = document.getElementById("summary-price");
    if (priceEl) {
      const cents = Number(appointmentTypeData.amount_cents);
      if (Number.isFinite(cents) && cents >= 0) {
        priceEl.textContent = (cents / 100).toFixed(2);
      } else if (appointmentTypeData.amount !== undefined && appointmentTypeData.amount !== null) {
        priceEl.textContent = String(appointmentTypeData.amount);
      }
    }

    return true;
  };

  const ensureHeadlessPayload = () => {
    if (typeof window.clinikoGetHeadlessPayload === "function") {
      return null;
    }

    if (!window.clinikoHeadlessPayload || typeof window.clinikoHeadlessPayload !== "object") {
      if (formHandlerData?.submission_template) {
        try {
          window.clinikoHeadlessPayload = JSON.parse(
            JSON.stringify(formHandlerData.submission_template)
          );
        } catch (_) {
          return null;
        }
      } else {
        return null;
      }
    }
    if (!window.clinikoHeadlessPayload.patient || typeof window.clinikoHeadlessPayload.patient !== "object") {
      window.clinikoHeadlessPayload.patient = {};
    }
    return window.clinikoHeadlessPayload;
  };

  const updateHeadlessPatient = (fields = {}) => {
    const payload = ensureHeadlessPayload();
    if (!payload) return false;
    Object.entries(fields).forEach(([k, v]) => {
      payload.patient[k] = v;
    });
    return true;
  };

  const updateAppointmentType = async (
    appointmentTypeId,
    updatePaymentStep = true
  ) => {
    const id = String(appointmentTypeId || "").trim();
    if (!id) {
      throw new Error("appointmentTypeId is required.");
    }

    let appointmentTypeData = null;
    if (endpoints.appointmentType) {
      appointmentTypeData = await fetchJson(
        buildUrl(endpoints.appointmentType, { appointment_type_id: id })
      );
    }

    const previousId = currentAppointmentTypeId;
    currentAppointmentTypeId = id;
    formHandlerData.module_id = id;

    if (
      formHandlerData.submission_template &&
      typeof formHandlerData.submission_template === "object"
    ) {
      formHandlerData.submission_template.moduleId = id;
    }

    const payload = ensureHeadlessPayload();
    if (payload) {
      payload.moduleId = id;
      if (previousId !== id && payload.patient && typeof payload.patient === "object") {
        payload.patient.practitioner_id = "";
        payload.patient.appointment_start = "";
      }
    }

    clearCalendarCache();
    if (previousId !== id) {
      updateHeadlessPatient({ practitioner_id: "", appointment_start: "" });
    }

    if (typeof window.clinikoResetTyroAttempt === "function") {
      window.clinikoResetTyroAttempt();
    }

    if (updatePaymentStep) {
      updatePaymentSummary(appointmentTypeData);
    }

    let practitioners = [];
    try {
      practitioners = await fetchPractitioners({ appointmentTypeId: id });
    } catch (_) {
      practitioners = [];
    }

    return {
      module_id: id,
      appointment_type: appointmentTypeData,
      practitioners: Array.isArray(practitioners) ? practitioners : [],
      payment: appointmentTypeData
        ? {
            amount_cents: Number(appointmentTypeData.amount_cents || 0),
            amount: String(appointmentTypeData.amount ?? ""),
            currency: String(appointmentTypeData.currency || "aud"),
            required: !!appointmentTypeData.payment_required,
          }
        : null,
    };
  };

  const updateFormtemplate = async (templateId) => {
    const id = String(templateId || "").trim();
    if (!id) {
      throw new Error("templateId is required.");
    }
    if (!endpoints.patientFormTemplate) {
      throw new Error("Patient form template endpoint not configured.");
    }

    const templateData = await fetchJson(
      buildUrl(endpoints.patientFormTemplate, {
        patient_form_template_id: id,
      })
    );

    const sections = Array.isArray(templateData?.sections)
      ? cloneJson(templateData.sections, [])
      : [];
    const submissionTemplate =
      templateData?.submission_template &&
      typeof templateData.submission_template === "object"
        ? cloneJson(templateData.submission_template, {})
        : buildSubmissionTemplateFromSections(sections);

    submissionTemplate.moduleId = String(formHandlerData?.module_id || currentAppointmentTypeId || "");
    submissionTemplate.patient_form_template_id = id;

    const previousTemplateId = currentPatientFormTemplateId;
    currentPatientFormTemplateId = id;
    formHandlerData.patient_form_template_id = id;
    formHandlerData.sections = sections;
    formHandlerData.submission_template = submissionTemplate;

    updateHeadlessScripts(sections, submissionTemplate, id);

    const payload = ensureHeadlessPayload();
    if (payload) {
      payload.patient_form_template_id = id;
      payload.content = cloneJson(submissionTemplate.content, { sections: [] });
      payload.patient = mergePatientWithSkeleton(
        payload.patient,
        submissionTemplate.patient
      );
    }

    return {
      patient_form_template_id: id,
      name: String(templateData?.name || ""),
      sections,
      submission_template: submissionTemplate,
      module_id: String(formHandlerData?.module_id || currentAppointmentTypeId || ""),
      previous_patient_form_template_id: previousTemplateId,
    };
  };

  const getMonthKeyFromDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    return `${year}-${month}`;
  };

  const shiftMonthKey = (monthKey, delta) => {
    if (!monthKey) return "";
    const [yearStr, monthStr] = monthKey.split("-");
    const year = Number(yearStr);
    const month = Number(monthStr);
    if (!year || !month) return "";
    const dt = new Date(year, month - 1 + delta, 1);
    return getMonthKeyFromDate(dt);
  };

  const groupTimesByPeriod = (times) => {
    const buckets = { morning: [], afternoon: [], evening: [] };
    (times || []).forEach((iso) => {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return;
      const hour = dt.getHours();
      if (hour < 12) buckets.morning.push(iso);
      else if (hour < 17) buckets.afternoon.push(iso);
      else buckets.evening.push(iso);
    });
    return buckets;
  };

  const fetchPractitioners = async ({ appointmentTypeId } = {}) => {
    const id = appointmentTypeId || currentAppointmentTypeId;
    const cacheKey = `cliniko:practitioners:${id}`;
    const cached = readCalendarCacheValue(cacheStore, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(cacheStore, cacheKey, async () => {
      const url = buildUrl(endpoints.practitioners, { appointment_type_id: id });
      const payload = await fetchJson(url);
      const list = Array.isArray(payload.practitioners) ? payload.practitioners : [];
      writeCalendarCacheValue(cacheStore, cacheKey, list, cacheTtlMs.practitioners);
      return list;
    });
  };

  const fetchCalendar = async ({ appointmentTypeId, practitionerId, monthKey } = {}) => {
    const id = appointmentTypeId || currentAppointmentTypeId;
    const practitioner = practitionerId || "";
    const month = monthKey || "";
    const cacheKey = `cliniko:calendar:${id}:${practitioner}:${month}`;
    const cached = readCalendarCacheValue(cacheStore, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(cacheStore, cacheKey, async () => {
      const url = buildUrl(endpoints.calendar, {
        appointment_type_id: id,
        practitioner_id: practitioner,
        month,
      });
      const payload = await fetchJson(url); // { grid_html, month_label, month_key }
      writeCalendarCacheValue(cacheStore, cacheKey, payload, cacheTtlMs.calendar);
      return payload;
    });
  };

  const prefetchCalendarWindow = async ({
    appointmentTypeId,
    practitionerId,
    monthKey,
    monthsAhead = 1,
  } = {}) => {
    const id = appointmentTypeId || currentAppointmentTypeId;
    const practitioner = practitionerId || "";
    let cursor = monthKey || getMonthKeyFromDate(new Date());

    if (!id || !practitioner || !cursor) return false;

    const tasks = [
      fetchCalendar({
        appointmentTypeId: id,
        practitionerId: practitioner,
        monthKey: cursor,
      }),
    ];

    for (let i = 0; i < Math.max(0, Number(monthsAhead || 0)); i += 1) {
      cursor = shiftMonthKey(cursor, 1);
      if (!cursor) break;
      tasks.push(
        fetchCalendar({
          appointmentTypeId: id,
          practitionerId: practitioner,
          monthKey: cursor,
        })
      );
    }

    await Promise.allSettled(tasks);
    return true;
  };

  const fetchAvailableTimes = async ({
    appointmentTypeId,
    practitionerId,
    from,
    to,
    perPage,
    page,
  } = {}) => {
    const id = appointmentTypeId || currentAppointmentTypeId;
    const practitioner = practitionerId || "";
    const fromDate = from || "";
    const toDate = to || "";
    const perPageValue = String(perPage || defaultPerPage);
    const pageValue = String(page || 1);
    const cacheKey = `cliniko:times:${id}:${practitioner}:${fromDate}:${toDate}:${perPageValue}:${pageValue}`;
    const cached = readCalendarCacheValue(cacheStore, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(cacheStore, cacheKey, async () => {
      const url = buildUrl(endpoints.availableTimes, {
        appointment_type_id: id,
        practitioner_id: practitioner,
        from: fromDate,
        to: toDate,
        per_page: perPageValue,
        page: pageValue,
      });
      const payload = await fetchJson(url);
      const rawTimes = payload.available_times || [];
      const items = Array.isArray(rawTimes)
        ? rawTimes.map((t) => t?.appointment_start || t?.appointmentStart || t).filter(Boolean)
        : [];
      const total = Number(payload.total_entries || items.length);
      const result = { items, total };
      writeCalendarCacheValue(cacheStore, cacheKey, result, cacheTtlMs.timesPage);
      return result;
    });
  };

  const fetchAllTimesForDate = async ({
    dateKey,
    appointmentTypeId,
    practitionerId,
    perPage,
    maxPages = 20,
  } = {}) => {
    let page = 1;
    let collected = [];
    let total = 0;
    let safety = 0;

    while (safety < maxPages) {
      const res = await fetchAvailableTimes({
        appointmentTypeId,
        practitionerId,
        from: dateKey,
        to: dateKey,
        perPage,
        page,
      });
      collected = collected.concat(res.items);
      total = res.total || collected.length;
      if (collected.length >= total || res.items.length === 0) break;
      page += 1;
      safety += 1;
    }

    return collected;
  };

  const toYmdOrEmpty = (value) => {
    if (!value) return "";
    if (value instanceof Date) {
      return Number.isNaN(value.getTime()) ? "" : toDateInputValue(value);
    }
    const dt = new Date(value);
    return Number.isNaN(dt.getTime()) ? "" : toDateInputValue(dt);
  };

  const fetchNextAvailableTimes = async ({
    appointmentTypeId,
    practitionerIds,
    from,
    to,
    includeUnavailable = true,
    refreshPractitioners = false,
  } = {}) => {
    const id = appointmentTypeId || currentAppointmentTypeId;
    if (!id) throw new Error("Appointment type not configured.");
    if (!endpoints.nextAvailableTimes) {
      throw new Error("Next available times endpoint not configured.");
    }

    let practitioners = [];
    if (Array.isArray(practitionerIds) && practitionerIds.length > 0) {
      practitioners = practitionerIds
        .map((item) => {
          if (item && typeof item === "object") {
            const pid = String(item.id || item.practitioner_id || "").trim();
            if (!pid) return null;
            return {
              id: pid,
              name: String(item.name || item.practitioner_name || pid),
            };
          }
          const pid = String(item || "").trim();
          if (!pid) return null;
          return { id: pid, name: pid };
        })
        .filter(Boolean);
    } else {
      if (refreshPractitioners) {
        const cacheKey = `cliniko:practitioners:${id}`;
        cacheStore.values.delete(cacheKey);
        cacheStore.pending.delete(cacheKey);
      }
      const list = await fetchPractitioners({ appointmentTypeId: id });
      practitioners = (Array.isArray(list) ? list : [])
        .map((item) => {
          const pid = String(item?.id || "").trim();
          if (!pid) return null;
          return { id: pid, name: String(item?.name || pid) };
        })
        .filter(Boolean);
    }

    if (!practitioners.length) return [];

    const payload = await fetchJson(
      buildUrl(endpoints.nextAvailableTimes, {
        appointment_type_id: id,
        practitioner_ids: practitioners.map((p) => p.id).join(","),
        from: toYmdOrEmpty(from),
        to: toYmdOrEmpty(to),
        _ts: Date.now(), // avoid browser/proxy stale responses for fresh lookups
      })
    );

    const rawItems = Array.isArray(payload.next_available_times)
      ? payload.next_available_times
      : [];
    const byId = new Map();
    rawItems.forEach((item) => {
      const pid = String(item?.practitioner_id || "").trim();
      if (pid) byId.set(pid, item);
    });

    const merged = practitioners.map((p) => {
      const serverItem = byId.get(p.id);
      return {
        practitioner_id: p.id,
        practitioner_name: String(
          serverItem?.practitioner_name || p.name || p.id
        ),
        appointment_start:
          serverItem?.appointment_start || serverItem?.appointmentStart || null,
      };
    });

    merged.sort((a, b) => {
      const aStart = a?.appointment_start;
      const bStart = b?.appointment_start;

      if (!aStart && !bStart) {
        return String(a?.practitioner_name || "").localeCompare(
          String(b?.practitioner_name || "")
        );
      }
      if (!aStart) return 1;
      if (!bStart) return -1;

      const aTs = new Date(aStart).getTime();
      const bTs = new Date(bStart).getTime();
      const aBad = Number.isNaN(aTs);
      const bBad = Number.isNaN(bTs);
      if (aBad && bBad) return 0;
      if (aBad) return 1;
      if (bBad) return -1;
      if (aTs === bTs) {
        return String(a?.practitioner_name || "").localeCompare(
          String(b?.practitioner_name || "")
        );
      }
      return aTs - bTs;
    });

    return includeUnavailable
      ? merged
      : merged.filter((item) => !!item.appointment_start);
  };

  window.ClinikoHeadlessCalendar = {
    endpoints,
    getMonthKeyFromDate,
    shiftMonthKey,
    toDateInputValue,
    groupTimesByPeriod,
    fetchPractitioners,
    fetchCalendar,
    prefetchCalendarWindow,
    fetchAvailableTimes,
    fetchAllTimesForDate,
    fetchNextAvailableTimes,
    updateHeadlessPatient,
    updateFormtemplate,
    updateFormTemplate: updateFormtemplate,
    updateAppointmentType,
  };
}

function initHeadlessPaymentWatcher() {
  if (!isHeadless || !isPaymentEnabled || !isStripeSelected()) return;

  const paymentForm = document.getElementById("payment_form");
  if (!paymentForm) return;

  const isVisible = (el) => {
    if (!el) return false;
    const style = window.getComputedStyle(el);
    return style.display !== "none" && style.visibility !== "hidden";
  };

  const maybeInit = () => {
    if (!isVisible(paymentForm)) return;
    safeInitStripe();
  };

  maybeInit();

  const observer = new MutationObserver(() => maybeInit());
  observer.observe(paymentForm, { attributes: true, attributeFilter: ["style", "class"] });

  window.addEventListener("load", () => maybeInit());
}

function getPatientHistoryAccessConfig() {
  return formHandlerData?.patient_history_access || {};
}

function isPatientHistoryAccessEnabled() {
  return !!getPatientHistoryAccessConfig()?.enabled;
}

function getPatientHistoryHashKey() {
  const configured = String(getPatientHistoryAccessConfig()?.hash_key || "").trim();
  return configured || "es_patient_access_token";
}

function getPatientHistoryQueryKey() {
  const configured = String(getPatientHistoryAccessConfig()?.query_key || "").trim();
  return configured || "patient_access_token";
}

function getPatientHistoryHandoffStorageKey() {
  return "es_patient_access_handoff";
}

function getPatientHistoryHandoffAckStorageKey() {
  return "es_patient_access_handoff_ack";
}

function escapeFieldNameForSelector(value) {
  if (window.CSS && typeof window.CSS.escape === "function") {
    return window.CSS.escape(String(value || ""));
  }

  return String(value || "").replace(/"/g, '\\"');
}

function normalizePatientHistoryValue(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item || "").trim()).filter(Boolean).join(", ");
  }

  return String(value || "").trim();
}

function readPatientHistoryBootstrapFromLocation() {
  const queryKey = getPatientHistoryQueryKey();
  const hashKey = getPatientHistoryHashKey();
  const url = new URL(window.location.href);
  let requestId = String(url.searchParams.get("request_id") || "").trim();

  let token = String(
    url.searchParams.get(queryKey) || url.searchParams.get("access_token") || ""
  ).trim();
  let nextHash = String(url.hash || "");

  if (!token) {
    const hash = nextHash.replace(/^#/, "");
    if (hash) {
      const params = new URLSearchParams(hash);
      token = String(
        params.get(queryKey) || params.get(hashKey) || params.get("access_token") || ""
      ).trim();
      if (!requestId) {
        requestId = String(params.get("request_id") || "").trim();
      }

      if (token) {
        params.delete(queryKey);
        params.delete(hashKey);
        params.delete("access_token");
        params.delete("request_id");
        const hashString = params.toString();
        nextHash = hashString ? `#${hashString}` : "";
      }
    }
  }

  if (!token && !requestId) {
    return { token: "", requestId: "" };
  }

  url.searchParams.delete(queryKey);
  url.searchParams.delete("access_token");
  url.searchParams.delete("request_id");

  const nextUrl = `${url.pathname}${url.search}${nextHash}`;
  window.history.replaceState(null, document.title, nextUrl);

  return { token, requestId };
}

function readPatientHistoryTokenFromLocation() {
  return readPatientHistoryBootstrapFromLocation().token;
}

function getPatientHistoryToken() {
  return String(patientHistoryAccessToken || "").trim();
}

function setPatientHistoryToken(token) {
  patientHistoryAccessToken = String(token || "").trim();
  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.token = patientHistoryAccessToken;
  }
  return patientHistoryAccessToken;
}

function getPatientHistoryChallengeToken() {
  return String(patientHistoryChallengeToken || "").trim();
}

function setPatientHistoryChallengeToken(token) {
  patientHistoryChallengeToken = String(token || "").trim();
  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.challengeToken = patientHistoryChallengeToken;
  }
  return patientHistoryChallengeToken;
}

function getPatientHistoryReturnUrl() {
  const configured = String(getPatientHistoryAccessConfig()?.return_url || "").trim();
  return configured || `${window.location.origin}${window.location.pathname}${window.location.search}`;
}

function createPatientHistoryRequestId() {
  if (window.crypto && typeof window.crypto.randomUUID === "function") {
    return `espr_${window.crypto.randomUUID().replace(/-/g, "")}`;
  }

  return `espr_${Math.random().toString(36).slice(2)}_${Date.now()}`;
}

function getPatientHistoryRequestStatusUrl(requestId) {
  const base = String(getPatientHistoryAccessConfig()?.request_status_url || "").trim();
  const normalizedRequestId = String(requestId || "").trim();
  if (!base || !normalizedRequestId) {
    return "";
  }

  const url = new URL(base, window.location.origin);
  url.searchParams.set("request_id", normalizedRequestId);
  return url.toString();
}

function getPatientHistoryRequestCompleteUrl() {
  return String(getPatientHistoryAccessConfig()?.request_complete_url || "").trim();
}

function clearPatientHistoryAttention() {
  if (patientHistoryAttentionTitle) {
    document.title = patientHistoryAttentionTitle;
    patientHistoryAttentionTitle = "";
  }
}

function requestPatientHistoryAttention(message = "Your saved details are ready.") {
  try {
    window.focus();
  } catch (_) {
  }

  if (!document.hidden) {
    return;
  }

  if (!patientHistoryAttentionTitle) {
    patientHistoryAttentionTitle = document.title;
  }
  document.title = `Ready to continue | ${patientHistoryAttentionTitle}`;

  if (!("Notification" in window) || Notification.permission !== "granted") {
    return;
  }

  try {
    const notice = new Notification("Booking ready", {
      body: String(message || "Your saved details are ready."),
      tag: "es-patient-history-ready",
      renotify: true,
    });

    window.setTimeout(() => {
      try {
        notice.close();
      } catch (_) {
      }
    }, 8000);
  } catch (_) {
  }
}

function publishPatientHistoryHandoff(token, requestId = "") {
  const normalizedToken = String(token || "").trim();
  if (!normalizedToken) return;

  try {
    const payload = JSON.stringify({
      token: normalizedToken,
      requestId: String(requestId || "").trim(),
      senderId: patientHistoryTabId,
      ts: Date.now(),
    });

    window.localStorage.setItem(
      getPatientHistoryHandoffStorageKey(),
      payload
    );

    window.setTimeout(() => {
      try {
        if (window.localStorage.getItem(getPatientHistoryHandoffStorageKey()) === payload) {
          window.localStorage.removeItem(getPatientHistoryHandoffStorageKey());
        }
      } catch (_) {
      }
    }, 2000);
  } catch (_) {
  }
}

function publishPatientHistoryHandoffAck(requestId, senderId) {
  const normalizedRequestId = String(requestId || "").trim();
  const normalizedSenderId = String(senderId || "").trim();
  if (!normalizedRequestId || !normalizedSenderId) return;

  try {
    const payload = JSON.stringify({
      requestId: normalizedRequestId,
      senderId: normalizedSenderId,
      receiverId: patientHistoryTabId,
      ts: Date.now(),
    });

    window.localStorage.setItem(
      getPatientHistoryHandoffAckStorageKey(),
      payload
    );

    window.setTimeout(() => {
      try {
        if (window.localStorage.getItem(getPatientHistoryHandoffAckStorageKey()) === payload) {
          window.localStorage.removeItem(getPatientHistoryHandoffAckStorageKey());
        }
      } catch (_) {
      }
    }, 2000);
  } catch (_) {
  }
}

function parsePatientHistoryHandoffPayload(rawValue) {
  if (!rawValue) return null;

  try {
    const parsed = JSON.parse(String(rawValue || ""));
    if (!parsed || typeof parsed !== "object") return null;

    const token = String(parsed.token || "").trim();
    const requestId = String(parsed.requestId || "").trim();
    const senderId = String(parsed.senderId || "").trim();
    const ts = Number(parsed.ts || 0);

    if (!token || !senderId || !Number.isFinite(ts)) {
      return null;
    }

    return { token, requestId, senderId, ts };
  } catch (_) {
    return null;
  }
}

function parsePatientHistoryHandoffAckPayload(rawValue) {
  if (!rawValue) return null;

  try {
    const parsed = JSON.parse(String(rawValue || ""));
    if (!parsed || typeof parsed !== "object") return null;

    const requestId = String(parsed.requestId || "").trim();
    const senderId = String(parsed.senderId || "").trim();
    const receiverId = String(parsed.receiverId || "").trim();
    const ts = Number(parsed.ts || 0);

    if (!requestId || !senderId || !receiverId || !Number.isFinite(ts)) {
      return null;
    }

    return { requestId, senderId, receiverId, ts };
  } catch (_) {
    return null;
  }
}

function attemptPatientHistoryHandoff(token) {
  const normalizedToken = String(token || "").trim();
  if (!normalizedToken) {
    return Promise.resolve({ acknowledged: false, requestId: "", source: "none" });
  }

  const requestId = `esph_req_${Math.random().toString(36).slice(2)}_${Date.now()}`;
  const timeoutMs = 900;

  return new Promise((resolve) => {
    let resolved = false;
    let timerId = 0;

    const finish = (result) => {
      if (resolved) return;
      resolved = true;
      window.removeEventListener("storage", onStorageAck);
      if (timerId) {
        window.clearTimeout(timerId);
      }
      resolve(result);
    };

    const onStorageAck = (event) => {
      if (event.storageArea !== window.localStorage) return;
      if (event.key !== getPatientHistoryHandoffAckStorageKey() || !event.newValue) return;

      const payload = parsePatientHistoryHandoffAckPayload(event.newValue);
      if (!payload) return;
      if (payload.senderId !== patientHistoryTabId) return;
      if (payload.requestId !== requestId) return;

      finish({
        acknowledged: true,
        requestId,
        receiverId: payload.receiverId,
        source: "storage",
        ts: payload.ts,
      });
    };

    window.addEventListener("storage", onStorageAck);
    publishPatientHistoryHandoff(normalizedToken, requestId);

    timerId = window.setTimeout(() => {
      finish({
        acknowledged: false,
        requestId,
        source: "timeout",
        ts: Date.now(),
      });
    }, timeoutMs);
  });
}

function stopPatientHistoryRequestStatusPolling() {
  if (typeof patientHistoryRequestStatusStop === "function") {
    patientHistoryRequestStatusStop();
  }
  patientHistoryRequestStatusStop = null;

  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.pendingRequestId = "";
  }
}

function startPatientHistoryRequestStatusPolling(requestId) {
  const normalizedRequestId = String(requestId || "").trim();
  if (!normalizedRequestId) {
    stopPatientHistoryRequestStatusPolling();
    return () => {};
  }

  stopPatientHistoryRequestStatusPolling();

  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.pendingRequestId = normalizedRequestId;
  }

  const timeoutMs = Math.max(
    30000,
    Number(getPatientHistoryAccessConfig()?.token_ttl || 900) * 1000
  );
  const intervalMs = 1500;
  const startedAt = Date.now();
  let stopped = false;
  let timerId = null;

  const stop = () => {
    stopped = true;
    if (timerId !== null) {
      window.clearTimeout(timerId);
      timerId = null;
    }
  };

  const onExpired = () => {
    stopPatientHistoryRequestStatusPolling();
    setPatientHistoryUiState({
      loading: false,
      showResults: false,
      status: "The verification code expired. Request a new code to continue.",
      tone: "error",
    });
    dispatchPatientHistoryEvent("es:patient-history:request-expired", {
      requestId: normalizedRequestId,
    });
  };

  const poll = async () => {
    if (stopped) {
      return;
    }

    try {
      const { response, result } = await loadPatientHistoryRequestStatus(normalizedRequestId);
      const ok = !!response && response.ok && !!result?.ok;
      const state = String(result?.state || "").trim();

      if (ok && state === "completed") {
        const accessToken = String(result?.access_token || "").trim();
        stopPatientHistoryRequestStatusPolling();

        if (accessToken) {
          dispatchPatientHistoryEvent("es:patient-history:request-completed", {
            requestId: normalizedRequestId,
            accessToken,
            response,
            result,
          });
          handlePatientHistoryIncomingToken(accessToken, {
            source: "request-status",
            ts: Date.now(),
          });
          return;
        }
      }

      if ((ok && state === "expired") || (response && response.status === 404)) {
        onExpired();
        return;
      }
    } catch (_) {
    }

    if (Date.now() - startedAt >= timeoutMs) {
      onExpired();
      return;
    }

    if (!stopped) {
      timerId = window.setTimeout(poll, intervalMs);
    }
  };

  patientHistoryRequestStatusStop = stop;
  void poll();
  return stop;
}

async function attemptPatientHistoryResume(token, requestId = "") {
  const normalizedToken = String(token || "").trim();
  const normalizedRequestId = String(requestId || "").trim();
  let completion = null;

  if (normalizedToken && normalizedRequestId) {
    try {
      completion = await completePatientHistoryRequest(normalizedRequestId, normalizedToken);
    } catch (_) {
      completion = null;
    }
  }

  const handoff = normalizedToken
    ? await attemptPatientHistoryHandoff(normalizedToken)
    : { acknowledged: false, requestId: "", source: "none" };

  return {
    ...(handoff && typeof handoff === "object" ? handoff : {}),
    acknowledged: !!handoff?.acknowledged,
    requestId: normalizedRequestId || String(handoff?.requestId || "").trim(),
    serverCompleted: !!(
      completion &&
      completion.response &&
      completion.response.ok &&
      completion.result?.ok
    ),
    completionResult: completion?.result || null,
  };
}

function handlePatientHistoryIncomingToken(token, options = {}) {
  const normalizedToken = String(token || "").trim();
  if (!normalizedToken) return false;

  const source = String(options.source || "storage").trim() || "storage";
  const timestamp = Number(options.ts || Date.now());
  const attentionMessage = String(
    options.message ||
      "Your saved details are ready in the original booking tab."
  ).trim();

  stopPatientHistoryRequestStatusPolling();
  setPatientHistoryToken(normalizedToken);
  setPatientHistoryChallengeToken("");
  setPatientHistoryCodeValue("");
  requestPatientHistoryAttention(attentionMessage);

  if (isHeadless) {
    dispatchPatientHistoryEvent("es:patient-history:handoff", {
      token: normalizedToken,
      source,
      ts: Number.isFinite(timestamp) ? timestamp : Date.now(),
    });
    return true;
  }

  setPatientHistoryStage("results");
  openPatientHistoryStandalone();
  setPatientHistoryUiState({
    loading: true,
    showResults: true,
    status: "Opening your saved details...",
    tone: "loading",
  });
  void refreshPatientHistoryLatest();
  return true;
}

function bindPatientHistoryHandoffListener() {
  if (patientHistoryHandoffListenerBound) return;
  patientHistoryHandoffListenerBound = true;

  window.addEventListener("storage", (event) => {
    if (event.storageArea !== window.localStorage) return;
    if (event.key !== getPatientHistoryHandoffStorageKey() || !event.newValue) return;

    const payload = parsePatientHistoryHandoffPayload(event.newValue);
    if (!payload || payload.senderId === patientHistoryTabId) return;

    const handled = handlePatientHistoryIncomingToken(payload.token, {
      source: "storage",
      ts: payload.ts,
    });

    if (handled) {
      publishPatientHistoryHandoffAck(payload.requestId, payload.senderId);
    }

    try {
      if (window.localStorage.getItem(getPatientHistoryHandoffStorageKey()) === event.newValue) {
        window.localStorage.removeItem(getPatientHistoryHandoffStorageKey());
      }
    } catch (_) {
    }
  });
}

function getPatientHistoryAppointmentTypeId() {
  return String(
    formHandlerData?.module_id ||
      document.getElementById("prepayment-form")?.dataset?.appointmentTypeId ||
      ""
  ).trim();
}

const PATIENT_HISTORY_STAGE_META = {
  prompt: {
    helper: "If you already completed this appointment type, we can load your most recent saved details.",
  },
  email: {
    helper: "Enter the same email address you used for your completed booking. We will email a 6-digit code.",
  },
  code: {
    helper: "Enter the 6-digit code from your email to load your saved details.",
  },
  results: {
    helper: "Review the most recent saved details for this booking type.",
  },
};

function buildPatientHistoryPrefillUrl(bookingId) {
  const template = String(getPatientHistoryAccessConfig()?.prefill_url_template || "").trim();
  if (!template || !bookingId) return "";
  return template.replace("__BOOKING_ID__", encodeURIComponent(String(bookingId)));
}

function buildHistoryRequestPayload(email, requestId = "") {
  const payload = {
    email: String(email || "").trim(),
    appointment_type_id: getPatientHistoryAppointmentTypeId(),
    return_url: getPatientHistoryReturnUrl(),
  };

  const normalizedRequestId = String(requestId || "").trim();
  if (normalizedRequestId) {
    payload.request_id = normalizedRequestId;
  }

  return payload;
}

function buildHistoryVerifyPayload(email, code, challengeToken) {
  return {
    email: String(email || "").trim(),
    code: String(code || "").trim(),
    appointment_type_id: getPatientHistoryAppointmentTypeId(),
    challenge_token: String(challengeToken || "").trim(),
  };
}

function buildPatientHistoryPreviewAnswer(question) {
  const type = String(question?.type || "");
  if (type === "checkboxes" || type === "radiobuttons") {
    const answers = Array.isArray(question?.answers) ? question.answers : [];
    const selected = answers
      .filter((answer) => !!answer?.selected)
      .map((answer) => String(answer?.value || "").trim())
      .filter(Boolean);

    if (question?.other?.selected) {
      const otherValue = String(question?.other?.value || "").trim();
      if (otherValue) {
        selected.push(otherValue);
      }
    }

    if (selected.length === 0) {
      if (Array.isArray(question?.answer)) {
        question.answer.forEach((item) => {
          const value = String(item || "").trim();
          if (value) {
            selected.push(value);
          }
        });
      } else {
        const fallback = String(question?.answer || "").trim();
        if (fallback) {
          selected.push(fallback);
        }
      }
    }

    return selected.join(", ");
  }

  return normalizePatientHistoryValue(question?.answer || "");
}

function buildPatientHistoryHeaders(patientAccessToken = "", includeRequestToken = true) {
  const headers = { "Content-Type": "application/json" };
  const requestToken = String(formHandlerData?.request_token || "").trim();
  const accessToken = String(patientAccessToken || "").trim();

  if (includeRequestToken && requestToken) {
    headers["X-ES-Request-Token"] = requestToken;
  }

  if (accessToken) {
    headers["X-ES-Patient-Access-Token"] = accessToken;
  }

  return headers;
}

function getPatientHistoryUi() {
  return {
    root: document.querySelector("[data-es-patient-history-access]"),
    helperEl: document.getElementById("es-patient-history-helper"),
    questionActions: document.getElementById("es-patient-history-question-actions"),
    yesBtn: document.getElementById("es-patient-history-yes"),
    noBtn: document.getElementById("es-patient-history-no"),
    emailRow: document.getElementById("es-patient-history-email-row"),
    emailInput: document.getElementById("es-patient-history-email"),
    requestBtn: document.getElementById("es-patient-history-request"),
    codeRow: document.getElementById("es-patient-history-code-row"),
    codeInput: document.getElementById("es-patient-history-code"),
    verifyBtn: document.getElementById("es-patient-history-verify"),
    resultsEl: document.getElementById("es-patient-history-results"),
    loadingEl: document.getElementById("es-patient-history-loading"),
    statusEl: document.getElementById("es-patient-history-status"),
  };
}

function getStepIndexForElement(element) {
  if (!element || !steps || typeof steps.length !== "number") return -1;
  return Array.from(steps).findIndex((step) => step === element);
}

function getPatientHistoryAccessSlot() {
  return document.querySelector("[data-es-patient-history-access-slot]");
}

function showPatientHistoryAccessSlot() {
  const slot = getPatientHistoryAccessSlot();
  if (slot) {
    setHidden(slot, false);
  }
}

function dismissPatientHistoryAccessSlot() {
  const slot = getPatientHistoryAccessSlot();
  if (slot) {
    setHidden(slot, true);
  }
}

function getPatientHistoryGateStep() {
  return getPatientHistoryAccessSlot()?.closest(".form-step") || null;
}

function getPatientHistoryGateStepIndex() {
  return getStepIndexForElement(getPatientHistoryGateStep());
}

function getPatientHistoryContinueStepIndex(mode = "use") {
  if (mode === "update") {
    return 0;
  }

  if (!isHeadless && shouldUseCalendarTimes()) {
    const calendarStep = document
      .querySelector("[data-appointment-selection]")
      ?.closest(".form-step");
    const calendarStepIndex = getStepIndexForElement(calendarStep);
    if (calendarStepIndex >= 0) {
      return calendarStepIndex;
    }
  }

  const patientStep = document.querySelector(".patient-grid")?.closest(".form-step");
  const patientStepIndex = getStepIndexForElement(patientStep);
  if (patientStepIndex >= 0) {
    return patientStepIndex;
  }

  return 0;
}

function syncPatientHistoryStandaloneUi() {
  if (isHeadless) return;

  const form = document.getElementById("prepayment-form");
  const gateStep = getPatientHistoryGateStep();

  if (!form || !gateStep) {
    patientHistoryStandaloneActive = false;
    return;
  }

  form.classList.toggle(
    "es-patient-history-gate-active",
    !!patientHistoryStandaloneActive
  );

  Array.from(steps || []).forEach((step) => {
    step.classList.toggle(
      "es-patient-history-step-gated",
      !!patientHistoryStandaloneActive && step === gateStep
    );
  });

  if (!patientHistoryStandaloneActive) {
    return;
  }

  Array.from(steps || []).forEach((step) => {
    setHidden(step, step !== gateStep);
  });

  setHidden(prevBtn, true);
  setHidden(nextBtn, true);
  if (progressEl) {
    setHidden(progressEl, true);
  }
}

function openPatientHistoryStandalone() {
  if (isHeadless || !isPatientHistoryAccessEnabled()) return false;

  const gateStepIndex = getPatientHistoryGateStepIndex();
  if (gateStepIndex < 0) return false;

  showPatientHistoryAccessSlot();
  patientHistoryStandaloneActive = true;
  window.currentStep = gateStepIndex;
  if (typeof showStep === "function") {
    showStep(gateStepIndex);
  } else {
    syncPatientHistoryStandaloneUi();
  }
  return true;
}

function closePatientHistoryStandalone(targetStep = 0) {
  patientHistoryStandaloneActive = false;

  const maxStepIndex = Math.max(0, (steps?.length || 1) - 1);
  const nextStepIndex = Math.max(0, Math.min(Number(targetStep || 0), maxStepIndex));
  window.currentStep = nextStepIndex;

  if (typeof showStep === "function") {
    showStep(nextStepIndex);
  } else {
    syncPatientHistoryStandaloneUi();
  }

  if (isSingleStep() && steps?.[nextStepIndex]) {
    window.setTimeout(() => {
      steps[nextStepIndex]?.scrollIntoView?.({
        behavior: "smooth",
        block: "start",
      });
    }, 80);
  }

  return nextStepIndex;
}

function getPatientHistoryEmailValue() {
  const ui = getPatientHistoryUi();
  const direct = String(ui.emailInput?.value || "").trim();
  if (direct) return direct;
  return String(getPatientEmailFromForm() || "").trim();
}

function setPatientHistoryEmailValue(value) {
  const ui = getPatientHistoryUi();
  const normalized = String(value || "").trim();
  if (ui.emailInput) {
    ui.emailInput.value = normalized;
  }
  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.email = normalized;
  }
}

function getPatientHistoryCodeValue() {
  const ui = getPatientHistoryUi();
  return String(ui.codeInput?.value || "").replace(/\D+/g, "").trim();
}

function setPatientHistoryCodeValue(value) {
  const ui = getPatientHistoryUi();
  if (ui.codeInput) {
    ui.codeInput.value = String(value || "").replace(/\D+/g, "").slice(0, 6);
  }
}

function setPatientHistoryStage(stage = "prompt") {
  const ui = getPatientHistoryUi();
  const showPrompt = stage === "prompt";
  const showEmail = stage === "email";
  const showCode = stage === "code";
  const showResults = stage === "results";
  const stageMeta = PATIENT_HISTORY_STAGE_META[stage] || PATIENT_HISTORY_STAGE_META.prompt;

  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.stage = stage;
  }

  if (ui.root) {
    ui.root.dataset.stage = stage;
  }

  if (ui.helperEl) {
    ui.helperEl.textContent = stageMeta.helper || "";
  }

  if (ui.questionActions) {
    ui.questionActions.classList.toggle("is-hidden", !showPrompt);
  }

  if (ui.emailRow) {
    ui.emailRow.classList.toggle("is-hidden", !showEmail);
  }

  if (ui.codeRow) {
    ui.codeRow.classList.toggle("is-hidden", !showCode);
  }

  if (ui.resultsEl) {
    ui.resultsEl.classList.toggle("is-hidden", !showResults);
  }

  if (stage === "email" && ui.emailInput) {
    window.requestAnimationFrame(() => ui.emailInput?.focus?.());
  }

  if (stage === "code" && ui.codeInput) {
    window.requestAnimationFrame(() => ui.codeInput?.focus?.());
  }
}

function setPatientHistoryUiState({
  loading = false,
  status = "",
  showResults = false,
  tone = "",
} = {}) {
  const ui = getPatientHistoryUi();
  const toneClass = loading
    ? "is-loading"
    : tone === "error"
      ? "is-error"
      : tone === "success"
        ? "is-success"
        : "is-info";

  if (ui.statusEl) {
    ui.statusEl.textContent = status || "";
    ui.statusEl.classList.toggle("is-hidden", !status);
    ui.statusEl.classList.remove("is-info", "is-loading", "is-success", "is-error");
    if (status) {
      ui.statusEl.classList.add(toneClass);
    }
  }

  if (ui.resultsEl) {
    ui.resultsEl.classList.toggle("is-hidden", !showResults);
  }

  if (ui.loadingEl) {
    ui.loadingEl.classList.toggle("is-hidden", !loading);
  }

  if (ui.root) {
    ui.root.classList.toggle("is-loading", !!loading);
  }
}

function clearPatientHistoryPreview() {
  const listEl = document.getElementById("es-patient-history-list");
  const previewEl = document.getElementById("es-patient-history-preview");
  if (listEl) {
    listEl.classList.remove("is-hidden");
  }
  if (!previewEl) return;
  previewEl.innerHTML = "";
  previewEl.classList.add("is-hidden");
}

function showPatientHistoryPreviewOnly() {
  const listEl = document.getElementById("es-patient-history-list");
  const previewEl = document.getElementById("es-patient-history-preview");
  if (listEl) {
    listEl.classList.add("is-hidden");
  }
  if (previewEl) {
    previewEl.classList.remove("is-hidden");
  }
}

function renderPatientHistoryEmpty(message) {
  const listEl = document.getElementById("es-patient-history-list");
  if (!listEl) return;
  listEl.innerHTML = "";
  if (!message) return;

  const empty = document.createElement("div");
  empty.className = "es-patient-history-access__empty";
  empty.textContent = message;
  listEl.appendChild(empty);
}

async function requestPatientHistoryLink(email, requestId = "") {
  const requestUrl = String(getPatientHistoryAccessConfig()?.request_url || "").trim();
  if (!requestUrl) {
    throw new Error("Patient history request endpoint is not configured.");
  }

  return postJsonExpectJson(requestUrl, buildHistoryRequestPayload(email, requestId));
}

async function requestPatientHistoryLinkWithResume(email, requestId = "") {
  const normalizedRequestId = String(requestId || createPatientHistoryRequestId()).trim();
  const output = await requestPatientHistoryLink(email, normalizedRequestId);
  const response = output?.response || null;
  const result = output?.result || {};
  const requestAccepted = !!(response && response.ok && result?.ok);
  const resolvedRequestId = String(result?.request_id || normalizedRequestId || "").trim();

  stopPatientHistoryRequestStatusPolling();
  if (requestAccepted && resolvedRequestId) {
    startPatientHistoryRequestStatusPolling(resolvedRequestId);
  }

  return {
    response,
    result,
    requestAccepted,
    requestId: resolvedRequestId,
  };
}

async function requestPatientHistoryCode(email, requestId = "") {
  return requestPatientHistoryLink(email, requestId);
}

async function verifyPatientHistoryCode(email, code, challengeToken = "") {
  const verifyUrl = String(getPatientHistoryAccessConfig()?.verify_url || "").trim();
  if (!verifyUrl) {
    throw new Error("Patient history verify endpoint is not configured.");
  }

  return postJsonExpectJson(
    verifyUrl,
    buildHistoryVerifyPayload(email, code, challengeToken || getPatientHistoryChallengeToken())
  );
}

async function loadPatientHistoryRequestStatus(requestId) {
  const statusUrl = getPatientHistoryRequestStatusUrl(requestId);
  if (!statusUrl) {
    return { response: null, result: { ok: false, message: "Patient history request status endpoint is not configured." } };
  }

  return getJsonExpectJson(statusUrl);
}

async function completePatientHistoryRequest(requestId, token = "") {
  const completeUrl = getPatientHistoryRequestCompleteUrl();
  const normalizedRequestId = String(requestId || "").trim();
  const accessToken = String(token || getPatientHistoryToken() || "").trim();
  if (!completeUrl || !normalizedRequestId || !accessToken) {
    return { response: null, result: { ok: false, message: "Missing patient access request details." } };
  }

  const response = await fetch(completeUrl, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...buildPatientHistoryHeaders(accessToken, false),
    },
    body: JSON.stringify({ request_id: normalizedRequestId }),
  });
  const result = await response.json().catch(() => ({}));
  return { response, result };
}

async function loadPatientHistoryAppointments(token = "", limit = null) {
  const appointmentsUrl = String(getPatientHistoryAccessConfig()?.appointments_url || "").trim();
  const accessToken = String(token || getPatientHistoryToken() || "").trim();
  if (!appointmentsUrl || !accessToken) {
    return { response: null, result: { ok: false, message: "Missing patient access token." } };
  }

  const url = new URL(appointmentsUrl, window.location.origin);
  const historyLimit = Number(limit || getPatientHistoryAccessConfig()?.limit || 5);
  if (Number.isFinite(historyLimit) && historyLimit > 0) {
    url.searchParams.set("limit", String(Math.max(1, Math.min(10, Math.round(historyLimit)))));
  }

  const response = await fetch(url.toString(), {
    method: "GET",
    credentials: "same-origin",
    headers: buildPatientHistoryHeaders(accessToken, false),
  });
  const result = await response.json().catch(() => ({}));
  return { response, result };
}

async function loadPatientHistoryLatest(token = "") {
  const latestUrl = String(getPatientHistoryAccessConfig()?.latest_url || "").trim();
  const accessToken = String(token || getPatientHistoryToken() || "").trim();
  if (!latestUrl || !accessToken) {
    return { response: null, result: { ok: false, message: "Missing patient access token." } };
  }

  const response = await fetch(latestUrl, {
    method: "GET",
    credentials: "same-origin",
    headers: buildPatientHistoryHeaders(accessToken, false),
  });
  const result = await response.json().catch(() => ({}));
  return { response, result };
}

async function loadPatientHistoryPrefill(bookingId, token = "") {
  const url = buildPatientHistoryPrefillUrl(bookingId);
  const accessToken = String(token || getPatientHistoryToken() || "").trim();
  if (!url || !accessToken) {
    return { response: null, result: { ok: false, message: "Missing patient access token." } };
  }

  const response = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: buildPatientHistoryHeaders(accessToken, false),
  });
  const result = await response.json().catch(() => ({}));
  return { response, result };
}

function dispatchPatientHistoryEvent(name, detail) {
  document.dispatchEvent(new CustomEvent(name, { detail }));
}

function setFormControlValue(input, value) {
  if (!input) return;
  input.value = value;
  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.dispatchEvent(new Event("change", { bubbles: true }));
  input.dispatchEvent(new Event("blur", { bubbles: true }));
}

function setCheckboxLikeValue(input, checked) {
  if (!input) return;
  input.checked = !!checked;
  input.dispatchEvent(new Event("change", { bubbles: true }));
}

function applyPatientPrefillToForm(patient) {
  const patientData = patient && typeof patient === "object" ? patient : {};

  Object.entries(patientData).forEach(([key, value]) => {
    const selector = `[name="patient[${escapeFieldNameForSelector(key)}]"]`;
    const input = document.querySelector(selector);
    if (!input) return;
    setFormControlValue(input, normalizePatientHistoryValue(value));
  });
}

function applyQuestionPrefillToForm(question) {
  const name = String(question?.name || "").trim();
  const type = String(question?.type || "").trim();
  if (!name) return;

  if (type === "checkboxes") {
    const selectedValues = new Set();
    const answers = Array.isArray(question?.answers) ? question.answers : [];
    answers.forEach((answer) => {
      if (answer?.selected) {
        const value = String(answer?.value || "").trim();
        if (value) selectedValues.add(value);
      }
    });

    const fallbackAnswer = Array.isArray(question?.answer) ? question.answer : [];
    fallbackAnswer.forEach((answer) => {
      const value = String(answer || "").trim();
      if (value) selectedValues.add(value);
    });

    document
      .querySelectorAll(`[name="${escapeFieldNameForSelector(name)}[]"]`)
      .forEach((input) => {
        const value = String(input?.value || "");
        const isOther = value === "__other__" || value === "other";
        setCheckboxLikeValue(input, isOther ? !!question?.other?.selected : selectedValues.has(value));
      });

    const otherInput = document.querySelector(
      `[name="${escapeFieldNameForSelector(`${name}_other`)}"]`
    );
    setFormControlValue(
      otherInput,
      question?.other?.selected ? normalizePatientHistoryValue(question?.other?.value || "") : ""
    );
    return;
  }

  if (type === "radiobuttons") {
    let selectedValue = "";
    const answers = Array.isArray(question?.answers) ? question.answers : [];
    answers.forEach((answer) => {
      if (!selectedValue && answer?.selected) {
        selectedValue = String(answer?.value || "").trim();
      }
    });

    if (!selectedValue && typeof question?.answer === "string") {
      selectedValue = String(question.answer || "").trim();
    }

    document
      .querySelectorAll(`[name="${escapeFieldNameForSelector(name)}"]`)
      .forEach((input) => {
        const value = String(input?.value || "");
        const isOther = value === "__other__" || value === "other";
        const checked = isOther ? !!question?.other?.selected : selectedValue !== "" && value === selectedValue;
        setCheckboxLikeValue(input, checked);
      });

    const otherInput = document.querySelector(
      `[name="${escapeFieldNameForSelector(`${name}_other`)}"]`
    );
    setFormControlValue(
      otherInput,
      question?.other?.selected ? normalizePatientHistoryValue(question?.other?.value || "") : ""
    );
    return;
  }

  const input = document.querySelector(`[name="${escapeFieldNameForSelector(name)}"]`);
  if (!input) return;
  setFormControlValue(input, normalizePatientHistoryValue(question?.answer || ""));
}

function applyContentPrefillToForm(content) {
  const sections = Array.isArray(content?.sections) ? content.sections : [];
  sections.forEach((section) => {
    const questions = Array.isArray(section?.questions) ? section.questions : [];
    questions.forEach((question) => applyQuestionPrefillToForm(question));
  });
}

async function applyPatientHistoryPrefill(data, mode = "use") {
  const payload = data?.prefill || null;
  if (!payload || typeof payload !== "object") {
    showToast("We could not load that previous form.", "error");
    return false;
  }

  const currentModuleId = String(formHandlerData?.module_id || "").trim();
  const historyModuleId = String(payload?.history?.appointment_type_id || "").trim();
  const patient = payload?.patient && typeof payload.patient === "object" ? { ...payload.patient } : {};

  if (historyModuleId && currentModuleId && historyModuleId !== currentModuleId) {
    patient.practitioner_id = "";
  }

  patient.appointment_start = "";
  patient.appointment_date = "";

  if (isHeadless) {
    if (
      data?.latest_form?.patient_form_template_id &&
      window.ClinikoHeadlessCalendar &&
      typeof window.ClinikoHeadlessCalendar.updateFormtemplate === "function" &&
      String(formHandlerData?.patient_form_template_id || "").trim() !==
        String(data.latest_form.patient_form_template_id || "").trim()
    ) {
      try {
        await window.ClinikoHeadlessCalendar.updateFormtemplate(
          String(data.latest_form.patient_form_template_id || "").trim()
        );
      } catch (_) {
      }
    }

    const headlessPayload = getHeadlessPayload();
    if (headlessPayload && typeof headlessPayload === "object") {
      headlessPayload.patient = {
        ...(headlessPayload.patient && typeof headlessPayload.patient === "object"
          ? headlessPayload.patient
          : {}),
        ...patient,
      };
      headlessPayload.content = {
        sections: Array.isArray(payload?.content?.sections) ? payload.content.sections : [],
      };
    }
  } else {
    applyPatientPrefillToForm(patient);
    applyContentPrefillToForm(payload?.content || {});
  }

  clearPatientHistoryPreview();
  dispatchPatientHistoryEvent("es:patient-history:prefill-applied", {
    mode,
    payload,
    raw: data,
  });

  const targetStepIndex = getPatientHistoryContinueStepIndex(mode);
  const calendarStepIndex = getStepIndexForElement(
    document.querySelector("[data-appointment-selection]")?.closest(".form-step")
  );
  const routesToCalendar = mode !== "update" && calendarStepIndex >= 0 && targetStepIndex === calendarStepIndex;

  if (!isHeadless && typeof showStep === "function") {
    dismissPatientHistoryAccessSlot();
    closePatientHistoryStandalone(targetStepIndex);
  }

  const sameAppointmentType =
    historyModuleId !== "" && currentModuleId !== "" && historyModuleId === currentModuleId;
  showToast(
    sameAppointmentType
      ? mode === "update"
        ? "Previous details loaded. Review and update anything you want."
        : routesToCalendar
          ? "Previous details loaded. Choose a new appointment time to continue."
          : "Previous details loaded. Confirm your details and continue when ready."
      : "Previous details loaded. This booking page uses a different appointment type, so please review everything before continuing.",
    "success"
  );
  return true;
}

function formatPatientHistoryDateTime(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";

  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) {
    return raw;
  }

  try {
    return new Intl.DateTimeFormat(undefined, {
      weekday: "short",
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    }).format(parsed);
  } catch (_) {
    return parsed.toLocaleString();
  }
}

function renderPatientHistoryPreview(data) {
  const previewEl = document.getElementById("es-patient-history-preview");
  if (!previewEl) return;

  const prefill = data?.prefill || {};
  const patient = prefill?.patient && typeof prefill.patient === "object" ? prefill.patient : {};
  const sections = Array.isArray(prefill?.content?.sections) ? prefill.content.sections : [];
  const appointment = data?.appointment || {};

  previewEl.innerHTML = "";
  showPatientHistoryPreviewOnly();

  const head = document.createElement("div");
  head.className = "es-patient-history-access__preview-head";

  const title = document.createElement("h5");
  title.className = "es-patient-history-access__preview-title";
  title.textContent = "Your most recent saved details";

  const useAnotherEmailBtn = document.createElement("button");
  useAnotherEmailBtn.type = "button";
  useAnotherEmailBtn.className = "es-patient-history-access__back";
  useAnotherEmailBtn.textContent = "Use another email";
  useAnotherEmailBtn.addEventListener("click", () => {
    clearPatientHistoryPreview();
    stopPatientHistoryRequestStatusPolling();
    setPatientHistoryToken("");
    setPatientHistoryChallengeToken("");
    setPatientHistoryCodeValue("");
    setPatientHistoryStage("email");
    setPatientHistoryUiState({
      loading: false,
      showResults: false,
      status: "",
      tone: "info",
    });
  });

  head.appendChild(title);
  head.appendChild(useAnotherEmailBtn);
  previewEl.appendChild(head);

  const patientBlock = document.createElement("div");
  patientBlock.className = "es-patient-history-access__preview-block";

  [
    ["Patient", `${patient?.first_name || ""} ${patient?.last_name || ""}`.trim()],
    ["Email", patient?.email || ""],
    ["Phone", patient?.phone || ""],
    ["Appointment", appointment?.appointment_label || ""],
    ["When", formatPatientHistoryDateTime(appointment?.starts_at || "")],
  ].forEach(([label, value]) => {
    if (!value) return;
    const row = document.createElement("div");
    row.className = "es-patient-history-access__preview-row";
    const labelEl = document.createElement("div");
    labelEl.className = "es-patient-history-access__preview-label";
    labelEl.textContent = String(label);
    const valueEl = document.createElement("div");
    valueEl.className = "es-patient-history-access__preview-value";
    valueEl.textContent = String(value);
    row.appendChild(labelEl);
    row.appendChild(valueEl);
    patientBlock.appendChild(row);
  });

  previewEl.appendChild(patientBlock);

  const answersBlock = document.createElement("div");
  answersBlock.className = "es-patient-history-access__preview-block";

  let answerCount = 0;
  sections.forEach((section) => {
    const questions = Array.isArray(section?.questions) ? section.questions : [];
    questions.forEach((question) => {
      const value = buildPatientHistoryPreviewAnswer(question);
      if (!value) return;

      answerCount += 1;
      const row = document.createElement("div");
      row.className = "es-patient-history-access__preview-row";
      const labelEl = document.createElement("div");
      labelEl.className = "es-patient-history-access__preview-label";
      labelEl.textContent = String(section?.name || "Question");
      const valueEl = document.createElement("div");
      valueEl.className = "es-patient-history-access__preview-value";
      const questionStrong = document.createElement("strong");
      questionStrong.textContent = String(question?.name || "");
      valueEl.appendChild(questionStrong);
      valueEl.appendChild(document.createElement("br"));
      valueEl.appendChild(document.createTextNode(String(value)));
      row.appendChild(labelEl);
      row.appendChild(valueEl);
      answersBlock.appendChild(row);
    });
  });

  if (answerCount > 0) {
    previewEl.appendChild(answersBlock);
  } else {
    const empty = document.createElement("div");
    empty.className = "es-patient-history-access__empty";
    empty.textContent =
      "No reusable questionnaire answers were attached to this previous appointment, but we can still load your saved patient details.";
    previewEl.appendChild(empty);
  }

  const actions = document.createElement("div");
  actions.className = "es-patient-history-access__item-actions";

  const useBtn = document.createElement("button");
  useBtn.type = "button";
  useBtn.className = "es-patient-history-access__item-btn";
  useBtn.textContent = answerCount > 0 ? "Use previous form" : "Use saved details";
  useBtn.addEventListener("click", async () => {
    await applyPatientHistoryPrefill(data, "use");
  });

  const updateBtn = document.createElement("button");
  updateBtn.type = "button";
  updateBtn.className = "es-patient-history-access__item-btn";
  updateBtn.textContent = "Load and update";
  updateBtn.addEventListener("click", async () => {
    await applyPatientHistoryPrefill(data, "update");
  });

  actions.appendChild(useBtn);
  actions.appendChild(updateBtn);
  previewEl.appendChild(actions);
}

function renderPatientHistoryAppointments(items) {
  const listEl = document.getElementById("es-patient-history-list");
  if (!listEl) return;

  listEl.innerHTML = "";
  clearPatientHistoryPreview();

  if (!Array.isArray(items) || items.length === 0) {
    renderPatientHistoryEmpty("We could not find any past appointments for this verification code.");
    return;
  }

  items.forEach((item) => {
    const card = document.createElement("article");
    card.className = "es-patient-history-access__item";

    const head = document.createElement("div");
    head.className = "es-patient-history-access__item-head";
    const headInfo = document.createElement("div");
    const headTitle = document.createElement("h5");
    headTitle.className = "es-patient-history-access__item-title";
    headTitle.textContent = String(item?.appointment_label || "Previous appointment");
    const headMeta = document.createElement("p");
    headMeta.className = "es-patient-history-access__item-meta";
    headMeta.textContent = `${item?.starts_at || "Date unavailable"}${
      item?.practitioner_name ? ` · ${item.practitioner_name}` : ""
    }`;
    headInfo.appendChild(headTitle);
    headInfo.appendChild(headMeta);

    const badge = document.createElement("span");
    badge.className = "es-patient-history-access__badge";
    badge.textContent = String(item?.status || "completed");

    head.appendChild(headInfo);
    head.appendChild(badge);

    const meta = document.createElement("div");
    meta.className = "es-patient-history-access__item-meta";
    const knowsFormState = typeof item?.has_form === "boolean";
    meta.textContent = knowsFormState
      ? item?.has_form
        ? `Attached forms: ${Number(item?.forms_count || 0)}`
        : "No attached form was found for this appointment."
      : "Review this previous booking to reuse its saved details or questionnaire.";

    const actions = document.createElement("div");
    actions.className = "es-patient-history-access__item-actions";

    const reviewBtn = document.createElement("button");
    reviewBtn.type = "button";
    reviewBtn.className = "es-patient-history-access__item-btn";
    reviewBtn.textContent = knowsFormState
      ? item?.has_form
        ? "Review previous form"
        : "Load saved details"
      : "Review previous details";
    reviewBtn.addEventListener("click", async () => {
      setPatientHistoryUiState({
        loading: true,
        showResults: true,
        status: "Loading previous form details...",
        tone: "loading",
      });

      const { response, result } = await loadPatientHistoryPrefill(
        item?.booking_id || item?.appointment_id || ""
      );
      setPatientHistoryUiState({
        loading: false,
        showResults: true,
        status:
          response && response.ok && result?.ok
            ? "Review the previous details below."
            : result?.message || "We could not load that previous form.",
        tone: response && response.ok && result?.ok ? "success" : "error",
      });

      if (!response || !response.ok || !result?.ok) {
        clearPatientHistoryPreview();
        return;
      }

      if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
        window.ClinikoPatientHistoryAccess.state.lastPrefill = result?.data || null;
      }
      dispatchPatientHistoryEvent("es:patient-history:prefill-loaded", {
        bookingId: item?.booking_id || item?.appointment_id || "",
        data: result?.data || null,
      });
      renderPatientHistoryPreview(result?.data || {});
    });

    actions.appendChild(reviewBtn);
    card.appendChild(head);
    card.appendChild(meta);
    card.appendChild(actions);
    listEl.appendChild(card);
  });
}

async function refreshPatientHistoryLatest() {
  const token = getPatientHistoryToken();
  if (!token) return null;

  setPatientHistoryStage("results");
  setPatientHistoryUiState({
    loading: true,
    showResults: true,
    status: "Loading your latest saved details...",
    tone: "loading",
  });

  const { response, result } = await loadPatientHistoryLatest(token);
  const ok = !!response && response.ok && !!result?.ok && !!result?.data;
  const latestData = ok ? result.data : null;

  if (window.ClinikoPatientHistoryAccess && window.ClinikoPatientHistoryAccess.state) {
    window.ClinikoPatientHistoryAccess.state.appointments = [];
    window.ClinikoPatientHistoryAccess.state.lastPrefill = latestData;
  }

  setPatientHistoryUiState({
    loading: false,
    showResults: true,
    status: ok
      ? "Review your most recent saved details."
      : result?.message || "We could not load your saved details.",
    tone: ok ? "success" : "error",
  });

  if (ok) {
    renderPatientHistoryPreview(latestData);
  } else {
    clearPatientHistoryPreview();
    renderPatientHistoryEmpty(result?.message || "No completed appointment was found for this verification code.");
  }

  dispatchPatientHistoryEvent("es:patient-history:loaded", {
    ok,
    appointments: [],
    data: latestData,
    response,
    result,
  });

  dispatchPatientHistoryEvent("es:patient-history:prefill-loaded", {
    ok,
    bookingId: latestData?.appointment?.booking_id || "",
    data: latestData,
    response,
    result,
  });

  return { response, result };
}

function bindPatientHistoryChoiceButtons() {
  const ui = getPatientHistoryUi();

  if (ui.yesBtn) {
    ui.yesBtn.addEventListener("click", () => {
      const seededEmail = getPatientHistoryEmailValue();
      if (seededEmail) {
        setPatientHistoryEmailValue(seededEmail);
      }

      if (getPatientHistoryToken()) {
        setPatientHistoryStage("results");
        void refreshPatientHistoryLatest();
        return;
      }

      setPatientHistoryStage("email");
      setPatientHistoryUiState({
        status: "",
        showResults: false,
        tone: "info",
      });
    });
  }

  if (ui.noBtn) {
    ui.noBtn.addEventListener("click", () => {
      stopPatientHistoryRequestStatusPolling();
      setPatientHistoryChallengeToken("");
      setPatientHistoryCodeValue("");
      clearPatientHistoryPreview();
      renderPatientHistoryEmpty("");
      setPatientHistoryStage("prompt");
      setPatientHistoryUiState({
        status: "",
        showResults: false,
        tone: "info",
      });
      dismissPatientHistoryAccessSlot();
      closePatientHistoryStandalone(0);
    });
  }
}

function bindPatientHistoryRequestButton() {
  const ui = getPatientHistoryUi();
  const requestBtn = ui.requestBtn;
  if (!requestBtn) return;

  requestBtn.addEventListener("click", async () => {
    const email = getPatientHistoryEmailValue();
    if (!email) {
      setPatientHistoryUiState({
        status: "Please enter your email address first.",
        showResults: false,
        tone: "error",
      });
      showToast("Please enter your email address first.", "error");
      return;
    }

    setPatientHistoryEmailValue(email);
    requestBtn.disabled = true;
    setPatientHistoryUiState({
      status: "Sending verification code...",
      showResults: false,
      tone: "loading",
    });

    try {
      const { response, result } = await requestPatientHistoryCode(email);
      const requestAccepted = !!(response && response.ok && result?.ok);
      const resolvedRequestId = String(result?.request_id || "").trim();
      const challengeToken = String(result?.challenge_token || "").trim();
      const message = String(
        result?.message || (
          requestAccepted
            ? "If matching completed appointments exist for this booking type, we emailed a 6-digit code."
            : "We could not send the verification code."
        )
      );

      setPatientHistoryChallengeToken(challengeToken);
      setPatientHistoryCodeValue("");

      setPatientHistoryStage(requestAccepted ? "code" : "email");
      setPatientHistoryUiState({
        status: message,
        showResults: false,
        tone: requestAccepted ? "success" : "error",
      });

      showToast(message, requestAccepted ? "success" : "error");
      dispatchPatientHistoryEvent("es:patient-history:request-sent", {
        ok: requestAccepted,
        accepted: requestAccepted,
        requestId: resolvedRequestId,
        response,
        result,
      });
    } catch (error) {
      const message = "We could not send the verification code.";
      setPatientHistoryUiState({
        status: message,
        showResults: false,
        tone: "error",
      });
      setPatientHistoryStage("email");
      showToast(message, "error");
    } finally {
      requestBtn.disabled = false;
    }
  });
}

function bindPatientHistoryVerifyButton() {
  const ui = getPatientHistoryUi();
  const verifyBtn = ui.verifyBtn;
  if (!verifyBtn) return;

  verifyBtn.addEventListener("click", async () => {
    const email = getPatientHistoryEmailValue();
    const code = getPatientHistoryCodeValue();
    const challengeToken = getPatientHistoryChallengeToken();

    if (!email) {
      setPatientHistoryStage("email");
      setPatientHistoryUiState({
        status: "Please enter your email address first.",
        showResults: false,
        tone: "error",
      });
      return;
    }

    if (!/^\d{6}$/.test(code)) {
      setPatientHistoryUiState({
        status: "Please enter the 6-digit code from your email.",
        showResults: false,
        tone: "error",
      });
      return;
    }

    verifyBtn.disabled = true;
    setPatientHistoryUiState({
      loading: true,
      status: "Verifying code...",
      showResults: false,
      tone: "loading",
    });

    try {
      const { response, result } = await verifyPatientHistoryCode(email, code, challengeToken);
      const verified = !!(response && response.ok && result?.ok && result?.access_token);

      if (!verified) {
        setPatientHistoryStage("code");
        setPatientHistoryUiState({
          loading: false,
          status: String(result?.message || "The verification code is invalid or has expired."),
          showResults: false,
          tone: "error",
        });
        return;
      }

      setPatientHistoryToken(String(result.access_token || ""));
      setPatientHistoryChallengeToken("");
      setPatientHistoryCodeValue("");
      setPatientHistoryStage("results");
      await refreshPatientHistoryLatest();
    } catch (_) {
      setPatientHistoryStage("code");
      setPatientHistoryUiState({
        loading: false,
        status: "We could not verify the code. Please try again.",
        showResults: false,
        tone: "error",
      });
    } finally {
      verifyBtn.disabled = false;
    }
  });
}

function continuePatientHistoryAccessBootstrap() {
  if (getPatientHistoryToken()) {
    if (isHeadless) {
      return;
    }

    setPatientHistoryStage("results");
    openPatientHistoryStandalone();
    void refreshPatientHistoryLatest();
    return;
  }

  if (isHeadless || !isPatientHistoryAccessEnabled()) {
    return;
  }

  if (isPatientHistoryAccessEnabled()) {
    setPatientHistoryStage("prompt");
    setPatientHistoryEmailValue(getPatientEmailFromForm());
    setPatientHistoryUiState({
      status: "",
      showResults: false,
      tone: "info",
    });
    openPatientHistoryStandalone();
  }
}

function initPatientHistoryAccess() {
  const config = getPatientHistoryAccessConfig();
  const locationBootstrap = readPatientHistoryBootstrapFromLocation();
  const locationToken = String(locationBootstrap?.token || "").trim();

  window.ClinikoPatientHistoryAccess = {
    state: {
      token: "",
      challengeToken: "",
      email: "",
      stage: "prompt",
      appointments: [],
      lastPrefill: null,
      pendingRequestId: "",
    },
    config,
    getToken: () => getPatientHistoryToken(),
    setToken: (token) => setPatientHistoryToken(token),
    getChallengeToken: () => getPatientHistoryChallengeToken(),
    setChallengeToken: (token) => setPatientHistoryChallengeToken(token),
    requestCode: (email, requestId = "") => requestPatientHistoryCode(email, requestId),
    requestLink: (email, requestId = "") => requestPatientHistoryCode(email, requestId),
    verifyCode: (email, code, challengeToken = "") => {
      if (challengeToken) setPatientHistoryChallengeToken(challengeToken);
      return verifyPatientHistoryCode(email, code, challengeToken);
    },
    loadLatest: (token = "") => {
      if (token) setPatientHistoryToken(token);
      return refreshPatientHistoryLatest();
    },
    loadAppointments: (token = "") => {
      if (token) setPatientHistoryToken(token);
      return loadPatientHistoryAppointments();
    },
    loadPrefill: (bookingId, token = "") => {
      if (token) setPatientHistoryToken(token);
      return loadPatientHistoryPrefill(bookingId);
    },
    loadRequestStatus: (requestId) => loadPatientHistoryRequestStatus(requestId),
    completeRequest: (requestId, token = "") => completePatientHistoryRequest(requestId, token),
    startRequestPolling: (requestId) => startPatientHistoryRequestStatusPolling(requestId),
    stopRequestPolling: () => stopPatientHistoryRequestStatusPolling(),
    applyPrefill: (data, mode = "use") => applyPatientHistoryPrefill(data, mode),
    clearToken: () => {
      stopPatientHistoryRequestStatusPolling();
      setPatientHistoryToken("");
      setPatientHistoryChallengeToken("");
      setPatientHistoryCodeValue("");
    },
  };

  bindPatientHistoryChoiceButtons();
  bindPatientHistoryRequestButton();
  bindPatientHistoryVerifyButton();

  if (locationToken) {
    setPatientHistoryToken(locationToken);
  }

  window.__esPatientHistoryHandoffAttempt = null;
  window.__esPatientHistoryHandoffResult = null;
  continuePatientHistoryAccessBootstrap();
}

function getHeadlessPayload() {
  if (typeof window.clinikoGetHeadlessPayload === "function") {
    try {
      return window.clinikoGetHeadlessPayload();
    } catch (e) {
      console.error("clinikoGetHeadlessPayload error:", e);
      return null;
    }
  }
  if (window.clinikoHeadlessPayload && typeof window.clinikoHeadlessPayload === "object") {
    return window.clinikoHeadlessPayload;
  }
  return null;
}

function normalizeHeadlessPayload(payload) {
  if (!payload || typeof payload !== "object") return null;
  const content = payload.content || null;
  const patient = payload.patient || null;
  if (!content || !patient) return null;
  return { content, patient };
}

function toDateInputValue(date) {
  const tzOffset = date.getTimezoneOffset() * 60000;
  return new Date(date.getTime() - tzOffset).toISOString().slice(0, 10);
}

async function initAvailableTimesPicker() {
  const useCalendar = shouldUseCalendarTimes();
  const usePractitionerSelect = shouldUsePractitionerSelection();
  if (!useCalendar && !usePractitionerSelect) return;

  const formEl = document.getElementById("prepayment-form");
  const calendarGrid = useCalendar ? document.getElementById("appointment-calendar-grid") : null;
  const dayTitle = useCalendar ? document.getElementById("appointment-day-title") : null;
  const morningSlots = useCalendar ? document.getElementById("appointment-day-slots-morning") : null;
  const afternoonSlots = useCalendar ? document.getElementById("appointment-day-slots-afternoon") : null;
  const eveningSlots = useCalendar ? document.getElementById("appointment-day-slots-evening") : null;
  const emptySlots = useCalendar ? document.getElementById("appointment-day-empty") : null;
  const hiddenInput = useCalendar ? document.getElementById("appointment-time") : null;
  const statusEl = useCalendar ? document.getElementById("appointment-time-status") : null;
  const selectionWrap = document.querySelector("[data-appointment-selection]");
  const practitionerSelect = document.getElementById("appointment-practitioner");
  const practitionerSelectWrap = document.querySelector("[data-practitioner-select]");
  const dayHint = useCalendar ? document.getElementById("appointment-day-hint") : null;
  const dayPlaceholder = useCalendar ? document.getElementById("appointment-day-placeholder") : null;
  const dayTimesWrap = useCalendar ? document.querySelector(".appointment-day-times") : null;
  const dayLoading = useCalendar ? document.getElementById("appointment-day-loading") : null;

  if (!formEl) return;

  const calendarPrevBtn = formEl.querySelector("[data-calendar-nav='prev']");
  const calendarNextBtn = formEl.querySelector("[data-calendar-nav='next']");

  const calendarReady =
    useCalendar &&
    calendarGrid &&
    hiddenInput &&
    dayTitle &&
    morningSlots &&
    afternoonSlots &&
    eveningSlots;

  const endpoint = formHandlerData?.available_times_url;
  const practitionersEndpoint = formHandlerData?.practitioners_url;
  const calendarEndpoint = formHandlerData?.appointment_calendar_url;
  const appointmentTypeId =
    formEl.dataset.appointmentTypeId || formHandlerData?.module_id || "";
  let practitionerId = formEl.dataset.practitionerId || "";

  if (!appointmentTypeId) {
    if (statusEl) {
      statusEl.textContent = "Appointment type not configured.";
      statusEl.classList.remove("is-hidden");
      statusEl.classList.add("is-error");
    }
    return;
  }

  if (useCalendar && !endpoint) {
    if (statusEl) {
      statusEl.textContent = "Available times endpoint not configured.";
      statusEl.classList.remove("is-hidden");
      statusEl.classList.add("is-error");
    }
    return;
  }

  const perPage = Math.min(
    100,
    Math.max(1, Number(formHandlerData?.available_times_per_page || 100))
  );
  const frontendCache = getCalendarFrontendCacheStore();
  const frontendCacheTtlMs = {
    practitioners: 5 * 60 * 1000,
    calendar: 2 * 60 * 1000,
    timesPage: 30 * 1000,
  };
  let calendarRequestSeq = 0;
  let dayRequestSeq = 0;

  function setStatus(message, isError = false) {
    if (!statusEl) return;
    statusEl.textContent = message || "";
    statusEl.classList.toggle("is-hidden", !message);
    statusEl.classList.toggle("is-error", !!isError);
  }

  function setDayHint(message) {
    if (!dayHint) return;
    dayHint.textContent = message || "";
  }

  function setPlaceholderVisible(show) {
    if (dayPlaceholder) {
      dayPlaceholder.classList.toggle("is-hidden", !show);
    }
    if (dayTimesWrap) {
      dayTimesWrap.classList.toggle("is-placeholder", !!show);
    }
  }

  function setDayLoading(show, message = "") {
    if (dayLoading) {
      dayLoading.classList.toggle("is-hidden", !show);
      const text = dayLoading.querySelector(".appointment-day-times__loading-text");
      if (text) text.textContent = message || "Loading available times…";
    }
    if (dayTimesWrap) {
      dayTimesWrap.classList.toggle("is-loading", !!show);
    }
  }

  function clearDayTimes() {
    if (!calendarReady) return;
    [morningSlots, afternoonSlots, eveningSlots].forEach((slot) => {
      if (!slot) return;
      slot.innerHTML = "";
    });
    if (emptySlots) emptySlots.classList.add("is-hidden");
    if (dayTitle) dayTitle.textContent = "Select a day";
    setDayHint("Choose a date to see available times.");
    setPlaceholderVisible(true);
    setDayLoading(false);
  }

  function resetSelectedTime() {
    if (!calendarReady || !hiddenInput) return;
    hiddenInput.value = "";
    clearSelectedTime();
  }

  function getMonthKeyFromDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    return `${year}-${month}`;
  }

  function shiftMonthKey(monthKey, delta) {
    if (!monthKey) return "";
    const [yearStr, monthStr] = monthKey.split("-");
    const year = Number(yearStr);
    const month = Number(monthStr);
    if (!year || !month) return "";
    const dt = new Date(year, month - 1 + delta, 1);
    return getMonthKeyFromDate(dt);
  }

  let currentMonthKey = calendarGrid?.dataset?.calendarMonth || "";
  if (!currentMonthKey) {
    currentMonthKey = getMonthKeyFromDate(new Date());
    if (calendarGrid) calendarGrid.dataset.calendarMonth = currentMonthKey;
  }

  function updateNavState() {
    if (!calendarPrevBtn) return;
    const thisMonthKey = getMonthKeyFromDate(new Date());
    if (!currentMonthKey) {
      calendarPrevBtn.disabled = true;
      return;
    }
    calendarPrevBtn.disabled = currentMonthKey <= thisMonthKey;
  }

async function fetchJson(url, fallbackMessage) {
  const res = await fetch(url, {
    method: "GET",
    headers: {
      Accept: "application/json",
      ...(formHandlerData?.request_token
        ? { "X-ES-Request-Token": String(formHandlerData.request_token).trim() }
        : {}),
    },
    credentials: "same-origin",
  });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || fallbackMessage || "Request failed.");
    }
    return data?.data ?? data ?? {};
  }

  async function fetchPractitioners() {
    if (!practitionersEndpoint || !appointmentTypeId) return [];
    const cacheKey = `cliniko:practitioners:${appointmentTypeId}`;
    const cached = readCalendarCacheValue(frontendCache, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(frontendCache, cacheKey, async () => {
      const url = new URL(practitionersEndpoint, window.location.origin);
      url.searchParams.set("appointment_type_id", appointmentTypeId);
      const payload = await fetchJson(url.toString(), "Failed to load practitioners.");
      const list = Array.isArray(payload.practitioners) ? payload.practitioners : [];
      writeCalendarCacheValue(frontendCache, cacheKey, list, frontendCacheTtlMs.practitioners);
      return list;
    });
  }

  function populatePractitionerSelect(list) {
    if (!practitionerSelect || !practitionerSelectWrap) return;
    if (!Array.isArray(list) || list.length === 0) {
      practitionerSelect.innerHTML = "";
      const option = document.createElement("option");
      option.value = "";
      option.textContent = "No practitioners available";
      practitionerSelect.appendChild(option);
      practitionerSelect.disabled = true;
      practitionerSelectWrap.classList.remove("is-hidden");
      return;
    }

    practitionerSelect.innerHTML = "";
    list.forEach((item) => {
      const option = document.createElement("option");
      option.value = item.id;
      option.textContent = item.name || item.id;
      practitionerSelect.appendChild(option);
    });

    const defaultId = practitionerId || list[0]?.id || "";
    if (defaultId) {
      practitionerSelect.value = defaultId;
      practitionerId = defaultId;
      formEl.dataset.practitionerId = defaultId;
    }

    practitionerSelect.disabled = false;
    practitionerSelectWrap.classList.remove("is-hidden");
  }

  async function fetchCalendarPayload(practitioner, monthKey = null) {
    if (!calendarEndpoint || !appointmentTypeId) {
      throw new Error("Calendar endpoint not configured.");
    }
    const month = monthKey || currentMonthKey || "";
    const cacheKey = `cliniko:calendar:${appointmentTypeId}:${practitioner || ""}:${month}`;
    const cached = readCalendarCacheValue(frontendCache, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(frontendCache, cacheKey, async () => {
      const url = new URL(calendarEndpoint, window.location.origin);
      url.searchParams.set("appointment_type_id", appointmentTypeId);
      if (practitioner) url.searchParams.set("practitioner_id", practitioner);
      if (month) url.searchParams.set("month", month);
      const data = await fetchJson(url.toString(), "Failed to load calendar.");
      writeCalendarCacheValue(frontendCache, cacheKey, data, frontendCacheTtlMs.calendar);
      return data;
    });
  }

  async function prefetchCalendarMonth(practitioner, monthKey) {
    if (!practitioner || !monthKey) return false;
    try {
      await fetchCalendarPayload(practitioner, monthKey);
      return true;
    } catch (_) {
      return false;
    }
  }

  async function prefetchCalendarWindow(practitioner, baseMonthKey) {
    const base = baseMonthKey || currentMonthKey || getMonthKeyFromDate(new Date());
    if (!practitioner || !base) return false;
    const next = shiftMonthKey(base, 1);
    const tasks = [prefetchCalendarMonth(practitioner, base)];
    if (next) tasks.push(prefetchCalendarMonth(practitioner, next));
    await Promise.allSettled(tasks);
    return true;
  }

  async function refreshCalendar(practitioner, monthKey = null) {
    if (!calendarGrid) return;
    const requestSeq = ++calendarRequestSeq;

    calendarGrid.classList.add("is-loading");
    calendarGrid.setAttribute("aria-busy", "true");
    try {
      const payload = await fetchCalendarPayload(practitioner, monthKey);

      if (requestSeq !== calendarRequestSeq) {
        return;
      }

      calendarGrid.innerHTML = payload.grid_html || "";

      const monthLabel = document.getElementById("appointment-calendar-month");
      if (monthLabel && payload.month_label) {
        monthLabel.textContent = payload.month_label;
      }

      if (payload.month_key) {
        currentMonthKey = payload.month_key;
        calendarGrid.dataset.calendarMonth = payload.month_key;
      }
      updateNavState();

      const enabledDays = calendarGrid.querySelectorAll(
        ".calendar-day:not(.is-blank):not(.is-disabled)"
      );
      if (enabledDays.length === 0) {
        setStatus("No available times for the rest of this month.");
      } else {
        setStatus("Select a day to view times.");
      }

      const lookAheadMonth = shiftMonthKey(currentMonthKey, 1);
      if (lookAheadMonth) {
        void prefetchCalendarMonth(practitioner, lookAheadMonth);
      }
    } finally {
      if (requestSeq === calendarRequestSeq) {
        calendarGrid.classList.remove("is-loading");
        calendarGrid.removeAttribute("aria-busy");
      }
    }
  }

  async function fetchPage(from, to, page) {
    const cacheKey = `cliniko:times:${appointmentTypeId}:${practitionerId || ""}:${from}:${to}:${perPage}:${page}`;
    const cached = readCalendarCacheValue(frontendCache, cacheKey);
    if (cached) {
      return cached;
    }

    return withPendingCalendarRequest(frontendCache, cacheKey, async () => {
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set("appointment_type_id", appointmentTypeId);
      url.searchParams.set("from", from);
      url.searchParams.set("to", to);
      url.searchParams.set("per_page", String(perPage));
      url.searchParams.set("page", String(page));
      if (practitionerId) {
        url.searchParams.set("practitioner_id", practitionerId);
      }

      const payload = await fetchJson(url.toString(), "Failed to load available times.");
      const rawTimes = payload.available_times || [];
      const items = Array.isArray(rawTimes)
        ? rawTimes
            .map((t) => t?.appointment_start || t?.appointmentStart || t)
            .filter(Boolean)
        : [];

      const total = Number(payload.total_entries || items.length);
      const result = { items, total };
      writeCalendarCacheValue(frontendCache, cacheKey, result, frontendCacheTtlMs.timesPage);
      return result;
    });
  }

  async function fetchAllTimesForDate(dateKey) {
    let page = 1;
    let collected = [];
    let total = 0;
    let safety = 0;

    while (safety < 20) {
      const res = await fetchPage(dateKey, dateKey, page);
      collected = collected.concat(res.items);
      total = res.total || collected.length;
      if (collected.length >= total || res.items.length === 0) break;
      page += 1;
      safety += 1;
    }

    return collected;
  }

  function addTimeButton(container, iso, onSelect) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "appointment-time-slot";
    btn.dataset.iso = iso;

    const dt = new Date(iso);
    btn.textContent = Number.isNaN(dt.getTime())
      ? iso
      : dt.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });

    btn.addEventListener("click", () => onSelect(btn));
    container.appendChild(btn);
  }

  function clearSelectedTime() {
    if (!calendarReady) return;
    [morningSlots, afternoonSlots, eveningSlots].forEach((slot) => {
      if (!slot) return;
      slot
        .querySelectorAll(".appointment-time-slot.is-selected")
        .forEach((el) => el.classList.remove("is-selected"));
    });
  }

  function handleSelectTime(btn) {
    if (!calendarReady || !hiddenInput) return;
    const iso = btn.dataset.iso;
    if (!iso) return;

    clearSelectedTime();
    btn.classList.add("is-selected");
    hiddenInput.value = iso;

    const dt = new Date(iso);
    const label = Number.isNaN(dt.getTime())
      ? iso
      : `${dt.toLocaleDateString([], {
          weekday: "short",
          month: "short",
          day: "numeric",
        })} at ${dt.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })}`;

    setStatus(`Selected: ${label}`);

    if (selectionWrap) {
      const existingError = selectionWrap.querySelector(".field-error");
      if (existingError) existingError.remove();
    }
  }

  function escapeSelector(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }
    return String(value).replace(/"/g, '\\"');
  }

  function getDateKeyFromIso(iso) {
    if (!iso) return null;
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return null;
    return toDateInputValue(dt);
  }

  const timesCache = new Map();

  function updatePeriodIndicators(cell, times) {
    if (!calendarReady) return;
    const buckets = { morning: 0, afternoon: 0, evening: 0 };
    times.forEach((iso) => {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return;
      const hour = dt.getHours();
      if (hour < 12) buckets.morning += 1;
      else if (hour < 17) buckets.afternoon += 1;
      else buckets.evening += 1;
    });

    cell
      .querySelectorAll(".calendar-period")
      .forEach((el) => {
        const key = el.dataset.period;
        el.classList.toggle("is-active", (buckets[key] || 0) > 0);
      });

    cell.classList.toggle("is-empty", times.length === 0);
  }

  function renderTimes(dateKey, times, preselectIso = null) {
    if (!calendarReady) return;
    [morningSlots, afternoonSlots, eveningSlots].forEach((slot) => {
      slot.innerHTML = "";
    });
    if (emptySlots) emptySlots.classList.add("is-hidden");
    setPlaceholderVisible(false);
    setDayLoading(false);

    if (!times.length) {
      if (emptySlots) emptySlots.classList.remove("is-hidden");
      setStatus("No available times for this day.");
      setDayHint("No available times for this day.");
      return;
    }

    const buckets = { morning: [], afternoon: [], evening: [] };
    times.forEach((iso) => {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return;
      const hour = dt.getHours();
      if (hour < 12) buckets.morning.push(iso);
      else if (hour < 17) buckets.afternoon.push(iso);
      else buckets.evening.push(iso);
    });

    const renderBucket = (container, list) => {
      if (!list.length) {
        const empty = document.createElement("span");
        empty.className = "appointment-day-times__empty";
        empty.textContent = "No times";
        container.appendChild(empty);
        return;
      }
      list
        .slice()
        .sort((a, b) => new Date(a) - new Date(b))
        .forEach((iso) => addTimeButton(container, iso, handleSelectTime));
    };

    renderBucket(morningSlots, buckets.morning);
    renderBucket(afternoonSlots, buckets.afternoon);
    renderBucket(eveningSlots, buckets.evening);

    if (preselectIso) {
      const preBtn = selectionWrap?.querySelector(
        `.appointment-time-slot[data-iso="${escapeSelector(preselectIso)}"]`
      );
      if (preBtn) handleSelectTime(preBtn);
    } else {
      setStatus("Select a time to continue.");
      setDayHint("Select a time below to continue.");
    }
  }

  async function selectDay(cell, dateKey, preselectIso = null) {
    if (!calendarReady) return;
    const requestSeq = ++dayRequestSeq;
    calendarGrid
      .querySelectorAll(".calendar-day.is-selected")
      .forEach((el) => el.classList.remove("is-selected"));
    cell.classList.add("is-selected");

    const dt = new Date(dateKey);
    dayTitle.textContent = Number.isNaN(dt.getTime())
      ? "Selected day"
      : dt.toLocaleDateString([], {
          weekday: "long",
          month: "long",
          day: "numeric",
        });

    setStatus("Loading available times…");
    setDayHint("Loading available times…");
    setPlaceholderVisible(false);
    setDayLoading(true, "Fetching times for the selected day…");
    [morningSlots, afternoonSlots, eveningSlots].forEach((slot) => {
      slot.innerHTML = "";
    });
    if (emptySlots) emptySlots.classList.add("is-hidden");

    if (timesCache.has(dateKey)) {
      const cached = timesCache.get(dateKey);
      if (requestSeq !== dayRequestSeq) return;
      updatePeriodIndicators(cell, cached);
      renderTimes(dateKey, cached, preselectIso);
      return;
    }

    let times = [];
    try {
      times = await fetchAllTimesForDate(dateKey);
    } catch (e) {
      if (requestSeq !== dayRequestSeq) return;
      setStatus(e?.message || "Failed to load available times.", true);
      return;
    }

    if (requestSeq !== dayRequestSeq) return;
    timesCache.set(dateKey, times);
    updatePeriodIndicators(cell, times);
    renderTimes(dateKey, times, preselectIso);
  }

  if (calendarReady && calendarGrid) {
    calendarGrid.addEventListener("click", (event) => {
      const cell = event.target.closest(".calendar-day");
      if (!cell || cell.classList.contains("is-blank") || cell.classList.contains("is-disabled")) return;
      const dateKey = cell.dataset.date;
      if (!dateKey) return;
      selectDay(cell, dateKey);
    });
  }

  async function initializePractitionerSelect() {
    if (!practitionerSelect || !practitionersEndpoint) return;
    try {
      const list = await fetchPractitioners();
      populatePractitionerSelect(list);
    } catch (e) {
      populatePractitionerSelect([]);
      setStatus(e?.message || "Failed to load practitioners.", true);
    }

    if (!practitionerSelect.dataset.bound) {
      practitionerSelect.dataset.bound = "1";
      practitionerSelect.addEventListener("change", async () => {
        const selected = practitionerSelect.value || "";
        if (!selected || selected === practitionerId) return;
        practitionerId = selected;
        formEl.dataset.practitionerId = selected;
        timesCache.clear();
        resetSelectedTime();
        clearDayTimes();

        if (calendarReady) {
          try {
            await prefetchCalendarWindow(selected, currentMonthKey);
            await refreshCalendar(selected, currentMonthKey);
          } catch (e) {
            setStatus(e?.message || "Failed to load calendar.", true);
          }
        }
      });
    }
  }

  async function initializeCalendarState() {
    if (calendarReady && calendarEndpoint && practitionerSelect) {
      try {
        await prefetchCalendarWindow(practitionerId, currentMonthKey);
        await refreshCalendar(practitionerId, currentMonthKey);
      } catch (e) {
        setStatus(e?.message || "Failed to load calendar.", true);
      }
    } else if (calendarReady) {
      const enabledDays = calendarGrid.querySelectorAll(
        ".calendar-day:not(.is-blank):not(.is-disabled)"
      );
      if (enabledDays.length === 0) {
        setStatus("No available times for the rest of this month.");
      } else {
        setStatus("Select a day to view times.");
      }
    }

    if (calendarReady && hiddenInput && hiddenInput.value) {
      const preDateKey = getDateKeyFromIso(hiddenInput.value);
      if (preDateKey) {
        const preCell = calendarGrid.querySelector(
          `.calendar-day[data-date="${escapeSelector(preDateKey)}"]`
        );
        if (preCell) {
          selectDay(preCell, preDateKey, hiddenInput.value);
        }
      }
    }
  }

  clearDayTimes();

  initializePractitionerSelect()
    .then(initializeCalendarState)
    .catch(() => initializeCalendarState());

  if (calendarReady) {
    updateNavState();

    if (calendarPrevBtn && !calendarPrevBtn.dataset.bound) {
      calendarPrevBtn.dataset.bound = "1";
      calendarPrevBtn.addEventListener("click", async (event) => {
        event.preventDefault();
        if (calendarPrevBtn.disabled) return;
        const targetKey = shiftMonthKey(currentMonthKey, -1);
        if (!targetKey) return;
        timesCache.clear();
        resetSelectedTime();
        clearDayTimes();
        try {
          await refreshCalendar(practitionerId, targetKey);
        } catch (e) {
          setStatus(e?.message || "Failed to load calendar.", true);
        }
      });
    }

    if (calendarNextBtn && !calendarNextBtn.dataset.bound) {
      calendarNextBtn.dataset.bound = "1";
      calendarNextBtn.addEventListener("click", async (event) => {
        event.preventDefault();
        const targetKey = shiftMonthKey(currentMonthKey, 1);
        if (!targetKey) return;
        timesCache.clear();
        resetSelectedTime();
        clearDayTimes();
        try {
          await refreshCalendar(practitionerId, targetKey);
        } catch (e) {
          setStatus(e?.message || "Failed to load calendar.", true);
        }
      });
    }
  }

  if (calendarReady && hiddenInput && calendarGrid) {
    document.addEventListener("restoreform", () => {
      if (!hiddenInput.value) return;
      const restoredKey = getDateKeyFromIso(hiddenInput.value);
      if (!restoredKey) return;
      const restoredCell = calendarGrid.querySelector(
        `.calendar-day[data-date="${escapeSelector(restoredKey)}"]`
      );
      if (restoredCell) {
        selectDay(restoredCell, restoredKey, hiddenInput.value);
      }
    });
  }
}

function isSingleStep() {
  return formType === "single" || formType === "unstyled";
}

function isUnstyledForm() {
  return formType === "unstyled";
}

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

function onlyDigits(value) {
  return String(value || "").replace(/\D+/g, "");
}

function isValidYmdDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  const dt = new Date(Date.UTC(year, month - 1, day));
  return (
    dt.getUTCFullYear() === year &&
    dt.getUTCMonth() + 1 === month &&
    dt.getUTCDate() === day
  );
}

function normalizeDateYmd(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";

  if (isValidYmdDate(raw)) return raw;

  const slash = raw.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (slash) {
    const ymd = `${slash[3]}-${slash[2]}-${slash[1]}`;
    return isValidYmdDate(ymd) ? ymd : "";
  }

  const compact = onlyDigits(raw);
  if (compact.length === 8) {
    const ymd = `${compact.slice(4, 8)}-${compact.slice(2, 4)}-${compact.slice(0, 2)}`;
    return isValidYmdDate(ymd) ? ymd : "";
  }

  return "";
}

function normalizePatientForSubmission(patientRaw) {
  const patient = patientRaw && typeof patientRaw === "object" ? { ...patientRaw } : {};
  const normalized = {};

  const copyTrim = (key) => {
    if (!(key in patient)) return;
    normalized[key] = String(patient[key] || "").trim();
  };

  [
    "first_name",
    "last_name",
    "email",
    "address_1",
    "address_2",
    "city",
    "state",
    "country",
    "practitioner_id",
    "appointment_start",
    "appointment_date",
  ].forEach(copyTrim);

  if ("medicare" in patient) {
    normalized.medicare = onlyDigits(patient.medicare).slice(0, 9);
  }

  if ("medicare_reference_number" in patient) {
    normalized.medicare_reference_number = onlyDigits(
      patient.medicare_reference_number
    ).slice(0, 1);
  }

  if ("phone" in patient) {
    let digits = onlyDigits(patient.phone);
    if (digits.startsWith("61")) digits = "0" + digits.slice(2);
    normalized.phone = digits.slice(0, 10);
  }

  if ("post_code" in patient) {
    const digits = onlyDigits(patient.post_code);
    normalized.post_code = digits ? digits.slice(0, 4) : String(patient.post_code || "").trim();
  }

  if ("date_of_birth" in patient) {
    normalized.date_of_birth = normalizeDateYmd(patient.date_of_birth);
  }

  if (!normalized.appointment_date && normalized.appointment_start) {
    const match = normalized.appointment_start.match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) normalized.appointment_date = match[1];
  }

  Object.entries(patient).forEach(([key, value]) => {
    if (Object.prototype.hasOwnProperty.call(normalized, key)) return;
    if (typeof value === "string") {
      normalized[key] = value.trim();
    } else {
      normalized[key] = value;
    }
  });

  return normalized;
}

function normalizeContentForSubmission(contentRaw) {
  const sectionsIn = Array.isArray(contentRaw?.sections) ? contentRaw.sections : [];

  const sections = sectionsIn.map((section) => {
    const questionsIn = Array.isArray(section?.questions) ? section.questions : [];

    const questions = questionsIn
      .map((q) => {
        const type = String(q?.type || "text");
        if (type === "signature") return null;

        const question = {
          name: String(q?.name || ""),
          type,
          required: !!q?.required,
        };

        if (type === "checkboxes" || type === "radiobuttons") {
          const legacySelected = new Set();
          if (type === "checkboxes" && Array.isArray(q?.answer)) {
            q.answer.forEach((v) => {
              const value = String(v || "").trim();
              if (value) legacySelected.add(value);
            });
          }
          if (type === "radiobuttons" && typeof q?.answer === "string") {
            const value = String(q.answer || "").trim();
            if (value) legacySelected.add(value);
          }

          const answersIn = Array.isArray(q?.answers) ? q.answers : [];
          question.answers = answersIn
            .map((answer) => {
              if (answer && typeof answer === "object") {
                const value = String(answer.value || "").trim();
                if (!value) return null;
                return { value, selected: !!answer.selected || legacySelected.has(value) };
              }
              const value = String(answer || "").trim();
              if (!value) return null;
              return { value, selected: legacySelected.has(value) };
            })
            .filter(Boolean);

          if (question.answers.length === 0 && legacySelected.size > 0) {
            question.answers = Array.from(legacySelected).map((value) => ({
              value,
              selected: true,
            }));
          }

          if (q?.other && typeof q.other === "object" && q.other.enabled) {
            if (q.other.selected) {
              question.other = {
                enabled: true,
                selected: true,
                value: String(q.other.value || "").trim(),
              };
            } else {
              question.other = { enabled: true };
            }
          }

          return question;
        }

        question.answer = String(q?.answer ?? "");
        return question;
      })
      .filter(Boolean);

    return {
      name: String(section?.name || ""),
      description: String(section?.description || ""),
      questions,
    };
  });

  return { sections };
}

function parseFormToStructuredBody(formEl) {
  const formData = new FormData(formEl);
  const sectionsData = Array.isArray(formHandlerData.sections)
    ? formHandlerData.sections
    : [];

  const rawContent = {
    sections: sectionsData.map((section) => {
      const sectionQuestions = Array.isArray(section?.questions) ? section.questions : [];

      const questions = sectionQuestions
        .map((q) => {
          const question = {
            name: q?.name,
            type: q?.type,
            required: !!q?.required,
          };

          if (q?.type === "checkboxes" && Array.isArray(q.answers)) {
            const rawSelected = new Set((formData.getAll(q.name + "[]") || []).map(String));
            question.answers = q.answers.map((opt) => {
              const value = String(opt?.value || "");
              return { value, selected: rawSelected.has(value) };
            });

            if (q.other?.enabled) {
              const otherChecked =
                rawSelected.has("__other__") || rawSelected.has("other");
              const otherValue = (formData.get(q.name + "_other") || "").trim();
              question.other = otherChecked
                ? { value: otherValue, enabled: true, selected: true }
                : { enabled: true };
            }
          } else if (q?.type === "radiobuttons" && Array.isArray(q.answers)) {
            const selected = String(formData.get(q.name) || "");
            question.answers = q.answers.map((opt) => {
              const value = String(opt?.value || "");
              return { value, selected: selected === value };
            });

            if (q.other?.enabled) {
              const isOther = selected === "__other__" || selected === "other";
              const otherValue = (formData.get(q.name + "_other") || "").trim();
              question.other = isOther
                ? { value: otherValue, enabled: true, selected: true }
                : { enabled: true };
            }
          } else {
            question.answer = String(formData.get(q?.name) ?? "");
          }

          return question;
        })
        .filter((question) => question.type !== "signature");

      return {
        name: section?.name,
        description: section?.description,
        questions,
      };
    }),
  };

  const extracted = extractNestedFields(formEl, "patient");
  return {
    content: normalizeContentForSubmission(rawContent),
    patient: normalizePatientForSubmission(extracted?.patient || {}),
  };
}

let embedFormStep = 0;

let clinikoEmbedListenerBound = false;
let formActionsInitialDisplay = "";
let hasShownClinikoEmailModalForThisPatientStep = false;


function listenClinikoEmbed() {
  if (!isClinikoForm) return;
  if (clinikoEmbedListenerBound) return;
  clinikoEmbedListenerBound = true;

  const formActionsElement = document.querySelector(".form-actions");

  function updateFormActionsVisibility() {
    if (!formActionsElement) return;

    if (embedFormStep === 0) {
      formActionsElement.style.display = formActionsInitialDisplay || "";
    } else {
      formActionsElement.style.display = "none";
    }
  }

  updateFormActionsVisibility();

  window.addEventListener("message", async function (e) {
    if (e.origin !== formHandlerData.cliniko_embeded_host) return;

    const prevStep = embedFormStep;

    // --- 1. Resize Handler ---
    if (typeof e.data === "string" && e.data.startsWith("cliniko-bookings-resize:")) {
      if (!isUnstyledForm()) {
        const iframe = document.querySelector("#cliniko-payment_iframe");
        const height = e.data.split(":")[1];

        if (iframe && height != 0) {
          iframe.style.height = height + "px";
          iframe.parentElement.style.maxHeight = height + "px";
        }

        // heuristic: return to step 0
        if (embedFormStep > 0 && parseInt(height, 10) < 600) {
          embedFormStep = 0;
        }
      }
    }

    // --- 2. Page/Step Change Handler ---
    else if (typeof e.data === "string" && e.data.startsWith("cliniko-bookings-page:")) {
      const pageMessage = e.data;

      if (pageMessage === "cliniko-bookings-page:schedule") {
        embedFormStep = 1;
        hasShownClinikoEmailModalForThisPatientStep = false;
      } else if (pageMessage === "cliniko-bookings-page:patient") {
        embedFormStep = 2;

        // ✅ Open modal ONLY when entering step 2 (not on repeated messages)
        if (!hasShownClinikoEmailModalForThisPatientStep) {
          hasShownClinikoEmailModalForThisPatientStep = true;
          openClinikoEmailConfirmModal();
        }
      } else if (pageMessage === "cliniko-bookings-page:confirmed") {
        embedFormStep = 3;
      } else {
        embedFormStep = 0;
        hasShownClinikoEmailModalForThisPatientStep = false;
      }

      // --- Confirmed Booking Action (Only runs if confirmed) ---
      if (embedFormStep === 3) {
        showPaymentLoader();
        const iframe = document.querySelector("#cliniko-payment_iframe");
        if (iframe) iframe.style.display = "none";

        await submitBookingForm(null, null, true, {
          patientBookedTime: new Date().toISOString(),
        });
      }
    }

    // --- 3. Visibility Update Check ---
    if (embedFormStep !== prevStep) {
      updateFormActionsVisibility();
    }
  });
}

function getPatientEmailFromForm() {
  const byId = document.getElementById("patient-email");
  const byName = document.querySelector('input[name="patient[email]"]');
  const el = byId || byName;
  return (el?.value || "").trim();
}

function ensureClinikoEmailConfirmModal() {
  const wrap = document.getElementById("es-email-confirm-modal");
  if (!wrap) return;

  // prevent double-binding
  if (wrap.dataset.bound === "1") return;
  wrap.dataset.bound = "1";

  const dialog = wrap.querySelector(".es-email-modal__dialog");

  // close on backdrop
  wrap.addEventListener("click", (ev) => {
    const t = ev.target;
    if (t?.getAttribute && t.getAttribute("data-es-close") === "1") {
      closeClinikoEmailConfirmModal();
    }
  });

  // close on ESC
  document.addEventListener("keydown", (ev) => {
    const isOpen = !wrap.classList.contains("is-hidden");
    if (!isOpen) return;
    if (ev.key === "Escape") closeClinikoEmailConfirmModal();
  });

  // buttons
  wrap.querySelector("#es-email-modal-ok")?.addEventListener("click", () => {
    closeClinikoEmailConfirmModal();
  });

  wrap.querySelector("#es-email-modal-copy")?.addEventListener("click", async () => {
    const email = getPatientEmailFromForm();
    if (!email) {
      showToast("No email found in the form. Please enter your email first.");
      return;
    }

    try {
      await navigator.clipboard.writeText(email);
      showToast("Email copied.", "success");
    } catch (_) {
      const tmp = document.createElement("input");
      tmp.value = email;
      document.body.appendChild(tmp);
      tmp.select();
      document.execCommand("copy");
      tmp.remove();
      showToast("Email copied.", "success");
    }
  });

  wrap.querySelector("#es-email-modal-edit")?.addEventListener("click", () => {
    closeClinikoEmailConfirmModal();

    const patientStepIndex = Math.max(0, steps.length - 2);
    window.currentStep = patientStepIndex;
    showStep(patientStepIndex);

    const fa = document.querySelector(".form-actions");
    if (fa) fa.style.display = formActionsInitialDisplay || "";

    setTimeout(() => {
      const emailInput =
        document.getElementById("patient-email") ||
        document.querySelector('input[name="patient[email]"]');
      emailInput?.focus();
    }, 50);
  });

  // optional: focus dialog when opened (if your open function calls this)
  wrap._esFocusDialog = () => dialog?.focus();
}


function openClinikoEmailConfirmModal() {
  ensureClinikoEmailConfirmModal();
  const wrap = document.getElementById("es-email-confirm-modal");
  if (!wrap) return;

  wrap.classList.remove("is-hidden");
  wrap.setAttribute("aria-hidden", "false");
  wrap.removeAttribute("inert");

  // set email text
  const email = getPatientEmailFromForm() || "—";
  const el = wrap.querySelector("#es-email-modal-email");
  if (el) el.textContent = email;

  wrap._esFocusDialog?.();
}

function closeClinikoEmailConfirmModal() {
  const wrap = document.getElementById("es-email-confirm-modal");
  if (!wrap) return;

  wrap.classList.add("is-hidden");
  wrap.setAttribute("aria-hidden", "true");
  wrap.setAttribute("inert", "");
}


function closeClinikoEmailConfirmModal() {
  const modal = document.getElementById("es-email-confirm-modal");
  if (!modal) return;

  modal.classList.add("is-hidden");
  modal.setAttribute("aria-hidden", "true");

  const root = document.getElementById("prepayment-form");
  if (root) root.removeAttribute("inert");

  document.documentElement.style.overflow = "";
}


function updateIndicators(index) {
  const type = formHandlerData.appearance.progress_type;

  if (type === "steps") {
    document.querySelectorAll(".progress-step").forEach((el, i) => {
      el.classList.toggle("is-active", i === index);
    });
  } else if (type === "dots") {
    document.querySelectorAll(".progress-dot").forEach((el, i) => {
      el.classList.toggle("is-active", i === index);
    });
  } else if (type === "bar") {
    const total = steps.length;
    const fill = document.querySelector(".progress-fill");
    if (fill) {
      fill.style.width = ((index + 1) / total) * 100 + "%";
    }
  } else if (type === "fraction") {
    const text = document.querySelector(
      ".form-progress--fraction .progress-text"
    );
    if (text) {
      text.textContent = index + 1 + "/" + steps.length;
    }
  } else if (type === "percentage") {
    const text = document.querySelector(
      ".form-progress--percentage .progress-text"
    );
    if (text) {
      text.textContent = Math.round(((index + 1) / steps.length) * 100) + "%";
    }
  }
}

function isCurrentStepValid() {
  const scope = isSingleStep()
    ? document.getElementById("prepayment-form")
    : steps[window.currentStep];
  if (!scope) return true;

  const currentFields = scope.querySelectorAll(
    "[required], [data-required-group]"
  );
  let isValid = true;

  for (let field of currentFields) {
    if (field.disabled) continue;
    if (field.type === "hidden" && !field.hasAttribute("data-validate-hidden"))
      continue;
    const parent =
      field.closest(".col-span-4, .col-span-6, .col-span-8, .col-span-12") ||
      field.parentElement;
    const existingError = parent.querySelector(".field-error");
    if (existingError) existingError.remove();

    // Required checkbox group
    if (field.hasAttribute("data-required-group")) {
      const groupName = field.getAttribute("data-required-group");
      const groupInputs = field.querySelectorAll(
        `input[name="${groupName}[]"]`
      );
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

    // Required radio
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

    if (field.type === "hidden" && field.id === "appointment-time") {
      if (!value) {
        isValid = false;
        if (!existingError) {
          const msg = document.createElement("div");
          msg.className = "field-error";
          msg.textContent = "Please select an appointment time.";
          parent.appendChild(msg);
        }
      }
      continue;
    }

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

    // Medicare number (9 digits, reference number is separate)
    if (field.name === "patient[medicare]") {
      const clean = value.replace(/\D/g, "");
      if (clean.length !== 9) {
        field.style.borderColor = "red";
        isValid = false;
        const msg = document.createElement("div");
        msg.className = "field-error";
        msg.textContent = "Medicare number must contain exactly 9 digits.";
        parent.appendChild(msg);
        continue;
      }
      field.style.borderColor = "";
      continue;
    }

    // Medicare reference number (1 digit, 1–9)
    if (field.name === "patient[medicare_reference_number]") {
      const clean = value.replace(/\D/g, "");
      if (!/^[1-9]$/.test(clean)) {
        field.style.borderColor = "red";
        isValid = false;
        const msg = document.createElement("div");
        msg.className = "field-error";
        msg.textContent =
          "Medicare reference number must be a single digit between 1 and 9.";
        parent.appendChild(msg);
        continue;
      }
      field.style.borderColor = "";
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

function setHidden(el, hidden) {
  if (!el) return;
  el.classList.toggle("is-hidden", !!hidden);
  el.setAttribute("aria-hidden", hidden ? "true" : "false");
  el.style.display = hidden ? "none" : "";
}

function showStep(i) {
  window.scrollTo({ top: 0, behavior: "smooth" });

  if (isSingleStep()) {
    steps.forEach((step) => {
      setHidden(step, false);
    });

    setHidden(prevBtn, true);
    if (progressEl) setHidden(progressEl, true);

    if (isClinikoForm) {
      setHidden(nextBtn, true);
    } else {
      setHidden(nextBtn, false);
    }
    syncPatientHistoryStandaloneUi();
    return;
  }

  // show only the current step
  steps.forEach((step, index) => {
    setHidden(step, index !== i);
  });

  // prev button: hidden on first step
  setHidden(prevBtn, i === 0);

  // next button: hide on last step ONLY when it's a Cliniko form
  const hideNext = i === steps.length - 1 && isClinikoForm;
  setHidden(nextBtn, hideNext ? true : false);

  if (progressEl) setHidden(progressEl, false);
  updateIndicators(i);
  syncPatientHistoryStandaloneUi();
}

function updateStepIndicator(index) {
  const type = formHandlerData.appearance.progress_type;

  if (type === "steps") {
    document.querySelectorAll(".progress-step").forEach((el, i) => {
      el.classList.toggle("is-active", i === index);
    });
  } else if (type === "dots") {
    document.querySelectorAll(".progress-dot").forEach((el, i) => {
      el.classList.toggle("is-active", i === index);
    });
  } else if (type === "bar") {
    const total = steps.length;
    const fill = document.querySelector(".progress-fill");
    if (fill) {
      fill.style.width = ((index + 1) / total) * 100 + "%";
    }
  } else if (type === "fraction") {
    const text = document.querySelector(
      ".form-progress--fraction .progress-text"
    );
    if (text) {
      text.textContent = index + 1 + "/" + steps.length;
    }
  } else if (type === "percentage") {
    const text = document.querySelector(
      ".form-progress--percentage .progress-text"
    );
    if (text) {
      text.textContent = Math.round(((index + 1) / steps.length) * 100) + "%";
    }
  }
}

async function safeInitStripe() {
  // Already initialized and mounted?
  if (stripeInstance && cardElementInstance) {
    return;
  }
  const { stripe, cardElement, errorEl } = await initializeStripeElements();
  stripeInstance = stripe;
  cardElementInstance = cardElement;
  errorElementInstance = errorEl;
  handlePaymentAndFormSubmission(
    stripeInstance,
    cardElementInstance,
    errorElementInstance
  );
}

async function handleNextStep() {
  if (!isCurrentStepValid()) {
    showToast("Please review the highlighted fields before continuing.");
    return;
  }

  if (isSingleStep()) {
    await handleFinalStep();
    return;
  }

  if (window.currentStep < steps.length - 1) {
    updateStepIndicator(window.currentStep + 1);

    // ✅ Pre-init Stripe ONLY if Stripe is selected
    if (
      isPaymentEnabled &&
      isStripeSelected() &&
      window.currentStep === steps.length - 2 &&
      !stripeInitStarted &&
      !isClinikoForm
    ) {
      stripeInitStarted = true;
      await safeInitStripe();
    }

    window.currentStep++;
    showStep(window.currentStep);
    return;
  }

  await handleFinalStep();
}

async function safeInitStripe() {
  if (!isStripeSelected()) return;     // ✅ hard stop
  if (typeof Stripe === "undefined") return;
  if (typeof initStripe !== "function") return;

  try {
    await initStripe();
  } catch (e) {
    console.error("safeInitStripe error:", e);
  }
}



async function handleFinalStep() {
  // Se o passo atual não é válido, aborta
  if (!isCurrentStepValid()) {
    showToast("Please review the highlighted fields before continuing.");
    return;
  }

  // Caso Cliniko Embed esteja ativo → ignora Stripe
  if (isClinikoForm) {
    listenClinikoEmbed();
    return;
  }

  // Caso pagamento Stripe esteja habilitado
  if (isPaymentEnabled) {
    await showStripePaymentForm();
    return;
  }

  // Caso não tenha pagamento algum → apenas submete
  showPaymentLoader();
  await submitBookingForm();
}

async function showStripePaymentForm() {
  const preForm = document.getElementById("prepayment-form");
  const paymentForm = document.getElementById("payment_form");

  if (!paymentForm) {
    return;
  }

  if (preForm) {
    preForm.style.display = "none";
  }
  paymentForm.style.display = "flex";

  // ✅ Only init Stripe if Stripe gateway is selected
  if (isStripeSelected()) {
    await safeInitStripe();
  }

  // (Optional) if you ever need to force Tyro handler attach, you can do it here,
  // but your tyrohealth.js already attaches on DOMContentLoaded + MutationObserver.

  const backBtn = document.getElementById("go-back-button");
  if (backBtn && !backBtn.dataset.bound) {
    backBtn.dataset.bound = "1";
    backBtn.addEventListener("click", () => {
      if (preForm) {
        preForm.style.display = "block";
        showStep(window.currentStep);
      }
      paymentForm.style.display = "none";
    });
  }
}


function showToast(message, type = "error") {
  const isSuccess = type === "success";

  const icon = isSuccess
    ? `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#2e7d32" viewBox="0 0 24 24">
         <path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
       </svg>`
    : `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#f44336" viewBox="0 0 24 24">
         <path d="M1 21h22L12 2 1 21zm13-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
       </svg>`;

  const bgColor = isSuccess ? "#f1f8f4" : "#fff";
  const borderColor = isSuccess ? "#c8e6c9" : "#eee";
  const textColor = isSuccess ? "#2e7d32" : "#333";

  Toastify({
    text: `
      <div style="
        display: flex;
        align-items: center;
        gap: 12px;
      ">
        ${icon}
        <span style="
          color: ${textColor};
          font-size: 14px;
          font-weight: 500;
        ">${message}</span>
      </div>
    `,
    duration: 4000,
    gravity: "bottom",
    position: "left",
    stopOnFocus: true,
    escapeMarkup: false,
    style: {
      background: bgColor,
      border: `1px solid ${borderColor}`,
      boxShadow: "0 2px 8px rgba(0,0,0,0.08)",
      borderRadius: "8px",
      padding: "12px 16px",
      minWidth: "260px",
      maxWidth: "360px",
    },
  }).showToast();
}

function bindOtherToggle(form) {
  if (!form) return;

  const onToggle = (cb) => {
    const targetId = cb.getAttribute("data-other-target");
    const wrap = targetId ? document.getElementById(targetId) : null;
    console.log("toggle dat wrap", wrap);
    if (!wrap) return;

    const textInput = wrap.querySelector('input[type="text"]');
    const isRequiredGroup = cb.hasAttribute("data-required");

    if (cb.checked) {
      wrap.style.display = "block";
      cb.setAttribute("aria-expanded", "true");
      if (isRequiredGroup && textInput)
        textInput.setAttribute("required", "required");
      if (textInput) textInput.focus();
    } else {
      wrap.style.display = "none";
      cb.setAttribute("aria-expanded", "false");
      if (textInput) {
        textInput.removeAttribute("required");
        textInput.value = "";
      }
    }
  };

  form.querySelectorAll("input.other-toggle").forEach((cb) => {
    cb.addEventListener("change", () => onToggle(cb));
  });
}
function mountForm() {
  window.currentStep = 0;
   showStep(window.currentStep);

  listenClinikoEmbed();

  const form = document.getElementById("prepayment-form");
  bindOtherToggle(form);
  setupSignatureCanvas();
  initAvailableTimesPicker();


  if (nextBtn) {
    window.nextBtnLabel = nextBtn.innerHTML;
  }

  if (isSingleStep() && nextBtn) {
    const labelSpan = nextBtn.querySelector("span");
    if (labelSpan) {
      labelSpan.textContent = "Submit";
    } else {
      nextBtn.textContent = "Submit";
    }
    window.nextBtnLabel = nextBtn.innerHTML;
  }

  const nextBtnLabel = nextBtn ? nextBtn.innerHTML : "";

  if (nextBtn) {
    nextBtn.addEventListener("click", async () => {
      await handleNextStep(steps);
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener("click", () => {
      if (window.currentStep > 0) {
        window.currentStep--;
        showStep(window.currentStep);
      }
      if (window.currentStep + 1 < steps.length && nextBtn) {
        nextBtn.innerHTML = nextBtnLabel;
      }
    });
  }

 

  function attachValidationListeners() {
    document
      .querySelectorAll(
        "#prepayment-form input[required], #prepayment-form textarea[required], #prepayment-form select[required]"
      )
      .forEach((input) => {
        input.addEventListener("input", () => {
          input.style.borderColor = "";
          const parent =
            input.closest(
              ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
            ) || input.parentElement;
          const existingError = parent.querySelector(".field-error");
          if (existingError) existingError.remove();
        });
      });

    // Radio buttons
    document
      .querySelectorAll("#prepayment-form input[type='radio'][required]")
      .forEach((radio) => {
        radio.addEventListener("change", () => {
          const group = document.getElementsByName(radio.name);
          group.forEach((el) => {
            el.style.borderColor = "";
            const parent =
              el.closest(
                ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
              ) || el.parentElement;
            const existingError = parent.querySelector(".field-error");
            if (existingError) existingError.remove();
          });
        });
      });

    // Required checkbox groups
    document
      .querySelectorAll("[data-required-group]")
      .forEach((groupContainer) => {
        const groupName = groupContainer.getAttribute("data-required-group");
        const checkboxes = groupContainer.querySelectorAll(
          `input[name="${groupName}[]"]`
        );
        checkboxes.forEach((cb) => {
          cb.addEventListener("change", () => {
            const hasChecked = [...checkboxes].some((c) => c.checked);
            if (hasChecked) {
              checkboxes.forEach((c) => {
                c.style.outline = "none";
                const parent =
                  c.closest(
                    ".col-span-4, .col-span-6, .col-span-8, .col-span-12"
                  ) || c.parentElement;
                const existingError = parent.querySelector(".field-error");
                if (existingError) existingError.remove();
              });
            }
          });
        });
      });
  }

  attachValidationListeners();
}

/**
 * Backwards compatible:
 * - Stripe calls: submitBookingForm("tok_123", errorEl)
 * - Tyro calls:   submitBookingForm({ gateway:"tyrohealth", transactionId:"..." , invoiceReference?: "..." }, errorEl)
 *
 * @param {string|{gateway:'stripe', token:string}|{gateway:'tyrohealth', transactionId:string, invoiceReference?:string}|null} paymentArg
 * @param {HTMLElement|null} errorEl
 * @param {boolean} isClinikoIframe
 * @param {{ patientBookedTime?: string|Date, attemptId?: string, attemptToken?: string }} opts
 */
function buildRequestHeaders(attemptToken = "") {
  const headers = { "Content-Type": "application/json" };
  const requestToken = String(formHandlerData?.request_token || "").trim();
  const attempt = String(attemptToken || "").trim();

  if (requestToken) {
    headers["X-ES-Request-Token"] = requestToken;
  }

  if (attempt) {
    headers["X-ES-Attempt-Token"] = attempt;
  }

  return headers;
}

async function postJsonExpectJson(url, payload, attemptToken = "") {
  const response = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: buildRequestHeaders(attemptToken),
    body: JSON.stringify(payload || {}),
  });

  const result = await response.json().catch(() => ({}));
  return { response, result };
}

async function getJsonExpectJson(url, attemptToken = "") {
  const response = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: buildRequestHeaders(attemptToken),
  });

  const result = await response.json().catch(() => ({}));
  return { response, result };
}

function colorWithAlpha(color, alpha) {
  const value = String(color || "").trim();
  if (!value) {
    return `rgba(0, 115, 230, ${alpha})`;
  }

  if (value.startsWith("#")) {
    let hex = value.slice(1);
    if (hex.length === 3) {
      hex = hex.split("").map((char) => char + char).join("");
    }

    if (hex.length === 6) {
      const parsed = Number.parseInt(hex, 16);
      if (!Number.isNaN(parsed)) {
        const r = (parsed >> 16) & 255;
        const g = (parsed >> 8) & 255;
        const b = parsed & 255;
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
      }
    }
  }

  const rgbMatch = value.match(/^rgba?\(([^)]+)\)$/i);
  if (rgbMatch) {
    const parts = rgbMatch[1].split(",").map((part) => part.trim());
    if (parts.length >= 3) {
      return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
    }
  }

  return value;
}

function getPaymentLoaderProgress(code = "", fallback = null) {
  const byCode = {
    preflighted: 18,
    payment_verified: 52,
    making_appointment: 76,
    linking_form: 92,
    completed: 100,
    failed: 100,
    cleaned: 100,
  };

  if (Object.prototype.hasOwnProperty.call(byCode, code)) {
    return byCode[code];
  }

  if (typeof fallback === "number" && Number.isFinite(fallback)) {
    return Math.max(0, Math.min(100, fallback));
  }

  return null;
}

function getPaymentLoaderHeadline(code = "", fallback = "") {
  const byCode = {
    preflighted: "Validated with Cliniko...",
    payment_verified: "Payment confirmed...",
    making_appointment: "Making your appointment...",
    linking_form: "Sending your form to the doctor...",
    completed: "Appointment confirmed.",
    failed: "We could not complete your booking.",
    cleaned: "Booking cancelled.",
  };

  return byCode[code] || fallback;
}

function resetPaymentLoaderState() {
  paymentLoaderProgress = null;
  paymentLoaderHeadline = "";
  paymentLoaderDetail = "";
}

function hidePaymentLoader() {
  resetPaymentLoaderState();
  try {
    jQuery.LoadingOverlay("hide");
  } catch (_) {}
}

function updatePaymentLoader(headline, detail, progress = null) {
  const title = document.getElementById("es-payment-loader-title");
  const body = document.getElementById("es-payment-loader-detail");
  const fill = document.getElementById("es-payment-loader-fill");
  const value = document.getElementById("es-payment-loader-value");
  const progressEl = document.getElementById("es-payment-loader-progress");
  const previousProgress =
    typeof paymentLoaderProgress === "number" && Number.isFinite(paymentLoaderProgress)
      ? paymentLoaderProgress
      : null;

  let safeProgress = previousProgress;
  let shouldKeepExistingText = false;

  if (typeof progress === "number" && Number.isFinite(progress)) {
    const incomingProgress = Math.max(0, Math.min(100, progress));
    shouldKeepExistingText =
      previousProgress !== null && incomingProgress < previousProgress;
    safeProgress =
      previousProgress !== null
        ? Math.max(previousProgress, incomingProgress)
        : incomingProgress;
    paymentLoaderProgress = safeProgress;
  }

  const nextHeadline = shouldKeepExistingText
    ? paymentLoaderHeadline || headline
    : headline || paymentLoaderHeadline;
  const nextDetail = shouldKeepExistingText
    ? paymentLoaderDetail || detail
    : detail || paymentLoaderDetail;

  if (!shouldKeepExistingText) {
    if (headline) paymentLoaderHeadline = headline;
    if (detail) paymentLoaderDetail = detail;
  }

  if (title && nextHeadline) title.textContent = nextHeadline;
  if (body && nextDetail) body.textContent = nextDetail;

  if (typeof safeProgress === "number" && Number.isFinite(safeProgress)) {
    if (fill) {
      fill.style.width = `${safeProgress}%`;
    }
    if (value) {
      value.textContent = `${Math.round(safeProgress)}%`;
    }
    if (progressEl) {
      progressEl.setAttribute("aria-valuenow", String(Math.round(safeProgress)));
    }
  }
}

function updatePaymentLoaderFromAttempt(attempt) {
  const progressCode = String(attempt?.progress?.code || attempt?.status || "").trim();
  const message = String(attempt?.progress?.message || "").trim();
  updatePaymentLoader(
    getPaymentLoaderHeadline(progressCode, "Processing your booking..."),
    message || "Please wait while we finish your booking.",
    getPaymentLoaderProgress(progressCode)
  );
}

function buildAttemptStatusUrl(attemptId) {
  const rawUrl = String(formHandlerData?.booking_attempt_status_url || "").trim();
  if (!rawUrl || !attemptId) {
    return "";
  }

  const url = new URL(rawUrl, window.location.origin);
  url.searchParams.set("attempt_id", attemptId);
  return url.toString();
}

function startAttemptStatusPolling(attemptId, attemptToken, onUpdate) {
  const statusUrl = buildAttemptStatusUrl(attemptId);
  if (!statusUrl || !attemptToken || typeof onUpdate !== "function") {
    return () => {};
  }

  let stopped = false;
  let timerId = null;

  const poll = async () => {
    if (stopped) {
      return;
    }

    try {
      const { response, result } = await getJsonExpectJson(statusUrl, attemptToken);
      if (response.ok && result?.ok && result?.attempt) {
        onUpdate(result.attempt);

        const status = String(result.attempt?.status || "").trim();
        if (["completed", "failed", "cleaned"].includes(status)) {
          stopped = true;
          return;
        }
      }
    } catch (_) {
      // Keep polling while finalize is in-flight.
    }

    if (!stopped) {
      timerId = window.setTimeout(poll, 500);
    }
  };

  poll();

  return () => {
    stopped = true;
    if (timerId !== null) {
      window.clearTimeout(timerId);
    }
  };
}

async function submitBookingForm(
  paymentArg = null,
  errorEl = null,
  isClinikoIframe = false,
  opts = {}
) {
  const formElement = document.getElementById("prepayment-form");
  const headlessPayload = (isHeadless || !formElement)
    ? normalizeHeadlessPayload(getHeadlessPayload())
    : null;

  if (!headlessPayload && !formElement) {
    const msg =
      "Headless payload missing. Provide window.clinikoHeadlessPayload or window.clinikoGetHeadlessPayload().";
    if (errorEl) errorEl.textContent = msg;
    else showToast(msg, "error");
    return;
  }

  const parsed = headlessPayload || parseFormToStructuredBody(formElement);
  const content = normalizeContentForSubmission(parsed?.content || {});
  const patient = normalizePatientForSubmission(parsed?.patient || {});

  // ---- normalize payment input (string token OR object) ----
  const payment = (() => {
    if (!paymentArg) return { gateway: null };

    if (typeof paymentArg === "string") {
      // Stripe legacy call
      return { gateway: "stripe", token: paymentArg };
    }

    if (typeof paymentArg === "object" && paymentArg) {
      return paymentArg; // expected tyrohealth or stripe object
    }

    return { gateway: null };
  })();

  // ---- basic validation for tyrohealth payload (avoid silent bad submits) ----
  if (!isClinikoIframe && payment.gateway === "tyrohealth" && !payment.transactionId) {
    const msg = "Missing Tyro transactionId.";
    if (errorEl) errorEl.textContent = msg;
    else showToast(msg, "error");
    return;
  }

  if (!isClinikoIframe && payment.gateway === "stripe" && !payment.token) {
    const msg = "Missing Stripe token.";
    if (errorEl) errorEl.textContent = msg;
    else showToast(msg, "error");
    return;
  }

  // --- require patient_booked_time when iframe ---
  let patientBookedTimeIso = null;
  if (isClinikoIframe) {
    const v = opts.patientBookedTime;
    if (!v) {
      const msg = "Missing patient_booked_time for Cliniko iframe flow.";
      if (errorEl) errorEl.textContent = msg;
      else showToast(msg, "error");
      return;
    }

    patientBookedTimeIso =
      v instanceof Date ? v.toISOString() : new Date(v).toISOString();

    if (Number.isNaN(Date.parse(patientBookedTimeIso))) {
      const msg = "Invalid patient_booked_time. Provide a Date or ISO8601 string.";
      if (errorEl) errorEl.textContent = msg;
      else showToast(msg, "error");
      return;
    }
  }

  // --- base payload (clean + legacy) ---
  const payload = {
    content,
    patient: isClinikoIframe
      ? { ...patient, patient_booked_time: patientBookedTimeIso }
      : patient,
    moduleId: String(formHandlerData.module_id || ""),
    patient_form_template_id: String(formHandlerData.patient_form_template_id || ""),

    // ✅ NEW: unified payment object (recommended for backend going forward)
    payment: payment.gateway
      ? {
          gateway: payment.gateway,
          ...(payment.gateway === "stripe" ? { token: payment.token || null } : {}),
          ...(payment.gateway === "tyrohealth"
            ? {
                transactionId: payment.transactionId || null,
                invoiceReference: payment.invoiceReference || null,
              }
            : {}),
        }
      : null,

    // ✅ keep old field for Stripe backend compatibility
    stripeToken: payment.gateway === "stripe" ? (payment.token || null) : null,

    // ✅ keep old Tyro fields for backend compatibility
    paymentGateway: payment.gateway === "tyrohealth" ? "tyrohealth" : null,
    tyroTransactionId: payment.gateway === "tyrohealth" ? (payment.transactionId || null) : null,
    invoiceReference: payment.gateway === "tyrohealth" ? (payment.invoiceReference || null) : null,
  };

  try {
    if (isClinikoIframe) {
      const { response, result } = await postJsonExpectJson(
        formHandlerData.cliniko_embeded_form_sync_patient_form_url,
        payload
      );

      if (response.status === 202 && result?.success) {
        showToast("We’re processing your form now…", "success");
        window.formIsSubmitting = true;

        const redirectBase = formHandlerData.redirect_url;
        const queryParams = new URLSearchParams({
          patient_name:
            patient?.first_name && patient?.last_name
              ? `${patient.first_name} ${patient.last_name}`
              : "",
          email: patient?.email ?? "",
          ref: "iframe",
          status: "scheduling_queued",
          receipt: "",
        });

        window.location.href = `${redirectBase}?${queryParams.toString()}`;
        return;
      }

      handleChargeErrors(result, errorEl);
      return;
    }

    const providedAttemptId = String(opts?.attemptId || "").trim();
    let attemptToken = String(opts?.attemptToken || "").trim();
    let attemptId = providedAttemptId;
    let paymentResult = {};
    let paymentRequired = false;

    if (!providedAttemptId) {
      updatePaymentLoader(
        "Validating your details...",
        "Checking your form and appointment details with Cliniko.",
        getPaymentLoaderProgress("preflighted")
      );

      const { response: preflightResponse, result: preflightResult } = await postJsonExpectJson(
        formHandlerData.booking_attempt_preflight_url,
        {
          ...payload,
          gateway: payment.gateway || null,
        }
      );

      if (!preflightResponse.ok || !preflightResult?.ok) {
        handleChargeErrors(preflightResult, errorEl);
        return;
      }

      attemptId = String(preflightResult?.attempt?.id || "");
      attemptToken = String(preflightResult?.attempt?.token || "");
      if (!attemptId) {
        handleChargeErrors(
          { message: "Could not create booking attempt." },
          errorEl
        );
        return;
      }
      if (!attemptToken) {
        handleChargeErrors(
          { message: "Could not secure booking attempt." },
          errorEl
        );
        return;
      }

      paymentResult = preflightResult?.payment || {};
      paymentRequired = !!paymentResult?.required;
    } else {
      if (!attemptToken) {
        handleChargeErrors(
          { message: "Missing secure booking attempt token." },
          errorEl
        );
        return;
      }
      paymentRequired = payment.gateway === "stripe" || payment.gateway === "tyrohealth";
    }

    if (paymentRequired && payment.gateway === "stripe") {
      updatePaymentLoader(
        "Processing your secure payment...",
        "Please wait while we confirm payment.",
        38
      );

      const { response: chargeResponse, result: chargeResult } = await postJsonExpectJson(
        formHandlerData.booking_attempt_charge_stripe_url,
        {
          attempt_id: attemptId,
          attempt_token: attemptToken,
          stripeToken: payment.token || null,
        },
        attemptToken
      );

      if (!chargeResponse.ok || !chargeResult?.ok) {
        handleChargeErrors(chargeResult, errorEl);
        return;
      }

      paymentResult = chargeResult?.payment || paymentResult;
    }

    if (paymentRequired && payment.gateway === "tyrohealth") {
      updatePaymentLoader(
        "Confirming your payment...",
        "Verifying your Tyro Health transaction on the server.",
        38
      );

      const { response: tyroResponse, result: tyroResult } = await postJsonExpectJson(
        formHandlerData.booking_attempt_confirm_tyro_url,
        {
          attempt_id: attemptId,
          attempt_token: attemptToken,
          transactionId: payment.transactionId || null,
        },
        attemptToken
      );

      if (!tyroResponse.ok || !tyroResult?.ok) {
        handleChargeErrors(tyroResult, errorEl);
        return;
      }

      paymentResult = tyroResult?.payment || paymentResult;
    }

    updatePaymentLoader(
      "Making your appointment...",
      "Sending your form to the doctor and attaching it to your booking.",
      getPaymentLoaderProgress("making_appointment")
    );

    const stopAttemptStatusPolling = startAttemptStatusPolling(
      attemptId,
      attemptToken,
      updatePaymentLoaderFromAttempt
    );

    let finalizeResponse;
    let finalizeResult;
    try {
      ({ response: finalizeResponse, result: finalizeResult } = await postJsonExpectJson(
        formHandlerData.booking_attempt_finalize_url,
        {
          attempt_id: attemptId,
          attempt_token: attemptToken,
        },
        attemptToken
      ));
    } finally {
      stopAttemptStatusPolling();
    }

    if (!finalizeResponse.ok || !finalizeResult?.ok) {
      updatePaymentLoader(
        "We could not complete your booking.",
        finalizeResult?.detail || finalizeResult?.message || "Unexpected booking error.",
        100
      );
      handleChargeErrors(finalizeResult, errorEl);
      return;
    }

    updatePaymentLoader(
      "Appointment confirmed.",
      "Your appointment and form have been confirmed.",
      100
    );

    showToast("Your appointment has been confirmed.", "success");
    window.formIsSubmitting = true;

    const redirectBase = formHandlerData.redirect_url;
    const ref =
      paymentResult?.reference ||
      (payment.gateway === "tyrohealth"
        ? payment.transactionId || "tyro"
        : payment.gateway === "stripe"
        ? "stripe"
        : "free");

    const queryParams = new URLSearchParams({
      patient_name:
        patient?.first_name && patient?.last_name
          ? `${patient.first_name} ${patient.last_name}`
          : "",
      email: patient?.email ?? "",
      ref: ref ?? "free",
      status: "booking_confirmed",
      receipt: paymentResult?.receipt_url ?? "",
      attempt_id: attemptId,
    });

    window.location.href = `${redirectBase}?${queryParams.toString()}`;
    return;
  } catch (err) {
    console.error("Request failed", err);
    const message = "Unexpected error. Please try again.";
    updatePaymentLoader("We could not complete your booking.", message, 100);
    if (errorEl) errorEl.textContent = message;
    else showToast(message, "error");
  } finally {
    hidePaymentLoader();
  }
}



// Helper to render payment errors (mirrors your existing UI pattern)
function handleChargeErrors(result, errorEl) {
  const message = result?.message || "Payment failed. Please try again.";
  if (errorEl) {
    errorEl.innerHTML = "";
    if (Array.isArray(result?.errors) && result.errors.length > 0) {
      const ul = document.createElement("ul");
      ul.style.marginTop = "0.5rem";
      ul.style.fontSize = "0.8rem";
      ul.style.paddingLeft = "1.2rem";
      ul.style.color = "#c62828";
      ul.style.fontWeight = "500";
      result.errors.forEach((e) => {
        const li = document.createElement("li");
        li.textContent = `${e.label || "Error"}: ${
          e.detail || e.code || "Unknown"
        }`;
        ul.appendChild(li);
      });
      errorEl.appendChild(ul);
    } else {
      errorEl.textContent = message;
    }
  } else {
    showToast(message);
  }
}

function showPaymentLoader() {
  const styles = formHandlerData.appearance?.variables || {};
  const logo = formHandlerData.logo_url;
  const colorPrimary = styles.colorPrimary || formHandlerData?.btn_bg || "#0073e6";
  const colorText = styles.colorText || "#333";
  const mutedText = colorWithAlpha(colorText, 0.72);
  const trackColor = "#d9dde3";
  const outlineColor = "#c7ccd3";
  const borderRadius = styles.borderRadius || "10px";
  const initialHeadline =
    paymentLoaderHeadline || "Processing your secure payment...";
  const initialDetail =
    paymentLoaderDetail || "Please wait while we confirm your appointment with the clinic.";
  const initialProgress =
    typeof paymentLoaderProgress === "number" && Number.isFinite(paymentLoaderProgress)
      ? Math.max(0, Math.min(100, paymentLoaderProgress))
      : 10;

  jQuery.LoadingOverlay("show", {
    image: "",
    background: "rgba(255, 255, 255, 0.85)",
    zIndex: 9999,
    custom: jQuery(`
      <div style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 32px;
        font-family: ${styles.fontFamily || "sans-serif"};
        color: ${colorText};
      ">
        ${
          logo
            ? `<img src="${logo}" alt="Logo" style="max-height: 60px; margin-bottom: 20px;" class="pulse-logo" />`
            : ""
        }
        <div id="es-payment-loader-title" style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
          ${initialHeadline}
        </div>
        <div id="es-payment-loader-detail" style="font-size: 14px; color: ${mutedText};">
          ${initialDetail}
        </div>
        <div style="width: min(340px, 82vw); margin-top: 18px;">
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; font-size: 12px; color: ${mutedText};">
            <span>Booking progress</span>
            <span id="es-payment-loader-value">${Math.round(initialProgress)}%</span>
          </div>
          <div
            id="es-payment-loader-progress"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="${Math.round(initialProgress)}"
            style="
              width: 100%;
              height: 10px;
              overflow: hidden;
              background: ${trackColor};
              border-radius: ${borderRadius};
              box-shadow: inset 0 0 0 1px ${outlineColor};
            "
          >
            <div
              id="es-payment-loader-fill"
              style="
                width: ${initialProgress}%;
                height: 100%;
                background: ${colorPrimary};
                border-radius: ${borderRadius};
                transition: width 240ms ease;
              "
            ></div>
          </div>
        </div>
      </div>
    `),
  });

  updatePaymentLoader(
    initialHeadline,
    initialDetail,
    initialProgress
  );

  if (!document.getElementById("pulse-logo-style")) {
    const style = document.createElement("style");
    style.id = "pulse-logo-style";
    style.innerHTML = `
      @keyframes pulseLogo {
        0%   { transform: scale(1); opacity: 1; }
        50%  { transform: scale(1.08); opacity: 0.85; }
        100% { transform: scale(1); opacity: 1; }
      }
      .pulse-logo {
        animation: pulseLogo 1.6s ease-in-out infinite;
      }
    `;
    document.head.appendChild(style);
  }
}

window.showPaymentLoader = showPaymentLoader;
window.updatePaymentLoader = updatePaymentLoader;
window.resetPaymentLoaderState = resetPaymentLoaderState;
window.hidePaymentLoader = hidePaymentLoader;

function setupSignatureCanvas() {
  const canvas = document.getElementById("signature-pad");
  const clearBtn = document.getElementById("clear-signature");
  const signatureDataInput = document.getElementById("signature-data");

  if (!canvas || !clearBtn || !signatureDataInput) return;

  const ctx = canvas.getContext("2d");
  let drawing = false;

  ctx.strokeStyle = "#000";
  ctx.lineWidth = 2;
  ctx.lineCap = "round";

  // Desktop: mouse
  canvas.addEventListener("mousedown", (e) => {
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(getX(e), getY(e));
  });

  canvas.addEventListener("mousemove", (e) => {
    if (!drawing) return;
    ctx.lineTo(getX(e), getY(e));
    ctx.stroke();
  });

  canvas.addEventListener("mouseup", () => {
    drawing = false;
    ctx.closePath();
    signatureDataInput.value = canvas.toDataURL("image/png");
  });

  canvas.addEventListener("mouseleave", () => {
    if (drawing) {
      drawing = false;
      ctx.closePath();
      signatureDataInput.value = canvas.toDataURL("image/png");
    }
  });

  // Mobile: touch
  canvas.addEventListener("touchstart", (e) => {
    e.preventDefault();
    drawing = true;
    const touch = e.touches[0];
    ctx.beginPath();
    ctx.moveTo(getTouchX(touch), getTouchY(touch));
  });

  canvas.addEventListener("touchmove", (e) => {
    e.preventDefault();
    if (!drawing) return;
    const touch = e.touches[0];
    ctx.lineTo(getTouchX(touch), getTouchY(touch));
    ctx.stroke();
  });

  canvas.addEventListener("touchend", () => {
    drawing = false;
    ctx.closePath();
    signatureDataInput.value = canvas.toDataURL("image/png");
  });

  clearBtn.addEventListener("click", () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    signatureDataInput.value = "";
  });

  function getX(e) {
    const rect = canvas.getBoundingClientRect();
    return e.clientX - rect.left;
  }

  function getY(e) {
    const rect = canvas.getBoundingClientRect();
    return e.clientY - rect.top;
  }

  function getTouchX(touch) {
    const rect = canvas.getBoundingClientRect();
    return touch.clientX - rect.left;
  }

  function getTouchY(touch) {
    const rect = canvas.getBoundingClientRect();
    return touch.clientY - rect.top;
  }
}
