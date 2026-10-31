<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

/** Setup guidance only. Selecting a service never changes the token-validation policy. */
final class SignInProviders
{
    public static function catalog(): array
    {
        $catalog = [
            'custom' => [
                'name' => 'Other compatible service / custom adapter', 'can_enable' => true,
                'status' => 'Compatibility must be verified',
                'notice' => 'Use this for another service or a separately implemented token-checking adapter. Your developer must verify it meets this plugin\'s requirements. Selecting it does not add an adapter.',
                'steps' => ['Identify the service that will handle patient sign-in. Ask its administrator where to find the issuer, API audience and token-checking endpoint.', 'Register the phone/browser app for browser-based login. Separately obtain the website\'s ID and private password for checking that app\'s tokens.', 'Ask the developer to confirm the website may check tokens issued to the patient app and that all required response fields are returned.', 'Copy the verified values below. Save, check the connection, approve the app locally and test a real patient login.'],
                'locations' => [
                    'issuer' => 'Ask the service administrator for the exact issuer address. Do not derive it from the login page.',
                    'audience' => 'Ask for the resource/API audience configured at the service; it must match the token-check response.',
                    'introspection_url' => 'Ask for a public HTTPS RFC 7662 endpoint accepting client-secret Basic authentication.',
                    'introspection_client' => 'Obtain the confidential website client ID that may check the patient app\'s tokens.',
                    'introspection_secret' => 'Obtain that website client\'s private secret securely. Do not use a patient password.',
                ],
                'links' => [['label' => 'Token-checking standard (developer reference)', 'url' => 'https://www.rfc-editor.org/rfc/rfc7662']],
            ],
            'keycloak' => [
                'name' => 'Keycloak', 'can_enable' => true, 'status' => 'Provider-side configuration required',
                'endpoint_hint' => ['pattern' => '/realms/[^/]+/?$', 'suffix' => '/protocol/openid-connect/token/introspect'],
                'notice' => 'Keycloak offers the token-checking method this plugin uses. Its realm, audience and permissions still need configuration. This guide is not evidence that your installation has been tested.',
                'steps' => ['In the Keycloak Admin Console, choose the clinic\'s realm. A realm is the group of accounts and apps sharing your sign-in setup.', 'Under Clients, create a public patient-app client: Client authentication off, Standard flow on, exact redirect addresses and PKCE S256. Keep Direct access grants off.', 'Create a separate website client with Client authentication on and Client ID and Secret credentials. Copy its Client ID and the secret from Credentials.', 'Configure the API audience and required scopes. Include the website client ID in the token audience when your version requires it for introspection; do not disable audience checking. Ensure required claims reach introspection.', 'Open the realm\'s OpenID configuration document to obtain the exact issuer and introspection endpoint. Copy the values below, then complete the app approval and patient test.'],
                'locations' => [
                    'issuer' => 'Copy issuer from the realm\'s OpenID configuration. Typical shape: https://login.example.com/realms/clinic. A reverse-proxy base path may also be present.',
                    'audience' => 'Use the API audience configured in the patient app\'s Audience mapper, not automatically the realm name or mobile app ID.',
                    'introspection_url' => 'Copy introspection_endpoint from OpenID configuration. Usually the issuer address followed by /protocol/openid-connect/token/introspect.',
                    'introspection_client' => 'Clients → your confidential website client → Client ID. This is not the public patient-app client.',
                    'introspection_secret' => 'The same website client → Credentials → Client secret. Keep it on WordPress only.',
                ],
                'links' => [['label' => 'Keycloak endpoints', 'url' => 'https://www.keycloak.org/securing-apps/oidc-layers'], ['label' => 'Keycloak clients, credentials and audiences', 'url' => 'https://www.keycloak.org/docs/latest/server_admin/index.html'], ['label' => 'Introspection audience checks (developer reference)', 'url' => 'https://github.com/keycloak/keycloak/blob/main/services/src/main/java/org/keycloak/protocol/oidc/AccessTokenIntrospectionProvider.java']],
            ],
            'okta' => [
                'name' => 'Okta — custom authorization server', 'can_enable' => true, 'status' => 'Custom server and permissions required',
                'endpoint_hint' => ['pattern' => '^/oauth2/[^/]+/?$', 'suffix' => '/v1/introspect'],
                'notice' => 'Use a custom authorization server for your clinic API, not the Okta org server. Confirm your plan includes the required feature and verify the website client can check the patient app\'s tokens.',
                'steps' => ['In the Okta Admin Console, open Security → API → Authorization Servers. Select or create the custom server used for this clinic API.', 'In that server\'s settings, record its Issuer and Audience. Define the API scopes and an access policy/rule allowing the patient app\'s intended access.', 'Under Applications, register the patient app for Authorization Code with PKCE and its exact redirect addresses. It must not contain a shared website password.', 'Arrange a separate confidential client using client_secret_basic for website token checks. Copy its Client ID and Client secret securely. Have your developer verify cross-client introspection access.', 'Use the custom server\'s introspection endpoint. Save, run the connection check and test an actual patient token before rollout.'],
                'locations' => [
                    'issuer' => 'Security → API → Authorization Servers → your custom server → Settings → Issuer. Typical shape: https://your-org.okta.com/oauth2/server-id.',
                    'audience' => 'The same custom server → Settings → Audience. Copy that exact value, not the app client ID.',
                    'introspection_url' => 'Copy introspection_endpoint from the custom server\'s OpenID configuration. Usually issuer followed by /v1/introspect. Do not use the org-server endpoint.',
                    'introspection_client' => 'The confidential website application\'s Client ID. Confirm it supports client_secret_basic for this token check.',
                    'introspection_secret' => 'The same website application\'s Client secret, supplied through a secure channel.',
                ],
                'links' => [['label' => 'Okta authorization server types', 'url' => 'https://developer.okta.com/docs/concepts/auth-servers/'], ['label' => 'Create a custom authorization server', 'url' => 'https://developer.okta.com/docs/guides/customize-authz-server/main/'], ['label' => 'Okta client authentication', 'url' => 'https://developer.okta.com/docs/api/openapi/okta-oauth/guides/client-auth'], ['label' => 'Dedicated token-checking client example', 'url' => 'https://developer.okta.com/blog/2018/07/06/secure-api-mulesoft']],
            ],
            'authentik' => [
                'name' => 'authentik', 'can_enable' => true, 'status' => 'Extra response configuration or adapter needed',
                'notice' => 'Do not assume a default authentik setup is compatible. Its standard response may lack token_type, client_id and scope required by this plugin. Your developer must configure verified response fields or implement a compatible adapter, then test a real token.',
                'steps' => ['In the authentik administrator interface, create an application and public OAuth2/OpenID provider for the patient app. Configure its exact redirect addresses and PKCE login.', 'Create a separate confidential provider for the website connection. On the patient app\'s provider, add this website provider under Federated OAuth2/OpenID Providers so it may check that app\'s tokens.', 'Copy the patient app provider\'s issuer from its OpenID configuration. Per-application issuer addresses and global issuer mode differ.', 'Ask the developer to verify the audience and all required introspection fields on your deployed version. A reachable endpoint alone is not sufficient.', 'Only after compatibility is confirmed, enter the values, save, check the connection, approve the patient app and test a patient login.'],
                'locations' => [
                    'issuer' => 'The patient app provider\'s OpenID configuration → issuer. Default shape: https://login.example.com/application/o/app-slug/. Preserve the trailing slash.',
                    'audience' => 'Obtain the intended API audience from the verified token-check response/configuration. Do not assume a separate API audience exists by default.',
                    'introspection_url' => 'Copy introspection_endpoint from provider metadata. The standard global path is /application/o/introspect/.',
                    'introspection_client' => 'The confidential website OAuth2 provider → Client ID. Grant it directional access on the patient app provider first.',
                    'introspection_secret' => 'That confidential provider → Client secret. Keep it on the website, not in the patient app.',
                ],
                'links' => [['label' => 'authentik endpoints and cross-provider access', 'url' => 'https://docs.goauthentik.io/add-secure-apps/providers/oauth2/'], ['label' => 'Confidential token checks', 'url' => 'https://docs.goauthentik.io/add-secure-apps/providers/oauth2/machine_to_machine'], ['label' => 'Response implementation (developer reference)', 'url' => 'https://github.com/goauthentik/authentik/blob/main/authentik/providers/oauth2/views/introspection.py'], ['label' => 'Response claims implementation (developer reference)', 'url' => 'https://github.com/goauthentik/authentik/blob/main/authentik/providers/oauth2/id_token.py']],
            ],
            'auth0' => [
                'name' => 'Auth0', 'can_enable' => false, 'status' => 'Documented validation method not implemented here',
                'notice' => 'Auth0 documents signature-based validation of its API access tokens. This plugin currently checks tokens through a different method. Selecting Auth0 does not add that missing validation support; do not invent an introspection URL.',
                'steps' => ['Have your developer review Auth0\'s API access-token validation requirements before you fill in this form.', 'Plan either dedicated JWT validation support in the plugin or a separately implemented, security-reviewed compatible introspection adapter.', 'For a verified adapter, choose Other compatible service / custom adapter and use its actual endpoint and credentials. Do not substitute /userinfo or the token-issuing endpoint.', 'Keep patient sign-in off until that additional work is complete. Existing Auth0 tenant/app details alone are not sufficient.'],
                'locations' => [],
                'links' => [['label' => 'Auth0 API access-token validation', 'url' => 'https://auth0.com/docs/secure/tokens/access-tokens/validate-access-tokens']],
            ],
            'entra' => [
                'name' => 'Microsoft Entra ID', 'can_enable' => false, 'status' => 'Documented validation method not implemented here',
                'notice' => 'Entra API tokens use signature-based validation not implemented by this checker. A tenant ID, app ID and secret are not enough. Its native-authentication introspect endpoint is for MFA methods, not this plugin\'s token check.',
                'steps' => ['Have your developer review Entra access-token validation for your intended API and account type.', 'Plan dedicated JWT validation support or a separately implemented, security-reviewed compatible introspection adapter.', 'Do not paste the native-authentication /introspect, Microsoft Graph or token-issuing endpoint into the login-checking address.', 'For a verified adapter, choose Other compatible service / custom adapter and use its actual connection details. Keep sign-in off until that work is complete.'],
                'locations' => [],
                'links' => [['label' => 'Microsoft Entra access-token validation', 'url' => 'https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens'], ['label' => 'Native authentication introspect has a different purpose', 'url' => 'https://learn.microsoft.com/en-us/entra/identity-platform/reference-native-authentication-overview']],
            ],
        ];
        $catalog['google_keycloak'] = [
            'name' => 'Google sign-in through Keycloak', 'can_enable' => true,
            'status' => 'Google login with a configured Keycloak connection',
            'endpoint_hint' => $catalog['keycloak']['endpoint_hint'],
            'notice' => 'Patients can choose Google at login, but this API receives and checks Keycloak access tokens. First complete the Keycloak setup. Selecting this guide does not configure Google or link patient accounts automatically.',
            'steps' => ['Set up the Keycloak realm, public patient-app client and confidential website client using the Keycloak guide. Keep its API audience and permission checks.', 'In Keycloak, open Identity Providers, add Google, and copy the Redirect URI that Keycloak displays.', 'In Google Cloud Console, use the Google Auth Platform Clients page to create a Web application OAuth client for the broker. Complete the consent setup and add the exact Keycloak Redirect URI.', 'Copy that Google client ID and secret into the Google identity provider in Keycloak, not into these WordPress website-connection fields. Keep the secret out of the phone app.', 'Test Google login through Keycloak. Approve the app using its public Keycloak client ID and link the test patient using their Keycloak subject ID, not their Google email.', 'Continue this wizard with the Keycloak connection values below. Test that a signed-in patient sees only their own information.'],
            'collect' => ['Keycloak issuer and configured API audience.', 'Confidential Keycloak website client ID and secret, delivered securely.', 'Public Keycloak patient-app client ID for local application approval.', 'The verified test patient\'s Keycloak subject ID for their existing WordPress patient account.'],
            'locations' => array_replace($catalog['keycloak']['locations'], [
                'issuer' => 'Use the Keycloak realm issuer, not https://accounts.google.com. Google confirms identity upstream; Keycloak issues the pass this API checks.',
                'introspection_url' => 'Use the realm\'s Keycloak introspection_endpoint. Do not use a Google tokeninfo, login or token-issuing URL.',
                'introspection_secret' => 'Use the confidential Keycloak website client secret. The separate Google broker client secret stays in Keycloak.',
            ]),
            'links' => [['label' => 'Keycloak Google identity-provider setup', 'url' => 'https://www.keycloak.org/docs/latest/server_admin/index.html#_google'], ['label' => 'Create a Google web client', 'url' => 'https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid'], ['label' => 'Keycloak token-checking endpoints', 'url' => 'https://www.keycloak.org/securing-apps/oidc-layers']],
        ];
        $catalog['firebase'] = [
            'name' => 'Google Firebase Authentication', 'can_enable' => false,
            'status' => 'Guidance only — direct validation not implemented',
            'notice' => 'Firebase can manage your app\'s sign-in, including Google login. This plugin does not currently verify Firebase ID tokens directly. Project IDs or Firebase API keys pasted into the current fields will not add that support.',
            'steps' => ['Create or select the clinic\'s Firebase project. In Project settings, record its Project ID and register each app platform you will use.', 'In the Firebase console, open Authentication → Sign-in method. Enable the required methods, such as Google or email/password. Follow the Android, Apple or web instructions for your actual app.', 'Have the developer integrate Firebase Authentication in the app and confirm a test user can sign in. Successful Firebase login alone does not grant access to this clinic API.', 'Before connecting directly, implement Firebase token verification or a separately implemented, security-reviewed compatible adapter. Check signatures, project identity, expiry and the chosen revocation policy; keep local app permissions and verified patient linking.', 'Keep patient sign-in off here while that work is unfinished. For a working compatible adapter, choose the custom-service path and enter only its documented connection values.', 'Agree the test plan: the linked patient sees only their own data; an unlinked account cannot see records; expired or revoked login passes are handled correctly.'],
            'collect' => ['Firebase console → Project settings → General → Project ID. This is not the Firebase API key.', 'Registered Android, Apple or web app details and enabled sign-in methods for your developer.', 'Firebase user UID obtained from the test account and verified on the server, not guessed from an email.', 'The developer\'s plan for API validation and patient linking. Do not paste a service-account JSON private key or Firebase API key into Website connection password.'],
            'locations' => [],
            'links' => [['label' => 'Find Firebase project identifiers', 'url' => 'https://firebase.google.com/docs/projects/learn-more'], ['label' => 'Firebase Google login for web', 'url' => 'https://firebase.google.com/docs/auth/web/google-signin'], ['label' => 'Firebase Google login for Android', 'url' => 'https://firebase.google.com/docs/auth/android/google-signin'], ['label' => 'Firebase Google login for Apple apps', 'url' => 'https://firebase.google.com/docs/auth/ios/google-signin'], ['label' => 'Verify Firebase login tokens', 'url' => 'https://firebase.google.com/docs/auth/admin/verify-id-tokens'], ['label' => 'Firebase revocation and sessions', 'url' => 'https://firebase.google.com/docs/auth/admin/manage-sessions']],
        ];
        $catalog['google'] = [
            'name' => 'Google Sign-In — direct', 'can_enable' => false,
            'status' => 'Configure under Patient login → Social sign-in',
            'notice' => 'Direct Google ID-token exchange is now available in Patient login → Social sign-in, with a Patient app registration and explicit patient links. It does not use these OAuth introspection fields. Keep this external-service connection disabled for direct Google login.',
            'steps' => ['Register the actual app platforms in Google Cloud / Google Auth Platform, with consent, branding and permitted origins/redirects.', 'Create a Patient app · WordPress / social JWT under Applications. Enter the exact Google ID-token audience in its Google client ID field.', 'Open Patient login → Social sign-in and explicitly enable Google. Your developer implements /auth/challenge → nonce-capable Google sign-in → /auth/social.', 'Connect the verified Google subject ID to an existing active verified patient under Patient links. Do not match by email.', 'Keep this external OAuth connection off. Google tokeninfo is not an introspection endpoint. Never put a Google client secret in the app.', 'Alternatively, choose Google sign-in through Keycloak here for Keycloak-issued access tokens and its broker flow.'],
            'collect' => ['Google Auth Platform → Clients → the approved web, Android or Apple client IDs for your app.', 'Authorised origins/redirect addresses from the developer; keep test and production registrations deliberate.', 'The test account\'s stable Google subject identifier obtained after server verification, not an email-based guess.', 'The developer\'s plan for secure clinic API sessions and local permissions. There is no Google website-checking password to invent for the current introspection setup.'],
            'locations' => [],
            'links' => [['label' => 'Google client setup', 'url' => 'https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid'], ['label' => 'Verify Google login on the server', 'url' => 'https://developers.google.com/identity/gsi/web/guides/verify-google-id-token'], ['label' => 'Google identity protocol', 'url' => 'https://developers.google.com/identity/openid-connect/openid-connect']],
        ];
        $catalog['cognito'] = [
            'name' => 'Amazon Cognito', 'can_enable' => false,
            'status' => 'Guidance only — direct validation not implemented',
            'notice' => 'Cognito can manage app sign-in. Its user-pool tokens need a validation flow not implemented by this checker. An AWS region, user pool or app client ID entered here does not create that integration.',
            'steps' => ['In Amazon Cognito, create or select the user pool that manages your patient-app accounts. This is different from an identity pool used for AWS resource access.', 'Open the user pool\'s app-client settings. Configure the public phone/browser client without a shared secret, its exact callback addresses and the intended login flow.', 'Give the developer the user pool, region and app client details. Obtain the issuer and public-key information from the actual pool\'s discovery metadata rather than guessing an endpoint.', 'Before connecting directly, implement Cognito token verification or a separately implemented compatible adapter. Verify signature, expiry, issuer, token purpose and the correct client/API binding; preserve local app permissions and verified patient linking.', 'Do not use userInfo, GetUser or a token-issuing endpoint as the current login-checking address. Agree how login revocation will be enforced before rollout.', 'Keep sign-in off until the additional work is complete. A verified adapter uses the custom-service path and its own documented endpoint and website credentials.'],
            'collect' => ['The clinic\'s Cognito user pool ID and region, not an identity pool ID.', 'User pool → App clients → the app client ID and approved callback addresses.', 'Issuer and public-key metadata for that specific pool, supplied by the developer.', 'A test patient\'s verified Cognito subject ID and the plan for local patient linking. Do not paste AWS access keys into Website connection password.'],
            'locations' => [],
            'links' => [['label' => 'Cognito app-client settings', 'url' => 'https://docs.aws.amazon.com/cognito/latest/developerguide/user-pool-settings-client-apps.html'], ['label' => 'Cognito token verification', 'url' => 'https://docs.aws.amazon.com/cognito/latest/developerguide/amazon-cognito-user-pools-using-tokens-verifying-a-jwt.html'], ['label' => 'Pool endpoints and discovery', 'url' => 'https://docs.aws.amazon.com/cognito/latest/developerguide/federation-endpoints.html']],
        ];
        return $catalog;
    }

    public static function guide(string $id): array
    {
        $catalog = self::catalog();
        return $catalog[$id] ?? $catalog['custom'];
    }

}
