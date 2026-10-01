<?php

namespace App\Admin\Modules\Components;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

if (!defined('ABSPATH')) {
    exit;
}

final class ComponentStyles
{
    private const OPTION_KEY = 'wp_cliniko_component_styles';
    private static bool $frontendEnqueued = false;

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_component_styles_save', [self::class, 'save']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontend']);
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        $saved = is_array($saved) ? $saved : [];
        $settings = array_replace_recursive(self::defaults(), $saved);
        $savedLists = is_array($saved['lists'] ?? null) ? $saved['lists'] : [];
        foreach ([
            'title_color' => 'primary',
            'header_text' => 'text',
            'body_text' => 'text',
            'muted_text' => 'muted',
            'header_background' => 'surface_alt',
            'row_background' => 'surface',
            'alternate_background' => 'surface_alt',
            'hover_background' => 'surface_alt',
            'divider_color' => 'border',
            'table_border_color' => 'border',
            'column_divider_color' => 'border',
            'card_background' => 'surface',
            'card_border' => 'border',
            'action_background' => 'primary',
            'action_border_color' => 'primary',
        ] as $listKey => $foundationKey) {
            if (!array_key_exists($listKey, $savedLists)) {
                $settings['lists'][$listKey] = $settings['foundation'][$foundationKey];
            }
        }
        foreach (['table_radius', 'card_radius', 'action_radius'] as $listKey) {
            if (!array_key_exists($listKey, $savedLists)) {
                $settings['lists'][$listKey] = $settings['foundation']['radius'];
            }
        }
        $settings['foundation']['installed_fonts'] = self::sanitizeInstalledFonts($settings['foundation']['installed_fonts'] ?? []);
        return $settings;
    }

    /** @return array<string,mixed> */
    public static function frontendConfig(): array
    {
        $settings = self::settings();
        return [
            'calendar' => [
                'prefetch' => ($settings['calendar']['prefetch'] ?? 'yes') === 'yes',
                'scroll_to_times' => ($settings['calendar']['scroll_to_times'] ?? 'no') === 'yes',
            ],
            'review' => [
                'edit_mode' => (string) ($settings['review']['edit_mode'] ?? 'multiple'),
            ],
            'steps' => [
                'scroll_to_top' => ($settings['steps']['scroll_to_top'] ?? 'no') === 'yes',
            ],
        ];
    }

    public static function enqueueFrontend(): void
    {
        if (self::$frontendEnqueued) {
            return;
        }
        self::$frontendEnqueued = true;

        $css = dirname(__DIR__, 2) . '/assets/shortcode-components.css';
        $js = dirname(__DIR__, 2) . '/assets/shortcode-components.js';
        wp_enqueue_style('cliniko-shortcode-components', plugins_url('../../assets/shortcode-components.css', __FILE__), [], is_file($css) ? (string) filemtime($css) : null);
        wp_enqueue_script('cliniko-shortcode-components', plugins_url('../../assets/shortcode-components.js', __FILE__), [], is_file($js) ? (string) filemtime($js) : null, true);
        wp_localize_script('cliniko-shortcode-components', 'ClinikoComponentSettings', self::frontendConfig());
        wp_add_inline_style('cliniko-shortcode-components', self::cssVariables(self::settings()));
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $settings = self::settings();
        $elementor = self::elementorTheme();
        $tabs = [
            'foundation' => ['Theme', 'Colours, type and shape'],
            'review' => ['Data review', 'Renewals and details'],
            'forms' => ['Form fields', 'Inputs and spacing'],
            'calendar' => ['Calendar', 'Dates and time slots'],
            'steps' => ['Steps', 'Multi-step navigation'],
            'lists' => ['Lists', 'Tables and timelines'],
            'feedback' => ['Messages', 'Success and errors'],
        ];
        ?>
        <div class="wrap cliniko-unified-admin cliniko-component-editor" data-cliniko-component-editor>
            <header class="cliniko-component-editor__hero">
                <div>
                    <span class="cliniko-component-editor__eyebrow">Patient-facing component library</span>
                    <h1>Component Styles</h1>
                    <p>Edit each shared UI component independently. Changes are previewed here and only become public after you save.</p>
                </div>
                <div class="cliniko-component-editor__hero-actions">
                    <span class="cliniko-component-editor__status">Global settings</span>
                    <button type="button" class="button" data-import-elementor <?php disabled(empty($elementor['available'])); ?> title="<?php echo esc_attr((string) $elementor['message']); ?>">Copy theme from Elementor</button>
                </div>
            </header>
            <p class="cliniko-component-editor__import-status" data-import-status aria-live="polite"><?php echo esc_html((string) $elementor['message']); ?></p>
            <?php if (isset($_GET['components_saved'])) : ?><div class="notice notice-success is-dismissible"><p>Component styles saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['font_installed']) && sanitize_key(wp_unslash((string) $_GET['font_installed'])) === '1') : ?><div class="notice notice-success is-dismissible"><p>The font was installed and selected for the component library.</p></div><?php endif; ?>
            <?php if (isset($_GET['components_error']) && sanitize_key(wp_unslash((string) $_GET['components_error'])) === 'font_upload') : ?><div class="notice notice-error is-dismissible"><p>The component styles were saved, but the font could not be installed. Check the file type, upload limit, and your WordPress upload permission.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="cliniko-component-editor__form">
                <input type="hidden" name="action" value="wp_cliniko_component_styles_save">
                <?php wp_nonce_field('save_cliniko_component_styles'); ?>
                <div class="cliniko-component-editor__layout">
                    <nav class="cliniko-component-editor__library" role="tablist" aria-label="Shared components">
                        <header><strong>Components</strong><small>Select one to edit</small></header>
                        <?php foreach ($tabs as $key => [$label, $description]) : ?>
                            <button type="button" role="tab" aria-selected="<?php echo $key === 'foundation' ? 'true' : 'false'; ?>" class="<?php echo $key === 'foundation' ? 'is-active' : ''; ?>" data-component-tab="<?php echo esc_attr($key); ?>">
                                <span class="cliniko-component-editor__library-icon" aria-hidden="true"><?php echo esc_html(strtoupper(substr($label, 0, 1))); ?></span>
                                <span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                    <main class="cliniko-component-editor__workspace">
                        <?php self::renderFoundationPanel($settings); ?>
                        <?php self::renderReviewPanel($settings); ?>
                        <?php self::renderFormsPanel($settings); ?>
                        <?php self::renderCalendarPanel($settings); ?>
                        <?php self::renderStepsPanel($settings); ?>
                        <?php self::renderListsPanel($settings); ?>
                        <?php self::renderFeedbackPanel($settings); ?>
                    </main>
                    <aside class="cliniko-component-editor__sidebar">
                        <section class="cliniko-component-editor__preview-card">
                            <header><div><strong>Live preview</strong><span data-component-preview-label>Theme</span></div><div><button type="button" class="button is-active" data-preview-width="desktop">Desktop</button><button type="button" class="button" data-preview-width="mobile">Mobile</button></div></header>
                            <div class="cliniko-component-editor__preview" data-component-preview><?php self::renderPreview(); ?></div>
                        </section>
                        <section class="cliniko-component-editor__publish"><h2>Apply globally</h2><p>Existing shortcode classes and custom CSS remain available. These shared values provide their common visual foundation.</p><button type="submit" class="button button-primary button-large">Save component styles</button><button type="submit" name="reset_components" value="1" class="button" data-reset-components>Reset defaults</button></section>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_cliniko_component_styles');
        if (!empty($_POST['reset_components'])) {
            delete_option(self::OPTION_KEY);
        } else {
            $raw = is_array($_POST['components'] ?? null) ? wp_unslash($_POST['components']) : [];
            $sanitized = self::sanitize($raw);
            $fontFile = self::fontUploadFromRequest();
            $fontResult = $fontFile === [] ? [] : self::installFont($fontFile, (string) ($raw['foundation']['font_name'] ?? ''));
            $redirectArgs = ['components_saved' => 1];
            if (isset($fontResult['font'])) {
                $installedFonts = self::sanitizeInstalledFonts($sanitized['foundation']['installed_fonts'] ?? []);
                $newFamily = (string) $fontResult['font']['family'];
                $installedFonts = array_values(array_filter($installedFonts, static fn(array $font): bool => strtolower((string) ($font['family'] ?? '')) !== strtolower($newFamily)));
                $installedFonts[] = $fontResult['font'];
                $sanitized['foundation']['installed_fonts'] = $installedFonts;
                $sanitized['foundation']['font_family'] = $newFamily;
                $redirectArgs['font_installed'] = 1;
            } elseif (isset($fontResult['error'])) {
                $redirectArgs['components_error'] = 'font_upload';
            }
            update_option(self::OPTION_KEY, $sanitized, false);
            $redirect = AccountBuilders::url(AccountBuilders::TAB_COMPONENTS, $redirectArgs);
            wp_safe_redirect($redirect);
            exit;
        }
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_COMPONENTS, ['components_saved' => 1]));
        exit;
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'foundation' => ['primary' => '#2563eb', 'accent' => '#0ea5e9', 'text' => '#111827', 'muted' => '#64748b', 'surface' => '#ffffff', 'surface_alt' => '#f8fafc', 'border' => '#e2e8f0', 'radius' => 12, 'control_height' => 46, 'font_family' => 'inherit', 'installed_fonts' => []],
            'review' => ['answer_spacing' => 0, 'detail_spacing' => 8, 'label_weight' => 650, 'label_width' => 120, 'edit_style' => 'link', 'edit_mode' => 'multiple', 'show_dividers' => 'no'],
            'forms' => ['field_gap' => 20, 'label_position' => 'top'],
            'calendar' => ['day_size' => 52, 'day_gap' => 6, 'panel_gap' => 16, 'panel_radius' => 16, 'available' => '#2563eb', 'selected' => '#1d4ed8', 'morning' => '#0ea5e9', 'afternoon' => '#f59e0b', 'evening' => '#ef4444', 'slot_style' => 'pill', 'prefetch' => 'yes', 'scroll_to_times' => 'no'],
            'steps' => ['indicator_style' => 'numbered', 'scroll_to_top' => 'no'],
            'lists' => [
                'row_spacing' => 12,
                'row_horizontal_spacing' => 12,
                'item_gap' => 12,
                'title_color' => '#2563eb',
                'title_size' => 24,
                'header_background' => '#f8fafc',
                'header_text' => '#111827',
                'header_size' => 14,
                'header_weight' => 700,
                'body_size' => 16,
                'body_text' => '#111827',
                'muted_text' => '#64748b',
                'row_background' => '#ffffff',
                'alternate_background' => '#f8fafc',
                'striped_rows' => 'no',
                'hover_background' => '#f8fafc',
                'hover_rows' => 'yes',
                'show_dividers' => 'yes',
                'divider_color' => '#e2e8f0',
                'divider_width' => 1,
                'divider_style' => 'solid',
                'table_border_color' => '#e2e8f0',
                'table_border_width' => 1,
                'table_border_style' => 'solid',
                'table_radius' => 12,
                'table_elevation' => 'none',
                'show_column_dividers' => 'no',
                'column_divider_color' => '#e2e8f0',
                'column_divider_width' => 1,
                'column_divider_style' => 'solid',
                'card_background' => '#ffffff',
                'card_border' => '#e2e8f0',
                'card_border_width' => 1,
                'card_radius' => 12,
                'card_padding' => 16,
                'elevation' => 'none',
                'action_background' => '#2563eb',
                'action_text' => '#ffffff',
                'action_border_color' => '#2563eb',
                'action_border_width' => 1,
                'action_border_style' => 'solid',
                'action_radius' => 8,
                'pagination_style' => 'buttons',
                'pagination_alignment' => 'center',
                'pagination_gap' => 8,
                'pagination_show_page' => 'yes',
            ],
            'feedback' => ['success' => '#166534', 'error' => '#b91c1c', 'surface' => '#f0fdf4'],
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private static function sanitize(array $raw): array
    {
        $defaults = self::defaults();
        $result = $defaults;
        foreach (['primary', 'accent', 'text', 'muted', 'surface', 'surface_alt', 'border'] as $key) {
            $result['foundation'][$key] = self::controlledColor($raw, 'foundation', $key, (string) $defaults['foundation'][$key]);
        }
        $fontSelection = sanitize_text_field((string) ($raw['foundation']['font_family'] ?? 'inherit'));
        $customFont = sanitize_text_field((string) ($raw['foundation']['font_family_custom'] ?? ''));
        $fontOptions = self::fontOptions(self::settings());
        if ($fontSelection === '__custom__') {
            $result['foundation']['font_family'] = $customFont !== '' ? $customFont : 'inherit';
        } elseif (isset($fontOptions[$fontSelection])) {
            $result['foundation']['font_family'] = $fontSelection;
        } else {
            $result['foundation']['font_family'] = 'inherit';
        }
        $result['foundation']['installed_fonts'] = self::sanitizeInstalledFonts(self::settings()['foundation']['installed_fonts'] ?? []);
        $result['foundation']['radius'] = self::integer($raw, 'foundation', 'radius', 0, 32, 12);
        $result['foundation']['control_height'] = self::integer($raw, 'foundation', 'control_height', 34, 72, 46);

        $result['review']['answer_spacing'] = self::integer($raw, 'review', 'answer_spacing', 0, 20, 0);
        $result['review']['detail_spacing'] = self::integer($raw, 'review', 'detail_spacing', 0, 24, 8);
        $result['review']['label_width'] = self::integer($raw, 'review', 'label_width', 80, 240, 120);
        $weight = (int) ($raw['review']['label_weight'] ?? 650);
        $result['review']['label_weight'] = in_array($weight, [400, 500, 600, 650, 700], true) ? $weight : 650;
        $result['review']['edit_style'] = in_array(($raw['review']['edit_style'] ?? ''), ['link', 'button'], true) ? (string) $raw['review']['edit_style'] : 'link';
        $result['review']['edit_mode'] = ($raw['review']['edit_mode'] ?? '') === 'single' ? 'single' : 'multiple';
        $result['review']['show_dividers'] = !empty($raw['review']['show_dividers']) ? 'yes' : 'no';

        $result['forms']['field_gap'] = self::integer($raw, 'forms', 'field_gap', 4, 48, 20);
        $result['forms']['label_position'] = ($raw['forms']['label_position'] ?? '') === 'inline' ? 'inline' : 'top';

        foreach (['available', 'selected', 'morning', 'afternoon', 'evening'] as $key) {
            $result['calendar'][$key] = self::controlledColor($raw, 'calendar', $key, (string) $defaults['calendar'][$key]);
        }
        $result['calendar']['day_size'] = self::integer($raw, 'calendar', 'day_size', 36, 84, 52);
        $result['calendar']['day_gap'] = self::integer($raw, 'calendar', 'day_gap', 2, 14, 6);
        $result['calendar']['panel_gap'] = self::integer($raw, 'calendar', 'panel_gap', 8, 32, 16);
        $result['calendar']['panel_radius'] = self::integer($raw, 'calendar', 'panel_radius', 0, 32, 16);
        $result['calendar']['slot_style'] = ($raw['calendar']['slot_style'] ?? '') === 'card' ? 'card' : 'pill';
        $result['calendar']['prefetch'] = !empty($raw['calendar']['prefetch']) ? 'yes' : 'no';
        $result['calendar']['scroll_to_times'] = !empty($raw['calendar']['scroll_to_times']) ? 'yes' : 'no';

        $result['steps']['indicator_style'] = ($raw['steps']['indicator_style'] ?? '') === 'compact' ? 'compact' : 'numbered';
        $result['steps']['scroll_to_top'] = !empty($raw['steps']['scroll_to_top']) ? 'yes' : 'no';
        $result['lists']['row_spacing'] = self::integer($raw, 'lists', 'row_spacing', 2, 40, 12);
        $result['lists']['row_horizontal_spacing'] = self::integer($raw, 'lists', 'row_horizontal_spacing', 0, 40, 12);
        $result['lists']['item_gap'] = self::integer($raw, 'lists', 'item_gap', 0, 40, 12);
        $result['lists']['title_size'] = self::integer($raw, 'lists', 'title_size', 14, 42, 24);
        $result['lists']['header_size'] = self::integer($raw, 'lists', 'header_size', 10, 24, 14);
        $result['lists']['body_size'] = self::integer($raw, 'lists', 'body_size', 10, 24, 16);
        $listHeaderWeight = (int) ($raw['lists']['header_weight'] ?? 700);
        $result['lists']['header_weight'] = in_array($listHeaderWeight, [500, 600, 650, 700, 800], true) ? $listHeaderWeight : 700;
        foreach ([
            'title_color' => $result['foundation']['primary'],
            'header_background' => $result['foundation']['surface_alt'],
            'header_text' => $result['foundation']['text'],
            'body_text' => $result['foundation']['text'],
            'muted_text' => $result['foundation']['muted'],
            'row_background' => $result['foundation']['surface'],
            'alternate_background' => $result['foundation']['surface_alt'],
            'hover_background' => $result['foundation']['surface_alt'],
            'divider_color' => $result['foundation']['border'],
            'table_border_color' => $result['foundation']['border'],
            'column_divider_color' => $result['foundation']['border'],
            'card_background' => $result['foundation']['surface'],
            'card_border' => $result['foundation']['border'],
            'action_background' => $result['foundation']['primary'],
            'action_text' => '#ffffff',
            'action_border_color' => $result['foundation']['primary'],
        ] as $key => $fallback) {
            $result['lists'][$key] = self::controlledColor($raw, 'lists', $key, (string) $fallback);
        }
        $result['lists']['striped_rows'] = !empty($raw['lists']['striped_rows']) ? 'yes' : 'no';
        $result['lists']['hover_rows'] = !empty($raw['lists']['hover_rows']) ? 'yes' : 'no';
        $result['lists']['show_dividers'] = !empty($raw['lists']['show_dividers']) ? 'yes' : 'no';
        $result['lists']['divider_width'] = self::integer($raw, 'lists', 'divider_width', 1, 4, 1);
        $result['lists']['divider_style'] = self::borderStyle($raw['lists']['divider_style'] ?? null);
        $result['lists']['table_border_width'] = self::integer($raw, 'lists', 'table_border_width', 0, 4, 1);
        $result['lists']['table_border_style'] = self::borderStyle($raw['lists']['table_border_style'] ?? null);
        $result['lists']['table_radius'] = self::integer($raw, 'lists', 'table_radius', 0, 32, (int) $result['foundation']['radius']);
        $result['lists']['table_elevation'] = self::elevation($raw['lists']['table_elevation'] ?? null);
        $result['lists']['show_column_dividers'] = !empty($raw['lists']['show_column_dividers']) ? 'yes' : 'no';
        $result['lists']['column_divider_width'] = self::integer($raw, 'lists', 'column_divider_width', 1, 4, 1);
        $result['lists']['column_divider_style'] = self::borderStyle($raw['lists']['column_divider_style'] ?? null);
        $result['lists']['card_border_width'] = self::integer($raw, 'lists', 'card_border_width', 0, 4, 1);
        $result['lists']['card_radius'] = self::integer($raw, 'lists', 'card_radius', 0, 32, (int) $result['foundation']['radius']);
        $result['lists']['card_padding'] = self::integer($raw, 'lists', 'card_padding', 0, 40, 16);
        $result['lists']['elevation'] = self::elevation($raw['lists']['elevation'] ?? null);
        $result['lists']['action_border_width'] = self::integer($raw, 'lists', 'action_border_width', 0, 4, 1);
        $result['lists']['action_border_style'] = self::borderStyle($raw['lists']['action_border_style'] ?? null);
        $result['lists']['action_radius'] = self::integer($raw, 'lists', 'action_radius', 0, 32, (int) $result['foundation']['radius']);
        $result['lists']['pagination_style'] = in_array(($raw['lists']['pagination_style'] ?? ''), ['buttons', 'pills', 'text'], true) ? (string) $raw['lists']['pagination_style'] : 'buttons';
        $result['lists']['pagination_alignment'] = in_array(($raw['lists']['pagination_alignment'] ?? ''), ['start', 'center', 'end'], true) ? (string) $raw['lists']['pagination_alignment'] : 'center';
        $result['lists']['pagination_gap'] = self::integer($raw, 'lists', 'pagination_gap', 0, 32, 8);
        $result['lists']['pagination_show_page'] = !empty($raw['lists']['pagination_show_page']) ? 'yes' : 'no';
        foreach (['success', 'error', 'surface'] as $key) {
            $result['feedback'][$key] = self::controlledColor($raw, 'feedback', $key, (string) $defaults['feedback'][$key]);
        }
        return $result;
    }

    /** @param array<string,mixed> $settings */
    private static function cssVariables(array $settings): string
    {
        $f = $settings['foundation'];
        $review = $settings['review'];
        $calendar = $settings['calendar'];
        $forms = $settings['forms'];
        $steps = $settings['steps'];
        $lists = $settings['lists'];
        $feedback = $settings['feedback'];
        $fontFamily = self::fontFamilyCss((string) $f['font_family']);
        $reviewDivider = ($review['show_dividers'] ?? 'no') === 'yes' ? (string) $f['border'] : 'transparent';
        $listDividerWidth = ($lists['show_dividers'] ?? 'yes') === 'yes' ? (int) $lists['divider_width'] . 'px' : '0';
        $listAlternateBackground = ($lists['striped_rows'] ?? 'no') === 'yes' ? (string) $lists['alternate_background'] : (string) $lists['row_background'];
        $listRowHoverBackground = ($lists['hover_rows'] ?? 'yes') === 'yes' ? (string) $lists['hover_background'] : (string) $lists['row_background'];
        $listCardHoverBackground = ($lists['hover_rows'] ?? 'yes') === 'yes' ? (string) $lists['hover_background'] : (string) $lists['card_background'];
        $listShadows = [
            'subtle' => '0 1px 3px rgb(15 23 42 / 10%)',
            'raised' => '0 10px 28px rgb(15 23 42 / 14%)',
        ];
        $listShadow = $listShadows[$lists['elevation'] ?? 'none'] ?? 'none';
        $listTableShadow = $listShadows[$lists['table_elevation'] ?? 'none'] ?? 'none';
        $listColumnDividerWidth = ($lists['show_column_dividers'] ?? 'no') === 'yes' ? (int) $lists['column_divider_width'] . 'px' : '0';
        $paginationAlignment = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'][$lists['pagination_alignment'] ?? 'center'] ?? 'center';
        $paginationStyle = (string) ($lists['pagination_style'] ?? 'buttons');
        $paginationBackground = $paginationStyle === 'text' ? 'transparent' : (string) $lists['action_background'];
        $paginationText = $paginationStyle === 'text'
            ? ((string) $lists['action_border_color'] !== 'transparent' ? (string) $lists['action_border_color'] : (string) $lists['body_text'])
            : (string) $lists['action_text'];
        $paginationBorder = $paginationStyle === 'text' ? 'transparent' : (string) $lists['action_border_color'];
        $paginationBorderWidth = $paginationStyle === 'text' ? '0' : (int) $lists['action_border_width'] . 'px';
        $paginationRadius = $paginationStyle === 'pills' ? '999px' : ($paginationStyle === 'text' ? '0' : (int) $lists['action_radius'] . 'px');
        $paginationPageDisplay = ($lists['pagination_show_page'] ?? 'yes') === 'yes' ? 'inline' : 'none';
        $slotRadius = ($calendar['slot_style'] ?? 'pill') === 'card' ? (int) $f['radius'] . 'px' : '999px';
        $stepLabelSize = ($steps['indicator_style'] ?? 'numbered') === 'compact' ? '0' : '12px';

        $css = self::fontFaceCss($settings) . ':root{'
            . '--cliniko-ui-primary:' . $f['primary'] . ';--cliniko-ui-accent:' . $f['accent'] . ';--cliniko-ui-text:' . $f['text'] . ';--cliniko-ui-muted:' . $f['muted'] . ';'
            . '--cliniko-ui-surface:' . $f['surface'] . ';--cliniko-ui-surface-alt:' . $f['surface_alt'] . ';--cliniko-ui-border:' . $f['border'] . ';--cliniko-ui-font-family:' . $fontFamily . ';'
            . '--cliniko-ui-radius:' . (int) $f['radius'] . 'px;--cliniko-ui-control-height:' . (int) $f['control_height'] . 'px;'
            . '--cliniko-ui-review-answer-spacing:' . (int) $review['answer_spacing'] . 'px;--cliniko-ui-review-detail-spacing:' . (int) $review['detail_spacing'] . 'px;--cliniko-ui-review-label-weight:' . (int) $review['label_weight'] . ';--cliniko-ui-review-label-width:' . (int) $review['label_width'] . 'px;--cliniko-ui-review-divider:' . $reviewDivider . ';'
            . '--cliniko-ui-field-gap:' . (int) $forms['field_gap'] . 'px;'
            . '--cliniko-ui-calendar-day-size:' . (int) $calendar['day_size'] . 'px;--cliniko-ui-calendar-day-gap:' . (int) $calendar['day_gap'] . 'px;--cliniko-ui-calendar-panel-gap:' . (int) $calendar['panel_gap'] . 'px;--cliniko-ui-calendar-panel-radius:' . (int) $calendar['panel_radius'] . 'px;'
            . '--cliniko-ui-calendar-available:' . $calendar['available'] . ';--cliniko-ui-calendar-selected:' . $calendar['selected'] . ';--cliniko-ui-calendar-morning:' . $calendar['morning'] . ';--cliniko-ui-calendar-afternoon:' . $calendar['afternoon'] . ';--cliniko-ui-calendar-evening:' . $calendar['evening'] . ';--cliniko-ui-calendar-slot-radius:' . $slotRadius . ';'
            . '--cliniko-ui-step-label-size:' . $stepLabelSize . ';'
            . '--cliniko-ui-list-row-spacing:' . (int) $lists['row_spacing'] . 'px;--cliniko-ui-list-row-horizontal-spacing:' . (int) $lists['row_horizontal_spacing'] . 'px;--cliniko-ui-list-item-gap:' . (int) $lists['item_gap'] . 'px;'
            . '--cliniko-ui-list-title-color:' . $lists['title_color'] . ';--cliniko-ui-list-title-size:' . (int) $lists['title_size'] . 'px;--cliniko-ui-list-body-size:' . (int) $lists['body_size'] . 'px;--cliniko-ui-list-body-text:' . $lists['body_text'] . ';--cliniko-ui-list-muted:' . $lists['muted_text'] . ';'
            . '--cliniko-ui-list-header-background:' . $lists['header_background'] . ';--cliniko-ui-list-header-text:' . $lists['header_text'] . ';--cliniko-ui-list-header-size:' . (int) $lists['header_size'] . 'px;--cliniko-ui-list-header-weight:' . (int) $lists['header_weight'] . ';'
            . '--cliniko-ui-list-row-background:' . $lists['row_background'] . ';--cliniko-ui-list-alternate-background:' . $listAlternateBackground . ';--cliniko-ui-list-row-hover-background:' . $listRowHoverBackground . ';'
            . '--cliniko-ui-list-divider:' . $lists['divider_color'] . ';--cliniko-ui-list-divider-width:' . $listDividerWidth . ';--cliniko-ui-list-divider-style:' . $lists['divider_style'] . ';'
            . '--cliniko-ui-list-table-border:' . $lists['table_border_color'] . ';--cliniko-ui-list-table-border-width:' . (int) $lists['table_border_width'] . 'px;--cliniko-ui-list-table-border-style:' . $lists['table_border_style'] . ';--cliniko-ui-list-table-radius:' . (int) $lists['table_radius'] . 'px;--cliniko-ui-list-table-shadow:' . $listTableShadow . ';'
            . '--cliniko-ui-list-column-divider:' . $lists['column_divider_color'] . ';--cliniko-ui-list-column-divider-width:' . $listColumnDividerWidth . ';--cliniko-ui-list-column-divider-style:' . $lists['column_divider_style'] . ';'
            . '--cliniko-ui-list-card-background:' . $lists['card_background'] . ';--cliniko-ui-list-card-hover-background:' . $listCardHoverBackground . ';--cliniko-ui-list-card-border:' . $lists['card_border'] . ';--cliniko-ui-list-card-border-width:' . (int) $lists['card_border_width'] . 'px;--cliniko-ui-list-card-radius:' . (int) $lists['card_radius'] . 'px;--cliniko-ui-list-card-padding:' . (int) $lists['card_padding'] . 'px;--cliniko-ui-list-card-shadow:' . $listShadow . ';'
            . '--cliniko-ui-list-action-background:' . $lists['action_background'] . ';--cliniko-ui-list-action-text:' . $lists['action_text'] . ';--cliniko-ui-list-action-border:' . $lists['action_border_color'] . ';--cliniko-ui-list-action-border-width:' . (int) $lists['action_border_width'] . 'px;--cliniko-ui-list-action-border-style:' . $lists['action_border_style'] . ';--cliniko-ui-list-action-radius:' . (int) $lists['action_radius'] . 'px;'
            . '--cliniko-ui-list-pagination-background:' . $paginationBackground . ';--cliniko-ui-list-pagination-text:' . $paginationText . ';--cliniko-ui-list-pagination-border:' . $paginationBorder . ';--cliniko-ui-list-pagination-border-width:' . $paginationBorderWidth . ';--cliniko-ui-list-pagination-border-style:' . $lists['action_border_style'] . ';--cliniko-ui-list-pagination-radius:' . $paginationRadius . ';--cliniko-ui-list-pagination-alignment:' . $paginationAlignment . ';--cliniko-ui-list-pagination-gap:' . (int) $lists['pagination_gap'] . 'px;--cliniko-ui-list-pagination-page-display:' . $paginationPageDisplay . ';'
            . '--cliniko-ui-feedback-success:' . $feedback['success'] . ';--cliniko-ui-feedback-error:' . $feedback['error'] . ';--cliniko-ui-feedback-surface:' . $feedback['surface'] . '}';
        if (($review['edit_style'] ?? 'link') === 'button') {
            $css .= '.cliniko-component-data-review .cliniko-renewal-edit{padding:5px 10px!important;border:1px solid currentColor!important;border-radius:var(--cliniko-ui-radius)!important;text-decoration:none!important}';
        }
        $reviewRows = '.cliniko-component-data-review .cliniko-patient-booking-form__question,.cliniko-component-data-review .cliniko-dashboard-detail,.cliniko-component-data-review .cliniko-appointment-details__list>div,.cliniko-component-data-review .cliniko-patient-form-history__answer';
        $css .= $reviewRows . '{border-bottom:' . (($review['show_dividers'] ?? 'no') === 'yes' ? '1px solid var(--cliniko-ui-review-divider)' : '0') . '!important}';
        if (($forms['label_position'] ?? 'top') === 'inline') {
            $css .= '.cliniko-component-form-fields .cliniko-patient-booking-form__question,.cliniko-component-form-fields .cliniko-patient-booking-form__patient-field{grid-template-columns:minmax(130px,.4fr) minmax(0,1fr);align-items:start}';
        }
        $listRows = '.cliniko-component-list .cliniko-dashboard-detail,.cliniko-component-list .cliniko-dashboard-table th,.cliniko-component-list .cliniko-dashboard-table td,.cliniko-component-list .cliniko-patient-form-history__answer,.cliniko-component-list .cliniko-dashboard-attachment';
        $css .= $listRows . '{border-bottom-width:var(--cliniko-ui-list-divider-width)!important;border-bottom-style:var(--cliniko-ui-list-divider-style)!important;border-bottom-color:var(--cliniko-ui-list-divider)!important}';
        $css .= '.cliniko-component-list .cliniko-dashboard-table tbody tr:last-child>td{border-bottom-width:0!important}';
        return $css;
    }

    /** @param array<string,mixed> $settings */
    private static function renderFoundationPanel(array $settings): void
    {
        $f = $settings['foundation'];
        $fontOptions = self::fontOptions($settings);
        $currentFont = (string) ($f['font_family'] ?? 'inherit');
        $fontSelection = isset($fontOptions[$currentFont]) ? $currentFont : '__custom__';
        $customFont = $fontSelection === '__custom__' ? $currentFont : '';
        $installedFonts = self::sanitizeInstalledFonts($f['installed_fonts'] ?? []); ?>
        <section class="cliniko-component-editor__panel is-active" data-component-panel="foundation"><header><div><span>Theme tokens</span><h2>Shared visual foundation</h2><p>Used as defaults by every compatible component. Component-specific settings remain separate.</p></div></header>
            <div class="cliniko-component-editor__groups">
                <section class="cliniko-component-editor__group"><header><h3>Brand colours</h3><p>Actions, links and readable content.</p></header><div class="cliniko-component-editor__controls"><?php self::colorControl('Primary', 'foundation', 'primary', (string) $f['primary']); self::colorControl('Accent', 'foundation', 'accent', (string) $f['accent']); self::colorControl('Text', 'foundation', 'text', (string) $f['text']); self::colorControl('Muted text', 'foundation', 'muted', (string) $f['muted']); ?></div></section>
                <section class="cliniko-component-editor__group"><header><h3>Surfaces</h3><p>Backgrounds and boundaries shared by component panels.</p></header><div class="cliniko-component-editor__controls"><?php self::colorControl('Main surface', 'foundation', 'surface', (string) $f['surface']); self::colorControl('Soft surface', 'foundation', 'surface_alt', (string) $f['surface_alt']); self::colorControl('Border', 'foundation', 'border', (string) $f['border']); ?></div></section>
                <section class="cliniko-component-editor__group"><header><h3>Shape and typography</h3><p>Global control proportions. Individual components can refine these values.</p></header><div class="cliniko-component-editor__controls"><?php self::rangeControl('Corner radius', 'foundation', 'radius', (int) $f['radius'], 0, 32); self::rangeControl('Control height', 'foundation', 'control_height', (int) $f['control_height'], 34, 72); ?><label class="is-wide cliniko-component-editor__font-family-control"><span>Font family</span><select name="components[foundation][font_family]" data-font-family-select><?php foreach ($fontOptions as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($fontSelection, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?><option value="__custom__" <?php selected($fontSelection, '__custom__'); ?>>Custom font stack</option></select><span class="cliniko-component-editor__custom-font" data-font-family-custom-wrap<?php echo $fontSelection === '__custom__' ? '' : ' hidden'; ?>><input type="text" name="components[foundation][font_family_custom]" value="<?php echo esc_attr($customFont); ?>" placeholder="Poppins, sans-serif" data-font-family-custom<?php disabled($fontSelection !== '__custom__'); ?>></span><small>Choose a safe system stack, a locally installed font, or enter a CSS fallback stack.</small></label></div></section>
                <section class="cliniko-component-editor__group cliniko-component-editor__font-install"><header><h3>Install a local font</h3><p>Keep the font on this WordPress site and make it available to every compatible shortcode.</p></header><div class="cliniko-component-editor__font-install-body"><?php if (current_user_can('upload_files')) : ?><label><span>Font name <small>(optional)</small></span><input type="text" name="components[foundation][font_name]" placeholder="Inter"></label><label><span>Font file</span><input type="file" name="components[foundation][font_file]" accept=".woff2,.woff,.ttf"><small>Supported formats: WOFF2, WOFF and TTF. The file is installed when you save component styles.</small></label><?php else : ?><p class="description">Font installation is unavailable for this account because it does not have the WordPress <code>upload_files</code> capability.</p><?php endif; ?><?php if ($installedFonts !== []) : ?><div class="cliniko-component-editor__installed-fonts"><strong>Installed locally</strong><div><?php foreach ($installedFonts as $font) : ?><span><?php echo esc_html((string) $font['family']); ?></span><?php endforeach; ?></div></div><?php endif; ?></div></section>
            </div>
        </section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderReviewPanel(array $settings): void
    {
        $r = $settings['review']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="review" hidden><header><div><span>Data display</span><h2>Review and detail rows</h2><p>Controls renewal answers, appointment details, patient details and completed form answers.</p></div></header><div class="cliniko-component-editor__groups">
            <section class="cliniko-component-editor__group"><header><h3>Row layout</h3><p>Renewal answers and ordinary detail lists have separate density controls.</p></header><div class="cliniko-component-editor__controls"><?php self::rangeControl('Renewal answer spacing', 'review', 'answer_spacing', (int) $r['answer_spacing'], 0, 20); self::rangeControl('Detail row spacing', 'review', 'detail_spacing', (int) $r['detail_spacing'], 0, 24); self::rangeControl('Label width', 'review', 'label_width', (int) $r['label_width'], 80, 240); ?><label><span>Label weight</span><select name="components[review][label_weight]"><?php foreach ([400,500,600,650,700] as $weight) : ?><option value="<?php echo $weight; ?>" <?php selected((int) $r['label_weight'], $weight); ?>><?php echo $weight; ?></option><?php endforeach; ?></select></label><?php self::switchControl('Show row dividers', 'Adds a fine line between review rows.', 'review', 'show_dividers', ($r['show_dividers'] ?? 'no') === 'yes'); ?></div></section>
            <section class="cliniko-component-editor__group"><header><h3>Editing</h3><p>Only applies where displayed data can be edited, such as appointment renewal answers.</p></header><div class="cliniko-component-editor__controls"><label><span>Edit action</span><select name="components[review][edit_style]"><option value="link" <?php selected($r['edit_style'], 'link'); ?>>Text link</option><option value="button" <?php selected($r['edit_style'], 'button'); ?>>Outlined button</option></select></label><label><span>Editing behaviour</span><select name="components[review][edit_mode]"><option value="multiple" <?php selected($r['edit_mode'], 'multiple'); ?>>Allow multiple fields</option><option value="single" <?php selected($r['edit_mode'], 'single'); ?>>One field at a time</option></select></label></div></section>
        </div></section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderFormsPanel(array $settings): void
    {
        $f = $settings['forms']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="forms" hidden><header><div><span>Input components</span><h2>Form fields</h2><p>Shared field rhythm for booking, patient details, patient forms and onboarding.</p></div></header><div class="cliniko-component-editor__groups"><section class="cliniko-component-editor__group"><header><h3>Field layout</h3><p>Control spacing here; height, colour and radius come from Theme.</p></header><div class="cliniko-component-editor__controls"><?php self::rangeControl('Space between fields', 'forms', 'field_gap', (int) $f['field_gap'], 4, 48); ?><label><span>Booking label position</span><select name="components[forms][label_position]"><option value="top" <?php selected($f['label_position'], 'top'); ?>>Above controls</option><option value="inline" <?php selected($f['label_position'], 'inline'); ?>>Beside controls</option></select><small>Patient-profile layouts keep their configured columns.</small></label></div></section></div></section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderCalendarPanel(array $settings): void
    {
        $c = $settings['calendar']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="calendar" hidden><header><div><span>Appointment selection</span><h2>Calendar and time slots</h2><p>A two-panel calendar matching the form shell: dates on the left and grouped times on the right.</p></div></header><div class="cliniko-component-editor__groups">
            <section class="cliniko-component-editor__group"><header><h3>Layout</h3><p>Size the day grid and the relationship between both panels.</p></header><div class="cliniko-component-editor__controls"><?php self::rangeControl('Day height', 'calendar', 'day_size', (int) $c['day_size'], 36, 84); self::rangeControl('Gap between days', 'calendar', 'day_gap', (int) $c['day_gap'], 2, 14); self::rangeControl('Gap between panels', 'calendar', 'panel_gap', (int) $c['panel_gap'], 8, 32); self::rangeControl('Panel radius', 'calendar', 'panel_radius', (int) $c['panel_radius'], 0, 32); ?><label><span>Time-slot shape</span><select name="components[calendar][slot_style]"><option value="pill" <?php selected($c['slot_style'], 'pill'); ?>>Pills</option><option value="card" <?php selected($c['slot_style'], 'card'); ?>>Rounded cards</option></select></label></div></section>
            <section class="cliniko-component-editor__group"><header><h3>Availability colours</h3><p>Separate date selection from the morning, afternoon and evening indicators.</p></header><div class="cliniko-component-editor__controls"><?php self::colorControl('Available date', 'calendar', 'available', (string) $c['available']); self::colorControl('Selected date/time', 'calendar', 'selected', (string) $c['selected']); self::colorControl('Morning', 'calendar', 'morning', (string) $c['morning']); self::colorControl('Afternoon', 'calendar', 'afternoon', (string) $c['afternoon']); self::colorControl('Evening', 'calendar', 'evening', (string) $c['evening']); ?></div></section>
            <section class="cliniko-component-editor__group"><header><h3>Behaviour</h3><p>Shared JavaScript behaviour used by booking calendars.</p></header><div class="cliniko-component-editor__controls"><?php self::switchControl('Preload the next month', 'Makes month navigation feel faster.', 'calendar', 'prefetch', ($c['prefetch'] ?? 'yes') === 'yes'); self::switchControl('Move to times after date selection', 'Useful on narrow screens and long pages.', 'calendar', 'scroll_to_times', ($c['scroll_to_times'] ?? 'no') === 'yes'); ?></div></section>
        </div></section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderStepsPanel(array $settings): void
    {
        $s = $settings['steps']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="steps" hidden><header><div><span>Navigation component</span><h2>Multi-step progress</h2><p>Used by booking and onboarding flows while each flow keeps its existing markup.</p></div></header><div class="cliniko-component-editor__groups"><section class="cliniko-component-editor__group"><header><h3>Progress presentation</h3><p>Choose how much step context patients see.</p></header><div class="cliniko-component-editor__controls"><label><span>Indicator style</span><select name="components[steps][indicator_style]"><option value="numbered" <?php selected($s['indicator_style'], 'numbered'); ?>>Numbers and labels</option><option value="compact" <?php selected($s['indicator_style'], 'compact'); ?>>Compact indicators</option></select></label><?php self::switchControl('Move to the top on step changes', 'Prevents the next step opening below the viewport.', 'steps', 'scroll_to_top', ($s['scroll_to_top'] ?? 'no') === 'yes'); ?></div></section></div></section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderListsPanel(array $settings): void
    {
        $l = $settings['lists']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="lists" hidden>
            <header><div><span>Repeated data</span><h2>Lists, tables and timelines</h2><p>Appointment tables, documents, completed forms and communication cards.</p></div></header>
            <div class="cliniko-component-editor__groups">
                <section class="cliniko-component-editor__group">
                    <header><h3>Layout and density</h3><p>Control the space inside rows and between repeated cards.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::rangeControl('Vertical row padding', 'lists', 'row_spacing', (int) $l['row_spacing'], 2, 40); ?>
                        <?php self::rangeControl('Horizontal row padding', 'lists', 'row_horizontal_spacing', (int) $l['row_horizontal_spacing'], 0, 40); ?>
                        <?php self::rangeControl('Space between cards', 'lists', 'item_gap', (int) $l['item_gap'], 0, 40); ?>
                        <?php self::rangeControl('Card content padding', 'lists', 'card_padding', (int) $l['card_padding'], 0, 40); ?>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header class="cliniko-component-editor__group-header-with-action"><div><h3>Typography and colours</h3><p>Give list titles, column headings and secondary information their own hierarchy.</p></div><button type="button" class="button" data-copy-theme-to-lists>Copy Theme colours</button></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::colorControl('List title', 'lists', 'title_color', (string) $l['title_color']); ?>
                        <?php self::rangeControl('Title size', 'lists', 'title_size', (int) $l['title_size'], 14, 42); ?>
                        <?php self::colorControl('Column heading text', 'lists', 'header_text', (string) $l['header_text']); ?>
                        <?php self::rangeControl('Column heading size', 'lists', 'header_size', (int) $l['header_size'], 10, 24); ?>
                        <label><span>Column heading weight</span><select name="components[lists][header_weight]"><?php foreach ([500, 600, 650, 700, 800] as $weight) : ?><option value="<?php echo $weight; ?>" <?php selected((int) $l['header_weight'], $weight); ?>><?php echo $weight; ?></option><?php endforeach; ?></select></label>
                        <?php self::rangeControl('Row text size', 'lists', 'body_size', (int) $l['body_size'], 10, 24); ?>
                        <?php self::colorControl('Row text', 'lists', 'body_text', (string) $l['body_text']); ?>
                        <?php self::colorControl('Secondary text', 'lists', 'muted_text', (string) $l['muted_text']); ?>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header><h3>Table rows</h3><p>Style table headers and make long appointment lists easier to scan.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::colorControl('Header background', 'lists', 'header_background', (string) $l['header_background']); ?>
                        <?php self::colorControl('Row background', 'lists', 'row_background', (string) $l['row_background']); ?>
                        <?php self::colorControl('Alternate row', 'lists', 'alternate_background', (string) $l['alternate_background']); ?>
                        <?php self::colorControl('Hover row', 'lists', 'hover_background', (string) $l['hover_background']); ?>
                        <?php self::switchControl('Striped rows', 'Alternates the row background in appointment tables.', 'lists', 'striped_rows', ($l['striped_rows'] ?? 'no') === 'yes'); ?>
                        <?php self::switchControl('Highlight on hover', 'Highlights table rows and repeated cards under the pointer.', 'lists', 'hover_rows', ($l['hover_rows'] ?? 'yes') === 'yes'); ?>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header><h3>Table frame and grid</h3><p>Control the appointment table container separately from cards, including its outer frame, corners and column separators.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::colorControl('Table border', 'lists', 'table_border_color', (string) $l['table_border_color']); ?>
                        <?php self::rangeControl('Table border width', 'lists', 'table_border_width', (int) $l['table_border_width'], 0, 4); ?>
                        <label><span>Table border style</span><select name="components[lists][table_border_style]"><option value="solid" <?php selected($l['table_border_style'], 'solid'); ?>>Solid</option><option value="dashed" <?php selected($l['table_border_style'], 'dashed'); ?>>Dashed</option><option value="dotted" <?php selected($l['table_border_style'], 'dotted'); ?>>Dotted</option></select></label>
                        <?php self::rangeControl('Container radius', 'lists', 'table_radius', (int) $l['table_radius'], 0, 32); ?>
                        <label><span>Table elevation</span><select name="components[lists][table_elevation]"><option value="none" <?php selected($l['table_elevation'], 'none'); ?>>None</option><option value="subtle" <?php selected($l['table_elevation'], 'subtle'); ?>>Subtle shadow</option><option value="raised" <?php selected($l['table_elevation'], 'raised'); ?>>Raised table</option></select></label>
                        <?php self::switchControl('Show column dividers', 'Adds a vertical separator between table columns.', 'lists', 'show_column_dividers', ($l['show_column_dividers'] ?? 'no') === 'yes'); ?>
                        <?php self::colorControl('Column divider', 'lists', 'column_divider_color', (string) $l['column_divider_color']); ?>
                        <?php self::rangeControl('Column divider width', 'lists', 'column_divider_width', (int) $l['column_divider_width'], 1, 4); ?>
                        <label><span>Column divider style</span><select name="components[lists][column_divider_style]"><option value="solid" <?php selected($l['column_divider_style'], 'solid'); ?>>Solid</option><option value="dashed" <?php selected($l['column_divider_style'], 'dashed'); ?>>Dashed</option><option value="dotted" <?php selected($l['column_divider_style'], 'dotted'); ?>>Dotted</option></select></label>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header><h3>Dividers and cards</h3><p>Dividers separate plain rows; card settings apply to forms, appointments and communication items.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::switchControl('Show row dividers', 'Separates table cells, documents and answer rows.', 'lists', 'show_dividers', ($l['show_dividers'] ?? 'yes') === 'yes'); ?>
                        <?php self::colorControl('Divider colour', 'lists', 'divider_color', (string) $l['divider_color']); ?>
                        <?php self::rangeControl('Divider width', 'lists', 'divider_width', (int) $l['divider_width'], 1, 4); ?>
                        <label><span>Divider style</span><select name="components[lists][divider_style]"><option value="solid" <?php selected($l['divider_style'], 'solid'); ?>>Solid</option><option value="dashed" <?php selected($l['divider_style'], 'dashed'); ?>>Dashed</option><option value="dotted" <?php selected($l['divider_style'], 'dotted'); ?>>Dotted</option></select></label>
                        <?php self::colorControl('Card background', 'lists', 'card_background', (string) $l['card_background']); ?>
                        <?php self::colorControl('Card border', 'lists', 'card_border', (string) $l['card_border']); ?>
                        <?php self::rangeControl('Card border width', 'lists', 'card_border_width', (int) $l['card_border_width'], 0, 4); ?>
                        <?php self::rangeControl('Card radius', 'lists', 'card_radius', (int) $l['card_radius'], 0, 32); ?>
                        <label><span>Card elevation</span><select name="components[lists][elevation]"><option value="none" <?php selected($l['elevation'], 'none'); ?>>None</option><option value="subtle" <?php selected($l['elevation'], 'subtle'); ?>>Subtle shadow</option><option value="raised" <?php selected($l['elevation'], 'raised'); ?>>Raised card</option></select></label>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header><h3>List actions</h3><p>Used for appointment details, pagination and actions inside repeated items.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <?php self::colorControl('Action background', 'lists', 'action_background', (string) $l['action_background']); ?>
                        <?php self::colorControl('Action text', 'lists', 'action_text', (string) $l['action_text']); ?>
                        <?php self::colorControl('Action border', 'lists', 'action_border_color', (string) $l['action_border_color']); ?>
                        <?php self::rangeControl('Action border width', 'lists', 'action_border_width', (int) $l['action_border_width'], 0, 4); ?>
                        <label><span>Action border style</span><select name="components[lists][action_border_style]"><option value="solid" <?php selected($l['action_border_style'], 'solid'); ?>>Solid</option><option value="dashed" <?php selected($l['action_border_style'], 'dashed'); ?>>Dashed</option><option value="dotted" <?php selected($l['action_border_style'], 'dotted'); ?>>Dotted</option></select></label>
                        <?php self::rangeControl('Action radius', 'lists', 'action_radius', (int) $l['action_radius'], 0, 32); ?>
                    </div>
                </section>
                <section class="cliniko-component-editor__group">
                    <header><h3>Pagination</h3><p>Patient-form history and appointment lists currently support previous/current/next navigation.</p></header>
                    <div class="cliniko-component-editor__controls">
                        <label><span>Pagination appearance</span><select name="components[lists][pagination_style]"><option value="buttons" <?php selected($l['pagination_style'], 'buttons'); ?>>Buttons</option><option value="pills" <?php selected($l['pagination_style'], 'pills'); ?>>Pills</option><option value="text" <?php selected($l['pagination_style'], 'text'); ?>>Text links</option></select></label>
                        <label><span>Pagination alignment</span><select name="components[lists][pagination_alignment]"><option value="start" <?php selected($l['pagination_alignment'], 'start'); ?>>Left</option><option value="center" <?php selected($l['pagination_alignment'], 'center'); ?>>Centre</option><option value="end" <?php selected($l['pagination_alignment'], 'end'); ?>>Right</option></select></label>
                        <?php self::rangeControl('Pagination spacing', 'lists', 'pagination_gap', (int) $l['pagination_gap'], 0, 32); ?>
                        <?php self::switchControl('Show current page', 'Shows the current page between the previous and next links.', 'lists', 'pagination_show_page', ($l['pagination_show_page'] ?? 'yes') === 'yes'); ?>
                    </div>
                </section>
            </div>
        </section><?php
    }

    /** @param array<string,mixed> $settings */
    private static function renderFeedbackPanel(array $settings): void
    {
        $f = $settings['feedback']; ?>
        <section class="cliniko-component-editor__panel" data-component-panel="feedback" hidden><header><div><span>Status component</span><h2>Success and error messages</h2><p>Shared feedback colours used after submissions and asynchronous actions.</p></div></header><div class="cliniko-component-editor__groups"><section class="cliniko-component-editor__group"><header><h3>Message colours</h3><p>Keep status messages recognisable across every shortcode.</p></header><div class="cliniko-component-editor__controls"><?php self::colorControl('Success text', 'feedback', 'success', (string) $f['success']); self::colorControl('Error text', 'feedback', 'error', (string) $f['error']); self::colorControl('Message surface', 'feedback', 'surface', (string) $f['surface']); ?></div></section></div></section><?php
    }

    private static function renderPreview(): void
    {
        ?>
        <div class="cliniko-component-preview" data-preview-canvas>
            <section class="cliniko-component-preview__scene is-active" data-preview-scene="foundation"><span class="cliniko-component-preview__eyebrow">Theme preview</span><h3>Patient experience</h3><p>Shared colours, typography, controls and surfaces establish the baseline for every component.</p><label class="cliniko-component-preview__field"><span>Example control</span><input type="text" value="Editable patient information"></label><button type="button" class="cliniko-component-preview__primary">Primary action</button><button type="button" class="cliniko-component-preview__secondary">Secondary action</button></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="review" hidden><span class="cliniko-component-preview__eyebrow">Renewal review</span><h3>Review your last appointment</h3><div class="cliniko-component-preview__review"><div><strong>Appointment type</strong><span>Follow-up consultation</span><a href="#">Edit</a></div><div><strong>Practitioner</strong><span>Dr Taylor</span><a href="#">Edit</a></div><div><strong>Preferred contact</strong><span>Phone</span><a href="#">Edit</a></div></div><h4>Appointment details</h4><div class="cliniko-component-preview__details"><div><strong>Status</strong><span>Confirmed</span></div><div><strong>Duration</strong><span>30 minutes</span></div></div></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="forms" hidden><span class="cliniko-component-preview__eyebrow">Form fields</span><h3>Patient details</h3><div class="cliniko-component-preview__fields"><label class="cliniko-component-preview__field"><span>Full name</span><input type="text" value="Alex Morgan"></label><label class="cliniko-component-preview__field"><span>Email</span><input type="email" value="alex@example.com"></label><label class="cliniko-component-preview__field"><span>Notes</span><textarea rows="3">Anything the clinic should know.</textarea></label></div></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="calendar" hidden><?php self::renderCalendarPreview(); ?></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="steps" hidden><span class="cliniko-component-preview__eyebrow">Booking progress</span><h3>Complete your renewal</h3><ol class="cliniko-component-preview__steps"><li class="is-complete">Review</li><li class="is-active">Consent</li><li>Appointment</li><li>Payment</li></ol><div class="cliniko-component-preview__step-card"><strong>Consent</strong><p>Please review and agree before continuing.</p></div></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="lists" hidden><span class="cliniko-component-preview__eyebrow">Patient dashboard</span><h3>Upcoming appointments</h3><div class="cliniko-component-preview__list"><div class="cliniko-component-preview__list-table-wrap"><table class="cliniko-component-preview__list-table"><thead><tr><th>Appointment</th><th>Status</th></tr></thead><tbody><tr><td><strong>Follow-up consultation</strong><small>14 Sep, 10:30 am</small></td><td>Confirmed</td></tr><tr class="is-preview-hover"><td><strong>Review appointment</strong><small>28 Sep, 2:00 pm</small></td><td>Pending</td></tr></tbody></table></div><div class="cliniko-component-preview__list-cards"><article><strong>Health history</strong><small>Completed 2 September</small></article><article><strong>Referral.pdf</strong><small>Patient document</small></article></div><a href="#" class="cliniko-component-preview__list-action">View details</a><nav class="cliniko-component-preview__pagination" aria-label="Preview pagination"><a href="#">Previous</a><span>Page 2</span><a href="#">Next</a></nav></div></section>
            <section class="cliniko-component-preview__scene" data-preview-scene="feedback" hidden><span class="cliniko-component-preview__eyebrow">Messages</span><h3>Submission feedback</h3><p class="cliniko-component-preview__feedback is-success">Your changes were saved successfully.</p><p class="cliniko-component-preview__feedback is-error">Please review the highlighted fields.</p></section>
        </div>
        <?php
    }

    private static function renderCalendarPreview(): void
    {
        ?><span class="cliniko-component-preview__eyebrow">Appointment selection</span><div class="appointment-selection"><div class="appointment-calendar"><div class="appointment-calendar__header"><div class="appointment-calendar__nav"><button type="button" class="calendar-nav">‹</button><strong class="appointment-calendar__month">September 2026</strong><button type="button" class="calendar-nav">›</button></div><div class="appointment-calendar__legend"><span><i class="is-morning"></i>Morning</span><span><i class="is-afternoon"></i>Afternoon</span><span><i class="is-evening"></i>Evening</span></div></div><div class="appointment-calendar__filters"><label>Practitioner<select><option>Dr Taylor</option></select></label></div><div class="appointment-calendar__weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="appointment-calendar__grid"><?php foreach (range(1, 14) as $day) : ?><button type="button" class="calendar-day <?php echo $day === 8 ? 'is-selected' : ($day % 3 === 0 ? 'is-available' : ''); ?>"><b><?php echo $day; ?></b><span><i class="is-morning"></i><?php if ($day % 2 === 0) : ?><i class="is-afternoon"></i><?php endif; ?></span></button><?php endforeach; ?></div></div><div class="appointment-day-times"><strong>Available times — Tue, 8 Sep</strong><p>Select an available time below.</p><?php foreach (['Morning' => ['9:00 am','10:30 am'], 'Afternoon' => ['1:00 pm','3:30 pm'], 'Evening' => ['5:30 pm']] as $period => $times) : ?><div class="appointment-day-times__group"><small><?php echo esc_html($period); ?></small><div><?php foreach ($times as $time) : ?><button type="button" class="appointment-time-slot<?php echo $time === '10:30 am' ? ' is-selected' : ''; ?>"><?php echo esc_html($time); ?></button><?php endforeach; ?></div></div><?php endforeach; ?></div></div><?php
    }

    private static function colorControl(string $label, string $group, string $key, string $value): void
    {
        $transparent = strtolower(trim($value)) === 'transparent';
        $pickerValue = sanitize_hex_color($value);
        $pickerValue = is_string($pickerValue) && $pickerValue !== '' ? $pickerValue : '#ffffff'; ?>
        <div class="cliniko-component-editor__control cliniko-component-editor__color-control<?php echo $transparent ? ' is-transparent' : ''; ?>" data-component-control data-component-color-control>
            <span><?php echo esc_html($label); ?></span>
            <div class="cliniko-component-editor__color"><input type="color" name="components[<?php echo esc_attr($group); ?>][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($pickerValue); ?>" data-component-color><code><?php echo esc_html($transparent ? 'transparent' : $pickerValue); ?></code></div>
            <label class="cliniko-component-editor__transparent"><input type="checkbox" name="components[<?php echo esc_attr($group); ?>][<?php echo esc_attr($key); ?>_transparent]" value="1" data-component-color-transparent <?php checked($transparent); ?>><span>Transparent</span></label>
        </div><?php
    }

    private static function rangeControl(string $label, string $group, string $key, int $value, int $min, int $max): void
    {
        ?><label><span><?php echo esc_html($label); ?></span><div class="cliniko-component-editor__range"><input type="range" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="components[<?php echo esc_attr($group); ?>][<?php echo esc_attr($key); ?>]" value="<?php echo $value; ?>" data-component-range><output><?php echo $value; ?>px</output></div></label><?php
    }

    private static function switchControl(string $label, string $description, string $group, string $key, bool $checked): void
    {
        ?><label class="cliniko-component-editor__switch"><input type="checkbox" name="components[<?php echo esc_attr($group); ?>][<?php echo esc_attr($key); ?>]" value="1" <?php checked($checked); ?>><span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span></label><?php
    }

    public static function enqueueAdminAssets(): void
    {
        $css = dirname(__DIR__, 2) . '/assets/component-styles-admin.css';
        $js = dirname(__DIR__, 2) . '/assets/component-styles-admin.js';
        wp_enqueue_style('cliniko-component-styles-admin', plugins_url('../../assets/component-styles-admin.css', __FILE__), ['cliniko-template-builder-admin'], is_file($css) ? (string) filemtime($css) : null);
        wp_enqueue_script('cliniko-component-styles-admin', plugins_url('../../assets/component-styles-admin.js', __FILE__), [], is_file($js) ? (string) filemtime($js) : null, true);
        wp_localize_script('cliniko-component-styles-admin', 'ClinikoComponentAdmin', ['elementor' => self::elementorTheme()]);
        wp_add_inline_style('cliniko-component-styles-admin', self::cssVariables(self::settings()));
    }

    /** @return array<string,string> */
    private static function fontOptions(array $settings): array
    {
        $options = [
            'inherit' => 'Use website/default font',
            'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' => 'System UI',
            'Arial, Helvetica, sans-serif' => 'Arial',
            '"Helvetica Neue", Helvetica, Arial, sans-serif' => 'Helvetica Neue',
            'Georgia, "Times New Roman", serif' => 'Georgia',
            'Verdana, Geneva, sans-serif' => 'Verdana',
            'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace' => 'Monospace',
        ];
        foreach (self::sanitizeInstalledFonts($settings['foundation']['installed_fonts'] ?? []) as $font) {
            $family = (string) ($font['family'] ?? '');
            if ($family !== '') {
                $options[$family] = $family . ' (installed)';
            }
        }
        return $options;
    }

    /** @return array<string,mixed> */
    private static function fontUploadFromRequest(): array
    {
        $files = isset($_FILES['components']) && is_array($_FILES['components']) ? $_FILES['components'] : [];
        $fontFile = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (isset($files[$key]['foundation']['font_file'])) {
                $fontFile[$key] = $files[$key]['foundation']['font_file'];
            }
        }
        return isset($fontFile['error']) ? $fontFile : [];
    }

    /** @return array{font?:array{family:string,url:string,format:string},error?:string} */
    private static function installFont(array $file, string $requestedName): array
    {
        if (!current_user_can('upload_files')) {
            return ['error' => 'The current user cannot upload fonts.'];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'The font upload failed.'];
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['woff2', 'woff', 'ttf'], true)) {
            return ['error' => 'Only WOFF2, WOFF and TTF files are supported.'];
        }
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $uploadFile = [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($file['size'] ?? 0),
        ];
        $upload = wp_handle_upload($uploadFile, [
            'test_form' => false,
            'mimes' => [
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'ttf' => 'font/ttf',
            ],
        ]);
        if (isset($upload['error'])) {
            return ['error' => (string) $upload['error']];
        }

        $url = esc_url_raw((string) ($upload['url'] ?? ''));
        if ($url === '') {
            return ['error' => 'The uploaded font did not return a usable URL.'];
        }
        $family = self::cleanFontName($requestedName);
        if ($family === '') {
            $family = self::cleanFontName((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));
        }
        if ($family === '') {
            return ['error' => 'The font needs a valid family name.'];
        }
        return ['font' => ['family' => $family, 'url' => $url, 'format' => $extension]];
    }

    /** @param mixed $fonts @return array<int,array{family:string,url:string,format:string}> */
    private static function sanitizeInstalledFonts($fonts): array
    {
        if (!is_array($fonts)) {
            return [];
        }
        $result = [];
        foreach ($fonts as $font) {
            if (!is_array($font)) {
                continue;
            }
            $family = self::cleanFontName((string) ($font['family'] ?? ''));
            $url = esc_url_raw((string) ($font['url'] ?? ''));
            $format = sanitize_key((string) ($font['format'] ?? ''));
            if ($family === '' || $url === '' || !in_array($format, ['woff2', 'woff', 'ttf'], true)) {
                continue;
            }
            $result[] = ['family' => $family, 'url' => $url, 'format' => $format];
        }
        return array_slice($result, -25);
    }

    private static function cleanFontName(string $name): string
    {
        $name = trim(sanitize_text_field($name));
        $clean = preg_replace('/[^\\p{L}\\p{N} _-]/u', '', $name);
        return trim(is_string($clean) ? $clean : '');
    }

    /** @param array<string,mixed> $settings */
    private static function fontFaceCss(array $settings): string
    {
        $selected = self::fontFamilyName((string) ($settings['foundation']['font_family'] ?? ''));
        if ($selected === '') {
            return '';
        }
        foreach (self::sanitizeInstalledFonts($settings['foundation']['installed_fonts'] ?? []) as $font) {
            if (strcasecmp((string) $font['family'], $selected) !== 0) {
                continue;
            }
            $format = $font['format'] === 'ttf' ? 'truetype' : (string) $font['format'];
            return '@font-face{font-family:"' . $font['family'] . '";src:url("' . $font['url'] . '") format("' . $format . '");font-style:normal;font-weight:400;font-display:swap;}';
        }
        return '';
    }

    private static function fontFamilyName(string $font): string
    {
        $first = trim(explode(',', $font)[0]);
        return self::cleanFontName(trim($first, " \t\n\r\0\x0B\"'"));
    }

    /** @return array{available:bool,message:string,kit:string,values:array<string,string>} */
    private static function elementorTheme(): array
    {
        $unavailable = ['available' => false, 'message' => 'Elementor theme import is unavailable.', 'kit' => '', 'values' => []];
        if (!defined('ELEMENTOR_VERSION') && !class_exists('Elementor\\Plugin')) {
            $unavailable['message'] = 'Install and activate Elementor to copy its Site Settings.';
            return $unavailable;
        }

        $kitId = (int) get_option('elementor_active_kit', 0);
        if ($kitId <= 0) {
            $unavailable['message'] = 'Elementor does not have an active Site Kit.';
            return $unavailable;
        }
        $settings = get_post_meta($kitId, '_elementor_page_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (class_exists('Elementor\\Plugin')) {
            try {
                $kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
                $displaySettings = $kit->get_settings_for_display();
                if (is_array($displaySettings)) {
                    $settings = array_replace_recursive($displaySettings, $settings);
                }
            } catch (\Throwable $exception) {
                // Saved kit metadata remains a valid fallback when the
                // Elementor runtime cannot resolve its display settings.
            }
        }

        $colors = [];
        foreach ((array) ($settings['system_colors'] ?? []) as $color) {
            if (!is_array($color)) {
                continue;
            }
            $id = sanitize_key((string) ($color['_id'] ?? ''));
            $value = sanitize_hex_color((string) ($color['color'] ?? ''));
            if ($id !== '' && is_string($value) && $value !== '') {
                $colors[$id] = $value;
            }
        }
        $values = [];
        foreach (['primary' => 'primary', 'accent' => 'accent', 'text' => 'text', 'secondary' => 'muted'] as $elementorKey => $componentKey) {
            if (isset($colors[$elementorKey])) {
                $values['components[foundation][' . $componentKey . ']'] = $colors[$elementorKey];
            }
        }
        foreach ((array) ($settings['system_typography'] ?? []) as $typography) {
            if (!is_array($typography) || ($typography['_id'] ?? '') !== 'text') {
                continue;
            }
            $family = sanitize_text_field((string) ($typography['typography_font_family'] ?? ''));
            if ($family !== '') {
                $values['components[foundation][font_family]'] = '__custom__';
                $values['components[foundation][font_family_custom]'] = $family;
            }
            break;
        }
        if (isset($colors['primary'])) {
            $values['components[foundation][surface_alt]'] = self::mixWithWhite($colors['primary'], 0.06);
            $values['components[foundation][border]'] = self::mixWithWhite($colors['primary'], 0.22);
            $values['components[calendar][available]'] = $colors['primary'];
            $values['components[calendar][selected]'] = $colors['primary'];
        }
        $elementorSurface = sanitize_hex_color((string) ($settings['background_color'] ?? ''));
        if (is_string($elementorSurface) && $elementorSurface !== '') {
            $values['components[foundation][surface]'] = $elementorSurface;
        }

        if ($values === []) {
            $unavailable['message'] = 'The active Elementor Site Kit has no global colours or fonts to copy.';
            return $unavailable;
        }
        $kitTitle = trim((string) get_the_title($kitId));
        return [
            'available' => true,
            'message' => 'Ready to copy from Elementor' . ($kitTitle !== '' ? ': ' . $kitTitle : '') . '. Review the preview, then save.',
            'kit' => $kitTitle,
            'values' => $values,
        ];
    }

    private static function mixWithWhite(string $hex, float $weight): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#e2e8f0';
        }
        $parts = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $mixed = array_map(static fn(int $part): int => (int) round(255 - ((255 - $part) * $weight)), $parts);
        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    private static function cleanColor($value, string $fallback): string
    {
        $color = sanitize_hex_color((string) $value);
        return is_string($color) && $color !== '' ? $color : $fallback;
    }

    /** @param array<string,mixed> $raw */
    private static function controlledColor(array $raw, string $group, string $key, string $fallback): string
    {
        if (!empty($raw[$group][$key . '_transparent'])) {
            return 'transparent';
        }
        return self::cleanColor($raw[$group][$key] ?? null, $fallback);
    }

    private static function borderStyle($value): string
    {
        return in_array($value, ['solid', 'dashed', 'dotted'], true) ? (string) $value : 'solid';
    }

    private static function elevation($value): string
    {
        return in_array($value, ['none', 'subtle', 'raised'], true) ? (string) $value : 'none';
    }

    /** @param array<string,mixed> $raw */
    private static function integer(array $raw, string $group, string $key, int $min, int $max, int $fallback): int
    {
        $value = (int) ($raw[$group][$key] ?? $fallback);
        return max($min, min($max, $value));
    }

    private static function fontFamilyCss(string $font): string
    {
        if ($font === '' || strtolower($font) === 'inherit') {
            return 'inherit';
        }
        $font = sanitize_text_field($font);
        $font = str_replace(['\\', ';', '{', '}'], '', $font);
        $families = [];
        $generic = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded'];
        foreach (explode(',', $font) as $family) {
            $family = trim($family, " \t\n\r\0\x0B\"'");
            $clean = self::cleanFontName($family);
            if ($clean === '') {
                continue;
            }
            $families[] = in_array(strtolower($clean), $generic, true) ? $clean : '"' . $clean . '"';
        }
        return $families !== [] ? implode(',', $families) : 'inherit';
    }
}
