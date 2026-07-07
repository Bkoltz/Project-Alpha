<?php
/**
 * Sync Merchant Rate Cron Job
 *
 * Fetches actual Stripe balance_transaction fees from the last 30 days of
 * successful payments and computes the effective merchant rate.
 *
 * Stores three non-user-editable app_config keys:
 *   - stripe_effective_rate_pct     (blended percent = total_fees / total_amount * 100)
 *   - stripe_effective_fixed        (0.00 for blended model)
 *   - stripe_effective_rate_synced_at (ISO 8601 timestamp)
 *
 * The surcharge calculator reads these to cap the client's portion at the
 * real fee Stripe charged.
 *
 * Run via cron: php /var/www/src/cron/sync_merchant_rate.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/../utils/cron_state.php';

$logPrefix = '[sync_merchant_rate]';
$jobName = 'sync_merchant_rate';

if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

// Initialize Stripe service
$stripe = StripeService::fromAppConfig($appConfig);
if (!$stripe) {
    @error_log("$logPrefix Stripe not configured. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Stripe not configured; skipped');
    exit(0);
}

@error_log("$logPrefix Starting merchant rate sync at " . date('c'));

try {
    $since = time() - 86400 * 30; // last 30 days
    $intents = $stripe->listPaymentIntents($since);

    $totalAmountCents = 0;
    $totalFeeCents = 0;
    $count = 0;
    $skipped = 0;

    foreach ($intents as $pi) {
        if (($pi['status'] ?? '') !== 'succeeded') {
            $skipped++;
            continue;
        }

        // Pull the first charge's balance_transaction from expanded charges
        $charge = $pi['charges']['data'][0] ?? null;
        if (!$charge || empty($charge['balance_transaction'])) {
            @error_log("$logPrefix No charge/balance_transaction for PI {$pi['id']}; skipping");
            $skipped++;
            continue;
        }

        $btId = $charge['balance_transaction'];

        try {
            $bt = $stripe->getBalanceTransaction($btId);
        } catch (Throwable $e) {
            @error_log("$logPrefix Failed to fetch balance_transaction $btId for PI {$pi['id']}: " . $e->getMessage());
            $skipped++;
            continue;
        }

        $amount = (int)($bt['amount'] ?? 0);
        $fee = (int)($bt['fee'] ?? 0);

        if ($amount <= 0) {
            $skipped++;
            continue;
        }

        $totalAmountCents += $amount;
        $totalFeeCents += $fee;
        $count++;
    }

    @error_log("$logPrefix Analyzed $count succeeded payment(s), skipped $skipped");

    // Not enough data to compute a reliable blended rate
    if ($count < 10) {
        @error_log("$logPrefix Insufficient payment data ($count < 10). Keeping existing rate.");
        cron_state_mark_success($pdo, $jobName, "count=$count; kept existing rate");
        exit(0);
    }

    $totalAmountDollars = $totalAmountCents / 100;
    $totalFeeDollars = $totalFeeCents / 100;
    $effectivePct = ($totalAmountCents > 0)
        ? round(($totalFeeCents / $totalAmountCents) * 100, 2)
        : 0.0;
    $effectiveFixed = 0.0;
    $syncedAt = date('c');

    // Upsert config values
    $configKeys = [
        'stripe_effective_rate_pct' => (string)$effectivePct,
        'stripe_effective_fixed' => number_format($effectiveFixed, 2),
        'stripe_effective_rate_synced_at' => $syncedAt,
    ];

    foreach ($configKeys as $key => $value) {
        $stmt = $pdo->prepare('
            INSERT INTO app_config (organization_id, config_key, config_value)
            VALUES (0, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ');
        $stmt->execute([$key, $value]);
    }

    $result = sprintf(
        'rate=%.2f%%, fixed=%.2f, amount=$%.2f, fee=$%.2f, count=%d',
        $effectivePct,
        $effectiveFixed,
        $totalAmountDollars,
        $totalFeeDollars,
        $count
    );

    cron_state_mark_success($pdo, $jobName, $result);
    @error_log("$logPrefix Merchant rate synced: $result");

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);
