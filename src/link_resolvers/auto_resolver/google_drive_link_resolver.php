<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

class GoogleDriveLinkResolver implements LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string
    {
        // Call Google Drive API to find folder by name
        return null;
    }

    public function resolveForClient(Client $client): ?string
    {
        return null;
    }

    public function getType(): string
    {
        return 'auto_gdrive';
    }
}