(() => {
    'use strict';

    const root = document.querySelector('.cliniko-user-guide');
    const content = root?.querySelector('[data-cliniko-docs-content]');
    if (!root || !content) return;

    const headings = Array.from(content.querySelectorAll(':scope > h2[id]'));
    headings.forEach((heading, index) => {
        const section = document.createElement('section');
        section.className = 'cliniko-docs-section';
        section.dataset.clinikoDocsSection = heading.id;
        content.insertBefore(section, heading);

        let node = heading;
        const nextHeading = headings[index + 1] || null;
        while (node && node !== nextHeading) {
            const nextNode = node.nextSibling;
            section.appendChild(node);
            node = nextNode;
        }

        const anchor = document.createElement('a');
        anchor.className = 'cliniko-docs-heading-link';
        anchor.href = `#${heading.id}`;
        anchor.textContent = '#';
        anchor.setAttribute('aria-label', `Link to ${heading.textContent.trim()}`);
        heading.appendChild(anchor);
    });

    const sections = Array.from(content.querySelectorAll('[data-cliniko-docs-section]'));
    const navigationLinks = Array.from(root.querySelectorAll('.cliniko-docs-nav a[href^="#"]'));
    const emptyState = root.querySelector('[data-cliniko-docs-empty]');
    const search = root.querySelector('[data-cliniko-docs-search]');

    const setActive = sectionId => {
        navigationLinks.forEach(link => {
            const isActive = link.hash === `#${sectionId}`;
            link.classList.toggle('is-active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    navigationLinks.forEach(link => {
        link.addEventListener('click', () => setActive(link.hash.slice(1)));
    });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            const visible = entries
                .filter(entry => entry.isIntersecting && !entry.target.hidden)
                .sort((left, right) => left.boundingClientRect.top - right.boundingClientRect.top);
            if (visible[0]) setActive(visible[0].target.dataset.clinikoDocsSection);
        }, { rootMargin: '-70px 0px -65% 0px', threshold: [0, 0.1] });

        sections.forEach(section => observer.observe(section));
    }

    const updateSearch = () => {
        const term = search.value.trim().toLocaleLowerCase();
        let matches = 0;

        content.classList.toggle('is-searching', term !== '');
        sections.forEach(section => {
            const matchesTerm = term === '' || section.textContent.toLocaleLowerCase().includes(term);
            section.hidden = !matchesTerm;
            if (matchesTerm) matches += 1;
        });

        navigationLinks.forEach(link => {
            const section = root.querySelector(`[data-cliniko-docs-section="${link.hash.slice(1)}"]`);
            link.hidden = Boolean(section?.hidden);
        });

        root.querySelectorAll('[data-cliniko-docs-nav-group]').forEach(group => {
            group.hidden = !Array.from(group.querySelectorAll('a')).some(link => !link.hidden);
        });

        root.classList.toggle('has-no-results', matches === 0);
        if (emptyState) emptyState.hidden = matches !== 0;
        if (matches > 0) {
            const firstMatch = sections.find(section => !section.hidden);
            if (firstMatch) setActive(firstMatch.dataset.clinikoDocsSection);
        }
    };

    search?.addEventListener('input', updateSearch);
    setActive((window.location.hash || '#requirements').slice(1));
})();
