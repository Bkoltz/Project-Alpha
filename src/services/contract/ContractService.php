<?php

namespace App\services\contract;

use App\data_transfer_objects\contract\ContractSignatures;
use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\contract\ContractData;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\contract\create_record\RegularContractRecord;
use App\record_transfer_objects\contract\create_record\LongTermContractRecord;
use App\record_transfer_objects\contract\create_record\OnDemandContractRecord;
use App\record_transfer_objects\contract\edit_record\LongTermContractEditRecord;
use App\record_transfer_objects\contract\edit_record\OnDemandContractEditRecord;
use App\record_transfer_objects\contract\edit_record\RegularContractEditRecord;
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

        FinancialService::updateContractFinancialData($documentType, $contractData, $contractItems);

        $record = $this->generateInsertRecord($documentType, $contractData->toArray());
        $recordItems = ItemRecord::fromArray($contractItems?->toArray());

        return $this->repository->createContract($documentType, $record, $recordItems);
    }

    public function updateContract(int $id, DocumentType $documentType, ContractData $contractData, ?ItemData $contractItems): void
    {
        $this->validateContractData($contractData);
        $contractItems?->validate();

        FinancialService::updateContractFinancialData($documentType, $contractData, $contractItems);

        $record = $this->generateEditRecord($documentType, $contractData->toArray());
        $recordItems = ItemRecord::fromArray($contractItems?->toArray());

        $this->repository->updateFullContract($id, $documentType, $record, $recordItems);
    }

    public function createContractWithSignatures(DocumentType $documentType, ContractData $contractData, ?ItemData $contractItems, ContractSignatures $contractSignatures): void
    {
        $id = $this->createContract($documentType, $contractData, $contractItems);
        $this->addContractSignatures($id, $contractSignatures);
    }

    public function updateContractWithSignatures(int $id, DocumentType $documentType, ContractData $contractData, ?ItemData $contractItems, ContractSignatures $contractSignatures): void
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

    public function getStoredContractItems(int $id, DocumentType $documentType): ?ItemData
    {
        $items = $this->repository->getStoredContractItems($id, $documentType);
        return ItemData::fromArray($items?->toArray());
    }

    public function getStoredContract(int $id, DocumentType $documentType, bool $validate = false): ContractData
    {
        $storedContract = $this->repository->getStoredContract($id, $documentType);
        $contractData = ContractData::fromArray($storedContract->toArray());

        if ($validate)
            $this->validateContractData($contractData);

        return $contractData;
    }

    public function getStoredSignatures(int $id): ?ContractSignatures
    {
        return $this->repository->getStoredSignatures($id);
    }

    public function payFullDeposit(DocumentType $documentType, int $id): float
    {
        $storedContract = $this->repository->getStoredContract($id, $documentType);
        $paidDeposit = 0; //FinancialCalculator::calculateDepositAmount($storedContract);

        $this->repository->payDeposit($id, $documentType, $paidDeposit);
        return $paidDeposit;
    }

    /* 
        Record generation
    */

    private function generateInsertRecord(DocumentType $documentType, array $data): InsertableRecord
    {
        return match ($documentType) {
            DocumentType::REGULAR => RegularContractRecord::fromArray($data),
            DocumentType::ON_DEMAND => OnDemandContractRecord::fromArray($data),
            DocumentType::LONG_TERM => LongTermContractRecord::fromArray($data),
            default => throw new Exception("Invalid document type")
        };
    }

    private function generateEditRecord(DocumentType $documentType, array $data): InsertableRecord
    {
        return match ($documentType) {
            DocumentType::REGULAR => RegularContractEditRecord::fromArray($data),
            DocumentType::ON_DEMAND => OnDemandContractEditRecord::fromArray($data),
            DocumentType::LONG_TERM => LongTermContractEditRecord::fromArray($data),
            default => throw new Exception("Invalid document type")
        };
    }

    /* 
        Data validation
    */

    public function validateContractData(ContractData $contractData): void
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
        $contractData->price_per_invoice ??= null;
        $contractData->weather_pending = empty($contractData->weather_pending) ? 0 : $contractData->weather_pending;
        $contractData->start_date = $contractData->start_date === null ? date('Y-m-d') : DateValidator::validateDate($contractData->start_date, 'Y-m-d');
        $contractData->end_date = DateValidator::validateDate($contractData->end_date, 'Y-m-d');
        $contractData->pricing_type ??= 'fixed_total';
        $contractData->price_per_invoice ??= 0;
        $contractData->billing_interval_count ??= null;
        $contractData->billing_interval_unit ??= null;
    }

    public function updateAndValidateContractData(ContractData $contractData): void
    {
        $this->validateContractData($contractData);
        $this->updateContractData($contractData);
    }

    public function updateContractData(ContractData $contractData): void
    {
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
