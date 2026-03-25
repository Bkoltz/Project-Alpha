<?php

namespace App\controllers\contract;

use App\services\contract\ContractListService;

class ContractListController {
    private ContractListService $service;

    public function __construct(ContractListService $service) {
        $this->service = $service;
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
}