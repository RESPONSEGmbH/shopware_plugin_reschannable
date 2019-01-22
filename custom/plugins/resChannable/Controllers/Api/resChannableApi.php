<?php

class Shopware_Controllers_Api_resChannableApi extends Shopware_Controllers_Api_Rest
{

    private $allowedFncs = array('getarticles');

    /**
     * @var \resChannable\Components\Api\Resource\ResChannableArticle
     */
    protected $channableArticleResource = null;

    /**
     * @var \Shopware\Components\Api\Resource\Article
     */
    protected $articleResource = null;

    /**
     * @var \Shopware\Components\Api\Resource\Media
     */
    protected $mediaResource = null;

    /**
     * @var \Shopware\Components\Api\Resource\Translation
     */
    protected $translationResource = null;

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

    protected $sSYSTEM = null;

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

    private $paymentMethods = null;

    private $pluginConfig = null;

    public function init()
    {
        # load certain shop
        $shopId = $this->Request()->getParam('shop');
        $repository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
        $this->shop = $repository->getActiveById($shopId);

        # load default shop if shop is not set
        if ( !$this->shop && !$shopId ) {
            $this->shop = $repository->getActiveDefault();
        }

        # throw exception if shop loading failed
        if ( !$this->shop ) {
            throw new Shopware\Components\Api\Exception\NotFoundException('Shop not found');
        }

        $this->shop->registerResources(Shopware()->Container());

        $this->shopId = $this->shop->getId();

        $this->mainShop = $this->shop->getMain();

        if ( $this->mainShop ) {
            $this->mainShopId = $this->mainShop->getId();
        } else {
            $this->mainShopId = $this->shopId;
        }

        $this->admin = Shopware()->Modules()->Admin();
        $this->export = Shopware()->Modules()->Export();

        $this->setContainer(Shopware()->Container());

        $this->pluginConfig = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('resChannable', $this->shop);

        $this->channableArticleResource = \Shopware\Components\Api\Manager::getResource('ResChannableArticle');
        $this->articleResource = \Shopware\Components\Api\Manager::getResource('Article');
        $this->mediaResource = \Shopware\Components\Api\Manager::getResource('Media');
        $this->translationResource = \Shopware\Components\Api\Manager::getResource('Translation');

        $this->translationComponent = new Shopware_Components_Translation();
        $this->configUnits = array_shift(array_values($this->translationComponent->read($this->shopId,'config_units')));

        $this->sSYSTEM = Shopware()->System();

        $this->config = Shopware()->Config();

        $this->moduleManager = $this->container->get('Modules');

        $this->loadPaymentMethods();
    }

    public function indexAction()
    {
        $fnc = $this->Request()->getParam('fnc');

        if ( !in_array($fnc,$this->allowedFncs)) {

            throw new Shopware\Components\Api\Exception\NotFoundException('Function not found');

        }

        $result = array();

        switch ($fnc) {

            case 'getarticles':

                $articleList = $this->getArticleList();

                $result['articles'] = $articleList;

                break;

        }

        $this->View()->assign($result);
        $this->View()->assign('success', true);
    }

    private function getArticleList()
    {
        $articleIdList = $this->getArticleIdList();

        $result = array();

        for ($i = 0; $i < sizeof($articleIdList); $i++) {

            $channableArticle = $articleIdList[$i];

            # fix because of the "transfer all articles" flag
            if ($channableArticle['detail']) {
                $detail = $channableArticle['detail'];
            } else {
                $detail = $channableArticle;
            }

            $article = $detail['article'];
            $articleId = $detail['articleId'];

            # Image check here because of performance issues
            $imageArticle = $this->channableArticleResource->getArticleImages($detail['id']);

            $images = $imageArticle['images'];

            # If main article without variants set article images
            if ( !$images && !$article['configuratorSetId'] ) {
                $images = $imageArticle['article']['images'];
            }

            # If plugin setting "only articles with images" is set
            if ( $this->pluginConfig['apiOnlyArticlesWithImg'] && empty($images) ) {
                continue;
            }

            # Replace translations if exist
            $translationVariant = $this->translationComponent->read($this->shopId,'variant',$articleId);
            $translations = $this->getTranslations($articleId,$this->shopId);
            if ( !empty($translations['name']) ) {
                $article['name'] = $translations['name'];
            }
            if ( !empty($translations['description']) ) {
                $article['description'] = $translations['description'];
            }
            if ( !empty($translations['descriptionLong']) ) {
                $article['descriptionLong'] = $translations['descriptionLong'];
            }
            if ( !empty($translations['keywords']) ) {
                $article['keywords'] = $translations['keywords'];
            }
            if ( !empty($translationVariant['additionalText']) ) {
                $detail['additionalText'] = $translationVariant['additionalText'];
            }
            if ( !empty($translationVariant['packUnit']) ) {
                $detail['packUnit'] = $translationVariant['packUnit'];
            }
            if ( !empty($this->configUnits['unit']) && !empty($detail['unit']['unit']) ) {
                $detail['unit']['unit'] = $this->configUnits['unit'];
            }
            if ( !empty($this->configUnits['description']) && !empty($detail['unit']['name']) ) {
                $detail['unit']['name'] = $this->configUnits['description'];
            }

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

            # Images
            $item['images'] = $this->getArticleImagePaths($images);

            # Links
            $links = $this->getArticleLinks($articleId,$article['name'],$detail['number']);
            $item['seoUrl'] = $links['seoUrl'];
            $item['url'] = $links['url'];
            $item['rewriteUrl'] = $links['rewrite'];

            # Only show stock if instock exceeds minpurchase
            if ( $detail['inStock'] >= $detail['minPurchase']) {
                $item['stock'] = $detail['inStock'];
            } else {
                $item['stock'] = 0;
            }

            # Price
            $item['prices'] = $this->channableArticleResource->getPrices($detail['id'],$article['tax']['tax'],$this->shop->getCustomerGroup()->getId());
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

            # Shipping costs
            $item['shippingCosts'] = $this->getShippingCosts($detail);

            # Properties
            $item['properties'] = $this->getArticleProperties($detail['id']);

            # Configuration
            $item['options'] = $this->getDetailConfiguratiorOptions($detail['id']);

            # Similar
            $item['similar'] = $this->channableArticleResource->getArticleSimilar($articleId);

            # Related
            $item['related'] = $this->channableArticleResource->getArticleRelated($articleId);

            # Excluded customer groups
            $item['excludedCustomerGroups'] = $this->getExcludedCustomerGroups($detail['id']);

            $result[] = $item;
        }

        return $result;
    }

