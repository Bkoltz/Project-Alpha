<?php
// src/config/mongodb.php
// MongoDB configuration and connection manager

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// MongoDB connection settings
$mongoHost = getenv('MONGODB_HOST') ?: 'localhost';
$mongoPort = getenv('MONGODB_PORT') ?: '27017';
$mongoDb = getenv('MONGODB_DATABASE') ?: 'project_alpha';
$mongoUser = getenv('MONGODB_USER') ?: '';
$mongoPass = getenv('MONGODB_PASSWORD') ?: '';

// Build connection string
$auth = '';
if ($mongoUser && $mongoPass) {
    $auth = urlencode($mongoUser) . ':' . urlencode($mongoPass) . '@';
}

$mongoUri = "mongodb://{$auth}{$mongoHost}:{$mongoPort}";

// Global MongoDB client instance
$mongoClient = null;
$mongoDatabase = null;

/**
 * Get MongoDB client instance
 * @return Client|null
 */
function getMongoClient(): ?Client {
    global $mongoClient, $mongoUri;
    
    if ($mongoClient === null) {
        try {
            $mongoClient = new Client($mongoUri);
        } catch (Exception $e) {
            error_log('MongoDB connection failed: ' . $e->getMessage());
            return null;
        }
    }
    
    return $mongoClient;
}

/**
 * Get MongoDB database instance
 * @return \MongoDB\Database|null
 */
function getMongoDatabase(): ?\MongoDB\Database {
    global $mongoDatabase, $mongoDb;
    
    if ($mongoDatabase === null) {
        $client = getMongoClient();
        if ($client) {
            $mongoDatabase = $client->selectDatabase($mongoDb);
        }
    }
    
    return $mongoDatabase;
}

/**
 * Get a MongoDB collection
 * @param string $collectionName
 * @return \MongoDB\Collection|null
 */
function getMongoCollection(string $collectionName): ?\MongoDB\Collection {
    $db = getMongoDatabase();
    return $db ? $db->selectCollection($collectionName) : null;
}

/**
 * Convert MySQL datetime string to MongoDB UTCDateTime
 * @param string|null $datetime
 * @return UTCDateTime|null
 */
function toMongoDate(?string $datetime): ?UTCDateTime {
    if (empty($datetime)) {
        return null;
    }
    $timestamp = strtotime($datetime);
    return $timestamp ? new UTCDateTime($timestamp * 1000) : null;
}

/**
 * Convert MongoDB UTCDateTime to PHP DateTime
 * @param UTCDateTime|null $mongoDate
 * @return DateTime|null
 */
function fromMongoDate(?UTCDateTime $mongoDate): ?DateTime {
    if ($mongoDate === null) {
        return null;
    }
    return $mongoDate->toDateTime();
}

/**
 * Convert MongoDB UTCDateTime to MySQL datetime string
 * @param UTCDateTime|null $mongoDate
 * @return string|null
 */
function mongoDateToString(?UTCDateTime $mongoDate): ?string {
    $dt = fromMongoDate($mongoDate);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

/**
 * Build MongoDB aggregation pipeline filter from conditions
 * @param array $conditions Array of ['field' => value] or ['field' => ['$operator' => value]]
 * @return array MongoDB filter
 */
function buildMongoFilter(array $conditions): array {
    $filter = [];
    
    foreach ($conditions as $field => $condition) {
        if (is_array($condition) && isset($condition['$in'])) {
            // Already a MongoDB operator
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$regex'])) {
            // Regex pattern
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$gte'])) {
            // Greater than or equal
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$lte'])) {
            // Less than or equal
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$gt'])) {
            // Greater than
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$lt'])) {
            // Less than
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$ne'])) {
            // Not equal
            $filter[$field] = $condition;
        } elseif (is_array($condition) && isset($condition['$exists'])) {
            // Exists check
            $filter[$field] = $condition;
        } elseif ($condition === null) {
            // Null check
            $filter[$field] = ['$exists' => false];
        } else {
            // Simple equality
            $filter[$field] = $condition;
        }
    }
    
    return $filter;
}

/**
 * Build regex pattern for LIKE queries
 * @param string $pattern SQL LIKE pattern (with %)
 * @return array MongoDB regex filter
 */
function likeToRegex(string $pattern): array {
    $regex = str_replace('%', '.*', preg_quote($pattern, '/'));
    return ['$regex' => '^' . $regex . '$', '$options' => 'i'];
}

/**
 * Convert SQL date range to MongoDB date filter
 * @param string $field
 * @param string|null $start
 * @param string|null $end
 * @return array
 */
function dateRangeToMongo(string $field, ?string $start, ?string $end): array {
    $filter = [];
    
    if ($start !== null && $start !== '') {
        $filter['$gte'] = toMongoDate($start . ' 00:00:00');
    }
    
    if ($end !== null && $end !== '') {
        $filter['$lte'] = toMongoDate($end . ' 23:59:59');
    }
    
    if (empty($filter)) {
        return [];
    }
    
    return [$field => $filter];
}

/**
 * Check if MongoDB is available
 * @return bool
 */
function isMongoAvailable(): bool {
    try {
        $client = getMongoClient();
        if ($client) {
            $client->admin->command(['ping' => 1]);
            return true;
        }
    } catch (Exception $e) {
        error_log('MongoDB availability check failed: ' . $e->getMessage());
    }
    return false;
}