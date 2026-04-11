<?php

namespace App\controllers;

use App\data_transfer_objects\ListFilterData;
use App\data_transfer_objects\DisplayCountData;

abstract class BaseListController
{
    public function extractFilterData(array $pageData): ListFilterData
    {
        return ListFilterData::fromArray($pageData);
    }

    public function extractDisplayCountData(array $pageData): DisplayCountData
    {
        return DisplayCountData::fromArray($pageData);
    }
}
