<?php

namespace App\services;

use App\record_transfer_objects\ProjectDocumentRecord;
use App\repositories\ProjectRepository;

// ***In this context, documentType does not refer to on-demand, long-term, nor regular, but quotes, contracts, or invoices
class ProjectService {
    private ProjectRepository $repository;
    
    public function __construct(ProjectRepository $repository) {
        $this->repository = $repository;
    }

    // project_id, document_type, document_id
    public function insertInvoiceProjectDoc(int $projectId, int $documentId) : void {
        $this->insertProjectDoc($projectId, 'invoice', $documentId);
    }

    public function insertContractProjectDoc(int $projectId, int $documentId) : void {
        $this->insertProjectDoc($projectId, 'contract', $documentId);
        
    }

    public function insertQuoteProjectDoc(int $projectId, int $documentId) : void {
        $this->insertProjectDoc($projectId, 'quote', $documentId);
        
    }

    private function insertProjectDoc(int $projectId, string $documentType, int $documentId) : void {
        $record = new ProjectDocumentRecord();
        $record->project_id = $projectId;
        $record->document_type = $documentType;
        $record->document_id = $documentId;

        $this->repository->insertProjectDocuments($record);
    }
}