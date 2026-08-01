<?php

declare(strict_types=1);

namespace App\Logging;

use RuntimeException;

/**
 * Size-bounds repository-owned file logs without copy/truncate races.
 *
 * Oversized active files are renamed on their own filesystem. Writers that
 * already have the old inode open can finish safely while new writers reopen
 * the recreated active path. Archives are not compressed until they have been
 * quiescent for the configured grace period.
 */
final class BoundedLogRotator
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const DEFAULT_ARCHIVE_RETENTION = 5;
    public const DEFAULT_DAILY_RETENTION = 30;
    public const DEFAULT_QUIESCENT_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $archiveRetention = self::DEFAULT_ARCHIVE_RETENTION,
        private readonly int $dailyRetention = self::DEFAULT_DAILY_RETENTION,
        private readonly int $quiescentSeconds = self::DEFAULT_QUIESCENT_SECONDS
    ) {
        if ($maxBytes < 1 || $archiveRetention < 1 || $dailyRetention < 1 || $quiescentSeconds < 0) {
            throw new RuntimeException('Invalid bounded log rotation policy.');
        }
    }

    /**
     * @param list<string> $directories
     * @return array{rotated:int,compressed:int,deleted:int,errors:list<string>}
     */
    public function sweep(array $directories, ?int $now = null): array
    {
        $now ??= time();
        $summary = ['rotated' => 0, 'compressed' => 0, 'deleted' => 0, 'errors' => []];

        foreach (array_values(array_unique($directories)) as $directory) {
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $lockPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.log-rotation.lock';
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) {
                $summary['errors'][] = "Could not open rotation lock for {$directory}.";
                continue;
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                continue;
            }

            try {
                // Maintain only archives from earlier sweeps. A newly renamed
                // inode may still have an active writer and must never be
                // compressed or pruned in the same pass.
                $sizeStreams = $this->sizeArchiveStreams($directory);
                foreach ($sizeStreams as $path) {
                    $this->maintainSizeArchives($path, $now, $summary);
                }
                $this->maintainCompletedDailyLogs($directory, $now, $summary);

                foreach ($this->activeFiles($directory) as $path) {
                    try {
                        if ($this->rotateOversized($path, $now) !== null) {
                            $summary['rotated']++;
                        }
                    } catch (\Throwable $error) {
                        $summary['errors'][] = $error->getMessage();
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $summary;
    }

    /** @return list<string> */
    private function activeFiles(string $directory): array
    {
        $paths = array_merge(
            glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.log') ?: [],
            glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.txt') ?: []
        );
        $paths = array_values(array_filter($paths, static fn(string $path): bool =>
            is_file($path) && !is_link($path)
        ));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return list<string> */
    private function sizeArchiveStreams(string $directory): array
    {
        $streams = array_fill_keys($this->activeFiles($directory), true);
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if (!preg_match(
                '/^(.*\.(?:log|txt))\.\d{8}T\d{6}Z-[a-f0-9]{8}(?:\.gz)?$/',
                $entry,
                $matches
            )) {
                continue;
            }
            $archive = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($archive) || is_link($archive)) {
                continue;
            }
            $streams[rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $matches[1]] = true;
        }
        $paths = array_keys($streams);
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function rotateOversized(string $path, int $now): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || (int)$stat['size'] < $this->maxBytes) {
            return null;
        }

        $suffix = gmdate('Ymd\THis\Z', $now) . '-' . bin2hex(random_bytes(4));
        $archive = $path . '.' . $suffix;
        $replacement = dirname($path) . DIRECTORY_SEPARATOR
            . '.' . basename($path) . '.replacement-' . bin2hex(random_bytes(4));
        $replacementHandle = @fopen($replacement, 'x+b');
        if ($replacementHandle === false) {
            throw new RuntimeException('Could not prepare replacement for ' . basename($path) . '.');
        }
        fclose($replacementHandle);
        $this->applyMetadata($replacement, $stat);

        if (!@rename($path, $archive)) {
            @unlink($replacement);
            throw new RuntimeException('Could not rotate ' . basename($path) . '.');
        }

        // A path-based writer may have recreated the active name in the tiny
        // rename window. Keep that path untouched rather than following it or
        // replacing data that the writer just created.
        if (file_exists($path) || is_link($path)) {
            @unlink($replacement);
        } elseif (!@rename($replacement, $path)) {
            // Restore the original name when no writer claimed it and the
            // prepared replacement could not be installed.
            @rename($archive, $path);
            @unlink($replacement);
            throw new RuntimeException('Could not recreate ' . basename($path) . ' after rotation.');
        }

        return $archive;
    }

    /**
     * @param array{rotated:int,compressed:int,deleted:int,errors:list<string>} $summary
     */
    private function maintainSizeArchives(string $activePath, int $now, array &$summary): void
    {
        $pattern = '/\.\d{8}T\d{6}Z-[a-f0-9]{8}(?:\.gz)?$/';
        $archives = array_values(array_filter(
            glob($activePath . '.*') ?: [],
            static fn(string $path): bool => is_file($path)
                && !is_link($path)
                && preg_match($pattern, $path) === 1
        ));

        foreach ($archives as $archive) {
            if (str_ends_with($archive, '.gz') || !$this->isQuiescent($archive, $now)) {
                continue;
            }
            try {
                if ($this->compressStableFile($archive)) {
                    $summary['compressed']++;
                }
            } catch (\Throwable $error) {
                $summary['errors'][] = $error->getMessage();
            }
        }

        $compressed = array_values(array_filter(
            glob($activePath . '.*.gz') ?: [],
            static fn(string $path): bool => is_file($path)
                && !is_link($path)
                && preg_match($pattern, $path) === 1
        ));
        usort($compressed, static fn(string $a, string $b): int =>
            ((int)filemtime($b) <=> (int)filemtime($a)) ?: strcmp($b, $a)
        );
        foreach (array_slice($compressed, $this->archiveRetention) as $old) {
            if ($this->isQuiescent($old, $now) && @unlink($old)) {
                $summary['deleted']++;
            }
        }
    }

    /**
     * Compress completed date-named logs (including Monolog's app-YYYY-MM-DD
     * files) and retain a bounded history for each filename prefix.
     *
     * @param array{rotated:int,compressed:int,deleted:int,errors:list<string>} $summary
     */
    private function maintainCompletedDailyLogs(string $directory, int $now, array &$summary): void
    {
        $groups = [];
        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $name = basename($path);
            if (!preg_match('/^(.*?)(\d{4}-\d{2}-\d{2})\.log$/', $name, $matches)) {
                continue;
            }
            if ($matches[2] >= gmdate('Y-m-d', $now) || !$this->isQuiescent($path, $now)) {
                continue;
            }
            try {
                if ($this->compressStableFile($path)) {
                    $summary['compressed']++;
                }
            } catch (\Throwable $error) {
                $summary['errors'][] = $error->getMessage();
            }
            $groups[$matches[1]] = true;
        }

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.log.gz') ?: [] as $path) {
            if (preg_match('/^(.*?)(\d{4}-\d{2}-\d{2})\.log\.gz$/', basename($path), $matches)) {
                $groups[$matches[1]] = true;
            }
        }

        foreach (array_keys($groups) as $prefix) {
            $quotedPrefix = preg_quote($prefix, '/');
            $compressed = array_values(array_filter(
                glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*.log.gz') ?: [],
                static fn(string $path): bool => is_file($path)
                    && !is_link($path)
                    && preg_match('/^' . $quotedPrefix . '\d{4}-\d{2}-\d{2}\.log\.gz$/', basename($path)) === 1
            ));
            rsort($compressed, SORT_STRING);
            foreach (array_slice($compressed, $this->dailyRetention) as $old) {
                if ($this->isQuiescent($old, $now) && @unlink($old)) {
                    $summary['deleted']++;
                }
            }
        }
    }

    private function isQuiescent(string $path, int $now): bool
    {
        $modified = @filemtime($path);
        return $modified !== false
            && $modified <= ($now - $this->quiescentSeconds)
            && !$this->isOpenInProcessNamespace($path);
    }

    /**
     * The cron container can prove that its own redirected writers released an
     * archive before compression. Other runtimes fall back to the conservative
     * quiescent-age check above.
     */
    private function isOpenInProcessNamespace(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc')) {
            return false;
        }
        $target = @stat($path);
        if ($target === false) {
            return false;
        }
        foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $descriptor) {
            $open = @stat($descriptor);
            if ($open !== false
                && (int)$open['dev'] === (int)$target['dev']
                && (int)$open['ino'] === (int)$target['ino']
            ) {
                return true;
            }
        }
        return false;
    }

    private function compressStableFile(string $source): bool
    {
        clearstatcache(true, $source);
        $before = @lstat($source);
        if ($before === false || is_link($source)) {
            return false;
        }
        $destination = $source . '.gz';
        if (file_exists($destination) || is_link($destination)) {
            return false;
        }
        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(4));
        $input = @fopen($source, 'rb');
        $output = @gzopen($temporary, 'wb9');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                @gzclose($output);
            }
            @unlink($temporary);
            throw new RuntimeException('Could not open ' . basename($source) . ' for compression.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $ok = true;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
            $written = gzwrite($output, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                $ok = false;
                break;
            }
        }
        fclose($input);
        $closed = @gzclose($output);
        if (!$ok || !$closed) {
            @unlink($temporary);
            throw new RuntimeException('Could not finalize ' . basename($source) . ' compression.');
        }
        $expectedHash = hash_final($hash);

        clearstatcache(true, $source);
        $after = @lstat($source);
        $sourceHash = @hash_file('sha256', $source);
        if (!$this->sameFileVersion($before, $after)
            || $bytes !== (int)$before['size']
            || !is_string($sourceHash)
            || !hash_equals($expectedHash, $sourceHash)
        ) {
            @unlink($temporary);
            return false;
        }
        if (!$this->verifyGzip($temporary, $bytes, $expectedHash)) {
            @unlink($temporary);
            throw new RuntimeException('Compressed archive failed integrity verification for ' . basename($source) . '.');
        }
        $this->syncFile($temporary);
        $this->applyMetadata($temporary, $before);
        @touch($temporary, (int)$before['mtime']);

        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish compressed archive ' . basename($destination) . '.');
        }

        clearstatcache(true, $source);
        if (!$this->sameFileVersion($before, @lstat($source))) {
            @unlink($destination);
            return false;
        }
        if (!@unlink($source)) {
            @unlink($destination);
            throw new RuntimeException('Could not remove uncompressed archive ' . basename($source) . '.');
        }
        return true;
    }

    /** @param array<string|int,mixed>|false $candidate */
    private function sameFileVersion(array $expected, array|false $candidate): bool
    {
        if ($candidate === false) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($expected[$field] ?? -1) !== (int)($candidate[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    private function verifyGzip(string $path, int $expectedBytes, string $expectedHash): bool
    {
        $input = @gzopen($path, 'rb');
        if ($input === false) {
            return false;
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $ok = true;
        while (!gzeof($input)) {
            $chunk = @gzread($input, 1024 * 1024);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if ($chunk !== '') {
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }
        }
        $closed = @gzclose($input);
        return $ok
            && $closed
            && $bytes === $expectedBytes
            && hash_equals($expectedHash, hash_final($hash));
    }

    private function syncFile(string $path): void
    {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open compressed archive for durability sync.');
        }
        $synced = @fflush($handle) && @fsync($handle);
        fclose($handle);
        if (!$synced) {
            throw new RuntimeException('Could not durability-sync compressed archive.');
        }
    }

    /** @param array<string|int,mixed> $stat */
    private function applyMetadata(string $path, array $stat): void
    {
        $mode = ((int)($stat['mode'] ?? 0660)) & 0777;
        @chmod($path, $mode > 0 ? $mode : 0660);
        if (isset($stat['uid'])) {
            @chown($path, (int)$stat['uid']);
        }
        if (isset($stat['gid'])) {
            @chgrp($path, (int)$stat['gid']);
        }
    }
}
