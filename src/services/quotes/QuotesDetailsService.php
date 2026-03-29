<?php

namespace App\services\quotes;

use App\repositories\quotes\QuotesDetailsRepository;
use App\config\AppConfiguration;
use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\QuoteItemsData;
use App\utils\enum\DocumentType;
use Dompdf\Dompdf;
use Dompdf\Options;
use finfo;

class QuotesDetailsService
{
    private QuotesDetailsRepository $repository;
    private QuoteService $quoteService;

    public function __construct(QuotesDetailsRepository $repository, QuoteService $quoteService)
    {
        $this->repository = $repository;
        $this->quoteService = $quoteService;
    }

    public function getPageData(array $data): array
    {
        $id = $data['id'] ?? 0;
        $appConfig = AppConfiguration::$ConfigSettings;

        $documentType = $this->getDocumentTypeById($id);
        $quote = $this->repository->getQuoteById($id, $documentType);
        $quote = $this->updateQuoteData($quote, $appConfig);

        $recieverInfo = $this->getRecieverInformation($quote);
        $senderInfo = $this->getSenderInformation($appConfig);

        $quoteData = QuoteData::fromArray($data);
        $this->quoteService->validateQuoteData($quoteData);

        $quoteItems = QuoteItemsData::fromArray($this->repository->getItemsById($id));
        $this->quoteService->validateQuoteItems($quoteItems);

        $data = FinancialService::calculateFinancialData($quoteData, $quoteItems)->toArray();

        return array_merge($data, $recieverInfo, $senderInfo, [
            'documentType' => $documentType,
            'appConfig' => $appConfig,
            'brand' => $appConfig['brand_name'] ?? 'Project Alpha',
            'quote' => $quote,
            'logoPath' => $this->resolveLogoPath($appConfig),
            'items' => $this->repository->getItemsById($id),
            'customFields' => $this->getCustomFields($quote),
            'colors' => $this->getStatusColors($quote),
        ]);
    }



    public function updateQuoteData(array $quote, array $appConfig): array
    {
        $quote['deposit_value'] = $this->getDepositValue($quote);
        $quote['terms'] = $this->resolveTerms($quote, $appConfig);

        return $quote;
    }

