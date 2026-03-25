<?php

namespace App\services\contract;

use App\data_transfer_objects\ContractData;
use App\repositories\contract\ContractRepository;

class ContractService {
    private ContractRepository $repository;

    public function __construct(ContractRepository $repository)
    {
        $this->repository = $repository;
    }

    public function createContract(ContractData $contractData) {
        $sortedData = $this->sortContractData($contractData);
        $sortedItemData = $this->sortContractItems($contractData);

        $this->repository->createContract($sortedData, $sortedItemData);
    }

    private function sortContractItems(ContractData $contractData) : array {
        $contractData = $contractData->toArray()['items'];
        
        return [
            $contractData['item'],
            $contractData['desc'],
            $contractData['qty'],
            $contractData['price']
        ];
    }

    // quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date
    private function sortContractData(ContractData $contractData): array
    {
        $contractData = $contractData->toArray();
        
        return [
            $contractData['quote_id'],
            $contractData['client_id'],
            $contractData['project_id'],
            'pending',
            $contractData['discount_type'],
            $contractData['discount_value'],
            $contractData['tax_percent'],
            $contractData['subtotal'],
            $contractData['total'],
            $contractData['project_code'],
            $contractData['deposit_type'],
            $contractData['deposit_amount'] ?? 0,
            $contractData['deposit_paid'] ?? 0,
            $contractData['fulfillment_date'],
        ];
    }
}