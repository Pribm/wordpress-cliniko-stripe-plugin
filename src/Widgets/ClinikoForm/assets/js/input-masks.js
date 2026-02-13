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

  // ➕ Medicare number (display as 1234 56789; 9 digits total)
  const medicareInput = document.querySelector('input[name="patient[medicare]"]');
  if (medicareInput) {
    IMask(medicareInput, {
      // 9 digits shown as 4 + 5 with spaces
      mask: '0000 00000'
    });
  }

  // ➕ Medicare reference (single digit)
  const medicareRefInput = document.querySelector('input[name="patient[medicare_reference_number]"]');
  if (medicareRefInput) {
    IMask(medicareRefInput, { mask: '0' });
  }

  // Optional: normalize before submit (strip spaces from medicare)
  // Adjust the selector to your actual form element if needed.
  const form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function () {
      if (medicareInput) {
        medicareInput.value = medicareInput.value.replace(/\s+/g, '');
        // If you prefer to KEEP spaces as stored value, comment out the line above.
      }
      // The reference is already one digit; no changes required.
    });
  }
});
