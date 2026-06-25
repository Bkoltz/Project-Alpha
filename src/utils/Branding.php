<?php
// Resolves per-organization branding with global fallback.
// Set at org level via Settings (future UI); falls back to app_config global values.

class Branding {
    // Returns the branding array for an organization, merging org overrides over global config.
    // $appConfig: the global config array (from src/config/app.php)
    // $orgId: optional organization ID. If null or 0, returns global only.
    public static function resolve(array $appConfig, ?int $orgId = null): array {
        $global = [
            'brand_name' => $appConfig['brand_name'] ?? 'Project Alpha',
            'logo_path' => $appConfig['logo_path'] ?? null,
            'from_name' => $appConfig['from_name'] ?? null,
            'from_email' => $appConfig['from_email'] ?? null,
            'from_phone' => $appConfig['from_phone'] ?? null,
            'from_address_line1' => $appConfig['from_address_line1'] ?? null,
            'from_address_line2' => $appConfig['from_address_line2'] ?? null,
            'from_city' => $appConfig['from_city'] ?? null,
            'from_state' => $appConfig['from_state'] ?? null,
            'from_postal' => $appConfig['from_postal'] ?? null,
        ];
        if ($orgId === null || $orgId <= 0) { return $global; }
        // Load org overrides from DB
        require_once __DIR__ . '/../config/db.php';
        global $pdo;
        if (!isset($pdo)) { return $global; }
        $stmt = $pdo->prepare(
            'SELECT brand_name, brand_logo_path, brand_from_name, brand_from_email,
                    brand_from_phone, brand_address_line1, brand_address_line2,
                    brand_city, brand_state, brand_postal
             FROM organizations WHERE id = ?'
        );
        $stmt->execute([$orgId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$org) { return $global; }
        // Merge: org overrides take precedence when non-null, else global
        $merge = function(string $globalKey, ?string $orgVal) use ($global) {
            return ($orgVal !== null && $orgVal !== '') ? $orgVal : $global[$globalKey];
        };
        return [
            'brand_name' => $merge('brand_name', $org['brand_name'] ?? null),
            'logo_path' => $merge('logo_path', $org['brand_logo_path'] ?? null),
            'from_name' => $merge('from_name', $org['brand_from_name'] ?? null),
            'from_email' => $merge('from_email', $org['brand_from_email'] ?? null),
            'from_phone' => $merge('from_phone', $org['brand_from_phone'] ?? null),
            'from_address_line1' => $merge('from_address_line1', $org['brand_address_line1'] ?? null),
            'from_address_line2' => $merge('from_address_line2', $org['brand_address_line2'] ?? null),
            'from_city' => $merge('from_city', $org['brand_city'] ?? null),
            'from_state' => $merge('from_state', $org['brand_state'] ?? null),
            'from_postal' => $merge('from_postal', $org['brand_postal'] ?? null),
        ];
    }

    // Convenience: just the brand name for an org
    public static function brandName(array $appConfig, ?int $orgId = null): string {
        return self::resolve($appConfig, $orgId)['brand_name'];
    }
}
