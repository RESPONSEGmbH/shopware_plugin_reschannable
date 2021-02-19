<?php

use Shopware\Components\Api\Exception\NotFoundException;
use Shopware\Components\Api\Exception\ParameterMissingException;
use Shopware\Components\Api\Manager;
use Symfony\Component\DependencyInjection\Container;

/**
 * Channable API controller
 */
class Shopware_Controllers_Api_resChannableApi extends Shopware_Controllers_Api_Rest
{

    /**
     * Allowed functions
     * @var array
     */
    private $allowedFncs = array('getarticles','setwebhookurl');

    /**
     * @var \resChannable\Components\Api\Resource\ResChannableArticle
     */
    protected $channableArticleResource = null;

    /**
     * @var \Shopware\Components\Api\Resource\Media
     */
    protected $mediaResource = null;

    /**
     * @var Shopware_Components_Translation
     */
    protected $translationComponent = null;

    /**
     * @var array $configUnits
     */
    protected $configUnits = null;

    /**
     * @var \Shopware\Models\Shop\Shop
     */
    protected $shop = null;

    /**
     * @var \Shopware\Models\Shop\Shop
     */
    protected $mainShop = null;

    /**
     * @var int $shopId
     */
    protected $shopId = null;

    /**
     * @var int $mainShopId
     */
    protected $mainShopId = null;

    /**
     * @var string
     */
    protected $sSYSTEM = null;

    /**
     * @var Shopware_Components_Config
     */
    protected $config = null;

    /**
     * @var sAdmin
     */
    protected $admin = null;

    /**
     * @var sExport
     */
    protected $export = null;

    /**
     * @var Shopware_Components_Modules
     */
    protected $moduleManager = null;

    /**
     * @var array
     */
    private $paymentMethods = null;

    /**
     * @var \Shopware\Components\Plugin\CachedConfigReader
     */
    private $pluginConfig = null;

    /**
     * @var array
     */
    private $articleAttributeConfig = array();

    /**
     * Init function
     *
     * @throws \Exception
     */
    public function init()
    {
        # load certain shop
        $shopId = $this->Request()->getParam('shop');
        $repository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
        $this->shop = $repository->getActiveById($shopId);

        # load default shop if shop is not set
        if ( !$this->shop && !$shopId )
            $this->shop = $repository->getActiveDefault();

        # throw exception if shop loading failed
        if ( !$this->shop )
            throw new NotFoundException('Shop not found');

        $this->shop->registerResources(Shopware()->Container());

        $this->shopId = $this->shop->getId();

        $this->mainShop = $this->shop->getMain();

        if ( $this->mainShop )
            $this->mainShopId = $this->mainShop->getId();
        else
            $this->mainShopId = $this->shopId;

        $this->admin = Shopware()->Modules()->Admin();
        $this->export = Shopware()->Modules()->Export();

        $this->setContainer(Shopware()->Container());

        $this->pluginConfig = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('resChannable', $this->shop);

        $articleAttributes = $this->container->get('shopware_attribute.crud_service')->getList('s_articles_attributes');

        foreach ($articleAttributes as $attribute) {
            if ( !$attribute->isIdentifier() && $attribute->isConfigured() ) {
                $this->articleAttributeConfig[lcfirst(Container::camelize($attribute->getColumnName()))] = array(
                    'label' => $attribute->getLabel(),
                    'columnName' => $attribute->getColumnName()
                );
            }
        }

        $this->channableArticleResource = Manager::getResource('ResChannableArticle');
        $this->mediaResource = Manager::getResource('Media');
        $this->config = Shopware()->Config();

        if (version_compare($this->config->get('version'), '5.6.0', '>='))
            $this->translationComponent = new Shopware_Components_Translation($this->container->get('dbal_connection'),$this->container);
        else
            $this->translationComponent = new Shopware_Components_Translation();

        $this->configUnits = array_shift(array_values($this->translationComponent->read($this->shopId,'config_units')));

        $this->sSYSTEM = Shopware()->System();

        $this->moduleManager = $this->container->get('Modules');

        $this->loadPaymentMethods();
    }

