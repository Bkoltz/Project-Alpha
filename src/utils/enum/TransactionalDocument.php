<?php

namespace App\utils\enum;

enum TransactionalDocumentType: string
{
    case QUOTE = 'quote';
    case CONTRACT = 'contract';
    case INVOICE = 'invoice';
};