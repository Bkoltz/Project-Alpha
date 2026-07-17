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

function pa_tax_state_fips_map(): array
{
    return [
        '01' => 'AL', '02' => 'AK', '04' => 'AZ', '05' => 'AR', '06' => 'CA',
        '08' => 'CO', '09' => 'CT', '10' => 'DE', '11' => 'DC', '12' => 'FL',
        '13' => 'GA', '15' => 'HI', '16' => 'ID', '17' => 'IL', '18' => 'IN',
        '19' => 'IA', '20' => 'KS', '21' => 'KY', '22' => 'LA', '23' => 'ME',
        '24' => 'MD', '25' => 'MA', '26' => 'MI', '27' => 'MN', '28' => 'MS',
        '29' => 'MO', '30' => 'MT', '31' => 'NE', '32' => 'NV', '33' => 'NH',
        '34' => 'NJ', '35' => 'NM', '36' => 'NY', '37' => 'NC', '38' => 'ND',
        '39' => 'OH', '40' => 'OK', '41' => 'OR', '42' => 'PA', '44' => 'RI',
        '45' => 'SC', '46' => 'SD', '47' => 'TN', '48' => 'TX', '49' => 'UT',
        '50' => 'VT', '51' => 'VA', '53' => 'WA', '54' => 'WV', '55' => 'WI',
        '56' => 'WY', '72' => 'PR', '78' => 'VI',
    ];
}

function pa_tax_state_name_map(): array
{
    return [
        'ALABAMA' => '01', 'ALASKA' => '02', 'ARIZONA' => '04', 'ARKANSAS' => '05',
        'CALIFORNIA' => '06', 'COLORADO' => '08', 'CONNECTICUT' => '09', 'DELAWARE' => '10',
        'DISTRICT OF COLUMBIA' => '11', 'WASHINGTON DC' => '11', 'FLORIDA' => '12',
        'GEORGIA' => '13', 'HAWAII' => '15', 'IDAHO' => '16', 'ILLINOIS' => '17',
        'INDIANA' => '18', 'IOWA' => '19', 'KANSAS' => '20', 'KENTUCKY' => '21',
        'LOUISIANA' => '22', 'MAINE' => '23', 'MARYLAND' => '24', 'MASSACHUSETTS' => '25',
        'MICHIGAN' => '26', 'MINNESOTA' => '27', 'MISSISSIPPI' => '28', 'MISSOURI' => '29',
        'MONTANA' => '30', 'NEBRASKA' => '31', 'NEVADA' => '32', 'NEW HAMPSHIRE' => '33',
        'NEW JERSEY' => '34', 'NEW MEXICO' => '35', 'NEW YORK' => '36',
        'NORTH CAROLINA' => '37', 'NORTH DAKOTA' => '38', 'OHIO' => '39',
        'OKLAHOMA' => '40', 'OREGON' => '41', 'PENNSYLVANIA' => '42',
        'RHODE ISLAND' => '44', 'SOUTH CAROLINA' => '45', 'SOUTH DAKOTA' => '46',
        'TENNESSEE' => '47', 'TEXAS' => '48', 'UTAH' => '49', 'VERMONT' => '50',
        'VIRGINIA' => '51', 'WASHINGTON' => '53', 'WEST VIRGINIA' => '54',
        'WISCONSIN' => '55', 'WYOMING' => '56', 'PUERTO RICO' => '72',
        'VIRGIN ISLANDS' => '78', 'U.S. VIRGIN ISLANDS' => '78',
    ];
}

function pa_tax_state_abbr_for_fips(string $stateFips): string
{
    $stateFips = str_pad(preg_replace('/\D+/', '', $stateFips), 2, '0', STR_PAD_LEFT);
    return pa_tax_state_fips_map()[$stateFips] ?? $stateFips;
}

