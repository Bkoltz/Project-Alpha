<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

class OrgLinkResolver implements LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string
    {
        // Return stored manual link from DB
        return $org->getDefaultLink();
    }

    public function resolveForClient(Client $client): ?string
    {
        return null;
    }

    public function getType(): string
    {
        return 'manual';
    }
}