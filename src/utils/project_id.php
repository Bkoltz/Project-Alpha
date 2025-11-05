<?php
// src/utils/project_id.php
// TODO: We need to add logic, so that all related long-term invoices show in the same project as the corresponding contract. We don't need to list all invoices in the project list view, but we need to add a way to let the user know there are multiple invoices for that project.
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

function project_next_code(PDO $pdo, int $client_id): string {
    // Fetch client name
    $st = $pdo->prepare('SELECT name FROM clients WHERE id=?');
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    $name = $client['name'] ?? '';
    $initials = project_client_initials($name);
    $year = date('Y');
    $prefix = $initials . '-' . $year; // e.g., PA-2025

    // Always work within a transaction (either existing or new)
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    
    try {
        // Try to lock and update the counter
        $sel = $pdo->prepare('SELECT next_seq FROM project_counters WHERE prefix=? FOR UPDATE');
        $sel->execute([$prefix]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            // Counter exists, increment it
            $seq = (int)$row['next_seq'];
            $upd = $pdo->prepare('UPDATE project_counters SET next_seq=next_seq+1 WHERE prefix=?');
            $upd->execute([$prefix]);
        } else {
            // Counter doesn't exist, create it
            $seq = 1;
            try {
                $ins = $pdo->prepare('INSERT INTO project_counters (prefix, next_seq) VALUES (?, 2) ON DUPLICATE KEY UPDATE next_seq=next_seq');
                $ins->execute([$prefix]);
            } catch (Throwable $insertErr) {
                // Handle race condition where another request inserted first
                $sel->execute([$prefix]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $seq = (int)$row['next_seq'];
                    $upd = $pdo->prepare('UPDATE project_counters SET next_seq=next_seq+1 WHERE prefix=?');
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
