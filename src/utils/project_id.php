<?php
// src/utils/project_id.php
// TODO: We need to add logic, so that all related long-term invoices show in the same project as the corresponding contract. 
// We don't need to list all invoices in the project list view, but we need to add a way to let the user know there are multiple invoices for that project.
function project_client_initials(string $name): string {
    // Build up to 2 initials from first two words; fallback to first two alphanumerics
    $name = trim($name);
    if ($name === '') return 'XX';
    // Normalize spaces and remove non-letter/digit except spaces
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    $parts = preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY);

    // Helpers with mb_* fallbacks
    $toUpper = function ($s) {
        if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
        return strtoupper($s);
    };
    $sub = function ($s, $start, $len = null) {
        if (function_exists('mb_substr')) return mb_substr($s, $start, $len, 'UTF-8');
        return substr($s, $start, $len ?? strlen($s));
    };
    $len = function ($s) {
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        return strlen($s);
    };

    $initials = '';
    foreach ($parts as $p) {
        $ch = $sub($p, 0, 1);
        if ($ch !== '') {
            $initials .= $toUpper($ch);
            if ($len($initials) >= 2) break;
        }
    }
    if ($initials === '') {
        $flat = preg_replace('/\s+/', '', $norm);
        $initials = $toUpper($sub($flat, 0, 3)) ?: 'XX';
    } elseif ($len($initials) === 1) {
        $flat = preg_replace('/\s+/', '', $norm);
        $next = $sub($flat, 1, 1);
        $initials .= $toUpper($next !== '' ? $next : 'X');
    }
    return $initials;
}

function project_contact_initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'XX';
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    $parts = preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) >= 2) {
        $first = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
        $lastPart = $parts[array_key_last($parts)];
        $last = function_exists('mb_substr') ? mb_substr($lastPart, 0, 1, 'UTF-8') : substr($lastPart, 0, 1);
        return function_exists('mb_strtoupper') ? mb_strtoupper($first . $last, 'UTF-8') : strtoupper($first . $last);
    }
    return project_client_initials($name);
}

function project_organization_initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'ORG';
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    $parts = preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $legalSuffixes = ['llc','inc','incorporated','ltd','limited','corp','corporation','co','company','pllc','llp','lp'];
    $meaningful = array_values(array_filter($parts, static function ($part) use ($legalSuffixes) {
        return !in_array(strtolower($part), $legalSuffixes, true);
    }));
    if (!$meaningful) $meaningful = $parts;
    if (count($meaningful) === 1) return project_client_initials($meaningful[0]);
    $initials = '';
    foreach (array_slice($meaningful, 0, 3) as $part) {
        $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
}

function project_organization_job_prefix(string $organizationName, string $contactName): string {
    return project_organization_initials($organizationName) . '-' . project_contact_initials($contactName);
}

function project_next_organization_sequence(PDO $pdo, int $organizationId): int {
    $counterType = 'job';
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        // project_id=0 makes the existing composite unique key effective; NULL values are not unique in MySQL.
        $pdo->prepare('INSERT INTO project_counters (organization_id,project_id,counter_type,counter_value) VALUES (?,0,?,1) ON DUPLICATE KEY UPDATE counter_value=counter_value')
            ->execute([$organizationId, $counterType]);
        $select = $pdo->prepare('SELECT counter_value FROM project_counters WHERE organization_id=? AND project_id=0 AND counter_type=? FOR UPDATE');
        $select->execute([$organizationId, $counterType]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Unable to initialize the organization job counter.');
        $sequence = (int)$row['counter_value'];
        $pdo->prepare('UPDATE project_counters SET counter_value=counter_value+1 WHERE organization_id=? AND project_id=0 AND counter_type=?')->execute([$organizationId, $counterType]);
        if ($ownTx) $pdo->commit();
        return $sequence;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function project_code_exists(PDO $pdo, string $code): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM project_meta WHERE project_code=? UNION SELECT 1 FROM quotes WHERE project_code=? UNION SELECT 1 FROM contracts WHERE project_code=? UNION SELECT 1 FROM invoices WHERE project_code=? LIMIT 1');
    $stmt->execute([$code, $code, $code, $code]);
    return (bool)$stmt->fetchColumn();
}

function project_next_code(PDO $pdo, int $client_id): string {
    // Organization contacts use ORG-CONTACT-####. Standalone clients keep the legacy initials-year sequence.
    $st = $pdo->prepare('SELECT c.name,c.organization_id,o.name AS organization_name FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.id=?');
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    $name = $client['name'] ?? '';
    if (!empty($client['organization_id']) && trim((string)($client['organization_name'] ?? '')) !== '') {
        $organizationName = (string)$client['organization_name'];
        $prefix = project_organization_job_prefix($organizationName, $name);
        try {
            do {
                $seq = project_next_organization_sequence($pdo, (int)$client['organization_id']);
                $code = sprintf('%s-%04d', $prefix, $seq);
            } while (project_code_exists($pdo, $code));
            return $code;
        } catch (Throwable $e) {
            error_log('[project_next_code] Organization sequence error: ' . $e->getMessage());
            return sprintf('%s-%04d', $prefix, (int)substr((string)round(microtime(true) * 1000), -4));
        }
    }
    $initials = project_client_initials($name);
    $year = date('Y');
    $prefix = $initials . '-' . $year; // e.g., PA-2025

    // Always work within a transaction (either existing or new)
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    
    try {
        // Try to lock and update the counter
        $sel = $pdo->prepare('SELECT counter_value FROM project_counters WHERE organization_id=0 AND project_id IS NULL AND counter_type=? FOR UPDATE');
        $sel->execute([$prefix]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Counter exists, increment it
            $seq = (int)$row['counter_value'];
            $upd = $pdo->prepare('UPDATE project_counters SET counter_value=counter_value+1 WHERE organization_id=0 AND project_id IS NULL AND counter_type=?');
            $upd->execute([$prefix]);
        } else {
            // Counter doesn't exist, create it
            $seq = 1;
            try {
                $ins = $pdo->prepare('INSERT INTO project_counters (organization_id, project_id, counter_type, counter_value) VALUES (0, NULL, ?, 2) ON DUPLICATE KEY UPDATE counter_value=counter_value');
                $ins->execute([$prefix]);
            } catch (Throwable $insertErr) {
                // Handle race condition where another request inserted first
                $sel->execute([$prefix]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $seq = (int)$row['counter_value'];
                    $upd = $pdo->prepare('UPDATE project_counters SET counter_value=counter_value+1 WHERE organization_id=0 AND project_id IS NULL AND counter_type=?');
                    $upd->execute([$prefix]);
                }
            }
        }
        
        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[project_next_code] Error: ' . $e->getMessage());
        // Fallback: use timestamp-based sequence
        $seq = (int)substr(microtime(true) * 1000, -4);
    }

    return sprintf('%s-%04d', $prefix, $seq); // e.g., PA-2025-0001
}
