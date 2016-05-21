<?php
namespace Tagcade\Service;
use RecursiveIteratorIterator;

interface Excel2CSVConverterInterface
{
    /**
     * @param $files RecursiveIteratorIterator
     * @param bool $saveOriginal
     * @return mixed
     */
    public function convert(RecursiveIteratorIterator $files, $saveOriginal = true);
}