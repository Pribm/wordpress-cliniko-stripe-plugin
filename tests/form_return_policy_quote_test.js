"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../src/Widgets/ClinikoForm/assets/js/form-handler.js"), "utf8");
const tick = () => new Promise((resolve) => setImmediate(resolve));

function bookingForm({ headless = false, verified = true } = {}) {
  const wrapper = { hidden: headless };
  const button = { disabled: false };
  const payment = {
    style: { display: headless ? "flex" : "none" },
    dataset: {},
    parentElement: wrapper,
    getClientRects() {
      return this.style.display === "none" || this.parentElement.hidden ? [] : [{}];
    },
    querySelector() { return button; },
  };
  const summary = { textContent: "" };
  const status = { textContent: "", hidden: true };
  const buttonAttributes = new Map();
  const continueButton = {
    textContent: "Continue to payment", disabled: false,
    querySelector: () => null,
    getAttribute: (name) => buttonAttributes.get(name),
    setAttribute: (name, value) => buttonAttributes.set(name, value),
    removeAttribute: (name) => buttonAttributes.delete(name),
  };
  const events = new Map();
  const requests = [];
  const timers = new Map();
  let timerId = 0;
  let quoteGate = null;
  let observer;
  let amount = 9000;
  const content = { sections: [{ name: "Treatment", questions: [{ name: "Medication", type: "text", answer: "Medication A" }] }] };
  const patient = {
    email: "guest@example.test", first_name: "Jane", last_name: "Doe",
    address_1: "22 West Street", city: "Brisbane", state: "QLD", post_code: "4000", country: "Australia",
    date_of_birth: "1990-05-10", phone: "0412345678", medicare: "1234 56789 0",
  };
  const nodes = {
    "prepayment-form": headless ? null : { style: { display: "block" }, addEventListener() {} },
    payment_form: payment,
    "summary-price": summary,
    "return-policy-status": status,
    "step-next": continueButton,
  };

  const context = vm.createContext({
    content,
    patient,
    console,
    formHandlerData: {
      is_payment_enabled: "yes",
      custom_form_payment: "tyrohealth",
      appointment_time_selection: "next_available",
      module_id: "222",
      patient_form_template_id: "999",
      rest_nonce: verified ? "wordpress-nonce" : "",
      authenticated_patient_booking: verified ? { enabled: true, nonce: "widget-nonce", preflight_url: "/preflight" } : {},
      return_policy: {
        id: "rule",
        quote_url: "/quote",
        module_id: "222",
        template_id: "999",
        question: { section: "Treatment", name: "Medication", type: "text" },
      },
    },
    document: {
      getElementById: (id) => nodes[id] || null,
      querySelector: () => null,
      querySelectorAll: () => [],
      addEventListener: (name, callback) => events.set(name, callback),
      dispatchEvent() {},
    },
    window: { addEventListener() {}, getComputedStyle: (element) => element.style },
    setTimeout: (fn) => { timers.set(++timerId, fn); return timerId; },
    clearTimeout: (id) => timers.delete(id),
    CustomEvent: class {},
    MutationObserver: class {
      constructor(callback) {
        this.callback = callback;
        this.targets = [];
        observer = this;
      }
      observe(target) { this.targets.push(target); }
    },
    fetch: async (url, request) => {
      assert.equal(url, "/quote", "The price check should only contact the quote endpoint");
      const body = JSON.parse(request.body);
      requests.push(body);
      const guestMatched = body.patient?.email === "guest@example.test" &&
        body.patient.first_name === "Jane" && body.patient.last_name === "Doe" &&
        body.patient.date_of_birth === "1990-05-10";
      const guestPrice = guestMatched ? 6000 : 9000;
      const quotePrice = verified ? amount : guestPrice;
      if (quoteGate) await quoteGate;
      return {
        ok: true,
        json: async () => ({
          ok: true,
          quote: {
            appointment_type_id: quotePrice === 9000 ? "222" : "223",
            amount_cents: quotePrice,
            return_pricing_applied: quotePrice === 6000,
            patient_verified: verified,
          },
        }),
      };
    },
  });

  vm.runInContext(source, context);
  const run = (script) => vm.runInContext(script, context);
  if (headless) {
    run("isHeadless = true; window.clinikoHeadlessPayload = { content, patient }; initHeadlessCalendarHelpers();");
  } else {
    run('parseFormToStructuredBody = () => ({ content, patient }); nextBtn = document.getElementById("step-next");');
  }
  return {
    run, requests, payment, wrapper, summary, status, continueButton,
    getObserver: () => observer,
    setPrice: (price) => { amount = price; },
    expireQuote: () => run("returnPolicyRequest.expiresAt = 0"),
    releaseTimers: () => {
      const callbacks = [...timers.values()];
      timers.clear();
      callbacks.forEach((callback) => callback());
    },
    pauseQuotes: () => {
      let release;
      quoteGate = new Promise((resolve) => { release = resolve; });
      return () => { quoteGate = null; release(); };
    },
    changePatient: (fields) => {
      run(`window.ClinikoHeadlessCalendar.updateHeadlessPatient(${JSON.stringify(fields)})`);
      events.get("change")?.();
      return tick();
    },
    changeAnswer: (answer) => {
      content.sections[0].questions[0].answer = answer;
      events.get("change")?.();
      return tick();
    },
  };
}

