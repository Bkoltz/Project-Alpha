<?php
// src/repositories/BaseRepository.php
// Base repository class for MongoDB operations

require_once __DIR__ . '/../config/mongodb.php';

use MongoDB\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

abstract class BaseRepository {
    protected ?Collection $collection = null;
    protected string $collectionName = '';
    protected ?string $primaryKey = '_id';
    
    public function __construct() {
        if (empty($this->collectionName)) {
            throw new Exception('Collection name must be defined in child class');
        }
        $this->collection = getMongoCollection($this->collectionName);
    }
    
    /**
     * Find a single document by ID
     * @param string|int $id
     * @return array|null
     */
    public function findById($id): ?array {
        if ($this->collection === null) return null;
        
        try {
            $filter = is_numeric($id) 
                ? ['mysql_id' => (int)$id]
                : ['_id' => new ObjectId($id)];
            
            $result = $this->collection->findOne($filter);
            return $result ? $this->documentToArray($result) : null;
        } catch (Exception $e) {
            error_log("Error finding document by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find a single document by filter
     * @param array $filter
     * @param array $options
     * @return array|null
     */
    public function findOne(array $filter, array $options = []): ?array {
        if ($this->collection === null) return null;
        
        try {
            $result = $this->collection->findOne($filter, $options);
            return $result ? $this->documentToArray($result) : null;
        } catch (Exception $e) {
            error_log("Error finding document: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find multiple documents by filter
     * @param array $filter
     * @param array $options
     * @return array
     */
    public function findMany(array $filter = [], array $options = []): array {
        if ($this->collection === null) return [];
        
        try {
            $cursor = $this->collection->find($filter, $options);
            $results = [];
            foreach ($cursor as $document) {
                $results[] = $this->documentToArray($document);
            }
            return $results;
        } catch (Exception $e) {
            error_log("Error finding documents: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Find with aggregation pipeline
     * @param array $pipeline
     * @param array $options
     * @return array
     */
    public function aggregate(array $pipeline, array $options = []): array {
        if ($this->collection === null) return [];
        
        try {
            $cursor = $this->collection->aggregate($pipeline, $options);
            $results = [];
            foreach ($cursor as $document) {
                $results[] = $this->documentToArray($document);
            }
            return $results;
        } catch (Exception $e) {
            error_log("Error aggregating documents: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Count documents matching filter
     * @param array $filter
     * @return int
     */
    public function count(array $filter = []): int {
        if ($this->collection === null) return 0;
        
        try {
            return $this->collection->countDocuments($filter);
        } catch (Exception $e) {
            error_log("Error counting documents: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
    * Insert a single document
    * @param array $data
    * @return string|null Inserted ID or null on failure
    */
    public function insert(array $data): ?string {
        if ($this->collection === null) return null;
        
        try {
            // Convert arrays to BSON arrays for nested structures
            $data = $this->convertToBSON($data);
            
            $result = $this->collection->insertOne($data);
            return (string)$result->getInsertedId();
        } catch (Exception $e) {
            error_log("Error inserting document: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a single document
     * @param array $filter
     * @param array $data
     * @return bool
     */
    public function update(array $filter, array $data): bool {
        if ($this->collection === null) return false;
        
        try {
            // Convert arrays to BSON arrays for nested structures
            $data = $this->convertToBSON($data);
            
            $result = $this->collection->updateOne($filter, ['$set' => $data]);
            return $result->getModifiedCount() > 0 || $result->getMatchedCount() > 0;
        } catch (Exception $e) {
            error_log("Error updating document: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update multiple documents
     * @param array $filter
     * @param array $data
     * @return int
     */
    public function updateMany(array $filter, array $data): int {
        if ($this->collection === null) return 0;
        
        try {
            // Convert arrays to BSON arrays for nested structures
            $data = $this->convertToBSON($data);
            
            $result = $this->collection->updateMany($filter, ['$set' => $data]);
            return $result->getModifiedCount();
        } catch (Exception $e) {
            error_log("Error updating documents: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Delete a single document
     * @param array $filter
     * @return bool
     */
    public function delete(array $filter): bool {
        if ($this->collection === null) return false;
        
        try {
            $result = $this->collection->deleteOne($filter);
            return $result->getDeletedCount() > 0;
        } catch (Exception $e) {
            error_log("Error deleting document: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete multiple documents
     * @param array $filter
     * @return int
     */
    public function deleteMany(array $filter): int {
        if ($this->collection === null) return 0;
        
        try {
            $result = $this->collection->deleteMany($filter);
            return $result->getDeletedCount();
        } catch (Exception $e) {
            error_log("Error deleting documents: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Convert BSON document to PHP array
     * @param mixed $document
     * @return array
     */
    protected function documentToArray($document): array {
        if ($document instanceof BSONDocument || $document instanceof BSONArray) {
            $document = (array)$document;
        }
        
        if (!is_array($document)) {
            return ['value' => $document];
        }
        
        $result = [];
        foreach ($document as $key => $value) {
            if ($value instanceof BSONDocument || $value instanceof BSONArray) {
                $result[$key] = $this->documentToArray($value);
            } elseif ($value instanceof \MongoDB\BSON\UTCDateTime) {
                $result[$key] = mongoDateToString($value);
            } elseif ($value instanceof ObjectId) {
                $result[$key] = (string)$value;
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Convert PHP array to BSON-compatible format
     * @param mixed $data
     * @return mixed
     */
    protected function convertToBSON($data) {
        if (is_array($data)) {
            // Check if it's a list array (sequential integer keys)
            $isList = array_keys($data) === range(0, count($data) - 1);
            
            if ($isList) {
                return new BSONArray($data);
            } else {
                // Associative array - convert to BSONDocument
                $converted = [];
                foreach ($data as $key => $value) {
                    $converted[$key] = $this->convertToBSON($value);
                }
                return new BSONDocument($converted);
            }
        }
        
        return $data;
    }
    
    /**
     * Paginate results
     * @param array $filter
     * @param int $page
     * @param int $perPage
     * @param array $sort
     * @return array ['data' => [], 'total' => int, 'pages' => int]
     */
    public function paginate(array $filter = [], int $page = 1, int $perPage = 50, array $sort = []): array {
        $total = $this->count($filter);
        $pages = (int)ceil(max(1, $total) / $perPage);
        $page = max(1, min($page, $pages));
        $skip = ($page - 1) * $perPage;
        
        $options = ['limit' => $perPage, 'skip' => $skip];
        if (!empty($sort)) {
            $options['sort'] = $sort;
        }
        
        $data = $this->findMany($filter, $options);
        
        return [
            'data' => $data,
            'total' => $total,
            'pages' => $pages,
            'page' => $page
        ];
    }
}