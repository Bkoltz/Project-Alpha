<?php

declare(strict_types=1);

final class DocumentLockedException extends RuntimeException
{
    public function __construct(string $message = 'This document is locked.')
    {
        parent::__construct($message, 409);
    }
}
final class DocumentPolicy
{
    private const TABLES = ['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'];

    public static function assertMutable(PDO $pdo, string $type, int $id, string $mutationType = 'commercial', bool $lock = false): array
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null || $id <= 0) {
            throw new InvalidArgumentException('Invalid document reference.');
        }
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1".$suffix);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Document not found.', 404);
        }
        $status = strtolower((string)($row['status'] ?? ''));
        if ($type === 'quote' && !in_array($status, ['draft', 'pending'], true)) {
            throw new DocumentLockedException('Approved, denied, rejected, or expired quotes cannot be edited. Clone this quote to make new terms.');
        }
        if ($type === 'contract' && $mutationType === 'commercial') {
            $signed = !empty($row['signed_at']) || trim((string)($row['signed_pdf_path'] ?? '')) !== '';
            if ($signed || !in_array($status, ['draft', 'pending'], true)) {
                throw new DocumentLockedException('Signed or active contracts are locked. Use an amendment or create a replacement contract.');
            }
        }
        if ($type === 'invoice' && in_array($status, ['void', 'cancelled'], true)) {
            throw new DocumentLockedException('Void or cancelled invoices must be reopened before adjustments can be made.');
        }
        return $row;
    }
}
