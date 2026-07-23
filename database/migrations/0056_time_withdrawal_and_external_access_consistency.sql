-- Preserve submitted time history when a pending entry is withdrawn for editing,
-- and make the explicit external-application entitlement authoritative.

ALTER TABLE time_submission_entries
    MODIFY COLUMN decision ENUM('pending','confirmed','returned','withdrawn','voided')
    NOT NULL DEFAULT 'pending';

SET @external_operations_application_key := (
    SELECT config_value
    FROM app_config
    WHERE organization_id=0
      AND config_key='external_ops_application_key'
    LIMIT 1
);

UPDATE application_entitlements
SET manual_enabled=CASE WHEN enabled=1 THEN 1 ELSE 0 END,
    automatic_enabled=0,
    oversight_enabled=0
WHERE @external_operations_application_key IS NOT NULL
  AND application_key=@external_operations_application_key;

DELETE scoped
FROM application_entitlement_business_units scoped
JOIN application_entitlements entitlement ON entitlement.id=scoped.entitlement_id
WHERE @external_operations_application_key IS NOT NULL
  AND entitlement.application_key=@external_operations_application_key;

DELETE scoped
FROM application_entitlement_oversight_units scoped
JOIN application_entitlements entitlement ON entitlement.id=scoped.entitlement_id
WHERE @external_operations_application_key IS NOT NULL
  AND entitlement.application_key=@external_operations_application_key;
