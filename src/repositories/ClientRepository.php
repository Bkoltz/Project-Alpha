<?php

namespace App\repositories;

use App\render_outputs\ContactInfoView;
use PDO;

class ClientRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getContactInfoFromId(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT cl.name client_name, o.name AS client_org, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal, cl.country FROM clients cl LEFT JOIN organizations o ON o.id=cl.organization_id WHERE cl.id=?');
        $stmt->execute([$clientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
