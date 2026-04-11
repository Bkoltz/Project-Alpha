<?php

namespace App\repositories;

use App\utils\enum\DocumentType;
use App\data_transfer_objects\ListFilterData;
use App\data_transfer_objects\ListFilterConfig;
use App\data_transfer_objects\ListFilterStatement;

abstract class BaseListRepository
{
    public function createFilteredStatement(DocumentType $documentType, ListFilterData $filterData, ListFilterConfig $filterConfig): ListFilterStatement
    {
        $where[] = $filterConfig->documentTypeFilters[$documentType];
        $values = [];

        foreach ($filterData->toArray() as $key => $value) {
            if (!isset($filterConfig->filters[$key]))
                continue;

            $filter = $filterConfig->filters[$key];

            if ($value === $filter['ignore'] || empty($value))
                continue;

            $where[] = $filter['sql'];
            $values[] = $value;
        }

        $where = ' WHERE ' . implode(' AND ', $where);
        
        return new ListFilterStatement($where, $values);
    }
}
