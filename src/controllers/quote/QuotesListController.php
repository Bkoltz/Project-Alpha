<?php

namespace App\controllers\quote;

use App\services\quotes\QuotesListService;

class QuotesListController
{
    private QuotesListService $service;

    public function __construct(QuotesListService $service)
    {
        $this->service = $service;
    }

    private function load(string $documentType = 'regular') : array {
        $filterData = $this->extractFilterData();
        $countData = $this->extractDisplayCountData();

        $output = $this->service->getRenderData($filterData, $countData, $documentType);

        return ['pages/quote/quote-general-list.twig', $output];
    }

    public function loadLongTerm(): array
    {
        return $this->load('longTerm');
    }

    public function loadRegular(): array
    {
        return $this->load('regular');
    }

    public function loadOnDemand(): array
    {
        return $this->load('onDemand');
    }

    private function extractFilterData(): array
    {
        return [
            'clientId' => isset($_GET['client_id']) ? $_GET['client_id'] : null,
            'clientName' =>  isset($_GET['client']) ? $_GET['client'] : null,
            'start' =>  isset($_GET['start']) ? $_GET['start'] : null,
            'end' =>  isset($_GET['end']) ? $_GET['end'] : null,
            'status' =>  isset($_GET['status']) ? $_GET['status'] : null,
            'projectCode' => isset($_GET['project_code']) ? $_GET['project_code'] : null,
            'docNo' =>  isset($_GET['doc_number']) ? $_GET['doc_number'] : null,
            'minPrice' =>  isset($_GET['min_price']) ? $_GET['min_price'] : null,
            'maxPrice' =>  isset($_GET['max_price']) ? $_GET['max_price'] : null,
        ];
    }

    private function extractDisplayCountData(): array
    {
        return [
            'perPage' => isset($_GET['per_page']) ? $_GET['per_page'] : null,
            'page' => isset($_GET['page']) ? $_GET['page'] : null,
        ];
    }
}
