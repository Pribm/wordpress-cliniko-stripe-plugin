<?php

namespace App\Admin\Modules;

use App\Service\PatientLinkService;
use App\Service\PatientVerificationEmailTemplate;

if (!defined('ABSPATH')) {
    exit;
}

final class UserGuide
{
    private const PAGE = 'wp-cliniko-user-guide';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Getting Started',
            'Getting Started',
            'manage_options',
            self::PAGE,
            [self::class, 'renderPage'],
            0
        );
    }

    public static function enqueueAssets(): void
    {
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== self::PAGE) {
            return;
        }

        $assetDirectory = dirname(__DIR__) . '/assets';
        $stylePath = $assetDirectory . '/user-guide-admin.css';
        $scriptPath = $assetDirectory . '/user-guide-admin.js';

        wp_enqueue_style(
            'cliniko-user-guide-admin',
            plugins_url('../assets/user-guide-admin.css', __FILE__),
            [],
            is_file($stylePath) ? (string) filemtime($stylePath) : null
        );
        wp_enqueue_script(
            'cliniko-user-guide-admin',
            plugins_url('../assets/user-guide-admin.js', __FILE__),
            [],
            is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
            true
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $settings = admin_url('admin.php?page=wp-cliniko-stripe-settings');
        $accounts = add_query_arg(['page' => 'wp-cliniko-stripe-settings', 'tab' => 'patient-accounts'], admin_url('admin.php'));
        $builder = admin_url('admin.php?page=wp-cliniko-account-builders');
        $verificationEmail = add_query_arg([
            'page' => 'wp-cliniko-account-builders',
            'builder_tab' => 'emails',
        ], admin_url('admin.php'));
        $debug = admin_url('admin.php?page=wp-cliniko-debug');
        $tools = admin_url('admin.php?page=wp-cliniko-tools');
        $version = defined('WP_CLINIKO_PLUGIN_VERSION') ? (string) WP_CLINIKO_PLUGIN_VERSION : '';
        $setupSteps = self::setupSteps($settings, $accounts, $builder, $verificationEmail);
        $completedSteps = count(array_filter(
            $setupSteps,
            static fn(array $step): bool => $step['complete']
        ));
        $setupProgress = $setupSteps !== []
            ? (int) round(($completedSteps / count($setupSteps)) * 100)
            : 0;
        ?>
        <div class="wrap cliniko-user-guide">
            <header class="cliniko-docs-hero" id="cliniko-docs-top">
                <div class="cliniko-docs-hero__copy">
                    <span class="cliniko-docs-eyebrow">EasyScripts documentation<?php echo $version !== '' ? ' / v' . esc_html($version) : ''; ?></span>
                    <h1>Getting Started with Cliniko + Stripe</h1>
                    <p>Follow the setup journey, then use the documentation to build patient accounts, social login, bookings, payments, and dashboard modules.</p>
                </div>
                <nav class="cliniko-docs-quick-links" aria-label="Quick documentation links">
                    <a href="<?php echo esc_url($settings); ?>">Configure APIs <span aria-hidden="true">&rarr;</span></a>
                    <a href="<?php echo esc_url($accounts); ?>">Patient accounts <span aria-hidden="true">&rarr;</span></a>
                    <a href="#ultimate-member-nextend">Social login setup <span aria-hidden="true">&darr;</span></a>
                </nav>
            </header>

            <section class="cliniko-setup-flow" aria-labelledby="cliniko-setup-title">
                <div class="cliniko-setup-flow__header">
                    <div>
                        <span class="cliniko-docs-eyebrow">Initial setup</span>
                        <h2 id="cliniko-setup-title">Your setup journey</h2>
                        <p>The checks below read the current WordPress configuration. Complete them in order for a reliable patient login and Cliniko verification flow.</p>
                    </div>
                    <div class="cliniko-setup-progress" aria-label="<?php echo esc_attr($completedSteps . ' of ' . count($setupSteps) . ' setup steps complete'); ?>">
                        <strong><?php echo esc_html($completedSteps . ' / ' . count($setupSteps)); ?></strong>
                        <span>steps complete</span>
                        <div class="cliniko-setup-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $setupProgress); ?>">
                            <span style="width:<?php echo esc_attr((string) $setupProgress); ?>%"></span>
                        </div>
                    </div>
                </div>
                <ol class="cliniko-setup-steps">
                    <?php foreach ($setupSteps as $index => $step) : ?>
                        <?php
                        $remainingChecks = count(array_filter(
                            $step['checks'],
                            static fn(array $check): bool => !$check['complete']
                        ));
                        $statusLabel = $step['complete']
                            ? 'Complete'
                            : sprintf('%d %s remaining', $remainingChecks, $remainingChecks === 1 ? 'item' : 'items');
                        ?>
                        <li class="cliniko-setup-step <?php echo $step['complete'] ? 'is-complete' : 'needs-action'; ?>">
                            <div class="cliniko-setup-step__number" aria-hidden="true">
                                <?php if ($step['complete']) : ?>
                                    <span class="dashicons dashicons-yes-alt"></span>
                                <?php else : ?>
                                    <?php echo esc_html((string) ($index + 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="cliniko-setup-step__body">
                                <div class="cliniko-setup-step__heading">
                                    <div>
                                        <span class="cliniko-setup-step__status"><?php echo esc_html($statusLabel); ?></span>
                                        <h3><?php echo esc_html($step['title']); ?></h3>
                                    </div>
                                    <p><?php echo esc_html($step['description']); ?></p>
                                </div>

                                <h4>What to check</h4>
                                <ul class="cliniko-setup-checks">
                                    <?php foreach ($step['checks'] as $check) : ?>
                                        <li class="cliniko-setup-check <?php echo $check['complete'] ? 'is-complete' : 'needs-action'; ?>">
                                            <span class="dashicons <?php echo $check['complete'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                                            <span class="cliniko-setup-check__copy">
                                                <strong><?php echo esc_html($check['label']); ?></strong>
                                                <span><?php echo esc_html($check['instruction']); ?></span>
                                            </span>
                                            <span class="cliniko-setup-check__state"><?php echo $check['complete'] ? 'Ready' : 'Needs attention'; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <?php if ($step['note'] !== '') : ?>
                                    <div class="cliniko-setup-step__note">
                                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                        <span><?php echo esc_html($step['note']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="cliniko-setup-step__actions">
                                    <?php foreach ($step['actions'] as $action) : ?>
                                        <a class="button <?php echo !$step['complete'] && $action['primary'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url($action['url']); ?>">
                                            <?php echo esc_html($action['label']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <div class="cliniko-docs-layout">
                <aside class="cliniko-docs-sidebar">
                    <label class="cliniko-docs-search">
                        <span class="screen-reader-text">Search documentation</span>
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" placeholder="Search this guide" data-cliniko-docs-search autocomplete="off">
                    </label>
                    <nav class="cliniko-docs-nav" aria-label="User guide sections">
                        <div class="cliniko-docs-nav__group" data-cliniko-docs-nav-group>
                            <strong>Getting started</strong>
                            <a href="#requirements">Requirements</a>
                            <a href="#credentials">API credentials</a>
                            <a href="#patient-accounts">Patient accounts</a>
                            <a href="#ultimate-member-nextend">Ultimate Member + Nextend</a>
                        </div>
                        <div class="cliniko-docs-nav__group" data-cliniko-docs-nav-group>
                            <strong>Build the portal</strong>
                            <a href="#ultimate-member">Protect pages</a>
                            <a href="#builder">Template Builder</a>
                            <a href="#shortcodes">Shortcodes</a>
                            <a href="#elementor">Elementor widgets</a>
                        </div>
                        <div class="cliniko-docs-nav__group" data-cliniko-docs-nav-group>
                            <strong>Bookings and payments</strong>
                            <a href="#booking">Booking flow</a>
                            <a href="#payments">Payments</a>
                            <a href="#webhooks">Webhooks</a>
                            <a href="#headless">Headless mode</a>
                        </div>
                        <div class="cliniko-docs-nav__group" data-cliniko-docs-nav-group>
                            <strong>Operations</strong>
                            <a href="#operations">Maintenance</a>
                            <a href="#troubleshooting">Troubleshooting</a>
                            <a href="#security">Security and privacy</a>
                        </div>
                    </nav>
                </aside>

                <main class="cliniko-docs-content" data-cliniko-docs-content>
            <div class="notice notice-info inline">
                <p><strong>Recommended order:</strong> install the required plugins, configure credentials, test the Cliniko connection, configure patient accounts, complete the <a href="#ultimate-member-nextend">Ultimate Member + Nextend setup</a>, create your templates/modules, then publish pages using the generated shortcodes or Elementor widgets.</p>
            </div>

            <h2 id="requirements">1. Requirements and installation</h2>
            <ol>
                <li>Use WordPress 5.9 or newer, PHP 7.4 or newer, and Elementor 3.10 or newer.</li>
                <li>Install and activate <strong>Ultimate Member</strong>. It is required for the patient-account workflow and page protection.</li>
                <li>Install and activate Elementor if you will use the booking widgets.</li>
                <li>Install this plugin from <em>Plugins → Add New → Upload Plugin</em>, or copy it into <code>wp-content/plugins/</code>, then activate it.</li>
                <li>Open <a href="<?php echo esc_url($settings); ?>">Cliniko + Stripe → API Credentials</a>.</li>
            </ol>
            <p>The plugin will not initialise its patient-account and booking features until Ultimate Member is active.</p>

            <h2 id="credentials">2. Configure Cliniko, Stripe, and Tyro Health</h2>
            <h3>Cliniko</h3>
            <ol>
                <li>In Cliniko, open <em>My info → API keys</em> and copy an API key.</li>
                <li>Enter the key in <strong>Cliniko API Key</strong>.</li>
                <li>Enter the first subdomain from your Cliniko URL as <strong>Cliniko App Name</strong>. For <code>https://my-clinic.au4.cliniko.com</code>, use <code>my-clinic</code>.</li>
                <li>Enter the regional part as <strong>Cliniko Shard</strong>, for example <code>au4</code>.</li>
                <li>Click <strong>Connect to Cliniko</strong>, select the business, and save.</li>
                <li>Use the cache TTL field to control how long GET responses are cached. Use <code>0</code> when you need to clear the cache manually.</li>
            </ol>
            <h3>Stripe</h3>
            <ol>
                <li>Enter the Stripe publishable key and secret key.</li>
                <li>Use test keys while testing and live keys only on the production site.</li>
                <li>Choose Stripe as the gateway in the booking form widget or booking template.</li>
            </ol>
            <h3>Tyro Health (THOP)</h3>
            <ol>
                <li>Select <strong>Staging</strong> or <strong>Production</strong>.</li>
                <li>Enter the Tyro App ID, Business Admin API key, app version, and optional provider number supplied by Tyro Health.</li>
                <li>Keep the Business Admin API key server-side; never place it in page content or JavaScript.</li>
                <li>Choose Tyro Health as the gateway in the booking form configuration.</li>
            </ol>

            <h2 id="patient-accounts">3. Configure patient accounts</h2>
            <p>Open <a href="<?php echo esc_url($accounts); ?>">Cliniko + Stripe → Patient Accounts</a>.</p>
            <ol>
                <li>Enable patient synchronisation.</li>
                <li>Select the WordPress roles that represent patients.</li>
                <li>Select the privacy-acceptance field used by your registration flow. The plugin records acceptance for synchronised users.</li>
                <li>Optionally select a strict synchronisation role. Users with that role must have a verified Cliniko link before they can authenticate.</li>
                <li>Choose whether existing non-provisional users may log in when Cliniko is temporarily unavailable.</li>
                <li>Choose a patient-data-unavailable page. On an Ultimate Member-restricted page, users whose patient data cannot be retrieved are redirected there. Temporary API failures are not treated as invalid links.</li>
                <li>Save the settings.</li>
            </ol>
            <h3>How patient verification works</h3>
            <ol>
                <li>A user registers with a configured patient role.</li>
                <li>The plugin finds or creates the matching Cliniko patient and sends a verification email.</li>
                <li>The user clicks the one-time verification link.</li>
                <li>The link is marked verified and the account becomes available for patient features.</li>
                <li>Ultimate Member account review can keep the account pending until an administrator approves it.</li>
            </ol>

            <h2 id="ultimate-member-nextend">4. Social login with Ultimate Member and Nextend</h2>
            <p>Use <strong>Nextend Social Login</strong> only to authenticate the social account. This plugin remains the authority for Cliniko matching, its own email verification, Ultimate Member approval, patient REST endpoints, and patient dashboard data.</p>

            <div class="notice notice-warning inline">
                <p><strong>Use Require Admin Review for the patient role.</strong> Do not use Auto Approve. Do not use Ultimate Member's Require Email Activation for this role unless you intentionally want a second, separate verification email. The Cliniko verification email is the approval step this plugin uses.</p>
            </div>

            <h3>Step 1: Configure the patient-account settings in this plugin</h3>
            <ol>
                <li>Open <a href="<?php echo esc_url($accounts); ?>">Cliniko + Stripe &rarr; Patient Accounts</a>.</li>
                <li>Enable <strong>Patient synchronization</strong>.</li>
                <li>Select the Ultimate Member/WordPress role used by patients, for example <code>EasyScripts Patient</code>, under <strong>Roles to synchronize</strong>.</li>
                <li>Select that same role as <strong>Strict synchronization role</strong>. This is the recommended setting: a patient cannot log in before the Cliniko link is verified, and a temporary Cliniko failure cannot loosen that rule.</li>
                <li>Leave <strong>Allow WordPress access when Cliniko fails</strong> off unless you explicitly want already-established, non-strict users to be able to log in during a Cliniko outage.</li>
                <li>Optionally select a <strong>Patient data unavailable redirect</strong> page. It is used on Ultimate Member-restricted pages when a logged-in patient's Cliniko data cannot be retrieved.</li>
                <li>Save the settings.</li>
            </ol>

            <h3>Step 2: Configure the Ultimate Member patient role</h3>
            <ol>
                <li>Go to <em>Ultimate Member &rarr; User Roles</em> and edit the patient role selected above.</li>
                <li>Under <strong>Registration Options</strong>, set <strong>Registration Status</strong> to <strong>Require Admin Review</strong>.</li>
                <li>Use this role, and only this role, for patient dashboard/page restrictions. Ultimate Member's page restrictions are role-based, so this must be combined with Nextend's login restriction and this plugin's pending-account guard; a role restriction alone is not the Cliniko approval check.</li>
                <li>Configure any normal Ultimate Member profile fields you need. The social-registration flow must result in a valid email address, first name, and last name.</li>
                <li>For a privacy/terms checkbox, make the Ultimate Member field required and enter its meta key in this plugin's <strong>Privacy acceptance field</strong> setting.</li>
            </ol>
            <p>The plugin puts configured patient accounts into Ultimate Member's <code>awaiting_admin_review</code> state while Cliniko verification is pending. After a successful Cliniko verification, it approves that same pending account. A manually rejected or inactive Ultimate Member account is never auto-approved.</p>

            <h3>Step 3: Configure WordPress and Nextend</h3>
            <ol>
                <li>Go to <em>Settings &rarr; General</em>. Turn on <strong>Anyone can register</strong>. This only allows creation of the provisional WordPress account; it does not grant patient access.</li>
                <li>For the free version of Nextend, set <strong>New User Default Role</strong> to the same patient role. With Nextend Pro, set the provider's <strong>Default roles for user who registered with this provider</strong> to that role for every enabled provider.</li>
                <li>In <em>Nextend Social Login &rarr; Global Settings &rarr; General</em>, keep <strong>Membership</strong> enabled so a new social identity may create a WordPress account.</li>
                <li>In the same Nextend screen, turn on <strong>Support login restrictions</strong>. This is essential: Nextend must not auto-log a user in while Ultimate Member marks the account as awaiting review.</li>
                <li>Display Nextend buttons on the Ultimate Member login/registration forms. Do not create a second, separate patient role for a provider.</li>
                <li>Use Nextend's social-registration/completion form when a provider does not return all required data. Do not allow generated or placeholder email addresses for patient accounts.</li>
            </ol>

            <h3>Step 3b: Configure provider callbacks and patient redirects</h3>
            <p>Nextend uses two different kinds of redirect. Do not use the patient dashboard URL as the provider callback URL.</p>
            <ol>
                <li><strong>Provider callback:</strong> open <em>Nextend Social Login &rarr; Providers &rarr; Google &rarr; Getting Started</em>. Copy the exact callback or redirect URI shown by Nextend into the Google Cloud OAuth client under <em>Authorized redirect URIs</em>. The protocol, domain, port, path, and trailing slash must match exactly. Add separate callback entries for local, staging, and production environments when they use different domains.</li>
                <li><strong>Successful login:</strong> open <em>Nextend Social Login &rarr; Global Settings &rarr; General &rarr; Default redirect URL</em>. Enable <em>for Login</em> and enter the protected patient dashboard URL.</li>
                <li><strong>New registration:</strong> in the same <em>Default redirect URL</em> setting, enable <em>for Register</em> and enter a public “Verify your email” or “Check your inbox” page. Do not send a pending patient directly to the protected dashboard.</li>
                <li><strong>Fixed redirect URL:</strong> normally leave both <em>for Login</em> and <em>for Register</em> disabled and blank. Fixed redirects override the originating page and can break Ultimate Member's intended <code>redirect_to</code> destination.</li>
                <li><strong>Prevent external redirect overrides:</strong> leave <em>Disable external redirects</em> unchecked unless the site intentionally requires one destination for every social login.</li>
                <li>Save Nextend, clear any page/cache-plugin cache, then test registration, verified login, an expired verification link, and a protected-page login from a private browser.</li>
            </ol>
            <div class="notice notice-warning inline"><p><strong>Important:</strong> the provider callback returns the OAuth response to Nextend and is copied from the provider's Getting Started tab. The Default redirect URLs are destinations on your site after Nextend finishes. They are not interchangeable.</p></div>
            <div class="notice notice-info inline"><p><strong>Ultimate Member compatibility:</strong> Ultimate Member can give Nextend the login or registration page itself as an originating redirect, which would normally prevent Nextend's Default redirect from running. It can also finish a pending social registration with a <code>?message=pending</code> URL before Nextend handles its registration destination. For a patient whose Cliniko link is pending, this plugin sends registration and restricted-login outcomes to Nextend's Default <em>for Register</em> URL. Other social-login errors return to the Ultimate Member login page instead of <code>wp-login.php</code>, including early provider errors that initially target <code>wp-login.php?nsl-notice=1</code>. Nextend may retain <code>nsl-notice=1</code> on the frontend destination so its error explanation can be displayed. For successful login, a valid <code>redirect_to</code> for a protected page is preserved; otherwise the Default <em>for Login</em> URL is used.</p></div>

            <h3>Step 4: Configure the Cliniko verification email</h3>
            <ol>
                <li>Open <a href="<?php echo esc_url($verificationEmail); ?>">Cliniko + Stripe &rarr; Template Builder &rarr; Email Templates</a>.</li>
                <li>Leave <strong>Send only the Cliniko verification email to new social patients</strong> enabled. This suppresses WordPress's separate set-password email for Nextend patient registrations while preserving the administrator notification.</li>
                <li>Create a public WordPress page containing <code>[cliniko_patient_verification success_url="/patient-dashboard/" login_after_verification="yes"]</code>. Replace <code>/patient-dashboard/</code> with the protected dashboard page that should open after successful verification. Do not restrict or cache the verification page because the patient is not approved or logged in yet.</li>
                <li>Copy the verification page's full URL into <strong>Public verification page URL</strong>. New verification emails will open that page instead of displaying a REST API JSON response.</li>
                <li>Customize the patient verification email, branding, and wording.</li>
                <li>Configure WordPress to use an authenticated transactional email or SMTP provider. Confirm the sender domain has SPF and DKIM configured; DMARC is also recommended. This plugin sends through <code>wp_mail()</code> and does not configure the mail transport itself.</li>
                <li>Send a real test registration and confirm that the email titled <em>Verify your patient account</em> arrives. The secure link is one-time, expires after 30 minutes, and should not be copied into a page or sent manually.</li>
            </ol>
            <p>A Nextend/WordPress message asking the person to set a password is not the Cliniko verification email. Nextend Free triggers that standard WordPress notification unless this plugin suppresses it. The Cliniko verification email should be the only registration email sent to the patient because it is the message that links and activates the account. A patient can still create a local password later using Lost Password.</p>

            <h3>What happens after social registration</h3>
            <ol>
                <li>Nextend creates a WordPress user with the configured patient role. The plugin notices the WordPress registration, profile-meta, and role events; no Nextend-specific patient ID mapping is used.</li>
                <li>The plugin marks the Ultimate Member account as pending review and waits until it has a valid WordPress email plus first and last name.</li>
                <li>It searches Cliniko for an exact email match. One match starts verification for that patient. No match starts verification for a new patient, which is created only after the person verifies the email. More than one match fails safely and must be resolved by staff.</li>
                <li>If the link expires, the patient's next Nextend social-login attempt sends one replacement verification email. The replacement immediately invalidates every older link, and repeated attempts are email-rate-limited.</li>
                <li>When the person opens the Cliniko verification link, the public verification shortcode confirms the token, obtains the patient again, confirms that the Cliniko email still matches, stores the encrypted patient link, and approves the Ultimate Member account. With <code>login_after_verification="yes"</code>, the page submits automatically, creates a non-persistent one-hour WordPress session, and redirects to the protected <code>success_url</code>. The visible button is used only as a fallback when automatic submission is disabled or JavaScript is unavailable.</li>
                <li>Patient shortcodes and protected patient REST routes require a logged-in user, a configured patient role, a verified Cliniko link, and a resolvable Cliniko patient. A social login alone is not enough.</li>
            </ol>

            <h3>Suspension, outages, and cache behaviour</h3>
            <ul>
                <li>The account is suspended only when Cliniko confirms that the linked patient no longer exists twice using live, uncached <code>404</code> responses. The link is revoked and all WordPress sessions are destroyed.</li>
                <li>Network failures, Cliniko authentication failures, rate limits, server errors, malformed responses, and a single <code>404</code> do not suspend the Ultimate Member account.</li>
                <li>Dashboard data retrieved by patient shortcodes is cached per WordPress user in encrypted WordPress transients. Profile data uses a longer cache; appointments, forms, attachments, and communications use shorter caches. Successful patient updates, bookings, document changes, and message changes invalidate the affected patient cache.</li>
                <li>When a Cliniko shortcode is rendered and Cliniko is temporarily unavailable, the plugin displays one global connection notice. Browser offline status also displays a notice.</li>
            </ul>

            <h3>Test checklist</h3>
            <ol>
                <li>Test in an incognito/private browser window so no prior WordPress session is reused.</li>
                <li>Register with a social account whose email exactly matches one Cliniko patient. Confirm the user is pending, cannot access the patient dashboard, receives the Cliniko email, then becomes approved after clicking it.</li>
                <li>Register with an email that has no Cliniko patient. Confirm no account is approved until the Cliniko verification link is used and the patient is created.</li>
                <li>Try a social account that has no email or lacks a first/last name. Confirm the completion form collects the missing values rather than creating a usable patient account with incomplete data.</li>
                <li>Sign in again with the same social identity. Confirm no duplicate WordPress user or Cliniko patient is created.</li>
                <li>Let a verification link expire, then sign in again with the same social identity. Confirm one replacement email arrives, the old link fails, and only the newest link verifies the account.</li>
                <li>Test a temporary Cliniko outage. Confirm the account is not suspended and the shortcode connection notice appears.</li>
                <li>Test an already-linked patient that Cliniko has removed only in a staging environment. Confirm two live missing-patient confirmations are required before the Ultimate Member account becomes inactive.</li>
            </ol>

            <h2 id="ultimate-member">5. Protect pages with Ultimate Member</h2>
            <ol>
                <li>Edit the page in WordPress.</li>
                <li>In Ultimate Member’s content restriction settings, enable restriction for the page.</li>
                <li>Choose the logged-in/member access rules appropriate for your site.</li>
                <li>Place patient shortcodes, dashboard modules, account forms, or patient form templates on the restricted page.</li>
                <li>Test as an anonymous visitor, an ordinary logged-in user, an unverified patient, and a verified patient.</li>
            </ol>
            <p>Ultimate Member protects page display. The plugin also performs server-side checks on patient REST routes, so a user cannot select another user’s patient ID through the normal patient endpoints.</p>

            <h2 id="builder">6. Use the Template Builder</h2>
            <p>Open <a href="<?php echo esc_url($builder); ?>">Cliniko + Stripe → Template Builder</a>. The builder contains these areas:</p>
            <h3>Patient Forms</h3>
            <p>Create forms for patient details, completed patient forms, Cliniko form templates, and other account experiences. Save each form and copy its generated shortcode into a page or Elementor Shortcode widget.</p>
            <h3>Booking Forms</h3>
            <p>Create guest, authenticated-patient, dynamic, and renewal booking forms. Select the appointment type, patient form template, scheduling mode, payment gateway, steps, success behaviour, and optional webhook settings.</p>
            <h3>Dashboard Modules</h3>
            <p>Create appointment-list modules and configure appointment types, displayed columns, appointment-detail buttons, patient form columns, and pagination. Use the generated dashboard shortcode on a protected page.</p>
            <h3>Shortcodes</h3>
            <p>Browse the catalogue, copy shortcodes, configure redirect URLs, and inspect the available patient variables and appointment-count variables.</p>
            <h3>Emails</h3>
            <p>Customise the patient verification email, account-closure confirmation, and account-closure receipt. Each template has its own message, branding, and live mobile/desktop preview. The plugin places secure verification and closure URLs on their respective buttons automatically.</p>
            <h3>Custom Code</h3>
            <p>Store small named code snippets or integration values made available by the plugin’s supported extension points. Only administrators can manage this area.</p>
            <h3>Import / Export</h3>
            <p>Move saved Template Builder configurations between sites with a versioned JSON bundle. Export only the groups you need, then use <strong>Merge</strong> to preserve destination-only templates or confirmed <strong>Replace</strong> mode to overwrite the groups included in the file. Credentials, patient records, patient-account settings, verification-page settings, and uploaded font files are excluded. Review page and media URLs, Cliniko IDs, and Elementor IDs on the destination site after import.</p>

            <h2 id="shortcodes">7. Shortcodes</h2>
            <p>Place these in a WordPress page, post, widget, or Elementor Shortcode element. Replace IDs with the IDs shown in the builder.</p>
            <table class="widefat striped" style="max-width:1100px;">
                <thead><tr><th>Shortcode</th><th>Purpose</th></tr></thead>
                <tbody>
                    <?php foreach ([
                        ['[cliniko_patient_first_name]', 'Current patient first name.'],
                        ['[cliniko_patient_last_name]', 'Current patient last name.'],
                        ['[cliniko_patient_full_name]', 'Current patient full name.'],
                        ['[cliniko_patient_preferred_name fallback="Patient"]', 'Preferred name with a fallback.'],
                        ['[cliniko_patient_email]', 'Current patient email.'],
                        ['[cliniko_patient_phone]', 'Current patient phone.'],
                        ['[cliniko_patient_value field="FIELD" fallback="Not provided"]', 'An allowed standard or Cliniko custom patient field.'],
                        ['[cliniko_patient_appointments_total]', 'Total appointment count.'],
                        ['[cliniko_patient_appointments_upcoming]', 'Upcoming appointment count.'],
                        ['[cliniko_patient_appointments_completed]', 'Completed appointment count.'],
                        ['[cliniko_patient_appointment_count type="completed" fallback="0"]', 'Generic total, upcoming, or completed count.'],
                        ['[cliniko_patient_form id="FORM_ID"]', 'Editable authenticated patient details form.'],
                        ['[cliniko_patient_forms id="FORM_ID"]', 'Previously completed patient forms.'],
                        ['[cliniko_patient_form_template id="FORM_ID"]', 'Blank Cliniko template for an authenticated patient.'],
                        ['[cliniko_guest_booking_form id="FORM_ID"]', 'Guest booking and payment form.'],
                        ['[cliniko_patient_booking_form id="FORM_ID"]', 'Patient booking form.'],
                        ['[cliniko_patient_booking_renewal]', 'Appointment renewal form; derives its appointment type and patient-form template from the appointment_id URL parameter.'],
                        ['[cliniko_patient_details_module id="MODULE_ID"]', 'Display-only patient details module.'],
                        ['[cliniko_dashboard_module id="MODULE_ID"]', 'Appointment dashboard module.'],
                        ['[cliniko_appointment_details]', 'Authenticated appointment details page.'],
                        ['[cliniko_patient_verification success_url="/patient-dashboard/" login_after_verification="yes"]', 'Public confirmation page for patient verification emails. It can log in the verified patient before opening a protected destination.'],
                        ['[cliniko_patient_close_account]', 'Email-confirmed closure of the local patient portal account. This does not delete the Cliniko health record.'],
                    ] as $shortcode): ?>
                        <tr><td><code><?php echo esc_html($shortcode[0]); ?></code></td><td><?php echo esc_html($shortcode[1]); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 id="elementor">8. Elementor widgets</h2>
            <h3>Cliniko: Appointment Type Card</h3>
            <ol><li>Add the widget to an Elementor page.</li><li>Select an appointment type.</li><li>Configure the icon, price display, button text, button link, colours, and optional CSS class.</li><li>Preview the card and publish.</li></ol>
            <h3>Cliniko: Stripe Booking Form</h3>
            <ol><li>Add the widget to an Elementor page.</li><li>Select the booking form configuration.</li><li>Choose Embed mode when Cliniko should render the booking UI, or Custom Form mode for the plugin’s multi-step flow.</li><li>Configure patient details, scheduling, payment, success redirects, and optional webhooks.</li><li>Publish and test the complete flow.</li></ol>

            <h2 id="booking">9. Booking and scheduling</h2>
            <p>Custom Form mode normally follows this sequence:</p>
            <ol><li>Collect patient and booking information.</li><li>Choose <strong>Next Available Time</strong> or <strong>Calendar Selection</strong>.</li><li>Select a practitioner where applicable.</li><li>Show the payment step when the appointment requires payment.</li><li>Submit the booking. Background workers complete scheduling and patient-form processing.</li></ol>
            <p>Calendar mode displays available dates and times. Dates with no availability are disabled, and the selected time is stored with the practitioner context.</p>
            <p>Use the renewal form for an existing appointment. It uses the appointment ID to prefill the renewal flow.</p>

            <h2 id="payments">10. Payments</h2>
            <ul>
                <li><strong>Stripe:</strong> the browser collects payment details using Stripe’s public key; the secret key remains server-side.</li>
                <li><strong>Tyro Health:</strong> the server mints a short-lived SDK token, then the Tyro flow handles the transaction.</li>
                <li><strong>Free appointments:</strong> do not require a payment transaction.</li>
                <li>Payment and booking status are checked server-side before an attempt is marked complete.</li>
            </ul>

            <h2 id="webhooks">11. Webhooks</h2>
            <ol>
                <li>Edit the custom booking form in Elementor.</li>
                <li>Enable <strong>Webhooks</strong> and enter the receiving URL.</li>
                <li>Select one or more events: <code>booking.preflighted</code>, <code>payment.verified</code>, <code>booking.completed</code>, and <code>booking.failed</code>.</li>
                <li>Optionally provide a signing secret. If blank, the plugin generates and stores one server-side.</li>
                <li>Save the Elementor page. Webhooks are queued through Action Scheduler.</li>
                <li>Verify the <code>X-Cliniko-Webhook-Signature</code> header using HMAC-SHA256 over <code>timestamp.raw_json_body</code>, after removing the <code>sha256=</code> prefix.</li>
            </ol>
            <p>Webhook payloads intentionally exclude clinical form answers, Medicare data, payment card details, and access tokens. Failed deliveries are retried.</p>

            <h2 id="headless">12. Headless custom-form mode</h2>
            <ol><li>Configure a custom booking form with the headless option.</li><li>Build your own interface using <code>formHandlerData.sections</code> and <code>formHandlerData.submission_template</code>.</li><li>For calendars, load practitioners, fetch the month grid, fetch times for a selected date, and call <code>updateHeadlessPatient({ appointment_start, practitioner_id })</code>.</li><li>Expose the completed payload as <code>window.clinikoHeadlessPayload</code> or <code>window.clinikoGetHeadlessPayload()</code>.</li><li>Show the Stripe or Tyro payment UI when the payload is ready.</li></ol>

            <h2 id="operations">13. Background processing and maintenance</h2>
            <p>Action Scheduler processes booking, patient-form, webhook, and cleanup jobs. WordPress Cron is used as a fallback. Keep WP-Cron or a real server cron running on production sites.</p>
            <p>Open <a href="<?php echo esc_url($tools); ?>">Cliniko + Stripe → Tools</a> to test connectivity, clear cached Cliniko responses, and run available maintenance actions.</p>
            <p>Open <a href="<?php echo esc_url($debug); ?>">Cliniko + Stripe → Debug</a> to enable temporary observability, inspect REST/API timing and errors, export filtered logs, or clear logs. Disable debug capture after troubleshooting.</p>

            <h2 id="troubleshooting">14. Troubleshooting checklist</h2>
            <ol>
                <li><strong>Users cannot access patient pages:</strong> confirm Ultimate Member is active, the page is configured correctly, the user has a configured patient role, and the verification link was completed.</li>
                <li><strong>Patient data is unavailable:</strong> check the linked patient ID, patient verification status, WordPress email, Cliniko credentials, business, shard, and API connectivity.</li>
                <li><strong>Calendar is empty:</strong> confirm Custom Form mode, Calendar Selection mode, appointment type, practitioner, date range, and availability in Cliniko.</li>
                <li><strong>Payment does not open:</strong> confirm the selected gateway credentials and that the final wizard action continues into the payment flow.</li>
                <li><strong>Webhooks are missing:</strong> confirm the Elementor page was saved, the URL is reachable, the event is selected, and Action Scheduler is running.</li>
                <li><strong>Old Cliniko data appears:</strong> clear the API cache from Tools or reduce the cache TTL.</li>
                <li><strong>Unexpected authentication behaviour:</strong> temporarily inspect the authentication switches in Debug, reproduce the issue one switch at a time, then re-enable all required protections.</li>
                <li><strong>A pending social user can access a protected page:</strong> confirm Nextend's <em>Support login restrictions</em> is enabled, the patient role uses <em>Require Admin Review</em>, the same role is selected in Patient Accounts, and the Debug authentication switch <em>Treat pending patient accounts as logged out</em> remains enabled. Sign out and test in a private browser window to remove an earlier social-login cookie.</li>
            </ol>

            <h2 id="security">15. Security and privacy rules</h2>
            <ul>
                <li>Use HTTPS and keep WordPress, Elementor, Ultimate Member, and this plugin updated.</li>
                <li>Never expose Cliniko, Stripe secret, or Tyro Business Admin credentials in frontend code.</li>
                <li>Use Ultimate Member restrictions on every page containing patient information.</li>
                <li>Do not place patient access tokens or private booking tokens in logs, emails, or webhook payloads.</li>
                <li>Test access with multiple patient accounts before publishing.</li>
                <li>Limit administrator access to the plugin settings, builder, tools, and debug pages.</li>
            </ul>
            <h3>Patient account closure</h3>
            <p>Place <code>[cliniko_patient_close_account]</code> on a page restricted to logged-in patients and exclude that page from every full-page/CDN cache. The shortcode sends no-cache headers when WordPress renders it. Use <code>display="modal"</code> to render a button that opens the warning and acknowledgement dialog; customise that button with <code>trigger_label="Close my account"</code>. The optional <code>success_url</code> attribute selects the same-site page shown after closure. When the email confirmation URL returns to a modal shortcode, the final confirmation dialog opens automatically. The patient must acknowledge the clinical-record distinction, receive a 30-minute email link, return while signed in, and submit a final confirmation. Opening the email link alone never deletes the account.</p>
            <p>Edit the <strong>Closure confirmation</strong> and <strong>Closure receipt</strong> tabs under <strong>Template Builder &rarr; Email Templates</strong> to customise their wording, logo, colours, and preview. The confirmation link is attached to the button automatically and cannot be removed accidentally.</p>
            <p>Closure deletes the WordPress user and Ultimate Member profile, lets Nextend remove its social identity link through WordPress's user-deletion hook, destroys sessions, clears the patient's encrypted dashboard cache, and emails a closure receipt. It deliberately does not call Cliniko's archive or delete operations. Requests concerning the Cliniko health record must be handled separately by clinic staff under the clinic's retention and privacy obligations.</p>
                </main>
            </div>
            <div class="cliniko-docs-empty" data-cliniko-docs-empty hidden>
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <h2>No matching documentation</h2>
                <p>Try a broader term or clear the search field.</p>
            </div>
        </div>
        <?php
    }

    /**
     * @return list<array{
     *     title:string,
     *     description:string,
     *     complete:bool,
     *     checks:list<array{label:string,instruction:string,complete:bool}>,
     *     actions:list<array{label:string,url:string,primary:bool}>,
     *     note:string
     * }>
     */
    private static function setupSteps(
        string $settingsUrl,
        string $accountsUrl,
        string $builderUrl,
        string $verificationEmailUrl
    ): array
    {
        $roles = get_option('wp_cliniko_patient_sync_roles', []);
        $roles = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        $strictRole = sanitize_key((string) get_option('wp_cliniko_patient_strict_role', ''));

        $ultimateMemberActive = function_exists('UM') || isset($GLOBALS['ultimatemember']);
        $nextendActive = defined('NSL_PATH_FILE') || class_exists('NextendSocialLogin', false);

        $clinikoApiKey = function_exists('wp_cliniko_get_secret_option')
            ? trim((string) wp_cliniko_get_secret_option('wp_cliniko_api_key'))
            : '';
        $clinikoBusinessSelected = trim((string) get_option('wp_cliniko_business_id', '')) !== '';
        $clinikoShardValid = preg_match('/^[a-z]{2}\d+$/', (string) get_option('wp_cliniko_shard', '')) === 1;

        $patientSyncEnabled = (bool) get_option('wp_cliniko_patient_sync_enabled', false);
        $strictRoleSelected = $strictRole !== '';
        $strictRoleIncluded = $strictRoleSelected && in_array($strictRole, $roles, true);

        $roleKey = strpos($strictRole, 'um_') === 0 ? substr($strictRole, 3) : $strictRole;
        $roleMeta = $roleKey !== '' ? get_option('um_role_' . $roleKey . '_meta', []) : [];
        $ultimateMemberReviewRequired = is_array($roleMeta)
            && ($roleMeta['_um_status'] ?? '') === 'pending';

        $wpRoles = wp_roles();
        $strictRoleLabel = $strictRole;
        if ($strictRole !== '' && isset($wpRoles->roles[$strictRole]['name'])) {
            $strictRoleLabel = translate_user_role((string) $wpRoles->roles[$strictRole]['name']);
        }
        $patientRoleDisplay = $strictRoleLabel !== ''
            ? sprintf('%s (%s)', $strictRoleLabel, $strictRole)
            : 'the patient role selected in Patient Accounts';

        // Nextend serializes its settings before passing them to update_option(),
        // so get_option() returns one remaining serialized layer here.
        $nextendSettings = maybe_unserialize(get_option('nextend_social_login', []));
        $nextendSettings = is_array($nextendSettings) ? $nextendSettings : [];
        $nextendRegistration = (int) ($nextendSettings['allow_register'] ?? -1);
        $wordpressRegistration = (bool) get_option('users_can_register', false);
        $nextendRegistrationAllowed = $nextendRegistration === 1
            || ($nextendRegistration === -1 && $wordpressRegistration);
        $nextendLoginRestrictions = (int) ($nextendSettings['login_restriction'] ?? 0) === 1;
        $nextendProviderEnabled = is_array($nextendSettings['enabled'] ?? null)
            && $nextendSettings['enabled'] !== [];
        $wordpressDefaultRole = sanitize_key((string) get_option('default_role', 'subscriber'));
        $wordpressDefaultRoleMatches = $strictRoleIncluded && $wordpressDefaultRole === $strictRole;
        $wordpressSettingsNeedAttention = !$wordpressRegistration || !$wordpressDefaultRoleMatches;
        $nextendSettingsNeedAttention = !$nextendActive
            || !$nextendRegistrationAllowed
            || !$nextendProviderEnabled
            || !$nextendLoginRestrictions;
        $nextendDefaultLoginRedirect = wp_validate_redirect(
            (string) ($nextendSettings['default_redirect'] ?? ''),
            ''
        );
        $nextendDefaultRegistrationRedirect = wp_validate_redirect(
            (string) ($nextendSettings['default_redirect_reg'] ?? ''),
            ''
        );
        $nextendRegistrationPageId = $nextendDefaultRegistrationRedirect !== ''
            ? url_to_postid($nextendDefaultRegistrationRedirect)
            : 0;
        $nextendRegistrationPage = $nextendRegistrationPageId > 0
            ? get_post($nextendRegistrationPageId)
            : null;
        $nextendRegistrationGuidancePageReady = $nextendRegistrationPage instanceof \WP_Post
            && $nextendRegistrationPage->post_status === 'publish'
            && $nextendRegistrationPage->post_password === '';
        $nextendDefaultRedirectsDiffer = $nextendDefaultLoginRedirect !== ''
            && $nextendDefaultRegistrationRedirect !== ''
            && untrailingslashit($nextendDefaultLoginRedirect) !== untrailingslashit($nextendDefaultRegistrationRedirect);
        $nextendFixedRedirectsEmpty = trim((string) ($nextendSettings['redirect'] ?? '')) === ''
            && trim((string) ($nextendSettings['redirect_reg'] ?? '')) === '';
        $nextendContextRedirectsAllowed = (int) ($nextendSettings['redirect_prevent_external'] ?? 0) === 0;
        $nextendRedirectActions = [[
            'label' => 'Open Nextend Redirect Settings',
            'url' => admin_url('options-general.php?page=nextend-social-login&view=global-settings&subview=general'),
            'primary' => true,
        ]];
        $enabledProviders = is_array($nextendSettings['enabled'] ?? null)
            ? $nextendSettings['enabled']
            : [];
        foreach ($enabledProviders as $provider) {
            $providerId = sanitize_key(is_string($provider) ? $provider : '');
            if ($providerId === '') {
                continue;
            }
            $nextendRedirectActions[] = [
                'label' => 'Open ' . ucfirst($providerId) . ' Callback Guide',
                'url' => admin_url(
                    'options-general.php?page=nextend-social-login&view=provider-'
                    . rawurlencode($providerId)
                    . '&subview=getting-started'
                ),
                'primary' => false,
            ];
        }

        $verificationTemplate = get_option(PatientVerificationEmailTemplate::OPTION, []);
        $verificationTemplateReviewed = is_array($verificationTemplate) && $verificationTemplate !== [];
        $verificationPageUrl = PatientLinkService::verificationPageUrl();
        $nextendWelcomeEmailSuppressed = PatientLinkService::suppressesNextendWelcomeEmail();

        $builderReady = false;
        foreach ([
            'wp_cliniko_patient_account_forms',
            'wp_cliniko_patient_onboardings',
            'wp_cliniko_dashboard_modules',
            'wp_cliniko_dashboard_patient_details',
            'wp_cliniko_patient_attachment_modules',
            'wp_cliniko_patient_communication_modules',
        ] as $option) {
            $configurations = get_option($option, []);
            if (is_array($configurations) && $configurations !== []) {
                $builderReady = true;
                break;
            }
        }

        $steps = [
            self::setupStep(
                'Activate the account plugins',
                'These plugins provide the patient account status and the social sign-in buttons.',
                [
                    [
                        'label' => 'Ultimate Member is active',
                        'instruction' => 'Go to Plugins and activate Ultimate Member.',
                        'complete' => $ultimateMemberActive,
                    ],
                    [
                        'label' => 'Nextend Social Login is active',
                        'instruction' => 'Go to Plugins and activate Nextend Social Login and Register.',
                        'complete' => $nextendActive,
                    ],
                ],
                [
                    ['label' => 'Open Plugins', 'url' => admin_url('plugins.php'), 'primary' => true],
                ],
                ''
            ),
            self::setupStep(
                'Connect Cliniko',
                'The plugin needs a valid Cliniko account connection before it can verify a patient.',
                [
                    [
                        'label' => 'Cliniko API key is saved',
                        'instruction' => 'Open API Settings, paste the API key, and save the settings.',
                        'complete' => $clinikoApiKey !== '',
                    ],
                    [
                        'label' => 'Cliniko shard is valid',
                        'instruction' => 'Enter the shard from your Cliniko URL, for example au1 or uk2.',
                        'complete' => $clinikoShardValid,
                    ],
                    [
                        'label' => 'A Cliniko business is selected',
                        'instruction' => 'Test the connection, select the required business, and save again.',
                        'complete' => $clinikoBusinessSelected,
                    ],
                ],
                [
                    ['label' => 'Open API Settings', 'url' => $settingsUrl, 'primary' => true],
                ],
                ''
            ),
            self::setupStep(
                'Configure patient synchronization',
                'Choose exactly which WordPress users must be verified against Cliniko.',
                [
                    [
                        'label' => 'Patient synchronization is enabled',
                        'instruction' => 'Open Patient Accounts and switch on patient synchronization.',
                        'complete' => $patientSyncEnabled,
                    ],
                    [
                        'label' => 'At least one patient role is selected',
                        'instruction' => 'Under Synchronization Roles, select every role used by patient accounts.',
                        'complete' => $roles !== [],
                    ],
                    [
                        'label' => 'The strict synchronization role is selected',
                        'instruction' => 'Choose the same patient role in Strict Synchronization Role.',
                        'complete' => $strictRoleIncluded,
                    ],
                ],
                [
                    ['label' => 'Open Patient Accounts', 'url' => $accountsUrl, 'primary' => true],
                ],
                $strictRoleIncluded ? 'Current patient role: ' . $patientRoleDisplay . '.' : ''
            ),
            self::setupStep(
                'Require Ultimate Member review',
                'New patient accounts must remain pending until this plugin confirms that the email belongs to a Cliniko patient.',
                [
                    [
                        'label' => 'A strict patient role is available',
                        'instruction' => 'Complete Patient Accounts first and choose the role used for new patients.',
                        'complete' => $strictRoleIncluded,
                    ],
                    [
                        'label' => 'Registration Status is Require Admin Review',
                        'instruction' => 'Edit ' . $patientRoleDisplay . ' in Ultimate Member and set Registration Status to Require Admin Review.',
                        'complete' => $ultimateMemberActive && $strictRoleIncluded && $ultimateMemberReviewRequired,
                    ],
                ],
                [
                    [
                        'label' => $strictRoleIncluded ? 'Edit Patient Role' : 'Open Ultimate Member Roles',
                        'url' => $strictRoleIncluded
                            ? add_query_arg(['page' => 'um_roles', 'tab' => 'edit', 'id' => $roleKey], admin_url('admin.php'))
                            : admin_url('admin.php?page=um_roles'),
                        'primary' => true,
                    ],
                    ['label' => 'Open Patient Accounts', 'url' => $accountsUrl, 'primary' => false],
                ],
                'Do not use Auto Approve for the patient role. Require Admin Review is what lets the Cliniko verification decide whether access is granted.'
            ),
            self::setupStep(
                'Configure social registration',
                'Set Nextend to create a pending patient account, then let Ultimate Member and this plugin control access.',
                [
                    [
                        'label' => 'WordPress registration is allowed',
                        'instruction' => 'Settings → General → Membership: tick “Anyone can register”.',
                        'complete' => $wordpressRegistration,
                    ],
                    [
                        'label' => 'Nextend allows social registration',
                        'instruction' => 'Settings → Nextend Social Login → Global Settings → General → Membership: choose Enabled. “WordPress default” also works while Anyone can register is on.',
                        'complete' => $nextendActive && $nextendRegistrationAllowed,
                    ],
                    [
                        'label' => 'At least one social provider is enabled',
                        'instruction' => 'In Nextend, configure Google, Facebook, or another provider, then switch that provider to Enabled.',
                        'complete' => $nextendActive && $nextendProviderEnabled,
                    ],
                    [
                        'label' => 'Support login restrictions is enabled',
                        'instruction' => 'Nextend → Global Settings → General → Support login restrictions: choose Enabled.',
                        'complete' => $nextendActive && $nextendLoginRestrictions,
                    ],
                    [
                        'label' => 'New User Default Role matches the patient role',
                        'instruction' => 'Settings → General → New User Default Role: choose ' . $patientRoleDisplay . '.',
                        'complete' => $wordpressDefaultRoleMatches,
                    ],
                ],
                [
                    [
                        'label' => 'Open WordPress Registration Settings',
                        'url' => admin_url('options-general.php'),
                        'primary' => $wordpressSettingsNeedAttention,
                    ],
                    [
                        'label' => 'Open Nextend Settings',
                        'url' => admin_url('options-general.php?page=nextend-social-login'),
                        'primary' => !$wordpressSettingsNeedAttention && $nextendSettingsNeedAttention,
                    ],
                    ['label' => 'Review Patient Role', 'url' => $accountsUrl, 'primary' => false],
                ],
                'If Nextend Pro assigns a provider-specific role, set that provider override to ' . $patientRoleDisplay . ' as well. Provider-specific overrides cannot be verified automatically here.'
            ),
            self::setupStep(
                'Configure social-login redirects',
                'Send verified patients to the dashboard and new, unverified patients to a public check-email page without confusing those destinations with the OAuth callback.',
                [
                    [
                        'label' => 'A default login redirect is configured',
                        'instruction' => 'Current value: ' . ($nextendDefaultLoginRedirect !== '' ? $nextendDefaultLoginRedirect : 'not configured') . '. Nextend → Global Settings → General → Default redirect URL: enable “for Login” and enter the protected patient dashboard URL.',
                        'complete' => $nextendActive && $nextendDefaultLoginRedirect !== '',
                    ],
                    [
                        'label' => 'Registration redirects to a published guidance page',
                        'instruction' => 'Current value: ' . ($nextendDefaultRegistrationRedirect !== '' ? $nextendDefaultRegistrationRedirect : 'not configured') . '. In Default redirect URL, enable “for Register” and select a published, non-password-protected Verify your email or Check your inbox page.',
                        'complete' => $nextendActive && $nextendRegistrationGuidancePageReady,
                    ],
                    [
                        'label' => 'Login and registration use different destinations',
                        'instruction' => 'Login should lead to the protected dashboard; registration should lead to a public page explaining that verification is required.',
                        'complete' => $nextendActive && $nextendDefaultRedirectsDiffer,
                    ],
                    [
                        'label' => 'Fixed redirect URLs are empty',
                        'instruction' => 'Leave both Fixed redirect URL checkboxes disabled unless you deliberately want to override every originating-page redirect.',
                        'complete' => $nextendActive && $nextendFixedRedirectsEmpty,
                    ],
                    [
                        'label' => 'Originating-page redirects are allowed',
                        'instruction' => 'Leave “Prevent external redirect overrides → Disable external redirects” unchecked so valid redirect_to destinations can be preserved.',
                        'complete' => $nextendActive && $nextendContextRedirectsAllowed,
                    ],
                ],
                $nextendRedirectActions,
                'Manual provider check: for every enabled provider, open its Getting Started tab and copy the exact callback URI into that provider’s developer console. Callback URIs return control to Nextend; they are not the patient dashboard or check-email destination.'
            ),
            self::setupStep(
                'Configure registration email delivery',
                'Make the Cliniko verification message the patient’s only registration email, then verify that WordPress can deliver it reliably.',
                [
                    [
                        'label' => 'The Cliniko verification email has been reviewed and saved',
                        'instruction' => 'Open Email Templates, review the subject, message, branding, expiry wording, and save the template.',
                        'complete' => $verificationTemplateReviewed,
                    ],
                    [
                        'label' => 'A public patient verification page is configured',
                        'instruction' => 'Create a public, uncached page containing [cliniko_patient_verification success_url="/patient-dashboard/" login_after_verification="yes"], then save its full URL in the Patient verification email settings.',
                        'complete' => $verificationPageUrl !== '',
                    ],
                    [
                        'label' => 'The separate WordPress set-password email is suppressed',
                        'instruction' => 'In Email Templates, enable “Send only the Cliniko verification email to new social patients”.',
                        'complete' => $nextendWelcomeEmailSuppressed,
                    ],
                ],
                [
                    [
                        'label' => 'Configure Verification Email',
                        'url' => $verificationEmailUrl,
                        'primary' => !$verificationTemplateReviewed || $verificationPageUrl === '' || !$nextendWelcomeEmailSuppressed,
                    ],
                ],
                'Mail transport is a manual production check: configure an authenticated SMTP or transactional provider for wp_mail(), set SPF and DKIM for the sender domain, then complete a real social registration in a private browser. The patient should receive only the Cliniko verification email; the site administrator can still receive the WordPress new-user notification.'
            ),
            self::setupStep(
                'Build the patient experience',
                'Create the content patients will use, then test registration and access from beginning to end.',
                [
                    [
                        'label' => 'At least one patient form or dashboard module exists',
                        'instruction' => 'Use the Template Builder to create and save a patient-facing form or module.',
                        'complete' => $builderReady,
                    ],
                ],
                [
                    ['label' => 'Open Template Builder', 'url' => $builderUrl, 'primary' => true],
                ],
                'Final manual test: publish the generated shortcode on an Ultimate Member protected page, register in a private browser, and confirm that an unverified patient cannot open the page.'
            ),
        ];

        return $steps;
    }

    /**
     * @param list<array{label:string,instruction:string,complete:bool}> $checks
     * @param list<array{label:string,url:string,primary:bool}> $actions
     * @return array{
     *     title:string,
     *     description:string,
     *     complete:bool,
     *     checks:list<array{label:string,instruction:string,complete:bool}>,
     *     actions:list<array{label:string,url:string,primary:bool}>,
     *     note:string
     * }
     */
    private static function setupStep(
        string $title,
        string $description,
        array $checks,
        array $actions,
        string $note
    ): array {
        $complete = !in_array(false, array_column($checks, 'complete'), true);

        return compact('title', 'description', 'complete', 'checks', 'actions', 'note');
    }
}
