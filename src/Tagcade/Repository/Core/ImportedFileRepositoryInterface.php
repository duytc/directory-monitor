<?php


namespace Tagcade\Repository\Core;

use Doctrine\Common\Persistence\ObjectRepository;
use Tagcade\Entity\Core\ImportedFile;

interface ImportedFileRepositoryInterface extends ObjectRepository
{
    /**
     * find an importedFile by hash
     * @param $hash
     * @return null|array|ImportedFile[]
     */
    public function findByHash($hash);
}