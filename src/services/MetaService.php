<?php
namespace App\services;

use App\record_transfer_objects\MetaRecord;
use App\repositories\meta\MetaRepository;

class MetaService {
    private MetaRepository $repository;

    public function __construct(MetaRepository $repository) {
        $this->repository = $repository;
    }

    public function insertProjectMetaFromArray(array $data) : void {
        $metaRecord = MetaRecord::fromArray($data);
        $this->setProjectMeta($metaRecord);
    }

    public function setProjectMeta(MetaRecord $meta) : void {
        $this->validate($meta);

        $this->repository->insertMetaRecord($meta);
    }

    private function validate(MetaRecord $metaRecord) : void {
        $metaRecord->notes ??= '';
        $metaRecord->project_code ??= '';
        $metaRecord->terms ??= '';
        $metaRecord->client_id ??= 0;
    }
}