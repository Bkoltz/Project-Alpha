<?php

namespace App\repositories\quotes;

use PDO;

class QuotesDetailsRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getQuoteData(int $id): array
    {
        $quote = $this->getQuoteById($id);
        $quoteItems = $this->getItemsById($id);

        $projectCode = $quote['project_code'] ?? '';
        if (!empty($projectCode)) {
            $terms = $this->getTerms($projectCode);
        }

        $document_type = $quote['document_type'];
        if (!empty($document_type)) {
            $custom_fields = $this->getCustomFields($document_type);
        }

        return ['quote' => $quote, 'quote_items' => $quoteItems, 'quote_terms' => $terms, 'custom_fields' => $custom_fields];
    }

    public function getQuoteById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT q.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE q.id=?');
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
}
