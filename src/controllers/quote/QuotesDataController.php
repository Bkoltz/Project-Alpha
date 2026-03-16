<?php

namespace App\controllers\quote;

use App\Config\Renderer;
use App\services\quotes\QuotesDataService;

class QuotesDataController
{
    private QuotesDataService $service;

    public function __construct(QuotesDataService $service)
    {
        $this->service = $service;
    }

    public function create()
    {
        $quoteData = $this->extractCreateData();
        $this->service->addQuote($quoteData);

        //Redirect to list
        header('Location: /?page=quote/regular-quote-list');
        exit;
    }

    public function load(): array
    {
        if (key_exists('id', $_GET)) {
            $id = $_GET['id'];
            $output = $this->service->getEditRenderData($id);

            return ['pages/quote/quote-edit.twig', $output];
        } else {
            $output = $this->service->getCreateRenderData();

            return ['pages/quote/quote-create.twig', $output];
        }
    }

    public function edit()
    {
        $id = $_POST['id'] ?? 0;

        $quoteData = $this->extractEditData();
        
        $success = $this->service->editQuote($quoteData, $id);

        if ($success === false) {
            header('Location: /?page=quote/quote-edit&id=' . $id . '&error=1');
            return;
        }

        header('Location: /?page=quote/quote-list');
        exit;
    }

    private function extractEditData(): array {
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

    private function extractStoredData() : array {
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
            'desc' => $_POST['item_desc'] ?? [],
            'qty' => $_POST['item_qty'] ?? [],
            'price' => $_POST['item_price'] ?? []
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
