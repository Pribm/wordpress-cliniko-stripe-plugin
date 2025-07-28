document.addEventListener("DOMContentLoaded", function () {
  if (typeof IMask === 'undefined') return;

  const phoneInput = document.querySelector('input[name="patient[phone]"]');
  if (phoneInput) {
    IMask(phoneInput, {
      mask: '0000 000 000'
    });
  }

  const postcodeInput = document.querySelector('input[name="patient[post_code]"]');
  if (postcodeInput) {
    IMask(postcodeInput, { mask: '0000' });
  }
});
