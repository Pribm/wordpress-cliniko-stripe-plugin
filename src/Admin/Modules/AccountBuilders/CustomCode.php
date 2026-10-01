<?php

namespace App\Admin\Modules\AccountBuilders;

if (!defined('ABSPATH')) {
    exit;
}

final class CustomCode
{
    private const OPTION_KEY = 'wp_cliniko_custom_code_bundles';
    private const SAVE_ACTION = 'wp_cliniko_custom_code_save';
    private const DELETE_ACTION = 'wp_cliniko_custom_code_delete';

    /** @var array<string,string> */
    private static array $pendingStyles = [];

    /** @var array<string,string> */
    private static array $pendingScripts = [];

    public static function init(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'save']);
        add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'delete']);
        add_action('wp', [self::class, 'preparePageAssets'], 20);
        add_action('wp_head', [self::class, 'printHeadAssets'], 9999);
        add_action('wp_footer', [self::class, 'printFrontendAssets'], 999);
    }

    private static function aliasKey(string $value): string
    {
        return sanitize_key(trim($value));
    }

    /**
     * Parse a comma-separated or repeated alias value.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function aliasList($value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $aliases = [];

        foreach ($values as $item) {
            $alias = self::aliasKey((string) $item);
            if ($alias !== '' && $alias !== 'alias') {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    public static function preparePageAssets(): void
    {
        if (is_admin()) {
            return;
        }

        global $post;
        $sources = [];
        if (is_object($post)) {
            $sources[] = (string) ($post->post_content ?? '');
            $elementorData = get_post_meta((int) ($post->ID ?? 0), '_elementor_data', true);
            if (is_string($elementorData) && $elementorData !== '') {
                $sources[] = $elementorData;
            }
        }

        foreach ($sources as $source) {
            foreach (['code_alias', 'booking_alias', 'variant', 'variants'] as $attribute) {
                if (!preg_match_all('/' . preg_quote($attribute, '/') . '\s*=\s*["\']([^"\']+)["\']/i', $source, $matches)) {
                    continue;
                }
                foreach ($matches[1] as $value) {
                    foreach (self::aliasList($value) as $alias) {
                        self::queueBundleAssets($alias, false);
                    }
                }
            }
        }

        foreach (self::aliasList($_GET['variants'] ?? []) as $variant) {
            self::queueBundleAssets($variant, true);
        }

        $variant = self::aliasKey((string) ($_GET['variant'] ?? $_GET['code_alias'] ?? ''));
        if ($variant !== '') {
            self::queueBundleAssets($variant, true);
        }

        $bookingAlias = self::aliasKey((string) ($_GET['booking_alias'] ?? $_GET['alias'] ?? ''));
        if ($bookingAlias !== '') {
            self::queueBundleAssets($bookingAlias, false);
        }
    }

    public static function printHeadAssets(): void
    {
        foreach (self::$pendingStyles as $alias => $styles) {
            echo '<style data-cliniko-code-bundle="' . esc_attr($alias) . '">' . $styles . '</style>';
        }

        self::$pendingStyles = [];
    }

    public static function printFrontendAssets(): void
    {
        foreach (self::$pendingScripts as $alias => $scripts) {
            echo '<script data-cliniko-code-bundle="' . esc_attr($alias) . '">' . $scripts . '</script>';
        }

        self::$pendingScripts = [];
    }

    private static function queueBundleAssets(string $alias, bool $fromUrl = false): void
    {
        if (isset(self::$pendingStyles[$alias]) || isset(self::$pendingScripts[$alias])) {
            return;
        }

        $bundle = self::enabledBundles()[$alias] ?? null;
        if (!is_array($bundle) || ($fromUrl && empty($bundle['url_selectable']))) {
            return;
        }

        $styles = '';
        $scripts = '';
        foreach ((array) ($bundle['snippets'] ?? []) as $snippet) {
            if (!is_array($snippet) || (array_key_exists('enabled', $snippet) && empty($snippet['enabled']))) {
                continue;
            }
            $code = (string) ($snippet['code'] ?? '');
            $type = strtolower((string) ($snippet['type'] ?? ''));
            if ($type === 'css') {
                $styles .= $code . "\n";
            } elseif ($type === 'js') {
                $scripts .= $code . "\n";
            }
        }

        if ($styles !== '') {
            self::$pendingStyles[$alias] = (self::$pendingStyles[$alias] ?? '') . $styles;
        }
        if ($scripts !== '') {
            self::$pendingScripts[$alias] = (self::$pendingScripts[$alias] ?? '') . $scripts;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public static function bundles(): array
    {
        $bundles = get_option(self::OPTION_KEY, []);
        return is_array($bundles) ? $bundles : [];
    }

    /** @return array<string,array<string,mixed>> */
    public static function enabledBundles(): array
    {
        return array_filter(
            self::bundles(),
            static fn(array $bundle): bool => !array_key_exists('enabled', $bundle) || !empty($bundle['enabled'])
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $bundles = self::bundles();
        $editing = sanitize_key((string) ($_GET['action'] ?? '')) === 'edit';
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        $bundle = $editing && isset($bundles[$id]) ? $bundles[$id] : [
            'name' => '', 'enabled' => true, 'url_selectable' => true, 'snippets' => [],
        ];
        if (!$editing && sanitize_key((string) ($_GET['action'] ?? '')) !== 'new') {
            self::renderList($bundles);
            return;
        }
        self::renderEditor($id, $bundle);
    }

    /** @param array<string,array<string,mixed>> $bundles */
    private static function renderList(array $bundles): void
    {
        ?>
        <div class="wrap cliniko-template-builder-page">
            <h1 class="wp-heading-inline">Custom Code</h1>
            <a class="page-title-action" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_CUSTOM_CODE, ['action' => 'new'])); ?>">Add New</a>
            <p>Create reusable HTML, CSS, and JavaScript bundles for booking shortcodes. Server-side PHP is intentionally not supported.</p>
            <div class="notice notice-info inline">
                <p><strong>How to apply custom code</strong></p>
                <p>Use a bundle alias in a shortcode with <code>code_alias="your-alias"</code>. If a booking alias has the same name as a bundle, that bundle is applied automatically.</p>
                <p>You can apply additional bundles from the page URL using the comma-separated <code>variants</code> parameter:</p>
                <p><code>?booking_alias=online-prescriptions&amp;variants=skin-treatment,summer-offer</code></p>
                <p>The booking bundle is applied first, followed by the variants from left to right. Each URL-selectable bundle must have <strong>Allow selection through <code>?variants=...</code></strong> enabled.</p>
            </div>
            <table class="widefat striped" style="max-width:1200px;margin-top:20px">
                <thead><tr><th>Name</th><th>Alias</th><th>Snippets</th><th>URL selectable</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($bundles === []) : ?><tr><td colspan="6">No code bundles have been created.</td></tr>
                <?php else : foreach ($bundles as $alias => $bundle) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($bundle['name'] ?? $alias)); ?></strong><?php if (empty($bundle['enabled'])) : ?> <em>(disabled)</em><?php endif; ?></td>
                        <td><code><?php echo esc_html((string) $alias); ?></code></td>
                        <td><?php echo count(is_array($bundle['snippets'] ?? null) ? $bundle['snippets'] : []); ?></td>
                        <td><?php echo !empty($bundle['url_selectable']) ? 'Yes' : 'No'; ?></td>
                        <td><code>code_alias="<?php echo esc_attr((string) $alias); ?>"</code></td>
                        <td><a href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_CUSTOM_CODE, ['action' => 'edit', 'id' => $alias])); ?>">Edit</a> |
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::DELETE_ACTION . '&id=' . rawurlencode((string) $alias)), 'delete_custom_code_' . $alias)); ?>" onclick="return confirm('Delete this code bundle?');">Delete</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** @param array<string,mixed> $bundle */
    private static function renderEditor(string $id, array $bundle): void
    {
        $snippets = is_array($bundle['snippets'] ?? null) ? $bundle['snippets'] : [];
        if ($snippets === []) {
            $snippets[] = ['name' => '', 'type' => 'css', 'placement' => 'after_form', 'code' => '', 'enabled' => true];
        }
        ?>
        <div class="wrap cliniko-template-builder-page">
            <h1><?php echo $id !== '' ? 'Edit Custom Code Bundle' : 'Add Custom Code Bundle'; ?></h1>
            <p>Bundle multiple ordered snippets under one alias. HTML is inserted only at the selected booking-form slot.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-custom-code-editor>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('save_custom_code'); ?>
                <table class="form-table" style="max-width:900px">
                    <tr><th><label for="custom-code-name">Name</label></th><td><input class="regular-text" required id="custom-code-name" name="name" value="<?php echo esc_attr((string) ($bundle['name'] ?? '')); ?>"></td></tr>
                    <tr><th><label for="custom-code-alias">Alias</label></th><td><input class="regular-text" required id="custom-code-alias" name="alias" value="<?php echo esc_attr($id); ?>" placeholder="prescription-booking"><p class="description">Use this alias with <code>code_alias</code> in shortcodes. Public URLs can use <code>variants=alias-one,alias-two</code>.</p></td></tr>
                    <tr><th>Availability</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($bundle['enabled'])); ?>> Enabled</label><br><label><input type="checkbox" name="url_selectable" value="1" <?php checked(!empty($bundle['url_selectable'])); ?>> Allow selection through <code>?variants=...</code></label></td></tr>
                </table>
                <h2>Bundle snippets</h2>
                <div data-custom-code-snippets>
                    <?php foreach ($snippets as $index => $snippet) : self::renderSnippet($index, is_array($snippet) ? $snippet : []); endforeach; ?>
                </div>
                <p><button type="button" class="button" data-add-custom-code-snippet>Add snippet</button></p>
                <p><button class="button button-primary" type="submit">Save bundle</button> <a class="button" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_CUSTOM_CODE)); ?>">Cancel</a></p>
            </form>
        </div>
        <template data-custom-code-snippet-template><?php self::renderSnippet('__INDEX__', []); ?></template>
        <script>
        (function(){const root=document.querySelector('[data-custom-code-editor]'),list=root&&root.querySelector('[data-custom-code-snippets]'),tpl=document.querySelector('[data-custom-code-snippet-template]');if(!root||!list||!tpl)return;let index=list.querySelectorAll('[data-custom-code-snippet]').length;root.querySelector('[data-add-custom-code-snippet]').addEventListener('click',function(){list.insertAdjacentHTML('beforeend',tpl.innerHTML.replaceAll('__INDEX__',String(index++)));});list.addEventListener('click',function(e){if(e.target.matches('[data-remove-custom-code-snippet]'))e.target.closest('[data-custom-code-snippet]').remove();});}());
        </script>
        <?php
    }

    /** @param array<string,mixed> $snippet */
    private static function renderSnippet($index, array $snippet): void
    {
        $type = in_array(($snippet['type'] ?? 'css'), ['html', 'css', 'js'], true) ? $snippet['type'] : 'css';
        $placement = in_array(($snippet['placement'] ?? 'after_form'), ['before_form', 'after_form', 'before_appointment', 'after_appointment', 'before_payment', 'after_payment'], true) ? $snippet['placement'] : 'after_form';
        ?>
        <fieldset class="postbox" data-custom-code-snippet style="padding:16px;max-width:1000px;margin:16px 0"><legend><strong>Snippet</strong></legend>
            <p><label>Name <input class="regular-text" name="snippets[<?php echo esc_attr((string) $index); ?>][name]" value="<?php echo esc_attr((string) ($snippet['name'] ?? '')); ?>"></label>
            <label>Type <select name="snippets[<?php echo esc_attr((string) $index); ?>][type]"><option value="html" <?php selected($type, 'html'); ?>>HTML</option><option value="css" <?php selected($type, 'css'); ?>>CSS</option><option value="js" <?php selected($type, 'js'); ?>>JavaScript</option></select></label>
            <label>Placement <select name="snippets[<?php echo esc_attr((string) $index); ?>][placement]"><option value="before_form" <?php selected($placement, 'before_form'); ?>>Before form</option><option value="after_form" <?php selected($placement, 'after_form'); ?>>After form</option><option value="before_appointment" <?php selected($placement, 'before_appointment'); ?>>Before appointment step</option><option value="after_appointment" <?php selected($placement, 'after_appointment'); ?>>After appointment step</option><option value="before_payment" <?php selected($placement, 'before_payment'); ?>>Before payment</option><option value="after_payment" <?php selected($placement, 'after_payment'); ?>>After payment</option></select></label>
            <label><input type="checkbox" name="snippets[<?php echo esc_attr((string) $index); ?>][enabled]" value="1" <?php checked(!isset($snippet['enabled']) || !empty($snippet['enabled'])); ?>> Enabled</label>
            <button type="button" class="button-link-delete" data-remove-custom-code-snippet>Remove</button></p>
            <textarea class="large-text code" rows="8" name="snippets[<?php echo esc_attr((string) $index); ?>][code]" placeholder="Paste trusted <?php echo esc_attr($type); ?> here..."><?php echo esc_textarea((string) ($snippet['code'] ?? '')); ?></textarea>
        </fieldset>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('save_custom_code');
        $alias = sanitize_key((string) ($_POST['alias'] ?? ''));
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if ($alias === '' || $name === '') wp_die('A name and alias are required.');
        $snippets = [];
        foreach (is_array($_POST['snippets'] ?? null) ? $_POST['snippets'] : [] as $snippet) {
            if (!is_array($snippet)) continue;
            $type = in_array(($snippet['type'] ?? ''), ['html', 'css', 'js'], true) ? $snippet['type'] : 'css';
            $placement = in_array(($snippet['placement'] ?? ''), ['before_form', 'after_form', 'before_appointment', 'after_appointment', 'before_payment', 'after_payment'], true) ? $snippet['placement'] : 'after_form';
            $code = trim((string) wp_unslash($snippet['code'] ?? ''));
            if ($code === '') continue;
            $snippets[] = ['name' => sanitize_text_field((string) ($snippet['name'] ?? '')), 'type' => $type, 'placement' => $placement, 'code' => $code, 'enabled' => !empty($snippet['enabled'])];
        }
        $bundles = self::bundles();
        if ($id = sanitize_key((string) ($_POST['id'] ?? ''))) unset($bundles[$id]);
        $bundles[$alias] = ['name' => $name, 'enabled' => !empty($_POST['enabled']), 'url_selectable' => !empty($_POST['url_selectable']), 'snippets' => $snippets];
        update_option(self::OPTION_KEY, $bundles, false);
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_CUSTOM_CODE, ['saved' => 1]));
        exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_custom_code_' . $id);
        $bundles = self::bundles(); unset($bundles[$id]); update_option(self::OPTION_KEY, $bundles, false);
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_CUSTOM_CODE, ['deleted' => 1])); exit;
    }

    /** @param array<string,mixed> $attributes */
    public static function applyToMarkup(string $markup, array $attributes): string
    {
        $placements = ['before_form' => '', 'after_form' => '', 'before_appointment' => '', 'after_appointment' => '', 'before_payment' => '', 'after_payment' => ''];

        $aliases = [];
        $aliases = array_merge($aliases, self::aliasList($attributes['booking_alias'] ?? []));
        $aliases = array_merge($aliases, self::aliasList($_GET['booking_alias'] ?? $_GET['alias'] ?? []));
        $aliases = array_merge($aliases, self::aliasList($attributes['code_alias'] ?? []));
        $aliases = array_merge($aliases, self::aliasList($_GET['variants'] ?? []));
        $aliases = array_merge($aliases, self::aliasList($_GET['variant'] ?? $_GET['code_alias'] ?? []));
        $aliases = array_values(array_unique($aliases));

        if ($aliases === []) {
            return $markup;
        }

        $bundles = self::enabledBundles();
        foreach ($aliases as $alias) {
            $bundle = $bundles[$alias] ?? null;
            $isUrlVariant = in_array($alias, self::aliasList($_GET['variants'] ?? []), true)
                || $alias === self::aliasKey((string) ($_GET['variant'] ?? ''))
                || $alias === self::aliasKey((string) ($_GET['code_alias'] ?? ''));

            if (!is_array($bundle) || ($isUrlVariant && empty($bundle['url_selectable']))) {
                continue;
            }

            foreach ((array) ($bundle['snippets'] ?? []) as $snippet) {
                if (!is_array($snippet) || (array_key_exists('enabled', $snippet) && empty($snippet['enabled']))) continue;
                $code = (string) ($snippet['code'] ?? '');
                $type = strtolower((string) ($snippet['type'] ?? ''));
                $placement = (string) ($snippet['placement'] ?? 'after_form');
                if ($type === 'html') {
                    $placements[$placement] = ($placements[$placement] ?? '') . $code;
                }
            }

            self::queueBundleAssets($alias);
        }

        foreach ($placements as $placement => $code) $markup = str_replace('<!-- cliniko-code-slot:' . $placement . ' -->', $code, $markup);
        return $markup;
    }
}
