document.addEventListener("DOMContentLoaded", function () {
  if (typeof IMask === 'undefined') return;

  // Phone (AU)
  const phoneInput = document.querySelector('input[name="patient[phone]"]');
  if (phoneInput) {
    IMask(phoneInput, { mask: '0000 000 000' });
  }

  // Postcode
  const postcodeInput = document.querySelector('input[name="patient[post_code]"]');
  if (postcodeInput) {
    IMask(postcodeInput, { mask: '0000' });
  }

  // Medicare number (display as 1234 56789 0; 10 digits total)
  const medicareInput = document.querySelector('input[name="patient[medicare]"]');
  if (medicareInput) {
    IMask(medicareInput, {
      // 10 digits shown as 4 + 5 + 1 with spaces
      mask: '0000 00000 0'
    });
  }

  // ➕ Medicare reference (single digit)
  const medicareRefInput = document.querySelector('input[name="patient[medicare_reference_number]"]');
  if (medicareRefInput) {
    IMask(medicareRefInput, { mask: '0' });
  }

  // Keep the formatted value intact; the backend sanitizer will normalize it.
});
