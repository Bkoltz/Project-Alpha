<?php

namespace App\repositories\invoice;

use App\record_transfer_objects\InvoiceRecord;
use App\record_transfer_objects\ItemRecord;
use PDO;

class InvoicesRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createInvoice(InvoiceRecord $invoiceData, ItemRecord $invoiceItems)
    {
        $id = $this->insertInvoice($invoiceData);
        $this->insertInvoiceItems($id, $invoiceItems);
    }

    private function insertInvoice(InvoiceRecord $invoiceData): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($invoiceData->toArray());

        return (int)$this->pdo->lastInsertId();
    }

    private function insertInvoiceItems(int $id, ItemRecord $invoiceItems)
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i < 0; $i++) {
            $row = array_merge([$id], $invoiceItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function doesInvoiceExist(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM invoices WHERE contract_id = :id)');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function getDueDateFromContractId(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT due_date FROM invoices WHERE contract_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function denyInvoice(int $id): void
    {
        $this->pdo->prepare("UPDATE invoices SET status='denied' WHERE contract_id=? AND status<>'paid'")->execute([$id]);
    }

    public function setInvoiceDueDate(int $id, string $dueDate): void
    {
        $this->pdo->prepare('UPDATE invoices SET due_date=? WHERE id=?')->execute([$dueDate, $id]);
    }
}
