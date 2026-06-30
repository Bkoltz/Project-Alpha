<?php

function pa_link_provider_credentials_from_row(array $row): array
{
    $raw = (string)($row['credentials'] ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function pa_link_provider_row_score(array $row): int
{
    $credentials = pa_link_provider_credentials_from_row($row);
    $score = 0;
    if (!empty($credentials['refresh_token'])) {
        $score += 1000;
    }
    if (!empty($credentials['access_token'])) {
        $score += 500;
    }
    if (!empty($credentials['service_account']) || !empty($credentials['bucket'])) {
        $score += 300;
    }
    if (!empty($row['is_enabled'])) {
        $score += 100;
    }
    if ($credentials) {
        $score += 50;
    }
    $score += min(49, max(0, (int)($row['id'] ?? 0)));
    return $score;
}

function pa_link_provider_best_row(PDO $pdo, string $provider): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM link_resolver_config WHERE provider = ? ORDER BY id DESC');
    $stmt->execute([$provider]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return null;
    }

    usort($rows, static function (array $a, array $b): int {
        return pa_link_provider_row_score($b) <=> pa_link_provider_row_score($a);
    });

    return $rows[0];
}

function pa_link_provider_best_rows(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM link_resolver_config ORDER BY provider, id DESC');
    $best = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $provider = (string)($row['provider'] ?? '');
        if ($provider === '') {
            continue;
        }
        if (!isset($best[$provider]) || pa_link_provider_row_score($row) > pa_link_provider_row_score($best[$provider])) {
            $best[$provider] = $row;
        }
    }
    return $best;
}

function pa_link_provider_save(PDO $pdo, string $provider, int $isEnabled, array $credentials, int $expirationDays): void
{
    $credentialsJson = json_encode($credentials);
    if ($credentialsJson === false) {
        $credentialsJson = '{}';
    }

    $row = pa_link_provider_best_row($pdo, $provider);
    if ($row && !empty($row['id'])) {
        $stmt = $pdo->prepare('
            UPDATE link_resolver_config
            SET is_enabled = ?, credentials = ?, default_expiration_days = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$isEnabled, $credentialsJson, $expirationDays, (int)$row['id']]);
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO link_resolver_config (provider, is_enabled, credentials, default_expiration_days)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$provider, $isEnabled, $credentialsJson, $expirationDays]);
}
