<?php

namespace App\controllers\invoice;

use App\utils\enum\DocumentType;
use App\services\invoice\InvoiceDataService;

class InvoiceDataController
{
    private InvoiceDataService $service;

    public function __construct(InvoiceDataService $service)
    {
        $this->service = $service;
    }

    public function load(): array
    {
        if (key_exists('id', $_GET)) {
            $id = $_GET['id'];
            $output = $this->service->getEditRenderData($id);

            return ['pages/invoice/invoice-edit.twig', $output->toArray()];
        } else {
            $output = $this->service->getCreateRenderData();

            return ['pages/invoice/invoice-create.twig', $output->toArray()];
        }
    }

    public function create()
    {
        $id = $_POST['id'];
    }

    public function update()
    {
        $id = $_POST['id'];
    }
}
