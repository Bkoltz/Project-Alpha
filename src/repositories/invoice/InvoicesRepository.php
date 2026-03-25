<?php

namespace App\repositories\invoice;

use PDO;

class InvoicesRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createInvoice(array $invoiceData, array $invoiceItems)
    {
        $id = $this->insertInvoice($invoiceData);
        $this->insertInvoiceItems($id, $invoiceItems);
    }

    private function insertInvoice(array $invoiceData): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($invoiceData);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertInvoiceItems(int $id, array $invoiceItems)
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        foreach ($invoiceItems as $item) {
            if (!empty($item))
                $stmt->execute([$id, $item['description'], $item['description'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        }
    }
}