    public function createPDF(int $id, string $content, array $appConfig)
    {
        $doc = $this->repository->getQuoteDate($id);
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

    public function getSenderInformation(array $appConfig): array
    {
        $fromName = $appConfig['brand_name'] ?? 'Project Alpha';
        $fromPhone = $appConfig['from_phone'] ?? '';
        $fromEmail = $appConfig['from_email'] ?? '';

        $cityLine = array_filter([
            trim((string)($appConfig['from_city'] ?? '')),
            trim((string)($appConfig['from_state'] ?? '')),
            trim((string)($appConfig['from_postal'] ?? ''))
        ]);

        $cityLine = implode(', ', $cityLine);

        $fromLines = array_filter([
            trim((string)($appConfig['brand_name'] ?? 'Project Alpha')),
            trim((string)($appConfig['from_address_line1'] ?? '')),
            trim((string)($appConfig['from_address_line2'] ?? '')),
            $cityLine
        ]);

        return ['fromLines' => $fromLines, 'fromName' => $fromName, 'fromPhone' => $fromPhone, 'fromEmail' => $fromEmail];
    }

    public function getRecieverInformation(array $quote): array
    {
        $cityLine = array_filter([
            trim((string)($quote['city'] ?? '')),
            trim((string)($quote['state'] ?? '')),
            trim((string)($quote['postal'] ?? ''))
        ]);

        $cityLine = implode(', ', $cityLine);

        $toLines = array_filter([
            trim((string)$quote['client_name'] ?? ''),
            trim((string)$quote['client_org'] ?? ''),
            trim((string)$quote['address_line1'] ?? ''),
            trim((string)$quote['address_line2'] ?? ''),
            $cityLine
        ]);

        return ['toLines' => $toLines];
    }

    public function resolveTerms(array $quote, array $appConfig): string
    {
        $termsText = $this->repository->getTerms($quote['project_code'] ?? '');

        if ($termsText === '') {
            $termsText = trim((string)($quote['terms'] ?? ''));
        }

        if ($termsText === '' && !empty($quote['is_on_demand'])) {
            $termsText = trim((string)($appConfig['on_demand_terms'] ?? ''));
        }

        if ($termsText === '') {
            $termsText = trim((string)($appConfig['terms'] ?? ''));
        }

        return $termsText;
    }

    public function resolveLogoPath(array $appConfig): string
    {
        // Resolve default logo under project root public/assets
        $desiredLogoPath = $appConfig['logo_path'];
        $defaultLogoPath = BASE_PATH . '/public/assets/default-logo.png';

        $logoConf = trim((string)($desiredLogoPath ?? ''));
        $logoPath = $logoConf ?: $defaultLogoPath;

        $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;

        if (!$isUrl) {
            $base = rtrim(BASE_PATH, DIRECTORY_SEPARATOR);

            if ($logoPath !== '') {
                $fullPath = realpath($base . DIRECTORY_SEPARATOR . ltrim($logoPath, '/\\'));

                if ($fullPath !== false && str_starts_with($fullPath, $base)) {
                    $logoPath = $fullPath;
                }
            }
        }

        // Prefer embedding local images as data URIs so Dompdf can render them reliably
        $logoSrc = $logoPath;
        if (is_file($logoPath) && !$isUrl) {
            // Try to read the file and build a data URI (base64). This avoids file:// or remote restrictions
            $imgContents = file_get_contents($logoPath);
            if ($imgContents !== false) {
                $mime = null;
                // Prefer explicit SVG mime type when extension indicates SVG
                if (str_ends_with(strtolower($logoPath), '.svg')) {
                    $mime = 'image/svg+xml';
                } else {
                    if (function_exists('finfo_open')) {
                        if (function_exists('finfo_open')) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = $finfo->buffer($imgContents) ?: null;
                        }
                    }
                }

                $allowed = [
                    'image/png',
                    'image/jpeg',
                    'image/gif',
                    'image/webp',
                    'image/svg+xml',
                ];

                if ($mime && in_array($mime, $allowed, true)) {
                    $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
                }
            } else {
                $normalized = str_replace('\\', '/', $logoPath);
                if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '/') === 0) {
                    $logoSrc = 'file:///' . ltrim($normalized, '/');
                }
            }
        }

        return $logoSrc;
    }

    private function getDocumentTypeById(int $id): DocumentType
    {
        $data = $this->repository->getDocumentTypeData($id);

        if ($data['is_on_demand'] == 1) {
            return DocumentType::ON_DEMAND;
        } else if ($data['is_long_term'] == 1) {
            return DocumentType::LONG_TERM;
        } else {
            return DocumentType::REGULAR;
        }
    }

    /* 
        TODO: COME BACK TO RECHECK THIS

        This renders the data to load custom fields but I have no idea if we are wanting to exclude fields that 'should' be in a specifed quote based on
        doucment type if it wasnt found the quote initally. This is what we are doing currently and its kinda lame
    */
    public function getCustomFields(array $quote): array
    {
        $customFieldValues = !empty($quote['custom_fields']) ? json_decode($quote['custom_fields'], true) : [];
        $customFieldValues = !is_array($customFieldValues) ?: [];

        $customFieldDefs = $this->repository->getCustomFields($quote['pricing_type']) ?? '';

        $displayCustomFields = [];
        foreach ($customFieldDefs as $customField) {
            $key = $customField['field_key'];

            if (!empty($customFieldValues['key'])) {
                $val = $customFieldValues[$key];

                $displayCustomFields[] = ['label' => $customField['field_label'], 'value' => $val];
            }
        }

        return $displayCustomFields;
    }

    public function getDepositvalue(array $quote): int
    {
        $depositType = $quote['deposit_type'] ?? 'none';
        $depositValue = (float)($quote['deposit_amount'] ?? 0);
        $quoteTotal = (float)($quote['total'] ?? 0);

        if ($depositType === 'percent') {
            $depositValue = max(0, min(100, $depositValue)) * $quoteTotal / 100;
        }

        return $depositValue;
    }

    public function getStatusColors(array $quote): array
    {
        $status = strtolower($quote['status'] ?? 'pending');

        $statusColors = [
            'pending' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
            'approved' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
            'rejected' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444']
        ];
        $colors = $statusColors[$status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];

        return $colors;
    }

    //Usless
    public function getSVGData($logoPath)
    {
        $svgContents = @file_get_contents($logoPath);
        $svgData = 'data:image/svg+xml;base64,' . base64_encode($svgContents);
    }


    public function rejectQuote(int $id)
    {
        $this->repository->rejectQuote($id);
    }
}
