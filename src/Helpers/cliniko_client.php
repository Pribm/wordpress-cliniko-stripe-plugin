<?php

use App\Client\Cliniko\CachedClientDecorator;
use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;

function cliniko_client(bool $withCache = false, int $ttl = 300): ApiClientInterface {
    $client = Client::getInstance();
    return $withCache ? new CachedClientDecorator($client, $ttl) : $client;
}