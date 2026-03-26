<?php

namespace App\controllers\contract;

use App\services\contract\ContractListService;
use App\services\contract\ContractService;

class ContractListController {
    private ContractListService $service;
    private ContractService $contractService;

    public function __construct(ContractListService $service, ContractService $contractService) {
        $this->service = $service;
        $this->contractService = $contractService;
    }

    public function load() {
        
    }

    public function pause() {
        
    }

    public function resume() {
        
    }

    public function activate() {
        
    }

    public function terminate() {
        
    }

    public function complete() : void {
        $id = $_GET['id'] ?? 0;
        $this->contractService->completeContract($id);
    }

    public function deny() : void {
        $id = $_GET['id'] ?? 0;
        $this->contractService->denyContract($id);
    }


}