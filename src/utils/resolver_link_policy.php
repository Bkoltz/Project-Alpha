<?php
declare(strict_types=1);

/** Shared read gate: disabled providers must never expose cached resolver links. */
function pa_resolver_link_visibility_sql(string $alias = 'entity_links'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid link table alias.');
    }
    return '(' . $alias . '.link_type <> "resolver_blacklist"
        AND (COALESCE(' . $alias . '.link_source, "manual") <> "resolver"
        OR (
            EXISTS (SELECT 1 FROM app_config resolver_global
                    WHERE resolver_global.organization_id = 0
                      AND resolver_global.config_key = "link_resolver_enabled"
                      AND resolver_global.config_value = "1")
            AND EXISTS (SELECT 1 FROM link_resolver_config resolver_provider
                        WHERE resolver_provider.provider = SUBSTR(' . $alias . '.link_type, 6)
                          AND resolver_provider.is_enabled = 1)
            AND ' . $alias . '.link_type IN ("auto_dropbox","auto_gdrive","auto_s3","auto_r2")
        )))';
}

/** Remove cached URLs only, preserving manual links, credentials and opt-out markers. */
function pa_remove_disabled_resolver_links(PDO $pdo, ?string $provider = null): int
{
    if ($provider !== null && !in_array($provider, ['dropbox', 'gdrive', 's3', 'r2'], true)) {
        throw new InvalidArgumentException('Invalid link provider.');
    }
    $condition = 'link_source = "resolver"
            AND link_type <> "resolver_blacklist"
            AND NOT ' . pa_resolver_link_visibility_sql();
    $parameters = [];
    if ($provider !== null) {
        $condition .= ' AND link_type = ?';
        $parameters[] = 'auto_' . $provider;
    }
    // An entity's manual-only preference can live on the generated row itself.
    // Remove the URL while retaining that preference as a non-link marker.
    $marker = $pdo->prepare('UPDATE entity_links
        SET link_type="resolver_blacklist",title="Resolver disabled",url="#",include_on_invoices=0
        WHERE ' . $condition . ' AND ignore_auto_generation=1');
    $marker->execute($parameters);
    $removed = $marker->rowCount();
    $statement = $pdo->prepare('DELETE FROM entity_links WHERE ' . $condition);
    $statement->execute($parameters);
    return $removed + $statement->rowCount();
}
