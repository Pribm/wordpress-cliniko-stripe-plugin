export function updateIndicators(index, type, steps) {
  if (type === "steps") {
    document.querySelectorAll(".progress-step").forEach((el, i) =>
      el.classList.toggle("is-active", i === index)
    );
  } else if (type === "dots") {
    document.querySelectorAll(".progress-dot").forEach((el, i) =>
      el.classList.toggle("is-active", i === index)
    );
  } else if (type === "bar") {
    const fill = document.querySelector(".progress-fill");
    if (fill) fill.style.width = ((index + 1) / steps.length) * 100 + "%";
  } else if (type === "fraction") {
    const text = document.querySelector(".form-progress--fraction .progress-text");
    if (text) text.textContent = `${index + 1}/${steps.length}`;
  } else if (type === "percentage") {
    const text = document.querySelector(".form-progress--percentage .progress-text");
    if (text) text.textContent = `${Math.round(((index + 1) / steps.length) * 100)}%`;
  }
}

export function showStep(i, steps, prevBtn, nextBtn, nextBtnLabel, type) {
  window.scrollTo({ top: 0, behavior: "smooth" });
  steps.forEach((step, idx) => step.style.display = idx === i ? "block" : "none");
  prevBtn.style.display = i === 0 ? "none" : "flex";
  nextBtn.textContent = i === steps.length - 1 ? "Submit" : nextBtnLabel;
  updateIndicators(i, type, steps);
}
