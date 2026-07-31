<?php
// src/utils/project_billing.php

function project_invoice_terms_days(PDO $pdo, ?int $projectId, array $appConfig): int
{
    $netTermsDays = max(0, (int)($appConfig['net_terms_days'] ?? 30));
    if (!$projectId) {
        return $netTermsDays;
    }
    try {
        $stmt = $pdo->prepare('SELECT invoice_net_terms_days FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null && $value !== '') {
            return max(0, (int)$value);
        }
    } catch (Throwable $e) {
        error_log('[project_billing] net terms lookup failed: ' . $e->getMessage());
    }
    return $netTermsDays;
}

function project_invoice_due_date(PDO $pdo, ?int $projectId, array $appConfig, ?string $baseDate = null): string
{
    $netTermsDays = project_invoice_terms_days($pdo, $projectId, $appConfig);
    $monthlyBilling = false;

    if ($projectId) {
        try {
            $stmt = $pdo->prepare('SELECT invoice_billing_period, invoice_net_terms_days FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $monthlyBilling = ($row['invoice_billing_period'] ?? 'per_invoice') === 'monthly';
                if ($row['invoice_net_terms_days'] !== null && $row['invoice_net_terms_days'] !== '') {
                    $netTermsDays = max(0, (int)$row['invoice_net_terms_days']);
                }
            }
        } catch (Throwable $e) {
            error_log('[project_billing] due date lookup failed: ' . $e->getMessage());
        }
    }

    $base = $baseDate ?: date('Y-m-d');
    $dueBase = $monthlyBilling ? date('Y-m-t', strtotime($base)) : $base;
    return date('Y-m-d', strtotime($dueBase . ' +' . $netTermsDays . ' days'));
}

function project_uses_monthly_invoice_billing(PDO $pdo, ?int $projectId): bool
{
    if (!$projectId) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT invoice_billing_period FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        return (string)($stmt->fetchColumn() ?: 'per_invoice') === 'monthly';
    } catch (Throwable $e) {
        error_log('[project_billing] monthly billing lookup failed: ' . $e->getMessage());
        return false;
    }
}
