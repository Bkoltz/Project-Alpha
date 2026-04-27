<?php

namespace App\services\quotes;

use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterData;
use App\render_outputs\PageNumberView;
use App\repositories\quotes\QuotesListRepository;
use App\render_outputs\quote\QuoteListView;
use App\services\BaseListService;
use App\utils\enum\DocumentType;

class QuotesListService extends BaseListService
{
    private QuotesListRepository $repository;

    private const PAGE_TITLES = [
        DocumentType::REGULAR->value  => 'Quotes',
        DocumentType::LONG_TERM->value => 'Long-Term Quotes',
        DocumentType::ON_DEMAND->value => 'On-Demand Quotes',
    ];

    public function __construct(QuotesListRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getRenderData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData): QuoteListView
    {
        $this->updateFilterData($filterData);
        $this->updateCountData($displayCountData);

        $displayFilterConfig = $this->getDisplayFilterConfig($filterData->toArray());
        $displayData = $this->getDisplayData($documentType, $filterData, $displayCountData);
        $rows = $this->repository->getQuoteRows($documentType, $filterData, $displayCountData);
        $this->updateRowStyle($rows);

        return new QuoteListView(array_merge(
            [
                'rows' => $rows,
                'page_number_view' => $displayData,
                'title' => $this::PAGE_TITLES[$documentType->value],
                'document_type' => $documentType->value,
                'filter_config' => $displayFilterConfig
            ],
            $displayCountData->toArray(),
            $filterData->toArray()
        ));
    }

    private function getDisplayData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData): PageNumberView
    {
        $displayCount = $this->repository->getQuoteCount($documentType, $filterData);
        return new PageNumberView(array_merge($displayCountData->toArray(), ['display_count' => $displayCount]));
    }

    public function getDisplayFilterConfig(array $filterData): array
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
                    'value' => $filterData['clientName'],
                    'id_value' => $filterData['clientId']
                ],
                'status' => [
                    'type' => 'select',
                    'label' => 'Status',
                    'value' => $filterData['status'],
                    'options' => $statusOptions
                ],
                'start' => [
                    'type' => 'date',
                    'label' => 'Start',
                    'value' => $filterData['start']
                ],
                'end' => [
                    'type' => 'date',
                    'label' => 'End',
                    'value' => $filterData['end']
                ],
                'min_price' => [
                    'type' => 'number',
                    'label' => 'Min ($)',
                    'value' => $filterData['minPrice'],
                    'step' => '0.01'
                ],
                'max_price' => [
                    'type' => 'number',
                    'label' => 'Max ($)',
                    'value' => $filterData['maxPrice'],
                    'step' => '0.01'
                ],
                'project_code' => [
                    'type' => 'text',
                    'label' => 'Project ID',
                    'value' => $filterData['projectCode'],
                    'placeholder' => 'PA-2025'
                ],
                'doc_number' => [
                    'type' => 'number',
                    'label' => 'Doc #',
                    'value' => $filterData['docNo']
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
