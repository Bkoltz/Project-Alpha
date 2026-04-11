<?php
namespace App\services;

use App\record_transfer_objects\MetaRecord;
use App\repositories\meta\MetaRepository;

class MetaService {
    private MetaRepository $repository;

    public function __construct(MetaRepository $repository) {
        $this->repository = $repository;
    }

    public function setProjectMeta(MetaRecord $meta) : void {
        $this->repository->setMeta($meta);
    }
}