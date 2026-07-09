<?php

declare(strict_types=1);

function pa_tax_rate_from_import_columns(array $parts): float
{
    $values = [];
    for ($idx = 3; $idx <= 6; $idx++) {
        $values[] = max(0.0, (float)($parts[$idx] ?? 0));
    }
    return round(max($values) * 100, 4);
}

function pa_tax_active_date_clause(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "(($prefix" . "start_date IS NULL OR $prefix" . "start_date <= CURDATE()) AND ($prefix" . "end_date IS NULL OR $prefix" . "end_date >= CURDATE()))";
}

function pa_tax_lookup_by_county(PDO $pdo, string $query, int $limit = 12): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT f.state_abbr, f.county_name, j.state_fips, j.county_fips, j.state_rate, j.county_rate, j.total_rate
         FROM tax_jurisdictions j
         JOIN fips_counties f
           ON f.state_fips = j.state_fips
          AND f.county_fips = j.county_fips
         WHERE j.jurisdiction_type = "county"
           AND j.is_active = 1
           AND ' . pa_tax_active_date_clause('j') . '
           AND (f.county_name LIKE ? OR CONCAT(f.county_name, ", ", f.state_abbr) LIKE ?)
         ORDER BY f.state_abbr, f.county_name
         LIMIT ' . max(1, min(50, $limit))
    );
    $like = '%' . $query . '%';
    $stmt->execute([$like, $like]);

    $choices = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $choices[] = pa_tax_choice(
            (float)$row['total_rate'],
            (string)$row['county_name'] . ' County, ' . (string)$row['state_abbr'],
            'county',
            [
                'state_fips' => (string)$row['state_fips'],
                'county_fips' => (string)$row['county_fips'],
                'state_abbr' => (string)$row['state_abbr'],
                'county' => (string)$row['county_name'],
            ]
        );
    }
    return $choices;
}

function pa_tax_lookup_by_zip(PDO $pdo, string $zip5, ?string $zip4 = null): array
{
    $zip5 = preg_replace('/\D+/', '', $zip5);
    $zip4 = $zip4 !== null ? preg_replace('/\D+/', '', $zip4) : null;
    if (!is_string($zip5) || strlen($zip5) !== 5) {
        return [
            'status' => 'error',
            'message' => 'Enter a 5 digit ZIP code.',
            'choices' => [],
        ];
    }

    $params = [$zip5, $zip5];
    $zip4Clause = '';
    if (is_string($zip4) && strlen($zip4) === 4) {
        $zip4Clause = ' AND (
            zip5_start <> zip5_end
            OR (zip4_start <= ? AND zip4_end >= ?)
        )';
        $params[] = $zip4;
        $params[] = $zip4;
    }

    $stmt = $pdo->prepare(
        'SELECT zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code
         FROM tax_boundaries
         WHERE zip5_start <= ?
           AND zip5_end >= ?
           AND ' . pa_tax_active_date_clause() . $zip4Clause . '
         ORDER BY state_fips, county_fips, jurisdiction_code, zip4_start
         LIMIT 250'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [
            'status' => 'not_found',
            'message' => 'No imported tax boundary matched that ZIP.',
            'choices' => [],
        ];
    }

    $choices = [];
    foreach ($rows as $row) {
        $choice = pa_tax_choice_for_boundary($pdo, $row);
        if ($choice === null) {
            continue;
        }
        $key = implode('|', [
            number_format((float)$choice['rate'], 4, '.', ''),
            $choice['state_fips'] ?? '',
            $choice['county_fips'] ?? '',
            $choice['jurisdiction_code'] ?? '',
        ]);
        $choices[$key] = $choice;
    }

    $choices = array_values($choices);
    usort($choices, static function (array $a, array $b): int {
        return [$a['state_abbr'] ?? '', $a['county'] ?? '', $a['label'] ?? ''] <=> [$b['state_abbr'] ?? '', $b['county'] ?? '', $b['label'] ?? ''];
    });

    if (!$choices) {
        return [
            'status' => 'not_found',
            'message' => 'Boundary rows matched, but no active imported rates were found.',
            'choices' => [],
        ];
    }

    return [
        'status' => count($choices) === 1 ? 'single' : 'multiple',
        'message' => count($choices) === 1
            ? 'Rate matched from imported boundary data.'
            : 'This ZIP can map to multiple tax jurisdictions. Choose the one that matches the sale location.',
        'choices' => $choices,
    ];
}

