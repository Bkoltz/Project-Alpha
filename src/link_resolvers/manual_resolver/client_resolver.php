<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

class ClientLinkResolver implements LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string
    {
        return null;
    }

    public function resolveForClient(Client $client): ?string
    {
        return $client->getDefaultLink();
    }

    public function getType(): string
    {
        return 'manual';
    }
}