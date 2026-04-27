<?php

namespace App\controllers\quote;

use App\config\AppConfiguration;
use App\services\quotes\QuotesDetailsService;
use App\utils\enum\DocumentType;
use App\Config\Renderer;
use App\services\DocumentService;
use App\services\quotes\QuoteService;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesDetailsController
{
  private QuotesDetailsService $service;
  private DocumentService $documentService;
  private QuoteService $quoteService;
  private Renderer $renderer;

  private const DOCUMENT_PATHS = [
    DocumentType::REGULAR->value => 'pages/quote/regular-quote-details.twig',
    DocumentType::LONG_TERM->value => 'pages/quote/long-term-quote-details.twig',
    DocumentType::ON_DEMAND->value => 'pages/quote/on-demand-quote-details.twig'
  ];

  public function __construct(QuotesDetailsService $service, DocumentService $documentService, QuoteService $quoteService, Renderer $renderer)
  {
    $this->service = $service;
    $this->documentService = $documentService;
    $this->quoteService = $quoteService;
    $this->renderer = $renderer;
  }

  public function load(): array
  {
    (int)$id = $_GET['id'] ?? 0;

    $output = $this->service->getRenderData($id);
    $file = $this::DOCUMENT_PATHS[$output->document_type->value];

    return [$file, $output->toArray()];
  }

  public function toPDF(): void
  {
    (int)$id = $_GET['id'] ?? 0;
    $appConfig = AppConfiguration::$ConfigSettings;

    $output = $this->service->getRenderData($id);
    $content = $this->renderer->getRenderHTML('pages/quote/quote-pdf.twig', $output->toArray());
    $this->service->createPDF($id, $content, $appConfig);
  }

  public function reject(): void
  {
    (int)$id = $_POST['id'];

    $this->quoteService->rejectQuote($id);

    header("Location:/?page=quote/quote-list");
    exit;
  }

  public function approve(): void
  {
    (int)$id = $_POST['id'] ?? 0;

    $this->documentService->acceptQuoteAndCreateFullDoc($id);

    header("Location:/?page=quote/quote-list");
    exit;
  }
}
