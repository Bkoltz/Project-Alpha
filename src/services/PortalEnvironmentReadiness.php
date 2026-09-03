<?php

declare(strict_types=1);

namespace App\Services;

/** Local, environment-only diagnostics. Never bootstrap the app or query client data. */
final class PortalEnvironmentReadiness
{
    /** @param callable(string): (string|false) $read */
    public static function report(callable $read): array
    {
        $value = static fn(string $key): string => trim((string) $read($key));
        $receiver = $value('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL');
        $parts = parse_url($receiver);
        $validReceiver = $receiver !== '' && filter_var($receiver, FILTER_VALIDATE_URL) !== false
            && is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
        $key = $value('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID');
        $secret = $value('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET');
        $json = $value('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
        $jsonObject = false;
        if ($json !== '') {
            try {
                // Shape only: no application ID selection, credential extraction, or echoing.
                $jsonObject = json_decode($json, false, 32, JSON_THROW_ON_ERROR) instanceof \stdClass;
            } catch (\JsonException) {
                $jsonObject = false;
            }
        }
        return [
            'status' => 'environment_only',
            'receiver_override_present' => $receiver !== '',
            'receiver_override_https_valid' => (bool) $validReceiver,
            'direct_signing_key_present' => $key !== '',
            'direct_signing_key_shape_valid' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $key) === 1,
            'direct_signing_secret_present' => $secret !== '',
            'direct_signing_secret_length_valid' => strlen($secret) >= 32 && strlen($secret) <= 1000,
            'signing_map_present' => $json !== '',
            'signing_map_json_object_valid' => $jsonObject,
            'database_configuration_status' => 'unknown_not_checked',
            'producer_contract_status' => 'unknown_not_checked',
            'runtime_flags_status' => 'unknown_not_checked',
            'receiver_key_match_status' => 'unknown_not_checked',
            'portal_activation_status' => 'unknown_not_checked',
        ];
    }
}
