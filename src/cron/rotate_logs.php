<?php

declare(strict_types=1);

use App\Logging\BoundedLogRotator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$roots = ['/var/www/config/logs/system', '/var/www/config/logs/cron'];
$localConfig = dirname(__DIR__, 2) . '/config/logs';
if (!is_dir('/var/www/config')) {
    $roots = [$localConfig . '/system', $localConfig . '/cron'];
}

$summary = (new BoundedLogRotator())->sweep($roots);
foreach ($summary['errors'] as $error) {
    @error_log('[rotate_logs] ' . $error);
}
exit($summary['errors'] === [] ? 0 : 1);
