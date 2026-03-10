<?php

namespace App\services\quotes;

use App\repositories\quotes\QuotesCreateRepository;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesCreateService
{
    private QuotesCreateRepository $repository;

    public function __construct(QuotesCreateRepository $repository)
    {
        $this->repository = $repository;
    }

    public function addQuote($pageData)
    {
        $updatedPageData = $this->updatePageData($pageData);

        $this->repository->createQuote($updatedPageData);
    }

    private function updatePageData(array $pageData) : array
    {
        $documentData = $this->getDocumentData($pageData['doc_type']);

        $pageData['items'] = $this->getQuoteItems($pageData);
        $pageData['fulfillment_date'] = $this->verifyFulfillmentData($pageData['fulfillment_date']);
        $pageData['custom_fields'] = json_encode($this->getCustomFields('regular'));
        $pageData['project_code'] = $this->repository->getNextProjectCode($pageData['client_id']);

        $finacialData = $this->getFinancialData($pageData['items'], $pageData['pricing_type'], $pageData['discount_type'], $pageData['discount_value'], $documentData['is_long_term'], $pageData['tax_percent']);

        return array_merge($pageData, $documentData, $finacialData);
    }


    public function getPageData(): array
    {
        $fields = $this->getCustomFields('regular') ?? [];
        $fieldCount = count($fields);
        $columns = min($fieldCount, 3);
        $token = csrf_token();

        return ['csrfToken' => $token, 'fields' => $fields,  'columns' => $columns, 'idSuffix' => ''];
    }

    public function getDocumentData(string $doc_type): array
    {
        $is_long_term = ($doc_type === 'long_term') ? 1 : 0;
        $is_on_demand = ($doc_type === 'on_demand') ? 1 : 0;

        return [
            'is_long_term' => $is_long_term,
            'is_on_demand' => $is_on_demand
        ];
    }

    public function verifyFulfillmentData(string $fulfillment_date): string
    {
        return $fulfillment_date ?: date('Y-m-d', strtotime('+30 days'));
    }

    public function areItemsRequired(string $pricing_type, bool $is_long_term): bool
    {
        return !($is_long_term && ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand'));
    }

    public function getQuoteItems(array $itemData): array
    {
        $items = [];

        $item = $itemData['item'];
        $desc = $itemData['desc'];
        $qty = $itemData['qty'];
        $price = $itemData['price'];

        for ($i = 0; $i < count($item); $i++) {
            $itm = trim((string)($item[$i] ?? ''));
            $d = trim((string)($desc[$i] ?? ''));
            $q = (float)($qty[$i] ?? 0);
            $p = (float)($price[$i] ?? 0);

            if ($itm === '' || $q <= 0 || $p < 0) continue;

            $line = $q * $p;
            $items[] = ['item' => $itm, 'description' => $d, 'quantity' => $q, 'unit_price' => $p, 'line_total' => $line];
        }

        return $items;
    }

    //customFieldsJson
    public function getCustomFields(string $documentType): array
    {
        $value = $this->repository->getCustomFields($documentType) ?? [];
        return $value;
    }

    public function getClientId(): int
    {
        // project_next_code($pdo, $client_id)
        return 1;
    }

    /* Financial Data */

    public function getFinancialData(array $items, string $pricing_type, string $discount_type, float $discount_value, bool $is_long_term, float $tax_percent): array
    {
        $subtotal =  $this->getSubtotal($items, $pricing_type, $is_long_term);
        $discount_amount = $this->getDiscountAmount($discount_type, $discount_value, $subtotal);
        $tax_amount = $this->getTaxAmount($subtotal, $discount_amount, $tax_percent);

        return [
            'discount_amount' => $discount_amount,
            'total' => $this->getTotal($subtotal, $discount_amount, $tax_amount),
            'taxAmount' => $tax_amount,
            'subtotal' => $subtotal
        ];
    }

    public function getDiscountAmount(string $discount_type, float $discount_value, float $subtotal): float
    {
        $discount_amount = 0.0;

        if ($discount_type === 'percent') {
            $discount_amount = max(0.0, min(100.0, $discount_value)) * $subtotal / 100.0;
        } elseif ($discount_type === 'fixed') {
            $discount_amount = max(0.0, $discount_value);
        }

        return $discount_amount;
    }

    public function getTotal(float $subtotal, float $discount_amount, float $tax_amount): float
    {
        return max(0.0, $subtotal - $discount_amount + $tax_amount);
    }

    private function getTaxAmount(float $subtotal, float $discount_amount, float $tax_percent): float
    {
        return max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_amount) / 100.0;
    }

    public function getSubtotal(array $items, string $pricing_type, bool $is_long_term): float
    {
        $subtotal = 0;

        if (!$this->areItemsRequired($pricing_type, $is_long_term)) {
            foreach ($items as $item) {
                $linePrice = $item['line'] ?? 0;
                $subtotal += $linePrice;
            }
        }

        return $subtotal;
    }
}
// header('Location: /?page=quote/quotes-create&error=Please%20select%20a%20client');
// header('Location: /?page=quote/quotes-create&error=Add%20at%20least%20one%20item');
// header('Location: /?page=quote/quotes-create&error=Failed%20to%20create%20quote');
// header('Location: /?page=quote/quotes-list&created=1');