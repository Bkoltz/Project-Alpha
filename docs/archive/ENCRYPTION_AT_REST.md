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

The canonical Compose deployment now activates MySQL's file keyring, requires encrypted application and system tablespaces, and enables redo and undo log encryption. The migrator converts existing PA InnoDB tables before application services start. See the current [Database Encryption](../admin/database-encryption.html) operating guide.

Do not enable tablespace encryption without a key-backup plan. Losing the database keyring can make otherwise healthy data files unrecoverable.

## Verification

For every deployment, document and test:

1. Which layer encrypts the database volume
2. Where encryption keys are stored
3. Who can access the keys
4. How keys and data are backed up
5. How a complete restore is performed

Application-level secret encryption and storage-level encryption solve different problems; use both when the deployment threat model requires them.
