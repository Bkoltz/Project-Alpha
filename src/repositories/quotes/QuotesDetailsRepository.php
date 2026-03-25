<?php

namespace App\repositories\quotes;

use App\utils\enum\DocumentType;
use PDO;

class QuotesDetailsRepository
{
    private $pdo;

    private const DOCUMENT_TYPE_VALUES = [
        DocumentType::REGULAR->value => 'SELECT q.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE q.id=?',
        DocumentType::LONG_TERM->value => 'SELECT q.*, cl.name client_name, o.name AS client_org, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal, cl.country FROM quotes q JOIN clients cl ON cl.id=q.client_id LEFT JOIN organizations o ON o.id=cl.organization_id WHERE q.id=? AND q.is_long_term=1',
        DocumentType::ON_DEMAND->value => 'SELECT q.*, cl.name client_name, o.name AS client_org, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal, cl.country FROM quotes q JOIN clients cl ON cl.id=q.client_id LEFT JOIN organizations o ON o.id=cl.organization_id WHERE q.id=? AND q.is_on_demand=1'
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getQuoteData(int $id, DocumentType $documentType): array
    {
        $quote = $this->getQuoteById($id, $documentType);
        $quoteItems = [];
        if ($documentType == DocumentType::REGULAR)
            $quoteItems = $this->getItemsById($id);

        $projectCode = $quote['project_code'] ?? '';
        if (!empty($projectCode))
            $terms = $this->getTerms($projectCode);

        $custom_fields = $this->getCustomFields($documentType->value);

        return ['quote' => $quote, 'quote_items' => $quoteItems, 'quote_terms' => $terms, 'custom_fields' => $custom_fields];
    }

    public function getDocumentTypeData(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT is_on_demand, is_long_term FROM quotes WHERE id=?');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);;
    }

    public function getQuoteById(int $id, DocumentType $documentType): array
    {
        $sql = $this::DOCUMENT_TYPE_VALUES[$documentType->value];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getItemsById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public function getQuoteDate(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT document_date FROM quotes WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    //Quotes project code
    public function getTerms(string $projectCode): string
    {
        $terms = $this->pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
        $terms->execute([$projectCode]);

        return (string)$terms->fetchColumn();
    }

    //Quotes pricing type
    public function getCustomFields(string $documentType): array
    {
        $cfStmt = $this->pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0 ORDER BY display_order, id');
        $cfStmt->execute([$documentType]);
        return  $cfStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateQuoteStatus(int $id, string $status)
    {
        $st = $this->pdo->prepare('UPDATE quotes SET status=? WHERE id=? AND status="pending"');
        $st->execute([$status, $id]);
    }

    public function rejectQuote($id)
    {
        $st = $this->pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=? AND status="pending"');
        $st->execute([$id]);
    }

    public function approveQuote(int $id)
    {
        $stmt = $this->pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?');
        $stmt->execute([$id]);
    }
}