function pa_tax_choice_for_boundary(PDO $pdo, array $boundary): ?array
{
    $stateFips = (string)$boundary['state_fips'];
    $countyFips = (string)$boundary['county_fips'];
    $jurisdictionCode = isset($boundary['jurisdiction_code']) ? trim((string)$boundary['jurisdiction_code']) : '';

    $countyStmt = $pdo->prepare(
        'SELECT f.state_abbr, f.county_name, j.state_rate, j.county_rate, j.total_rate
         FROM tax_jurisdictions j
         JOIN fips_counties f
           ON f.state_fips = j.state_fips
          AND f.county_fips = j.county_fips
         WHERE j.state_fips = ?
           AND j.county_fips = ?
           AND j.jurisdiction_type = "county"
           AND j.is_active = 1
           AND ' . pa_tax_active_date_clause('j') . '
         LIMIT 1'
    );
    $countyStmt->execute([$stateFips, $countyFips]);
    $county = $countyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$county) {
        return null;
    }

    $stateRate = (float)$county['state_rate'];
    $countyRate = (float)$county['county_rate'];
    $extraRate = 0.0;
    $extraLabels = [];

    if ($jurisdictionCode !== '') {
        $extraStmt = $pdo->prepare(
            'SELECT jurisdiction_code, jurisdiction_type, city_rate, special_rate, county_rate, total_rate
             FROM tax_jurisdictions
             WHERE state_fips = ?
               AND jurisdiction_code = ?
               AND jurisdiction_type <> "county"
               AND is_active = 1
               AND ' . pa_tax_active_date_clause() . '
             ORDER BY jurisdiction_type, id'
        );
        $extraStmt->execute([$stateFips, $jurisdictionCode]);
        foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $extra) {
            $local = max((float)$extra['city_rate'], (float)$extra['special_rate'], (float)$extra['county_rate'], 0.0);
            if ($local <= 0.0) {
                continue;
            }
            $extraRate += $local;
            $extraLabels[] = pa_tax_jurisdiction_label($stateFips, (string)$extra['jurisdiction_code'], (string)$extra['jurisdiction_type']);
        }
    }

    $rate = round($stateRate + $countyRate + $extraRate, 4);
    $label = (string)$county['county_name'] . ' County, ' . (string)$county['state_abbr'];
    if ($extraLabels) {
        $label .= ' - ' . implode(', ', array_unique($extraLabels));
    }

    return pa_tax_choice($rate, $label, $extraLabels ? 'zip_complex' : 'zip', [
        'state_fips' => $stateFips,
        'county_fips' => $countyFips,
        'state_abbr' => (string)$county['state_abbr'],
        'county' => (string)$county['county_name'],
        'jurisdiction_code' => $jurisdictionCode,
    ]);
}

function pa_tax_choice(float $rate, string $label, string $source, array $extra = []): array
{
    return array_merge([
        'rate' => round($rate, 4),
        'rate_display' => number_format($rate, 2) . '%',
        'label' => $label,
        'source' => $source,
    ], $extra);
}

function pa_tax_jurisdiction_label(string $stateFips, string $jurisdictionCode, string $type): string
{
    if ($stateFips === '55' && $jurisdictionCode === '53000') {
        return 'City of Milwaukee';
    }
    return ucfirst($type) . ' jurisdiction ' . $jurisdictionCode;
}