async function main() {
  const rendered = bookingForm();
  await rendered.run("prepareReturnPolicyQuote({ content, patient })");
  rendered.setPrice(6000);
  await rendered.run("showStripePaymentForm()");
  assert.equal(rendered.requests.length, 1, "Opening payment should reuse a recent quote for the same inputs");
  rendered.expireQuote();
  const releaseRenderedQuote = rendered.pauseQuotes();
  const continueToPayment = rendered.run("showStripePaymentForm()");
  assert.equal(rendered.continueButton.textContent, "Checking your price…", "The button should show progress during a quote");
  assert.equal(rendered.continueButton.disabled, true);
  releaseRenderedQuote();
  await continueToPayment;
  assert.equal(rendered.continueButton.textContent, "Continue to payment");
  assert.equal(rendered.continueButton.disabled, false);
  assert.equal(rendered.summary.textContent, "60.00", "An expired quote should refresh before opening payment");
  assert.equal(rendered.status.textContent, "Return price applied.");
  assert.equal(rendered.requests.length, 2);
  assert.equal(rendered.payment.style.display, "flex");

  const headless = bookingForm({ headless: true });
  await headless.run("window.ClinikoHeadlessCalendar.resolveBookingQuote()");
  headless.run("initHeadlessPaymentWatcher()");
  const observer = headless.getObserver();
  assert.ok(observer.targets.includes(headless.wrapper), "The watcher should observe the headless step wrapper");
  assert.equal(headless.requests.length, 1, "A hidden wrapper should not trigger a payment quote");
  headless.setPrice(6000);
  headless.expireQuote();
  headless.wrapper.hidden = false;
  await observer.callback();
  assert.equal(headless.summary.textContent, "60.00", "Showing the headless payment step should refresh the price");
  assert.equal(headless.requests[1].authenticated_booking_nonce, "widget-nonce");
  assert.equal(headless.requests[1].patient, undefined, "Account quotes should use the verified link instead of browser contact details");
  headless.setPrice(9000);
  await headless.changeAnswer("Medication B");
  assert.equal(headless.summary.textContent, "90.00", "Editing the answer should update an open headless payment step");

  const guest = bookingForm({ verified: false });
  await guest.run("showStripePaymentForm()");
  assert.equal(guest.summary.textContent, "60.00", "The rendered form should show the matched guest price at payment");
  assert.deepEqual(guest.requests.at(-1).patient, {
    email: "guest@example.test", first_name: "Jane", last_name: "Doe", date_of_birth: "1990-05-10",
  });
  assert.equal(guest.status.textContent, "Return price applied.");

  const headlessGuest = bookingForm({ headless: true, verified: false });
  const releaseBackgroundQuote = headlessGuest.pauseQuotes();
  headlessGuest.run("initReturnPolicyQuotePrefetch()");
  headlessGuest.releaseTimers();
  assert.equal(headlessGuest.requests.length, 1, "The quote should begin as soon as the headless identity is complete");
  const checkoutQuote = headlessGuest.run("window.ClinikoHeadlessCalendar.resolveBookingQuote({ content, patient })");
  assert.equal(headlessGuest.requests.length, 1, "Continue to payment should share the background quote");
  releaseBackgroundQuote();
  await checkoutQuote;
  assert.equal(headlessGuest.summary.textContent, "60.00", "The headless helper should use the supplied guest details");
  headlessGuest.run("initHeadlessPaymentWatcher()");
  headlessGuest.wrapper.hidden = false;
  await headlessGuest.getObserver().callback();
  const requestsBeforeAddressEdit = headlessGuest.requests.length;
  await headlessGuest.changePatient({ address_1: "24 West Street" });
  assert.equal(headlessGuest.requests.length, requestsBeforeAddressEdit, "An address edit should keep the cached quote");
  await headlessGuest.changePatient({ date_of_birth: "1990-05-11" });
  assert.equal(headlessGuest.summary.textContent, "90.00", "A date of birth edit should update the guest price");
  assert.equal(headlessGuest.status.textContent, "Price updated.");
  await headlessGuest.changePatient({ date_of_birth: "1990-05-10" });
  assert.equal(headlessGuest.summary.textContent, "60.00", "Correcting the date of birth should restore the return price");
  console.log("Return policy quote tests passed.");
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
