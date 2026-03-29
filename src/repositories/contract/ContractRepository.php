<?php

namespace App\repositories\contract;

use App\record_transfer_objects\ContractItemsRecord;
use App\data_transfer_objects\ContractSignatures;
use APp\data_transfer_objects\ItemData;
use PDO;
use App\utils\enum\DocumentType;
use App\record_transfer_objects\ContractRecord;
use App\record_transfer_objects\ContractEditRecord;
use App\record_transfer_objects\ItemRecord;

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

    public function createContract(ContractRecord $contractData, ItemData $contractItems): int
    {
        $id = $this->insertContract($contractData);
        $this->insertContractItems($id, $contractItems);

        return (int)$this->pdo->lastInsertId();
    }

    public function updatedContract(ContractEditRecord $editRecord) {

    }

    public function updateContractSignatures(int $id, ContractSignatures $contractSignatures): void
    {
        $this->pdo->prepare('DELETE FROM contract_signatures WHERE contract_id=?')->execute([$id]);

        $stmt = $this->pdo->prepare('INSERT INTO contract_signatures (contract_id, signer_title, display_order, is_required) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < 0; $i++) {
            $row = $contractSignatures->getRow($i);
            $stmt->execute(array_merge([$id], $row));
        }
    }

    public function voidContract(int $id): void
    {
        $this->pdo->prepare("UPDATE contracts SET status='cancelled', voided_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
    }

    private function insertContract(ContractRecord $contractData): int
    {
        $insertStatement = $this::DOCUMENT_TYPE_INSERT_STATEMENTS['regular'];

        $stmt = $this->pdo->prepare($insertStatement);
        $stmt->execute($contractData->toArray());

        return (int)$this->pdo->lastInsertId();
    }

    private function insertContractItems(int $id, ItemRecord $contractItems): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i > 0; $i++) {
            $row = array_merge([$id], $contractItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function payDeposit(int $id, float $depositPaid): void
    {
        $this->pdo->prepare('UPDATE contracts SET deposit_paid=? WHERE id=?')->execute([$depositPaid, $id]);
    }

    public function denyContract(int $id): void
    {
        $this->pdo->prepare('UPDATE contracts SET status="denied" WHERE id=?')->execute([$id]);
    }

    public function completeContract(int $id): void
    {
        $this->pdo->prepare('UPDATE contracts SET status=?, completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['completed', $id]);
    }

    public function getStoredContract(int $id): ContractRecord
    {
        $stmt = $this->pdo->prepare('SELECT deposit_type, deposit_amount, total, deposit_paid FROM contracts WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        return ContractRecord::fromArray($stmt->fetch(PDO::FETCH_ASSOC) ?? []);
    }
}
