<?php
namespace App\Service;

use App\Admin\Modules\Credentials;

if (!defined('ABSPATH')) {
    exit;
}

class NotificationService
{
    private const SUCCESS_OPTION_ENABLED = 'wp_cliniko_send_email_on_success';
    private const SUCCESS_OPTION_TEMPLATE = 'wp_cliniko_success_email_tpl';

    private const FAILURE_OPTION_ENABLED = 'wp_cliniko_send_email_on_failure';
    private const FAILURE_OPTION_TEMPLATE = 'wp_cliniko_failure_email_tpl';

    public function sendSuccess(array $args, array $patient, string $paymentRef, ?int $amountCents): void
    {
        // check if user enabled it in Elementor
        if (get_option(self::SUCCESS_OPTION_ENABLED) !== 'yes') {
            error_log("[NotificationService] Success email disabled for $paymentRef");
            return;
        }

        $this->dispatchMail(
            $args,
            $patient,
            $paymentRef,
            $amountCents,
            self::SUCCESS_OPTION_TEMPLATE,
            'Your request has been confirmed',
            'cliniko_notify_success_',
            true
        );
    }

    public function sendFailure(array $args, array $patient, string $paymentRef, ?int $amountCents): void
    {
        if (get_option(self::FAILURE_OPTION_ENABLED) !== 'yes') {
            error_log("[NotificationService] Failure email disabled for $paymentRef");
            return;
        }

        $this->dispatchMail(
            $args,
            $patient,
            $paymentRef,
            $amountCents,
            self::FAILURE_OPTION_TEMPLATE,
            'We could not complete your request',
            'cliniko_notify_failure_',
            false
        );
    }

    private function dispatchMail(
        array $args,
        array $patient,
        string $paymentRef,
        ?int $amountCents,
        string $templateOption,
        string $fallbackSubject,
        string $notifyKeyPrefix,
        bool $isSuccess
    ): void {
        $toEmail = (string) ($patient['email'] ?? '');
        if (!$toEmail) {
            error_log("[NotificationService] No email found for ref=$paymentRef");
            return;
        }

        $notifyKey = $notifyKeyPrefix . md5($paymentRef);
        if (get_transient($notifyKey)) {
            error_log("[NotificationService] Email suppressed (already sent) for $paymentRef");
            return;
        }

        $subject = $fallbackSubject;
        $body = $this->renderTemplate($templateOption, $args, $patient, $paymentRef, $amountCents, $isSuccess);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $mailOk = wp_mail($toEmail, $subject, $body, $headers);

        error_log("[NotificationService] Email " . ($mailOk ? 'SENT' : 'NOT SENT') . " to $toEmail for $paymentRef");

        if ($mailOk) {
            set_transient($notifyKey, 1, 86400);
        }
    }

    private function renderTemplate(
        string $optionKey,
        array $args,
        array $patient,
        string $paymentRef,
        ?int $amountCents,
        bool $isSuccess
    ): string {
        $tpl = get_option($optionKey, '');
        $amountTxt = is_int($amountCents) ? number_format($amountCents / 100, 2) : '';

        $vars = [
            '{first_name}'        => esc_html((string) ($patient['first_name'] ?? '')),
            '{last_name}'         => esc_html((string) ($patient['last_name'] ?? '')),
            '{email}'             => esc_html((string) ($patient['email'] ?? '')),
            '{amount}'            => $amountTxt,
            '{currency}'          => strtoupper((string) ($args['currency'] ?? 'AUD')),
            '{payment_reference}' => $paymentRef,
            '{appointment_label}' => (string) ($args['appointment_label'] ?? ''),
        ];

        if (!empty($tpl)) {
            return strtr($tpl, $vars);
        }

        // fallback if Elementor template not set
        $greet = $vars['{first_name}'] ? 'Hi ' . $vars['{first_name}'] : 'Hi';
        if ($isSuccess) {
            return "<p>$greet,</p>"
                . "<p>Your {$vars['{appointment_label}']} request has been confirmed.</p>"
                . ($amountTxt ? "<p>We received your payment of <strong>\${$amountTxt} {$vars['{currency}']}</strong>.</p>" : '')
                . "<p>Thank you for choosing Our service.</p>";
        }

        return "<p>$greet,</p>"
            . "<p>We were unable to complete your {$vars['{appointment_label}']} request.</p>"
            . ($amountTxt ? "<p>A refund of <strong>\${$amountTxt} {$vars['{currency}']}</strong> has been initiated.</p>" : '')
            . "<p>Please contact support if you need assistance.</p>";
    }

      /**
     * Generic email sender.
     *
     * @param string $toEmail   Recipient email address
     * @param string $subject   Email subject
     * @param string $message   Main message text (plain text or HTML-safe)
     * @param string $type      Either 'success' or 'error' to pick template style
     *
     * @return bool true on success, false otherwise
     */
    public function sendGenericEmail(string $toEmail, string $subject, string $message, string $type = 'success'): bool
    {
        if (empty($toEmail)) {
            error_log('[NotificationService] Generic email: missing recipient.');
            return false;
        }

        // Choose template
        $body = $this->buildGenericTemplate($message, $type);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($toEmail, $subject, $body, $headers);

        error_log("[NotificationService] Generic email " . ($sent ? 'SENT' : 'FAILED') . " to {$toEmail} ({$type})");

        return $sent;
    }

 /**
     * Build simple HTML email with two formats (success or error),
     * including dynamic site logo and signature name.
     */
    private function buildGenericTemplate(string $message, string $type): string
    {
        // Retrieve dynamic name from Cliniko credentials or fallback to site name
        $site_name = get_bloginfo('name');
        $app_name = Credentials::getAppName();
        $brand_name = $site_name ? $site_name : ucfirst($app_name);

        // Get logo from WordPress customizer
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        $logo_html = $logo_url
            ? "<div style='text-align:center; margin-bottom:24px;'>
                   <img src='{$logo_url}' alt='{$brand_name} logo' style='max-width:160px; height:auto;'>
               </div>"
            : '';

        $baseStyles = "
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
        ";

        if ($type === 'error') {
            $headerColor = '#b91c1c';
            $bgColor = '#fee2e2';
            $title = 'There was a problem with your request';
        } else {
            $headerColor = '#047857';
            $bgColor = '#ecfdf5';
            $title = 'Your request was successful';
        }

        return "
            <html>
                <body style='{$baseStyles} background-color:#f9fafb; padding:40px 0;'>
                    <table width='100%' cellspacing='0' cellpadding='0' style='max-width:600px;margin:auto;background-color:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.08);'>
                        <tr>
                            <td style='background-color:{$headerColor};color:#fff;padding:20px 24px;font-size:20px;font-weight:bold;text-align:center;'>
                                {$title}
                            </td>
                        </tr>
                        <tr>
                            <td style='padding:24px;background-color:{$bgColor};'>
                                {$logo_html}
                                <p style='margin-top:0;margin-bottom:16px;'>{$message}</p>
                                <p style='margin-top:24px;font-size:14px;color:#555;'>Thank you,<br><strong>{$brand_name} Team</strong></p>
                            </td>
                        </tr>
                    </table>
                </body>
            </html>
        ";
    }
}