    private function getArticleIdList()
    {
        $limit = $this->pluginConfig['apiPollLimit'];
        $offset = $this->Request()->getParam('offset');
        $sort = '';

        $this->View()->assign('offset', $offset);
        $this->View()->assign('limit', $limit);

        $filter = array();

        # only articles with images
        /*if ( $this->pluginConfig['apiOnlyArticlesWithImg'] ) {
            $filter[] = array(
                'property'   => 'images.id',
                'expression' => '>',
                'value'      => '0'
            );
        }*/

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
        }

        # only articles with an ean
        if ( $this->pluginConfig['apiOnlyArticlesWithEan'] ) {
            $filter[] = array(
                'property'   => 'detail.ean',
                'expression' => '!=',
                'value'      => ''
            );
        }

        # check if all articles flag is set
        if ( $this->pluginConfig['apiAllArticles'] ) {
            $result = $this->channableArticleResource->getAllArticlesList($offset, $limit, $filter, $sort);
        } else {
            $result = $this->channableArticleResource->getList($offset, $limit, $filter, $sort);
        }

        return $result['data'];
    }

    private function getArticleImagePaths($articleImages)
    {
        $images = array();

        for ( $i = 0; $i < sizeof($articleImages); $i++ ) {

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

    private function getBasePath()
    {
        $url = $this->Request()->getBaseUrl() . '/';
        $uri = $this->Request()->getScheme() . '://' . $this->Request()->getHttpHost();
        $url = $uri . $url;

        return $url;
    }

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

    private function getArticleCategories($articleId)
    {
        $categories = $this->channableArticleResource->getArticleCategories($articleId,$this->shop->getCategory()->getId());

        $em = $this->getModelManager();
        $categoryRepo = $em->getRepository('Shopware\Models\Category\Category');

        $categoryList = array();

        foreach ( $categories as $category ) {

            $path = $categoryRepo->getPathById($category['id']);

            $categoryList[] = array_values($path);
        }

        return $categoryList;
    }

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

    private function loadPaymentMethods()
    {
        $builder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $builder->select([
            'id',
            'name'
        ]);
        $builder->from('s_core_paymentmeans', 'payments');
        $builder->where('payments.active = 1');

        $statement = $builder->execute();
        $this->paymentMethods = $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

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
        $detail = $this->channableArticleResource->getArticleProperties($detailId);

        $propertyValues = $detail['article']['propertyValues'];

        $properties = array();
        for ( $i = 0; $i < sizeof($propertyValues); $i++) {

            $propertyOption = $this->translationComponent->read($this->shopId,'propertyoption',$propertyValues[$i]['optionId']);

            if ( !empty($propertyOption['optionName']) ) {
                $propertyValues[$i]['option']['name'] = $propertyOption['optionName'];
            }

            $propertyValue = $this->translationComponent->read($this->shopId,'propertyvalue',$propertyValues[$i]['id']);

            if ( !empty($propertyValue['optionValue']) ) {
                $propertyValues[$i]['value'] = $propertyValue['optionValue'];
            }

            $properties[$this->filterFieldNames($propertyValues[$i]['option']['name'])][] = $propertyValues[$i]['value'];
        }

        return $properties;
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
        $builder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
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
     * Get detail configuration options
     *
     * @param $detailId
     * @return array
     */
    private function getDetailConfiguratiorOptions($detailId)
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

                if ( !empty($configuratorOption['name']) ) {
                    $detail['configuratorOptions'][$i]['name'] = $configuratorOption['name'];
                }

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

            for ($i = 0; $i < sizeof($grp); $i++) {

                $groups[$this->filterFieldNames($grp[$i]['key'])] = $grp[$i]['name'];

            }

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

        return $field;
    }

}