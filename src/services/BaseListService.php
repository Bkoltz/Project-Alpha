<?php

namespace App\services;

use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterData;
use App\data_transfer_objects\PageButtonData;

abstract class BaseListService
{
    public abstract function getDisplayFilterConfig(array $filterData): array;

    public function getPageButtonData(int $displayItemsCount, DisplayCountData $countData, ListFilterData $filteredData): PageButtonData
    {
        return new PageButtonData([
            'per_page' => $this->getPagePath($countData, $filteredData, 1),
            'page' => $this->getPagePath($countData, $filteredData, -1),
            'offset' => $this->getLastPageNumber($displayItemsCount, $countData),
        ]);
    }

    public function updateCountData(DisplayCountData $displayCountData): void
    {
        $amountPerPage = (int)($displayCountData->per_page ?? 50);

        if (!in_array($amountPerPage, [50, 100], true))
            $amountPerPage = 50;

        $currentPageNumber = max(1, (int)($displayCountData->page ?? 1));
        $offset = ($currentPageNumber - 1) * $amountPerPage;

        $displayCountData->per_page = $amountPerPage;
        $displayCountData->page = $currentPageNumber;
        $displayCountData->offset = $offset;
    }

    public function updateFilterData(ListFilterData $ListFilterData): void
    {
        $ListFilterData->client_id = $ListFilterData->client_id !== null ? (int)$ListFilterData->client_id : 0;
        $ListFilterData->client_name = trim($ListFilterData->client_name ?? '');
        $ListFilterData->start = $ListFilterData->start ?? '';
        $ListFilterData->end = $ListFilterData->end ?? '';
        $ListFilterData->status = $ListFilterData->status ?? 'all';
        $ListFilterData->project_code = trim($ListFilterData->project_code ?? '');
        $ListFilterData->doc_number = $ListFilterData->doc_number !== null ? (int)$ListFilterData->doc_number : 0;

        $ListFilterData->min_price = is_numeric($ListFilterData->min_price) ? (float)$ListFilterData->min_price : null;
        $ListFilterData->max_price = is_numeric($ListFilterData->max_price) ? (float)$ListFilterData->max_price : null;
    }

    private function getLastPageNumber(int $displayItemsCount, DisplayCountData $displayCountData): int
    {
        return ceil(max(1, $displayItemsCount) / $displayCountData->per_page);
    }

    private function getPagePath(DisplayCountData $countData, ListFilterData $filteredData, int $amount): string
    {
        $path = '/?' . http_build_query($filteredData->toArray() + ['page' => 'quote/quote-list', 'per_page' => $countData->per_page]);
        $path .= '&p=' . $countData->page += $amount;

        return $path;
    }
}
