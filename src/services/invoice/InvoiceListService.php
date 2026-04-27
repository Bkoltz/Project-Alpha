<?php

namespace App\services\invoice;

use App\render_outputs\invoice\InvoiceListView;
use App\utils\enum\DocumentType;
use App\data_transfer_objects\ListFilterData;
use App\data_transfer_objects\DisplayCountData;
use App\render_outputs\PageNumberView;
use App\repositories\invoice\InvoiceListRepository;
use App\services\BaseListService;

class InvoiceListService extends BaseListService
{
    private InvoiceListRepository $repository;

    private const TITLE = [
        DocumentType::REGULAR->value => 'Invoices',
        DocumentType::LONG_TERM->value => 'Recurring Invoices',
        DocumentType::ON_DEMAND->value => 'On-Demand Invoices'
    ];

    public function __construct(InvoiceListRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getRenderData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData): InvoiceListView
    {
        $this->updateFilterData($filterData);
        $this->updateCountData($displayCountData);

        $displayFilterConfig = $this->getDisplayFilterConfig($filterData->toArray());
        $displayData = $this->getDisplayData($documentType, $filterData, $displayCountData);
        $rows = $this->repository->getInvoiceRows($documentType, $filterData, $displayCountData);

        return new InvoiceListView(array_merge([
            'rows' => $rows,
            'page_number_view' => $displayData,
            'title' => $this::TITLE[$documentType->value],
            'document_type' => $documentType->value,
            'filter_config' => $displayFilterConfig
        ], 
        $displayCountData->toArray(), 
        $filterData->toArray()));
    }

    private function getDisplayData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData): PageNumberView
    {
        $displayCount = $this->repository->getInvoiceCount($documentType, $filterData);
        return new PageNumberView(array_merge($displayCountData->toArray(), ['display_count' => $displayCount]));
    }

    public function getDisplayFilterConfig(array $filterData): array
    {
        return $filterConfig = [
            'page' => 'invoice/invoices-list',
            'filters' => [
                'client' => [
                    'type' => 'client_autocomplete',
                    'label' => 'Client',
                    'value' => $filterData['client_name'],
                    'id_value' => $filterData['client_id']
                ],
                'status' => [
                    'type' => 'select',
                    'label' => 'Status',
                    'value' => $filterData['statusFilter'],
                    'options' => [
                        ['value' => 'all', 'label' => 'All'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'unpaid', 'label' => 'Unpaid/Partial'],
                        ['value' => 'overdue', 'label' => 'Overdue']
                    ]
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
                    'value' => $filterData['min_price'] ?? '',
                    'step' => '0.01'
                ],
                'max_price' => [
                    'type' => 'number',
                    'label' => 'Max ($)',
                    'value' => $filterData['max_price'] ?? '',
                    'step' => '0.01'
                ],
                'project_code' => [
                    'type' => 'text',
                    'label' => 'Project',
                    'value' => $filterData['project_code'],
                    'placeholder' => 'PA-2025'
                ],
                'doc_number' => [
                    'type' => 'number',
                    'label' => 'Doc #',
                    'value' => $filterData['doc_no']
                ]
            ]
        ];
    }
}

