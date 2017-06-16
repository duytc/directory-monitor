<?php

namespace Tagcade\Service;

class URPostFileResult implements URPostFileResultInterface
{
    private $statusCode;
    private $message;

    /**
     * URPostFileResult constructor.
     * @param $statusCode
     * @param $message
     */
    public function __construct($statusCode, $message)
    {
        $this->statusCode = $statusCode;
        $this->message = $message;
    }

    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @inheritdoc
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @inheritdoc
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @inheritdoc
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }
}