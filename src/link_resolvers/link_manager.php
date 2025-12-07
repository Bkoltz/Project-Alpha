<?php
// src/link_resolvers/link_manager.php
// This manager coordinates all link resolvers (manual and auto)

class LinkResolverManager
{
    private $pdo;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Get all links for an organization
     */
    public function getAllLinksForOrganization($orgId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT type, url, expiration_date, is_expired 
                FROM link 
                WHERE entity_type = 'organization' AND entity_id = ?
                ORDER BY type
            ");
            $stmt->execute([$orgId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            @error_log('[LinkResolverManager] Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all links for a client
     */
    public function getAllLinksForClient($clientId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT type, url, expiration_date, is_expired 
                FROM link 
                WHERE entity_type = 'client' AND entity_id = ?
                ORDER BY type
            ");
            $stmt->execute([$clientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            @error_log('[LinkResolverManager] Error: ' . $e->getMessage());
            return [];
        }
    }
}
