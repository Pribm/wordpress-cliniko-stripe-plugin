<?php

use App\Client\Cliniko\CachedClientDecorator;
use App\Client\Cliniko\Client;
use App\Client\Cliniko\EncryptedDashboardClient;
use App\Client\Cliniko\ObservedClientDecorator;
use App\Contracts\ApiClientInterface;
use App\Debug\Settings as DebugSettings;

function cliniko_client(bool $withCache = false, int $ttl = 300): ApiClientInterface {
    $client = Client::getInstance();
    $resolved = $withCache ? new CachedClientDecorator($client, $ttl) : $client;

    if (!DebugSettings::isEnabled()) {
        return $resolved;
    }

    return new ObservedClientDecorator($resolved, 'cliniko', $withCache);
}

/**
 * Return the encrypted, patient-scoped client used only by dashboard reads.
 * It deliberately has a separate cache namespace from public/reference data.
 */
function cliniko_dashboard_client(?ApiClientInterface $client = null, int $ttl = 7200): ApiClientInterface {
    $scopeId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    return new EncryptedDashboardClient(
        $client ?: Client::getInstance(),
        $scopeId,
        $ttl
    );
}

function cliniko_dashboard_cache_invalidate(): void {
    if (!function_exists('get_current_user_id') || !function_exists('cliniko_dashboard_client')) {
        return;
    }

    $client = cliniko_dashboard_client(null, 7200);
    if ($client instanceof EncryptedDashboardClient) {
        $client->invalidateAll();
    }
}
