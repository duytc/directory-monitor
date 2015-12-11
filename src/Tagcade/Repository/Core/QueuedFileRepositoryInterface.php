<?php


namespace Tagcade\Repository\Core;

use Doctrine\Common\Persistence\ObjectRepository;
use Tagcade\Entity\Core\ImportedFile;
use Tagcade\Entity\Core\QueuedFile;

interface QueuedFileRepositoryInterface extends ObjectRepository
{
    /**
     * find an importedFile by hash
     * @param $hash
     * @return null|QueuedFile
     */
    public function findByHash($hash);

    /**
     * @param $md5
     * @param $filePath
     * @return mixed
     */
    public function createNew($md5, $filePath);
}