<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "link")]
class Link
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: "links")]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: "links")]
    private ?Client $client = null;

    #[ORM\Column(type: "string", length: 255)]
    private string $title;

    #[ORM\Column(type: "string", length: 500)]
    private string $url;

    #[ORM\Column(type: "string", length: 50)]
    private string $type;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // getters and setters...
}