<?php

namespace App\controllers\quote;

use App\config\AppConfiguration;
use App\services\quotes\QuotesDetailsService;
use App\utils\enum\DocumentType;
use App\Config\Renderer;
use App\services\DocumentService;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesDetailsController
{
  private QuotesDetailsService $service;
  private DocumentService $documentService;
  private Renderer $renderer;

  private const DOCUMENT_PATHS = [
    DocumentType::REGULAR->value => 'partials/document_list/details/regular-quote-details.twig',
    DocumentType::LONG_TERM->value => 'partials/document_list/details/long-term-quote-details.twig',
    DocumentType::ON_DEMAND->value => 'partials/document_list/details/on-demand-quote-details.twig'
  ];

  public function __construct(QuotesDetailsService $service, DocumentService $documentService, Renderer $renderer)
  {
    $this->service = $service;
    $this->documentService = $documentService;
    $this->renderer = $renderer;
  }

  public function load(): array
  {
    $output = $this->getRenderData();
    $file = $this::DOCUMENT_PATHS[$output['documentType']->value ?? DocumentType::REGULAR->value];

    return [$file, $output];
  }

  public function toPDF()
  {
    $id = $_GET['id'] ?? 0;
    $appConfig = AppConfiguration::$ConfigSettings;

    $output = $this->getRenderData();
    $content = $this->renderer->getRenderHTML('pages/quote/quote-pdf.twig', $output);
    $this->service->createPDF($id, $content, $appConfig);
  }

  private function getRenderData(): array
  {
    $requestData = $this->getRequestData();
    return $this->service->getPageData($requestData);
  }

  private function getRequestData(): array
  {
    return [
      'csrf_token' => csrf_token(),
      'id' => $_GET['id'] ?? 0,
      'requestURI' => $_SERVER['REQUEST_URI'] ?? '',
      'dateUpdated' => $_GET['date_updated'] ?? false,
      'reenabled' => $_GET['reenabled'] ?? false,
    ];
  }

  public function reject()
  {
    $id = $_POST['id'];

    $this->service->rejectQuote($id);

    header("Location:/?page=quote/quote-list");
    exit;
  }

  public function approve()
  {
    $id = $_POST['id'];

    $this->documentService->acceptQuoteAndCreateFullDoc($id);

    header("Location:/?page=quote/quote-list");
    exit;
  }
}
