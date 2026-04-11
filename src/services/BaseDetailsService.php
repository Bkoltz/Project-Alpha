<?php

namespace App\services;

use App\config\AppConfiguration;
use App\data_transfer_objects\render_outputs\DocumentDetails\BrandingView;
use App\data_transfer_objects\render_outputs\DocumentDetails\ContactInfoView;
use finfo;

class BaseDetailsService
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    protected function getSenderContactInfo()
    {
        $appConfig = AppConfiguration::$ConfigSettings;

        $fromName = $appConfig['brand_name'] ?? 'Project Alpha';
        $fromPhone = $appConfig['from_phone'] ?? '';
        $fromEmail = $appConfig['from_email'] ?? '';

        $cityLine = array_filter([
            trim((string)($appConfig['from_city'] ?? '')),
            trim((string)($appConfig['from_state'] ?? '')),
            trim((string)($appConfig['from_postal'] ?? ''))
        ]);

        $cityLine = implode(', ', $cityLine);

        $fromLines = array_filter([
            trim((string)($appConfig['brand_name'] ?? 'Project Alpha')),
            trim((string)($appConfig['from_address_line1'] ?? '')),
            trim((string)($appConfig['from_address_line2'] ?? '')),
            $cityLine
        ]);

        return ['fromLines' => $fromLines, 'fromName' => $fromName, 'fromPhone' => $fromPhone, 'fromEmail' => $fromEmail];
    }

    protected function getContactInfo(int $clientId): ContactInfoView
    {
        $clientContactInfo = $this->clientService->getClientContactInformationById($clientId);
        $senderContactInfo = $this->getSenderContactInfo();
        return new ContactInfoView((array_merge($clientContactInfo, $senderContactInfo)));
    }

    protected function getBranding(): BrandingView
    {
        $logo_path = $this->resolveLogoPath();
        return new BrandingView(['name' => AppConfiguration::$ConfigSettings['brand_name'], 'logo_path' => $logo_path]);
    }

    protected function resolveLogoPath(): string
    {
        $appConfig = AppConfiguration::$ConfigSettings;

        // Resolve default logo under project root public/assets
        $desiredLogoPath = $appConfig['logo_path'];
        $defaultLogoPath = BASE_PATH . '/public/assets/default-logo.png';

        $logoConf = trim((string)($desiredLogoPath ?? ''));
        $logoPath = $logoConf ?: $defaultLogoPath;

        $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;

        if (!$isUrl) {
            $base = rtrim(BASE_PATH, DIRECTORY_SEPARATOR);

            if ($logoPath !== '') {
                $fullPath = realpath($base . DIRECTORY_SEPARATOR . ltrim($logoPath, '/\\'));

                if ($fullPath !== false && str_starts_with($fullPath, $base)) {
                    $logoPath = $fullPath;
                }
            }
        }

        // Prefer embedding local images as data URIs so Dompdf can render them reliably
        $logoSrc = $logoPath;
        if (is_file($logoPath) && !$isUrl) {
            // Try to read the file and build a data URI (base64). This avoids file:// or remote restrictions
            $imgContents = file_get_contents($logoPath);
            if ($imgContents !== false) {
                $mime = null;
                // Prefer explicit SVG mime type when extension indicates SVG
                if (str_ends_with(strtolower($logoPath), '.svg')) {
                    $mime = 'image/svg+xml';
                } else {
                    if (function_exists('finfo_open')) {
                        if (function_exists('finfo_open')) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = $finfo->buffer($imgContents) ?: null;
                        }
                    }
                }

                $allowed = [
                    'image/png',
                    'image/jpeg',
                    'image/gif',
                    'image/webp',
                    'image/svg+xml',
                ];

                if ($mime && in_array($mime, $allowed, true)) {
                    $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
                }
            } else {
                $normalized = str_replace('\\', '/', $logoPath);
                if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '/') === 0) {
                    $logoSrc = 'file:///' . ltrim($normalized, '/');
                }
            }
        }

        return $logoSrc;
    }
}
