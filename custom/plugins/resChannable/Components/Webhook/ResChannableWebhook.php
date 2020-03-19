<?php

namespace resChannable\Components\Webhook;

use Doctrine\DBAL\Connection;
use Shopware\Components\Model\ModelManager;
use Shopware\Components\Plugin\CachedConfigReader;
use Shopware\Bundle\StoreFrontBundle\Service;
use Shopware\Models\Article\Repository as ArticleRepository;
use Shopware\Models\Shop\Repository as ShopRepository;
use Shopware\Models\Shop\Shop;

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
     * @var ArticleRepository
     */
    private $detailRepository = null;

    /**
     * @var ShopRepository
     */
    private $shopRepository = null;

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
     * @param string $number
     * @param Shop $shop
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
        $this->_postData(array($article),$config['apiWebhookUrl'], $shop);
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
            $this->updateChannable($number,$shop);
        }
    }

    /**
     * Get plugin config
     *
     * @param Shop $shop
     *
     * @return array|mixed
     */
    private function _getPluginConfig($shop)
    {
        if ( $this->config === null )
            $this->config = $this->configReader->getByPluginName('resChannable', $shop);

        return $this->config;
    }

    /**
     * Get article data for webhook post
     *
     * @param string $number
     * @param Shop $shop
     *
     * @return array
     */
    private function _getArticleData($number, $shop)
    {
        $config = $this->_getPluginConfig($shop);

        $detail = $this->getDetailRepository()->findOneBy(array('number' => $number));
        if ( !$detail instanceof \Shopware\Models\Article\Detail )
            return;
        
        /** @var \Shopware\Models\Article\Article $article */
        $article = $detail->getArticle();
        $detailId = $detail->getId();
        $articleId = $article->getId();

        if ( !$config['apiAllowRealTimeUpdates'] )
            return;

        $translations = $this->_getTranslations($articleId,$shop->getId());
        $prices = $this->_getPrices($detailId,$article->getTax()->getTax());
        $additionalData = $this->_getDetailData($number);
        $ean = $detail->getEan();

        $item = array();
        $item['id'] = (int)$detailId;
        $item['articleId'] = (int)$articleId;
        $item['number'] = $number;
        $item['name'] = $article->getName();
        $item['stock'] = (int)$additionalData['instock'];
        $item['stockTracking'] = ( $article->getLastStock() === true );
        $item['price'] = $prices[0]['price'];
        $item['ean'] = ( empty($ean) ? '' : $ean );

        if ( !empty($translations['name']) ) {
            $item['name'] = $translations['name'];
        }

        # Pickware stock fields
        if ( isset($additionalData['pickware_physical_stock_for_sale']) ) {
            $item['pickware']['physicalStockForSale'] = (int)$additionalData['pickware_physical_stock_for_sale'];
            $item['pickware']['reservedStock'] = ((int)$additionalData['pickware_physical_stock_for_sale'] - (int)$additionalData['instock']);
        }

        return $item;
    }

    /**
     * Internal helper function to load the article main detail prices into the backend module.
     *
     * @param int $id
     * @param double $tax
     *
     * @return array
     */
    protected function _getPrices($id, $tax)
    {
        $prices = $this->getDetailRepository()
            ->getPricesQuery($id)
            ->getArrayResult();

        return $this->formatPricesFromNetToGross($prices, $tax);
    }

    /**
     * Internal helper function to convert gross prices to net prices.
     *
     * @param array $prices
     * @param double $tax
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
     * @return ArticleRepository
     */
    protected function getDetailRepository()
    {
        if ( $this->detailRepository === null )
            $this->detailRepository = $this->entityManager->getRepository('Shopware\Models\Article\Detail');

        return $this->detailRepository;
    }

    /**
     * Get shop repository
     *
     * @return ShopRepository
     */
    public function getShopRepository()
    {
        if ( $this->shopRepository === null )
            $this->shopRepository = $this->entityManager->getRepository('Shopware\Models\Shop\Shop');

        return $this->shopRepository;
    }

    /**
     * Post data to Channable webhook url
     *
     * @param array $data
     * @param string $url
     * @param Shop $shop
     */
    private function _postData($data, $url, $shop)
    {
        $config = $this->_getPluginConfig($shop);

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
     * @param int $articleId
     * @param int $shopId
     *
     * @return array
     */
    private function _getTranslations($articleId,$shopId)
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

    /**
     * Get detail data. Not loaded via the entity as stock will not be saved during the ordering process
     *
     * @param string $number
     * @return array
     */
    private function _getDetailData($number)
    {
        $sql = '
            SELECT IF(ad.instock < 0, 0, ad.instock) as instock, aa.*
            FROM s_articles a
            LEFT JOIN s_articles_details ad
            ON ad.ordernumber=?
            LEFT JOIN s_articles_attributes aa
            ON ad.id = aa.articledetailsID
            WHERE a.id=ad.articleID
        ';

        $detail = Shopware()->Db()->fetchRow($sql, array(
            $number
        ));

        return $detail;
    }

}
