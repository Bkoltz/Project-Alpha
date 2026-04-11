<?php

namespace App\services\contract;

use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterData;
use App\data_transfer_objects\render_outputs\Contact\ContractListView;
use App\data_transfer_objects\render_outputs\PageNumberView;
use App\repositories\contract\ContractListRepository;
use App\services\BaseListService;
use App\utils\enum\DocumentType;

class ContractListService extends BaseListService
{
    private ContractListRepository $repository;

    private const TITLE = [
        DocumentType::REGULAR->value => 'Contracts',
        DocumentType::LONG_TERM->value => 'Long-Term Contracts',
        DocumentType::ON_DEMAND->value => 'On-Demand Contracts'
    ];

    public function __construct(ContractListRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getRenderData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData): ContractListView
    {
        $this->updateFilterData($filterData);
        $this->updateCountData($displayCountData);

        $displayFilterConfig = $this->getDisplayFilterConfig($filterData->toArray());
        $displayData = $this->getDisplayData($documentType, $filterData, $displayCountData);
        $rows = $this->repository->getContractRows($documentType, $filterData, $displayCountData);

        $output = new ContractListView(array_merge([
            'rows' => $rows,
            'page_number_view' => $displayData,
            'title' => $this::TITLE[$documentType->value],
            'document_type' => $documentType->value,
            'filter_config' => $displayFilterConfig
        ], $displayCountData->toArray(), $filterData->toArray()));

        return $output;
    }

    private function getDisplayData(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $displayCountData) : PageNumberView {
    
        $displayCount = $this->repository->getContractCount($documentType, $filterData);
        return new PageNumberView(array_merge($displayCountData->toArray(), ['display_count' => $displayCount]));
    }

    public function getDisplayFilterConfig(array $filterData): array
    {
        return [
            'page' => 'contract/contracts-list',
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
                    'value' => $filterData['status'],
                    'options' => [
                        ['value' => '', 'label' => 'All'],
                        ['value' => 'pending', 'label' => 'Pending'],
                        ['value' => 'active', 'label' => 'Active'],
                        ['value' => 'completed', 'label' => 'Completed'],
                        ['value' => 'cancelled', 'label' => 'Cancelled'],
                        ['value' => 'void', 'label' => 'Void']
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
                    'value' => $min_price ?? '',
                    'step' => '0.01'
                ],
                'max_price' => [
                    'type' => 'number',
                    'label' => 'Max ($)',
                    'value' => $max_price ?? '',
                    'step' => '0.01'
                ],
                'project_code' => [
                    'type' => 'text',
                    'label' => 'Project ID',
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
