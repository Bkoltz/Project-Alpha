<?php

namespace App\services\quotes;

use App\repositories\quotes\QuotesListRepository;

class QuotesListService
{
    private QuotesListRepository $repository;

    private const PAGE_TITLES = [
        'regular'  => 'Quotes',
        'longTerm' => 'Long-Term Quotes',
        'onDemand' => 'On-Demand Quotes',
    ];

    public function __construct(QuotesListRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getRenderData(array $rawFilterData, array $rawCountData, string $documentType): array
    {
        $updatedCountData = $this->updateCountData($rawCountData);
        $updatedFilterData = $this->updateFilterData($rawFilterData);

        $displayData = $this->getDisplayData($documentType, $updatedFilterData, $updatedCountData);
        $pageButtonData = $this->getPageButtonData($displayData['quotesCount'], $updatedCountData, $updatedFilterData);
        $filterConfig = $this->generateFilterConfig($updatedFilterData);


        return array_merge($displayData, $updatedCountData, $pageButtonData, ['filterConfig' => $filterConfig, 'documentType' => $documentType]);
    }

    private function getDisplayData(string $documentType, array $updatedFilterData, array $updatedCountData): array
    {
        $displayData = $this->repository->getDisplayData($documentType, $updatedFilterData, $updatedCountData);

        $displayData['rows'] = $this->updateRowStyle($displayData['rows']);
        $displayData['title'] = $this::PAGE_TITLES[$documentType] ?? 'Quotes';

        return $displayData;
    }

    private function getPageButtonData(int $quotesCount, array $countData, array $filteredData): array
    {
        return [
            'nextPagePath' => $this->getPagePath($countData, $filteredData, 1),
            'previousPagePath' => $this->getPagePath($countData, $filteredData, -1),
            'pageCount' => $this->getLastPageNumber($quotesCount, $countData['perPage']),
        ];
    }

    private function updateFilterData(array $rawFilterData): array
    {
        $client_id = (int)$rawFilterData['clientId'] ?: 0;
        $client_name = trim($rawFilterData['clientName'] ?? '');
        $start = $rawFilterData['start'] ?? '';
        $end = $rawFilterData['end'] ?? '';
        $status = $rawFilterData['status'] ?? 'all';
        $project_code = trim($rawFilterData['projectCode'] ?? '');
        $doc_no = (int)$rawFilterData['docNo'] ?: 0;
        $min_price =  (float)$rawFilterData['minPrice'] ?: null;
        $max_price =  (float)$rawFilterData['maxPrice'] ?: null;

        return [
            'clientId' => $client_id,
            'clientName' => $client_name,
            'start' => $start,
            'end'  => $end,
            'status' => $status,
            'projectCode' => $project_code,
            'docNo'  => $doc_no,
            'minPrice' => $min_price,
            'maxPrice' => $max_price,
        ];
    }

    private function updateCountData(array $rawCountData): array
    {
        $amountPerPage = (int)($rawCountData['perPage'] ?? 50);

        if (!in_array($amountPerPage, [50, 100], true))
            $amountPerPage = 50;

        $currentPageNumber = max(1, (int)($rawCountData['page'] ?? 1));
        $offset = ($currentPageNumber - 1) * $amountPerPage;

        return [
            'perPage' => $amountPerPage,
            'page' => $currentPageNumber,
            'offset' => $offset
        ];
    }

    private function getLastPageNumber(int $quotesCount, int $perPage): int
    {
        return ceil(max(1, $quotesCount) / $perPage);
    }

    //Expects filtered data
    //Generates the link to move $amount pages from the current page
    private function getPagePath(array $countData, array $filteredData, int $amount): string
    {
        $path = '/?' . http_build_query($filteredData + ['page' => 'quote/quote-list', 'per_page' => $countData['perPage']]);
        $path .= '&p=' . $countData['page'] += $amount;

        return $path;
    }

    //Expects filtered data
    private function generateFilterConfig(array $filteredData): array
    {
        $statusOptions = [
            ['value' => 'all', 'label' => 'All'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'rejected', 'label' => 'Denied'],
            ['value' => 'pending', 'label' => 'Pending']
        ];

        return [
            'page' => 'quote/quote-list',
            'filters' => [
                'client' => [
                    'type' => 'client_autocomplete',
                    'label' => 'Client',
                    'value' => $filteredData['clientName'],
                    'id_value' => $filteredData['clientId']
                ],
                'status' => [
                    'type' => 'select',
                    'label' => 'Status',
                    'value' => $filteredData['status'],
                    'options' => $statusOptions
                ],
                'start' => [
                    'type' => 'date',
                    'label' => 'Start',
                    'value' => $filteredData['start']
                ],
                'end' => [
                    'type' => 'date',
                    'label' => 'End',
                    'value' => $filteredData['end']
                ],
                'min_price' => [
                    'type' => 'number',
                    'label' => 'Min ($)',
                    'value' => $filteredData['minPrice'],
                    'step' => '0.01'
                ],
                'max_price' => [
                    'type' => 'number',
                    'label' => 'Max ($)',
                    'value' => $filteredData['maxPrice'],
                    'step' => '0.01'
                ],
                'project_code' => [
                    'type' => 'text',
                    'label' => 'Project ID',
                    'value' => $filteredData['projectCode'],
                    'placeholder' => 'PA-2025'
                ],
                'doc_number' => [
                    'type' => 'number',
                    'label' => 'Doc #',
                    'value' => $filteredData['docNo']
                ]
            ]
        ];
    }

    private function updateRowStyle(array $rows): array
    {
        foreach ($rows as &$row) {
            switch ($row['status']) {
                case 'approved':
                    $row['style'] = 'background:#ecfdf5;';
                    break;
                case 'pending':
                    $row['style'] = 'background:#fffbeb;';
                    break;
                case 'rejected':
                    $row['style'] = 'background:#fef2f2;';
                    break;
                default:
                    break;
            }
        }

        return $rows;
    }
}
