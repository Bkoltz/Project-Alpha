<?php
// Shared, presentation-only brand header for invoice-style documents.
$documentBrandName = trim((string)($documentBrandName ?? ($appConfig['brand_name'] ?? 'Project Alpha')));
$documentBrandName = $documentBrandName !== '' ? $documentBrandName : 'Project Alpha';
$documentBrandLabel = trim((string)($documentBrandLabel ?? ''));
$documentBrandMetaLines = is_array($documentBrandMetaLines ?? null) ? $documentBrandMetaLines : [];
$logoConf = trim((string)($appConfig['logo_path'] ?? ''));
$projectRoot = realpath(__DIR__ . '/../../..');
$defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.png') : '';
$logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
$isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;

if (preg_match('/page=serve-upload/i', $logoPath)) {
    $parsed = parse_url($logoPath);
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
        if (!empty($query['file'])) {
            $filename = basename((string)$query['file']);
            $bases = [];
            if ($projectRoot) {
                $configuredUploads = realpath($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
                $bases[] = $configuredUploads ?: ($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
            }
            $bases[] = '/var/www/config/uploads';
            foreach ($bases as $base) {
                $candidate = @realpath(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $filename);
                if ($candidate !== false && is_file($candidate)) {
                    $logoPath = $candidate;
                    $isUrl = false;
                    break;
                }
            }
        }
    }
}

if (!$isUrl && $logoPath !== '' && $projectRoot) {
    $candidate = ($logoPath[0] === '/' || $logoPath[0] === '\\')
        ? @realpath($projectRoot . $logoPath)
        : @realpath($projectRoot . DIRECTORY_SEPARATOR . $logoPath);
    if ($candidate) {
        $logoPath = $candidate;
    }
}

$canShowLogo = $isUrl || ($logoPath !== '' && @is_file($logoPath));
$logoSrc = $logoPath;
if ($canShowLogo && !$isUrl) {
    $imageContents = @file_get_contents($logoPath);
    if ($imageContents !== false) {
        $mime = null;
        if (preg_match('/\.svg$/i', $logoPath)) {
            $mime = 'image/svg+xml';
        } elseif (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_buffer($finfo, $imageContents);
                if ($detected) { $mime = $detected; }
                if (PHP_VERSION_ID < 80500) { @finfo_close($finfo); }
            }
        }
        $logoSrc = 'data:' . ($mime ?: 'image/png') . ';base64,' . base64_encode($imageContents);
    } else {
        $normalized = str_replace('\\', '/', $logoPath);
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')) {
            $logoSrc = 'file:///' . ltrim($normalized, '/');
        }
    }
}
?>
<table style="width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse">
  <tr>
    <td style="vertical-align:middle;width:70%">
      <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($documentBrandName); ?></div>
      <?php if ($documentBrandLabel !== ''): ?><div style="color:#374151;font-size:13px;margin-top:2px"><?php echo htmlspecialchars($documentBrandLabel); ?></div><?php endif; ?>
      <?php foreach ($documentBrandMetaLines as $metaLine): ?>
        <?php if (trim((string)$metaLine) !== ''): ?><div style="color:#374151;font-size:13px;margin-top:2px"><?php echo htmlspecialchars((string)$metaLine); ?></div><?php endif; ?>
      <?php endforeach; ?>
    </td>
    <td style="vertical-align:middle;width:30%;text-align:right">
      <?php if ($canShowLogo): ?>
        <?php if (!$isUrl && preg_match('/\.svg$/i', $logoPath) && is_file($logoPath) && defined('PDF_MODE')): ?>
          <?php echo @file_get_contents($logoPath); ?>
        <?php else: ?>
          <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($documentBrandName); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
        <?php endif; ?>
      <?php endif; ?>
    </td>
  </tr>
</table>
