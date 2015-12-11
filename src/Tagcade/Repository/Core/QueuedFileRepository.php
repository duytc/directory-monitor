<?php

namespace Tagcade\Repository\Core;


use Doctrine\ORM\EntityRepository;
use Tagcade\Entity\Core\QueuedFile;

class QueuedFileRepository extends EntityRepository implements QueuedFileRepositoryInterface
{
    /**
     * @inheritdoc
     */
    public function findByHash($hash)
    {
        $queuedFiles = $this->findBy(['hash' => $hash]);
        if ($queuedFiles != null && count($queuedFiles) > 1) {
            throw new \LogicException('There should be one unique hash of file has been queued');
        }

        return count($queuedFiles) > 0 ? current($queuedFiles) : null;
    }

    public function createNew($md5, $filePath)
    {
        $queuedFile = new QueuedFile();
        $queuedFile->setFilePath($filePath);
        $queuedFile->setHash($md5);
        $queuedFile->setHashType('md5');

        $this->_em->persist($queuedFile);

        $this->_em->flush();
    }
}