function pa_tax_state_fips_for_hint(?string $state): ?string
{
    $state = strtoupper(trim((string)$state));
    if ($state === '') {
        return null;
    }
    if (preg_match('/^\d{1,2}$/', $state)) {
        $fips = str_pad($state, 2, '0', STR_PAD_LEFT);
        return isset(pa_tax_state_fips_map()[$fips]) ? $fips : null;
    }
    if (preg_match('/^[A-Z]{2}$/', $state)) {
        $fips = array_search($state, pa_tax_state_fips_map(), true);
        return $fips !== false ? str_pad((string)$fips, 2, '0', STR_PAD_LEFT) : null;
    }
    return pa_tax_state_name_map()[$state] ?? null;
}

function pa_tax_state_options(): array
{
    $namesByFips = array_flip(pa_tax_state_name_map());
    $options = [];
    foreach (pa_tax_state_fips_map() as $fips => $abbr) {
        $fips = str_pad((string)$fips, 2, '0', STR_PAD_LEFT);
        $name = $namesByFips[$fips] ?? $abbr;
        if ($name === 'WASHINGTON DC') {
            $name = 'DISTRICT OF COLUMBIA';
        }
        $options[] = [
            'fips' => $fips,
            'abbr' => $abbr,
            'name' => ucwords(strtolower(str_replace('U.S.', 'US', $name))),
        ];
    }
    return $options;
}

function pa_tax_active_date_clause(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "(($prefix" . "start_date IS NULL OR $prefix" . "start_date <= CURDATE()) AND ($prefix" . "end_date IS NULL OR $prefix" . "end_date >= CURDATE()))";
}

function pa_tax_lookup_by_county(PDO $pdo, string $query, int $limit = 12, ?string $stateHint = null): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $stateHintFips = pa_tax_state_fips_for_hint($stateHint);

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
         ORDER BY ' . ($stateHintFips !== null ? 'CASE WHEN j.state_fips = ? THEN 0 ELSE 1 END, ' : '') . 'f.state_abbr, f.county_name
         LIMIT ' . max(1, min(50, $limit))
    );
    $like = '%' . $query . '%';
    $params = [$like, $like];
    if ($stateHintFips !== null) {
        $params[] = $stateHintFips;
    }
    $stmt->execute($params);

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
    return pa_tax_dedupe_choices($choices);
}

function pa_tax_lookup_by_zip(PDO $pdo, string $zip5, ?string $zip4 = null, ?string $stateHint = null): array
{
    $zip5 = preg_replace('/\D+/', '', $zip5);
    $zip4 = $zip4 !== null ? preg_replace('/\D+/', '', $zip4) : null;
    $stateHintFips = pa_tax_state_fips_for_hint($stateHint);
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
        // Compare the full ZIP+4 point against both ends of the imported
        // range. This also handles a boundary whose start/end span ZIP5s.
        $zip4Clause = ' AND (zip5_start < ? OR (zip5_start = ? AND zip4_start <= ?))
                        AND (zip5_end > ? OR (zip5_end = ? AND zip4_end >= ?))';
        array_push($params, $zip5, $zip5, $zip4, $zip5, $zip5, $zip4);
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
    $rows = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row): bool => pa_tax_boundary_contains_zip($row, $zip5, $zip4)
    ));
    $rows = pa_tax_unique_boundaries($rows);
    if (!$rows) {
        return [
            'status' => 'not_found',
            'message' => 'No imported tax boundary matched that ZIP.',
            'choices' => [],
        ];
    }

    $choices = pa_tax_dedupe_choices(pa_tax_choices_for_boundaries($pdo, $rows));
    usort($choices, static function (array $a, array $b) use ($stateHintFips): int {
        if ($stateHintFips !== null) {
            $aRank = (($a['state_fips'] ?? '') === $stateHintFips) ? 0 : 1;
            $bRank = (($b['state_fips'] ?? '') === $stateHintFips) ? 0 : 1;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
        }
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
            : pa_tax_zip_multiple_message($choices, $stateHintFips),
        'choices' => $choices,
    ];
}

