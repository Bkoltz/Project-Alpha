<?php
// src/config/logging.php
// Logging configuration using Monolog

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;

// Create logger instance
$logger = new Logger('project_alpha');

// Create logs directory if it doesn't exist
$logsDir = '/var/www/config/logs/system';
if (!is_dir($logsDir)) {
    $logsDir = __DIR__ . '/../../config/logs/system';
}
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Set up rotating file handler - rotates at 10MB, keeps 30 files
$rotatingHandler = new RotatingFileHandler(
    $logsDir . '/app.log',
    30,  // Keep 30 files
    Logger::DEBUG  // Minimum log level
);

// Use JSON format for structured logging
$jsonFormatter = new JsonFormatter();
$rotatingHandler->setFormatter($jsonFormatter);

// Add the handler to the logger
$logger->pushHandler($rotatingHandler);

// Add processors for additional context
$logger->pushProcessor(new UidProcessor());  // Adds unique ID to each log entry
$logger->pushProcessor(new WebProcessor());  // Adds request info (IP, URL, etc.)

// Add custom processor for user context
$logger->pushProcessor(function ($record) {
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) {
        $record['extra']['user_id'] = $_SESSION['user']['id'] ?? null;
        $record['extra']['user_email'] = $_SESSION['user']['email'] ?? null;
    }
    return $record;
});

// Create separate handlers for different log levels
// Error log - only errors and above
$errorHandler = new RotatingFileHandler(
    $logsDir . '/error.log',
    30,
    Logger::ERROR
);
$errorHandler->setFormatter($jsonFormatter);
$logger->pushHandler($errorHandler);

// Security log - for authentication and authorization events
$securityLogger = new Logger('security');
$securityHandler = new RotatingFileHandler(
    $logsDir . '/security.log',
    30,
    Logger::INFO
);
$securityHandler->setFormatter($jsonFormatter);
$securityLogger->pushHandler($securityHandler);
$securityLogger->pushProcessor(new UidProcessor());
$securityLogger->pushProcessor(new WebProcessor());

// Audit log - for document and financial changes
$auditLogger = new Logger('audit');
$auditHandler = new RotatingFileHandler(
    $logsDir . '/audit.log',
    30,
    Logger::INFO
);
$auditHandler->setFormatter($jsonFormatter);
$auditLogger->pushHandler($auditHandler);
$auditLogger->pushProcessor(new UidProcessor());
$auditLogger->pushProcessor(new WebProcessor());

// System log - for scheduled jobs and background tasks
$systemLogger = new Logger('system');
$systemHandler = new RotatingFileHandler(
    $logsDir . '/system.log',
    30,
    Logger::INFO
);
$systemHandler->setFormatter($jsonFormatter);
$systemLogger->pushHandler($systemHandler);
$systemLogger->pushProcessor(new UidProcessor());

// Return loggers for use throughout the application
return [
    'app' => $logger,
    'security' => $securityLogger,
    'audit' => $auditLogger,
    'system' => $systemLogger,
];
