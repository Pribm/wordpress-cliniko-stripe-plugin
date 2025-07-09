<?php

use App\Client\ClinikoClient;
use App\Exception\ApiException;
use App\Model\PatientFormTemplate;

if (!defined('ABSPATH'))
    exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'wp-cliniko-stripe-settings',
        'Patient Forms',
        'Patient Forms',
        'manage_options',
        'wp-cliniko-patient-forms',
        'render_cliniko_patient_forms_page'
    );

    add_submenu_page(
        null, // Página oculta (sem exibição no menu)
        'View Patient Form',
        'View Patient Form',
        'manage_options',
        'wp-cliniko-patient-form-view',
        'render_cliniko_patient_form_view_page'
    );

    if (
    is_admin() &&
    isset($_GET['page']) && $_GET['page'] === 'wp-cliniko-patient-forms' &&
    isset($_GET['action']) && $_GET['action'] === 'delete' &&
    isset($_GET['id'])
  ) {
    $id = sanitize_text_field($_GET['id']);

    try {
        $client = ClinikoClient::getInstance();
      PatientFormTemplate::delete($id, $client);
      wp_safe_redirect(admin_url('admin.php?page=wp-cliniko-patient-forms&deleted=1'));
      exit;
    } catch (Exception $e) {
      wp_die('Failed to delete form: ' . $e->getMessage());
    }
  }

});



function render_cliniko_patient_forms_page()
{
    try {
        $client = ClinikoClient::getInstance();
        $forms = PatientFormTemplate::all($client);
    } catch (\Throwable $e) {
        throw new ApiException($e->getMessage());
    }

    ?>
    <div class="wrap">
        <h1>Cliniko Patient Forms</h1>
        <p>These are the patient form templates currently available in your Cliniko account.</p>

        <?php if (is_null($forms)): ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> Unable to load patient forms from Cliniko. Please check your API key and internet connection.</p>
            </div>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Name</th>
                        <th>Email to Patient</th>
                        <th>Restricted</th>
                        <th>Archived</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forms)): ?>
                        <tr>
                            <td colspan="7">No patient forms found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forms as $form): ?>
                            <tr>
                                <td><?php echo esc_html($form->getId()); ?></td>
                                <td><?php echo esc_html($form->getName()); ?></td>
                                <td><?php echo $form->isEmailToPatientOnCompletion() ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $form->isRestrictedToPractitioner() ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $form->isArchived() ? 'Yes' : 'No'; ?></td>
                                <td><?php echo esc_html(date('Y-m-d', strtotime($form->getCreatedAt()))); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=wp-cliniko-patient-form-view&id=' . urlencode($form->getId())); ?>"
                                        class="button button-small">View</a>

                                    <a href="#" class="button button-small">Duplicate</a>
                                    <a href="<?php echo admin_url('admin.php?page=wp-cliniko-patient-forms&action=delete&id=' . esc_attr($form->getId())); ?>"
                                        onclick="return confirm('Are you sure you want to delete this form template?');"
                                        class="button button-small button-link-delete">
                                        Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}



function render_cliniko_patient_form_view_page()
{
    if (!isset($_GET['id'])) {
        echo '<div class="notice notice-error"><p>Missing patient form ID.</p></div>';
        return;
    }

    $id = sanitize_text_field($_GET['id']);
    $client = ClinikoClient::getInstance();

    $form = PatientFormTemplate::find($id, $client);

    if (!$form) {
        echo '<div class="notice notice-error"><p>Patient form not found.</p></div>';
        return;
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Patient Form: <?php echo esc_html($form->getName()); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=wp-cliniko-patient-forms'); ?>" class="page-title-action">← Back to
            list</a>
        <hr class="wp-header-end" />

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th colspan="2">Form Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th style="width: 200px;">Form ID</th>
                    <td><?php echo esc_html($form->getId()); ?></td>
                </tr>
                <tr>
                    <th>Email to Patient</th>
                    <td><?php echo $form->isEmailToPatientOnCompletion() ? '<span class="dashicons dashicons-yes" style="color: green;"></span> Yes' : 'No'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Restricted to Practitioner</th>
                    <td><?php echo $form->isRestrictedToPractitioner() ? 'Yes' : 'No'; ?></td>
                </tr>
                <tr>
                    <th>Archived</th>
                    <td><?php echo $form->isArchived() ? 'Yes' : 'No'; ?></td>
                </tr>
                <tr>
                    <th>Created At</th>
                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($form->getCreatedAt()))); ?></td>
                </tr>
                <tr>
                    <th>Last Updated</th>
                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($form->getUpdatedAt()))); ?></td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top: 40px;">Form Sections & Questions</h2>

        <?php foreach ($form->getSections() as $section): ?>
            <div class="postbox" style="margin-top: 20px;">
                <div class="inside">
                    <h3><?php echo esc_html($section->name); ?></h3>
                    <p style="margin-bottom: 1em;"><?php echo wp_kses_post($section->description); ?></p>
                    <ul style="list-style-type: disc; padding-left: 20px;">
                        <?php foreach ($section->questions as $question): ?>
                            <li>
                                <strong><?php echo esc_html($question->name); ?></strong>
                                <span style="color: #555;">(<?php echo esc_html($question->type); ?>)</span>
                                <?php if ($question->required): ?>
                                    <span style="color: red; font-style: italic;"> – Required</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
