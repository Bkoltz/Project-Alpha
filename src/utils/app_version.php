<?php
// Resolves the running Project Alpha version. Set at image build time via the
// APP_VERSION build-arg/env or a baked /var/www/APP_VERSION file; falls back to "dev".
function app_version(): string {
    static $v = null;
    if ($v !== null) return $v;
    $env = getenv('APP_VERSION');
    if ($env !== false && trim($env) !== '') { $v = trim($env); return $v; }
    foreach (['/var/www/APP_VERSION', __DIR__ . '/../../APP_VERSION'] as $f) {
        if (@is_readable($f)) {
            $c = trim((string)@file_get_contents($f));
            if ($c !== '') { $v = $c; return $v; }
        }
    }
    $v = 'dev';
    return $v;
}

function asset_url(string $path): string {
    $path = '/' . ltrim($path, '/');
    $version = app_version();

    // Source checkouts keep assets in <project>/public, while the production
    // container mounts that directory directly as DOCUMENT_ROOT. Check both
    // layouts so every deployment receives a file-mtime cache buster.
    $publicRoots = [dirname(__DIR__, 2) . '/public'];
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($documentRoot !== '') {
        $publicRoots[] = $documentRoot;
    }

    foreach (array_unique($publicRoots) as $publicRoot) {
        $filePath = rtrim($publicRoot, '/\\') . $path;
        if (@is_file($filePath)) {
            $mtime = @filemtime($filePath);
            if ($mtime !== false) {
                $version .= '-' . $mtime;
            }
            break;
        }
    }

    $separator = str_contains($path, '?') ? '&' : '?';
    return $path . $separator . 'v=' . rawurlencode($version);
}
