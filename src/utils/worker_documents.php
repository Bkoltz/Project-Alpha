<?php

declare(strict_types=1);

function worker_document_category_labels(): array
{
    return [
        'equipment_use' => 'Equipment use agreement',
        'safety_waiver' => 'Safety agreement or waiver',
        'employment_agreement' => 'Employment agreement',
        'contractor_agreement' => 'Contractor agreement',
        'confidentiality' => 'Confidentiality / NDA',
        'policy_acknowledgement' => 'Policy acknowledgement',
        'license_certification' => 'License or certification',
        'training_record' => 'Training record',
        'other' => 'Other worker document',
    ];
}

function worker_documents_storage_root(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'worker-documents';
}

function worker_documents_user_directory(int $userId): string
{
    return worker_documents_storage_root() . DIRECTORY_SEPARATOR . 'user-' . $userId;
}

function worker_documents_profile_directory(int $workerProfileId): string
{
    return worker_documents_storage_root() . DIRECTORY_SEPARATOR . 'worker-' . $workerProfileId;
}

function worker_document_db_path(int $userId, string $storedName): string
{
    return 'worker-documents/user-' . $userId . '/' . $storedName;
}

function worker_document_profile_db_path(int $workerProfileId, string $storedName): string
{
    return 'worker-documents/worker-' . $workerProfileId . '/' . $storedName;
}

function worker_document_absolute_path(string $dbPath): ?string
{
    $root = realpath(worker_documents_storage_root());
    if ($root === false) {
        return null;
    }

    $candidate = realpath(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
        . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbPath), DIRECTORY_SEPARATOR)
    );
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($candidate, $prefix) ? $candidate : null;
}

function worker_document_detect_mime(string $path): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return (string)($finfo->file($path) ?: 'application/octet-stream');
}
