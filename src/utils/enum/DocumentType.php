<?php

namespace App\utils\enum;

enum DocumentType: string
{
    case REGULAR = 'regular';
    case ON_DEMAND = 'on_demand';
    case LONG_TERM = 'long_term';
};
