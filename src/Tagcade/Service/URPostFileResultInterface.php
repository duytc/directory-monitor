<?php

namespace Tagcade\Service;

interface URPostFileResultInterface
{
    /**
     * @return mixed
     */
    public function getStatusCode();

    /**
     * @param mixed $statusCode
     */
    public function setStatusCode($statusCode);

    /**
     * @return mixed
     */
    public function getMessage();

    /**
     * @param mixed $message
     */
    public function setMessage($message);
}