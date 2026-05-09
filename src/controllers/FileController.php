<?php

namespace App\controllers;

use App\services\FileService;

class FileController
{
    private FileService $fileService;

    public function __construct(FileService $fileService)
    {
        $this->fileService = $fileService;
    }

    public function load()
    {
        $fileName = (string)($_GET['file'] ?? '');
        $download = filter_var($_GET['download'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $return = $this->fileService->getStoredFileData($fileName, $download);

        if (!$return) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $return['mime']);
        header('Content-Disposition: ' . $return['disposition'] . '; filename="' . basename(rawurldecode($return['name'])) . '"' );
        header('Content-Length: ' . filesize($return['path']));
        readfile($return['path']);
        exit;
    }
}
