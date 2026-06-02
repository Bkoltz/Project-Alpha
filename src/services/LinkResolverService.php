<?php
// src/services/LinkResolverService.php

class LinkResolverService {
    private $pdo;
    private $config;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }
    
    private function loadConfig() {
        $this->config = [
            'enabled' => false,
            'default_expiration_days' => 365,
            'org_level_only' => false,
            'providers' => []
        ];
        
        try {
            // Load app config
            $stmt = $this->pdo->query("SELECT config_key, config_value FROM app_config WHERE config_key IN ('link_resolver_enabled', 'default_link_expiration_days', 'org_level_links_only')");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                switch ($row['config_key']) {
                    case 'link_resolver_enabled':
                        $this->config['enabled'] = (bool)$row['config_value'];
                        break;
                    case 'default_link_expiration_days':
                        $this->config['default_expiration_days'] = (int)$row['config_value'];
                        break;
                    case 'org_level_links_only':
                        $this->config['org_level_only'] = (bool)$row['config_value'];
                        break;
                }
            }
            
            // Load provider configs
            $stmt = $this->pdo->query("SELECT * FROM link_resolver_config WHERE is_enabled = 1");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->config['providers'][$row['provider']] = [
                    'credentials' => json_decode($row['credentials'], true),
                    'default_expiration_days' => $row['default_expiration_days'] ?? $this->config['default_expiration_days']
                ];
            }
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error loading config: ' . $e->getMessage());
        }
    }
    
    /**
     * Auto-generate links for a client
     */
    public function autoGenerateForClient($clientId) {
        if (!$this->config['enabled']) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }
        
        try {
            // Get client info
            $stmt = $this->pdo->prepare("SELECT name, organization_id FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                return ['success' => false, 'message' => 'Client not found'];
            }
            
            // Check if org-level only and client has org
            if ($this->config['org_level_only'] && $client['organization_id']) {
                return ['success' => false, 'message' => 'Client belongs to organization - manage links at org level'];
            }
            
            // Check if client is ignored
            $stmt = $this->pdo->prepare("SELECT ignore_auto_generation FROM link WHERE entity_type = 'client' AND entity_id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($link && $link['ignore_auto_generation']) {
                return ['success' => false, 'message' => 'Client is marked to ignore auto-generation'];
            }
            
            $generated = [];
            $errors = [];
            
            foreach ($this->config['providers'] as $provider => $providerConfig) {
                $result = $this->generateLinkForProvider($provider, 'client', $clientId, $client['name'], $providerConfig);
                if ($result['success']) {
                    $generated[] = $provider;
                } else {
                    $errors[$provider] = $result['message'];
                }
            }
            
            return [
                'success' => count($generated) > 0,
                'generated' => $generated,
                'errors' => $errors
            ];
            
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error generating links for client: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Auto-generate links for an organization
     */
    public function autoGenerateForOrganization($orgId) {
        if (!$this->config['enabled']) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }
        
        try {
            // Get org info
            $stmt = $this->pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $stmt->execute([$orgId]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$org) {
                return ['success' => false, 'message' => 'Organization not found'];
            }
            
            // Check if org is ignored
            $stmt = $this->pdo->prepare("SELECT ignore_auto_generation FROM link WHERE entity_type = 'organization' AND entity_id = ? LIMIT 1");
            $stmt->execute([$orgId]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($link && $link['ignore_auto_generation']) {
                return ['success' => false, 'message' => 'Organization is marked to ignore auto-generation'];
            }
            
            $generated = [];
            $errors = [];
            
            foreach ($this->config['providers'] as $provider => $providerConfig) {
                $result = $this->generateLinkForProvider($provider, 'organization', $orgId, $org['name'], $providerConfig);
                if ($result['success']) {
                    $generated[] = $provider;
                } else {
                    $errors[$provider] = $result['message'];
                }
            }
            
            return [
                'success' => count($generated) > 0,
                'generated' => $generated,
                'errors' => $errors
            ];
            
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error generating links for organization: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate link for specific provider
     */
    private function generateLinkForProvider($provider, $entityType, $entityId, $entityName, $providerConfig) {
        try {
            // Map provider to resolver type
            $typeMap = [
                'dropbox' => 'auto_dropbox',
                'gdrive' => 'auto_gdrive',
                's3' => 'auto_s3'
            ];
            
            $linkType = $typeMap[$provider] ?? null;
            if (!$linkType) {
                return ['success' => false, 'message' => 'Invalid provider'];
            }
            
            // Load appropriate resolver
            $resolverPath = __DIR__ . "/../link_resolvers/auto_resolver/{$provider}_link_resolver.php";
            if (!file_exists($resolverPath)) {
                return ['success' => false, 'message' => 'Resolver not found'];
            }
            
            require_once $resolverPath;
            
            $resolverClass = ucfirst($provider) . 'LinkResolver';
            $resolver = new $resolverClass($providerConfig['credentials']);
            
            // Search for folder and generate public link
            $folderResult = $resolver->searchFolder($entityName);
            if (!$folderResult['success']) {
                return ['success' => false, 'message' => $folderResult['message'] ?? 'Folder not found'];
            }
            
            $publicLink = $resolver->generatePublicLink($folderResult['folder_id']);
            if (!$publicLink) {
                return ['success' => false, 'message' => 'Could not generate public link'];
            }
            
            // Calculate expiration date
            $expirationDays = $providerConfig['default_expiration_days'] ?? $this->config['default_expiration_days'];
            $expirationDate = date('Y-m-d', strtotime("+{$expirationDays} days"));
            
            // Check if link already exists
            $stmt = $this->pdo->prepare("SELECT link_id FROM link WHERE entity_type = ? AND entity_id = ? AND type = ?");
            $stmt->execute([$entityType, $entityId, $linkType]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing link
                $stmt = $this->pdo->prepare("
                    UPDATE link 
                    SET url = ?, expiration_date = ?, is_expired = 0, last_verified = NOW()
                    WHERE link_id = ?
                ");
                $stmt->execute([$publicLink, $expirationDate, $existing['link_id']]);
            } else {
                // Insert new link
                $stmt = $this->pdo->prepare("
                    INSERT INTO link (entity_type, entity_id, type, url, expiration_date, is_expired, last_verified)
                    VALUES (?, ?, ?, ?, ?, 0, NOW())
                ");
                $stmt->execute([$entityType, $entityId, $linkType, $publicLink, $expirationDate]);
            }
            
            return ['success' => true, 'url' => $publicLink];
            
        } catch (Throwable $e) {
            @error_log("[LinkResolverService] Error generating {$provider} link: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Mark entity as ignored for auto-generation
     */
    public function markAsIgnored($entityType, $entityId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE link 
                SET ignore_auto_generation = 1
                WHERE entity_type = ? AND entity_id = ?
            ");
            $stmt->execute([$entityType, $entityId]);
            
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error marking as ignored: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Unmark entity as ignored
     */
    public function unmarkAsIgnored($entityType, $entityId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE link 
                SET ignore_auto_generation = 0
                WHERE entity_type = ? AND entity_id = ?
            ");
            $stmt->execute([$entityType, $entityId]);
            
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error unmarking as ignored: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Manually expire links for entity
     */
    public function expireLinks($entityType, $entityId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE link 
                SET is_expired = 1
                WHERE entity_type = ? AND entity_id = ?
            ");
            $stmt->execute([$entityType, $entityId]);
            
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error expiring links: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Refresh/regenerate links for entity
     */
    public function refreshLinks($entityType, $entityId) {
        if ($entityType === 'client') {
            return $this->autoGenerateForClient($entityId);
        } elseif ($entityType === 'organization') {
            return $this->autoGenerateForOrganization($entityId);
        }
        return ['success' => false, 'message' => 'Invalid entity type'];
    }
}
