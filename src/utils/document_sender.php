<?php
// src/utils/document_sender.php
// Optional per-user sender profile for quote, contract, and invoice documents.
require_once __DIR__ . '/email_identity.php';

function document_sender_defaults(array $appConfig): array
{
    $company = trim((string)($appConfig['from_company'] ?? ''));
    if ($company === '') {
        $company = (string)($appConfig['brand_name'] ?? 'Project Alpha');
    }

    return [
        'enabled' => false,
        'name' => ($appConfig['from_name'] ?? '') ?: pa_email_sender_name($appConfig, false),
        'company' => $company,
        'address_line1' => $appConfig['from_address_line1'] ?? '',
        'address_line2' => $appConfig['from_address_line2'] ?? '',
        'city' => $appConfig['from_city'] ?? '',
        'state' => $appConfig['from_state'] ?? '',
        'postal' => $appConfig['from_postal'] ?? '',
        'country' => $appConfig['from_country'] ?? '',
        'phone' => $appConfig['from_phone'] ?? '',
        'email' => $appConfig['from_email'] ?? '',
    ];
}

function document_sender_for_creator(PDO $pdo, array $appConfig, ?int $creatorId): array
{
    $defaults = document_sender_defaults($appConfig);
    if (!$creatorId) {
        return $defaults;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT document_sender_enabled, document_sender_name, document_sender_company,
                    document_sender_address_line1, document_sender_address_line2,
                    document_sender_city, document_sender_state, document_sender_postal,
                    document_sender_country, document_sender_phone, document_sender_email
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$creatorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $defaults;
    }

    if (!$row || empty($row['document_sender_enabled'])) {
        return $defaults;
    }

    $sender = $defaults;
    $map = [
        'name' => 'document_sender_name',
        'company' => 'document_sender_company',
        'address_line1' => 'document_sender_address_line1',
        'address_line2' => 'document_sender_address_line2',
        'city' => 'document_sender_city',
        'state' => 'document_sender_state',
        'postal' => 'document_sender_postal',
        'country' => 'document_sender_country',
        'phone' => 'document_sender_phone',
        'email' => 'document_sender_email',
    ];

    foreach ($map as $key => $column) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value !== '') {
            $sender[$key] = $value;
        }
    }
    $sender['enabled'] = true;

    return $sender;
}

function document_sender_lines(array $sender): array
{
    $lines = [];
    $name = trim((string)($sender['name'] ?? ''));
    $company = trim((string)($sender['company'] ?? ''));
    if ($name !== '') {
        $lines[] = $name;
    }
    if ($company !== '' && strcasecmp($company, $name) !== 0) {
        $lines[] = $company;
    }

    foreach (['address_line1', 'address_line2'] as $key) {
        $value = trim((string)($sender[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $value;
        }
    }

    $cityParts = [];
    foreach (['city', 'state', 'postal'] as $key) {
        $value = trim((string)($sender[$key] ?? ''));
        if ($value !== '') {
            $cityParts[] = $value;
        }
    }
    if (!empty($cityParts)) {
        $lines[] = implode(', ', $cityParts);
    }

    $country = trim((string)($sender['country'] ?? ''));
    if ($country !== '') {
        $lines[] = $country;
    }

    return $lines;
}
