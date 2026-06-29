<?php

declare(strict_types=1);

require_once __DIR__ . '/migration_lib.php';

function create_required_migration_backup(): string
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';
    $directory = getenv('MIGRATION_BACKUP_DIR') ?: '/var/www/backups/pre-migration';

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create migration backup directory '$directory'.");
    }
    if (!is_writable($directory)) {
        throw new RuntimeException("Migration backup directory '$directory' is not writable.");
    }

    $base = $directory . DIRECTORY_SEPARATOR . $db . '_' . gmdate('Y-m-d_H-i-s');
    $temporary = $base . '.sql.tmp';
    $archive = $base . '.sql.gz';
    $command = [
        'mysqldump', "--host=$host", "--port=$port", "--user=$user",
        '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
        "--result-file=$temporary", $db,
    ];
    $environment = array_merge($_ENV, ['MYSQL_PWD' => $pass]);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysqldump.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_file($temporary) || filesize($temporary) === 0) {
        @unlink($temporary);
        throw new RuntimeException('Pre-migration backup failed: ' . trim($stderr ?: $stdout));
    }

    $input = fopen($temporary, 'rb');
    $output = gzopen($archive, 'wb9');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        @unlink($temporary);
        @unlink($archive);
        throw new RuntimeException('Could not compress the pre-migration backup.');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false || gzwrite($output, $chunk) === false) {
            fclose($input);
            gzclose($output);
            @unlink($temporary);
            @unlink($archive);
            throw new RuntimeException('Could not write the compressed pre-migration backup.');
        }
    }
    fclose($input);
    gzclose($output);
    @unlink($temporary);

    if (!is_file($archive) || filesize($archive) === 0) {
        throw new RuntimeException('Compressed pre-migration backup is empty.');
    }

    return $archive;
}

try {
    $arguments = $argv ?? [];
    $dryRun = in_array('--dry-run', $arguments, true);
    $verbose = in_array('--verbose', $arguments, true) || in_array('-v', $arguments, true);
    $validateFilesOnly = in_array('--validate-files', $arguments, true);
    $directory = dirname(__DIR__, 2) . '/database/migrations';
    $files = migration_files($directory);

    if ($validateFilesOnly) {
        fwrite(STDOUT, 'Migration files valid: ' . count($files) . PHP_EOL);
        exit(0);
    }

    $pdo = migration_connection();
    $ledger = migration_ledger($pdo);
    migration_validate_history($files, $ledger);
    $pending = array_diff_key($files, $ledger);

    if ($dryRun) {
        foreach ($pending as $file) {
            $sql = file_get_contents($file['path']);
            migration_statements((string) $sql);
            fwrite(STDOUT, sprintf("Would apply %04d %s (%s)\n", $file['version'], $file['filename'], substr($file['checksum'], 0, 12)));
        }
        migration_schema_health($pdo);
        fwrite(STDOUT, 'Dry run passed; pending migrations: ' . count($pending) . PHP_EOL);
        exit(0);
    }

    if ($pending !== []) {
        $backup = create_required_migration_backup();
        fwrite(STDOUT, "Pre-migration backup created: $backup\n");
    }

    foreach ($pending as $file) {
        $sql = file_get_contents($file['path']);
        foreach (migration_statements((string) $sql) as $statement) {
            $result = $pdo->query($statement);
            if ($result !== false) {
                $result->closeCursor();
            }
        }
        $record = $pdo->prepare(
            'INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)'
        );
        $record->execute([$file['version'], $file['filename'], $file['checksum']]);
        if ($verbose) {
            fwrite(STDOUT, "Applied {$file['filename']}.\n");
        }
    }

    migration_schema_health($pdo);
    fwrite(STDOUT, 'Migration and schema validation passed; applied: ' . count($pending) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
