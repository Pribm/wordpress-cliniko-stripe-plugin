'use strict';

global.window = {};
global.document = { querySelectorAll: function () { return []; } };
require('../src/Admin/assets/shortcode-form-input-rules.js');

function assertSame(expected, actual, message) {
    if (expected !== actual) throw new Error(message + ' Expected ' + expected + ', got ' + actual);
}

function maskedControl(initialValue, pattern, prefix) {
    var listeners = {};
    pattern = pattern || '4## ### ###';
    prefix = prefix || '+61';
    var attributes = {
        'data-cliniko-input-rule': 'pattern',
        'data-cliniko-mask': pattern,
        'data-cliniko-mask-prefix': prefix,
        'data-cliniko-mask-suffix': '',
        placeholder: '+61412 345 678'
    };
    return {
        dataset: {},
        value: initialValue || '',
        selectionStart: 0,
        selectionEnd: 0,
        validityMessage: '',
        getAttribute: function (name) { return attributes[name] || null; },
        hasAttribute: function (name) { return Object.prototype.hasOwnProperty.call(attributes, name); },
        addEventListener: function (name, listener) { listeners[name] = listener; },
        setCustomValidity: function (message) { this.validityMessage = message; },
        closest: function () { return null; },
        fire: function (name, event) { listeners[name](event || {}); }
    };
}

function initControl(control) {
    var root = {
        querySelectorAll: function (selector) {
            return selector.indexOf('data-cliniko-input-rule') !== -1 ? [control] : [];
        }
    };
    window.ClinikoShortcodeInputRules.init(root);
}

var literalFirst = maskedControl();
initControl(literalFirst);
literalFirst.value = '4';
literalFirst.fire('input');
assertSame('+614', literalFirst.value, 'Typing the fixed first digit must use that digit as the pattern literal.');
literalFirst.value += '2';
literalFirst.fire('input');
assertSame('+6142', literalFirst.value, 'The next typed digit must fill the first placeholder once.');

var differentFirst = maskedControl();
initControl(differentFirst);
differentFirst.value = '5';
differentFirst.fire('input');
assertSame('+6145', differentFirst.value, 'Any different first digit must be placed after the configured literal.');

var alternateLiteral = maskedControl('', '7##-##', '+1');
initControl(alternateLiteral);
alternateLiteral.value = '7';
alternateLiteral.fire('input');
assertSame('+17', alternateLiteral.value, 'Fixed-literal handling must work with any configured starting number.');

literalFirst.selectionStart = literalFirst.value.length;
literalFirst.selectionEnd = literalFirst.value.length;
var prevented = false;
literalFirst.fire('keydown', { key: 'Backspace', preventDefault: function () { prevented = true; } });
if (!prevented) {
    literalFirst.value = literalFirst.value.slice(0, -1);
    literalFirst.fire('input');
}
assertSame('+614', literalFirst.value, 'Backspace must remove one entered digit without duplicating fixed characters.');

console.log('Shared shortcode frontend input rule tests passed.');
