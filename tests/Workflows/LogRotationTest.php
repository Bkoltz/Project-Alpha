<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Logging\BoundedLogRotator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Logging/BoundedLogRotator.php';

final class LogRotationTest extends TestCase
{
    private string $root;
    private string $system;
    private string $cron;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa-log-rotation-' . bin2hex(random_bytes(6));
        $this->system = $this->root . DIRECTORY_SEPARATOR . 'system';
        $this->cron = $this->root . DIRECTORY_SEPARATOR . 'cron';
        mkdir($this->system, 0700, true);
        mkdir($this->cron, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testExactThresholdRotatesTxtAndCronLogWithoutLosingEitherSide(): void
    {
        $error = $this->system . DIRECTORY_SEPARATOR . 'error_log.txt';
        $cron = $this->cron . DIRECTORY_SEPARATOR . 'cron.log';
        file_put_contents($error, str_repeat('E', 1024));
        file_put_contents($cron, str_repeat('C', 1024));
        chmod($error, 0640);

        $result = (new BoundedLogRotator(1024, 3, 3, 3600))
            ->sweep([$this->system, $this->cron], 1_800_000_000);

        self::assertSame(2, $result['rotated']);
        self::assertSame([], $result['errors']);
        self::assertSame('', file_get_contents($error));
        self::assertSame('', file_get_contents($cron));
        file_put_contents($error, "new error\n", FILE_APPEND);
        file_put_contents($cron, "new cron\n", FILE_APPEND);
        self::assertSame("new error\n", file_get_contents($error));
        self::assertSame("new cron\n", file_get_contents($cron));

        $errorArchives = glob($error . '.*') ?: [];
        $cronArchives = glob($cron . '.*') ?: [];
        self::assertCount(1, $errorArchives);
        self::assertCount(1, $cronArchives);
        self::assertSame(str_repeat('E', 1024), file_get_contents($errorArchives[0]));
        self::assertSame(str_repeat('C', 1024), file_get_contents($cronArchives[0]));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0640, fileperms($error) & 0777);
        }
    }

    public function testLinuxWriterCanFinishOnRenamedInodeBeforeDeferredCompression(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Open-inode rename semantics require the Linux deployment runtime.');
        }
        $active = $this->cron . DIRECTORY_SEPARATOR . 'cron.log';
        file_put_contents($active, str_repeat('A', 1024));
        $writer = fopen($active, 'ab');
        self::assertIsResource($writer);

        $rotator = new BoundedLogRotator(1024, 3, 3, 60);
        $first = $rotator->sweep([$this->cron], time());
        self::assertSame(1, $first['rotated']);
        fwrite($writer, "late writer output\n");
        fflush($writer);

        $archives = glob($active . '.*') ?: [];
        self::assertCount(1, $archives);
        self::assertSame(
            str_repeat('A', 1024) . "late writer output\n",
            file_get_contents($archives[0])
        );
        self::assertSame('', file_get_contents($active));

        touch($archives[0], time() - 120);
        $whileOpen = $rotator->sweep([$this->cron], time());
        self::assertSame(0, $whileOpen['compressed']);
        self::assertFileExists($archives[0]);
        fclose($writer);

        $afterClose = $rotator->sweep([$this->cron], time());
        self::assertSame(1, $afterClose['compressed']);
        self::assertSame(
            str_repeat('A', 1024) . "late writer output\n",
            gzdecode((string)file_get_contents($archives[0] . '.gz'))
        );
    }

    public function testSmallFilesAndSymlinksAreNotRotated(): void
    {
        $small = $this->system . DIRECTORY_SEPARATOR . 'small.log';
        file_put_contents($small, 'small');
        chmod($small, 0644);
        $linked = $this->system . DIRECTORY_SEPARATOR . 'linked.log';

        $symlinkCreated = function_exists('symlink') && @symlink($small, $linked);

        $result = (new BoundedLogRotator(1024, 3, 3, 0))
            ->sweep([$this->system], time());

        self::assertSame(0, $result['rotated']);
        self::assertSame('small', file_get_contents($small));
        if ($symlinkCreated) {
            self::assertTrue(is_link($linked));
        }
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0644, fileperms($small) & 0777);
        }
    }

    public function testQuiescentArchivesAreCompressedByteForByteAndRetentionIsBounded(): void
    {
        $active = $this->cron . DIRECTORY_SEPARATOR . 'cron.log';
        file_put_contents($active, 'active');
        $now = time();
        for ($index = 1; $index <= 5; $index++) {
            $archive = sprintf(
                '%s.2026010%dT010101Z-%08x',
                $active,
                $index,
                $index
            );
            file_put_contents($archive, "archive-$index\n");
            touch($archive, $now - 7200 - $index);
        }

        $result = (new BoundedLogRotator(1024, 3, 3, 3600))
            ->sweep([$this->cron], $now);

        self::assertSame(5, $result['compressed']);
        self::assertSame(2, $result['deleted']);
        self::assertSame([], $result['errors']);
        $archives = glob($active . '.*.gz') ?: [];
        self::assertCount(3, $archives);
        foreach ($archives as $archive) {
            $decoded = gzdecode((string)file_get_contents($archive));
            self::assertIsString($decoded);
            self::assertMatchesRegularExpression('/^archive-\d\n$/', $decoded);
        }
        self::assertSame('active', file_get_contents($active));
    }

    public function testOrphanedSizeArchiveIsStillCompressedAndRetained(): void
    {
        $now = time();
        $archive = $this->system . DIRECTORY_SEPARATOR
            . 'error_log.txt.20260730T010101Z-00000001';
        file_put_contents($archive, "orphaned archive\n");
        touch($archive, $now - 7200);

        $result = (new BoundedLogRotator(1024, 3, 3, 3600))
            ->sweep([$this->system], $now);

        self::assertSame(1, $result['compressed']);
        self::assertSame([], $result['errors']);
        self::assertFileDoesNotExist($archive);
        self::assertFileExists($archive . '.gz');
        self::assertSame(
            "orphaned archive\n",
            gzdecode((string)file_get_contents($archive . '.gz'))
        );
    }

    public function testCompletedDailyLogsAreCompressedAndRetainedByPrefix(): void
    {
        $now = strtotime('2026-07-30 12:00:00 UTC');
        foreach (['2026-07-24', '2026-07-25', '2026-07-26', '2026-07-27'] as $date) {
            $path = $this->system . DIRECTORY_SEPARATOR . $date . '.log';
            file_put_contents($path, "daily-$date\n");
            touch($path, $now - 172800);
        }
        $today = $this->system . DIRECTORY_SEPARATOR . '2026-07-30.log';
        file_put_contents($today, 'today');

        $result = (new BoundedLogRotator(1024, 3, 3, 3600))
            ->sweep([$this->system], $now);

        self::assertSame(4, $result['compressed']);
        self::assertSame(1, $result['deleted']);
        self::assertCount(3, glob($this->system . DIRECTORY_SEPARATOR . '*.log.gz') ?: []);
        self::assertSame('today', file_get_contents($today));
    }

    public function testDeploymentWiresPersistentPathsMinuteSweepAndPhpDeduplication(): void
    {
        self::assertSame(10 * 1024 * 1024, BoundedLogRotator::DEFAULT_MAX_BYTES);
        $root = dirname(__DIR__, 2);
        $phpIni = (string)file_get_contents($root . '/php.ini');
        $cron = (string)file_get_contents($root . '/cron/crontab');
        $script = (string)file_get_contents($root . '/src/cron/rotate_logs.php');
        $compose = (string)file_get_contents($root . '/docker-compose.yml');
        $webEntrypoint = (string)file_get_contents($root . '/docker/start.sh');
        $cronEntrypoint = (string)file_get_contents($root . '/cron/entrypoint.sh');
        $dockerfile = (string)file_get_contents($root . '/Dockerfile');
        $initializer = (string)file_get_contents($root . '/src/Logging/LogFileInitializer.php');

        self::assertStringContainsString(
            'error_log = /var/www/config/logs/system/error_log.txt',
            $phpIni
        );
        self::assertStringContainsString('ignore_repeated_errors = On', $phpIni);
        self::assertStringContainsString('ignore_repeated_source = Off', $phpIni);
        self::assertStringContainsString(
            '* * * * * www-data . /etc/environment && php /var/www/src/cron/rotate_logs.php',
            $cron
        );
        self::assertStringContainsString(BoundedLogRotator::class, $script);
        self::assertGreaterThanOrEqual(3, substr_count($compose, 'pa_config:/var/www/config'));
        self::assertStringNotContainsString('rotate_logs.php', $webEntrypoint);
        self::assertStringNotContainsString('rotate_logs.php', $cronEntrypoint);
        self::assertStringContainsString(
            'mkdir -p /var/www/config/logs/system',
            $dockerfile
        );
        self::assertStringNotContainsString(
            '$this->applyMetadata($path',
            (string)file_get_contents($root . '/src/Logging/BoundedLogRotator.php')
        );
        self::assertStringContainsString('[ -L "$ERROR_LOG" ]', $webEntrypoint);
        self::assertStringContainsString('[ -L "$CRON_LOG" ]', $cronEntrypoint);
        self::assertStringContainsString('runuser -u www-data -- php /var/www/src/cron/prepare_log_file.php', $webEntrypoint);
        self::assertStringContainsString('runuser -u www-data -- php /var/www/src/cron/prepare_log_file.php', $cronEntrypoint);
        self::assertStringNotContainsString('touch "$ERROR_LOG"', $webEntrypoint);
        self::assertStringNotContainsString('chown www-data:www-data "$ERROR_LOG"', $webEntrypoint);
        self::assertStringNotContainsString('touch "$LOG_DIR/cron.log"', $cronEntrypoint);
        self::assertStringNotContainsString('chown -R www-data:www-data "$LOG_DIR"', $cronEntrypoint);
        self::assertStringContainsString('must be a regular file', $webEntrypoint);
        self::assertStringContainsString('must be a regular file', $cronEntrypoint);
        self::assertStringContainsString('hash_file', $initializer);
        self::assertStringContainsString('fsync', $initializer);
        self::assertStringContainsString('rename($temporary, $path)', $initializer);
        self::assertStringNotContainsString('chown(', $initializer);
        self::assertStringNotContainsString('copy(', $initializer);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
