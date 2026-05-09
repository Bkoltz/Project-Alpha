<?php

namespace App\services;

class FileService
{
    public const FILE_STORAGE_PATH = BASE_PATH . '/src/uploads/signed_contracts';

    private const EXTENSION_MAP = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml'
    ];

    public function getStoredFileData(string $fileName, bool $download): array|bool
    {
        $fileName = str_replace(chr(0), '', $fileName);
        $fileName = ltrim($fileName, '/\\');
        if ($fileName === '' || strpos($fileName, '..') !== false)
            return "failed to find file";

        $filePath = realpath(self::FILE_STORAGE_PATH . '/' . $fileName);
        if ($filePath === false || !is_file($filePath))
            return "path is not file";

        if (!str_starts_with($filePath, self::FILE_STORAGE_PATH . DIRECTORY_SEPARATOR))
            return "file path is not in path";

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = self::EXTENSION_MAP[$extension] ?? 'application/octet-stream';
        $disposition = $download ? 'attachment' : 'inline';

        return [
            'mime' => $mime,
            'disposition' => $disposition,
            'path' => $filePath,
            'name' => $fileName
        ];
    }
}
