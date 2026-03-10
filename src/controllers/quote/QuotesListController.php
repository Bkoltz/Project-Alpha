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

    public function load() : array {
        $filterData = $this->extractFilterData();
        $countData = $this->extractDisplayCountData();

        $output = $this->service->getDisplayData($filterData, $countData);

        return ['pages/quote/quotes-list.twig', $output];
    }

    private function extractFilterData(): array
    {
        return [
            'clientId' => $_GET['client_id'],
            'clientName' => $_GET['client'],
            'start' => $_GET['start'],
            'end' => $_GET['end'],
            'status' => $_GET['status'],
            'projectCode' => $_GET['project_code'],
            'docNo' => $_GET['doc_number'],
            'minPrice' => $_GET['min_price'],
            'maxPrice' => $_GET['max_price'],
        ];
    }

    private function extractDisplayCountData(): array
    {
        return [
            'perPage' => $_GET['per_page'],
            'page' => $_GET['page'],
        ];
    }
}