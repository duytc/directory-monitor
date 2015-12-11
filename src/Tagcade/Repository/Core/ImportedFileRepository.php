<?php

namespace Tagcade\Repository\Core;


use Doctrine\ORM\EntityRepository;

class ImportedFileRepository extends EntityRepository implements ImportedFileRepositoryInterface
{
    /**
     * @inheritdoc
     */
    public function findByHash($hash)
    {
        return $this->findBy(['hash' => $hash]);
    }
}