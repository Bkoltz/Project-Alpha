<?php

declare(strict_types=1);

/**
 * Build the externally rendered recipient without changing the document's
 * internal client/contact association.
 *
 * Expected aliases are client_* and organization_* so callers cannot
 * accidentally present a client's address as the organization's address.
 *
 * @return array{lines:list<string>,phone:?string,email:?string,organization_addressed:bool,contact_included:bool}
 */
function pa_document_recipient(array $document, bool $generalRecipient = false): array
{
    if ($generalRecipient) {
        return [
            'lines' => ['General Recipient'],
            'phone' => null,
            'email' => null,
            'organization_addressed' => false,
            'contact_included' => false,
        ];
    }

    $clientName = trim((string)($document['client_name'] ?? ''));
    $organizationName = trim((string)($document['organization_name'] ?? ''));
    $organizationAddressed = $organizationName !== '';
    $contactIncluded = !$organizationAddressed || !empty($document['show_contact_on_document']);
    $lines = [];

    if ($organizationAddressed) {
        $lines[] = $organizationName;
        if ($contactIncluded && $clientName !== '') {
            $lines[] = 'Attn: ' . $clientName;
        }
    } elseif ($clientName !== '') {
        $lines[] = $clientName;
    }

    $prefix = $organizationAddressed ? 'organization_' : 'client_';
    if ($organizationAddressed && !pa_document_recipient_has_address($document, $prefix)) {
        // Preserve a usable recipient block for legacy organizations whose
        // address still lives only on the associated client.
        $prefix = 'client_';
    }
    foreach (pa_document_recipient_address_lines($document, $prefix) as $line) {
        $lines[] = $line;
    }

    return [
        'lines' => $lines,
        'phone' => $contactIncluded
            ? pa_document_recipient_value($document, 'client_phone')
            : pa_document_recipient_value($document, 'organization_phone'),
        'email' => $contactIncluded
            ? pa_document_recipient_value($document, 'client_email')
            : pa_document_recipient_value($document, 'organization_email'),
        'organization_addressed' => $organizationAddressed,
        'contact_included' => $contactIncluded,
    ];
}

function pa_document_recipient_has_address(array $document, string $prefix): bool
{
    foreach (['address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $field) {
        if (trim((string)($document[$prefix . $field] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function pa_document_recipient_address_lines(array $document, string $prefix): array
{
    $lines = [];
    foreach (['address_line1', 'address_line2'] as $field) {
        $value = trim((string)($document[$prefix . $field] ?? ''));
        if ($value !== '') {
            $lines[] = $value;
        }
    }

    $city = trim((string)($document[$prefix . 'city'] ?? ''));
    $state = trim((string)($document[$prefix . 'state'] ?? ''));
    $postalCode = trim((string)($document[$prefix . 'postal_code'] ?? ''));
    $locality = $city;
    if ($state !== '') {
        $locality .= ($locality !== '' ? ', ' : '') . $state;
    }
    if ($postalCode !== '') {
        $locality .= ($locality !== '' ? ' ' : '') . $postalCode;
    }
    if ($locality !== '') {
        $lines[] = $locality;
    }
    return $lines;
}

function pa_document_recipient_value(array $document, string $key): ?string
{
    $value = trim((string)($document[$key] ?? ''));
    return $value !== '' ? $value : null;
}
