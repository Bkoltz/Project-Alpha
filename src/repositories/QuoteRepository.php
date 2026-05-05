<?php
// src/repositories/QuoteRepository.php
// MongoDB repository for quotes collection

require_once __DIR__ . '/BaseRepository.php';

class QuoteRepository extends BaseRepository {
    protected string $collectionName = 'quotes';
    
    /**
     * Find quotes with filtering and pagination
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function findQuotes(array $filters = [], int $page = 1, int $perPage = 50): array {
        $filter = [];
        
        // Exclude long-term quotes by default
        $filter['$or'] = [
            ['is_long_term' => ['$exists' => false]],
            ['is_long_term' => false]
        ];
        
        // Client filter
        if (isset($filters['client_id']) && $filters['client_id'] > 0) {
            $filter['client_id'] = (int)$filters['client_id'];
        } elseif (isset($filters['client_name']) && $filters['client_name'] !== '') {
            $filter['client_name'] = ['$regex' => preg_quote($filters['client_name'], '/'), '$options' => 'i'];
        }
        
        // Date range
        if (isset($filters['start']) && $filters['start'] !== '') {
            $filter['created_at']['$gte'] = toMongoDate($filters['start'] . ' 00:00:00');
        }
        if (isset($filters['end']) && $filters['end'] !== '') {
            $filter['created_at']['$lte'] = toMongoDate($filters['end'] . ' 23:59:59');
        }
        
        // Status filter
        if (isset($filters['status']) && in_array($filters['status'], ['approved', 'rejected', 'pending'], true)) {
            $filter['status'] = $filters['status'];
        }
        
        // Project code filter
        if (isset($filters['project_code']) && $filters['project_code'] !== '') {
            $filter['project_code'] = ['$regex' => '^' . preg_quote($filters['project_code']), '$options' => 'i'];
        }
        
        // Document number filter
        if (isset($filters['doc_number']) && $filters['doc_number'] > 0) {
            $filter['doc_number'] = (int)$filters['doc_number'];
        }
        
        // Price range
        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $filter['total']['$gte'] = (float)$filters['min_price'];
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $filter['total']['$lte'] = (float)$filters['max_price'];
        }
        
        $sort = ['created_at' => -1]; // DESC
        
        return $this->paginate($filter, $page, $perPage, $sort);
    }
    
    /**
     * Get quotes with client info (aggregation pipeline)
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getQuotesWithClient(array $filters = [], int $page = 1, int $perPage = 50): array {
        $matchFilter = [];
        
        // Exclude long-term quotes
        $matchFilter['$or'] = [
            ['is_long_term' => ['$exists' => false]],
            ['is_long_term' => false]
        ];
        
        // Build filter conditions
        if (isset($filters['client_id']) && $filters['client_id'] > 0) {
            $matchFilter['client_id'] = (int)$filters['client_id'];
        } elseif (isset($filters['client_name']) && $filters['client_name'] !== '') {
            $matchFilter['client_name'] = ['$regex' => preg_quote($filters['client_name'], '/'), '$options' => 'i'];
        }
        
        if (isset($filters['start']) && $filters['start'] !== '') {
            $matchFilter['created_at']['$gte'] = toMongoDate($filters['start'] . ' 00:00:00');
        }
        if (isset($filters['end']) && $filters['end'] !== '') {
            $matchFilter['created_at']['$lte'] = toMongoDate($filters['end'] . ' 23:59:59');
        }
        
        if (isset($filters['status']) && in_array($filters['status'], ['approved', 'rejected', 'pending'], true)) {
            $matchFilter['status'] = $filters['status'];
        }
        
        if (isset($filters['project_code']) && $filters['project_code'] !== '') {
            $matchFilter['project_code'] = ['$regex' => '^' . preg_quote($filters['project_code']), '$options' => 'i'];
        }
        
        if (isset($filters['doc_number']) && $filters['doc_number'] > 0) {
            $matchFilter['doc_number'] = (int)$filters['doc_number'];
        }
        
        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $matchFilter['total']['$gte'] = (float)$filters['min_price'];
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $matchFilter['total']['$lte'] = (float)$filters['max_price'];
        }
        
        // Count pipeline
        $countPipeline = [['$match' => $matchFilter], ['$count' => 'total']];
        $countResult = $this->aggregate($countPipeline);
        $total = $countResult[0]['total'] ?? 0;
        
        $pages = (int)ceil(max(1, $total) / $perPage);
        $page = max(1, min($page, $pages));
        $skip = ($page - 1) * $perPage;
        
        // Main pipeline with lookup
        $pipeline = [
            ['$match' => $matchFilter],
            ['$lookup' => [
                'from' => 'clients',
                'localField' => 'client_id',
                'foreignField' => 'mysql_id',
                'as' => 'client_info'
            ]],
            ['$unwind' => [
                'path' => '$client_info',
                'preserveNullAndEmptyArrays' => true
            ]],
            ['$project' => [
                '_id' => 1,
                'mysql_id' => 1,
                'id' => '$mysql_id',
                'doc_number' => ['$ifNull' => ['$doc_number', '$mysql_id']],
                'project_code' => 1,
                'status' => 1,
                'total' => 1,
                'created_at' => 1,
                'client_id' => 1,
                'client_name' => ['$ifNull' => ['$client_info.name', '$client_name']],
                'is_long_term' => 1
            ]],
            ['$sort' => ['created_at' => -1]],
            ['$skip' => $skip],
            ['$limit' => $perPage]
        ];
        
        $data = $this->aggregate($pipeline);
        
        return [
            'data' => $data,
            'total' => $total,
            'pages' => $pages,
            'page' => $page
        ];
    }
    
    /**
     * Get quotes by client and project
     * @param int $clientId
     * @param string $projectCode
     * @return array
     */
    public function getByClientAndProject(int $clientId, string $projectCode): array {
        return $this->findMany([
            'client_id' => $clientId,
            'project_code' => ['$exists' => true, '$ne' => null],
            '$or' => [
                ['project_code' => ['$regex' => '^' . preg_quote($projectCode), '$options' => 'i']],
                ['project_code' => $projectCode]
            ]
        ], ['sort' => ['created_at' => -1]]);
    }
    
