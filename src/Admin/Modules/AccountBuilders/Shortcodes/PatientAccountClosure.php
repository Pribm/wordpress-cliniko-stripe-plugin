<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Service\PatientAccountClosureService;
use App\Service\PatientLinkService;

if (!defined('ABSPATH')) {
    exit;
}

/** Patient-facing, email-confirmed portal account closure shortcode. */
final class PatientAccountClosure
{
    private const SHORTCODE = 'cliniko_patient_close_account';
    private const REQUEST_ACTION = 'wp_cliniko_request_account_closure';
    private const CONFIRM_ACTION = 'wp_cliniko_confirm_account_closure';

    public static function init(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'render']);
        add_action('admin_post_' . self::REQUEST_ACTION, [self::class, 'requestClosure']);
        add_action('admin_post_' . self::CONFIRM_ACTION, [self::class, 'confirmClosure']);
    }

    /** @param mixed $attributes */
    public static function render($attributes = []): string
    {
        if (!headers_sent()) {
            nocache_headers();
        }

        $attributes = shortcode_atts([
            'title' => 'Close patient portal account',
            'button_label' => 'Send closure confirmation',
            'display' => 'inline',
            'trigger_label' => 'Close my portal account',
            'success_url' => home_url('/'),
        ], is_array($attributes) ? $attributes : [], self::SHORTCODE);

        $modalDisplay = sanitize_key((string) $attributes['display']) === 'modal';
        self::enqueueAssets($modalDisplay);
        $returnTo = self::currentUrl();
        if (!is_user_logged_in()) {
            $loginUrl = function_exists('um_get_core_page')
                ? (string) um_get_core_page('login')
                : wp_login_url();
            $loginUrl = add_query_arg('redirect_to', $returnTo, $loginUrl);
            return '<section class="cliniko-account-closure"><p class="cliniko-account-closure__message">You must be logged in to close your portal account. <a href="'
                . esc_url($loginUrl) . '">Log in</a>.</p></section>';
        }

        $userId = (int) get_current_user_id();
        $patientLinks = new PatientLinkService();
        if (!$patientLinks->userHasConfiguredRole($userId)) {
            return current_user_can('manage_options')
                ? '<section class="cliniko-account-closure"><p class="cliniko-account-closure__message is-error">This shortcode is available only to configured patient roles.</p></section>'
                : '';
        }

        $status = sanitize_key((string) ($_GET['cliniko_account_closure_status'] ?? ''));
        $requestedUserId = absint($_GET['user_id'] ?? 0);
        $token = sanitize_text_field((string) wp_unslash($_GET['token'] ?? ''));
        $confirmationRequested = sanitize_key((string) ($_GET['cliniko_account_closure'] ?? '')) === 'confirm';
        $dialogId = wp_unique_id('cliniko-account-closure-dialog-');
        $titleId = $dialogId . '-title';
        $autoOpen = $modalDisplay && ($confirmationRequested || $status !== '');

        ob_start();
        ?>
        <?php if ($modalDisplay) : ?>
            <div class="cliniko-account-closure-modal-root" data-cliniko-account-closure-modal<?php echo $autoOpen ? ' data-auto-open="true"' : ''; ?>>
                <button type="button" class="cliniko-account-closure__trigger" data-account-closure-open aria-controls="<?php echo esc_attr($dialogId); ?>" aria-expanded="false"><?php echo esc_html((string) $attributes['trigger_label']); ?></button>
                <div class="cliniko-account-closure-modal" data-account-closure-overlay hidden>
                    <div class="cliniko-account-closure-modal__backdrop" data-account-closure-close></div>
                    <div id="<?php echo esc_attr($dialogId); ?>" class="cliniko-account-closure-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($titleId); ?>" tabindex="-1" data-account-closure-dialog>
                        <button type="button" class="cliniko-account-closure-modal__close" data-account-closure-close aria-label="Close account closure dialog">&times;</button>
        <?php endif; ?>
        <section class="cliniko-account-closure<?php echo $modalDisplay ? ' is-modal' : ''; ?>" aria-labelledby="<?php echo esc_attr($titleId); ?>">
            <header class="cliniko-account-closure__header">
                <span class="cliniko-account-closure__eyebrow">Account and privacy</span>
                <h2 id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html((string) $attributes['title']); ?></h2>
            </header>

            <?php if ($status === 'requested') : ?>
                <p class="cliniko-account-closure__message is-success">Check your email. The confirmation link expires after 30 minutes.</p>
            <?php elseif ($status !== '') : ?>
                <p class="cliniko-account-closure__message is-error"><?php echo esc_html(self::errorMessage($status)); ?></p>
            <?php endif; ?>

            <?php if ($confirmationRequested) : ?>
                <?php if ((new PatientAccountClosureService($patientLinks))->isValidConfirmation($userId, $requestedUserId, $token)) : ?>
                    <div class="cliniko-account-closure__warning">
                        <h3>Final confirmation</h3>
                        <p>This permanently deletes your WordPress patient portal account and signs out every session. You will need to register again to use the portal in future.</p>
                        <p><strong>Your Cliniko health record is not deleted.</strong> Appointments, treatment notes, clinical documents, invoices, and payments remain with the clinic.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-account-closure__form">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::CONFIRM_ACTION); ?>">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $requestedUserId); ?>">
                        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                        <input type="hidden" name="return_to" value="<?php echo esc_attr($returnTo); ?>">
                        <input type="hidden" name="success_url" value="<?php echo esc_attr(wp_validate_redirect((string) $attributes['success_url'], home_url('/'))); ?>">
                        <?php wp_nonce_field('confirm_patient_account_closure_' . $requestedUserId); ?>
                        <button type="submit" class="cliniko-account-closure__button is-danger">Permanently close my portal account</button>
                    </form>
                <?php else : ?>
                    <p class="cliniko-account-closure__message is-error">This confirmation link is invalid, expired, or belongs to a different signed-in account. Request a new link below.</p>
                    <?php self::renderRequestForm($returnTo, (string) $attributes['button_label']); ?>
                <?php endif; ?>
            <?php else : ?>
                <div class="cliniko-account-closure__warning">
                    <h3>Before you close the account</h3>
                    <ul>
                        <li>Your portal login, Ultimate Member profile, social-login link, and locally cached patient data will be removed.</li>
                        <li>This does not cancel appointments or delete your Cliniko medical and financial records.</li>
                        <li>For access, correction, or deletion of clinical records, contact the clinic directly.</li>
                    </ul>
                </div>
                <?php self::renderRequestForm($returnTo, (string) $attributes['button_label']); ?>
            <?php endif; ?>
        </section>
        <?php if ($modalDisplay) : ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    public static function requestClosure(): void
    {
        if (!is_user_logged_in()) {
            wp_die('You must be logged in.', 'Unauthorized', ['response' => 403]);
        }
        check_admin_referer('request_patient_account_closure');

        $returnTo = wp_validate_redirect(
            (string) wp_unslash($_POST['return_to'] ?? ''),
            home_url('/')
        );
        if ((string) ($_POST['acknowledge'] ?? '') !== 'yes') {
            self::redirectWithStatus($returnTo, 'acknowledgement_required');
        }

        $result = (new PatientAccountClosureService())->request(
            (int) get_current_user_id(),
            $returnTo
        );
        self::redirectWithStatus($returnTo, $result['status']);
    }

    public static function confirmClosure(): void
    {
        if (!is_user_logged_in()) {
            wp_die('You must be logged in.', 'Unauthorized', ['response' => 403]);
        }

        $requestedUserId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('confirm_patient_account_closure_' . $requestedUserId);
        $token = sanitize_text_field((string) wp_unslash($_POST['token'] ?? ''));
        $returnTo = wp_validate_redirect(
            (string) wp_unslash($_POST['return_to'] ?? ''),
            home_url('/')
        );
        $successUrl = wp_validate_redirect(
            (string) wp_unslash($_POST['success_url'] ?? ''),
            home_url('/')
        );

        $result = (new PatientAccountClosureService())->close(
            (int) get_current_user_id(),
            $requestedUserId,
            $token
        );
        if (!$result['ok']) {
            self::redirectWithStatus($returnTo, $result['status']);
        }

        wp_safe_redirect(add_query_arg('cliniko_account_closed', '1', $successUrl));
        exit;
    }

    private static function renderRequestForm(string $returnTo, string $buttonLabel): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-account-closure__form">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::REQUEST_ACTION); ?>">
            <input type="hidden" name="return_to" value="<?php echo esc_attr($returnTo); ?>">
            <?php wp_nonce_field('request_patient_account_closure'); ?>
            <label class="cliniko-account-closure__acknowledgement">
                <input type="checkbox" name="acknowledge" value="yes" required>
                <span>I understand this closes only my online patient portal account and does not delete my Cliniko health record.</span>
            </label>
            <button type="submit" class="cliniko-account-closure__button"><?php echo esc_html($buttonLabel); ?></button>
        </form>
        <?php
    }

    private static function currentUrl(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '/';
        $homeParts = wp_parse_url(home_url('/'));
        if (!is_array($homeParts) || empty($homeParts['host'])) {
            return home_url('/');
        }

        $origin = (string) ($homeParts['scheme'] ?? 'https') . '://' . (string) $homeParts['host'];
        if (!empty($homeParts['port'])) {
            $origin .= ':' . (int) $homeParts['port'];
        }

        return wp_validate_redirect($origin . '/' . ltrim($requestUri, '/'), home_url('/'));
    }

    private static function redirectWithStatus(string $returnTo, string $status): void
    {
        $returnTo = remove_query_arg('cliniko_account_closure_status', $returnTo);
        wp_safe_redirect(add_query_arg(
            'cliniko_account_closure_status',
            sanitize_key($status),
            $returnTo
        ));
        exit;
    }

    private static function errorMessage(string $status): string
    {
        return match ($status) {
            'rate_limited' => 'Please wait before requesting another confirmation email.',
            'email_failed' => 'The confirmation email could not be sent. Please try again later.',
            'acknowledgement_required' => 'Confirm that you understand what account closure affects.',
            'invalid' => 'The closure confirmation is invalid or expired. Request a new email.',
            'multisite' => 'Please contact the clinic to close this account.',
            'not_allowed' => 'This account cannot be closed from the patient portal.',
            default => 'The account-closure request could not be completed.',
        };
    }

    private static function enqueueAssets(bool $modalDisplay = false): void
    {
        $path = dirname(__DIR__, 3) . '/assets/patient-account-closure.css';
        wp_enqueue_style(
            'cliniko-patient-account-closure',
            plugins_url('../../../assets/patient-account-closure.css', __FILE__),
            [],
            is_file($path) ? (string) filemtime($path) : null
        );
        if (!$modalDisplay) {
            return;
        }

        $scriptPath = dirname(__DIR__, 3) . '/assets/patient-account-closure.js';
        wp_enqueue_script(
            'cliniko-patient-account-closure',
            plugins_url('../../../assets/patient-account-closure.js', __FILE__),
            [],
            is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
            true
        );
    }
}
