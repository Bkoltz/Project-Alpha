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
        $filters = $filterConfig->document_type_filters ?? [];

        if (isset($filters[$documentType->value]))
            $where[] = $filters[$documentType->value];

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

        $where = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        return new ListFilterStatement([
            'sql' => $where,
            'values' => $values
        ]);
    }
}
