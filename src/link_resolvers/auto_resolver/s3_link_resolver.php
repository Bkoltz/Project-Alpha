<?php
namespace App\LinkResolver;

use App\Entity\Client;
use App\Entity\Organization;

class S3LinkResolver implements LinkResolverInterface
{
    public function resolveForOrganization(Organization $org): ?string
    {
        // Generate signed URL for S3 bucket/folder
        return null;
    }

    public function resolveForClient(Client $client): ?string
    {
        return null;
    }

    public function getType(): string
    {
        return 'auto_s3';
    }
}