<?php
// src/config/bootstrap.php
// Ensures required tables exist (idempotent), currently only users for auth

require_once __DIR__ . '/db.php';

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Login attempts for throttling
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(255) NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip),
            INDEX (email),
            INDEX (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // API keys and usage
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            key_prefix VARCHAR(32) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            scopes VARCHAR(1024) NULL,
            allowed_ips TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            UNIQUE KEY uq_key_hash (key_hash),
            INDEX (key_prefix),
            INDEX (revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_usage (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT NOT NULL,
            used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (api_key_id, used_at),
            CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    // Fail closed (but do not break public assets). If creation fails, login/setup will error later visibly.
}
