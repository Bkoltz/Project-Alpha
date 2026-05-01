<?php

namespace App\controllers\quote;

use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\quote\QuoteData;
use App\services\quotes\QuotesDataService;
use App\services\quotes\QuoteService;

class QuotesDataController
{
    private QuotesDataService $dataService;
    private QuoteService $quoteService;

    public function __construct(QuoteService $quoteService, QuotesDataService $dataService)
    {
        $this->quoteService = $quoteService;
        $this->dataService = $dataService;
    }

    public function load(): array
    {
        if (key_exists('id', $_GET)) {
            $id = $_GET['id'];
            $output = $this->dataService->getEditRenderData($id);

            return ['pages/quote/quote-edit.twig', $output->toArray()];
        } else {
            $output = $this->dataService->getCreateRenderData();
            
            return ['pages/quote/quote-create.twig', $output->toArray()];
        }
    }

    public function create()
    {
        $quoteData = QuoteData::fromArray($_POST);
        $quoteItems = ItemData::fromArray($_POST);

        $this->quoteService->createQuote($quoteData, $quoteItems);

        header('Location: /?page=quote/regular-quote-list');
        exit;
    }

    public function edit()
    {
        $id = $_POST['id'] ?? 0;
        $quoteData = QuoteData::fromArray($_POST);
        $quoteItems = ItemData::fromArray($_POST);

        $this->quoteService->editQuote($id, $quoteData, $quoteItems);

        header('Location: /?page=quote/quote-list');
        exit;
    }
}
