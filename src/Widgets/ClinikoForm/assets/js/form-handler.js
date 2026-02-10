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

function initHeadlessCalendarHelpers() {
  if (!isHeadless) return;

  const endpoints = {
    practitioners: formHandlerData?.practitioners_url,
    calendar: formHandlerData?.appointment_calendar_url,
    availableTimes: formHandlerData?.available_times_url,
  };

  const defaultAppointmentTypeId = formHandlerData?.module_id || "";
  const defaultPerPage = Math.min(
    100,
    Math.max(1, Number(formHandlerData?.available_times_per_page || 100))
  );

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
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || "Request failed.");
    }
    return data?.data ?? data ?? {};
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
    const id = appointmentTypeId || defaultAppointmentTypeId;
    const url = buildUrl(endpoints.practitioners, { appointment_type_id: id });
    const payload = await fetchJson(url);
    return Array.isArray(payload.practitioners) ? payload.practitioners : [];
  };

  const fetchCalendar = async ({ appointmentTypeId, practitionerId, monthKey } = {}) => {
    const id = appointmentTypeId || defaultAppointmentTypeId;
    const url = buildUrl(endpoints.calendar, {
      appointment_type_id: id,
      practitioner_id: practitionerId || "",
      month: monthKey || "",
    });
    return await fetchJson(url); // { grid_html, month_label, month_key }
  };

  const fetchAvailableTimes = async ({
    appointmentTypeId,
    practitionerId,
    from,
    to,
    perPage,
    page,
  } = {}) => {
    const id = appointmentTypeId || defaultAppointmentTypeId;
    const url = buildUrl(endpoints.availableTimes, {
      appointment_type_id: id,
      practitioner_id: practitionerId || "",
      from: from || "",
      to: to || "",
      per_page: String(perPage || defaultPerPage),
      page: String(page || 1),
    });
    const payload = await fetchJson(url);
    const rawTimes = payload.available_times || [];
    const items = Array.isArray(rawTimes)
      ? rawTimes.map((t) => t?.appointment_start || t?.appointmentStart || t).filter(Boolean)
      : [];
    const total = Number(payload.total_entries || items.length);
    return { items, total };
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

  window.ClinikoHeadlessCalendar = {
    endpoints,
    getMonthKeyFromDate,
    shiftMonthKey,
    toDateInputValue,
    groupTimesByPeriod,
    fetchPractitioners,
    fetchCalendar,
    fetchAvailableTimes,
    fetchAllTimesForDate,
    updateHeadlessPatient,
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

  async function fetchPractitioners() {
    if (!practitionersEndpoint || !appointmentTypeId) return [];
    const url = new URL(practitionersEndpoint, window.location.origin);
    url.searchParams.set("appointment_type_id", appointmentTypeId);

    const res = await fetch(url.toString(), {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || "Failed to load practitioners.");
    }

    const payload = data?.data ?? data ?? {};
    return Array.isArray(payload.practitioners) ? payload.practitioners : [];
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

  async function refreshCalendar(practitioner, monthKey = null) {
    if (!calendarEndpoint || !appointmentTypeId || !calendarGrid) return;

    calendarGrid.classList.add("is-loading");
    calendarGrid.setAttribute("aria-busy", "true");

    const url = new URL(calendarEndpoint, window.location.origin);
    url.searchParams.set("appointment_type_id", appointmentTypeId);
    if (practitioner) url.searchParams.set("practitioner_id", practitioner);
    if (monthKey) url.searchParams.set("month", monthKey);

    const res = await fetch(url.toString(), {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      calendarGrid.classList.remove("is-loading");
      calendarGrid.removeAttribute("aria-busy");
      calendarGrid.innerHTML = "";
      throw new Error(data?.message || "Failed to load calendar.");
    }

    const payload = data?.data ?? data ?? {};
    calendarGrid.innerHTML = payload.grid_html || "";
    calendarGrid.classList.remove("is-loading");
    calendarGrid.removeAttribute("aria-busy");

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
  }

  async function fetchPage(from, to, page) {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set("appointment_type_id", appointmentTypeId);
    url.searchParams.set("from", from);
    url.searchParams.set("to", to);
    url.searchParams.set("per_page", String(perPage));
    url.searchParams.set("page", String(page));
    if (practitionerId) {
      url.searchParams.set("practitioner_id", practitionerId);
    }

    const res = await fetch(url.toString(), {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || "Failed to load available times.");
    }

    const payload = data?.data ?? data ?? {};
    const rawTimes = payload.available_times || [];
    const items = Array.isArray(rawTimes)
      ? rawTimes
          .map((t) => t?.appointment_start || t?.appointmentStart || t)
          .filter(Boolean)
      : [];

    const total = Number(payload.total_entries || items.length);
    return { items, total };
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
      updatePeriodIndicators(cell, cached);
      renderTimes(dateKey, cached, preselectIso);
      return;
    }

    let times = [];
    try {
      times = await fetchAllTimesForDate(dateKey);
    } catch (e) {
      setStatus(e?.message || "Failed to load available times.", true);
      return;
    }

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

function parseFormToStructuredBody(formEl) {
  const formData = new FormData(formEl);
  const sectionsData = formHandlerData.sections || [];

  const structured = {
    content: {
      sections: sectionsData
        .map((section) => {
          const questions = section.questions
            .map((q) => {
              const question = {
                name: q.name,
                type: q.type,
                required: !!q.required,
              };

              // CHECKBOXES
              if (q.type === "checkboxes" && Array.isArray(q.answers)) {
                const rawSelected = formData.getAll(q.name + "[]") || [];

                // include all options; mark only selected
                question.answers = q.answers.map((opt) => {
                  const entry = { value: opt.value };
                  if (rawSelected.includes(opt.value)) entry.selected = true;
                  return entry;
                });

                if (q.other?.enabled) {
                  const otherChecked =
                    rawSelected.includes("__other__") ||
                    rawSelected.includes("other");
                  const otherValue = (
                    formData.get(q.name + "_other") || ""
                  ).trim();

                  question.other = otherChecked
                    ? { value: otherValue, enabled: true, selected: true } // value may be "" (allowed)
                    : { enabled: true };
                }

                // RADIOBUTTONS
              } else if (
                q.type === "radiobuttons" &&
                Array.isArray(q.answers)
              ) {
                const selected = formData.get(q.name);

                question.answers = q.answers.map((opt) => {
                  const entry = { value: opt.value };
                  if (selected === opt.value) entry.selected = true;
                  return entry;
                });

                if (q.other?.enabled) {
                  const isOther =
                    selected === "__other__" || selected === "other";
                  const otherValue = (
                    formData.get(q.name + "_other") || ""
                  ).trim();
                  question.other = isOther
                    ? { value: otherValue, enabled: true, selected: true }
                    : { enabled: true };
                }

                // SIMPLE INPUTS
              } else {
                question.answer = formData.get(q.name);
              }

              return question;
            })
            .filter((q) => {
              // ✅ keep original behavior: drop signature questions
              if (q.type === "signature") return false;

              // keep original “empty question” guards
              if (
                q.answers &&
                Array.isArray(q.answers) &&
                q.answers.length === 0
              )
                return false;
              if (
                "answer" in q &&
                typeof q.answer === "string" &&
                q.answer.trim() === ""
              )
                return false;

              return true;
            });

          return {
            name: section.name,
            description: section.description,
            questions,
          };
        })
        .filter((section) => section.questions.length > 0),
    },
    ...extractNestedFields(formEl, "patient"),
  };

  return structured;
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

    // Medicare number (10 digits, ignoring spaces)
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
 * @param {{ patientBookedTime?: string|Date }} opts
 */
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

  const { content, patient } = headlessPayload || parseFormToStructuredBody(formElement);

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
    console.log("here")
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
    moduleId: formHandlerData.module_id,
    patient_form_template_id: formHandlerData.patient_form_template_id,

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

  // --- choose endpoint ---
  // IMPORTANT:
  // - Stripe flow should hit your Stripe charge endpoint (formHandlerData.payment_url)
  // - TyroHealth flow should NOT hit Stripe charge endpoint; it should hit your booking/queue endpoint
  // - Cliniko iframe flow always uses cliniko_embeded_form_sync_patient_form_url
  const submitURL = (() => {
    if (isClinikoIframe) return formHandlerData.cliniko_embeded_form_sync_patient_form_url;

    if (payment.gateway === "tyrohealth") {
      // This should be your existing queue endpoint (send-patient-form), not payment_url
      return (
        window.TyroHealthData?.confirm_booking_url ||
        formHandlerData.cliniko_embeded_form_sync_patient_form_url
      );
    }

    // Default: Stripe payment endpoint
    return formHandlerData.payment_url;
  })();

  try {
    const response = await fetch(submitURL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });

    const result = await response.json().catch(() => ({}));

    const okForCliniko =
      isClinikoIframe && response.status === 202 && result?.success;

    // ✅ Stripe: your endpoint seems to respond {status:"success"}
    // ✅ Tyro: your booking queue endpoint responds {success:true, ...} and/or 202
    const okForStripe =
      !isClinikoIframe &&
      payment.gateway === "stripe" &&
      result?.status === "success";

  const okForTyro =
    !isClinikoIframe &&
    payment.gateway === "tyrohealth" &&
    (
      result?.success === true ||
      result?.status === "success" ||
      response.status === 202
    );

    if (okForCliniko || okForStripe || okForTyro) {
      // message
      showToast("We’re scheduling your appointment now…", "success");
      window.formIsSubmitting = true;

      const redirectBase = formHandlerData.redirect_url;

      const ref =
        result?.payment?.id ||
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
        status: "scheduling_queued",
        receipt: result?.payment?.receipt_url ?? "",
      });

      window.location.href = `${redirectBase}?${queryParams.toString()}`;
      return;
    }

    // otherwise
    handleChargeErrors(result, errorEl);
  } catch (err) {
    console.error("Request failed", err);
    const message = "Unexpected error. Please try again.";
    if (errorEl) errorEl.textContent = message;
    else showToast(message, "error");
  } finally {
    try { jQuery.LoadingOverlay("hide"); } catch (_) {}
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
        color: ${styles.colorText || "#333"};
      ">
        ${
          logo
            ? `<img src="${logo}" alt="Logo" style="max-height: 60px; margin-bottom: 20px;" class="pulse-logo" />`
            : ""
        }
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
          Processing your secure payment...
        </div>
        <div style="font-size: 14px; color: #666;">
          Please wait while we confirm your appointment with the clinic.
        </div>
      </div>
    `),
  });

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
