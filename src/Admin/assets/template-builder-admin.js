(function () {
    "use strict";

    var journeyId = 0;

    function moveAdminNotices() {
        var content = document.getElementById("wpbody-content");
        var navigation = content && content.querySelector(".cliniko-template-builder-nav");
        if (!content || !navigation) {
            return;
        }

        content.querySelectorAll('.um-admin-notice[data-key="um_is_not_child_theme"]').forEach(function (notice) {
            notice.remove();
        });

        content.querySelectorAll(".cliniko-template-builder-page .um-admin-notice").forEach(function (notice) {
            notice.classList.add("cliniko-template-builder-notice");
            content.insertBefore(notice, navigation);
        });
    }

    function directChild(element, selector) {
        return Array.prototype.find.call(element.children, function (child) {
            return child.matches(selector);
        }) || null;
    }

    function builderPages() {
        var pages = [];

        document.querySelectorAll("body.cliniko-template-builder-screen .wrap, .wrap.cliniko-template-builder-page").forEach(function (page) {
            if (pages.indexOf(page) !== -1) {
                return;
            }

            if (
                page.classList.contains("cliniko-template-builder-nav") ||
                page.classList.contains("cliniko-template-builder-subnav") ||
                page.closest(".cliniko-form-builder__preview-modal") ||
                page.querySelector("form[data-cliniko-onboarding-builder]") ||
                page.querySelector("[data-cliniko-account-editor]")
            ) {
                return;
            }

            var title = directChild(page, "h1") || page.querySelector(".cliniko-attachment-builder__heading h1");
            var content = page.querySelector("form, table.widefat, .postbox, .cliniko-attachment-builder__layout, #cliniko-generic-booking-builder, #cliniko-booking-aliases");

            if (title && content) {
                pages.push(page);
            }
        });

        return pages;
    }

    function activeBuilderName() {
        var active = document.querySelector(".cliniko-template-builder-subnav a.current, .cliniko-template-builder-nav__tabs a.is-active");
        var primaryLabel = active && active.querySelector("span");
        var label = primaryLabel && primaryLabel.textContent
            ? primaryLabel.textContent.trim()
            : (active && active.textContent ? active.textContent.trim() : "Shortcode");

        return label.replace(/\s+/g, " ") + " builder";
    }

    function pageDescription(page, isEditor) {
        var heading = page.querySelector(".cliniko-attachment-builder__heading");
        var description = directChild(page, "p") || (heading && heading.querySelector("p"));

        if (description && !description.closest(".notice")) {
            description.classList.add("cliniko-unified-admin__description");
            return description;
        }

        description = document.createElement("p");
        description.className = "cliniko-unified-admin__description";
        description.textContent = isEditor
            ? "Configure the shortcode experience, review its content, then publish it when it is ready."
            : "Create and manage reusable shortcode configurations for your patient experience.";

        return description;
    }

    function editorState(page, form) {
        if (page.querySelector("#cliniko-generic-booking-builder")) {
            return "Build shortcode";
        }
        if (page.querySelector("#cliniko-booking-aliases")) {
            return "Manage aliases";
        }
        if (page.classList.contains("cliniko-shortcode-library")) {
            return "Reference library";
        }
        if (!form) {
            return "Manage configurations";
        }

        if (form.classList.contains("es-email-builder__editor")) {
            return "Editing template";
        }

        var id = form.querySelector('input[type="hidden"][name="id"], input[type="hidden"][name$="_id"]');
        if (id && id.value && id.value !== "0") {
            return "Editing configuration";
        }

        return "New configuration";
    }

    function createHero(page, title, form) {
        var hero = document.createElement("header");
        var copy = document.createElement("div");
        var eyebrow = document.createElement("span");
        var heading = document.createElement("h1");
        var actions = document.createElement("div");
        var state = document.createElement("span");
        var sourceHeading = title.closest(".cliniko-attachment-builder__heading");
        var stateLabel = editorState(page, form);

        hero.className = "cliniko-unified-admin__hero";
        copy.className = "cliniko-unified-admin__hero-copy";
        eyebrow.className = "cliniko-unified-admin__eyebrow";
        eyebrow.textContent = activeBuilderName();
        heading.textContent = title.textContent.trim();

        copy.appendChild(eyebrow);
        copy.appendChild(heading);
        copy.appendChild(pageDescription(page, Boolean(form)));

        actions.className = "cliniko-unified-admin__hero-actions";
        state.className = "cliniko-unified-admin__status";
        state.textContent = stateLabel;
        if (form && !page.classList.contains("cliniko-shortcode-library")) {
            state.classList.add("is-ready");
        }
        actions.appendChild(state);

        page.querySelectorAll(".page-title-action, .cliniko-attachment-builder__heading > .button").forEach(function (action) {
            actions.appendChild(action);
        });

        hero.appendChild(copy);
        hero.appendChild(actions);
        page.insertBefore(hero, page.firstChild);

        title.classList.add("cliniko-unified-admin__source-title");
        if (sourceHeading) {
            sourceHeading.classList.add("cliniko-unified-admin__source-heading");
        }

        return hero;
    }

    function targetLabel(target, fallback) {
        var heading = target.querySelector("strong, h2, h3");
        return heading && heading.textContent.trim() ? heading.textContent.trim() : fallback;
    }

    function journeyTargets(page, form) {
        var targets = [];
        var sections = form ? form.querySelectorAll(".cliniko-booking-builder__section") : [];

        if (sections.length > 1) {
            sections.forEach(function (section, index) {
                targets.push({
                    label: targetLabel(section, "Section " + (index + 1)),
                    target: section
                });
            });
            return targets;
        }

        var attachmentCards = page.querySelectorAll(".cliniko-attachment-builder__card");
        if (attachmentCards.length > 1) {
            attachmentCards.forEach(function (card, index) {
                targets.push({
                    label: targetLabel(card, "Section " + (index + 1)),
                    target: card
                });
            });

            var attachmentPublish = page.querySelector(".cliniko-attachment-builder__publish");
            if (attachmentPublish) {
                targets.push({ label: "Publish", target: attachmentPublish });
            }
            return targets;
        }

        var layout = form && form.querySelector(".cliniko-form-builder__layout");
        if (layout) {
            var name = form.querySelector(".cliniko-form-builder__name");
            var panel = layout.querySelector("main .cliniko-form-builder__panel, .cliniko-form-builder__panel");
            var sidebar = layout.querySelector(".cliniko-form-builder__sidebar");

            if (name) {
                targets.push({ label: "Setup", target: name });
            }
            if (panel) {
                targets.push({ label: "Build", target: panel });
            }
            if (sidebar) {
                targets.push({ label: "Publish", target: sidebar });
            }
            return targets;
        }

        if (form && form.matches("[data-custom-code-editor]")) {
            var setup = form.querySelector(".form-table");
            var snippets = form.querySelector("[data-custom-code-snippets]");
            var save = directChild(form, "p:last-child");

            if (setup) {
                targets.push({ label: "Setup", target: setup });
            }
            if (snippets) {
                targets.push({ label: "Code snippets", target: snippets });
            }
            if (save) {
                targets.push({ label: "Publish", target: save });
            }
            return targets;
        }

        if (form && form.classList.contains("es-email-builder__editor")) {
            form.querySelectorAll(":scope > .es-email-panel").forEach(function (panel, index) {
                targets.push({
                    label: targetLabel(panel, "Section " + (index + 1)),
                    target: panel
                });
            });

            var preview = page.querySelector(".es-email-builder__preview-column");
            if (preview) {
                targets.push({ label: "Live preview", target: preview });
            }
            return targets;
        }

        var postboxes = form ? form.querySelectorAll(":scope > .postbox") : [];
        if (postboxes.length > 1) {
            postboxes.forEach(function (postbox, index) {
                targets.push({
                    label: targetLabel(postbox, "Section " + (index + 1)),
                    target: postbox
                });
            });
        }

        return targets;
    }

    function activateJourneyButton(navigation, activeButton) {
        navigation.querySelectorAll("button").forEach(function (button) {
            var active = button === activeButton;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-current", active ? "step" : "false");
        });
    }

    function createJourney(page, hero, form) {
        var targets = journeyTargets(page, form);
        if (targets.length < 2) {
            return;
        }

        var navigation = document.createElement("nav");
        navigation.className = "cliniko-unified-admin__journey";
        navigation.setAttribute("aria-label", "Builder sections");

        targets.forEach(function (item, index) {
            var button = document.createElement("button");
            var number = document.createElement("span");

            if (!item.target.id) {
                journeyId += 1;
                item.target.id = "cliniko-builder-section-" + journeyId;
            }

            button.type = "button";
            button.setAttribute("aria-controls", item.target.id);
            number.textContent = String(index + 1);
            button.appendChild(number);
            button.appendChild(document.createTextNode(item.label));

            if (index === 0) {
                button.classList.add("is-active");
                button.setAttribute("aria-current", "step");
            }

            button.addEventListener("click", function () {
                activateJourneyButton(navigation, button);
                item.target.scrollIntoView({ behavior: "smooth", block: "start" });
            });
            navigation.appendChild(button);
        });

        hero.insertAdjacentElement("afterend", navigation);
    }

    function enhanceBuilderPages() {
        builderPages().forEach(function (page) {
            if (page.classList.contains("cliniko-unified-admin")) {
                return;
            }

            var title = directChild(page, "h1") || page.querySelector(".cliniko-attachment-builder__heading h1");
            var form = directChild(page, "form") ||
                page.querySelector(".cliniko-attachment-builder__layout")?.closest("form") ||
                page.querySelector(".es-email-builder__editor");

            page.classList.add("cliniko-unified-admin");
            var hero = createHero(page, title, form);
            createJourney(page, hero, form);
        });
    }

    function initialiseAdminExperience() {
        moveAdminNotices();
        enhanceBuilderPages();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialiseAdminExperience);
    } else {
        initialiseAdminExperience();
    }

    var observer = new MutationObserver(function () {
        moveAdminNotices();
        enhanceBuilderPages();
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true
    });
}());
