<?php

namespace App\repositories\contract;

use PDO;
use App\data_transfer_objects\contract\ContractSignatures;
use App\record_transfer_objects\contract\create_record\BaseContractRecord;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\contract\create_record\LongTermContractRecord;
use App\record_transfer_objects\contract\create_record\OnDemandContractRecord;
use App\record_transfer_objects\contract\create_record\RegularContractRecord;
use App\utils\enum\DocumentType;
use App\record_transfer_objects\ItemRecord;

class ContractRepository
{
    private PDO $pdo;

    private const  DOCUMENT_TYPE_INSERT_STATEMENTS = [
        DocumentType::REGULAR->value => 'INSERT INTO contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_value, deposit_paid, fulfillment_date, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        DocumentType::LONG_TERM->value => 'INSERT INTO long_term_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_value, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        DocumentType::ON_DEMAND->value => 'INSERT INTO on_demand_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, price_per_invoice, deposit_type, deposit_value, deposit_paid, project_code, start_date, end_date, billing_interval_count, billing_interval_unit, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    ];

    private const DOCUMENT_TYPE_EDIT_STATEMENTS = [
        DocumentType::REGULAR->value => 'UPDATE contracts SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, deposit_type=?, deposit_value=?, deposit_paid=?, fulfillment_date=?, scope=?, custom_fields=? WHERE id=?',
        DocumentType::LONG_TERM->value => 'INSERT INTO long_term_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_value, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        DocumentType::ON_DEMAND->value => 'INSERT INTO on_demand_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, price_per_invoice, deposit_type, deposit_value, deposit_paid, project_code, start_date, end_date, billing_interval_count, billing_interval_unit, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    ];

    private const DOCUMENT_TYPE_TABLES = [
        DocumentType::REGULAR->value => "contracts",
        DocumentType::LONG_TERM->value => "long_term_contracts",
        DocumentType::ON_DEMAND->value => "on_demand_contracts",
    ];

    private const DOCUMENT_TYPE_ITEM_TABLES = [
        DocumentType::REGULAR->value => "contract_items",
        DocumentType::LONG_TERM->value => "long_term_contract_items",
        DocumentType::ON_DEMAND->value => "on_demand_contract_items",
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

    public function updateFullContract(int $id, DocumentType $documentType, InsertableRecord $contractData, ?ItemRecord $contractItems): void
    {
        $this->updateContract($id, $documentType, $contractData);
        $this->updateContractItems($id, $documentType, $contractItems);
    }

    public function updateContract(int $id, DocumentType $documentType, InsertableRecord $contractData): void
    {
        $sql = self::DOCUMENT_TYPE_EDIT_STATEMENTS[$documentType->value];
        $stmt = $this->pdo->prepare($sql);

        $stmt->execute(array_merge($contractData->toInsertValues(), [$id]));
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

    public function getProjectCodeFromId(int $id, DocumentType $documentType): string
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$documentType->value];
        $stmt = $this->pdo->prepare('SELECT project_code FROM ' . $table . ' WHERE id=?');
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
        $table = $this::DOCUMENT_TYPE_ITEM_TABLES[$documentType->value];
        $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE contract_id=?')->execute([$id]);

        $this->insertContractItems($id, $documentType, $contractItems);
    }

    public function insertContractItems(int $id, DocumentType $documentType, ItemRecord $contractItems): void
    {
        $table = $this::DOCUMENT_TYPE_ITEM_TABLES[$documentType->value];
        $stmt = $this->pdo->prepare('INSERT INTO ' . $table . ' (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i < count($contractItems->item); $i++) {
            $row = array_merge([$id], $contractItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function payDeposit(int $id, DocumentType $documentType, float $depositPaid): void
    {
        $table = $this::DOCUMENT_TYPE_TABLES[$documentType->value];
        $stmt = $this->pdo->prepare('UPDATE ' . $table . ' SET deposit_paid=? WHERE id=?');
        $stmt->execute([$depositPaid, $id]);
    }

    public function getStoredContractItems(int $id, DocumentType $documentType): ?ItemRecord
    {
        $table = self::DOCUMENT_TYPE_ITEM_TABLES[$documentType->value];
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE contract_id=?');
        $stmt->execute([$id]);

        return ItemRecord::fromRepoArray($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
    }

    public function setContractSignaturePath(int $id, DocumentType $documentType, string $path): void {
        $table = self::DOCUMENT_TYPE_TABLES[$documentType->value];
        $stmt = $this->pdo->prepare('UPDATE ' . $table . ' SET signed_pdf_path=?, status=? WHERE id=?');
        $stmt->execute([$path, 'active', $id]);
    }

    public function getStoredSignatures(int $id): ?ContractSignatures
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id=?');
        $stmt->execute([$id]);

        return ContractSignatures::fromArray($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
    }

    public function getStoredContract(int $id, DocumentType $documentType): BaseContractRecord
    {
        $table = self::DOCUMENT_TYPE_TABLES[$documentType->value];

        $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=?');
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
