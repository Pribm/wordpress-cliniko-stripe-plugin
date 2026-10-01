(function () {
    'use strict';

    var settings = window.ClinikoConnectionNoticeSettings || {};
    if (settings.enabled !== true) return;

    var notices = {};
    var rootClass = 'cliniko-connection-notice';

    function remove(type) {
        if (notices[type] && notices[type].parentNode) notices[type].parentNode.removeChild(notices[type]);
        delete notices[type];
    }

    function show(type, message) {
        if (notices[type]) return notices[type];
        Object.keys(notices).forEach(function (existingType) {
            if (existingType !== type) remove(existingType);
        });
        var node = document.createElement('div');
        node.className = rootClass + ' cliniko-connection-notice--' + type;
        node.setAttribute('role', 'alert');
        node.setAttribute('aria-live', 'assertive');
        var text = document.createElement('span');
        text.textContent = message;
        node.appendChild(text);
        var close = document.createElement('button');
        close.type = 'button';
        close.className = rootClass + '__dismiss';
        close.setAttribute('aria-label', settings.dismissLabel || 'Dismiss notification');
        close.textContent = 'x';
        close.addEventListener('click', function () { remove(type); });
        node.appendChild(close);
        (document.body || document.documentElement).appendChild(node);
        notices[type] = node;
        return node;
    }

    function reportOffline() { show('offline', settings.offlineMessage || 'You appear to be offline.'); }
    function reportUnavailable() { show('unavailable', settings.unavailableMessage || 'Cliniko is temporarily unavailable.'); }
    function clearOffline() { remove('offline'); }
    function inspectResponse(response, payload) {
        var code = payload && (payload.code || payload.data?.code || payload.data?.data?.code);
        if (code === 'cliniko_connection_unavailable' || (response && response.status === 503)) reportUnavailable();
        return response;
    }

    window.ClinikoConnectionNotice = {
        reportOffline: reportOffline,
        reportUnavailable: reportUnavailable,
        clearOffline: clearOffline,
        inspectResponse: inspectResponse
    };

    window.addEventListener('offline', reportOffline);
    window.addEventListener('online', clearOffline);

    if (navigator.onLine === false) reportOffline();
    else if (settings.serverUnavailable === true) reportUnavailable();
}());
