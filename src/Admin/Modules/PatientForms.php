<?php

namespace App\Admin\Modules;

use App\Client\Cliniko\Client;
use App\Exception\ApiException;
use App\Model\PatientFormTemplate;

if (!defined('ABSPATH')) exit;

class PatientForms {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'registerMenus']);
    }

    public static function registerMenus(): void {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Patient Forms',
            'Patient Forms',
            'manage_options',
            'wp-cliniko-patient-forms',
            [self::class, 'renderFormsPage']
        );

        add_submenu_page(
            '',
            'View Patient Form',
            'View Patient Form',
            'manage_options',
            'wp-cliniko-patient-form-view',
            [self::class, 'renderSingleFormView']
        );

        if (
            is_admin() &&
            isset($_GET['page']) && $_GET['page'] === 'wp-cliniko-patient-forms' &&
            isset($_GET['action']) && $_GET['action'] === 'delete' &&
            isset($_GET['id'])
        ) {
            $id = sanitize_text_field($_GET['id']);

            try {
                $client = Client::getInstance();
                PatientFormTemplate::delete($id, $client);
                wp_safe_redirect(admin_url('admin.php?page=wp-cliniko-patient-forms&deleted=1'));
                exit;
            } catch (\Exception $e) {
                wp_die('Failed to delete form: ' . $e->getMessage());
            }
        }
    }

    public static function renderFormsPage(): void {
        try {
            $client = Client::getInstance();
            $forms = PatientFormTemplate::all($client);
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage());
        }

        include __DIR__ . '/views/patient-forms-list.php';
    }

    public static function renderSingleFormView(): void {
        if (!isset($_GET['id'])) {
            echo '<div class="notice notice-error"><p>Missing patient form ID.</p></div>';
            return;
        }

        $id = sanitize_text_field($_GET['id']);
        $client = Client::getInstance();
        $form = PatientFormTemplate::find($id, $client);

        if (!$form) {
            echo '<div class="notice notice-error"><p>Patient form not found.</p></div>';
            return;
        }

        include __DIR__ . '/views/patient-form-single.php';
    }
}
