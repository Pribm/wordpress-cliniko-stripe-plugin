(function () {
  const root = document.querySelector("[data-cliniko-component-editor]");
  if (!root) return;

  const preview = root.querySelector("[data-preview-canvas]");
  const previewLabel = root.querySelector("[data-component-preview-label]");
  const importStatus = root.querySelector("[data-import-status]");
  const tabLabels = {
    foundation: "Theme",
    review: "Data review",
    forms: "Form fields",
    calendar: "Calendar and times",
    steps: "Multi-step progress",
    lists: "Lists and timelines",
    feedback: "Messages",
  };

  const activate = (key, focusTab) => {
    root.querySelectorAll("[data-component-tab]").forEach((tab) => {
      const active = tab.dataset.componentTab === key;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
      tab.tabIndex = active ? 0 : -1;
      if (active && focusTab) tab.focus();
    });
    root.querySelectorAll("[data-component-panel]").forEach((panel) => {
      const active = panel.dataset.componentPanel === key;
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
    });
    root.querySelectorAll("[data-preview-scene]").forEach((scene) => {
      const active = scene.dataset.previewScene === key;
      scene.hidden = !active;
      scene.classList.toggle("is-active", active);
    });
    if (previewLabel) previewLabel.textContent = tabLabels[key] || "Preview";
    if (preview) preview.dataset.activeComponent = key;
  };

  const tabs = Array.from(root.querySelectorAll("[data-component-tab]"));
  tabs.forEach((tab, index) => {
    tab.addEventListener("click", () => activate(tab.dataset.componentTab));
    tab.addEventListener("keydown", (event) => {
      if (!['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].includes(event.key)) return;
      event.preventDefault();
      const direction = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : -1;
      const next = tabs[(index + direction + tabs.length) % tabs.length];
      activate(next.dataset.componentTab, true);
    });
  });

  const value = (name) => root.querySelector(`[name="${name}"]`)?.value || "";
  const checked = (name) => !!root.querySelector(`[name="${name}"]`)?.checked;
  const transparentName = (name) => name.replace(/\]$/, "_transparent]");
  const colorValue = (name) => checked(transparentName(name)) ? "transparent" : value(name);
  const setColorValue = (name, nextValue) => {
    const input = root.querySelector(`[name="${name}"]`);
    const transparent = root.querySelector(`[name="${transparentName(name)}"]`);
    if (!input) return;
    if (nextValue === "transparent") {
      if (transparent) transparent.checked = true;
      return;
    }
    input.value = nextValue;
    if (transparent) transparent.checked = false;
  };
  const syncFontFamilyControl = () => {
    const select = root.querySelector("[data-font-family-select]");
    const customWrap = root.querySelector("[data-font-family-custom-wrap]");
    const customInput = root.querySelector("[data-font-family-custom]");
    const isCustom = select?.value === "__custom__";
    if (customWrap) customWrap.hidden = !isCustom;
    if (customInput) customInput.disabled = !isCustom;
  };
  const variableValues = () => {
    const listRowBackground = colorValue("components[lists][row_background]");
    const listCardBackground = colorValue("components[lists][card_background]");
    const listHoverBackground = colorValue("components[lists][hover_background]");
    const listActionBackground = colorValue("components[lists][action_background]");
    const listActionText = colorValue("components[lists][action_text]");
    const listActionBorder = colorValue("components[lists][action_border_color]");
    const listShadows = {
      none: "none",
      subtle: "0 1px 3px rgb(15 23 42 / 10%)",
      raised: "0 10px 28px rgb(15 23 42 / 14%)",
    };
    const paginationStyle = value("components[lists][pagination_style]");
    const paginationText = paginationStyle === "text"
      ? (listActionBorder === "transparent" ? colorValue("components[lists][body_text]") : listActionBorder)
      : listActionText;
    const paginationAlignment = { start: "flex-start", center: "center", end: "flex-end" }[value("components[lists][pagination_alignment]")] || "center";
    return {
      "--cliniko-ui-primary": colorValue("components[foundation][primary]"),
      "--cliniko-ui-accent": colorValue("components[foundation][accent]"),
      "--cliniko-ui-text": colorValue("components[foundation][text]"),
      "--cliniko-ui-muted": colorValue("components[foundation][muted]"),
      "--cliniko-ui-surface": colorValue("components[foundation][surface]"),
      "--cliniko-ui-surface-alt": colorValue("components[foundation][surface_alt]"),
      "--cliniko-ui-border": colorValue("components[foundation][border]"),
      "--cliniko-ui-font-family": (value("components[foundation][font_family]") === "__custom__"
        ? value("components[foundation][font_family_custom]")
        : value("components[foundation][font_family]")) || "inherit",
      "--cliniko-ui-radius": `${value("components[foundation][radius]")}px`,
      "--cliniko-ui-control-height": `${value("components[foundation][control_height]")}px`,
      "--cliniko-ui-review-answer-spacing": `${value("components[review][answer_spacing]")}px`,
      "--cliniko-ui-review-detail-spacing": `${value("components[review][detail_spacing]")}px`,
      "--cliniko-ui-review-label-width": `${value("components[review][label_width]")}px`,
      "--cliniko-ui-review-label-weight": value("components[review][label_weight]"),
      "--cliniko-ui-field-gap": `${value("components[forms][field_gap]")}px`,
      "--cliniko-ui-calendar-day-size": `${value("components[calendar][day_size]")}px`,
      "--cliniko-ui-calendar-day-gap": `${value("components[calendar][day_gap]")}px`,
      "--cliniko-ui-calendar-panel-gap": `${value("components[calendar][panel_gap]")}px`,
      "--cliniko-ui-calendar-panel-radius": `${value("components[calendar][panel_radius]")}px`,
      "--cliniko-ui-calendar-available": colorValue("components[calendar][available]"),
      "--cliniko-ui-calendar-selected": colorValue("components[calendar][selected]"),
      "--cliniko-ui-calendar-morning": colorValue("components[calendar][morning]"),
      "--cliniko-ui-calendar-afternoon": colorValue("components[calendar][afternoon]"),
      "--cliniko-ui-calendar-evening": colorValue("components[calendar][evening]"),
      "--cliniko-ui-list-row-spacing": `${value("components[lists][row_spacing]")}px`,
      "--cliniko-ui-list-row-horizontal-spacing": `${value("components[lists][row_horizontal_spacing]")}px`,
      "--cliniko-ui-list-item-gap": `${value("components[lists][item_gap]")}px`,
      "--cliniko-ui-list-title-color": colorValue("components[lists][title_color]"),
      "--cliniko-ui-list-title-size": `${value("components[lists][title_size]")}px`,
      "--cliniko-ui-list-body-size": `${value("components[lists][body_size]")}px`,
      "--cliniko-ui-list-body-text": colorValue("components[lists][body_text]"),
      "--cliniko-ui-list-muted": colorValue("components[lists][muted_text]"),
      "--cliniko-ui-list-header-background": colorValue("components[lists][header_background]"),
      "--cliniko-ui-list-header-text": colorValue("components[lists][header_text]"),
      "--cliniko-ui-list-header-size": `${value("components[lists][header_size]")}px`,
      "--cliniko-ui-list-header-weight": value("components[lists][header_weight]"),
      "--cliniko-ui-list-row-background": listRowBackground,
      "--cliniko-ui-list-alternate-background": checked("components[lists][striped_rows]") ? colorValue("components[lists][alternate_background]") : listRowBackground,
      "--cliniko-ui-list-row-hover-background": checked("components[lists][hover_rows]") ? listHoverBackground : listRowBackground,
      "--cliniko-ui-list-divider": colorValue("components[lists][divider_color]"),
      "--cliniko-ui-list-divider-width": checked("components[lists][show_dividers]") ? `${value("components[lists][divider_width]")}px` : "0",
      "--cliniko-ui-list-divider-style": value("components[lists][divider_style]"),
      "--cliniko-ui-list-table-border": colorValue("components[lists][table_border_color]"),
      "--cliniko-ui-list-table-border-width": `${value("components[lists][table_border_width]")}px`,
      "--cliniko-ui-list-table-border-style": value("components[lists][table_border_style]"),
      "--cliniko-ui-list-table-radius": `${value("components[lists][table_radius]")}px`,
      "--cliniko-ui-list-table-shadow": listShadows[value("components[lists][table_elevation]")] || "none",
      "--cliniko-ui-list-column-divider": colorValue("components[lists][column_divider_color]"),
      "--cliniko-ui-list-column-divider-width": checked("components[lists][show_column_dividers]") ? `${value("components[lists][column_divider_width]")}px` : "0",
      "--cliniko-ui-list-column-divider-style": value("components[lists][column_divider_style]"),
      "--cliniko-ui-list-card-background": listCardBackground,
      "--cliniko-ui-list-card-hover-background": checked("components[lists][hover_rows]") ? listHoverBackground : listCardBackground,
      "--cliniko-ui-list-card-border": colorValue("components[lists][card_border]"),
      "--cliniko-ui-list-card-border-width": `${value("components[lists][card_border_width]")}px`,
      "--cliniko-ui-list-card-radius": `${value("components[lists][card_radius]")}px`,
      "--cliniko-ui-list-card-padding": `${value("components[lists][card_padding]")}px`,
      "--cliniko-ui-list-card-shadow": listShadows[value("components[lists][elevation]")] || "none",
      "--cliniko-ui-list-action-background": listActionBackground,
      "--cliniko-ui-list-action-text": listActionText,
      "--cliniko-ui-list-action-border": listActionBorder,
      "--cliniko-ui-list-action-border-width": `${value("components[lists][action_border_width]")}px`,
      "--cliniko-ui-list-action-border-style": value("components[lists][action_border_style]"),
      "--cliniko-ui-list-action-radius": `${value("components[lists][action_radius]")}px`,
      "--cliniko-ui-list-pagination-background": paginationStyle === "text" ? "transparent" : listActionBackground,
      "--cliniko-ui-list-pagination-text": paginationText,
      "--cliniko-ui-list-pagination-border": paginationStyle === "text" ? "transparent" : listActionBorder,
      "--cliniko-ui-list-pagination-border-width": paginationStyle === "text" ? "0" : `${value("components[lists][action_border_width]")}px`,
      "--cliniko-ui-list-pagination-border-style": value("components[lists][action_border_style]"),
      "--cliniko-ui-list-pagination-radius": paginationStyle === "pills" ? "999px" : (paginationStyle === "text" ? "0" : `${value("components[lists][action_radius]")}px`),
      "--cliniko-ui-list-pagination-alignment": paginationAlignment,
      "--cliniko-ui-list-pagination-gap": `${value("components[lists][pagination_gap]")}px`,
      "--cliniko-ui-list-pagination-page-display": checked("components[lists][pagination_show_page]") ? "inline" : "none",
      "--cliniko-ui-feedback-success": colorValue("components[feedback][success]"),
      "--cliniko-ui-feedback-error": colorValue("components[feedback][error]"),
      "--cliniko-ui-feedback-surface": colorValue("components[feedback][surface]"),
    };
  };

  const updatePreview = () => {
    syncFontFamilyControl();
    Object.entries(variableValues()).forEach(([key, current]) => {
      if (preview && current) preview.style.setProperty(key, current);
    });
    preview?.classList.toggle("has-edit-button", value("components[review][edit_style]") === "button");
    preview?.classList.toggle("has-review-dividers", checked("components[review][show_dividers]"));
    preview?.classList.toggle("has-inline-label", value("components[forms][label_position]") === "inline");
    preview?.classList.toggle("has-card-slots", value("components[calendar][slot_style]") === "card");
    preview?.classList.toggle("has-compact-steps", value("components[steps][indicator_style]") === "compact");
    root.querySelectorAll("[data-component-color-control]").forEach((control) => {
      const input = control.querySelector("[data-component-color]");
      const transparent = !!control.querySelector("[data-component-color-transparent]")?.checked;
      const code = control.querySelector("code");
      control.classList.toggle("is-transparent", transparent);
      if (input) input.disabled = transparent;
      if (code && input) code.textContent = transparent ? "transparent" : input.value;
    });
    root.querySelectorAll("[data-component-range]").forEach((input) => {
      const output = input.parentElement?.querySelector("output");
      if (output) output.textContent = `${input.value}px`;
    });
  };

  root.querySelectorAll("input,select").forEach((control) => {
    control.addEventListener("input", updatePreview);
    control.addEventListener("change", updatePreview);
  });

  root.querySelector("[data-copy-theme-to-lists]")?.addEventListener("click", (event) => {
    const mappings = {
      "components[foundation][primary]": ["components[lists][title_color]", "components[lists][action_background]", "components[lists][action_border_color]"],
      "components[foundation][text]": ["components[lists][header_text]", "components[lists][body_text]"],
      "components[foundation][muted]": ["components[lists][muted_text]"],
      "components[foundation][surface]": ["components[lists][row_background]", "components[lists][card_background]"],
      "components[foundation][surface_alt]": ["components[lists][header_background]", "components[lists][alternate_background]", "components[lists][hover_background]"],
      "components[foundation][border]": ["components[lists][divider_color]", "components[lists][table_border_color]", "components[lists][column_divider_color]", "components[lists][card_border]"],
    };
    Object.entries(mappings).forEach(([sourceName, targets]) => {
      const sourceValue = colorValue(sourceName);
      targets.forEach((targetName) => {
        if (!sourceValue) return;
        setColorValue(targetName, sourceValue);
      });
    });
    updatePreview();
    const button = event.currentTarget;
    const original = button.textContent;
    button.textContent = "Theme colours copied";
    window.setTimeout(() => { button.textContent = original; }, 1400);
  });

  root.querySelectorAll("[data-preview-width]").forEach((button) => button.addEventListener("click", () => {
    root.querySelectorAll("[data-preview-width]").forEach((item) => item.classList.toggle("is-active", item === button));
    preview?.classList.toggle("is-mobile", button.dataset.previewWidth === "mobile");
  }));

  root.querySelectorAll("[data-preview-scene] a[href='#']").forEach((link) => link.addEventListener("click", (event) => event.preventDefault()));

  const elementor = window.ClinikoComponentAdmin?.elementor || {};
  root.querySelector("[data-import-elementor]")?.addEventListener("click", () => {
    if (!elementor.available || !elementor.values) return;
    let imported = 0;
    Object.entries(elementor.values).forEach(([name, importedValue]) => {
      const control = root.querySelector(`[name="${name}"]`);
      if (!control || !importedValue) return;
      if (control.matches("[data-component-color]")) setColorValue(name, importedValue);
      else control.value = importedValue;
      control.dispatchEvent(new Event("input", { bubbles: true }));
      const field = control.closest("[data-component-control], label");
      field?.classList.add("is-imported");
      window.setTimeout(() => field?.classList.remove("is-imported"), 1600);
      imported += 1;
    });
    activate("foundation");
    updatePreview();
    if (importStatus) {
      importStatus.textContent = imported
        ? `Copied ${imported} theme values from Elementor${elementor.kit ? ` (${elementor.kit})` : ""}. Review them, then save.`
        : "No compatible Elementor theme values were found.";
      importStatus.classList.toggle("is-success", imported > 0);
    }
  });

  root.querySelector("[data-reset-components]")?.addEventListener("click", (event) => {
    if (!window.confirm("Reset every shared component to its default style and behaviour?")) event.preventDefault();
  });

  activate("foundation");
  updatePreview();
})();
