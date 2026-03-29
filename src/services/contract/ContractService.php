<?php

namespace App\services\contract;

use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\ContractSignatures;
use App\data_transfer_objects\ItemData;
use App\record_transfer_objects\ContractItemsRecord;
use App\record_transfer_objects\ContractRecord;
use App\repositories\contract\ContractRepository;
use App\services\quotes\FinancialService;

class ContractService
{
    private ContractRepository $repository;

    public function __construct(ContractRepository $repository)
    {
        $this->repository = $repository;
    }

    public function createContract(ContractData $contractData, ItemData $contractItems) : int 
    {
        $this->validateContractData($contractData);
        $this->validateContractItems($contractItems);

        $record = ContractRecord::fromArray($contractData->toArray());
        $recordItems = ContractItemsRecord::fromArray($contractItems->toArray());

        return $this->repository->createContract($record, $recordItems);
    }

    public function createContractWithSignatures(ContractData $contractData, ItemData $contractItems, ContractSignatures $contractSignatures) : void {
        $id = $this->createContract($contractData, $contractItems);
        $this->addContractSignatures($id, $contractSignatures);
    }

    public function addContractSignatures(int $id, ContractSignatures $contractSignatures) : void {
        $this->repository->updateContractSignatures($id, $contractSignatures);
    }

    public function voidContract(int $id) : void {
        $this->repository->voidContract($id);
    }

    public function payDeposit(int $id) : float {
        $storedContract = $this->repository->getStoredContract($id);

        $paidDeposit = FinancialService::calculateDepositValue($storedContract);

        $this->repository->payDeposit($id, $paidDeposit);
        return $paidDeposit;
    }

    public function denyContract(int $id) : void {
        $this->repository->denyContract($id);
    }

    public function completeContract(int $id) : void {
        $this->repository->completeContract($id);
    }

    private function validateContractItems(ItemData $contractItem) : void
    {
        $contractItem->item ??= 0;
        $contractItem->description ??= 0;
        $contractItem->quantity ??= 0;
        $contractItem->price ??= 0;
        $contractItem->line_total ??= 0;
    }

    // quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date
    private function validateContractData(ContractData $contractData) : void
    {
        $contractData->quote_id ??= 0;
        $contractData->client_id ??= 0;
        $contractData->project_id ??= 0;
        $contractData->status ??= 'pending';
        $contractData->discount_type ??= 'none';
        $contractData->discount_value ??= '';
        $contractData->tax_percent ??= 0;
        $contractData->subtotal ??= 0;
        $contractData->total ??= 0;
        $contractData->project_code ??= '';
        $contractData->deposit_type ??= 'none';
        $contractData->deposit_amount ??= 0;
        $contractData->deposit_paid ??= 0;
    }
}
