<?php
// tools/path_audit.php
// Scans PHP files under src/controllers and src/views/pages for __DIR__-based require/include statements
// and reports any referenced files that don't exist.

$roots = [
    __DIR__ . '/../src/controllers',
    __DIR__ . '/../src/views/pages',
];

$files = [];
foreach ($roots as $r) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strtolower($f->getExtension()) !== 'php') continue;
        $files[] = $f->getPathname();
    }
}

$pattern = '/(require_once|require|include_once|include)\s*\(?\s*__DIR__\s*\.\s*(["\'])([^"\']+)\2\s*\)?\s*;?/i';
$errors = [];
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;
    if (preg_match_all($pattern, $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $raw = $match[3];
            // raw starts with . e.g., '/../views/pages/quote-print.php' or '/../config/db.php'
            // Normalize concatenation of __DIR__ . '/..'
            // Build raw candidate path (dir + relative)
            $dir = dirname($file);
            $candidateRaw = $dir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\','/'], DIRECTORY_SEPARATOR, $raw), DIRECTORY_SEPARATOR);
            $candidateReal = realpath($candidateRaw);
            $exists = $candidateReal !== false ? is_file($candidateReal) : is_file($candidateRaw);
            if (!$exists) {
                $errors[] = [
                    'file' => $file,
                    'require' => $raw,
                    'resolved' => $candidateReal ?: $candidateRaw,
                ];
            }
        }
    }
}

if (empty($errors)) {
    echo "OK: no missing __DIR__ require/include targets detected.\n";
    exit(0);
}

foreach ($errors as $e) {
    echo "MISSING: {$e['file']} -> {$e['require']} -> {$e['resolved']}\n";
}

exit(1);

// No extra helpers required; realpath/file_exists used above.
