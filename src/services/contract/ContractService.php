<?php

namespace App\services\contract;

use App\data_transfer_objects\contract\ContractEditData;
use App\data_transfer_objects\contract\ContractSignatures;
use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\contract\ContractData;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\contract\create_record\RegularContractRecord;
use App\record_transfer_objects\contract\create_record\LongTermContractRecord;
use App\record_transfer_objects\contract\create_record\OnDemandContractRecord;
use App\record_transfer_objects\ContractEditRecord;
use App\record_transfer_objects\ItemRecord;
use App\repositories\contract\ContractRepository;
use App\services\DateValidator;
use App\services\FinancialService;
use App\services\ProjectService;
use App\utils\enum\DocumentType;
use Exception;

class ContractService
{
    private ContractRepository $repository;
    private ProjectService $projectService;

    public function __construct(ContractRepository $repository, ProjectService $projectService)
    {
        $this->repository = $repository;
        $this->projectService = $projectService;
    }

    public function createContract(DocumentType $documentType, ContractData $contractData, ?ItemData $contractItems): int
    {
        $this->updateAndValidateContractData($contractData);
        $contractItems?->validate();

        $record = $this->generateInsertRecord($documentType, $contractData->toArray());
        $recordItems = ItemRecord::fromArray($contractItems?->toArray());

        return $this->repository->createContract($documentType, $record, $recordItems);
    }

    private function generateInsertRecord(DocumentType $documentType, array $data): InsertableRecord
    {
        return match ($documentType) {
            DocumentType::REGULAR => RegularContractRecord::fromArray($data),
            DocumentType::ON_DEMAND => OnDemandContractRecord::fromArray($data),
            DocumentType::LONG_TERM => LongTermContractRecord::fromArray($data),
            default => throw new Exception("Invalid document type")
        };
    }

    public function updateContract(int $id, DocumentType $documentType, ContractEditData $contractData, ?ItemData $contractItems): void
    {
        $this->validateContractEditData($contractData);
        $contractItems?->validate();

        $record = ContractEditRecord::fromArray($contractData->toArray());
        $recordItems = ItemRecord::fromArray($contractItems?->toArray());

        $this->repository->updateContract($id, $record);
        $this->repository->updateContractItems($id, $documentType, $recordItems);
    }

    public function createContractWithSignatures(DocumentType $documentType, ContractData $contractData, ?ItemData $contractItems, ContractSignatures $contractSignatures): void
    {
        $id = $this->createContract($documentType, $contractData, $contractItems);
        $this->addContractSignatures($id, $contractSignatures);
    }

    public function updateContractWithSignatures(int $id, DocumentType $documentType, ContractEditData $contractData, ?ItemData $contractItems, ContractSignatures $contractSignatures): void
    {
        $this->updateContract($id, $documentType, $contractData, $contractItems);
        $this->updateContractSignatures($id, $contractSignatures);
    }

    public function addContractSignatures(int $id, ContractSignatures $contractSignatures): void
    {
        $this->repository->updateContractSignatures($id, $contractSignatures);
    }

    public function updateContractSignatures(int $id, ContractSignatures $contractSignatures): void
    {
        $this->repository->updateContractSignatures($id, $contractSignatures);
    }

    public function getStoredContractItems(int $id): ?ItemData
    {
        $items = $this->repository->getStoredContractItems($id);
        return ItemData::fromArray($items?->toArray());
    }

    public function getStoredContract(int $id, DocumentType $documentType): ContractData
    {
        $storedContract = $this->repository->getStoredContract($id, $documentType);
        return ContractData::fromArray($storedContract->toArray());
    }

    public function getStoredSignatures(int $id): ?ContractSignatures
    {
        return $this->repository->getStoredSignatures($id);
    }

    public function payFullDeposit(DocumentType $documentType, int $id): float
    {
        $storedContract = $this->repository->getStoredContract($id, $documentType);
        $paidDeposit = FinancialService::calculateDepositValue($storedContract);

        $this->repository->payDeposit($id, $documentType, $paidDeposit);
        return $paidDeposit;
    }

    /* 
        Data validation
    */

    private function validateContractEditData(ContractEditData $contractData): void
    {
        $contractData->client_id ??= 0;
        $contractData->discount_type ??= 'none';
        $contractData->discount_value ??= 0;
        $contractData->tax_percent ??= 0;
        $contractData->subtotal ??= 0;
        $contractData->total ??= 0;
        $contractData->terms ??= '';
        $contractData->weather_pending ??= false;
        $contractData->deposit_type ??= 'none';
        $contractData->deposit_amount ??= 0;
        $contractData->deposit_paid ??= 0;
        $contractData->scope ??= '';
        $contractData->custom_fields ??= [];
    }

    private function validateContractData(ContractData $contractData): void
    {
        $contractData->quote_id ?: null;
        $contractData->client_id ?: null;
        $contractData->project_id ??= 0;
        $contractData->status ??= 'pending';
        $contractData->discount_type ??= 'none';
        $contractData->tax_percent ??= 0;
        $contractData->subtotal ??= 0;
        $contractData->total ??= 0;
        $contractData->deposit_type ??= 'none';
        $contractData->deposit_amount ??= 0;
        $contractData->deposit_paid ??= 0;
        $contractData->fulfillment_date = DateValidator::validateDate($contractData->fulfillment_date);
    }

    private function updateAndValidateContractData(ContractData $contractData): void
    {
        $this->validateContractData($contractData);
        $this->updateContractData($contractData);
    }

    private function updateContractData(ContractData $contractData): void {
        $contractData->status = 'pending';
        $contractData->created_at = date('Y-m-d H:i:s');
        $contractData->project_code = $this->projectService->getNextProjectCode($contractData->client_id);
    }

    /* 
        Status related methods
    */

    public function activateContract(int $id, DocumentType $documentType): void
    {
        $this->repository->activateContract($id, $documentType);
    }

    public function pauseContract(int $id, DocumentType $documentType): void
    {
        $this->repository->pauseContract($id, $documentType);
    }

    public function denyContract(int $id, DocumentType $documentType): void
    {
        $this->repository->denyContract($id, $documentType);
    }

    public function completeContract(int $id, DocumentType $documentType): void
    {
        $this->repository->completeContract($id, $documentType);
    }

    public function voidContract(int $id, DocumentType $documentType): void
    {
        $this->repository->voidContract($id, $documentType);
    }
}
