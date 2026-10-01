<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Service\PatientLinkService;

if (!defined('ABSPATH')) {
    exit;
}

/** Public, token-protected patient-account verification landing page. */
final class PatientVerificationConfirmation
{
    private const SHORTCODE = 'cliniko_patient_verification';
    private const CONFIRM_ACTION = 'wp_cliniko_confirm_patient_verification';
    private const DEFAULT_LOGIN_DURATION = 3600;
    private const MAX_LOGIN_DURATION = 86400;

    public static function init(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'render']);
        add_action('admin_post_' . self::CONFIRM_ACTION, [self::class, 'confirm']);
        add_action('admin_post_nopriv_' . self::CONFIRM_ACTION, [self::class, 'confirm']);
        add_action('admin_post_' . PatientLinkService::EMAIL_VERIFY_ACTION, [self::class, 'confirmDirect']);
        add_action('admin_post_nopriv_' . PatientLinkService::EMAIL_VERIFY_ACTION, [self::class, 'confirmDirect']);
    }

    /** @param mixed $attributes */
    public static function render($attributes = []): string
    {
        if (!headers_sent()) {
            nocache_headers();
        }

        $attributes = shortcode_atts([
            'title' => 'Verify your patient account',
            'button_label' => 'Verify my account',
            'success_url' => home_url('/'),
            'login_after_verification' => 'no',
        ], is_array($attributes) ? $attributes : [], self::SHORTCODE);

        self::enqueueAssets();
        $returnTo = self::currentUrl();
        $status = sanitize_key((string) ($_GET['cliniko_patient_verification_status'] ?? ''));
        $confirmationRequested = sanitize_key((string) ($_GET['cliniko_patient_verification'] ?? '')) === 'confirm';
        $userId = absint($_GET['user_id'] ?? 0);
        $token = sanitize_text_field((string) wp_unslash($_GET['token'] ?? ''));
        $validShape = $userId > 0 && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
        $terminalStatus = in_array($status, ['expired', 'invalid', 'attempts_exceeded', 'revoked', 'pending', 'login_conflict'], true);
        $loginAfterVerification = self::isEnabled($attributes['login_after_verification']);

        ob_start();
        ?>
        <section class="cliniko-patient-verification">
            <header class="cliniko-patient-verification__header">
                <span class="cliniko-patient-verification__eyebrow">Patient account</span>
                <h2><?php echo esc_html((string) $attributes['title']); ?></h2>
            </header>

            <?php if ($status !== '') : ?>
                <p class="cliniko-patient-verification__message <?php echo $status === 'verified' ? 'is-success' : 'is-error'; ?>"><?php echo esc_html(self::statusMessage($status)); ?></p>
            <?php endif; ?>

            <?php if ($confirmationRequested && $validShape && !$terminalStatus) : ?>
                <p<?php echo $loginAfterVerification ? ' class="cliniko-patient-verification__progress" role="status"' : ''; ?>><?php echo esc_html($loginAfterVerification
                    ? 'Verifying your patient account and opening your dashboard…'
                    : 'Confirm your email address to link and activate your patient portal account.'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-patient-verification__form"<?php echo $loginAfterVerification ? ' data-cliniko-patient-verification-auto-submit' : ''; ?>>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::CONFIRM_ACTION); ?>">
                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                    <input type="hidden" name="return_to" value="<?php echo esc_attr($returnTo); ?>">
                    <input type="hidden" name="success_url" value="<?php echo esc_attr(wp_validate_redirect((string) $attributes['success_url'], home_url('/'))); ?>">
                    <input type="hidden" name="login_after_verification" value="<?php echo $loginAfterVerification ? 'yes' : 'no'; ?>">
                    <?php wp_nonce_field('confirm_patient_verification_' . $userId); ?>
                    <button type="submit" class="cliniko-patient-verification__button"><?php echo esc_html((string) $attributes['button_label']); ?></button>
                </form>
                <?php if ($loginAfterVerification) : ?>
                    <script>
                        (() => {
                            const root = document.currentScript.closest('.cliniko-patient-verification');
                            const form = root && root.querySelector('[data-cliniko-patient-verification-auto-submit]');
                            if (form instanceof HTMLFormElement) {
                                window.setTimeout(() => form.submit(), 0);
                            }
                        })();
                    </script>
                    <noscript>
                        <style>.cliniko-patient-verification__form[data-cliniko-patient-verification-auto-submit] .cliniko-patient-verification__button{display:inline-block}</style>
                        <p>JavaScript is disabled. Press the verification button to continue.</p>
                    </noscript>
                <?php endif; ?>
            <?php elseif (!$confirmationRequested && $status === '') : ?>
                <p class="cliniko-patient-verification__message">Open the secure verification link in your most recent patient-account email.</p>
            <?php elseif (!$validShape && $status === '') : ?>
                <p class="cliniko-patient-verification__message is-error">This verification link is invalid. Open the most recent verification email or request a replacement link.</p>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function confirm(): void
    {
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('confirm_patient_verification_' . $userId);
        self::verifyAndRedirect(
            $userId,
            sanitize_text_field((string) wp_unslash($_POST['token'] ?? '')),
            wp_validate_redirect((string) wp_unslash($_POST['return_to'] ?? ''), home_url('/')),
            wp_validate_redirect((string) wp_unslash($_POST['success_url'] ?? ''), home_url('/')),
            self::isEnabled($_POST['login_after_verification'] ?? 'no')
        );
    }

    /** Backward-safe browser fallback when no shortcode landing page is configured. */
    public static function confirmDirect(): void
    {
        self::verifyAndRedirect(
            absint($_GET['user_id'] ?? 0),
            sanitize_text_field((string) wp_unslash($_GET['token'] ?? '')),
            home_url('/'),
            home_url('/'),
            false
        );
    }

    private static function verifyAndRedirect(
        int $userId,
        string $token,
        string $returnTo,
        string $successUrl,
        bool $loginAfterVerification
    ): void
    {
        $patientLinks = new PatientLinkService();
        $result = $patientLinks->verify($userId, $token);
        if ($result['ok']) {
            if ($loginAfterVerification && !self::loginVerifiedPatient($userId, $patientLinks)) {
                self::redirectWithStatus(self::cleanVerificationUrl($returnTo), 'login_conflict');
            }

            nocache_headers();
            wp_safe_redirect(add_query_arg('cliniko_patient_verified', '1', $successUrl));
            exit;
        }

        $status = self::resultStatus((int) $result['http_status'], (string) $result['status']);
        self::redirectWithStatus($returnTo, $status);
    }

    private static function loginVerifiedPatient(int $userId, PatientLinkService $patientLinks): bool
    {
        $currentUserId = (int) get_current_user_id();
        if ($currentUserId > 0 && $currentUserId !== $userId) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$patientLinks->isVerifiedUser($userId) || headers_sent()) {
            return false;
        }

        $duration = max(300, min(
            self::MAX_LOGIN_DURATION,
            (int) apply_filters('wp_cliniko_patient_verification_login_duration', self::DEFAULT_LOGIN_DURATION, $userId)
        ));
        $expirationFilter = static function ($length, $cookieUserId) use ($duration, $userId): int {
            return (int) $cookieUserId === $userId ? $duration : (int) $length;
        };

        add_filter('auth_cookie_expiration', $expirationFilter, 999, 3);
        try {
            wp_clear_auth_cookie();
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, false, is_ssl());
        } finally {
            remove_filter('auth_cookie_expiration', $expirationFilter, 999);
        }
        do_action('wp_login', (string) $user->user_login, $user);
        return true;
    }

    private static function redirectWithStatus(string $returnTo, string $status): void
    {
        $returnTo = remove_query_arg('cliniko_patient_verification_status', $returnTo);
        nocache_headers();
        wp_safe_redirect(add_query_arg('cliniko_patient_verification_status', $status, $returnTo));
        exit;
    }

    private static function cleanVerificationUrl(string $url): string
    {
        return remove_query_arg([
            'cliniko_patient_verification',
            'cliniko_patient_verification_status',
            'user_id',
            'token',
        ], $url);
    }

    /** @param mixed $value */
    private static function isEnabled($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function resultStatus(int $httpStatus, string $status): string
    {
        if ($httpStatus === 410) {
            return 'expired';
        }
        if ($httpStatus === 429) {
            return 'attempts_exceeded';
        }
        if ($httpStatus === 503) {
            return 'unavailable';
        }
        if ($httpStatus === 202 || $status === PatientLinkService::STATUS_PENDING) {
            return 'pending';
        }
        if ($status === PatientLinkService::STATUS_REVOKED) {
            return 'revoked';
        }
        return 'invalid';
    }

    private static function statusMessage(string $status): string
    {
        return match ($status) {
            'verified' => 'Your patient account has been verified.',
            'expired' => 'This verification link has expired. Sign in with your social provider to request a replacement email.',
            'attempts_exceeded' => 'This verification link can no longer be used. Sign in with your social provider to request a replacement email.',
            'unavailable' => 'Cliniko could not be reached. Please try the verification button again shortly.',
            'revoked' => 'The account could not be linked because its email no longer matches the Cliniko patient.',
            'pending' => 'Your email was verified, but the account is still awaiting approval. Please contact the clinic.',
            'login_conflict' => 'Your account was verified, but this browser is signed in as another user. Sign out before entering the patient portal.',
            default => 'This verification link is invalid or has already been used.',
        };
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

    private static function enqueueAssets(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/patient-verification.css';
        wp_enqueue_style(
            'cliniko-patient-verification',
            plugins_url('../../../assets/patient-verification.css', __FILE__),
            [],
            is_file($path) ? (string) filemtime($path) : null
        );
    }
}
