<?php

namespace App\services\quotes;

use App\repositories\quotes\QuotesDataRepository;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesDataService
{
    private QuotesDataRepository $repository;

    public function __construct(QuotesDataRepository $repository)
    {
        $this->repository = $repository;
    }

    public function addQuote(array $pageData)
    {
        $updatedPageData = $this->verifyCreateData($pageData);
        
        if ($updatedPageData === false)
            return false;

        $this->repository->createQuote($updatedPageData);
    }

    public function editQuote(array $pageData, int $id)
    {
        $updatedPageData = $this->verifyEditData($pageData, $id);

        if ($updatedPageData === false)
            return false;
        
        $this->repository->editQuote($updatedPageData, $id);
    }

    public function getCreateRenderData(): array
    {
        $fields = $this->getCustomFields('regular') ?? [];
        $fieldCount = count($fields);
        $columns = min($fieldCount, 3);
        $token = csrf_token();

        return ['csrfToken' => $token, 'fields' => $fields,  'columns' => $columns, 'idSuffix' => ''];
    }

    public function getEditRenderData(int $id): array
    {
        $defaultRenderData = $this->getCreateRenderData();
        $quoteItems = $this->getStoredQuoteItems($id);
        $quoteData = $this->getStoredQuote($id);
        $quoteMeta = $this->getStoredQuoteMeta($quoteData['project_code']);

        return array_merge($defaultRenderData, $quoteMeta, ['quote' => $quoteData, 'items' => $quoteItems]);
    }

    private function verifyEditData($pageData, $id) : array|bool {
        $updatedPageData = $this->updateEditData($pageData, $id);
        
        $clientId = trim($updatedPageData['client_id']);

        if (!$this->repository->clientExists($clientId)) {
            return false;
        }

        return $updatedPageData;
    }

    private function updateEditData($pageData, $id) : array {
        //Provide the old quote data to fill gaps in the page data for things that dont change like billing type. I know its lazy shut up
        $oldQuote = $this->repository->getQuote($id);
        $pageData += $oldQuote;

        $pageData['items'] = $this->getInputQuoteItems($pageData);
        $pageData['fulfillment_date'] = $this->verifyFulfillmentData($pageData['fulfillment_date']);
        $pageData['custom_fields'] = json_encode($this->getCustomFields('regular'));

        $finacialData = QuotesFinances::calculateFinancialData($pageData);

        return array_merge($pageData, $finacialData);
    }

    private function verifyCreateData(array $pageData) : array {
        $documentData = $this->getInputDocumentData($pageData['doc_type']);
        $pageData['items'] = $this->getInputQuoteItems($pageData);
        $pageData['fulfillment_date'] = $this->verifyFulfillmentData($pageData['fulfillment_date']);
        $pageData['custom_fields'] = json_encode($this->getCustomFields('regular'));
        $pageData['project_code'] = $this->repository->getNextProjectCode($pageData['client_id']);

        $finacialData = QuotesFinances::calculateFinancialData($pageData);

        return array_merge($pageData, $documentData, $finacialData);
    }

    private function getStoredQuoteItems(int $id): string
    {
        $quoteItems = $this->repository->getQuoteItems($id);
        $quoteItems = json_encode($quoteItems);
        return $quoteItems;
    }

    private function getStoredQuoteMeta(string $projectCode): array
    {
        $quoteMeta = $this->repository->getQuoteMeta($projectCode);
        return $quoteMeta;
    }

    private function getStoredQuote(int $id): array
    {
        $quoteData = $this->repository->getQuote($id);
        $quoteData = $this->updateClientName($quoteData);

        return $quoteData;
    }

    private function getInputDocumentData(string $doc_type): array
    {
        $is_long_term = ($doc_type === 'long_term') ? 1 : 0;
        $is_on_demand = ($doc_type === 'on_demand') ? 1 : 0;

        return [
            'is_long_term' => $is_long_term,
            'is_on_demand' => $is_on_demand
        ];
    }

    private function updateClientName(array $quote): array
    {
        $id = $quote['client_id'];

        $client = $this->repository->getClientById($id);
        $quote["client_name"] = $client['name'];

        return $quote;
    }

    private function verifyFulfillmentData(string $fulfillmentDate): string
    {
        return $fulfillmentDate ?: date('Y-m-d', strtotime('+30 days'));
    }

    private function areItemsRequired(string $pricingType, bool $isLongTerm): bool
    {
        return !($isLongTerm || ($pricingType === 'per_invoice' || $pricingType === 'on_demand'));
    }

    private function getInputQuoteItems(array $pageData): array
    {
        $items = [];

        $item = $pageData['item'];
        $desc = $pageData['desc'];
        $qty = $pageData['qty'];
        $price = $pageData['price'];

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

    private function getCustomFields(string $documentType): array
    {
        $value = $this->repository->getCustomFields($documentType) ?? [];
        return $value;
    }

}
