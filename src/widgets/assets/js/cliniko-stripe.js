async function initStripe() {
  if (typeof Stripe === "undefined") {
    console.error("Stripe.js not loaded");
    return;
  }

  window.stripe = Stripe(ClinikoStripeData.stripe_pk);
  const { stripe } = window;

  try {
    const res = await fetch(ClinikoStripeData.client_secret_url, {
      method: "POST",
      body: JSON.stringify({ moduleId: ClinikoStripeData.module_id }),
      headers: { "Content-Type": "application/json" },
    });

    const data = await res.json();
    const { name, duration, price, description } = data;

    document.getElementById("summary-name").textContent = name ?? "N/A";
    document.getElementById("summary-description").textContent =
      description ?? "N/A";
    document.getElementById("summary-duration").textContent = duration ?? "--";
    document.getElementById("summary-price").textContent =
      (price / 100).toFixed(2) ?? "--";

    const elements = stripe.elements();

    const style = {
      base: {
        fontSize: "16px",
        color: "#32325d",
        fontFamily: "Arial, sans-serif",
        "::placeholder": {
          color: "#aab7c4",
        },
      },
      invalid: {
        color: "#fa755a",
        iconColor: "#fa755a",
      },
    };

    const cardElement = elements.create("card", { style });
    cardElement.mount("#payment-element");

    document
      .getElementById("payment-button")
      .addEventListener("click", async () => {
        const { token, error } = await stripe.createToken(cardElement);

        if (error) {
          alert(error.message);
          return;
        }

        const formElement = document.getElementById("prepayment-form");
        const { content, patient } = parseFormToStructuredBody(formElement);

        const response = await fetch(ClinikoStripeData.booking_url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            content,
            patient,
            stripeToken: token.id,
            moduleId: ClinikoStripeData.module_id,
            patient_form_template_id:
              ClinikoStripeData.patient_form_template_id,
          }),
        });

        const result = await response.json();

        if (result.status === "success") {
          window.location.href = ClinikoStripeData.redirect_url;
        } else {
          alert("Error booking appointment.");
        }

        // if (paymentIntent && paymentIntent.status === "succeeded") {

        //   const formElement = document.getElementById("prepayment-form");
        //   const {content, patient} = parseFormToStructuredBody(formElement);

        //   const response = await fetch(ClinikoStripeData.booking_url, {
        //     method: "POST",
        //     headers: { "Content-Type": "application/json" },
        //     body: JSON.stringify({
        //       content,
        //       patient,
        //       moduleId: ClinikoStripeData.module_id,
        //       paymentIntentId: paymentIntent.id,
        //       patient_form_template_id: ClinikoStripeData.patient_form_template_id,
        //     }),
        //   });

        //   const result = await response.json();

        //   if (result.status === "success") {
        //     const query = new URLSearchParams({
        //       status: result.status,
        //       appointment: JSON.stringify(result.appointment),
        //       patient: JSON.stringify(result.patient),
        //     }).toString();

        //     window.location.href = ClinikoStripeData.redirect_url + "?" + query;
        //   } else {
        //     alert("Payment successful, but error scheduling appointment.");
        //   }
        // }
      });
  } catch (err) {
    console.error("Stripe init error:", err);
  }
}
