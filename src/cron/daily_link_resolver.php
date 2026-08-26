<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/LinkResolverService.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/resolver_link_policy.php';

$jobName = 'daily_link_resolver';
$logPrefix = '[daily_link_resolver]';

if (empty($appConfig['cron_enabled'])) {
    @error_log($logPrefix . ' Cron is disabled.');
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

if (empty($appConfig['link_resolver_enabled'])) {
    // Also clean up installations that disabled the resolver before this policy existed.
    try {
        pa_remove_disabled_resolver_links($pdo);
    } catch (Throwable $e) {
        @error_log($logPrefix . ' Cleanup failed: ' . $e->getMessage());
        cron_state_mark_failure($pdo, $jobName, $e);
        exit(1);
    }
    @error_log($logPrefix . ' Link resolver is disabled.');
    cron_state_mark_success($pdo, $jobName, 'Link resolver disabled');
    exit(0);
}

if (empty($appConfig['link_resolver_daily_scan_enabled'])) {
    @error_log($logPrefix . ' Daily folder scan is disabled.');
    cron_state_mark_success($pdo, $jobName, 'Daily folder scan disabled');
    exit(0);
}

try {
    $enabledProviders = [];
    foreach (pa_link_provider_best_rows($pdo) as $provider => $row) {
        if (!empty($row['is_enabled']) && in_array($provider, ['dropbox', 'gdrive', 's3', 'r2'], true)) {
            $enabledProviders[] = $provider;
        }
    }

    if (!$enabledProviders) {
        cron_state_mark_success($pdo, $jobName, 'No enabled link providers; skipped');
        @error_log($logPrefix . ' No enabled link providers.');
        exit(0);
    }

    $service = new LinkResolverService($pdo);
    $summaries = [];
    $failures = [];

    foreach ($enabledProviders as $provider) {
        @error_log($logPrefix . ' Starting ' . $provider . ' folder scan.');
        $result = $service->runProviderScan($provider);
        $summaries[] = sprintf(
            '%s: scanned %d, generated %d, reused %d, review %d, no match %d, errors %d',
            $provider,
            (int)($result['scanned'] ?? 0),
            (int)($result['generated'] ?? 0),
            (int)($result['skipped'] ?? 0),
            (int)($result['review_required'] ?? 0),
            (int)($result['no_match'] ?? 0),
            (int)($result['errors'] ?? 0)
        );

        if (empty($result['success']) || (int)($result['errors'] ?? 0) > 0) {
            $failures[] = $provider . ': ' . (string)($result['message'] ?? 'scan failed');
        }
    }

    $summary = implode('; ', $summaries);
    if ($failures) {
        throw new RuntimeException(implode('; ', $failures) . '. ' . $summary);
    }

    cron_state_mark_success($pdo, $jobName, $summary);
    @error_log($logPrefix . ' Completed. ' . $summary);
} catch (Throwable $e) {
    @error_log($logPrefix . ' Failed: ' . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);
