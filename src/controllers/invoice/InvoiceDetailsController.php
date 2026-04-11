<?php

namespace App\controllers\invoice;

use App\data_transfer_objects\render_outputs\RenderStatement;
use App\services\invoice\InvoiceDetailsService;
use App\utils\enum\DocumentType;

class InvoiceDetailsController
{
    private InvoiceDetailsService $service;

    private const RENDER_PATHS = [
        DocumentType::REGULAR => 'pages\contract\regular-contract-details.twig',
        DocumentType::LONG_TERM->value => 'pages\contract\long-term-contract-details.twig',
        DocumentType::ON_DEMAND->value => 'pages\contract\on-demand-contract-details.twig'
    ];

    public function __construct(InvoiceDetailsService $service)
    {
        $this->service = $service;
    }

    public function load(): RenderStatement
    {
        $id = $_GET['id'];
        $output = $this->service->getDetailsRenderData($id, DocumentType::REGULAR);
        $path = $this::RENDER_PATHS[DocumentType::REGULAR];

        return new RenderStatement($path, $output->toArray());
    }
}
