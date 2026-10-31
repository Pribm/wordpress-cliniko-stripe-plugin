// Keep the selected workspace visible in the horizontal mobile navigation.
(() => {
    'use strict';
    const navigation = document.querySelector('.cliniko-plugin-admin .cliniko-admin-nav');
    const active = navigation?.querySelector('[aria-current="page"]');
    if (!navigation || !active) return;
    const narrow = window.matchMedia('(max-width: 782px)');
    const revealActive = () => {
        if (!narrow.matches) return;
        const item = active.getBoundingClientRect();
        const container = navigation.getBoundingClientRect();
        navigation.scrollLeft += item.left - container.left - (navigation.clientWidth - item.width) / 2;
    };
    revealActive();
    narrow.addEventListener('change', revealActive);
})();
