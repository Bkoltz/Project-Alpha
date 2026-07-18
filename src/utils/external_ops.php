<?php

use App\Services\ExternalOpsConfigService;

/** @return array<string,mixed> */
function pa_external_ops_delivery_config(PDO $pdo): array
{
    return (new ExternalOpsConfigService())->load($pdo);
}

function pa_external_ops_enabled(PDO $pdo): bool
{
    return !empty(pa_external_ops_delivery_config($pdo)['enabled']);
}

function pa_external_ops_application_key(PDO $pdo): string
{
    return (string)pa_external_ops_delivery_config($pdo)['application_key'];
}
