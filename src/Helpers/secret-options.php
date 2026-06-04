<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wp_cliniko_secret_option_prefix')) {
    function wp_cliniko_secret_option_prefix(): string
    {
        return 'enc:v1:';
    }
}

if (!function_exists('wp_cliniko_secret_option_secret')) {
    function wp_cliniko_secret_option_secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT') && AUTH_SALT) {
            return (string) AUTH_SALT;
        }

        return __FILE__;
    }
}

if (!function_exists('wp_cliniko_secret_option_encryption_key')) {
    function wp_cliniko_secret_option_encryption_key(): string
    {
        return hash_hmac('sha256', 'wp-cliniko-secret-options|enc', wp_cliniko_secret_option_secret(), true);
    }
}

if (!function_exists('wp_cliniko_secret_option_mac_key')) {
    function wp_cliniko_secret_option_mac_key(): string
    {
        return hash_hmac('sha256', 'wp-cliniko-secret-options|mac', wp_cliniko_secret_option_secret(), true);
    }
}

if (!function_exists('wp_cliniko_secret_option_base64url_encode')) {
    function wp_cliniko_secret_option_base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('wp_cliniko_secret_option_base64url_decode')) {
    function wp_cliniko_secret_option_base64url_decode(string $value): ?string
    {
        $padded = strtr(trim($value), '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : null;
    }
}

if (!function_exists('wp_cliniko_secret_option_normalize_input')) {
    function wp_cliniko_secret_option_normalize_input($value): string
    {
        $normalized = is_string($value) ? $value : (string) $value;
        if (function_exists('wp_unslash')) {
            $normalized = (string) wp_unslash($normalized);
        }

        return trim($normalized);
    }
}

if (!function_exists('wp_cliniko_secret_option_is_encrypted')) {
    function wp_cliniko_secret_option_is_encrypted(string $value): bool
    {
        return $value !== '' && str_starts_with($value, wp_cliniko_secret_option_prefix());
    }
}

if (!function_exists('wp_cliniko_secret_option_encrypt')) {
    /**
     * Sanitizer for secret options stored in wp_options.
     *
     * Returns encrypted ciphertext when OpenSSL is available, otherwise the
     * original normalized value so settings remain functional.
     *
     * @param mixed $value
     */
    function wp_cliniko_secret_option_encrypt($value): string
    {
        $normalized = wp_cliniko_secret_option_normalize_input($value);
        if ($normalized === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            return $normalized;
        }

        $cipherMethod = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        if ($ivLength <= 0) {
            return $normalized;
        }

        try {
            $iv = random_bytes($ivLength);
        } catch (\Throwable $e) {
            $iv = substr(hash('sha256', uniqid('wp_cliniko_secret_', true), true), 0, $ivLength);
        }

        $ciphertext = openssl_encrypt(
            $normalized,
            $cipherMethod,
            wp_cliniko_secret_option_encryption_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($ciphertext) || $ciphertext === '') {
            return $normalized;
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, wp_cliniko_secret_option_mac_key(), true);
        return wp_cliniko_secret_option_prefix() . wp_cliniko_secret_option_base64url_encode($iv . $mac . $ciphertext);
    }
}

if (!function_exists('wp_cliniko_secret_option_decrypt')) {
    function wp_cliniko_secret_option_decrypt(string $value): string
    {
        $stored = trim($value);
        if ($stored === '' || !wp_cliniko_secret_option_is_encrypted($stored)) {
            return $stored;
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = wp_cliniko_secret_option_base64url_decode(substr($stored, strlen(wp_cliniko_secret_option_prefix())));
        if ($raw === null || $raw === '') {
            return '';
        }

        $cipherMethod = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        if ($ivLength <= 0 || strlen($raw) <= ($ivLength + 32)) {
            return '';
        }

        $iv = substr($raw, 0, $ivLength);
        $mac = substr($raw, $ivLength, 32);
        $ciphertext = substr($raw, $ivLength + 32);

        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, wp_cliniko_secret_option_mac_key(), true);
        if (!hash_equals($expectedMac, $mac)) {
            return '';
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipherMethod,
            wp_cliniko_secret_option_encryption_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return is_string($plaintext) ? $plaintext : '';
    }
}

if (!function_exists('wp_cliniko_get_secret_option')) {
    function wp_cliniko_get_secret_option(string $option, string $default = ''): string
    {
        if (!function_exists('get_option')) {
            return $default;
        }

        $stored = get_option($option, $default);
        if (!is_string($stored) || $stored === '') {
            return $default;
        }

        if (wp_cliniko_secret_option_is_encrypted($stored)) {
            $decrypted = wp_cliniko_secret_option_decrypt($stored);
            return $decrypted !== '' ? $decrypted : $default;
        }

        $encrypted = wp_cliniko_secret_option_encrypt($stored);
        if ($encrypted !== '' && $encrypted !== $stored && function_exists('update_option')) {
            update_option($option, $encrypted, false);
        }

        return $stored;
    }
}