    /**
     * Index action
     *
     * @throws ParameterMissingException
     */
    public function indexAction()
    {
        $fnc = $this->Request()->getParam('fnc');

        if ( !in_array($fnc,$this->allowedFncs))
            throw new ParameterMissingException('fnc');

        $result = array();

        switch ($fnc) {

            case 'getarticles':

                $articleList = $this->getArticleList();

                $result['articles'] = $articleList;

                break;

            case 'setwebhookurl':

                $url = $this->Request()->getParam('url');

                $this->_saveWebHookUrl($url);

                break;
        }

        $this->View()->assign($result);
        $this->View()->assign('success', true);
    }

    /**
     * Get article list
     *
     * @return array
     */
    private function getArticleList()
    {
        $articleIdList = $this->getArticleIdList();

        $result = array();

        $articleCnt = count($articleIdList);
        for ($i = 0; $i < $articleCnt; $i++) {

            $detail = $articleIdList[$i];

            $article = $detail['article'];
            $articleId = $detail['articleId'];

            # Image check here because of performance issues
            $variantImages = $this->channableArticleResource->getDetailImages($detail['id']);

            $imageArticle = $this->channableArticleResource->getArticleImages($articleId);
            $images = array_merge($variantImages,$imageArticle);

            # If plugin setting "only articles with images" is set
            if ( $this->pluginConfig['apiOnlyArticlesWithImg'] && empty($images) )
                continue;

            # Replace translations if exist
            $translationVariant = $this->translationComponent->read($this->shopId,'variant',$detail['id']);
            $translations = $this->translationComponent->read($this->shopId,'article',$articleId);

            if ( !empty($translations['name']) )
                $article['name'] = $translations['name'];
            if ( !empty($translations['description']) )
                $article['description'] = $translations['description'];
            if ( !empty($translations['descriptionLong']) )
                $article['descriptionLong'] = $translations['descriptionLong'];
            if ( !empty($translations['keywords']) )
                $article['keywords'] = $translations['keywords'];
            if ( !empty($translationVariant['additionalText']) )
                $detail['additionalText'] = $translationVariant['additionalText'];
            if ( !empty($translationVariant['packUnit']) )
                $detail['packUnit'] = $translationVariant['packUnit'];
            if ( !empty($this->configUnits['unit']) && !empty($detail['unit']['unit']) )
                $detail['unit']['unit'] = $this->configUnits['unit'];
            if ( !empty($this->configUnits['description']) && !empty($detail['unit']['name']) )
                $detail['unit']['name'] = $this->configUnits['description'];

            $item = array();

            $item['id'] = $detail['id'];
            $item['groupId'] = $detail['articleId'];
            $item['articleNumber'] = $detail['number'];
            $item['active'] = $detail['active'];
            $item['name'] = $article['name'];
            $item['additionalText'] = $detail['additionalText'];
            $item['supplier'] = $article['supplier']['name'];
            $item['supplierNumber'] = $detail['supplierNumber'];
            $item['ean'] = $detail['ean'];
            $item['description'] = $article['description'];
            $item['keywords'] = $article['keywords'];
            $item['descriptionLong'] = $article['descriptionLong'];

            $item['releaseDate'] = $detail['releaseDate'];

            $item['is_variant'] = ($article['configuratorSetId'] > 0);

            # Images
            $item['images'] = $this->getArticleImagePaths($images);

            if ($variantImages) {
                $item['variant_images'] = $this->getArticleImagePaths($variantImages);
            } else {
                $item['variant_images'] = array();
            }

            # Links
            $links = $this->getArticleLinks($articleId,$article['name'],$detail['number']);
            $item['seoUrl'] = $links['seoUrl'];
            $item['url'] = $links['url'];
            $item['rewriteUrl'] = $links['rewrite'];

            # stock
            # Only show stock if instock exceeds minpurchase
            if ( $detail['inStock'] >= $detail['minPurchase'])
                $item['stock'] = $detail['inStock'];
            else
                $item['stock'] = 0;
            $item['minPurchase'] = $detail['minPurchase'];
            $item['maxPurchase'] = $detail['maxPurchase'];
            $item['minStock'] = $detail['stockMin'];

            # Article not buyable if stock <= 0
            $item['lastStock'] = $detail['lastStock'];

            # Price
            $item['purchasePrice'] = ($detail['purchasePrice'] > 0 ? (float) $detail['purchasePrice'] : '' );
            $item['prices'] = $this->channableArticleResource->getPrices(
                $detail['id'],
                $article['tax']['tax'],
                $this->shop->getCustomerGroup()->getId(),
                $this->shop->getCustomerGroup()->getTax(),
                true
            );

            # Set first price of price list in root
            if ( $item['prices'] ) {
                foreach ( $item['prices'] as $priceGroup ) {
                    foreach ( $priceGroup as $price ) {
                        $item['priceNetto'] = $price['priceNetto'];
                        $item['priceBrutto'] = $price['priceBrutto'];
                        $item['pseudoPriceNetto'] = $price['pseudoPriceNetto'];
                        $item['pseudoPriceBrutto'] = $price['pseudoPriceBrutto'];
                        break;
                    }
                    break;
                }
            }

            # Pricelists
            $item['additionalPrices'] = $this->getAdditionalPrices($detail['id'],$article['tax']['tax']);

            $item['currency'] = $this->shop->getCurrency()->getCurrency();
            $item['taxRate'] = $article['tax']['tax'];

            # Delivery time text
            $item['shippingTime'] = $detail['shippingTime'];
            $item['shippingTimeText'] = $this->getShippingTimeText($detail);
            $item['shippingFree'] = $detail['shippingFree'];

            $item['weight'] = $detail['weight'];
            $item['width'] = $detail['width'];
            $item['height'] = $detail['height'];
            $item['length'] = $detail['len'];

            # Units
            $item['packUnit'] = $detail['packUnit'];
            $item['purchaseUnit'] = $detail['purchaseUnit'];
            $item['referenceUnit'] = $detail['referenceUnit'];
            if ( isset($detail['unit']) ) {
                $item['unit'] = $detail['unit']['unit'];
                $item['unitName'] = $detail['unit']['name'];
            }

            # Categories
            $item['categories'] = $this->getArticleCategories($articleId);

            # SEO categories
            $item['seoCategory'] = $this->getArticleSeoCategory($articleId);

            # Shipping costs - disabled regarding individual shipping errors
            #$item['shippingCosts'] = $this->getShippingCosts($detail);
            $item['shippingCosts'] = 0;

            # Properties
            if (!empty($this->pluginConfig['properties'])) {
                foreach ($this->getArticleProperties($detail['id']) as $sKey => $sValue) {
                    $item['properties'][$sKey] = $sValue;
                }
            } else {
                $item['properties'] = array();
            }

            # Configuration
            $item['options'] = $this->getDetailConfiguratorOptions($detail['id']);

            # Similar
            $item['similar'] = $this->channableArticleResource->getArticleSimilar($articleId);

            # Related
            $item['related'] = $this->channableArticleResource->getArticleRelated($articleId);

            # Excluded customer groups
            $item['excludedCustomerGroups'] = $this->getExcludedCustomerGroups($detail['id']);

            # Article attributes
            if ( $detail['attribute'] ) {

                foreach ( $detail['attribute'] as $attrField => $sAttrValue ) {

                    if ( $sAttrValue == "" || $attrField == "id" || $attrField == "articleId" || $attrField == "articleDetailId" )
                        continue;

                    if ( $attrField == "pickwarePhysicalStockForSale" ) {

                        $item['pickware']['physicalStockForSale'] = $detail['attribute']['pickwarePhysicalStockForSale'];
                        $item['pickware']['reservedStock'] = ($detail['attribute']['pickwarePhysicalStockForSale'] - $detail['inStock']);

                        continue;
                    }

                    $attrKey = lcfirst(Container::camelize($attrField));

                    $item['attributes'][$attrKey] = $sAttrValue;

                    $attrLngKey = '__attribute_'.$this->camelCaseToUnderscore($attrField);

                    # Set translations if available
                    if ( !empty($translationVariant[$attrLngKey]) ) {
                        $item['attributes'][$attrKey] = $translationVariant[$attrLngKey];
                    } elseif ( !empty($translations[$attrLngKey]) ) {
                        $item['attributes'][$attrKey] = $translations[$attrLngKey];
                    } else {
                        $item['attributes'][$attrKey] = $sAttrValue;
                    }
                }
            }

            # Added
            /** @var \DateTime $addedDate */
            $addedDate = $article['added'];
            $added = '';
            if ( $addedDate instanceof \DateTime )
                $added = $addedDate->format('Y-m-d H:i:s');
            $item['added'] = $added;

            # Changed
            /** @var \DateTime $changedDate */
            $changedDate = $article['changed'];
            $changed = '';
            if ( $changedDate instanceof \DateTime )
                $changed = $changedDate->format('Y-m-d H:i:s');
            $item['changed'] = $changed;

            # Notification
            $item['notification'] = $article['notification'];

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Get article id list
     *
     * @return array
     */
    private function getArticleIdList()
    {
        $limit = $this->pluginConfig['apiPollLimit'];
        $offset = $this->Request()->getParam('offset');
        $sort = '';

        $this->View()->assign('offset', $offset);
        $this->View()->assign('limit', $limit);

        $filter = array();

        # filter category id
        $categoriesId = $this->shop->getCategory()->getId();

        $filter[] = array(
            'property'   => 'categories.id',
            'expression' => '=',
            'value'      => $categoriesId
        );

        # only active articles
        if ( $this->pluginConfig['apiOnlyActiveArticles'] ) {
            $filter[] = array(
                'property'   => 'article.active',
                'expression' => '=',
                'value'      => '1'
            );
            $filter[] = array(
                'property'   => 'detail.active',
                'expression' => '=',
                'value'      => '1'
            );
        }

        # only articles with an ean
        if ( $this->pluginConfig['apiOnlyArticlesWithEan'] ) {
            $filter[] = array(
                'property'   => 'detail.ean',
                'expression' => '!=',
                'value'      => ''
            );
        }

        # Get article list
        $result = $this->channableArticleResource->getList($offset, $limit, $filter, $sort);

        return $result['data'];
    }

    /**
     * Get article image paths
     *
     * @param $articleImages
     * @return array
     */
    private function getArticleImagePaths($articleImages)
    {
        $images = array();

        $imageCnt = count($articleImages);
        for ( $i = 0; $i < $imageCnt; $i++ ) {

            try {

                if ($articleImages[$i]['mediaId']) {

                    $image = $this->mediaResource->getOne($articleImages[$i]['mediaId']);
                    $images[] = $image['path'];

                } elseif ( !empty($articleImages[$i]['parent']) && $articleImages[$i]['parent']['mediaId'] ) {

                    $image = $this->mediaResource->getOne($articleImages[$i]['parent']['mediaId']);
                    $images[] = $image['path'];

                }

            } catch ( \Exception $Exception ) {

            }
        }

        return $images;
    }

    /**
     * Helper function which selects all configured links
     * for the passed article id.
     *
     * @param $articleId
     * @param $name
     * @param $number
     *
     * @return array
     */
    protected function getArticleLinks($articleId,$name,$number)
    {
        $baseFile = $this->getBasePath();
        $detail = $baseFile . '?sViewport=detail&sArticle=' . $articleId . '&number='.$number;

        $rewrite = Shopware()->Modules()->Core()->sRewriteLink($detail, $name);

        $seoUrl = $baseFile . $this->channableArticleResource->getArticleSeoUrl($articleId,$this->shopId) . '?number='.$number;

        $links = array('rewrite' => $rewrite,
                       'url'  => $detail,
                       'seoUrl' => $seoUrl);

        return $links;
    }

    /**
     * Get base path
     *
     * @return string
     */
    private function getBasePath()
    {
        $url = $this->Request()->getBaseUrl() . '/';
        $uri = $this->Request()->getScheme() . '://' . $this->Request()->getHttpHost();
        $url = $uri . $url;

        return $url;
    }

    /**
     * Get shipping time text
     *
     * @param $detail
     * @return mixed|string
     */
    private function getShippingTimeText($detail)
    {

        if ( isset($detail['active']) && !$detail['active'] ) {

            $shippingTime = Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataInfoNotAvailable'
            );

        } elseif ( $detail['releaseDate'] instanceOf \DateTime && $detail['releaseDate']->getTimestamp() > time() ) {

            $dateFormat = Shopware()->Snippets()->getNamespace('api/resChannable')->get(
                'dateFormat'
            );

            $shippingTime = Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataInfoShipping'
            ) . ' ' . date($dateFormat);

            # Todo ESD, partial stock
            /*} elseif ( $detail['esd'] ) {
                /*<link itemprop="availability" href="http://schema.org/InStock" />
                <p class="delivery--information">
                    <span class="delivery--text delivery--text-available">
                        <i class="delivery--status-icon delivery--status-available"></i>
                        {s name="DetailDataInfoInstantDownload"}{/s}
                    </span>
                </p>
        } elseif {config name="instockinfo"} && $sArticle.modus == 0 && $sArticle.instock > 0 && $sArticle.quantity > $sArticle.instock}
            <link itemprop="availability" href="http://schema.org/LimitedAvailability" />
            <p class="delivery--information">
                <span class="delivery--text delivery--text-more-is-coming">
                    <i class="delivery--status-icon delivery--status-more-is-coming"></i>
                    {s name="DetailDataInfoPartialStock"}{/s}
                </span>
            </p>*/
        } elseif ( $detail['inStock'] >= $detail['minPurchase'] ) {

            $shippingTime = Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataInfoInstock'
            );

        } elseif ( $detail['shippingTime'] ) {

            $shippingTime = Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataShippingtime'
            ) . ' ' . $detail['shippingTime'] . ' ' . Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataShippingDays'
            );
        } else {

            $shippingTime = Shopware()->Snippets()->getNamespace('frontend/plugins/index/delivery_informations')->get(
                'DetailDataNotAvailable'
            );
        }

        return $shippingTime;
    }

    /**
     * Get article categories
     *
     * @param $articleId
     * @return array
     */
    private function getArticleCategories($articleId)
    {
        $categories = $this->channableArticleResource->getArticleCategories($articleId,$this->shop->getCategory()->getId());

        $em = $this->getModelManager();
        $categoryRepo = $em->getRepository('Shopware\Models\Category\Category');

        $categoryList = array();

        foreach ( $categories as $category )
        {
            $path = $categoryRepo->getPathById($category['id']);

            $categoryList[] = array_values($path);
        }

        return $categoryList;
    }

    /**
     * Get article seo category
     *
     * @param $articleId
     * @return array
     */
    private function getArticleSeoCategory($articleId)
    {
        $category = $this->channableArticleResource->getArticleSeoCategory($articleId,$this->shopId);

        $em = $this->getModelManager();
        $categoryRepo = $em->getRepository('Shopware\Models\Category\Category');

        $path = array_values($categoryRepo->getPathById($category['categoryId']));

        return $path;
    }

    /**
     * Get shipping costs
     *
     * @param $detail
     * @return array
     */
    public function getShippingCosts($detail)
    {
        $paymentMethods = $this->getPaymentMethods();

        $article = array('articleID' => $detail['articleId'],
                         'ordernumber' => $detail['number'],
                         'shippingfree' => $detail['shippingFree'],
                         'price' => $detail['prices'][0]['price'] * (($detail['article']['tax']['tax'] + 100) / 100),
                         'netprice' => $detail['prices'][0]['price'],
                         'esd' => 0
        );

        $this->export->sCurrency['factor'] = $this->shop->getCurrency()->getFactor();

        $payment = $paymentMethods[0]['id'];

        $country = 2;

        $shippingCosts = $this->export->sGetArticleShippingcost($article, $payment, $country);

        return $shippingCosts;
    }

    /**
     * Load payment methods
     *
     * @throws \Exception
     */
    private function loadPaymentMethods()
    {
        $builder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $builder->select(array(
            'id',
            'name'
        ));
        $builder->from('s_core_paymentmeans', 'payments');
        $builder->where('payments.active = 1');

        $statement = $builder->execute();
        $this->paymentMethods = $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get payment methods
     *
     * @return array
     */
    private function getPaymentMethods()
    {
        return $this->paymentMethods;
    }

    /**
     * Get article properties
     *
     * @param $detailId
     * @return array
     */
    private function getArticleProperties($detailId)
    {
        $detail = $this->channableArticleResource->getArticleProperties($detailId, $this->pluginConfig['properties'], $this->config->get('version'));

        $propertyValues = $detail['article']['propertyValues'];

        $properties = array();

        for ( $i = 0; $i < sizeof($propertyValues); $i++) {

            # Check option translation
            $propertyOptionLng = $this->translationComponent->read($this->shopId,'propertyoption',$propertyValues[$i]['optionId']);

            $optionName = $this->filterFieldNames($propertyValues[$i]['option']['name']);

            if ( !empty($propertyOptionLng['optionName']) )
                $propertyValues[$i]['option']['name'] = $propertyOptionLng['optionName'];

            # Check value translation
            $propertyValueLng = $this->translationComponent->read($this->shopId,'propertyvalue',$propertyValues[$i]['id']);

            if ( !empty($propertyValueLng['optionValue']) )
                $propertyValues[$i]['value'] = $propertyValueLng['optionValue'];

            $properties[$optionName][] = $propertyValues[$i]['value'];

            # Attributes
            if ( isset($propertyValues[$i]['attribute']) ) {
                foreach ( $propertyValues[$i]['attribute'] as $valAttr => $valAttrVal ) {

                    if ( $valAttr != 'id' && $valAttr != 'propertyValueId' ) {

                        $lngKey = '__attribute_'.$this->camelCaseToUnderscore($valAttr);

                        if ( isset($propertyValueLng[$lngKey]) )
                            $valAttrVal = $propertyValueLng[$lngKey];

                        $properties[$optionName . "_" . $this->filterFieldNames($valAttr)] = $valAttrVal;
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * Get detail configuration options
     *
     * @param $detailId
     * @return array
     */
    private function getDetailConfiguratorOptions($detailId)
    {
        $detail = $this->channableArticleResource->getDetailConfiguratiorOptions($detailId);

        $options = array();
        if (isset($detail['configuratorOptions'])) {

            for ($i = 0; $i < sizeof($detail['configuratorOptions']); $i++) {

                $configuratorGroup = $this->translationComponent->read($this->shopId,'configuratorgroup',$detail['configuratorOptions'][$i]['groupId']);

                if ( !empty($configuratorGroup['name']) ) {
                    $detail['configuratorOptions'][$i]['group']['name'] = $configuratorGroup['name'];
                }

                $configuratorOption = $this->translationComponent->read($this->shopId,'configuratoroption',$detail['configuratorOptions'][$i]['id']);

                if ( !empty($configuratorOption['name']) )
                    $detail['configuratorOptions'][$i]['name'] = $configuratorOption['name'];

                $options[$this->filterFieldNames($detail['configuratorOptions'][$i]['group']['name'])] = $detail['configuratorOptions'][$i]['name'];
            }
        }

        return $options;
    }

    /**
     * Get excluded customer groups
     *
     * @param $detailId
     * @return array
     */
    private function getExcludedCustomerGroups($detailId)
    {
        $grp = $this->channableArticleResource->getExcludedCustomerGroups($detailId);

        $groups = array();

        if ( $grp ) {

            for ($i = 0; $i < sizeof($grp); $i++)
                $groups[$this->filterFieldNames($grp[$i]['key'])] = $grp[$i]['name'];
        }

        return $groups;
    }

    /**
     * Remove bad chars from field names
     *
     * @param $field
     * @return string
     */
    private function filterFieldNames($field)
    {
        # replace umlauts
        $field = str_replace(array('Ä','Ö','Ü','ä','ö','ü','ß'),array('Ae','Oe','Ue','ae','oe','ue','ss'),$field);
        # strip bad chars
        $field = preg_replace('/[^0-9a-zA-Z_]+/','',$field);

        return strtolower($field);
    }

    /**
     * Save webhook url in order to activate or deactivate the webhooks
     *
     * @param $url
     * @throws ParameterMissingException
     */
    private function _saveWebHookUrl($url)
    {
        if ( !$this->shopId )
            throw new NotFoundException('Shop id not set');

        $shop = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop')->findOneBy(array('id' => $this->shopId));
        $pluginManager = Shopware()->Container()->get('shopware_plugininstaller.plugin_manager');
        $plugin = $pluginManager->getPluginByName('resChannable');
        $pluginManager->saveConfigElement($plugin, 'apiWebhookUrl', $url, $shop);
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function camelCaseToUnderscore($string)
    {
        return strtolower(ltrim(preg_replace('/[A-Z]/', '_$0', $string), '_'));
    }

    /**
     * @param int $detailID
     * @param int $tax
     *
     * @return string
     */
    private function getAdditionalPrices($detailID, $tax)
    {
        $customerGroups = $this->pluginConfig['priceLists'];

        $prices = array();
        $em = $this->getModelManager();

        foreach ( $customerGroups as $id ) {

            /** @var \Shopware\Models\Customer\Group $customerGroup */
            $customerGroup = $em->find(\Shopware\Models\Customer\Group::class, $id);

            if ( $priceList = $this->channableArticleResource->getPrices($detailID, $tax, $id, $customerGroup->getTax()) )
                $prices = array_merge($prices,$priceList);
        }

        return $prices;
    }

}
