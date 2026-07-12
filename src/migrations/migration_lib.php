<?php

declare(strict_types=1);

/**
 * Discover the immutable, sequential SQL migration history.
 *
 * @return array<int, array{version:int,filename:string,path:string,checksum:string,checksums:list<string>}>
 */
function migration_files(string $directory): array
{
    $paths = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($paths, SORT_STRING);

    $files = [];
    $expected = 1;
    foreach ($paths as $path) {
        $filename = basename($path);
        if (!preg_match('/^(\d{4})_[a-z0-9]+(?:_[a-z0-9]+)*\.sql$/', $filename, $matches)) {
            throw new RuntimeException("Invalid migration filename '$filename'; expected 0001_description.sql.");
        }
        if (str_ends_with($filename, '_rollback.sql')) {
            throw new RuntimeException("Rollback migration '$filename' is not permitted in the forward migration directory.");
        }

        $version = (int) $matches[1];
        if ($version !== $expected) {
            throw new RuntimeException(sprintf(
                'Migration sequence is not contiguous: expected %04d, found %04d (%s).',
                $expected,
                $version,
                $filename
            ));
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration '$filename' is unreadable or empty.");
        }

        $normalizedLf = str_replace(["\r\n", "\r"], "\n", $sql);
        $normalizedCrlf = str_replace("\n", "\r\n", $normalizedLf);
        $checksums = array_values(array_unique([
            hash('sha256', $sql),
            hash('sha256', $normalizedLf),
            hash('sha256', $normalizedCrlf),
        ]));

        $files[$version] = [
            'version' => $version,
            'filename' => $filename,
            'path' => $path,
            'checksum' => $checksums[0],
            'checksums' => $checksums,
        ];
        $expected++;
    }

    return $files;
}

/**
 * Split ordinary MySQL migration SQL without interpreting semicolons inside
 * strings, identifiers, or comments. DELIMITER/procedure migrations are
 * intentionally unsupported; application migrations must remain plain SQL.
 *
 * @return list<string>
 */
