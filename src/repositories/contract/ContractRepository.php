<?php

namespace App\repositories\contract;

use PDO;
use App\utils\enum\DocumentType;

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

    public function createContract(array $contractData, array $contractItems)
    {
        $id = $this->insertContract($contractData);
        $this->insertContractItems($id, $contractItems);
    }

    private function insertContract(array $contractData): int
    {
        $insertStatement = $this::DOCUMENT_TYPE_INSERT_STATEMENTS['regular'];

        $stmt = $this->pdo->prepare($insertStatement);
        $stmt->execute($contractData);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertContractItems(int $id, array $contractItems)
    {
        $stmt = $this->pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        foreach ($contractItems as $item) {
            if (!empty($item))
                $stmt->execute([$id, $item['description'], $item['description'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        }
    }

    public function denyContract() {
        
    }

    public function approveContract() {
        
    }
}
