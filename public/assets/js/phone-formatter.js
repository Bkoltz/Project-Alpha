/**
 * Auto-format US phone numbers
 * 10 digits -> (555) 123-4567
 * 7 digits  -> 123-4567
 * Otherwise returns cleaned digits
 */
function formatPhone(value) {
  const digits = value.replace(/\D/g, '');
  if (digits.length === 10) {
    return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
  }
  if (digits.length === 7) {
    return digits.slice(0, 3) + '-' + digits.slice(3);
  }
  return value;
}

function cleanPhone(value) {
  return value.replace(/\D/g, '');
}

// Auto-format phone inputs on blur
document.addEventListener('DOMContentLoaded', function() {
  const phoneInputs = document.querySelectorAll('input[type="tel"], input[name="phone"], input[name="from_phone"]');
  phoneInputs.forEach(function(input) {
    input.addEventListener('blur', function() {
      const cleaned = cleanPhone(input.value);
      if (cleaned.length === 10 || cleaned.length === 7) {
        input.value = formatPhone(input.value);
      }
    });
    // Allow only digits, spaces, parens, dashes while typing
    input.addEventListener('input', function() {
      input.value = input.value.replace(/[^0-9\s\-\(\)]/g, '');
    });
  });
});
