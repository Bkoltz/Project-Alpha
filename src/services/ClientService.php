<?php

namespace App\services;

use App\repositories\ClientRepository;

class ClientService
{
    private ClientRepository $repository;

    public function __construct(ClientRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getClientContactInformationById(int $clientId): array
    {
        $clientInfo = $this->repository->getContactInfoFromId($clientId);

        $cityLine = array_filter([
            trim((string)($clientInfo['city'] ?? '')),
            trim((string)($clientInfo['state'] ?? '')),
            trim((string)($clientInfo['postal'] ?? ''))
        ]);

        $cityLine = implode(', ', $cityLine);

        $toLines = array_filter([
            trim((string)$clientInfo['client_name'] ?? ''),
            trim((string)$clientInfo['client_org'] ?? ''),
            trim((string)$clientInfo['address_line1'] ?? ''),
            trim((string)$clientInfo['address_line2'] ?? ''),
            $cityLine
        ]);

        return ['toLines' => $toLines];
    }
}
