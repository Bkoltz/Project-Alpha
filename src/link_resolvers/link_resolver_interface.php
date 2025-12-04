<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

interface LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string;
    public function resolveForClient(Client $client): ?string;
    public function getType(): string; // e.g. "auto_dropbox"
}