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
        $postData = $this->resolveDateFields($_POST);

        $quoteData = QuoteData::fromArray($postData);
        $quoteItems = ItemData::fromArray($postData);

        $this->quoteService->createQuote($quoteData, $quoteItems);

        header('Location: /?page=quote/regular-quote-list');
        exit;
    }

    public function edit()
    {
        $id = $_POST['id'] ?? 0;
        $postData = $this->resolveDateFields($_POST);

        $quoteData = QuoteData::fromArray($postData);
        $quoteItems = ItemData::fromArray($postData);

        $this->quoteService->editQuote($id, $quoteData, $quoteItems);

        header('Location: /?page=quote/quote-list');
        exit;
    }

    // The create form has separate start_date/end_date inputs per doc_type (long_term_*,
    // on_demand_*) so the browser never has two same-named fields to choose between.
    private function resolveDateFields(array $postData): array
    {
        $postData['start_date'] = match ($postData['doc_type'] ?? null) {
            'long_term' => $postData['long_term_start_date'] ?? null,
            'on_demand' => $postData['on_demand_start_date'] ?? null,
            default => $postData['start_date'] ?? null
        };

        $postData['end_date'] = match ($postData['doc_type'] ?? null) {
            'long_term' => $postData['long_term_end_date'] ?? null,
            'on_demand' => $postData['on_demand_end_date'] ?? null,
            default => $postData['end_date'] ?? null
        };

        return $postData;
    }
}