function pa_tax_zip_multiple_message(array $choices, ?string $stateHintFips = null): string
{
    $states = [];
    foreach ($choices as $choice) {
        $state = (string)($choice['state_abbr'] ?? '');
        if ($state !== '') {
            $states[$state] = true;
        }
    }
    if (count($states) > 1) {
        return 'This ZIP crosses imported state or jurisdiction boundaries. Choose the location that matches the sale address.';
    }
    if ($stateHintFips !== null) {
        return 'This ZIP can map to multiple tax jurisdictions. PA ranked the selected client state first.';
    }
    return 'This ZIP can map to multiple tax jurisdictions. Choose the one that matches the sale location.';
}

function pa_tax_unique_boundaries(array $rows): array
{
    $unique = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string)($row['state_fips'] ?? '') . '|'
            . (string)($row['county_fips'] ?? '') . '|'
            . trim((string)($row['jurisdiction_code'] ?? ''));
        if (!isset($unique[$key])) {
            $unique[$key] = $row;
        }
    }
    return array_values($unique);
}

function pa_tax_boundary_contains_zip(array $boundary, string $zip5, ?string $zip4 = null): bool
{
    $zip5 = preg_replace('/\D+/', '', $zip5) ?? '';
    $zip4 = $zip4 !== null ? (preg_replace('/\D+/', '', $zip4) ?? '') : '';
    $startZip5 = str_pad((string)($boundary['zip5_start'] ?? ''), 5, '0', STR_PAD_LEFT);
    $endZip5 = str_pad((string)($boundary['zip5_end'] ?? ''), 5, '0', STR_PAD_LEFT);
    if (strlen($zip5) !== 5 || $zip5 < $startZip5 || $zip5 > $endZip5) {
        return false;
    }
    if (strlen($zip4) !== 4) {
        return true;
    }

    $start = $startZip5 . str_pad((string)($boundary['zip4_start'] ?? '0000'), 4, '0', STR_PAD_LEFT);
    $end = $endZip5 . str_pad((string)($boundary['zip4_end'] ?? '9999'), 4, '0', STR_PAD_LEFT);
    $target = $zip5 . $zip4;
    return strcmp($target, $start) >= 0 && strcmp($target, $end) <= 0;
}

/**
 * Resolve every matched boundary in two bulk jurisdiction queries. A ZIP
 * lookup therefore performs a fixed three queries instead of two queries per
 * boundary row.
 */
