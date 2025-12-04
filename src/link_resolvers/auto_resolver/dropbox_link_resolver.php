<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

class DropboxLinkResolver implements LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string
    {
        // Example: search Dropbox API for folder named $org->getName()
        // return generated share link or null
        return null; // placeholder
    }

    public function resolveForClient(Client $client): ?string
    {
        // Example: search Dropbox API for folder named $client->getName()
        return null; // placeholder
    }

    public function getType(): string
    {
        return 'auto_dropbox';
    }
}