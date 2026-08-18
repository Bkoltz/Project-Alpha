<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class ManagedDeliveryIntentSigner
{
    /** @param array{applicationKey:string,keyId:string,secret:string,authHeaders:array<string,string>} $contract @return list<string> */
    public static function headers(array $contract, string $deliveryId, string $url, string $body, ?string $timestamp = null): array
    {
        $parts = PortalProjectionDeliveryConfigService::validateDestination($url);
        $timestamp ??= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $path = (string)($parts['path'] ?? '/');
        $digest = hash('sha256', $body);
        $canonical = $timestamp . "\nPOST\n" . $path . "\n" . $contract['keyId'] . "\n" . $deliveryId . "\n" . $body;
        $headers = [
            'Content-Type: application/json',
            'X-Portal-Integration-Application-Key: ' . $contract['applicationKey'],
            'X-Portal-Integration-Timestamp: ' . $timestamp,
            'X-Portal-Integration-Body-SHA256: ' . $digest,
            'X-Portal-Integration-Key-Id: ' . $contract['keyId'],
            'X-Portal-Integration-Delivery-Id: ' . $deliveryId,
            'X-Portal-Integration-Signature: sha256=' . hash_hmac('sha256', $canonical, $contract['secret']),
        ];
        foreach ($contract['authHeaders'] as $name => $value) $headers[] = $name . ': ' . $value;
        return $headers;
    }
}
