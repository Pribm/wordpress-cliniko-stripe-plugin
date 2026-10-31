<?php

namespace App\Routes;

use App\Authorization\Api\AccessPolicy;
use App\Controller\Api\ManagedController;
use App\Service\Api\ApiAudit;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

final class ManagedApiRoutes
{
    private array $registered = [];
    public static array $collisions = [];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register'], 100);
        add_filter('rest_post_dispatch', [$this, 'response'], 20, 3);
        add_filter('rest_pre_serve_request', [$this, 'cors'], 20, 4);
    }

    public function register(): void
    {
        if (!ApiSettings::get()['enabled']) return;
        $policy = new AccessPolicy();
        $controller = new ManagedController();
        $occupied = rest_get_server()->get_namespaces();
        foreach (ApiSettings::namespaces() as $namespace) {
            if (in_array($namespace, $occupied, true) && !in_array($namespace, $this->registered, true)) {
                self::$collisions[] = $namespace;
                continue;
            }
            $this->registered[] = $namespace;
            foreach (EndpointCatalog::definitions() as $endpoint) {
                if (EndpointCatalog::state($endpoint) !== 'Active') continue;
                register_rest_route($namespace, EndpointCatalog::route($endpoint), [
                    'methods' => $endpoint['method'],
                    'callback' => static fn(WP_REST_Request $request) => $controller->dispatch($request, $endpoint),
                    'permission_callback' => static fn(WP_REST_Request $request) => $policy->authorize($request, $endpoint),
                    'args' => EndpointCatalog::args($endpoint),
                ]);
            }
        }
    }

    public function response($response, $server, WP_REST_Request $request)
    {
        if (!$this->handles($request->get_route()) || !$response instanceof \WP_REST_Response) return $response;
        $data = (array) $response->get_data();
        $requestId = bin2hex(random_bytes(16));
        if ($response->get_status() >= 400 && !isset($data['error'])) {
            $data = ['ok' => false, 'error' => ['code' => (string) ($data['code'] ?? 'request_failed'), 'message' => (string) ($data['message'] ?? 'Request failed.'), 'fields' => (object) ($data['data']['params'] ?? [])]];
        }
        $data['meta'] = ['request_id' => $requestId];
        $response->set_data($data);
        $response->header('X-Cliniko-API-Version', ApiSettings::VERSION);
        $response->header('X-Request-ID', $requestId);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        if ($response->get_status() === 429) $response->header('Retry-After', '600');
        $aliases = ApiSettings::get()['aliases'];
        foreach ($aliases as $slug => $expiry) {
            if (str_starts_with($request->get_route(), '/' . $slug . '/v1/')) {
                $response->header('Deprecation', '@' . (string) ($expiry - 30 * DAY_IN_SECONDS));
                $response->header('Sunset', gmdate('D, d M Y H:i:s', $expiry) . ' GMT');
            }
        }
        $operation = 'unknown';
        foreach (EndpointCatalog::definitions() as $endpoint) {
            foreach (ApiSettings::namespaces() as $namespace) {
                if ($request->get_method() === $endpoint['method'] && preg_match('#^/' . preg_quote($namespace, '#') . EndpointCatalog::route($endpoint) . '$#D', $request->get_route())) $operation = $endpoint['key'];
            }
        }
        ApiAudit::record('api_request', $operation, (string) $request->get_param('_cliniko_api_client'), (int) $request->get_param('_cliniko_api_user'), $response->get_status(), $requestId);
        return $response;
    }

    public function cors($served, $result, WP_REST_Request $request, $server)
    {
        if (!$this->handles($request->get_route())) return $served;
        $origin = (string) $request->get_header('origin');
        // Replace WordPress's permissive reflected CORS headers on this API only.
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        if ($origin !== '' && ApiSettings::browserOriginAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin', false);
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-ES-Attempt-Token, X-ES-Onboarding-Token, Idempotency-Key');
            header('Access-Control-Expose-Headers: X-Request-ID, X-Cliniko-API-Version, Retry-After');
        }
        return $served;
    }

    private function handles(string $route): bool
    {
        foreach ($this->registered as $namespace) if ($route === '/' . $namespace || str_starts_with($route, '/' . $namespace . '/')) return true;
        return false;
    }
}
