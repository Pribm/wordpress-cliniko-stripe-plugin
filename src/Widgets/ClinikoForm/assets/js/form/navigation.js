export function goToStep(i, steps, prevBtn, nextBtn, nextBtnLabel, progressType) {
  window.currentStep = i;
  showStep(i, steps, prevBtn, nextBtn, nextBtnLabel, progressType);
}
