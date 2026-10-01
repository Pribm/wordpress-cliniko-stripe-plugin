(function () {
  const config = window.ClinikoPatientBookingData || {};
  const componentSettings = config.components || window.ClinikoComponentSettings || {};

  const inspectConnection = (response, payload) => {
    window.ClinikoConnectionNotice?.inspectResponse?.(response, payload);
  };

  const reportNetworkFailure = (error) => {
    if (!navigator.onLine || error instanceof TypeError) {
      window.ClinikoConnectionNotice?.reportOffline?.();
    }
  };

  const post = async (url, payload, token) => {
    const headers = { "Content-Type": "application/json" };
    if (config.rest_nonce) headers["X-WP-Nonce"] = config.rest_nonce;
    if (token) headers["X-ES-Attempt-Token"] = token;
    try {
      const response = await fetch(url, { method: "POST", credentials: "same-origin", headers, body: JSON.stringify(payload) });
      let result = {};
      try { result = await response.json(); } catch (e) { result = {}; }
      inspectConnection(response, result);
      return { response, result };
    } catch (error) {
      reportNetworkFailure(error);
      throw error;
    }
  };

  const message = (form, text, error) => {
    const node = form.querySelector("[data-booking-status]");
    node.textContent = text || "";
    node.classList.toggle("is-error", !!error);
  };

  const buildContent = (form) => {
    const sections = {};
    form.querySelectorAll("fieldset[data-section-index]").forEach((fieldset) => {
      const si = fieldset.dataset.sectionIndex;
      sections[si] = {
        name: fieldset.dataset.sectionName || "",
        description: fieldset.dataset.sectionDescription || "",
        questions: {},
      };

      fieldset.querySelectorAll("[data-question-type]").forEach((node) => {
        const qi = node.dataset.questionIndex;
        const type = node.dataset.questionType;
        const label = node.querySelector("label")?.textContent?.replace(/\s*\*\s*$/, "") || "Question";
        const required = node.dataset.questionRequired === "1" || !!node.querySelector("[required]");
        const question = { name: label, type, required };
        if (type === "checkboxes" || type === "radiobuttons") {
          question.answers = Array.from(node.querySelectorAll("[data-booking-answer-option]")).map((input) => ({ value: input.value, selected: input.checked }));
          const otherToggle = node.querySelector("[data-booking-other-toggle]");
          if (otherToggle) question.other = otherToggle.checked
            ? { enabled: true, selected: true, value: node.querySelector("[data-booking-other-input]")?.value?.trim() || "" }
            : { enabled: true };
        } else {
          const control = node.querySelector("textarea,input,select");
          question.answer = window.ClinikoShortcodeInputRules?.valueForSubmission(control) || control?.value || "";
        }
        sections[si].questions[qi] = question;
      });
    });
    return { sections: Object.keys(sections).sort((a, b) => Number(a) - Number(b)).map((si) => ({ ...sections[si], questions: Object.keys(sections[si].questions).sort((a, b) => Number(a) - Number(b)).map((qi) => sections[si].questions[qi]) })) };
  };

  const buildPatient = (form) => {
    const field = (name) => {
      const control = form.querySelector(`[name="patient[${name}]"]`) || form.querySelector(`[name="${name}"]`);
      return window.ClinikoShortcodeInputRules?.valueForSubmission(control) || control?.value || "";
    };
    return {
    first_name: field("first_name"),
    last_name: field("last_name"),
    email: field("email"),
    phone: field("phone"),
    medicare: field("medicare"),
    medicare_reference_number: field("medicare_reference_number"),
    address_1: field("address_1"),
    address_2: field("address_2"),
    city: field("city"),
    state: field("state"),
    post_code: field("post_code"),
    country: field("country"),
    date_of_birth: field("date_of_birth"),
    practitioner_id: form.querySelector("[name='practitioner_id']")?.value || "",
    appointment_date: form.querySelector("[name='appointment_date']")?.value || "",
    appointment_start: form.querySelector("[name='appointment_start']")?.value || "",
    };
  };

  const initialiseOtherChoices = (form) => {
    form.querySelectorAll("[data-booking-other-toggle]").forEach((toggle) => {
      const question = toggle.closest("[data-question-type]");
      const control = question?.querySelector("[data-booking-other-control]");
      const input = control?.querySelector("[data-booking-other-input]");
      const groupName = toggle.name;
      const apply = () => {
        const selected = toggle.checked;
        if (control) control.hidden = !selected;
        if (input) {
          input.required = selected;
          if (!selected) input.setCustomValidity("");
        }
      };
      question?.querySelectorAll("input[type='checkbox'], input[type='radio']").forEach((choice) => {
        if (choice.name === groupName) choice.addEventListener("change", apply);
      });
      apply();
    });
  };

  const loadPractitioners = async (form) => {
    const select = form.querySelector("[data-booking-practitioner]");
    let response;
    let data;
    try {
      response = await fetch(`${config.practitioners_url}?appointment_type_id=${encodeURIComponent(config.appointment_type_id)}`, { credentials: "same-origin" });
      data = await response.json();
      inspectConnection(response, data);
      if (!response.ok) throw new Error(data?.message || "Practitioners could not be loaded.");
    } catch (error) {
      reportNetworkFailure(error);
      throw error;
    }
    const practitioners = data?.data?.practitioners || [];
    if (select) {
      select.innerHTML = '<option value="">Select a practitioner</option>';
      practitioners.forEach((item) => { const option = document.createElement("option"); option.value = item.id; option.textContent = item.name; select.appendChild(option); });
    } else {
      const hiddenPractitioner = form.querySelector("[name='practitioner_id']");
      if (hiddenPractitioner && practitioners[0]) hiddenPractitioner.value = practitioners[0].id;
    }
  };

  const timePeriod = (iso) => {
    const hour = new Date(iso).getHours();
    if (hour >= 5 && hour < 12) return "morning";
    if (hour >= 12 && hour < 17) return "afternoon";
    return "evening";
  };

  const setTimesLoading = (form, loading) => {
    const loader = form.querySelector("[data-booking-times-loader]");
    const placeholder = form.querySelector("[data-booking-times-placeholder]");
    const groups = form.querySelector("[data-booking-time-groups]");
    const empty = form.querySelector("[data-booking-times-empty]");
    if (loader) loader.hidden = !loading;
    if (loading) {
      if (placeholder) placeholder.hidden = true;
      if (groups) groups.hidden = true;
      if (empty) empty.hidden = true;
    }
  };

  const renderTimeSlots = (form, times, date, select) => {
    const groups = form.querySelector("[data-booking-time-groups]");
    const placeholder = form.querySelector("[data-booking-times-placeholder]");
    const empty = form.querySelector("[data-booking-times-empty]");
    const title = form.querySelector("[data-booking-times-title]");
    const hint = form.querySelector("[data-booking-times-hint]");
    const buckets = { morning: [], afternoon: [], evening: [] };
    times.forEach((item) => buckets[timePeriod(item.iso)].push(item));
    form.querySelectorAll("[data-booking-time-slots]").forEach((slotGroup) => slotGroup.replaceChildren());

    if (title) {
      const day = new Date(`${date}T00:00:00`);
      title.textContent = Number.isNaN(day.getTime()) ? "Available times" : `Available times — ${day.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" })}`;
    }
    if (!times.length) {
      if (groups) groups.hidden = true;
      if (placeholder) placeholder.hidden = true;
      if (empty) empty.hidden = false;
      if (hint) hint.textContent = "No available times were returned for this day.";
      select.closest("label")?.classList.add("is-enhanced");
      return;
    }

    Object.entries(buckets).forEach(([period, items]) => {
      const group = form.querySelector(`[data-booking-time-group="${period}"]`);
      const slotGroup = form.querySelector(`[data-booking-time-slots="${period}"]`);
      if (group) group.hidden = items.length === 0;
      items.forEach((item) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "appointment-time-slot";
        button.dataset.bookingTimeSlot = item.iso;
        button.textContent = item.label;
        button.classList.toggle("is-selected", select.value === item.iso);
        button.addEventListener("click", () => {
          form.querySelectorAll("[data-booking-time-slot].is-selected").forEach((current) => current.classList.remove("is-selected"));
          button.classList.add("is-selected");
          select.value = item.iso;
          select.setCustomValidity("");
          select.dispatchEvent(new Event("change", { bubbles: true }));
          if (hint) hint.textContent = `${item.label} selected.`;
        });
        slotGroup?.appendChild(button);
      });
    });
    if (groups) groups.hidden = false;
    if (placeholder) placeholder.hidden = true;
    if (empty) empty.hidden = true;
    if (hint) hint.textContent = "Select an available time below to continue.";
    select.closest("label")?.classList.add("is-enhanced");
  };

  const loadTimes = async (form) => {
    const date = form.querySelector("[data-booking-date]")?.value || "";
    const practitioner = form.querySelector("[data-booking-practitioner]")?.value || form.querySelector("[name='practitioner_id']")?.value || "";
    const select = form.querySelector("[data-booking-time]");
    if (!date || !practitioner || !select) return;
    select.innerHTML = "<option>Loading times...</option>";
    setTimesLoading(form, true);
    const url = new URL(config.available_times_url, window.location.origin);
    url.searchParams.set("appointment_type_id", config.appointment_type_id);
    url.searchParams.set("practitioner_id", practitioner);
    url.searchParams.set("from", date);
    url.searchParams.set("to", date);
    url.searchParams.set("per_page", "100");
    url.searchParams.set("_ts", String(Date.now()));
    try {
      const response = await fetch(url.toString(), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "Cache-Control": "no-cache, no-store",
          Pragma: "no-cache",
        },
      });
      const data = await response.json();
      inspectConnection(response, data);
      if (!response.ok) throw new Error(data?.message || "Available times could not be loaded.");
      const times = (data?.data?.available_times || []).map((item) => {
        const iso = typeof item === "string" ? item : item?.appointment_start || "";
        return {
          iso,
          label: new Date(iso).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }),
        };
      }).filter((item) => item.iso);
      select.innerHTML = '<option value="">Select a time</option>';
      times.forEach((item) => {
        const option = document.createElement("option");
        option.value = item.iso;
        option.textContent = item.label;
        select.appendChild(option);
      });
      renderTimeSlots(form, times, date, select);
      if (componentSettings.calendar?.scroll_to_times) {
        form.querySelector("[data-booking-times-panel]")?.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    } catch (error) {
      reportNetworkFailure(error);
      select.innerHTML = '<option value="">Select a time</option>';
      renderTimeSlots(form, [], date, select);
      const hint = form.querySelector("[data-booking-times-hint]");
      if (hint) hint.textContent = error.message || "Available times could not be loaded.";
    } finally {
      setTimesLoading(form, false);
    }
  };

  const monthKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
  const shiftMonth = (key, delta) => {
    const [year, month] = key.split("-").map(Number);
    return month ? monthKey(new Date(year, month - 1 + delta, 1)) : monthKey(new Date());
  };

  const calendarRequestState = new WeakMap();
  const getCalendarRequestState = (form) => {
    let state = calendarRequestState.get(form);
    if (!state) {
      state = { preloaded: new Map(), pending: new Map() };
      calendarRequestState.set(form, state);
    }
    return state;
  };

  const getCalendarRequestKey = (form, practitioner, month) =>
    [config.appointment_type_id, practitioner, month].join(":");

  const clearCalendarPreloads = (form) => {
    getCalendarRequestState(form).preloaded.clear();
  };

  const fetchCalendarData = async (form, month, options = {}) => {
    const practitioner = form.querySelector("[data-booking-practitioner]")?.value || form.querySelector("[name='practitioner_id']")?.value || "";
    if (!practitioner || !config.appointment_calendar_url) throw new Error("No practitioner availability is available.");
    const state = getCalendarRequestState(form);
    const key = getCalendarRequestKey(form, practitioner, month);
    const consumePreload = options.consume !== false;

    if (!options.forceFresh && consumePreload && state.preloaded.has(key)) {
      const preloaded = state.preloaded.get(key);
      state.preloaded.delete(key);
      return preloaded;
    }

    const pending = state.pending.get(key);
    if (pending) {
      const pendingData = await pending;
      if (consumePreload) state.preloaded.delete(key);
      return pendingData;
    }

    if (options.forceFresh) state.preloaded.delete(key);
    const url = new URL(config.appointment_calendar_url, window.location.origin);
    url.searchParams.set("appointment_type_id", config.appointment_type_id);
    url.searchParams.set("practitioner_id", practitioner);
    url.searchParams.set("month", month);
    url.searchParams.set("_ts", String(Date.now()));
    const request = (async () => {
      const response = await fetch(url.toString(), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "Cache-Control": "no-cache, no-store",
          Pragma: "no-cache",
        },
      });
      const payload = await response.json();
      inspectConnection(response, payload);
      const data = payload?.data || payload;
      if (!response.ok || !data?.grid_html) throw new Error("Calendar could not be loaded.");
      if (options.preload) state.preloaded.set(key, data);
      return data;
    })();
    state.pending.set(key, request);
    try {
      return await request;
    } finally {
      if (state.pending.get(key) === request) state.pending.delete(key);
    }
  };

  const preloadCalendarMonth = async (form, month) => {
    if (componentSettings.calendar?.prefetch === false) return false;
    const practitioner = form.querySelector("[data-booking-practitioner]")?.value || form.querySelector("[name='practitioner_id']")?.value || "";
    if (!practitioner || !month) return false;
    const state = getCalendarRequestState(form);
    const key = getCalendarRequestKey(form, practitioner, month);
    if (state.preloaded.has(key) || state.pending.has(key)) return true;
    try {
      await fetchCalendarData(form, month, { consume: false, preload: true });
      return true;
    } catch (_) {
      return false;
    }
  };

  const loadCalendar = async (form, month, options = {}) => {
    const calendar = form.querySelector("[data-booking-calendar]");
    const grid = calendar?.querySelector("[data-calendar-grid]");
    const label = calendar?.querySelector("[data-calendar-month]");
    const status = calendar?.querySelector("[data-calendar-status]");
    const loader = calendar?.querySelector("[data-calendar-loader]");
    if (!calendar || !grid || !config.appointment_calendar_url) return;
    grid.setAttribute("aria-busy", "true");
    if (loader) { loader.hidden = false; loader.style.display = "flex"; }
    try {
      const data = await fetchCalendarData(form, month, {
        forceFresh: options.forceFresh === true,
      });
      grid.innerHTML = data.grid_html;
      const table = grid.querySelector("table.appointment-calendar__table");
      const cells = table ? Array.from(table.querySelectorAll("tbody td.calendar-day")) : [];
      if (cells.length) {
        const fragment = document.createDocumentFragment();
        cells.forEach((cell) => fragment.appendChild(cell));
        grid.replaceChildren(fragment);
      }
      grid.dataset.calendarMonth = data.month_key || month;
      if (label) label.textContent = data.month_label || month;
      if (status) status.textContent = "Select an available day. Morning, afternoon, and evening markers show when times are available.";
      const visibleMonth = data.month_key || month;
      const direction = Number(options.prefetchDirection) < 0 ? -1 : 1;
      void preloadCalendarMonth(form, shiftMonth(visibleMonth, direction));
    } catch (error) {
      reportNetworkFailure(error);
      grid.innerHTML = "";
      if (status) status.textContent = error.message || "Calendar could not be loaded.";
    } finally {
      grid.removeAttribute("aria-busy");
      if (loader) { loader.hidden = true; loader.style.display = "none"; }
    }
  };

  const initCalendar = (form) => {
    const calendar = form.querySelector("[data-booking-calendar]");
    if (!calendar || calendar.dataset.initialized === "1") return;
    calendar.dataset.initialized = "1";
    const grid = calendar.querySelector("[data-calendar-grid]");
    const initialMonth = monthKey(new Date());
    grid.dataset.calendarMonth = initialMonth;
    calendar.querySelector("[data-calendar-prev]")?.addEventListener("click", () => {
      const current = grid.dataset.calendarMonth || initialMonth;
      void loadCalendar(form, shiftMonth(current, -1), { prefetchDirection: -1 });
    });
    calendar.querySelector("[data-calendar-next]")?.addEventListener("click", () => {
      const current = grid.dataset.calendarMonth || initialMonth;
      void loadCalendar(form, shiftMonth(current, 1), { prefetchDirection: 1 });
    });
    grid.addEventListener("click", (event) => {
      const day = event.target.closest(".calendar-day");
      if (!day || day.classList.contains("is-disabled") || day.classList.contains("is-blank")) return;
      const date = form.querySelector("[data-booking-date]");
      if (!date) return;
      date.value = day.dataset.date || "";
      grid.querySelectorAll(".calendar-day.is-selected").forEach((item) => item.classList.remove("is-selected"));
      day.classList.add("is-selected");
      date.dispatchEvent(new Event("change", { bubbles: true }));
    });
    grid.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      const day = event.target.closest(".calendar-day");
      if (!day || day.classList.contains("is-disabled") || day.classList.contains("is-blank")) return;
      event.preventDefault();
      day.click();
    });
    void Promise.allSettled([
      loadCalendar(form, initialMonth, { prefetchDirection: 1 }),
      preloadCalendarMonth(form, shiftMonth(initialMonth, 1)),
    ]);
  };

  const initStripe = (form) => {
    if (config.gateway !== "stripe" || !window.Stripe || !config.stripe_pk) return null;
    const stripe = window.Stripe(config.stripe_pk);
    const elements = stripe.elements();
    const card = elements.create("card");
    card.mount(form.querySelector("[data-booking-card]"));
    card.on("change", (event) => { form.querySelector("[data-booking-payment-error]").textContent = event.error?.message || ""; });
    return { stripe, card };
  };

  const initSteps = (form) => {
    if (form.dataset.multistep !== "yes" || form.dataset.stepsInitialized === "1") return;
    const steps = Array.from(form.querySelectorAll("[data-booking-step]"));
    if (steps.length < 2) return;
    form.dataset.stepsInitialized = "1";
    let index = 0;
    const controls = document.createElement("div");
    const back = document.createElement("button");
    const next = document.createElement("button");
    const submit = form.querySelector("[type='submit']");
    controls.className = "cliniko-booking-step-controls";
    controls.dataset.bookingStepControls = "1";
    back.type = "button";
    next.type = "button";
    back.className = "cliniko-booking-step-controls__back";
    next.className = "cliniko-booking-step-controls__next";
    back.disabled = true;
    back.textContent = "Back";
    next.textContent = "Next";
    controls.append(back, next);
    if (submit && submit.parentNode) {
      submit.parentNode.insertBefore(controls, submit);
    } else {
      form.appendChild(controls);
    }
    const progress = form.querySelector("[data-booking-progress]");
    let progressSummary = null;
    let progressTrack = null;
    let progressFill = null;
    if (progress) {
      progressSummary = document.createElement("p");
      progressSummary.className = "cliniko-booking-progress-summary";
      progress.parentNode.insertBefore(progressSummary, progress);
      progressTrack = document.createElement("div");
      progressTrack.className = "cliniko-booking-progress-track";
      progressFill = document.createElement("span");
      progressFill.className = "cliniko-booking-progress-fill";
      progressTrack.appendChild(progressFill);
      progress.parentNode.insertBefore(progressTrack, progress);
      steps.forEach((step, stepIndex) => {
        const item = document.createElement("li");
        item.textContent = step.dataset.stepLabel || `Step ${stepIndex + 1}`;
        item.dataset.step = String(stepIndex);
        item.setAttribute("aria-current", stepIndex === 0 ? "step" : "false");
        progress.appendChild(item);
      });
    }
    const render = () => {
      steps.forEach((step, stepIndex) => {
        const active = stepIndex === index;
        step.hidden = !active;
        step.style.display = active ? "" : "none";
        step.setAttribute("aria-hidden", active ? "false" : "true");
      });
      const showBack = index > 0;
      const showNext = index < steps.length - 1;
      const showSubmit = index === steps.length - 1;
      back.hidden = !showBack;
      back.style.display = showBack ? "" : "none";
      back.disabled = !showBack;
      next.hidden = !showNext;
      next.style.display = showNext ? "" : "none";
      if (submit) {
        submit.hidden = !showSubmit;
        submit.style.display = showSubmit ? "" : "none";
      }
      progress?.querySelectorAll("[data-step]").forEach((item, itemIndex) => {
        const active = itemIndex === index;
        item.classList.toggle("is-active", active);
        item.classList.toggle("is-complete", itemIndex < index);
        item.setAttribute("aria-current", active ? "step" : "false");
      });
      if (progressSummary) progressSummary.textContent = `Step ${index + 1} of ${steps.length}: ${steps[index].dataset.stepLabel || "Current step"}`;
      if (progressFill) progressFill.style.width = `${steps.length > 1 ? (index / (steps.length - 1)) * 100 : 100}%`;
    };
    const valid = (step) => Array.from(step.querySelectorAll("input,select,textarea")).every((control) => control.reportValidity());
    next.addEventListener("click", () => {
      if (!valid(steps[index])) return;
      index = Math.min(index + 1, steps.length - 1);
      render();
    });
    back.addEventListener("click", () => { index = Math.max(index - 1, 0); render(); });
    render();
  };

  const finish = (form) => {
    if (config.success_action === "redirect" && config.success_redirect_url) {
      window.location.assign(config.success_redirect_url);
      return;
    }
    message(form, config.success_message || "Your appointment has been confirmed.", false);
    form.reset();
  };

  const runTyroPayment = async (form, attempt, patient) => {
    if (!window.MedipassTransactionSDK || !config.tyro || !config.tyro.appId || !config.tyro.appVersion) {
      throw new Error("Tyro Health is not available.");
    }
    const tokenResponse = await post(config.tyro.sdk_token_url, {});
    if (!tokenResponse.response.ok || !tokenResponse.result || !tokenResponse.result.token) {
      throw new Error("Tyro Health is not available.");
    }
    window.MedipassTransactionSDK.setConfig({
      env: config.tyro.env,
      apiKey: tokenResponse.result.token,
      appId: config.tyro.appId,
      appVersion: config.tyro.appVersion,
    });
    const payment = attempt.payment || {};
    await new Promise((resolve, reject) => {
      window.MedipassTransactionSDK.renderCreateTransaction({
        platform: "virtual-terminal",
        paymentMethod: "new-payment-card",
        chargeAmount: "$" + ((payment.amount || 0) / 100).toFixed(2),
        invoiceReference: payment.invoice_reference || "Cliniko booking",
        patient: {
          firstName: patient.first_name,
          lastName: patient.last_name,
          email: patient.email,
          mobile: patient.phone,
        },
        ...(config.tyro.provider_number ? { providerNumber: config.tyro.provider_number } : {}),
      }, {
        hideChatBubble: true,
        allowEdit: false,
        onSuccess: async (transaction) => {
          try {
            const transactionId = String(transaction && transaction._id || "").trim();
            if (!transactionId) throw new Error("Tyro payment could not be verified.");
            const confirmed = await post(config.confirm_tyro_url, {
              attempt_id: attempt.attempt.id,
              transactionId,
            }, attempt.attempt.token);
            if (!confirmed.response.ok || !confirmed.result || !confirmed.result.ok) throw new Error("Tyro payment could not be verified.");
            const finalized = await post(config.finalize_url, {
              attempt_id: attempt.attempt.id,
            }, attempt.attempt.token);
            if (!finalized.response.ok || !finalized.result || !finalized.result.ok) throw new Error("The appointment could not be completed.");
            resolve();
          } catch (error) {
            reject(error);
          }
        },
        onError: () => reject(new Error("Tyro payment could not be completed.")),
        onCancel: () => reject(new Error("Tyro payment was cancelled.")),
      });
    });
  };

  document.querySelectorAll("[data-cliniko-patient-booking-form], [data-cliniko-guest-booking-form]").forEach((form) => {
    if (config.renewal_appointment_id) {
      const questionSteps = Array.from(form.querySelectorAll(".cliniko-patient-booking-form__questions > [data-booking-step]"));
      if (questionSteps.length > 1) {
        const reviewStep = questionSteps[0];
        reviewStep.dataset.stepLabel = "Review last appointment";
        questionSteps.slice(1).forEach((step) => {
          step.querySelectorAll("fieldset").forEach((fieldset) => reviewStep.appendChild(fieldset));
          step.remove();
        });
      } else if (questionSteps[0]) {
        questionSteps[0].dataset.stepLabel = "Review last appointment";
      }
      if (window.ClinikoComponents?.review?.init) {
        window.ClinikoComponents.review.init(form);
      } else {
        form.querySelectorAll("[data-question-type] input, [data-question-type] select, [data-question-type] textarea").forEach((control) => {
          control.disabled = true;
          control.dataset.renewalLocked = "1";
        });
        form.querySelectorAll("[data-question-type]").forEach((field) => field.classList.add("is-reviewing"));
        form.querySelectorAll("[data-renewal-edit]").forEach((button) => {
          button.addEventListener("click", () => {
            const field = button.closest("[data-question-type]");
            if (!field) return;
            field.querySelectorAll("input,select,textarea").forEach((control) => {
              control.disabled = false;
              control.removeAttribute("data-renewal-locked");
            });
            button.hidden = true;
            field.classList.add("is-editing");
            field.classList.remove("is-reviewing");
            field.querySelector("input:not([type='hidden']),select,textarea")?.focus();
          });
        });
      }
    }
    initialiseOtherChoices(form);
    const date = form.querySelector("[data-booking-date]");
    const automaticScheduling = form.dataset.schedulingMode === "next_available";
    if (!automaticScheduling && date) {
      const today = new Date();
      date.min = today.toISOString().slice(0, 10);
      const practitionerRequest = loadPractitioners(form).catch(() => message(form, "Practitioners could not be loaded.", true));
      const practitioner = form.querySelector("[data-booking-practitioner]");
      if (practitioner) practitioner.addEventListener("change", () => loadTimes(form));
      date.addEventListener("change", () => loadTimes(form));
      if (practitioner) practitioner.addEventListener("change", () => {
        clearCalendarPreloads(form);
        return loadCalendar(form, form.querySelector("[data-calendar-grid]")?.dataset.calendarMonth || monthKey(new Date()), {
          forceFresh: true,
          prefetchDirection: 1,
        });
      });
      if (form.querySelector("[data-booking-calendar]")) void practitionerRequest.then(() => initCalendar(form));
    }
    if (date && !form.querySelector("[data-booking-calendar]")) {
      date.addEventListener("change", () => loadTimes(form));
    }
    if (window.ClinikoComponents?.steps?.init) {
      window.ClinikoComponents.steps.init(form);
    } else {
      initSteps(form);
    }
    const stripeState = initStripe(form);

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const selectedTime = form.querySelector("[data-booking-time]");
      if (selectedTime && !selectedTime.value) {
        const hint = form.querySelector("[data-booking-times-hint]");
        if (hint) hint.textContent = "Select an available time to continue.";
        form.querySelector("[data-booking-times-panel]")?.focus();
        return;
      }
      if (!form.reportValidity()) return;
      const patient = buildPatient(form);
      message(form, "Validating your booking...", false);
      const payload = { moduleId: config.appointment_type_id, patient_form_template_id: config.template_id, patient, content: buildContent(form), gateway: config.gateway === "none" ? "free" : config.gateway };
      if (config.authenticated) {
        payload.booking_form_id = config.form_id;
        payload.booking_form_nonce = config.form_nonce;
      }
      if (config.renewal_appointment_id) payload.renewal_appointment_id = config.renewal_appointment_id;
      try {
        const preflight = await post(config.preflight_url, payload);
        if (!preflight.response.ok || !preflight.result.ok) throw new Error(preflight.result.detail || preflight.result.message || "The booking could not be validated.");
        const attemptId = preflight.result.attempt.id;
        const attemptToken = preflight.result.attempt.token;
        let payment = preflight.result.payment || {};
        if (payment.required && config.gateway === "stripe") {
          if (!stripeState) throw new Error("Payment is required but Stripe could not be initialized.");
          const tokenResult = await stripeState.stripe.createToken(stripeState.card);
          if (tokenResult.error) throw new Error(tokenResult.error.message);
          const charged = await post(config.charge_url, { attempt_id: attemptId, stripeToken: tokenResult.token.id }, attemptToken);
          if (!charged.response.ok || !charged.result.ok) throw new Error(charged.result.detail || charged.result.message || "Payment failed.");
          payment = charged.result.payment || payment;
        } else if (payment.required && config.gateway === "tyrohealth") {
          await runTyroPayment(form, attempt, patient);
          finish(form);
          return;
        }
        message(form, "Confirming your appointment...", false);
        const finalized = await post(config.finalize_url, { attempt_id: attemptId }, attemptToken);
        if (!finalized.response.ok || !finalized.result.ok) throw new Error(finalized.result.detail || finalized.result.message || "The appointment could not be completed.");
        if (config.success_action === "redirect" && config.success_redirect_url) {
          window.location.assign(config.success_redirect_url);
          return;
        }
        finish(form);
      } catch (error) {
        if (config.failure_action === "redirect" && config.failure_redirect_url) {
          window.location.assign(config.failure_redirect_url);
          return;
        }
        message(form, config.failure_message || "We could not complete your booking. Please try again.", true);
      }
    });
  });
})();
