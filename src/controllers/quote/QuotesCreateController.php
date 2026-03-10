<?php

namespace App\controllers\quote;

use App\Config\Renderer;
use App\services\quotes\QuotesCreateService;

class QuotesCreateController
{
    private QuotesCreateService $service;

    public function __construct(QuotesCreateService $service)
    {
        $this->service = $service;
    }

    public function create()
    {
        $quoteData = $this->getPageData();
        $this->service->addQuote($quoteData);

        //Redirect to list
        header('Location: /?page=quote/quotes-list');
        exit;
    }

    public function load() : array
    {
        $output = $this->service->getPageData();
        return ['pages/quote/quotes-create.twig', $output];
    }

    private function getPageData()
    {
        $pricing_type = in_array(($_POST['lt_pricing_type'] ?? 'per_invoice'), ['per_invoice', 'fixed_total', 'on_demand']) ? $_POST['lt_pricing_type'] : 'per_invoice';
        $end_date_type = $_POST['lt_end_date_type'] ?? 'ongoing';

        $itemData = $this->extractItemData();
        $discountData = $this->extractDiscountData();
        $depositData = $this->extractDepositData();

        return array_merge(
            $itemData,
            $discountData,
            $depositData,
            [
                'client_id' => (int)($_POST['client_id'] ?? 0),
                'doc_type' => $_POST['doc_type'] ?? 'regular',
                'project_id' =>  (int)$_POST['project_id'] ?: null,
                'tax_percent' => (float)($_POST['tax_percent'] ?? 0),
                'fulfillment_date' => $_POST['fulfillment_date'] ?? $_POST['custom_field_fulfillment_date'] ?? null,
                'start_date' => $_POST['lt_start_date'] ?: null,
                'end_date' => ($end_date_type === 'fixed' && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null,
                'billing_interval_count' => (int)($_POST['lt_billing_interval_count'] ?? 1),
                'billing_interval_unit' => in_array(($_POST['lt_billing_interval_unit'] ?? 'month'), ['day', 'week', 'month', 'year']) ? $_POST['lt_billing_interval_unit'] : 'month',
                'pricing_type' => $pricing_type,
                'price_per_invoice' => ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand') ? (float)($_POST['lt_price_per_invoice'] ?? 0) : null,
                'scope' => trim((string)($_POST['scope'] ?? '')),
                'notes' => trim((string)($_POST['project_notes'] ?? ''))
            ]
        );
    }

    private function extractDepositData(): array
    {
        $deposit_type = $_POST['deposit_type'] ?? $_POST['custom_field_deposit_type'] ?? 'none';

        return [
            'deposit_type' => in_array($deposit_type, ['None', 'Percent', 'Fixed']) ? $deposit_type : 'none',
            'deposit_value' => (float)($_POST['deposit_value'] ?? $_POST['custom_field_deposit_value'] ?? 0)
        ];
    }

    private function extractDiscountData()
    {
        return [
            'discount_type' => in_array(($_POST['discount_type'] ?? 'none'), ['none', 'percent', 'fixed']) ? $_POST['discount_type'] : 'none',
            'discount_value' => (float)($_POST['discount_value'] ?? 0)
        ];
    }

    private function extractItemData()
    {
        return [
            'item' => $_POST['item'] ?? [],
            'desc' => $_POST['item_desc'] ?? [],
            'qty' => $_POST['item_qty'] ?? [],
            'price' => $_POST['item_price'] ?? []
        ];
    }
}
