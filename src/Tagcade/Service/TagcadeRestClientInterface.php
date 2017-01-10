<?php

namespace Tagcade\Service;


interface TagcadeRestClientInterface
{
    /**
     * get Token
     *
     * @param bool $force
     * @return mixed
     */
    public function getToken($force = false);

    /**
     * get list data sources
     *
     * @param $publisherId
     * @param $email
     *
     * @return array|bool false if no data sources found
     */
    public function getListDataSourcesByEmail($publisherId, $email);

    /**
     * get list data sources
     *
     * @param $publisherId
     * @param $demandPartnerCName
     *
     * @return array|bool false if no data sources found
     */
    public function getListDataSourcesByIntegration($publisherId, $demandPartnerCName);

    /**
     * @param string $file file path
     * @param string $dataSourceIds
     * @return array [code => <http code>, message => <mixed, message>]
     */
    public function postFileToURApiForDataSourcesViaEmailWebHook($file, $dataSourceIds);

    /**
     * @param string $file file path
     * @param string $dataSourceIds
     * @return array [code => <http code>, message => <mixed, message>]
     */
    public function postFileToURApiForDataSourcesViaFetcher($file, $dataSourceIds);
}