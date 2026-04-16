<?php

namespace App\controllers\quote;

use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\QuoteData;
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

    public function create()
    {
        $quoteData = $this->extractCreateData();
        $quoteData = QuoteData::fromArray($quoteData);

        $quoteItems = $this->extractItemData();
        $quoteItems = ItemData::fromArray($quoteItems);

        $this->quoteService->createQuote($quoteData, $quoteItems);

        //Redirect to list
        header('Location: /?page=quote/regular-quote-list');
        exit;
    }

    public function load(): array
    {
        if (key_exists('id', $_GET)) {
            $id = $_GET['id'];
            $output = $this->dataService->getEditRenderData($id);

            return ['pages/quote/quote-edit.twig', $output];
        } else {
            $output = $this->dataService->getCreateRenderData();
            
            return ['pages/quote/quote-create.twig', $output];
        }
    }

    public function edit()
    {
        $id = $_POST['id'] ?? 0;

        $quoteData = $this->extractEditData();
        $quoteData = QuoteData::fromArray($quoteData);

        $this->quoteService->editQuote($id, $quoteData);

        header('Location: /?page=quote/quote-list');
        exit;
    }

    private function extractEditData(): array
    {
        return array_merge(
            $this->extractItemData(),
            $this->extractDiscountData(),
            $this->extractMetaData(),
            $this->extractTaxData(),
            $this->extractDocumentData(),
            $this->extractTimelineData()
        );
    }

    private function extractCreateData(): array
    {
        return array_merge(
            $this->extractBillingData(),
            $this->extractDepositData(),
            $this->extractItemData(),
            $this->extractDocumentData(),
            $this->extractTimelineData(),
            $this->extractTaxData(),
            $this->extractMetaData(),
            $this->extractDiscountData()
        );
    }

    private function extractStoredData(): array
    {
        return isset($_POST['quote']) ? $_POST['quote'] : [];
    }

    private function extractTaxData(): array
    {
        return  [
            'tax_percent' => (float)($_POST['tax_percent'] ?? 0),
        ];
    }

    private function extractDocumentData(): array
    {
        return [
            'client_id' => (int)($_POST['client_id'] ?? 0),
            'doc_type' => $_POST['doc_type'] ?? 'regular',
            'project_id' =>  (int)$_POST['project_id'] ?: null
        ];
    }

    private function extractBillingData(): array
    {
        $pricing_type = in_array(($_POST['lt_pricing_type'] ?? 'per_invoice'), ['per_invoice', 'fixed_total', 'on_demand']) ? $_POST['lt_pricing_type'] : 'per_invoice';

        return [
            'billing_interval_count' => (int)($_POST['lt_billing_interval_count'] ?? 1),
            'billing_interval_unit' => in_array(($_POST['lt_billing_interval_unit'] ?? 'month'), ['day', 'week', 'month', 'year']) ? $_POST['lt_billing_interval_unit'] : 'month',
            'pricing_type' => $pricing_type,
            'price_per_invoice' => ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand') ? (float)($_POST['lt_price_per_invoice'] ?? 0) : null
        ];
    }

    private function extractTimelineData(): array
    {
        $end_date_type = $_POST['lt_end_date_type'] ?? 'ongoing';

        return [
            'fulfillment_date' => $_POST['fulfillment_date'] ?? $_POST['custom_field_fulfillment_date'] ?? null,
            'start_date' => isset($_POST['lt_start_date']) ? $_POST['lt_start_date'] : null,
            'end_date' => ($end_date_type === 'fixed' && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null
        ];
    }

    private function extractDepositData(): array
    {
        $deposit_type = $_POST['deposit_type'] ?? $_POST['custom_field_deposit_type'] ?? 'none';

        return [
            'deposit_type' => in_array($deposit_type, ['None', 'Percent', 'Fixed']) ? $deposit_type : 'none',
            'deposit_value' => (float)($_POST['deposit_value'] ?? $_POST['custom_field_deposit_value'] ?? 0)
        ];
    }

    private function extractDiscountData(): array
    {
        return [
            'discount_type' => in_array(($_POST['discount_type'] ?? 'none'), ['none', 'percent', 'fixed']) ? $_POST['discount_type'] : 'none',
            'discount_value' => (float)($_POST['discount_value'] ?? 0)
        ];
    }

    private function extractItemData(): array
    {
        return [
            'item' => $_POST['item'] ?? [],
            'description' => $_POST['item_desc'] ?? [],
            'quantity' => $_POST['item_qty'] ?? [],
            'unit_price' => $_POST['item_price'] ?? [],
            'line_total' => []
        ];
    }

    private function extractMetaData(): array
    {
        return [
            'scope' => trim((string)($_POST['scope'] ?? '')),
            'notes' => trim((string)($_POST['project_notes'] ?? '')),
            'terms' => trim((string)($_POST['project_terms'] ?? ''))
        ];
    }
}
