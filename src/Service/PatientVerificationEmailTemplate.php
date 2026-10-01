<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientVerificationEmailTemplate
{
    public const OPTION = 'wp_cliniko_patient_verification_email_template';

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            'subject' => 'Verify your patient account',
            'preheader' => 'Confirm your email address to activate your patient account.',
            'heading' => 'Verify your patient account',
            'body' => "Hi {first_name},\n\nConfirm that this email address belongs to your {site_name} and Cliniko patient account.",
            'button_text' => 'Verify patient account',
            'footer' => 'This secure link expires in {expires_minutes} minutes and can only be used once. If you did not create this account, ignore this email.',
            'logo_url' => '',
            'background_color' => '#f3f4f6',
            'card_color' => '#ffffff',
            'text_color' => '#1f2937',
            'button_color' => '#2563eb',
            'button_text_color' => '#ffffff',
        ];
    }

    /** @return array<string,string> */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    /** @param mixed $input @return array<string,string> */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $result = [];
        foreach (['subject', 'preheader', 'heading', 'button_text'] as $key) {
            $result[$key] = sanitize_text_field((string) ($input[$key] ?? $defaults[$key]));
        }
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

    public static function subject(\WP_User $user): string
    {
        return self::replace(self::get()['subject'], $user);
    }

    public static function render(\WP_User $user, string $verificationUrl): string
    {
        $template = self::get();
        $preheader = esc_html(self::replace($template['preheader'], $user));
        $heading = esc_html(self::replace($template['heading'], $user));
        $body = nl2br(esc_html(self::replace($template['body'], $user)));
        $button = esc_html(self::replace($template['button_text'], $user));
        $footer = nl2br(esc_html(self::replace($template['footer'], $user)));
        $logo = $template['logo_url'] !== ''
            ? '<p style="margin:0 0 24px"><img src="' . esc_url($template['logo_url']) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="display:block;max-width:180px;max-height:72px"></p>'
            : '';

        return '<!doctype html><html><body style="margin:0;padding:0;background:' . esc_attr($template['background_color']) . '">'
            . '<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0">' . $preheader . '</span>'
            . '<div style="padding:32px 16px"><div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:32px;background:' . esc_attr($template['card_color']) . ';color:' . esc_attr($template['text_color']) . ';border-radius:10px">'
            . $logo
            . '<h1 style="font-size:24px;line-height:1.3;margin:0 0 16px">' . $heading . '</h1>'
            . '<div style="line-height:1.6">' . $body . '</div>'
            . '<p style="margin:28px 0"><a href="' . esc_url($verificationUrl) . '" style="display:inline-block;background:' . esc_attr($template['button_color']) . ';color:' . esc_attr($template['button_text_color']) . ';text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600">' . $button . '</a></p>'
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
}
