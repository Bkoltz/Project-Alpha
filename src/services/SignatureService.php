<?php

namespace App\services;

use App\services\contract\ContractService;
use App\utils\enum\DocumentType;

class SignatureService
{
    private ContractService $contractService;

    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    public function getSignaturePath(string $fileName): string
    {
        return '/?page=serve-upload&file=' . rawurlencode($fileName);
    }

    public function addSignedDocument(int $contractId, DocumentType $documentType, array $fileData): bool
    {
        if (!$this->isUploadValid($fileData))
            return false;

        $fileName = $this->generateFileName($contractId);
        $internalDest = FileService::FILE_STORAGE_PATH . DIRECTORY_SEPARATOR . $fileName;

        // Try move_uploaded_file first (preferred)
        $success = move_uploaded_file($fileData['tmp_name'], $internalDest);

        // Fall back to rename/copy if needed
        if (!$success)
            $success = rename($fileData['tmp_name'], $internalDest);

        $this->contractService->setContractSignaturePath($contractId, $documentType, $fileName);
        return $success;
    }

    // Returns success
    private function isUploadValid(array $fileData): bool
    {
        // Validate upload
        if (empty($fileData) || !is_uploaded_file($fileData['tmp_name']))
            return false;

        // Max 25 MB
        if (!empty($fileData['size']) && $fileData['size'] > 25 * 1024 * 1024)
            return false;

        $mime = mime_content_type($fileData['tmp_name']);
        $uploadName = (string)($fileData['name'] ?? '');

        $isExtensionPDF = preg_match('/\.pdf$/i', $uploadName) === 1;
        $isMimePDF = $mime == 'application/pdf';
        if ($isMimePDF && !$isExtensionPDF)
            return false;

        return true;
    }

    private function generateFileName(int $contractId): string
    {
        return 'contract_' . $contractId . '_signed_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    }
}
