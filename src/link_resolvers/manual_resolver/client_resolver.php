<?php
// src/link_resolvers/manual_resolver/client_resolver.php
// This resolver handles manually entered client links from the database

class ClientLinkResolver
{
    private $pdo;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    public function resolveForClient($clientId)
    {
        try {
            // Get manually entered links for this client
            $stmt = $this->pdo->prepare("
                SELECT url FROM entity_links 
                WHERE entity_type = 'client' 
                  AND entity_id = ? 
                  AND link_type = 'manual'
                  AND is_expired = 0
                LIMIT 1
            ");
            $stmt->execute([$clientId]);
            return $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            @error_log('[ClientLinkResolver] Error: ' . $e->getMessage());
            return null;
        }
    }
    
    public function getType()
    {
        return 'manual_client';
    }
}
