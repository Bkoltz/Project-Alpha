<?php

namespace App\services\quotes;

use App\config\AppConfiguration;
use App\data_transfer_objects\quote\QuoteData;
use App\render_outputs\quote\QuoteDetailsView;
use App\services\BaseDetailsService;
use App\services\ClientService;
use App\services\CustomFieldsService;
use App\services\FinancialService;
use Dompdf\Dompdf;
use Dompdf\Options;

class QuotesDetailsService extends BaseDetailsService
{
    private QuoteService $quoteService;
    private CustomFieldsService $customFieldsService;

    public function __construct(QuoteService $quoteService, CustomFieldsService $customFieldsService, ClientService $clientService)
    {
        $this->quoteService = $quoteService;
        $this->customFieldsService = $customFieldsService;

        parent::__construct($clientService);
    }

    public function getRenderData(int $id): QuoteDetailsView
    {
        $documentType = $this->quoteService->documentTypeFromId($id);

        $quote = $this->quoteService->getStoredQuote($id);
        $this->quoteService->validateQuoteData($quote);

        $quoteItems = $this->quoteService->getStoredQuoteItems($id);
        $quoteItems?->validate();

        FinancialService::updateQuoteFinancialData($quote, $quoteItems);

        $branding = $this->getBranding();
        $contactInfo = $this->getContactInfo($quote->client_id);
        $customFields = $this->customFieldsService->getCustomFieldDisplayView($documentType);
        $colors = $this->getStatusColors($quote);

        return new QuoteDetailsView([
            'id' => $id,
            'quote' => $quote,
            'items' => $quoteItems,
            'document_type' => $documentType,
            'app_config' => AppConfiguration::$ConfigSettings,
            'quote' => $quote,
            'branding' => $branding,
            'custom_fields' => $customFields,
            'contact_info' => $contactInfo,
            'colors' => $colors,
        ]);
    }

    public function createPDF(int $id, string $content, array $appConfig)
    {
        $doc = $this->quoteService->getQuoteDate($id);
        $documentDate = date('m/d/Y', strtotime($doc['document_date'])) ?: date('m/d/Y');

        $brand = htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha');
        $html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Quote - {$brand}</title>\n<style>\n  @page { margin: 72px 54px 72px 54px; }\n  body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 12px; color: #111; }\n</style>\n</head><body>" . $content . "</body></html>";

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        // Allow Dompdf to access local files under the project directory (for logos, etc.)
        $options->set('chroot', BASE_PATH);
        $dompdf = new Dompdf($options);

        // Set base path so relative/local file URLs resolve
        $publicDir = realpath(BASE_PATH . DIRECTORY_SEPARATOR . 'public');

        $dompdf->setProtocol('file://');

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        // Add page header text: Page X of Y at top-right
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $w = $canvas->get_width();
        $canvas->page_text(54, 22, $documentDate, $font, 10, [0, 0, 0]);
        $pageText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $canvas->page_text($w - 140, 22, $pageText, $font, 10, [0, 0, 0]);


        $h = $canvas->get_height();
        $canvas->page_text(54, $h - 30, 'Powered by Project Alpha', $font, 10, [0, 0, 0]);

        $filename = 'quote_Q-' . ($id) . '.pdf';

        $dompdf->stream($filename, ['Attachment' => false]);
    }

    public function getStatusColors(QuoteData $quote): array
    {
        $status = strtolower($quote->status ?? 'pending');

        $statusColors = [
            'pending' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
            'approved' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
            'rejected' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444']
        ];
        $colors = $statusColors[$status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];

        return $colors;
    }
}
