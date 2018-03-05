<?php


namespace Tagcade\Service;


class RetryCycleService implements RetryCycleServiceInterface
{
    private $server;
    private $prefixKey;
    private $expireTime;

    function __construct($server, $prefixKey, $expireTime)
    {
        $this->server = $server;
        $this->prefixKey = $prefixKey;
        $this->expireTime = $expireTime;
    }

    public function getRetryCycleForFile($filePath)
    {
        $key = sprintf('%s_%s', $this->prefixKey, md5($filePath));

        if ($this->server instanceof \Redis) {
            if ($this->server->isConnected()) {
                return $this->server->get($key);
            }
        }

        return 0;
    }

    public function increaseRetryCycleForFile($filePath)
    {
        $key = sprintf('%s_%s', $this->prefixKey, md5($filePath));

        if ($this->server instanceof \Redis) {
            if ($this->server->isConnected()) {
                if (!$this->server->exists($key)) {
                    $this->server->set($key, 0, $this->expireTime);
                }

                $this->server->incr($key);
            }
        }
    }

    public function removeRetryCycleKey($filePath)
    {
        $key = sprintf('%s_%s', $this->prefixKey, md5($filePath));

        if ($this->server instanceof \Redis) {
            if ($this->server->isConnected()) {
                $this->server->del($key);
            }
        }
    }
}