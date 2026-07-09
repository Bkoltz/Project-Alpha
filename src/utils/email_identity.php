<?php

declare(strict_types=1);

function pa_email_sender_name(array $appConfig, bool $allowSmtpOverride = true): string
{
    $candidates = [];
    if ($allowSmtpOverride) {
        $candidates[] = $appConfig['smtp_from_name'] ?? '';
    }
    $candidates[] = $appConfig['from_company'] ?? '';
    $candidates[] = $appConfig['brand_name'] ?? '';
    $candidates[] = $appConfig['from_name'] ?? '';
    $candidates[] = 'Project Alpha';

    foreach ($candidates as $candidate) {
        $name = trim((string)$candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return 'Project Alpha';
}
