<?php
// src/link_resolvers/manual_resolver/org_resolver.php
// This resolver handles manually entered organization links from the database

class OrgLinkResolver
{
    private $pdo;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    public function resolveForOrganization($orgId)
    {
        try {
            // Get manually entered links for this organization
            $stmt = $this->pdo->prepare("
                SELECT url FROM entity_links 
                WHERE entity_type = 'organization' 
                  AND entity_id = ? 
                  AND link_type = 'manual'
                  AND is_expired = 0
                LIMIT 1
            ");
            $stmt->execute([$orgId]);
            return $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            @error_log('[OrgLinkResolver] Error: ' . $e->getMessage());
            return null;
        }
    }
    
    public function getType()
    {
        return 'manual_org';
    }
}
