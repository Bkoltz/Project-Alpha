<?php

namespace App\repositories\contract;

use App\data_transfer_objects\contract\ContractSignatures;
use PDO;
use App\record_transfer_objects\contract\create_record\BaseContractRecord;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\contract\create_record\LongTermContractRecord;
use App\record_transfer_objects\contract\create_record\OnDemandContractRecord;
use App\record_transfer_objects\contract\create_record\RegularContractRecord;
use App\record_transfer_objects\interfaces\RetrievableRecord;
use App\utils\enum\DocumentType;
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

    private const DOCUMENT_TYPE_TABLES = [
        DocumentType::REGULAR->value => "contracts",
        DocumentType::LONG_TERM->value => "long_term_contracts",
        DocumentType::ON_DEMAND->value => "on_demand_contracts",
    ];

    private const DOCUMENT_TYPE_ITEM_REMOVE_STATEMENTS = [
        DocumentType::REGULAR->value => 'DELETE FROM contract_items WHERE contract_id=?',
        DocumentType::LONG_TERM->value => 'DELETE FROM long_term_contract_items WHERE long_term_contract_id=?',
        DocumentType::ON_DEMAND->value => 'DELETE FROM on_demand_contract_items WHERE on_demand_contract_id=?',
    ];

    private const DOCUMENT_TYPE_ITEM_INSERT_STATEMENTS = [
        DocumentType::REGULAR->value => 'INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)',
        DocumentType::LONG_TERM->value => 'INSERT INTO long_term_contract_items (long_term_contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)',
        DocumentType::ON_DEMAND->value => 'INSERT INTO on_demand_contract_items (long_term_contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createContract(DocumentType $documentType, InsertableRecord $record, ?ItemRecord $contractItems): int
    {
        $id = $this->insertContract($documentType, $record);

        if (!empty($contractItems))
            $this->insertContractItems($id, $documentType, $contractItems);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateFullContract(int $id, DocumentType $documentType, ContractEditRecord $contractData, ?ItemRecord $contractItems): void
    {
        $this->updateContract($id, $contractData);
        $this->updateContractItems($id, $documentType, $contractItems);
    }

    public function updateContract(int $id, ContractEditRecord $contractData): void
    {
        $stmt = $this->pdo->prepare('UPDATE contracts SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?, deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=?, custom_fields=? WHERE id=?');
        $stmt->execute(array_merge($contractData->toArray(), [$id]));
    }

    public function updateContractSignatures(int $id, ContractSignatures $contractSignatures): void
    {
        $this->pdo->prepare('DELETE FROM contract_signatures WHERE contract_id=?')->execute([$id]);

        $this->insertContractSignatures($id, $contractSignatures);
    }

    public function insertContractSignatures(int $id, ContractSignatures $contractSignatures): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO contract_signatures (contract_id, signer_title, display_order, is_required) VALUES (?, ?, ?, ?)');

        for ($i = 0; $i < 0; $i++) {
            $row = $contractSignatures->getRow($i);
            $stmt->execute(array_merge([$id], $row));
        }
    }

    public function getProjectCodeFromId(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT project_code FROM contracts WHERE id=?');
        $stmt->execute([$id]);

        return (string)$stmt->fetchColumn();
    }

    private function insertContract(DocumentType $documentType, InsertableRecord $record): int
    {
        $sql = $this::DOCUMENT_TYPE_INSERT_STATEMENTS[$documentType->value];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($record->toInsertValues());

        return (int)$this->pdo->lastInsertId();
    }

    public function updateContractItems(int $id, DocumentType $documentType, ItemRecord $contractItems): void
    {
        $sql = $this::DOCUMENT_TYPE_ITEM_REMOVE_STATEMENTS[$documentType->value];
        $this->pdo->prepare($sql)->execute([$id]);

        $this->insertContractItems($id, $documentType, $contractItems);
    }

    public function insertContractItems(int $id, DocumentType $documentType, ItemRecord $contractItems): void
    {
        $sql = $this::DOCUMENT_TYPE_ITEM_INSERT_STATEMENTS[$documentType->value];
        $stmt = $this->pdo->prepare($sql);

        for ($i = 0; $i < count($contractItems->item); $i++) {
            $row = array_merge([$id], $contractItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function payDeposit(int $id, DocumentType $documentType, float $depositPaid): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$documentType->value];
        $this->pdo->prepare('UPDATE ' . $table . ' SET deposit_paid=? WHERE id=?')->execute([$depositPaid, $id]);
    }

    public function getStoredContractItems(int $id): ?ItemRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
        $stmt->execute([$id]);
        
        return ItemRecord::fromRepoArray($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
    }

    public function getStoredSignatures(int $id): ?ContractSignatures
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id=?');
        $stmt->execute([$id]);

        return ContractSignatures::fromArray($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
    }

    public function getStoredContract(int $id, DocumentType $documentType): BaseContractRecord
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$documentType->value];

        $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        return $this->generateRetrievedRecord($documentType, $stmt->fetch(PDO::FETCH_ASSOC) ?? []);
    }

    private function generateRetrievedRecord(DocumentType $documentType, array $data): BaseContractRecord
    {
        return match ($documentType) {
            DocumentType::REGULAR => RegularContractRecord::fromArray($data),
            DocumentType::LONG_TERM => LongTermContractRecord::fromArray($data),
            DocumentType::ON_DEMAND => OnDemandContractRecord::fromArray($data)
        };
    }

    public function getCustomFields(DocumentType $documentType): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0 ORDER BY display_order, id');
        $stmt->execute([$documentType->value]);
        return  $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
    }
    /* 
        Status related methods
    */

    public function activateContract(int $id, DocumentType $type): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$type->value] ?? "contracts";
        $stmt = $this->pdo->prepare('UPDATE ' . $table .  ' SET status="active" WHERE id=?');
        $stmt->execute([$id]);
    }

    public function pauseContract(int $id, DocumentType $type): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$type->value] ?? "contracts";
        $stmt = $this->pdo->prepare('UPDATE ' . $table .  ' SET status="paused" WHERE id=?');
        $stmt->execute([$id]);
    }

    public function denyContract(int $id, DocumentType $type): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$type->value] ?? "contracts";
        $stmt = $this->pdo->prepare('UPDATE ' . $table .  ' SET status="denied" WHERE id=?');
        $stmt->execute([$id]);
    }

    public function voidContract(int $id, DocumentType $type): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$type->value] ?? "contracts";
        $stmt = $this->pdo->prepare("UPDATE ' . $table .  ' SET status='cancelled', voided_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$id]);
    }

    public function completeContract(int $id, DocumentType $type): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$type->value] ?? "contracts";
        $stmt = $this->pdo->prepare('UPDATE ' . $table .  ' SET status="completed", completed_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$id]);
    }
}