function migration_statements(string $sql): array
{
    if (preg_match('/^\s*DELIMITER\b/im', $sql)) {
        throw new RuntimeException('DELIMITER blocks are not supported in Project Alpha migrations.');
    }

    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= $char;
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }

        if ($quote === null) {
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
        } else {
            if ($char === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }

        $buffer .= $char;
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('Migration contains an unterminated quote or block comment.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

/** @return array<int, array{version:int,filename:string,checksum:?string}> */
function migration_ledger(PDO $pdo): array
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
    )->fetchColumn();
    if ((int) $exists !== 1) {
        throw new RuntimeException(
            'schema_migrations is missing. This is not a Project Alpha 0.5.0 database; perform the documented destructive reinstall.'
        );
    }

    $rows = $pdo->query(
        'SELECT version, filename, checksum FROM schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_ASSOC);
    $ledger = [];
    foreach ($rows as $row) {
        $version = (int) $row['version'];
        $ledger[$version] = [
            'version' => $version,
            'filename' => (string) $row['filename'],
            'checksum' => $row['checksum'] === null ? null : (string) $row['checksum'],
        ];
    }

    if (!isset($ledger[0]) || $ledger[0]['filename'] !== 'baseline.sql') {
        throw new RuntimeException('The 0.5.0 baseline marker is missing or invalid.');
    }

    return $ledger;
}

/**
 * @param array<int, array{version:int,filename:string,path:string,checksum:string,checksums?:list<string>}> $files
 * @param array<int, array{version:int,filename:string,checksum:?string}> $ledger
 */
function migration_validate_history(array $files, array $ledger): void
{
    $expectedLedgerVersion = 0;
    foreach ($ledger as $version => $row) {
        if ($version !== $expectedLedgerVersion) {
            throw new RuntimeException("Applied migration ledger has a gap before version $version.");
        }
        if ($version > 0) {
            if (!isset($files[$version])) {
                throw new RuntimeException(sprintf('Applied migration %04d is missing from disk.', $version));
            }
            $file = $files[$version];
            if ($row['filename'] !== $file['filename']) {
                throw new RuntimeException("Applied migration filename differs for version $version.");
            }
            $acceptedChecksums = $file['checksums'] ?? [$file['checksum']];
            $matchesChecksum = false;
            foreach ($acceptedChecksums as $checksum) {
                if (hash_equals((string) $row['checksum'], $checksum)) {
                    $matchesChecksum = true;
                    break;
                }
            }
            if (!$matchesChecksum) {
                throw new RuntimeException("Checksum mismatch for applied migration {$file['filename']}.");
            }
        }
        $expectedLedgerVersion++;
    }
}

function migration_connection(): PDO
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';

    return new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function migration_schema_health(PDO $pdo): void
{
    $requiredTables = [
        'users', 'organizations', 'roles', 'role_permissions',
        'clients', 'organization_departments', 'organization_department_contacts',
        'projects', 'quotes', 'contracts', 'invoices', 'payments',
        'api_keys', 'client_onboarding_invitations', 'client_onboarding_submissions',
        'pa_integration_identity', 'alphaledger_policy', 'alphaledger_installations', 'alphaledger_events',
        'alphaledger_idempotency', 'alphaledger_project_assignments', 'employee_pay_records',
        'alphaledger_received_events',
        'rate_limits', 'schema_migrations',
    ];
    $deadTables = [
        'contract_notes', 'quote_history', 'contract_history', 'invoice_history',
        'recurring_invoices', 'recurring_invoice_items', 'webhook_deliveries',
        'notification_settings', 'notification_log', 'document_custom_field_values',
        'financial_records', 'migrations',
    ];

    $present = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $presentMap = array_fill_keys($present, true);
    $issues = [];
    foreach ($requiredTables as $table) {
        if (!isset($presentMap[$table])) {
            $issues[] = "missing table $table";
        }
    }
    foreach ($deadTables as $table) {
        if (isset($presentMap[$table])) {
            $issues[] = "legacy table still present: $table";
        }
    }

    $requiredColumns = [
        'users' => ['email', 'password_hash', 'role', 'force_password_reset', 'auth_version', 'totp_reenroll_required'],
        'clients' => ['organization_id', 'created_by'],
        'projects' => ['organization_id', 'department_id', 'created_by'],
        'project_clients' => ['client_id', 'send_project_invoices', 'can_view_invoice_links'],
        'entity_links' => ['include_on_invoices', 'resolver_mode', 'visibility_scope'],
        'quotes' => ['organization_id', 'created_by'],
        'contracts' => ['organization_id', 'created_by'],
        'invoices' => ['organization_id', 'created_by', 'collection_mode'],
        'api_keys' => ['name', 'key_prefix', 'key_hash', 'scopes', 'allowed_ips', 'created_at', 'last_used_at', 'revoked_at'],
        'api_usage' => ['api_key_id', 'used_at'],
        'client_onboarding_invitations' => ['organization_id', 'invited_email', 'token_hash', 'status'],
        'client_onboarding_submissions' => ['invitation_id', 'proposed_data', 'status'],
        'team_members' => ['user_id','display_name','email','is_active','profile_source','last_synced_at'],
        'time_entries' => ['source_system', 'external_id', 'external_revision', 'external_status','team_member_id','al_business_id','source_entry_id','source_updated_at','cost_rate_snapshot','billing_rate_snapshot'],
        'alphaledger_policy' => ['enabled', 'approved_api_key_id', 'approved_callback_url', 'approved_callback_hash', 'allow_unrestricted_key'],
        'alphaledger_installations' => ['installation_id','al_business_id','api_key_id','callback_url','command_api_url','capabilities','webhook_secret_enc','status'],
        'alphaledger_events' => ['installation_id', 'event_id', 'event_type', 'envelope', 'delivery_state'],
        'alphaledger_received_events' => ['installation_id', 'event_id', 'event_type', 'aggregate_id', 'result'],
        'employee_pay_records' => ['installation_id','external_id','external_revision','user_id','team_member_id','amount','status'],
        'alphaledger_employee_mappings' => ['al_business_id','al_employee_id','team_member_id'],
        'alphaledger_project_mappings' => ['al_business_id','al_project_id','project_id'],
        'alphaledger_team_assignments' => ['project_id','team_member_id','created_by'],
        'alphaledger_command_outbox' => ['operation_id','idempotency_key','operation_type','payload_enc','state'],
        'alphaledger_integration_exceptions' => ['exception_type','source_object_id','reason','status'],
    ];
    $columnQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnQuery->execute([$table, $column]);
            if ((int) $columnQuery->fetchColumn() !== 1) {
                $issues[] = "missing column $table.$column";
            }
        }
    }

    if ($issues !== []) {
        throw new RuntimeException('Schema health check failed: ' . implode('; ', $issues));
    }
}
