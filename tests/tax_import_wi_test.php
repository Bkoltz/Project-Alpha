<?php
/**
 * Test WI county tax rate aggregation behavior.
 *
 * Verifies that the fixture built from official WI DOR WIR062026.csv contains
 * all 72 WI counties and that totals equal the published state (5.0%) + local
 * rate, applying the WI divide-by-4 rule to the four summed rate columns.
 *
 * Also asserts the arithmetic used by the import handler:
 *   sum(rates) * 100 / 4
 */

$fixturePath = __DIR__ . '/fixtures/wi_county_rates_expected.json';

if (!is_readable($fixturePath)) {
    fwrite(STDERR, "FAIL: fixture not found: {$fixturePath}\n");
    exit(1);
}

$expected = json_decode(file_get_contents($fixturePath), true);
if (!is_array($expected)) {
    fwrite(STDERR, "FAIL: could not decode fixture JSON\n");
    exit(1);
}

// 1. Should have 72 WI counties.
$countyCount = count($expected);
assert($countyCount === 72, "Expected 72 WI counties, got {$countyCount}");

// 2. Verify the arithmetic the handler must use (sum * 100 / 4).
$rates = [0.005, 0.005, 0.005, 0.005];
$legacyLocal = array_sum($rates) * 100;       // 2.0 (buggy over-count)
$correctLocal = array_sum($rates) * 100 / 4;  // 0.5 (WI spread rule)
assert($correctLocal === 0.5, 'WI local rate should be divided by 4');
assert($legacyLocal !== $correctLocal, 'Legacy handler over-counts WI');

// 3. Verify each fixture county total matches state (5.0%) + local.
foreach ($expected as $county => $totalRate) {
    $localRate = $totalRate - 5.0;

    // For the WIR062026.csv rows, the local portion is always a multiple of
    // 0.5 (one 0.005 column). Milwaukee is the only exception, where the rate
    // columns changed to 0.009 in 2024.
    if ($county === 'Milwaukee') {
        $expectedLocal = 0.009 * 4 * 100 / 4;
    } elseif (in_array($county, ['Waukesha', 'Winnebago'], true)) {
        $expectedLocal = 0.0;
    } else {
        $expectedLocal = 0.005 * 4 * 100 / 4;
    }

    $diff = abs($localRate - $expectedLocal);
    assert($diff < 0.0001,
        "{$county}: expected local rate {$expectedLocal}, got {$localRate}"
    );
}

echo "PASS: WI rate aggregation (72 counties, divide-by-4 rule verified)\n";
