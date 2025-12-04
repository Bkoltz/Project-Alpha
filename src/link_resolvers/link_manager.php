<?php
namespace App\Service;

use App\Entity\Client;
use App\Entity\Organization;
use App\LinkResolver\LinkResolverInterface;

class LinkResolverManager
{
    /** @var LinkResolverInterface[] */
    private array $resolvers;

    public function __construct(iterable $resolvers)
    {
        $this->resolvers = $resolvers;
    }

    public function resolveForOrganization(Organization $org): array
    {
        $links = [];
        foreach ($this->resolvers as $resolver) {
            $url = $resolver->resolveForOrganization($org);
            if ($url) {
                $links[] = ['title' => ucfirst($resolver->getType()), 'url' => $url];
            }
        }
        return $links;
    }

    public function resolveForClient(Client $client): array
    {
        $links = [];
        foreach ($this->resolvers as $resolver) {
            $url = $resolver->resolveForClient($client);
            if ($url) {
                $links[] = ['title' => ucfirst($resolver->getType()), 'url' => $url];
            }
        }
        return $links;
    }
}