function pa_tax_choices_for_boundaries(PDO $pdo, array $boundaries): array
{
    if (!$boundaries) {
        return [];
    }

    $countyPairs = [];
    $jurisdictionPairs = [];
    foreach ($boundaries as $boundary) {
        $stateFips = (string)($boundary['state_fips'] ?? '');
        $countyFips = (string)($boundary['county_fips'] ?? '');
        $jurisdictionCode = trim((string)($boundary['jurisdiction_code'] ?? ''));
        if ($stateFips === '' || $countyFips === '') {
            continue;
        }
        $countyPairs[$stateFips . '|' . $countyFips] = [$stateFips, $countyFips];
        if ($jurisdictionCode !== '') {
            $jurisdictionPairs[$stateFips . '|' . $jurisdictionCode] = [$stateFips, $jurisdictionCode];
        }
    }

    $counties = [];
    if ($countyPairs) {
        $clauses = [];
        $params = [];
        foreach ($countyPairs as [$stateFips, $countyFips]) {
            $clauses[] = '(j.state_fips = ? AND j.county_fips = ?)';
            array_push($params, $stateFips, $countyFips);
        }
        $countyStmt = $pdo->prepare(
            'SELECT j.id,j.state_fips,j.county_fips,f.state_abbr,f.county_name,j.state_rate,j.county_rate,j.total_rate
             FROM tax_jurisdictions j
             JOIN fips_counties f ON f.state_fips=j.state_fips AND f.county_fips=j.county_fips
             WHERE j.jurisdiction_type="county" AND j.is_active=1
               AND ' . pa_tax_active_date_clause('j') . '
               AND (' . implode(' OR ', $clauses) . ')
             ORDER BY j.state_fips,j.county_fips,j.id'
        );
        $countyStmt->execute($params);
        foreach ($countyStmt->fetchAll(PDO::FETCH_ASSOC) as $county) {
            $key = (string)$county['state_fips'] . '|' . (string)$county['county_fips'];
            if (!isset($counties[$key])) {
                $counties[$key] = $county;
            }
        }
    }

    $extras = [];
    if ($jurisdictionPairs) {
        $clauses = [];
        $params = [];
        foreach ($jurisdictionPairs as [$stateFips, $jurisdictionCode]) {
            $clauses[] = '(state_fips = ? AND jurisdiction_code = ?)';
            array_push($params, $stateFips, $jurisdictionCode);
        }
        $extraStmt = $pdo->prepare(
            'SELECT id,state_fips,jurisdiction_code,jurisdiction_type,city_rate,special_rate,county_rate,total_rate
             FROM tax_jurisdictions
             WHERE jurisdiction_type<>"county" AND is_active=1
               AND ' . pa_tax_active_date_clause() . '
               AND (' . implode(' OR ', $clauses) . ')
             ORDER BY state_fips,jurisdiction_code,jurisdiction_type,id'
        );
        $extraStmt->execute($params);
        foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $extra) {
            $key = (string)$extra['state_fips'] . '|' . (string)$extra['jurisdiction_code'];
            $extras[$key][] = $extra;
        }
    }

    $choices = [];
    foreach ($boundaries as $boundary) {
        $choice = pa_tax_choice_from_maps($boundary, $counties, $extras);
        if ($choice !== null) {
            $choices[] = $choice;
        }
    }
    return $choices;
}

function pa_tax_choice_from_maps(array $boundary, array $counties, array $extras): ?array
{
    $stateFips = (string)($boundary['state_fips'] ?? '');
    $countyFips = (string)($boundary['county_fips'] ?? '');
    $jurisdictionCode = trim((string)($boundary['jurisdiction_code'] ?? ''));
    $county = $counties[$stateFips . '|' . $countyFips] ?? null;
    if (!is_array($county)) {
        return null;
    }

    $extraRate = 0.0;
    $extraLabels = [];
    foreach ($extras[$stateFips . '|' . $jurisdictionCode] ?? [] as $extra) {
        $local = max((float)$extra['city_rate'], (float)$extra['special_rate'], (float)$extra['county_rate'], 0.0);
        if ($local <= 0.0) {
            continue;
        }
        $extraRate += $local;
        $extraLabels[] = pa_tax_jurisdiction_label($stateFips, (string)$extra['jurisdiction_code'], (string)$extra['jurisdiction_type']);
    }

    $rate = round((float)$county['state_rate'] + (float)$county['county_rate'] + $extraRate, 4);
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

function pa_tax_choice_for_boundary(PDO $pdo, array $boundary): ?array
{
    $choices = pa_tax_choices_for_boundaries($pdo, [$boundary]);
    return $choices[0] ?? null;
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

function pa_tax_dedupe_choices(array $choices): array
{
    $deduped = [];
    foreach ($choices as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $label = strtolower(trim((string)($choice['label'] ?? '')));
        $label = preg_replace('/\s+/', ' ', $label) ?: $label;
        $rate = number_format((float)($choice['rate'] ?? 0), 4, '.', '');
        $key = $rate . '|' . $label;
        if (!isset($deduped[$key])) {
            $deduped[$key] = $choice;
        }
    }

    return array_values($deduped);
}

function pa_tax_jurisdiction_label(string $stateFips, string $jurisdictionCode, string $type): string
{
    if ($stateFips === '55' && $jurisdictionCode === '53000') {
        return 'City of Milwaukee';
    }
    return ucfirst($type) . ' jurisdiction ' . $jurisdictionCode;
}
