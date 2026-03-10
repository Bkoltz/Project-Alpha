<?php

namespace App\controllers\quote;

use App\config\AppConfiguration;
use App\services\quotes\QuotesDetailsService;
use App\Config\Renderer;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesDetailsController
{
  private QuotesDetailsService $service;
  private Renderer $renderer;

  public function __construct(QuotesDetailsService $service, Renderer $renderer)
  {
    $this->service = $service;
    $this->renderer = $renderer;
  }

  public function load()
  {
    $output = $this->getRenderData();
    return $this->showQuoteDetails($output);
  }

  public function toPDF()
  {
    $id = $_GET['id'] ?? 0;
    $appConfig = AppConfiguration::$ConfigSettings;

    $output = $this->getRenderData();
    $content = $this->renderer->getRenderHTML('pages/quote/quotes-pdf.twig', $output);
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

  private function showQuoteDetails(array $output)
  {
    return ['pages/quote/quotes-details.twig', $output];
  }

  public function reject()
  {
    $id = $_POST['id'];

    $this->service->rejectQuote($id);

    header("Location:/?page=quote/quotes-list");
    exit;
  }

  private function approve() {}
}
