<?php
namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class NotificationService
{
    private const SUCCESS_OPTION_ENABLED  = 'wp_cliniko_send_email_on_success';
    private const SUCCESS_OPTION_TEMPLATE = 'wp_cliniko_success_email_tpl';

    private const FAILURE_OPTION_ENABLED  = 'wp_cliniko_send_email_on_failure';
    private const FAILURE_OPTION_TEMPLATE = 'wp_cliniko_failure_email_tpl';

    private const CONFIRM_OPTION_ENABLED  = 'wp_cliniko_send_email_on_confirm';
    private const CONFIRM_OPTION_SUBJECT  = 'wp_cliniko_confirm_email_subject';
    private const CONFIRM_OPTION_TEMPLATE = 'wp_cliniko_confirm_email_tpl';

    private const WELCOME_OPTION_ENABLED  = 'wp_cliniko_send_email_on_welcome';
    private const WELCOME_OPTION_SUBJECT  = 'wp_cliniko_welcome_email_subject';
    private const WELCOME_OPTION_TEMPLATE = 'wp_cliniko_welcome_email_tpl';

    private const RESET_OPTION_ENABLED    = 'wp_cliniko_send_email_on_password_reset';
    private const RESET_OPTION_SUBJECT    = 'wp_cliniko_password_reset_subject';
    private const RESET_OPTION_TEMPLATE   = 'wp_cliniko_password_reset_tpl';


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
            'We could not complete your request — refund on the way',
            'cliniko_notify_failure_',
            false
        );
    }

     public function sendConfirmation(array $patient, string $token): void
    {
        if (get_option(self::CONFIRM_OPTION_ENABLED) !== 'yes') return;

        $toEmail = (string) ($patient['email'] ?? '');
        if (!$toEmail) return;

        $subject = get_option(self::CONFIRM_OPTION_SUBJECT, 'Confirm your Us account');
        $tpl     = get_option(self::CONFIRM_OPTION_TEMPLATE, '<p>Hi {first_name},</p><p>Click <a href="{confirmation_url}">here</a> to confirm your account.</p>');

        $vars = [
            '{first_name}'       => esc_html((string) ($patient['first_name'] ?? '')),
            '{last_name}'        => esc_html((string) ($patient['last_name'] ?? '')),
            '{email}'            => esc_html($toEmail),
            '{confirmation_url}' => site_url('/?confirm_token=' . urlencode($token)),
        ];

        $body = strtr($tpl, $vars);

        wp_mail($toEmail, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    public function sendWelcome(array $patient): void
    {
        if (get_option(self::WELCOME_OPTION_ENABLED) !== 'yes') return;

        $toEmail = (string) ($patient['email'] ?? '');
        if (!$toEmail) return;

        $subject = get_option(self::WELCOME_OPTION_SUBJECT, 'Welcome to Us!');
        $tpl     = get_option(self::WELCOME_OPTION_TEMPLATE, '<p>Hi {first_name},</p><p>Welcome aboard! You can now book consultations easily.</p>');

        $vars = [
            '{first_name}' => esc_html((string) ($patient['first_name'] ?? '')),
            '{last_name}'  => esc_html((string) ($patient['last_name'] ?? '')),
            '{email}'      => esc_html($toEmail),
        ];

        $body = strtr($tpl, $vars);

        wp_mail($toEmail, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    public function sendPasswordReset(array $patient, string $resetUrl): void
    {
        if (get_option(self::RESET_OPTION_ENABLED) !== 'yes') return;

        $toEmail = (string) ($patient['email'] ?? '');
        if (!$toEmail) return;

        $subject = get_option(self::RESET_OPTION_SUBJECT, 'Reset your Us password');
        $tpl     = get_option(self::RESET_OPTION_TEMPLATE, '<p>Hi {first_name},</p><p>Click <a href="{reset_url}">here</a> to reset your password.</p>');

        $vars = [
            '{first_name}' => esc_html((string) ($patient['first_name'] ?? '')),
            '{last_name}'  => esc_html((string) ($patient['last_name'] ?? '')),
            '{email}'      => esc_html($toEmail),
            '{reset_url}'  => esc_url($resetUrl),
        ];

        $body = strtr($tpl, $vars);

        wp_mail($toEmail, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
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
                . "<p>Thank you for choosing Us.</p>";
        }

        return "<p>$greet,</p>"
            . "<p>We were unable to complete your {$vars['{appointment_label}']} request.</p>"
            . ($amountTxt ? "<p>A refund of <strong>\${$amountTxt} {$vars['{currency}']}</strong> has been initiated.</p>" : '')
            . "<p>Please contact support if you need assistance.</p>";
    }

        private function defaultBody(array $vars, bool $isSuccess, string $amountTxt): string
    {
        $greet = $vars['{first_name}'] ? 'Hi ' . $vars['{first_name}'] : 'Hi';
        if ($isSuccess) {
            return "<p>$greet,</p>"
                . "<p>Your {$vars['{appointment_label}']} request has been confirmed.</p>"
                . ($amountTxt ? "<p>We received your payment of <strong>\${$amountTxt} {$vars['{currency}']}</strong>.</p>" : '')
                . "<p>Thank you for choosing Us.</p>";
        }

        return "<p>$greet,</p>"
            . "<p>We were unable to complete your {$vars['{appointment_label}']} request.</p>"
            . ($amountTxt ? "<p>A refund of <strong>\${$amountTxt} {$vars['{currency}']}</strong> has been initiated.</p>" : '')
            . "<p>Please contact support if you need assistance.</p>";
    }
}
