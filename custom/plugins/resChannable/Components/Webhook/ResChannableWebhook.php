<?php

namespace resChannable\Components\Webhook;

use Doctrine\DBAL\Connection;
use Shopware\Components\Model\ModelManager;
use Shopware\Components\Plugin\CachedConfigReader;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\Bundle\StoreFrontBundle\Service;

class ResChannableWebhook
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ModelManager
     */
    private $entityManager;

    /**
     * @var CachedConfigReader
     */
    private $configReader;

    /**
     * @var Service\ListProductServiceInterface
     */
    private $listProductService;

    /**
     * @var Service\AdditionalTextServiceInterface
     */
    private $additionalTextService;

    /**
     * Plugin config
     * @var array
     */
    private $config = null;

    /**
     * @param Connection                          $connection
     * @param ModelManager                        $entityManager
     * @param CachedConfigReader                  $configReader
     * @param Service\ListProductServiceInterface $listProductService
     * @param Service\AdditionalTextServiceInterface $additionalTextService
     */
    public function __construct(
        Connection $connection,
        ModelManager $entityManager,
        CachedConfigReader $configReader,
        Service\ListProductServiceInterface $listProductService,
        Service\AdditionalTextServiceInterface $additionalTextService
    )
    {
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        $this->configReader = $configReader;
        $this->listProductService = $listProductService;
        $this->additionalTextService = $additionalTextService;
    }

    /**
     * Update Channable product data
     *
     * @param $number
     * @param \Shopware\Models\Shop\Shop $shop
     */
    public function updateChannable($number, $shop)
    {
        $config = $this->_getPluginConfig($shop);

        # Check webhook url
        if ( !$config['apiWebhookUrl'] || !$config['apiAllowRealTimeUpdates'] )
            return;

        # Get article data
        $article = $this->_getArticleData($number, $shop);

        # Do nothing if article data not found
        if ( !$article )
            return;

        # Post stock data
        $this->_postData(array($article),$config['apiWebhookUrl']);
    }

    /**
     * Update Channable product data for all relevant shops
     *
     * @param $number
     */
    public function updateChannableForAllShops($number)
    {
        $shops = $this->getShopRepository()->getActiveShops();

        foreach ( $shops as $shop ) {

            $config = $this->configReader->getByPluginName('resChannable', $shop);

            if ( !$config['apiAllowRealTimeUpdates'] || !$config['apiWebhookUrl'] )
                continue;

            # Get article data
            $article = $this->_getArticleData($number, $shop);

            # Do nothing if article data not found
            if ( !$article )
                continue;

            # Post stock data
            $this->_postData(array($article), $config['apiWebhookUrl']);
        }
    }

    /**
     * Get plugin config
     *
     * @return array|mixed
     */
    private function _getPluginConfig($shop)
    {
        if ( $this->config === null ) {

            $this->config = $this->configReader->getByPluginName('resChannable', $shop);

        }

        return $this->config;
    }

    /**
     * Get article data for webhook post
     *
     * @param $number
     * @param \Shopware\Models\Shop\Shop $shop
     *
     * @return array|void
     */
    private function _getArticleData($number, $shop)
    {
        $config = $this->_getPluginConfig();

        $detail = $this->getDetailRepository()->findOneBy(array('number' => $number));
        /** @var \Shopware\Models\Article\Article $article */
        $article = $detail->getArticle();
        $detailId = $detail->getId();
        $articleId = $article->getId();

        if ( !$config['apiAllowRealTimeUpdates'] )
            return;

        $translations = $this->getTranslations($articleId,$shop->getId());
        $prices = $this->getPrices($detailId,$article->getTax()->getTax());
        $inStock = $detail->getInStock();

        $item = array();
        $item['id'] = $detailId;
        $item['articleId'] = $articleId;
        $item['number'] = $number;
        $item['name'] = $article->getName();
        $item['stock'] = $inStock;
        $item['stockTracking'] = ( $article->getLastStock() === true );
        $item['price'] = $prices[0]['price'];
        $item['ean'] = $detail->getEan();

        if ( !empty($translations['name']) ) {
            $item['name'] = $translations['name'];
        }

        return $item;
    }

    /**
     * Internal helper function to load the article main detail prices into the backend module.
     *
     * @param $id
     * @param $tax
     *
     * @return array
     */
    protected function getPrices($id, $tax)
    {
        $prices = $this->getDetailRepository()
            ->getPricesQuery($id)
            ->getArrayResult();

        return $this->formatPricesFromNetToGross($prices, $tax);
    }

    /**
     * Internal helper function to convert gross prices to net prices.
     *
     * @param $prices
     * @param $tax
     *
     * @return array
     */
    protected function formatPricesFromNetToGross($prices, $tax)
    {
        foreach ($prices as $key => $price) {
            $customerGroup = $price['customerGroup'];
            if ($customerGroup['taxInput']) {
                $price['price'] = $price['price'] / 100 * (100 + $tax);
                $price['pseudoPrice'] = $price['pseudoPrice'] / 100 * (100 + $tax);
            }
            $prices[$key] = $price;
        }

        return $prices;
    }

    /**
     * Internal helper function to get access to the article repository.
     *
     * @return Shopware\Models\Article\Repository
     */
    protected function getDetailRepository()
    {
        return $this->entityManager->getRepository('Shopware\Models\Article\Detail');
    }

    /**
     * Get shop repository
     *
     * @return \Shopware\Models\Shop\Repository
     */
    public function getShopRepository()
    {
        return $this->entityManager->getRepository('Shopware\Models\Shop\Shop');
    }

    /**
     * Post data to Channable webhook url
     *
     * @param $data
     */
    private function _postData($data, $url)
    {
        $config = $this->_getPluginConfig();

        # Check webhook url
        if ( !$config['apiWebhookUrl'] )
            return;

        # JSON encoding
        $data = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data))
        );
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $result = curl_exec($ch);
    }

    /**
     * Get article translations
     *
     * @param $articleId
     * @param $shopId
     * @return array
     */
    private function getTranslations($articleId,$shopId)
    {
        $builder = $this->connection->createQueryBuilder();
        $builder->select(array(
            'translations.languageID','locales.language','locales.locale','translations.name',
            'translations.description','translations.description_long as descriptionLong',
            'translations.keywords'
        ));
        $builder->from('s_articles_translations', 'translations');
        $builder->innerJoin('translations','s_core_shops','shops','translations.languageID = shops.id');
        $builder->innerJoin('shops','s_core_locales','locales','shops.locale_id = locales.id');
        $builder->where('translations.articleID = :articleId');
        $builder->andWhere('translations.languageID = :languageID');
        $builder->setParameter('articleId',$articleId);
        $builder->setParameter('languageID',$shopId);

        $statement = $builder->execute();
        $languages = $statement->fetch(\PDO::FETCH_ASSOC);

        return $languages;
    }

}
