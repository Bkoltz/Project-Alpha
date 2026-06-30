<?php

require_once __DIR__ . '/acl.php';

function document_access_table_for_type(string $type): ?string
{
    return match ($type) {
        'quote' => 'quotes',
        'contract' => 'contracts',
        'invoice' => 'invoices',
        'project_invoice' => 'project_invoices',
        default => null,
    };
}

function document_access_load(PDO $pdo, string $type, int $id, string $extraSelect = ''): ?array
{
    $table = document_access_table_for_type($type);
    if ($table === null || $id <= 0) {
        return null;
    }
    $extra = trim($extraSelect);
    if ($extra !== '' && !preg_match('/^(,\s*[A-Za-z0-9_.,\s]+)$/', $extra)) {
        throw new InvalidArgumentException('Unsafe select list.');
    }
    $stmt = $pdo->prepare("SELECT *{$extra} FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function document_access_can_manage(PDO $pdo, string $type, array $document, int $userId): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    if ($type === 'project_invoice') {
        $projectId = (int)($document['project_id'] ?? 0);
        return $projectId > 0 && can_access_record($pdo, 'projects', $projectId, $userId);
    }

    $table = document_access_table_for_type($type);
    return $table !== null && can_access_record($pdo, $table, (int)($document['id'] ?? 0), $userId);
}

function document_access_require_manage(PDO $pdo, string $type, int $id): array
{
    $document = document_access_load($pdo, $type, $id);
    if (!$document) {
        http_response_code(404);
        throw new RuntimeException('Document not found');
    }

    if (!document_access_can_manage($pdo, $type, $document, (int)($_SESSION['user']['id'] ?? 0))) {
        http_response_code(403);
        throw new RuntimeException('Permission denied');
    }

    return $document;
}
