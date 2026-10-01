<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientAccountClosureEmailTemplate
{
    public const REQUEST = 'closure_request';
    public const RECEIPT = 'closure_receipt';
    public const REQUEST_OPTION = 'wp_cliniko_patient_account_closure_request_email_template';
    public const RECEIPT_OPTION = 'wp_cliniko_patient_account_closure_receipt_email_template';

    /** @return array<string,string> */
    public static function defaults(string $type): array
    {
        $branding = PatientVerificationEmailTemplate::get();
        $shared = [
            'logo_url' => (string) $branding['logo_url'],
            'background_color' => (string) $branding['background_color'],
            'card_color' => (string) $branding['card_color'],
            'text_color' => (string) $branding['text_color'],
            'button_color' => (string) $branding['button_color'],
            'button_text_color' => (string) $branding['button_text_color'],
        ];

        if ($type === self::RECEIPT) {
            return array_merge([
                'subject' => 'Your patient portal account has been closed',
                'preheader' => 'Confirmation that your online patient portal account is closed.',
                'heading' => 'Portal account closed',
                'body' => "Hi {first_name},\n\nYour {site_name} patient portal account has been closed and all portal sessions have been signed out.",
                'button_text' => '',
                'footer' => 'Your Cliniko health record was not deleted. Contact the clinic if you want to make a separate health-record privacy request. If you did not complete this closure, contact the clinic immediately.',
            ], $shared);
        }

        return array_merge([
            'subject' => 'Confirm patient portal account closure',
            'preheader' => 'Review and confirm your request to close your patient portal account.',
            'heading' => 'Confirm account closure',
            'body' => "Hi {first_name},\n\nWe received a request to close your {site_name} patient portal account. Use the button below, sign in if requested, and complete the final confirmation.",
            'button_text' => 'Review account closure',
            'footer' => 'This secure link expires in {expires_minutes} minutes. Opening it alone will not close your account. Your Cliniko health record, appointments, notes, documents, invoices, and payments are not deleted. If you did not request this, ignore this email.',
        ], $shared);
    }

    /** @return array<string,string> */
    public static function get(string $type): array
    {
        $type = self::normalizeType($type);
        $stored = get_option(self::option($type), []);
        return array_merge(self::defaults($type), is_array($stored) ? $stored : []);
    }

    /** @param mixed $input @return array<string,string> */
    public static function sanitize($input, string $type): array
    {
        $type = self::normalizeType($type);
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults($type);
        $result = [];
        foreach (['subject', 'preheader', 'heading'] as $key) {
            $result[$key] = sanitize_text_field((string) ($input[$key] ?? $defaults[$key]));
        }
        $result['button_text'] = $type === self::REQUEST
            ? sanitize_text_field((string) ($input['button_text'] ?? $defaults['button_text']))
            : '';
        foreach (['body', 'footer'] as $key) {
            $result[$key] = sanitize_textarea_field((string) ($input[$key] ?? $defaults[$key]));
        }
        $result['logo_url'] = esc_url_raw((string) ($input['logo_url'] ?? ''));
        foreach (['background_color', 'card_color', 'text_color', 'button_color', 'button_text_color'] as $key) {
            $color = sanitize_hex_color((string) ($input[$key] ?? ''));
            $result[$key] = is_string($color) && $color !== '' ? $color : $defaults[$key];
        }
        return $result;
    }

    public static function option(string $type): string
    {
        return self::normalizeType($type) === self::RECEIPT
            ? self::RECEIPT_OPTION
            : self::REQUEST_OPTION;
    }

    public static function subject(string $type, \WP_User $user): string
    {
        return self::replace(self::get($type)['subject'], $user);
    }

    public static function renderRequest(\WP_User $user, string $confirmationUrl): string
    {
        return self::render(self::REQUEST, $user, $confirmationUrl);
    }

    public static function renderReceipt(\WP_User $user): string
    {
        return self::render(self::RECEIPT, $user, '');
    }

    private static function render(string $type, \WP_User $user, string $actionUrl): string
    {
        $template = self::get($type);
        $preheader = esc_html(self::replace($template['preheader'], $user));
        $heading = esc_html(self::replace($template['heading'], $user));
        $body = nl2br(esc_html(self::replace($template['body'], $user)));
        $footer = nl2br(esc_html(self::replace($template['footer'], $user)));
        $logo = $template['logo_url'] !== ''
            ? '<p style="margin:0 0 24px"><img src="' . esc_url($template['logo_url']) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="display:block;max-width:180px;max-height:72px"></p>'
            : '';
        $button = '';
        if ($type === self::REQUEST && $actionUrl !== '') {
            $button = '<p style="margin:28px 0"><a href="' . esc_url($actionUrl) . '" style="display:inline-block;background:' . esc_attr($template['button_color']) . ';color:' . esc_attr($template['button_text_color']) . ';text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600">'
                . esc_html(self::replace($template['button_text'], $user)) . '</a></p>';
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:' . esc_attr($template['background_color']) . '">'
            . '<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0">' . $preheader . '</span>'
            . '<div style="padding:32px 16px"><div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:32px;background:' . esc_attr($template['card_color']) . ';color:' . esc_attr($template['text_color']) . ';border-radius:10px">'
            . $logo
            . '<h1 style="font-size:24px;line-height:1.3;margin:0 0 16px">' . $heading . '</h1>'
            . '<div style="line-height:1.6">' . $body . '</div>'
            . $button
            . '<div style="font-size:13px;color:' . esc_attr($template['text_color']) . ';opacity:.72;line-height:1.5">' . $footer . '</div>'
            . '</div></div></body></html>';
    }

    private static function replace(string $value, \WP_User $user): string
    {
        $firstName = trim((string) get_user_meta((int) $user->ID, 'first_name', true));
        return strtr($value, [
            '{first_name}' => $firstName !== '' ? $firstName : (string) $user->display_name,
            '{site_name}' => (string) get_bloginfo('name'),
            '{expires_minutes}' => '30',
        ]);
    }

    private static function normalizeType(string $type): string
    {
        return $type === self::RECEIPT ? self::RECEIPT : self::REQUEST;
    }
}
