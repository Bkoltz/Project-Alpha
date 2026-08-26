<?php
// src/link_resolvers/link_manager.php
// This manager coordinates all link resolvers (manual and auto)
require_once __DIR__ . '/../utils/resolver_link_policy.php';

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
            $resolverVisibility = pa_resolver_link_visibility_sql();
            $stmt = $this->pdo->prepare("
                SELECT link_type, url, expiration_date, is_expired 
                FROM entity_links 
                WHERE entity_type = 'organization' AND entity_id = ?
                  AND link_type <> 'resolver_blacklist'
                  AND {$resolverVisibility}
                ORDER BY link_type
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
            $resolverVisibility = pa_resolver_link_visibility_sql();
            $stmt = $this->pdo->prepare("
                SELECT link_type, url, expiration_date, is_expired 
                FROM entity_links 
                WHERE entity_type = 'client' AND entity_id = ?
                  AND link_type <> 'resolver_blacklist'
                  AND {$resolverVisibility}
                ORDER BY link_type
            ");
            $stmt->execute([$clientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            @error_log('[LinkResolverManager] Error: ' . $e->getMessage());
            return [];
        }
    }
}
