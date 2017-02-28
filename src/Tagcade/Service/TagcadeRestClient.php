<?php

namespace Tagcade\Service;

use RestClient\CurlRestClient;

class TagcadeRestClient implements TagcadeRestClientInterface
{
    const VIA_MODULE_EMAIL_WEB_HOOK = 1;
    const VIA_MODULE_FETCHER = 2;

    /** @var null */
    private $username;

    /** @var array */
    private $password;

    /** @var CurlRestClient */
    private $curl;

    private $getTokenUrl;
    private $getDataSourcesByIntegrationUrl;
    private $getDataSourcesByEmailUrl;
    private $urReceiveFileUrl;

    /**
     * store last token to save requests to api
     * @var string|null
     */
    private $token = null;

    const DEBUG = 0;

    function __construct(CurlRestClient $curl, $username, $password, $getTokenUrl,
                         $getDataSourcesByIntegrationUrl, $getDataSourcesByEmailUrl,
                         $urReceiveFileUrl)
    {
        $this->username = $username;
        $this->password = $password;
        $this->curl = $curl;
        $this->getTokenUrl = $getTokenUrl;
        $this->getDataSourcesByIntegrationUrl = $getDataSourcesByIntegrationUrl;
        $this->getDataSourcesByEmailUrl = $getDataSourcesByEmailUrl;
        $this->urReceiveFileUrl = $urReceiveFileUrl;
    }

    /**
     * @inheritdoc
     */
    public function getToken($force = false)
    {
        if ($this->token != null && $force == false) {
            return $this->token;
        }

        $data = array('username' => $this->username, 'password' => $this->password);
        $token = $this->curl->executeQuery($this->getTokenUrl, 'POST', array(), $data);
        $this->curl->close();
        $token = json_decode($token, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('json decoding for token error');
        }
        if (!array_key_exists('token', $token)) {
            throw new \Exception(sprintf('Could not authenticate user %s', $this->username));
        }

        $this->token = $token['token'];

        return $this->token;
    }

    /**
     * @inheritdoc
     */
    public function getListDataSourcesByEmail($publisherId, $email)
    {
        $header = array('Authorization: Bearer ' . $this->getToken());

        $data = [
            'publisher' => $publisherId,
            'email' => $email
        ];

        $dataSources = $this->curl->executeQuery(
            $this->getDataSourcesByEmailUrl,
            'GET',
            $header,
            $data
        );

        $this->curl->close();
        $dataSources = json_decode($dataSources, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('json decoding error when get List DataSources By Email');
        }

        if (array_key_exists('code', $dataSources) && $dataSources['code'] != 200) {
            throw new \Exception(sprintf('failed to get List DataSources By Email, code %d', $dataSources['code']));
        }

        return $dataSources;
    }

    /**
     * @inheritdoc
     */
    public function getListDataSourcesByIntegration($publisherId, $demandPartnerCName)
    {
        $header = array('Authorization: Bearer ' . $this->getToken());

        $data = [
            'publisher' => $publisherId,
            'integration' => $demandPartnerCName
        ];

        $dataSources = $this->curl->executeQuery(
            $this->getDataSourcesByIntegrationUrl,
            'GET',
            $header,
            $data
        );

        $this->curl->close();
        $dataSources = json_decode($dataSources, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('json decoding error when get List DataSources By Integration');
        }

        if (array_key_exists('code', $dataSources) && $dataSources['code'] != 200) {
            throw new \Exception(sprintf('failed to get List DataSources By Integration, code %d', $dataSources['code']));
        }

        return $dataSources;
    }

    /**
     * @inheritdoc
     */
    public function postFileToURApiForDataSourcesViaEmailWebHook($file, array $metadata, $dataSourceIds)
    {
        return $this->postFileToURApiForMultipleDataSources($file, $metadata, $dataSourceIds, self::VIA_MODULE_EMAIL_WEB_HOOK);
    }

    /**
     * @inheritdoc
     */
    public function postFileToURApiForDataSourcesViaFetcher($file, array $metadata, $dataSourceIds)
    {
        return $this->postFileToURApiForMultipleDataSources($file, $metadata, $dataSourceIds, self::VIA_MODULE_FETCHER);
    }

    /**
     * @inheritdoc
     */
    public function postFileToURApiForMultipleDataSources($file, array $metadata, $dataSourceIds, $viaModule = self::VIA_MODULE_EMAIL_WEB_HOOK)
    {
        /* get token */
        $header = array('Authorization: Bearer ' . $this->getToken());

        /* post file to data sources */
        $url = $this->urReceiveFileUrl;
        //$url = $url . '?' . http_build_query(['XDEBUG_SESSION_START' => 1]); // for debug with ur api

        $ch = curl_init();
        $header[] = 'Content-Type:multipart/form-data';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'source' => $viaModule === self::VIA_MODULE_EMAIL_WEB_HOOK ? 'email' : 'integration',
            'ids' => json_encode($dataSourceIds),
            'metadata' => json_encode($metadata),
            'file_content' => curl_file_create($file)
        ));
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file));
        $postResult = curl_exec($ch);
        curl_close($ch);

        if ($this->checkIfHttp413($postResult)) {
            // post failed to data source due to file too large
            return sprintf('Posted file %s fail to unified report api for %d data sources, code %d (file too large)', $file, count($dataSourceIds), 413);
        }

        $postResult = json_decode($postResult, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // json decoding error
            return sprintf('Posted file %s fail to unified report api for %d data sources cause by json response error', $file, count($dataSourceIds));
        }

        if (array_key_exists('code', $postResult) && $postResult['code'] != 200) {
            // post failed to data source
            return sprintf('Posted file %s fail to unified report api for %d data sources, code %d', $file, count($dataSourceIds), $postResult['code']);
        }

        $numbSuccess = 0;
        $errorDetail = '';
        foreach ($postResult as $postDSResult) {
            if (!is_array($postDSResult) || count($postDSResult) < 1) {
                continue;
            }

            // current post one file, hence get result contain one element only
            $postDSResult_i = $postDSResult[0];
            if (!array_key_exists('status', $postDSResult_i)) {
                continue;
            }

            $isPostSuccess = (bool)$postDSResult_i['status'];
            $numbSuccess += $isPostSuccess ? 1 : 0;

            // get message detail when fail
            if (!$isPostSuccess) {
                $dataSourceId = (array_key_exists('dataSource', $postDSResult_i))
                    ? $postDSResult_i['dataSource']
                    : 'unknown';

                $message = (array_key_exists('message', $postDSResult_i))
                    ? $postDSResult_i['message']
                    : 'unknown';
                $errorDetail = $errorDetail . sprintf('[dataSource: %s, error: %s]', $dataSourceId, $message);
            }
        }
        $numbFail = count($dataSourceIds) - $numbSuccess;

        return sprintf('Posted file %s to unified report api: %d data sources successfully, %d data sources fail. Error: %s',
            $file,
            $numbSuccess,
            $numbFail,
            ($numbFail > 0 ? $errorDetail : 'none')
        );
    }

    /**
     * check if file too large (http code 413)
     *
     * @param string $postResult html response
     * @return bool|int
     */
    private function checkIfHttp413($postResult)
    {
        /*
         * <html>
         * <head><title>413 Request Entity Too Large</title></head>
         * <body bgcolor="white">
         * <center><h1>413 Request Entity Too Large</h1></center>
         * <hr><center>nginx/1.10.2</center>
         * </body>
         * </html>
         */
        if (empty($postResult) || !is_string($postResult)) {
            return false;
        }

        return false !== strpos($postResult, '<head><title>413');
    }
}