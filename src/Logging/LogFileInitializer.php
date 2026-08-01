<?php

declare(strict_types=1);

namespace App\Logging;

use RuntimeException;

/**
 * Establish a writable active log as the current unprivileged process.
 *
 * A legacy readable file owned by another user is copied to a private sibling,
 * verified, durability-synced, and atomically replaced without privileged
 * pathname metadata operations.
 */
final class LogFileInitializer
{
    public static function ensureWritable(string $path): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The log parent must be a real directory.');
        }

        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('Could not create the active log exclusively.');
            }
            fclose($handle);
            if (!@chmod($path, 0660)) {
                @unlink($path);
                throw new RuntimeException('Could not apply active log permissions.');
            }
            self::assertWritableRegular($path);
            return 'created';
        }

        if (!self::isRegular($stat) || is_link($path)) {
            throw new RuntimeException('The active log must be a regular file.');
        }
        if (is_writable($path)) {
            return 'existing';
        }
        if (!is_readable($path)) {
            throw new RuntimeException('The legacy active log is not readable for adoption.');
        }

        return self::adoptReadableLegacyFile($path, $stat);
    }

    /** @param array<string|int,mixed> $expected */
    private static function adoptReadableLegacyFile(string $path, array $expected): string
    {
        $temporary = dirname($path) . DIRECTORY_SEPARATOR
            . '.' . basename($path) . '.adopt-' . bin2hex(random_bytes(8));
        $input = @fopen($path, 'rb');
        $output = @fopen($temporary, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw new RuntimeException('Could not prepare legacy log adoption.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $ok = self::sameVersion($expected, fstat($input));
        while ($ok && !feof($input)) {
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
            if (fwrite($output, $chunk) !== strlen($chunk)) {
                $ok = false;
                break;
            }
        }
        $flushed = $ok && fflush($output);
        $synced = $flushed && (!function_exists('fsync') || fsync($output));
        fclose($output);
        $expectedHash = hash_final($hash);

        clearstatcache(true, $path);
        $current = @lstat($path);
        $sourceStable = self::sameVersion($expected, fstat($input))
            && self::sameVersion($expected, $current);
        $temporaryHash = @hash_file('sha256', $temporary);
        if (!$synced
            || !$sourceStable
            || $bytes !== (int)$expected['size']
            || !is_string($temporaryHash)
            || !hash_equals($expectedHash, $temporaryHash)
        ) {
            fclose($input);
            @unlink($temporary);
            throw new RuntimeException('The legacy active log changed during adoption.');
        }
        if (!@chmod($temporary, 0660) || !@rename($temporary, $path)) {
            fclose($input);
            @unlink($temporary);
            throw new RuntimeException('Could not publish the adopted active log.');
        }
        fclose($input);
        self::assertWritableRegular($path);
        return 'adopted';
    }

    /** @param array<string|int,mixed>|false $stat */
    private static function isRegular(array|false $stat): bool
    {
        return $stat !== false && (((int)$stat['mode'] & 0170000) === 0100000);
    }

    /**
     * @param array<string|int,mixed> $expected
     * @param array<string|int,mixed>|false $candidate
     */
    private static function sameVersion(array $expected, array|false $candidate): bool
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

    private static function assertWritableRegular(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !self::isRegular(@lstat($path)) || !is_writable($path)) {
            throw new RuntimeException('The active log is not a writable regular file.');
        }
    }
}