    /**
     * Get pending quotes
     * @param int $limit
     * @return array
     */
    public function getPendingQuotes(int $limit = 10): array {
        return $this->findMany(
            ['status' => 'pending'],
            ['sort' => ['created_at' => -1], 'limit' => $limit]
        );
    }
    
    /**
     * Migrate from MySQL row to MongoDB document
     * @param array $mysqlRow
     * @return array
     */
    public static function fromMysqlRow(array $mysqlRow): array {
        return [
            'mysql_id' => (int)$mysqlRow['id'],
            'client_id' => (int)$mysqlRow['client_id'],
            'project_id' => isset($mysqlRow['project_id']) ? (int)$mysqlRow['project_id'] : null,
            'doc_number' => isset($mysqlRow['doc_number']) ? (int)$mysqlRow['doc_number'] : null,
            'project_code' => $mysqlRow['project_code'] ?? null,
            'status' => $mysqlRow['status'] ?? 'pending',
            'discount_type' => $mysqlRow['discount_type'] ?? 'none',
            'discount_value' => (float)($mysqlRow['discount_value'] ?? 0),
            'tax_percent' => (float)($mysqlRow['tax_percent'] ?? 0),
            'subtotal' => (float)($mysqlRow['subtotal'] ?? 0),
            'total' => (float)($mysqlRow['total'] ?? 0),
            'deposit_type' => $mysqlRow['deposit_type'] ?? 'none',
            'deposit_amount' => (float)($mysqlRow['deposit_amount'] ?? 0),
            'fulfillment_date' => $mysqlRow['fulfillment_date'] ?? null,
            'is_long_term' => (bool)($mysqlRow['is_long_term'] ?? 0),
            'is_on_demand' => (bool)($mysqlRow['is_on_demand'] ?? 0),
            'start_date' => $mysqlRow['start_date'] ?? null,
            'end_date' => $mysqlRow['end_date'] ?? null,
            'billing_interval_count' => (int)($mysqlRow['billing_interval_count'] ?? 1),
            'billing_interval_unit' => $mysqlRow['billing_interval_unit'] ?? 'month',
            'pricing_type' => $mysqlRow['pricing_type'] ?? null,
            'price_per_invoice' => isset($mysqlRow['price_per_invoice']) ? (float)$mysqlRow['price_per_invoice'] : null,
            'scope' => $mysqlRow['scope'] ?? null,
            'custom_fields' => isset($mysqlRow['custom_fields']) ? json_decode($mysqlRow['custom_fields'], true) : null,
            'created_at' => toMongoDate($mysqlRow['created_at'] ?? null),
            'document_date' => toMongoDate($mysqlRow['document_date'] ?? $mysqlRow['created_at'] ?? null),
            'migrated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
    }
}