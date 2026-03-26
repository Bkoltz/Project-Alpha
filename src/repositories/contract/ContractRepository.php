<?php

namespace App\repositories\contract;

use App\record_transfer_objects\ContractItemsRecord;
use PDO;
use App\utils\enum\DocumentType;
use App\record_transfer_objects\ContractRecord;

class ContractRepository
{
    private PDO $pdo;

    private const DOCUMENT_TYPE_INSERT_STATEMENTS = [
        DocumentType::REGULAR->value => 'INSERT INTO contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        DocumentType::LONG_TERM->value => 'INSERT INTO long_term_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        DocumentType::ON_DEMAND->value => 'INSERT INTO on_demand_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, price_per_invoice, deposit_type, deposit_amount, deposit_paid, project_code, start_date, end_date, billing_interval_count, billing_interval_unit, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createContract(ContractRecord $contractData, ContractItemsRecord $contractItems) : void
    {
        $id = $this->insertContract($contractData);
        $this->insertContractItems($id, $contractItems);
    }

    private function insertContract(ContractRecord $contractData): int
    {
        $insertStatement = $this::DOCUMENT_TYPE_INSERT_STATEMENTS['regular'];

        $stmt = $this->pdo->prepare($insertStatement);
        $stmt->execute($contractData->toArray());

        return (int)$this->pdo->lastInsertId();
    }

    private function insertContractItems(int $id, ContractItemsRecord $contractItems): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i > 0; $i++) {
            $row = array_merge([$id], $contractItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function denyContract(int $id): void
    {
        $this->pdo->prepare('UPDATE contracts SET status="denied" WHERE id=?')->execute([$id]);
    }

    public function completeContract(int $id): void
    {
        $this->pdo->prepare('UPDATE contracts SET status=?, completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['completed', $id]);
    }
}
