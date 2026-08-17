<?php

declare(strict_types=1);

use App\Services\PortalProjectionMutationService;

/**
 * Run an authoritative mutation and its scoped projection outbox work in one
 * transaction. Projection reconciliation uses its own savepoint and therefore
 * cannot make the core PA write unavailable when an optional integration fails.
 *
 * @param list<array{root_type:string,root_public_id:string}> $beforeScopes
 * @param callable():mixed $mutation
 * @param callable():list<array{root_type:string,root_public_id:string}> $afterScopes
 */
function portal_projection_mutate(PDO $pdo,array $beforeScopes,callable $mutation,callable $afterScopes):mixed
{
    $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$result=$mutation();$scopes=array_merge($beforeScopes,$afterScopes());(new PortalProjectionMutationService())->afterMutation($pdo,$scopes);if($owns)$pdo->commit();return$result;}catch(Throwable$error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function portal_projection_source_version():string{return'v-'.bin2hex(random_bytes(16));}
