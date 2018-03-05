<?php


namespace Tagcade\Service;


interface RetryCycleServiceInterface
{
    public function getRetryCycleForFile($filePath);

    public function increaseRetryCycleForFile($filePath);

    public function removeRetryCycleKey($filePath);
}