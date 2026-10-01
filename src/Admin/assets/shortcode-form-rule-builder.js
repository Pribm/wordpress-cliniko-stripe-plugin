(function () {
    'use strict';

    function isToken(character) {
        return character === '#' || character === 'A' || character === '*';
    }

    function preview(pattern, prefix, suffix) {
        var digit = 1;
        var letter = 0;
        var letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        return prefix + Array.from(pattern).map(function (character) {
            if (character === '#') {
                var number = String(digit);
                digit = digit === 9 ? 1 : digit + 1;
                return number;
            }
            if (character === 'A') return letters.charAt((letter++) % letters.length);
            if (character === '*') {
                var value = letter % 2 === 0 ? letters.charAt(letter % letters.length) : String(digit);
                letter += 1;
                digit = digit === 9 ? 1 : digit + 1;
                return value;
            }
            return character;
        }).join('') + suffix;
    }

    function sync(builder) {
        if (!builder) return;
        var inputType = builder.querySelector('[data-rule-input-type]');
        var format = builder.querySelector('[data-rule-format]');
        var dateSettings = builder.querySelector('[data-rule-date-settings]');
        var selectSettings = builder.querySelector('[data-rule-select-settings]');
        var maskSettings = builder.querySelector('[data-rule-mask-settings]');
        var validationSettings = builder.querySelector('.cliniko-shortcode-input-rules__validation');
        var maxLength = builder.querySelector('[data-rule-max-length]');
        var typeValue = inputType ? inputType.value : 'default';
        var formatValue = format ? format.value : 'none';
        var usesTextRules = typeValue !== 'date' && typeValue !== 'select';

        if (dateSettings) dateSettings.hidden = typeValue !== 'date';
        if (selectSettings) selectSettings.hidden = typeValue !== 'select';
        if (validationSettings) validationSettings.hidden = !usesTextRules;
        if (maskSettings) maskSettings.hidden = !usesTextRules || formatValue !== 'pattern';
        if (format) format.disabled = !usesTextRules;
        if (maxLength) maxLength.disabled = !usesTextRules || formatValue === 'pattern';

        var pattern = builder.querySelector('[data-rule-mask-pattern]');
        var prefix = builder.querySelector('[data-rule-mask-prefix]');
        var suffix = builder.querySelector('[data-rule-mask-suffix]');
        var output = builder.querySelector('[data-rule-mask-preview]');
        if (output) {
            var patternValue = pattern ? pattern.value : '';
            output.textContent = Array.from(patternValue).some(isToken)
                ? preview(patternValue, prefix ? prefix.value : '', suffix ? suffix.value : '')
                : 'Add #, A, or * to the pattern';
        }
        var summary = builder.querySelector('[data-rule-summary]');
        if (summary) {
            var labels = { default: 'Default input', text: 'Text input', date: 'Formatted date', select: 'Custom dropdown' };
            summary.textContent = labels[typeValue] || labels.default;
            if (formatValue === 'numbers') summary.textContent += ' · numbers only';
            if (formatValue === 'pattern') summary.textContent += ' · masked';
        }
    }

    document.querySelectorAll('[data-cliniko-rule-builder]').forEach(sync);
    document.addEventListener('input', function (event) {
        sync(event.target.closest('[data-cliniko-rule-builder]'));
    });
    document.addEventListener('change', function (event) {
        sync(event.target.closest('[data-cliniko-rule-builder]'));
    });
}());
