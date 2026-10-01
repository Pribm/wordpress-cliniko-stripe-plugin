<?php

namespace App\Admin\Modules\AccountBuilders\Forms;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

use App\Service\PatientFormHistoryService;
use App\Support\Auth;
use App\Support\Phtml;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientFormHistoryForms
{
    private const OPTION_KEY = 'wp_cliniko_patient_form_history_shortcodes';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_form_history_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_form_history_delete', [self::class, 'delete']);
        add_shortcode('cliniko_patient_forms', [self::class, 'renderShortcode']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if ($action === 'edit' || $action === 'new') {
            self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : '');
            return;
        }

        $forms = self::forms();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Patient Forms History</h1>
            <a href="<?php echo esc_url(self::url(['action' => 'new'])); ?>" class="page-title-action">Add New</a>
            <p>Create shortcodes that show forms previously completed by the logged-in Cliniko patient.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_patient_forms">Open styling guide</a></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Patient forms history shortcode saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Patient forms history shortcode deleted.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Name</th><th>Forms per page</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($forms === []) : ?>
                    <tr><td colspan="4">No patient forms history shortcodes have been created.</td></tr>
                <?php else : foreach ($forms as $id => $form) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($form['name'] ?? 'Patient forms')); ?></strong></td>
                        <td><?php echo esc_html((string) ($form['per_page'] ?? 5)); ?></td>
                        <td><code>[cliniko_patient_forms id="<?php echo esc_attr($id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_form_history_delete&id=' . rawurlencode($id)), 'delete_patient_form_history_' . $id)); ?>" onclick="return confirm('Delete this shortcode configuration?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderEditor(string $id): void
    {
        $form = self::forms()[$id] ?? [
            'name' => 'My patient forms',
            'per_page' => 5,
            'show_dates' => true,
            'empty_message' => 'You have not completed any patient forms yet.',
        ];
        $builderStyle = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.css';
        wp_enqueue_style(
            'cliniko-patient-account-form-builder',
                plugins_url('../../../assets/patient-account-form-builder.css', __FILE__),
            [],
            file_exists($builderStyle) ? (string) filemtime($builderStyle) : null
        );
        ?>
        <div class="wrap">
            <h1><?php echo $id !== '' ? 'Edit Patient Forms History' : 'Add Patient Forms History'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder">
                <input type="hidden" name="action" value="wp_cliniko_patient_form_history_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('save_patient_form_history'); ?>
                <div class="cliniko-form-builder__layout">
                    <main>
                        <input class="cliniko-form-builder__name" type="text" name="name" value="<?php echo esc_attr((string) $form['name']); ?>" placeholder="Module name" required>
                        <section class="cliniko-form-builder__panel">
                            <header><strong>Completed patient forms</strong></header>
                            <div data-builder-panel>
                                <p>Each form will show its Cliniko sections, questions, and the patient’s submitted answers. Results are always filtered by the authenticated patient ID.</p>
                                <div class="cliniko-form-builder__preview-form">
                                    <h3>Medical history</h3>
                                    <p><strong>Current medication</strong><br>Aspirin</p>
                                    <p><strong>Allergies</strong><br>Peanuts, Latex</p>
                                </div>
                            </div>
                        </section>
                    </main>
                    <aside class="cliniko-form-builder__sidebar">
                        <section class="cliniko-form-builder__sidebox">
                            <h2>Display</h2>
                            <p><label>Forms per page <input class="small-text" type="number" min="1" max="20" name="per_page" value="<?php echo esc_attr((string) $form['per_page']); ?>"></label></p>
                            <p><label><input type="checkbox" name="show_dates" value="1" <?php checked(!empty($form['show_dates'])); ?>> Show completed/created dates</label></p>
                            <p><label>Empty state message<textarea class="widefat" name="empty_message" rows="3"><?php echo esc_textarea((string) $form['empty_message']); ?></textarea></label></p>
                            <button type="submit" class="button button-primary button-large">Create / Update shortcode</button>
                        </section>
                        <section class="cliniko-form-builder__sidebox">
                            <h2>Shortcode</h2>
                            <?php if ($id !== '') : ?><code>[cliniko_patient_forms id="<?php echo esc_attr($id); ?>"]</code><?php else : ?><p>Save to generate the shortcode.</p><?php endif; ?>
                        </section>
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
        check_admin_referer('save_patient_form_history');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = sanitize_key('patient_forms_' . wp_generate_uuid4());
        }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $emptyMessage = sanitize_text_field((string) ($_POST['empty_message'] ?? ''));
        if ($name === '') {
            wp_die('Module name is required.');
        }

        $forms = self::forms();
        $forms[$id] = [
            'name' => $name,
            'per_page' => max(1, min(20, (int) ($_POST['per_page'] ?? 5))),
            'show_dates' => !empty($_POST['show_dates']),
            'empty_message' => $emptyMessage !== '' ? $emptyMessage : 'You have not completed any patient forms yet.',
        ];
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_patient_form_history_' . $id);
        $forms = self::forms();
        unset($forms[$id]);
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    /** @param array<string,mixed> $attributes */
    public static function renderShortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-patient-form-history__message is-error">Please log in to view your patient forms.</p>';
        }
        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $form = self::forms()[$id] ?? null;
        if (!is_array($form)) {
            return current_user_can('manage_options') ? '<p>Patient forms history shortcode not found.</p>' : '';
        }

        try {
            $patient = Auth::user();
            if ($patient === null) {
                return '<p class="cliniko-patient-form-history__message is-error">Patient not found.</p>';
            }
            $pageKey = 'cliniko_forms_page_' . substr(hash('sha256', $id), 0, 8);
            $page = max(1, (int) ($_GET[$pageKey] ?? 1));
            $result = (new PatientFormHistoryService())->forPatient(
                $patient,
                $page,
                (int) ($form['per_page'] ?? 5)
            );
            $style = __DIR__ . '/PatientFormHistoryForms/ShortCodeTemplates/patient-form-template.css';
            wp_enqueue_style(
                'cliniko-patient-form-history',
                plugins_url('PatientFormHistoryForms/ShortCodeTemplates/patient-form-template.css', __FILE__),
                ['cliniko-shortcode-components'],
                file_exists($style) ? (string) filemtime($style) : null
            );

            return Phtml::render(__DIR__ . '/PatientFormHistoryForms/ShortCodeTemplates/patient-form-history.phtml', [
                'configuration' => $form,
                'result' => $result,
                'pageKey' => $pageKey,
                'currentUrl' => self::currentUrl(),
            ]);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient form history shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-form-history__message is-error">Patient data is temporarily unavailable. Please try again shortly.</p>';
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function forms(): array
    {
        $forms = get_option(self::OPTION_KEY, []);
        return is_array($forms) ? $forms : [];
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(
            AccountBuilders::TAB_PATIENT_FORMS,
            array_merge(['forms_section' => AccountBuilders::FORMS_PATIENT_FORMS], $args)
        );
    }

    private static function currentUrl(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        $homePath = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        if ($homePath !== '' && $homePath !== '/' && str_starts_with($requestUri, $homePath . '/')) {
            $requestUri = substr($requestUri, strlen($homePath));
        }

        return wp_validate_redirect(home_url('/' . ltrim($requestUri, '/')), home_url('/'));
    }
}
