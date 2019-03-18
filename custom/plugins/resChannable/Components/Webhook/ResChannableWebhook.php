<?php

namespace resChannable\Components\Webhook;

use Doctrine\DBAL\Connection;
use Shopware\Bundle\StoreFrontBundle\Service\Core\ContextService;
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
     * @var ContextService
     */
    private $contextService;

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
     * @param Struct\ShopContextInterface         $contextService
     * @param CachedConfigReader                  $configReader
     * @param Service\ListProductServiceInterface $listProductService
     * @param Service\AdditionalTextServiceInterface $additionalTextService
     */
    public function __construct(
        Connection $connection,
        ModelManager $entityManager,
        Struct\ShopContextInterface $contextService,
        CachedConfigReader $configReader,
        Service\ListProductServiceInterface $listProductService,
        Service\AdditionalTextServiceInterface $additionalTextService
    )
    {
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        $this->contextService = $contextService;
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
        $article = $detail->getArticle();
        $detailId = $detail->getId();

        $prices = $this->getPrices($detailId,$article->getTax()->getTax());

        if ( !$config['apiAllowRealTimeUpdates'] )
            return;

        if ( !$config['apiAllArticles'] ) {

            $builder = $this->entityManager->createQueryBuilder();

            $builder->select(array(
                'ChannableArticle',
                'article',
                'detail'
            ))
                ->from('resChannable\Models\resChannableArticle\resChannableArticle', 'ChannableArticle')
                ->join('ChannableArticle.detail', 'detail')
                ->join('detail.article', 'article');

            $builder->where('detail.id = :id')
                ->setParameter('id', $detailId);

            # only articles with an ean
            if ( $config['apiOnlyArticlesWithEan'] ) {

                $builder->andWhere("detail.ean != ''");

            }

            $article = $builder->getQuery()
                ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

            if ( !$article )
                return;

        }

        $item = array();
        $item['id'] = $detailId;
        $item['stock'] = $detail->getInStock();
        $item['price'] = $prices[0]['price'];

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

}
