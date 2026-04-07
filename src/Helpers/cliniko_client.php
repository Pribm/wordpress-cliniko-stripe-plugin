<?php

use App\Client\Cliniko\CachedClientDecorator;
use App\Client\Cliniko\Client;
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
