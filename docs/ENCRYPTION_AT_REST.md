# Encryption at Rest

Project Alpha has two distinct encryption concerns: application secrets and MySQL data files.

## Application Secrets

Stripe, SMTP, and similar configured secrets are encrypted with AES-256-GCM before database storage. The application encryption key is generated on first boot when not supplied and is persisted in the configuration volume.

Operational requirements:

- Back up the configuration volume separately from MySQL.
- Restrict access to the key and its backups.
- Never commit the key or generated `.env` file.
- Restore the original key before expecting encrypted database values to decrypt.
- Rotate affected third-party credentials if the key or ciphertext database is exposed.

## MySQL Tablespace Encryption

The repository contains MySQL keyring configuration files under `database/mysql/`, but the published `docker-compose.yml` does not currently mount or enable them. Therefore, MySQL tablespace encryption must not be assumed for a default installation.

Operators requiring database-file encryption should use a reviewed deployment-specific design such as encrypted host storage or a fully configured MySQL keyring setup. Validate it after every MySQL image upgrade and test disaster recovery with the key material.

Do not enable tablespace encryption without a key-backup plan. Losing the database keyring can make otherwise healthy data files unrecoverable.

## Verification

For every deployment, document and test:

1. Which layer encrypts the database volume
2. Where encryption keys are stored
3. Who can access the keys
4. How keys and data are backed up
5. How a complete restore is performed

Application-level secret encryption and storage-level encryption solve different problems; use both when the deployment threat model requires them.
