<?php
// src/cron/link_expiration_checker.php

require_once __DIR__ . '/../config/db.php';

/**
 * Link Expiration Checker Cron Job
 * 
 * Run daily to:
 * - Mark links as expired when their expiration_date is past
 * - Optionally send email notifications
 * 
 * Setup: Add to crontab
 * 0 2 * * * /usr/bin/php /path/to/src/cron/link_expiration_checker.php
 */

try {
    $logPrefix = '[LinkExpirationChecker]';
    
    // Check if link expiration checker is enabled
    $stmt = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = 'link_expiration_checker'");
    $stmt->execute();
    $enabled = $stmt->fetchColumn();
    
    if (!$enabled) {
        echo $logPrefix . " Link expiration checker is disabled. Exiting.\n";
        exit(0);
    }
    
    echo "{$logPrefix} Starting link expiration check at " . date('Y-m-d H:i:s') . "\n";
    
    // Get today's date
    $today = date('Y-m-d');
    
    // Find all links that should be expired but aren't marked as such
    $stmt = $pdo->prepare("
        SELECT id, entity_type, entity_id, link_type, url, expiration_date
        FROM entity_links
        WHERE expiration_date IS NOT NULL 
          AND expiration_date < ?
          AND is_expired = 0
          AND ignore_auto_generation = 0
    ");
    $stmt->execute([$today]);
    $expiredLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $expiredCount = count($expiredLinks);
    
    if ($expiredCount === 0) {
        echo "{$logPrefix} No links to expire.\n";
    } else {
        echo "{$logPrefix} Found {$expiredCount} expired link(s).\n";
        
        // Mark links as expired
        $linkIds = array_column($expiredLinks, 'id');
        $placeholders = str_repeat('?,', count($linkIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            UPDATE entity_links
            SET is_expired = 1
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($linkIds);
        
        echo "{$logPrefix} Marked {$expiredCount} link(s) as expired.\n";
        
        // Check if email notifications are enabled
        $stmt = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = 'link_expiration_email_enabled'");
        $stmt->execute();
        $emailEnabled = $stmt->fetchColumn();
        
        if ($emailEnabled) {
            echo "{$logPrefix} Sending email notifications...\n";
            
            // Group links by entity
            $entitiesByType = [];
            foreach ($expiredLinks as $link) {
                $key = $link['entity_type'] . '_' . $link['entity_id'];
                if (!isset($entitiesByType[$key])) {
                    $entitiesByType[$key] = [
                        'type' => $link['entity_type'],
                        'id' => $link['entity_id'],
                        'links' => []
                    ];
                }
                $entitiesByType[$key]['links'][] = $link;
            }
            
            // Send email for each entity
            foreach ($entitiesByType as $entity) {
                try {
                    $entityName = getEntityName($pdo, $entity['type'], $entity['id']);
                    
                    $subject = "Link Expiration: {$entityName}";
                    $message = "The following links for {$entity['type']} '{$entityName}' have expired:\n\n";
                    
                    foreach ($entity['links'] as $link) {
                        $message .= "- " . $link['link_type'] . ": " . $link['url'] . " (expired: " . $link['expiration_date'] . ")\n";
                    }
                    
                    $message .= "\nPlease refresh or regenerate these links as needed.\n";
                    
                    // Get admin email from config
                    $stmt = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = 'admin_email'");
                    $stmt->execute();
                    $adminEmail = $stmt->fetchColumn();
                    
                    if ($adminEmail) {
                        mail($adminEmail, $subject, $message);
                        echo "{$logPrefix} Sent notification for {$entityName}\n";
                    }
                } catch (Throwable $e) {
                    echo "{$logPrefix} Error sending email for entity {$entity['id']}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    // Also check for links expiring soon (within 7 days) for early warning
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM entity_links
        WHERE expiration_date IS NOT NULL
          AND expiration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
          AND is_expired = 0
          AND ignore_auto_generation = 0
    ");
    $stmt->execute([$today, $today]);
    $expiringSoon = $stmt->fetchColumn();
    
    if ($expiringSoon > 0) {
        echo "{$logPrefix} Warning: {$expiringSoon} link(s) will expire within 7 days.\n";
    }
    
    echo "{$logPrefix} Link expiration check completed successfully.\n";
    
} catch (Throwable $e) {
    echo "{$logPrefix} ERROR: " . $e->getMessage() . "\n";
    error_log("{$logPrefix} Error: " . $e->getMessage());
    exit(1);
}

/**
 * Helper function to get entity name
 */
function getEntityName($pdo, $entityType, $entityId) {
    if ($entityType === 'client') {
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$entityId]);
        return $stmt->fetchColumn() ?: "Client #{$entityId}";
    } elseif ($entityType === 'organization') {
        $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $stmt->execute([$entityId]);
        return $stmt->fetchColumn() ?: "Organization #{$entityId}";
    }
    return "Unknown";
}
