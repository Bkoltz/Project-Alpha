<?php
// src/repositories/ClientRepository.php
// MongoDB repository for clients collection

require_once __DIR__ . '/BaseRepository.php';

class ClientRepository extends BaseRepository {
    protected string $collectionName = 'clients';
    
    /**
     * Find clients with optional filtering and pagination
     * @param string|null $searchName
     * @param string|null $orgName
     * @param bool $includeArchived
     * @param int|null $page
     * @param int $perPage
     * @return array
     */
    public function findClients(?string $searchName = null, ?string $orgName = null, 
                                bool $includeArchived = false, ?int $page = null, int $perPage = 50): array {
        $filter = [];
        
        if (!$includeArchived) {
            $filter['archived'] = ['$ne' => true];
        }
        
        if ($searchName !== null && $searchName !== '') {
            $filter['name'] = ['$regex' => preg_quote($searchName, '/'), '$options' => 'i'];
        }
        
        if ($orgName !== null && $orgName !== '') {
            $filter['$or'] = [
                ['organization_name' => ['$regex' => preg_quote($orgName, '/'), '$options' => 'i']],
                ['organization.name' => ['$regex' => preg_quote($orgName, '/'), '$options' => 'i']]
            ];
        }
        
        $sort = ['name' => 1]; // ASC
        
        if ($page !== null) {
            return $this->paginate($filter, $page, $perPage, $sort);
        }
        
        return $this->findMany($filter, ['sort' => $sort]);
    }
    
    /**
     * Find client by MySQL ID (migration compatibility)
     * @param int $mysqlId
     * @return array|null
     */
    public function findByMysqlId(int $mysqlId): ?array {
        return $this->findOne(['mysql_id' => $mysqlId]);
    }
    
    /**
     * Get clients for dropdown (id, name only)
     * @param bool $excludeArchived
     * @return array
     */
    public function getClientsForDropdown(bool $excludeArchived = true): array {
        $filter = [];
        if ($excludeArchived) {
            $filter['archived'] = ['$ne' => true];
        }
        
        return $this->findMany($filter, [
            'sort' => ['name' => 1],
            'projection' => ['_id' => 1, 'mysql_id' => 1, 'name' => 1]
        ]);
    }
    
    /**
     * Get client projects (aggregates from quotes, contracts, invoices)
     * @param int $clientId
     * @return array
     */
    public function getClientProjects(int $clientId): array {
        $pipeline = [
            ['$match' => ['mysql_id' => $clientId]],
            ['$lookup' => [
                'from' => 'quotes',
                'let' => ['client_id' => '$mysql_id'],
                'pipeline' => [
                    ['$match' => [
                        '$expr' => ['$eq' => ['$client_id', '$$client_id']],
                        'project_code' => ['$exists' => true, '$ne' => null]
                    ]],
                    ['$project' => ['project_code' => 1, 'doc_number' => 1, 'total' => 1, 'status' => 1, 'created_at' => 1, 'type' => ['$literal' => 'quote']]]
                ],
                'as' => 'quotes'
            ]],
            ['$lookup' => [
                'from' => 'contracts',
                'let' => ['client_id' => '$mysql_id'],
                'pipeline' => [
                    ['$match' => [
                        '$expr' => ['$eq' => ['$client_id', '$$client_id']],
                        'project_code' => ['$exists' => true, '$ne' => null]
                    ]],
                    ['$project' => ['project_code' => 1, 'doc_number' => 1, 'status' => 1, 'created_at' => 1, 'type' => ['$literal' => 'contract']]]
                ],
                'as' => 'contracts'
            ]],
            ['$lookup' => [
                'from' => 'invoices',
                'let' => ['client_id' => '$mysql_id'],
                'pipeline' => [
                    ['$match' => [
                        '$expr' => ['$eq' => ['$client_id', '$$client_id']],
                        'project_code' => ['$exists' => true, '$ne' => null]
                    ]],
                    ['$project' => ['project_code' => 1, 'doc_number' => 1, 'total' => 1, 'status' => 1, 'created_at' => 1, 'type' => ['$literal' => 'invoice']]]
                ],
                'as' => 'invoices'
            ]],
            ['$project' => [
                'projects' => [
                    '$setUnion' => ['$quotes', '$contracts', '$invoices']
                ]
            ]],
            ['$unwind' => '$projects'],
            ['$group' => [
                '_id' => '$projects.project_code',
                'quotes' => [
                    '$push' => [
                        '$cond' => [
                            ['$eq' => ['$projects.type', 'quote']],
                            '$projects',
                            '$$REMOVE'
                        ]
                    ]
                ],
                'contracts' => [
                    '$push' => [
                        '$cond' => [
                            ['$eq' => ['$projects.type', 'contract']],
                            '$projects',
                            '$$REMOVE'
                        ]
                    ]
                ],
                'invoices' => [
                    '$push' => [
                        '$cond' => [
                            ['$eq' => ['$projects.type', 'invoice']],
                            '$projects',
                            '$$REMOVE'
                        ]
                    ]
                ]
            ]],
            ['$sort' => ['_id' => -1]]
        ];
        
        return $this->aggregate($pipeline);
    }
    
    /**
     * Migrate from MySQL row to MongoDB document
     * @param array $mysqlRow
     * @return array MongoDB document
     */
    public static function fromMysqlRow(array $mysqlRow): array {
        return [
            'mysql_id' => (int)$mysqlRow['id'],
            'name' => $mysqlRow['name'] ?? '',
            'email' => $mysqlRow['email'] ?? null,
            'phone' => $mysqlRow['phone'] ?? null,
            'organization_id' => isset($mysqlRow['organization_id']) ? (int)$mysqlRow['organization_id'] : null,
            'organization_name' => $mysqlRow['organization_name'] ?? null,
            'notes' => $mysqlRow['notes'] ?? null,
            'address_line1' => $mysqlRow['address_line1'] ?? null,
            'address_line2' => $mysqlRow['address_line2'] ?? null,
            'city' => $mysqlRow['city'] ?? null,
            'state' => $mysqlRow['state'] ?? null,
            'postal' => $mysqlRow['postal'] ?? null,
            'country' => $mysqlRow['country'] ?? 'USA',
            'archived' => (bool)($mysqlRow['archived'] ?? 0),
            'created_at' => toMongoDate($mysqlRow['created_at'] ?? null),
            'migrated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
    }
}