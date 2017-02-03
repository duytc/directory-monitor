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
    private $urReceiveFileViaEmailWebHookUrl;
    private $urReceiveFileViaFetcherUrl;

    /**
     * store last token to save requests to api
     * @var string|null
     */
    private $token = null;

    const DEBUG = 0;

    function __construct(CurlRestClient $curl, $username, $password, $getTokenUrl,
                         $getDataSourcesByIntegrationUrl, $getDataSourcesByEmailUrl,
                         $urReceiveFileViaEmailWebHookUrl, $urReceiveFileViaFetcherUrl)
    {
        $this->username = $username;
        $this->password = $password;
        $this->curl = $curl;
        $this->getTokenUrl = $getTokenUrl;
        $this->getDataSourcesByIntegrationUrl = $getDataSourcesByIntegrationUrl;
        $this->getDataSourcesByEmailUrl = $getDataSourcesByEmailUrl;
        $this->urReceiveFileViaEmailWebHookUrl = $urReceiveFileViaEmailWebHookUrl;
        $this->urReceiveFileViaFetcherUrl = $urReceiveFileViaFetcherUrl;
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
    public function postFileToURApiForDataSourcesViaEmailWebHook($file, $dataSourceIds)
    {
        return $this->postFileToURApiForMultipleDataSources($file, $dataSourceIds, self::VIA_MODULE_EMAIL_WEB_HOOK);
    }

    /**
     * @inheritdoc
     */
    public function postFileToURApiForDataSourcesViaFetcher($file, $dataSourceIds)
    {
        return $this->postFileToURApiForMultipleDataSources($file, $dataSourceIds, self::VIA_MODULE_FETCHER);
    }

    /**
     * @inheritdoc
     */
    public function postFileToURApiForMultipleDataSources($file, $dataSourceIds, $viaModule = self::VIA_MODULE_EMAIL_WEB_HOOK)
    {
        /* get token */
        $header = array('Authorization: Bearer ' . $this->getToken());

        /* post file to data sources */
        $url = $viaModule === self::VIA_MODULE_FETCHER ? $this->urReceiveFileViaFetcherUrl : $this->urReceiveFileViaEmailWebHookUrl;
        //$url = $url . '?' . http_build_query(['XDEBUG_SESSION_START' => 1]); // for debug with ur api

        $ch = curl_init();
        $header[] = 'Content-Type:multipart/form-data';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'ids' => json_encode($dataSourceIds),
            'file_content' => curl_file_create($file)
        ));
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file));
        $postResult = curl_exec($ch);
        curl_close($ch);

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
        foreach ($postResult as $postDSResult) {
            if (!is_array($postDSResult) || count($postDSResult) < 1) {
                continue;
            }

            // current post one file, hence get result contain one element only
            $postDSResult_i = $postDSResult[0];
            if (!array_key_exists('status', $postDSResult_i)) {
                continue;
            }

            $numbSuccess += (bool)$postDSResult_i['status'] ? 1 : 0;
        }
        $numbFail = count($dataSourceIds) - $numbSuccess;

        return sprintf('Posted file %s to unified report api: %d data sources successfully, %d data sources fail', $file, $numbSuccess, $numbFail);
    }
}