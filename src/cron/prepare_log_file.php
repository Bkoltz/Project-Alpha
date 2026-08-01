<?php

declare(strict_types=1);

use App\Logging\LogFileInitializer;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = (string)($argv[1] ?? '');
if ($path === '') {
    fwrite(STDERR, "[prepare_log_file] A log path is required.\n");
    exit(2);
}

try {
    LogFileInitializer::ensureWritable($path);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[prepare_log_file] ' . $error->getMessage() . "\n");
    exit(1);
}
