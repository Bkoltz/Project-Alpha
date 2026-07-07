<?php

function api_scope_catalog(): array
{
    return [
        'dashboard.read' => [
            'label' => 'Dashboard summary',
            'description' => 'Read high-level dashboard counts and status summaries.',
            'endpoints' => ['api-dashboard-summary'],
        ],
        'financial.read' => [
            'label' => 'Financial summary',
            'description' => 'Read revenue, expense, and profit summaries.',
            'endpoints' => ['api-financial-summary'],
        ],
        'clients.read' => [
            'label' => 'Clients',
            'description' => 'Read client lists and client search results.',
            'endpoints' => ['api-clients', 'api-clients-search'],
        ],
        'projects.read' => [
            'label' => 'Projects',
            'description' => 'Read project lists.',
            'endpoints' => ['api-projects'],
        ],
        'quotes.read' => [
            'label' => 'Quotes',
            'description' => 'Read quote lists.',
            'endpoints' => ['api-quotes'],
        ],
        'invoices.read' => [
            'label' => 'Invoices',
            'description' => 'Read invoice lists.',
            'endpoints' => ['api-invoices'],
        ],
    ];
}

function api_scope_endpoint_map(): array
{
    $map = [];
    foreach (api_scope_catalog() as $scope => $definition) {
        foreach ((array)($definition['endpoints'] ?? []) as $endpoint) {
            $map[$endpoint] = $scope;
        }
    }
    return $map;
}

function api_scope_options_for_form(): array
{
    return ['full' => [
        'label' => 'Full API access',
        'description' => 'Allow every current and future API endpoint.',
        'endpoints' => ['*'],
    ]] + api_scope_catalog();
}

function api_normalize_scopes($value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
    }

    $allowed = ['full' => true] + array_fill_keys(array_keys(api_scope_catalog()), true);
    $scopes = [];
    foreach ($items as $item) {
        $scope = strtolower(trim((string)$item));
        if ($scope === '') {
            continue;
        }
        if (in_array($scope, ['*', 'all', 'admin', 'full_access', 'full-access'], true)) {
            $scope = 'full';
        }
        if ($scope === 'dashboard') {
            $scope = 'dashboard.read';
        }
        if (in_array($scope, ['read', 'write', 'read.write', 'read_write'], true)) {
            $scope = 'full';
        }
        if (isset($allowed[$scope])) {
            $scopes[$scope] = true;
        }
    }

    if (isset($scopes['full'])) {
        return ['full'];
    }
    return array_keys($scopes);
}

function api_scopes_to_storage(array $scopes): string
{
    $normalized = api_normalize_scopes($scopes);
    return $normalized ? implode(',', $normalized) : '';
}

function api_key_has_scope($storedScopes, string $requiredScope): bool
{
    $scopes = api_normalize_scopes($storedScopes);
    if (in_array('full', $scopes, true)) {
        return true;
    }
    return in_array(strtolower($requiredScope), $scopes, true);
}
