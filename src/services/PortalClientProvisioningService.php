<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/**
 * Reconciles Project Alpha's client directory into portal invitation intent.
 *
 * This service never binds an external identity and never grants a delivery
 * folder.  It only publishes an eligible principal and three least-privilege
 * portal capabilities.  The receiver must still verify the identity and an
 * operator must still grant content.
 */
final class PortalClientProvisioningService
{
    public const DEFAULT_CAPABILITIES = ['workspace.view', 'directory.read', 'delivery.view'];
    private const BOUND_PROFILE_KEY = 'external_ops_client_portal_profile_id';

    /**
     * Keep the portal producer behind the same administrator-facing connection
     * while retaining a distinct signed-delivery contract internally.
     *
     * @param array<string,mixed> $externalConfig
     */
    public function configureConnection(PDO $pdo, array $externalConfig, int $actorId): ?int
    {
        $applicationKey = trim((string)($externalConfig['application_key'] ?? ''));
        $requestedEnabled = !empty($externalConfig['configured_enabled']) || !empty($externalConfig['enabled']);
        $receiver = $this->portalReceiver((string)($externalConfig['webhook_url'] ?? ''));
        $existing = $this->boundProfile($pdo);
        if (!$existing && $applicationKey !== '') $existing = $this->profile($pdo, $applicationKey);
        if (!$existing) {
            $active = $pdo->query('SELECT * FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            if (count($active) > 1) throw new DomainException('More than one client portal producer is active. Retire the legacy profiles before saving this connection.');
            if (count($active) === 1) $existing = $active[0];
        }
        if ($existing) $this->bindProfile($pdo, (int)$existing['id']);

        $contractChanged = $existing && (
            !hash_equals((string)$existing['application_key'], $applicationKey)
            || !hash_equals(trim((string)($existing['portal_route'] ?? '')), $receiver)
        );
        $retiredState=$existing&&(empty($existing['enabled'])||empty($existing['portal_projection_enabled']));
        if ($existing && !$retiredState && (!$requestedEnabled || $contractChanged)) {
            $this->retireProfile($pdo, $existing, $actorId, $requestedEnabled ? 'connection_contract_changed' : 'connection_disabled');
            $existing = $this->profileById($pdo, (int)$existing['id']);
            $retiredState=true;
        }
        if (!$requestedEnabled || $applicationKey === '' || $receiver === '') return $existing ? (int)$existing['id'] : null;
        if($existing&&$retiredState){
            if($this->undeliveredCount($pdo,(int)$existing['id'])>0)return(int)$existing['id'];
            $this->resolveRetiredNormalRows($pdo,(int)$existing['id'],$actorId);
        }
        $this->assertOnlyProducer($pdo, $existing ? (int)$existing['id'] : null);

        $keyId = $existing ? trim((string)($existing['delivery_key_id'] ?? '')) : '';
        $capability = $this->portalCapability($applicationKey);
        $rekeying = $existing && !hash_equals((string)$existing['application_key'], $applicationKey);
        if ($keyId === '' || $rekeying) $keyId = $capability['keyId'];
        $secret = $capability['secret'];
        $storedSecret = $existing ? (new PortalProjectionDeliveryConfigService())->credentials($existing, false)['currentSecret'] : '';
        if ($storedSecret !== '' && !$rekeying) $secret = '';
        // A connection can continue serving business synchronization while its
        // independently signed portal producer remains visibly paused.
        if ($keyId === '' || ($secret === '' && $storedSecret === '')) return $existing ? (int)$existing['id'] : null;

        // PortalAuthorityService deliberately refuses to mutate a delivery
        // contract while any unresolved outbox row remains. Rows superseded by
        // the retirement itself are an administrative resolution, not work to
        // send, so close only those rows after every live row and revocation
        // has drained. This preserves the immutable old-host delivery contract
        // without allowing a dead-lettered normal event to block replacement.
        $authority = new PortalAuthorityService();
        $profileId = $authority->saveProfile($pdo, [
            'profile_id' => $existing ? (int)$existing['id'] : 0,
            'application_key' => $applicationKey,
            'display_label' => trim((string)($externalConfig['label'] ?? 'External application')) ?: 'External application',
            'enabled' => 1,
            'portal_projection_enabled' => 1,
            'relation_projection_enabled' => 1,
            'catalog_projection_enabled' => !empty($existing['catalog_projection_enabled']),
            'pricing_preview_enabled' => !empty($existing['pricing_preview_enabled']),
            'draft_quote_enabled' => !empty($existing['draft_quote_enabled']),
            'pricing_source' => $existing['pricing_source'] ?? null,
            'draft_source' => $existing['draft_source'] ?? null,
            'portal_route' => $receiver,
            'catalog_route' => $existing['catalog_route'] ?? null,
        ], $actorId);

        $headers = [];
        $accessId = trim((string)($externalConfig['access_client_id'] ?? ''));
        $accessSecret = trim((string)($externalConfig['access_client_secret'] ?? ''));
        if ($accessId !== '' && $accessSecret !== '') {
            $headers = ['CF-Access-Client-Id' => $accessId, 'CF-Access-Client-Secret' => $accessSecret];
        }
        (new PortalProjectionDeliveryConfigService())->saveProfile($pdo, $profileId, [
            'delivery_enabled' => 1,
            'delivery_key_id' => $keyId,
            'delivery_secret' => $secret,
            'delivery_auth_headers_json' => json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'delivery_timeout_seconds' => (int)($externalConfig['timeout_seconds'] ?? 15),
            'delivery_max_attempts' => (int)($externalConfig['max_attempts'] ?? 12),
        ], $actorId);
        (new PortalProjectionDeliveryConfigService())->saveRuntime($pdo, [
            'outbound_enabled' => 1,
            'hooks_enabled' => 1,
        ]);
        $this->audit($pdo, $profileId, 'portal.client_provisioning.connection_configured', 'profile', (string)$profileId, [
            'actor_id' => $actorId,
            'receiver_host' => (string)(parse_url($receiver, PHP_URL_HOST) ?: ''),
        ]);
        $this->bindProfile($pdo, $profileId);
        return $profileId;
    }

    /** @return array<string,mixed> */
    public function status(PDO $pdo, string $applicationKey): array
    {
        $profile = $this->boundProfile($pdo);
        if (!$profile && $applicationKey !== '') $profile = $this->profile($pdo, $applicationKey);
        $counts = ['active_roots'=>0,'revoked_roots'=>0,'eligible'=>0,'review_required'=>0,'revoked'=>0,'active_workspaces'=>0,'pending'=>0,'failed'=>0,'failed_revocations'=>0];
        if (!$profile) return ['configured'=>false,'ready'=>false,'profile'=>null,'counts'=>$counts,'transition_state'=>'unpaired','transition_message'=>null];
        foreach ([
            'active_roots' => "SELECT COUNT(*) FROM portal_client_access_roots WHERE access_state='active'",
            'revoked_roots' => "SELECT COUNT(*) FROM portal_client_access_roots WHERE access_state='revoked'",
            'eligible' => "SELECT COUNT(*) FROM portal_client_login_eligibility WHERE eligibility_status='eligible'",
            'review_required' => "SELECT COUNT(*) FROM portal_client_login_eligibility WHERE eligibility_status='review_required'",
            'revoked' => "SELECT COUNT(*) FROM portal_client_login_eligibility WHERE eligibility_status='revoked'",
            'active_workspaces' => 'SELECT COUNT(*) FROM portal_integration_profile_workspaces pw JOIN portal_v2_workspaces w ON w.id=pw.workspace_id WHERE pw.profile_id='.(int)$profile['id'].' AND pw.active=1 AND w.active=1',
            'pending' => 'SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id='.(int)$profile['id'].' AND delivered_at IS NULL AND dead_lettered_at IS NULL',
            'failed' => 'SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id='.(int)$profile['id'].' AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL',
            'failed_revocations' => 'SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id='.(int)$profile['id'].' AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL AND is_revocation=1',
        ] as $key => $sql) $counts[$key] = (int)$pdo->query($sql)->fetchColumn();
        $delivery = new PortalProjectionDeliveryConfigService();
        $runtime = $delivery->runtime($pdo);
        $credentials = $delivery->credentials($profile, false);
        $ready = !empty($profile['enabled']) && !empty($profile['portal_projection_enabled'])
            && !empty($profile['delivery_enabled']) && trim((string)($profile['portal_route'] ?? '')) !== ''
            && trim((string)($profile['delivery_key_id'] ?? '')) !== '' && $credentials['currentSecret'] !== ''
            && $runtime['outbound_enabled'] && $runtime['hooks_enabled'];
        $config=(new ExternalOpsConfigService())->load($pdo);$desiredReceiver=$this->portalReceiver((string)($config['webhook_url']??''));
        $changed=!hash_equals((string)$profile['application_key'],(string)($config['application_key']??$applicationKey))||!hash_equals(trim((string)($profile['portal_route']??'')),$desiredReceiver);
        $undelivered=$this->undeliveredCount($pdo,(int)$profile['id']);$transition='stable';$transitionMessage=null;
        if(empty($config['configured_enabled'])){$transition=$undelivered>0?'retiring':'disabled';$transitionMessage=$undelivered>0?'Portal revocations are draining before this connection is fully retired.':'Client portal provisioning is disabled with the external connection.';}
        elseif($changed||!$ready){$transition=$undelivered>0?'retiring':'replacement_required';$transitionMessage=$undelivered>0?((int)$counts['failed_revocations']>0?'The previous portal contract is retired, but one or more revocations need an audited retry before replacement can activate.':'The previous portal contract is retired. Drain its queued revocations, then save the connection again to activate the replacement.'):'The previous portal contract is retired. Save the connection again to activate its replacement.';}
        return ['configured'=>true,'ready'=>$ready,'profile'=>$profile,'counts'=>$counts,'transition_state'=>$transition,'transition_message'=>$transitionMessage];
    }

    public function retryFailedRevocations(PDO $pdo,string $applicationKey,int $actorId):int
    {
        $profile=$this->boundProfile($pdo);
        if(!$profile&&$applicationKey!=='')$profile=$this->profile($pdo,$applicationKey);
        if(!$profile)throw new DomainException('The client portal connection is not configured.');
        $owns=!$pdo->inTransaction();
        try{
            if($owns)$pdo->beginTransaction();
            PortalProjectionService::lockProfileContract($pdo,(int)$profile['id']);
            $errors=$pdo->prepare('SELECT COALESCE(last_error_code,\'unknown\') error_code,COUNT(*) error_count FROM portal_projection_outbox WHERE integration_profile_id=? AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL AND is_revocation=1 GROUP BY COALESCE(last_error_code,\'unknown\') ORDER BY error_code');
            $errors->execute([(int)$profile['id']]);
            $errorCounts=[];$total=0;
            foreach($errors->fetchAll(PDO::FETCH_ASSOC)as$row){$count=(int)$row['error_count'];$total+=$count;$errorCounts[(string)$row['error_code']]=$count;}
            if($total<1)throw new DomainException('There are no failed client portal revocations to retry.');
            $retry=$pdo->prepare("UPDATE portal_projection_outbox SET attempts=0,next_attempt_at=CURRENT_TIMESTAMP,claim_token=NULL,claimed_at=NULL,dead_lettered_at=NULL,last_http_status=NULL,last_error_code='revocation_retry_requested' WHERE integration_profile_id=? AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL AND is_revocation=1");
            $retry->execute([(int)$profile['id']]);
            $this->audit($pdo,(int)$profile['id'],'portal.client_provisioning.revocations_requeued','profile',(string)$profile['id'],['actor_id'=>$actorId,'retry_count'=>$total,'prior_error_counts'=>$errorCounts]);
            if($owns)$pdo->commit();
            return$total;
        }catch(Throwable$error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    /**
     * Idempotently reconcile every current or previously managed root and queue
     * only a first snapshot or changed resources.
     *
     * @return array<string,int>
     */
    public function reconcileAll(PDO $pdo, string $applicationKey, int $actorId): array
    {
        $profile = $this->requireReadyProfile($pdo, $applicationKey);
        $owns = !$pdo->inTransaction();
        try {
            if ($owns) $pdo->beginTransaction();
            $scopes = $this->allScopes($pdo);
            if (count($scopes) > 1000) throw new DomainException('Client portal reconciliation is limited to 1000 roots per run.');
            $summary = $this->ensureScopes($pdo, $scopes, $actorId, $profile);
            (new PortalProjectionMutationService())->afterMutation($pdo, $scopes, true);
            $this->audit($pdo, (int)$profile['id'], 'portal.client_provisioning.reconciled', 'profile', (string)$profile['id'], $summary + ['actor_id'=>$actorId]);
            if ($owns) $pdo->commit();
            return $summary;
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Ensure workspace and principal state without publishing.  The mutation
     * service publishes after it has reconciled the complete relationship graph.
     *
     * @param list<array{root_type:string,root_public_id:string}> $scopes
     * @param array<string,mixed>|false|null $profile
     * @return array<string,int>
     */
    public function ensureScopes(PDO $pdo, array $scopes, int $actorId = 0, array|false|null $profile = null): array
    {
        if (!$pdo->inTransaction()) throw new DomainException('Client portal reconciliation requires a transaction.');
        if ($profile === null) {
            $profile = $this->boundProfile($pdo);
        }
        if (!$profile || empty($profile['enabled']) || empty($profile['portal_projection_enabled'])) {
            return ['roots'=>0,'workspaces'=>0,'eligible'=>0,'review_required'=>0,'revoked'=>0];
        }

        $summary = ['roots'=>0,'workspaces'=>0,'eligible'=>0,'review_required'=>0,'revoked'=>0];
        foreach ($this->uniqueScopes($scopes) as $scope) {
            $summary['roots']++;
            $root = $this->rootRecord($pdo, $scope['root_type'], $scope['root_public_id']);
            $control = $this->rootControl($pdo, $scope['root_type'], $scope['root_public_id']);
            if (!$control && $root) {
                $this->upsertRootControl($pdo, $scope['root_type'], $scope['root_public_id'], 'active', null, $actorId);
                $control = ['access_state'=>'active'];
            }
            $rootActive = $root !== null && (($control['access_state'] ?? 'active') === 'active');
            if ($rootActive) {
                $this->ensureWorkspace($pdo, (int)$profile['id'], $scope, (string)$root['name'], $actorId);
                $summary['workspaces']++;
            }
            foreach ($this->rootClients($pdo, $scope, false) as $client) {
                $status = $this->reconcileClient($pdo, $client, $scope, $rootActive, $actorId);
                $summary[$status]++;
            }
            $this->touchRoot($pdo, $scope['root_type'], $scope['root_public_id']);
        }
        return $summary;
    }

    public function setRootAccess(PDO $pdo, string $applicationKey, string $rootType, string $rootPublicId, bool $active, int $actorId): void
    {
        if (!in_array($rootType, ['organization','standalone_client'], true) || $rootPublicId === '') throw new DomainException('Client portal root is invalid.');
        $profile = $this->requireReadyProfile($pdo, $applicationKey);
        $owns = !$pdo->inTransaction();
        try {
            if ($owns) $pdo->beginTransaction();
            $this->upsertRootControl($pdo, $rootType, $rootPublicId, $active ? 'active' : 'revoked', $active ? null : 'administrator_revoked', $actorId);
            $scope = ['root_type'=>$rootType,'root_public_id'=>$rootPublicId];
            $this->ensureScopes($pdo, [$scope], $actorId, $profile);
            (new PortalProjectionMutationService())->afterMutation($pdo, [$scope], true);
            $this->audit($pdo, (int)$profile['id'], $active?'portal.client_root.restored':'portal.client_root.revoked', $rootType, $rootPublicId, ['actor_id'=>$actorId]);
            if ($owns) $pdo->commit();
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function setClientAccess(PDO $pdo, string $applicationKey, int $clientId, bool $active, int $actorId): void
    {
        $profile = $this->requireReadyProfile($pdo, $applicationKey);
        $scope = (new PortalProjectionMutationService())->clientScopes($pdo, $clientId);
        if ($scope === []) throw new DomainException('Client portal root was not found.');
        $owns = !$pdo->inTransaction();
        try {
            if ($owns) $pdo->beginTransaction();
            $existing = $this->eligibility($pdo, $clientId);
            $this->saveEligibility($pdo, $clientId, $existing['portal_principal_id'] ?? null, $active?'automatic':'revoked', $active?'review_required':'revoked', $active?'missing_email':'none', $existing['canonical_email'] ?? null, (string)($existing['source_version'] ?? PortalSourceVersion::from(['clientId'=>$clientId])), $actorId);
            $this->ensureScopes($pdo, $scope, $actorId, $profile);
            (new PortalProjectionMutationService())->afterMutation($pdo, $scope, true);
            $client = $pdo->prepare('SELECT public_id FROM clients WHERE id=?');$client->execute([$clientId]);
            $this->audit($pdo, (int)$profile['id'], $active?'portal.client_login.restored':'portal.client_login.revoked', 'client', (string)($client->fetchColumn() ?: $clientId), ['actor_id'=>$actorId]);
            if ($owns) $pdo->commit();
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function rootDirectory(PDO $pdo, string $applicationKey, int $limit = 100): array
    {
        $profile = $this->profile($pdo, $applicationKey);
        if (!$profile) return [];
        $limit = max(1, min(250, $limit));
        $sql = "SELECT r.root_type,r.root_public_id,r.access_state,r.state_reason,r.last_reconciled_at,w.public_id workspace_public_id,w.display_name,w.active workspace_active,
            SUM(CASE WHEN e.eligibility_status='eligible' THEN 1 ELSE 0 END) eligible_count,
            SUM(CASE WHEN e.eligibility_status='review_required' THEN 1 ELSE 0 END) review_count,
            SUM(CASE WHEN e.eligibility_status='revoked' THEN 1 ELSE 0 END) revoked_count
            FROM portal_client_access_roots r
            LEFT JOIN portal_v2_workspaces w ON w.root_type=r.root_type AND w.root_public_id=r.root_public_id
            LEFT JOIN clients c ON (r.root_type='organization' AND c.organization_id=(SELECT o.id FROM organizations o WHERE o.public_id=r.root_public_id)) OR (r.root_type='standalone_client' AND c.public_id=r.root_public_id AND c.organization_id IS NULL)
            LEFT JOIN portal_client_login_eligibility e ON e.client_id=c.id
            GROUP BY r.root_type,r.root_public_id,r.access_state,r.state_reason,r.last_reconciled_at,w.public_id,w.display_name,w.active
            ORDER BY w.display_name,r.root_public_id LIMIT {$limit}";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|false */
    private function requireReadyProfile(PDO $pdo, string $applicationKey): array
    {
        $profile = $this->boundProfile($pdo);
        if (!$profile) $profile = $this->profile($pdo, $applicationKey);
        if (!$profile || empty($profile['enabled']) || empty($profile['portal_projection_enabled'])) throw new DomainException('Configure the client portal receiver on the external application connection first.');
        return $profile;
    }

    /** @return array<string,mixed>|false */
    private function profile(PDO $pdo, string $applicationKey): array|false
    {
        if ($applicationKey === '') return false;
        $statement = $pdo->prepare('SELECT * FROM portal_integration_profiles WHERE application_key=?');
        $statement->execute([$applicationKey]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|false */
    private function profileById(PDO $pdo,int $profileId):array|false{$s=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=?');$s->execute([$profileId]);return$s->fetch(PDO::FETCH_ASSOC);}
    /** @return array<string,mixed>|false */
    private function boundProfile(PDO $pdo):array|false{$s=$pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=?');$s->execute([self::BOUND_PROFILE_KEY]);$id=(int)($s->fetchColumn()?:0);return$id>0?$this->profileById($pdo,$id):false;}
    private function bindProfile(PDO $pdo,int $profileId):void{$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO app_config(organization_id,config_key,config_value)VALUES(0,?,?) ON CONFLICT(organization_id,config_key)DO UPDATE SET config_value=excluded.config_value':'INSERT INTO app_config(organization_id,config_key,config_value)VALUES(0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';$pdo->prepare($sql)->execute([self::BOUND_PROFILE_KEY,(string)$profileId]);}
    private function undeliveredCount(PDO $pdo,int $profileId):int{$s=$pdo->prepare('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=? AND delivered_at IS NULL AND (is_revocation=1 OR dead_lettered_at IS NULL)');$s->execute([$profileId]);return(int)$s->fetchColumn();}
    private function resolveRetiredNormalRows(PDO $pdo,int $profileId,int $actorId):void
    {
        // Once every live delivery and revocation has drained, old normal rows
        // cannot be replayed against a replacement route or signing contract.
        // Mark every dead-lettered normal row administratively resolved while
        // retaining its dead-letter timestamp and original error for audit.
        $s=$pdo->prepare('UPDATE portal_projection_outbox SET delivered_at=COALESCE(delivered_at,CURRENT_TIMESTAMP) WHERE integration_profile_id=? AND delivered_at IS NULL AND is_revocation=0 AND dead_lettered_at IS NOT NULL');
        $s->execute([$profileId]);
        $resolved=$s->rowCount();
        if($resolved>0)$this->audit($pdo,$profileId,'portal.client_provisioning.retired_events_resolved','profile',(string)$profileId,[
            'actor_id'=>$actorId,
            'resolved_count'=>$resolved,
            'resolution'=>'replacement_contract_activation',
        ]);
    }
    private function assertOnlyProducer(PDO $pdo,?int $profileId):void{$sql='SELECT COUNT(*) FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1'.($profileId!==null?' AND id<>?':'');$s=$pdo->prepare($sql);$s->execute($profileId!==null?[$profileId]:[]);if((int)$s->fetchColumn()>0)throw new DomainException('Another client portal producer is active. Retire and drain it before activating this connection.');}
    /** @param array<string,mixed> $profile */
    private function retireProfile(PDO $pdo,array $profile,int $actorId,string $reason):void
    {
        if(!empty($profile['enabled'])||!empty($profile['portal_projection_enabled']))(new PortalAuthorityService())->saveProfile($pdo,[
            'profile_id'=>(int)$profile['id'],'application_key'=>(string)$profile['application_key'],'display_label'=>(string)$profile['display_label'],
            'enabled'=>0,'portal_projection_enabled'=>0,
            'relation_projection_enabled'=>!empty($profile['relation_projection_enabled']),
            'catalog_projection_enabled'=>!empty($profile['catalog_projection_enabled']),
            'pricing_preview_enabled'=>!empty($profile['pricing_preview_enabled']),
            'draft_quote_enabled'=>!empty($profile['draft_quote_enabled']),
            'pricing_source'=>$profile['pricing_source']??null,'draft_source'=>$profile['draft_source']??null,
            'portal_route'=>$profile['portal_route']??null,'catalog_route'=>$profile['catalog_route']??null,
        ],$actorId);
        (new PortalProjectionDeliveryConfigService())->saveRuntime($pdo,['outbound_enabled'=>1,'hooks_enabled'=>1]);
        $this->audit($pdo,(int)$profile['id'],'portal.client_provisioning.connection_retired','profile',(string)$profile['id'],['actor_id'=>$actorId,'reason'=>$reason,'undelivered'=>$this->undeliveredCount($pdo,(int)$profile['id'])]);
    }

    /** @return list<array{root_type:string,root_public_id:string}> */
    private function allScopes(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT 'organization' root_type,o.public_id root_public_id FROM organizations o WHERE EXISTS(SELECT 1 FROM clients c WHERE c.organization_id=o.id AND c.archived=0 AND c.deleted_at IS NULL)
            UNION SELECT 'standalone_client',c.public_id FROM clients c WHERE c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL
            UNION SELECT root_type,root_public_id FROM portal_client_access_roots
            UNION SELECT root_type,root_public_id FROM portal_v2_workspaces
            ORDER BY root_type,root_public_id")->fetchAll(PDO::FETCH_ASSOC);
        return $this->uniqueScopes($rows);
    }

    /** @return array<string,mixed>|null */
    private function rootRecord(PDO $pdo, string $rootType, string $rootId): ?array
    {
        $sql = $rootType === 'organization'
            ? 'SELECT o.id,o.name FROM organizations o WHERE o.public_id=? AND EXISTS(SELECT 1 FROM clients c WHERE c.organization_id=o.id AND c.archived=0 AND c.deleted_at IS NULL)'
            : 'SELECT c.id,c.name FROM clients c WHERE c.public_id=? AND c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL';
        $statement = $pdo->prepare($sql);$statement->execute([$rootId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function rootClients(PDO $pdo, array $scope, bool $activeOnly): array
    {
        $active = $activeOnly ? ' AND c.archived=0 AND c.deleted_at IS NULL' : '';
        $sql = $scope['root_type'] === 'organization'
            ? 'SELECT c.* FROM clients c JOIN organizations o ON o.id=c.organization_id WHERE o.public_id=?'.$active.' ORDER BY c.id'
            : 'SELECT c.* FROM clients c WHERE c.public_id=? AND c.organization_id IS NULL'.$active.' ORDER BY c.id';
        $statement=$pdo->prepare($sql);$statement->execute([$scope['root_public_id']]);return$statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $client @param array{root_type:string,root_public_id:string} $scope */
    private function reconcileClient(PDO $pdo, array $client, array $scope, bool $rootActive, int $actorId): string
    {
        $clientId=(int)$client['id'];$existing=$this->eligibility($pdo,$clientId);$manual=(string)($existing['manual_state']??'automatic');
        $active=empty($client['archived'])&&empty($client['deleted_at']);
        $email=strtolower(trim((string)($client['email']??'')));$reason='none';$status='eligible';
        if(!$active){$status='review_required';$reason='client_inactive';}
        elseif(!$rootActive){$status='revoked';$reason='root_revoked';}
        elseif($manual==='revoked'){$status='revoked';$reason='none';}
        elseif(empty($client['organization_id'])&&!in_array((string)($client['client_type']??'unknown'),['consumer'],true)){$status='review_required';$reason='non_human_record';}
        elseif($email===''){$status='review_required';$reason='missing_email';}
        elseif(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)){$status='review_required';$reason='invalid_email';}
        elseif($this->emailCount($pdo,$email)!==1){$status='review_required';$reason='duplicate_email';}

        $principalId=isset($existing['portal_principal_id'])?(int)$existing['portal_principal_id']:null;
        $previousEmail=(string)($existing['canonical_email']??'');
        if($principalId&&$previousEmail!==''&&$email!==''&&!hash_equals($previousEmail,$email)){$this->revokePrincipal($pdo,$principalId,$actorId);$principalId=null;}
        if($status==='eligible'){
            if(!$principalId){
                $conflict=$pdo->prepare('SELECT COUNT(*) FROM portal_principals p LEFT JOIN portal_client_login_eligibility e ON e.portal_principal_id=p.id WHERE LOWER(TRIM(p.email_hint))=? AND p.enabled=1 AND p.revoked_at IS NULL AND e.client_id IS NULL');$conflict->execute([$email]);
                if((int)$conflict->fetchColumn()>0){$status='review_required';$reason='principal_conflict';}
                else{$principalId=$this->createManagedPrincipal($pdo,$email,(string)$client['name'],$actorId);}
            }
            if($status==='eligible'&&$principalId){
                $this->activatePrincipal($pdo,$principalId,$email,(string)$client['name'],$actorId);
                $this->linkPrincipalClient($pdo,$principalId,$clientId,$actorId);
                $scopeType=$scope['root_type']==='organization'?'client':'standalone_client';
                $scopeId=(string)$client['public_id'];
                foreach(self::DEFAULT_CAPABILITIES as$capability)$this->upsertEntitlement($pdo,$principalId,$capability,$scopeType,$scopeId,$actorId);
            }
        }
        if($status!=='eligible'&&$principalId)$this->revokePrincipal($pdo,$principalId,$actorId);
        $version=PortalSourceVersion::from(['clientPublicId'=>(string)$client['public_id'],'canonicalEmail'=>$status==='eligible'?$email:null,'status'=>$status,'reason'=>$reason,'manualState'=>$manual]);
        $this->saveEligibility($pdo,$clientId,$principalId,$manual,$status,$reason,$email!==''?$email:null,$version,$actorId);
        return$status;
    }

    private function emailCount(PDO $pdo,string $email):int{$s=$pdo->prepare('SELECT COUNT(*) FROM clients WHERE archived=0 AND deleted_at IS NULL AND LOWER(TRIM(email))=?');$s->execute([$email]);return(int)$s->fetchColumn();}
    private function eligibility(PDO$pdo,int$clientId):array|false{$s=$pdo->prepare('SELECT * FROM portal_client_login_eligibility WHERE client_id=?');$s->execute([$clientId]);return$s->fetch(PDO::FETCH_ASSOC);}
    private function rootControl(PDO$pdo,string$type,string$id):array|false{$s=$pdo->prepare('SELECT * FROM portal_client_access_roots WHERE root_type=? AND root_public_id=?');$s->execute([$type,$id]);return$s->fetch(PDO::FETCH_ASSOC);}

    private function ensureWorkspace(PDO$pdo,int$profileId,array$scope,string$name,int$actorId):void
    {
        $find=$pdo->prepare('SELECT id,public_id FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');$find->execute([$scope['root_type'],$scope['root_public_id']]);$workspace=$find->fetch(PDO::FETCH_ASSOC);$version=PortalSourceVersion::from(['rootType'=>$scope['root_type'],'rootPublicId'=>$scope['root_public_id'],'displayName'=>$name,'active'=>true]);
        if($workspace){$workspaceId=(int)$workspace['id'];$pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=?,active=1,updated_by=? WHERE id=?')->execute([$name,$version,$actorId?:null,$workspaceId]);}
        else{$publicId=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO portal_v2_workspaces(public_id,root_type,root_public_id,display_name,source_version,active,created_by,updated_by)VALUES(?,?,?,?,?,1,?,?)')->execute([$publicId,$scope['root_type'],$scope['root_public_id'],$name,$version,$actorId?:null,$actorId?:null]);$workspaceId=(int)$pdo->lastInsertId();}
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?'INSERT INTO portal_integration_profile_workspaces(profile_id,workspace_id,active,created_by,updated_by)VALUES(?,?,1,?,?) ON CONFLICT(profile_id,workspace_id)DO UPDATE SET active=1,updated_by=excluded.updated_by':'INSERT INTO portal_integration_profile_workspaces(profile_id,workspace_id,active,created_by,updated_by)VALUES(?,?,1,?,?) ON DUPLICATE KEY UPDATE active=1,updated_by=VALUES(updated_by)';$pdo->prepare($sql)->execute([$profileId,$workspaceId,$actorId?:null,$actorId?:null]);
    }

    private function createManagedPrincipal(PDO $pdo,string $email,string $name,int $actorId):int
    {
        $version=PortalSourceVersion::from(['emailHint'=>$email,'displayName'=>$name,'active'=>true]);
        $pdo->prepare('INSERT INTO portal_principals(email_hint,display_name,source_version,enabled,activated_at,created_by,updated_by)VALUES(?,?,?,1,CURRENT_TIMESTAMP,?,?)')
            ->execute([$email,$name,$version,$actorId?:null,$actorId?:null]);
        return (int)$pdo->lastInsertId();
    }

    private function portalReceiver(string $signedEventUrl):string
    {
        $override=trim((string)(getenv('EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL')?:''));
        $candidate=$override!==''?$override:$signedEventUrl;
        $parts=parse_url($candidate);
        $scheme=strtolower((string)($parts['scheme']??''));$host=(string)($parts['host']??'');
        if($host===''||($scheme!=='https'&&!in_array(strtolower($host),['localhost','127.0.0.1','::1'],true)))return'';
        $port=isset($parts['port'])?':'.(int)$parts['port']:'';
        return $scheme.'://'.$host.$port.'/api/internal/project-alpha/portal-v2';
    }

    /** @return array{keyId:string,secret:string} */
    private function portalCapability(string $applicationKey):array
    {
        $keyId=trim((string)(getenv('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID')?:''));
        $secret=trim((string)(getenv('EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET')?:''));
        $raw=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
        if(is_string($raw)&&trim($raw)!==''){
            try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$configured=$decoded[$applicationKey]['portal']??null;
                if(is_string($configured)){$secret=trim($configured);}
                elseif(is_array($configured)){
                    $secret=trim((string)($configured['current']??$secret));
                    $keyId=trim((string)($configured['keyId']??$configured['currentKeyId']??$keyId));
                }
            }catch(Throwable){}
        }
        if($keyId!==''&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D',$keyId)!==1)$keyId='';
        if(strlen($secret)<32||strlen($secret)>1000)$secret='';
        return['keyId'=>$keyId,'secret'=>$secret];
    }

    private function activatePrincipal(PDO$pdo,int$id,string$email,string$name,int$actorId):void{$version=PortalSourceVersion::from(['emailHint'=>$email,'displayName'=>$name,'active'=>true]);$pdo->prepare('UPDATE portal_principals SET email_hint=?,display_name=?,source_version=?,enabled=1,revoked_at=NULL,activated_at=COALESCE(activated_at,CURRENT_TIMESTAMP),authorization_version=authorization_version+1,updated_by=? WHERE id=?')->execute([$email,$name,$version,$actorId?:null,$id]);}
    private function revokePrincipal(PDO$pdo,int$id,int$actorId):void{$version=PortalSourceVersion::from(['principalId'=>$id,'active'=>false]);$pdo->prepare('UPDATE portal_principals SET source_version=?,enabled=0,revoked_at=CURRENT_TIMESTAMP,authorization_version=authorization_version+1,updated_by=? WHERE id=? AND (enabled<>0 OR revoked_at IS NULL)')->execute([$version,$actorId?:null,$id]);$pdo->prepare('UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=? AND active<>0')->execute([$version,$actorId?:null,$id]);$pdo->prepare('UPDATE portal_identity_bindings SET enabled=0,revoked_at=CURRENT_TIMESTAMP,updated_by=? WHERE portal_principal_id=? AND enabled<>0')->execute([$actorId?:null,$id]);}
    private function linkPrincipalClient(PDO$pdo,int$principalId,int$clientId,int$actorId):void{$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE INTO portal_principal_clients(portal_principal_id,client_id,created_by)VALUES(?,?,?)':'INSERT IGNORE INTO portal_principal_clients(portal_principal_id,client_id,created_by)VALUES(?,?,?)';$pdo->prepare($sql)->execute([$principalId,$clientId,$actorId?:null]);}
    private function upsertEntitlement(PDO$pdo,int$principalId,string$capability,string$scopeType,string$scopeId,int$actorId):void{$find=$pdo->prepare("SELECT id FROM portal_v2_entitlements WHERE portal_principal_id=? AND capability=? AND effect='allow' AND scope_type=? AND scope_public_id=?");$find->execute([$principalId,$capability,$scopeType,$scopeId]);$id=$find->fetchColumn();$version=PortalSourceVersion::from(['principalId'=>$principalId,'capability'=>$capability,'effect'=>'allow','scopeType'=>$scopeType,'scopePublicId'=>$scopeId,'active'=>true]);if($id)$pdo->prepare('UPDATE portal_v2_entitlements SET source_version=?,active=1,valid_from=COALESCE(valid_from,CURRENT_TIMESTAMP),expires_at=NULL,updated_by=? WHERE id=?')->execute([$version,$actorId?:null,(int)$id]);else$pdo->prepare("INSERT INTO portal_v2_entitlements(public_id,portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by)VALUES(?,?,?,'allow',?,?,?,1,CURRENT_TIMESTAMP,?,?)")->execute([bin2hex(random_bytes(16)),$principalId,$capability,$scopeType,$scopeId,$version,$actorId?:null,$actorId?:null]);}

    private function saveEligibility(PDO$pdo,int$clientId,mixed$principalId,string$manual,string$status,string$reason,?string$email,string$version,int$actorId):void{$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?'INSERT INTO portal_client_login_eligibility(client_id,portal_principal_id,manual_state,eligibility_status,review_reason,canonical_email,source_version,last_reconciled_at,created_by,updated_by)VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?) ON CONFLICT(client_id)DO UPDATE SET portal_principal_id=excluded.portal_principal_id,manual_state=excluded.manual_state,eligibility_status=excluded.eligibility_status,review_reason=excluded.review_reason,canonical_email=excluded.canonical_email,source_version=excluded.source_version,last_reconciled_at=CURRENT_TIMESTAMP,updated_by=excluded.updated_by':'INSERT INTO portal_client_login_eligibility(client_id,portal_principal_id,manual_state,eligibility_status,review_reason,canonical_email,source_version,last_reconciled_at,created_by,updated_by)VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?) ON DUPLICATE KEY UPDATE portal_principal_id=VALUES(portal_principal_id),manual_state=VALUES(manual_state),eligibility_status=VALUES(eligibility_status),review_reason=VALUES(review_reason),canonical_email=VALUES(canonical_email),source_version=VALUES(source_version),last_reconciled_at=UTC_TIMESTAMP(6),updated_by=VALUES(updated_by)';$pdo->prepare($sql)->execute([$clientId,$principalId?:null,$manual,$status,$reason,$email,$version,$actorId?:null,$actorId?:null]);}
    private function upsertRootControl(PDO$pdo,string$type,string$id,string$state,?string$reason,int$actorId):void{$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?'INSERT INTO portal_client_access_roots(root_type,root_public_id,access_state,state_reason,created_by,updated_by)VALUES(?,?,?,?,?,?) ON CONFLICT(root_type,root_public_id)DO UPDATE SET access_state=excluded.access_state,state_reason=excluded.state_reason,updated_by=excluded.updated_by':'INSERT INTO portal_client_access_roots(root_type,root_public_id,access_state,state_reason,created_by,updated_by)VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE access_state=VALUES(access_state),state_reason=VALUES(state_reason),updated_by=VALUES(updated_by)';$pdo->prepare($sql)->execute([$type,$id,$state,$reason,$actorId?:null,$actorId?:null]);}
    private function touchRoot(PDO$pdo,string$type,string$id):void{$now=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'CURRENT_TIMESTAMP':'UTC_TIMESTAMP(6)';$pdo->prepare("UPDATE portal_client_access_roots SET last_reconciled_at={$now} WHERE root_type=? AND root_public_id=?")->execute([$type,$id]);}

    private function audit(PDO$pdo,int$profileId,string$action,string$type,string$id,array$metadata):void{$pdo->prepare('INSERT INTO portal_integration_audit(integration_profile_id,api_key_id,action,target_type,target_public_id,metadata_json)VALUES(?,NULL,?,?,?,?)')->execute([$profileId,$action,$type,$id,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    /** @param list<array<string,mixed>> $scopes @return list<array{root_type:string,root_public_id:string}> */
    private function uniqueScopes(array$scopes):array{$out=[];foreach($scopes as$scope){$type=(string)($scope['root_type']??'');$id=(string)($scope['root_public_id']??'');if(!in_array($type,['organization','standalone_client'],true)||$id==='')continue;$out[$type.'|'.$id]=['root_type'=>$type,'root_public_id'=>$id];}ksort($out);return array_values($out);}
}
