<?php

namespace resChannable\Components\Api\Resource;

use Shopware\Components\Api\Resource\Resource;
use Shopware\Components\Model\QueryBuilder;
use Shopware\Models\Article\Image;

class ResChannableArticle extends Resource
{

    /**
     * @param $offset
     * @param $limit
     * @param $filter
     * @param $sort
     *
     * @return array
     */
    public function getList($offset, $limit, $filter, $sort)
    {
        $this->checkPrivilege('read');

        $builder = $this->getBaseQuery();
        $builder = $this->addQueryLimit($builder, $offset, $limit);

        if (!empty($filter)) {
            $builder->addFilter($filter);
        }
        if (!empty($sort)) {
            $builder->addOrderBy($sort);
        }

        $query = $builder->getQuery();

        $query->setHydrationMode($this->getResultMode());

        $paginator = $this->getManager()->createPaginator($query);
        $totalResult = $paginator->count();
        $articles = $paginator->getIterator()->getArrayCopy();

        return array('data' => $articles, 'total' => $totalResult);
    }

    /**
     * @param QueryBuilder $builder
     * @param int          $offset
     * @param int          $limit
     *
     * @return QueryBuilder
     */
    protected function addQueryLimit(QueryBuilder $builder, $offset, $limit = null)
    {
        $builder->setFirstResult($offset)
            ->setMaxResults($limit);

        return $builder;
    }

    /**
     * @return \Doctrine\ORM\QueryBuilder|QueryBuilder
     */
    protected function getBaseQuery()
    {
        $builder = $this->getManager()->createQueryBuilder();

        $builder->select(array(
            'detail',
            'article',
            'detailUnit',
            'tax',
            'detailAttribute',
            'supplier'
        ))
            ->from('Shopware\Models\Article\Detail', 'detail')
            ->join('detail.article', 'article')
            ->leftJoin('article.allCategories', 'categories', null, null, 'categories.id')
            ->leftJoin('detail.unit', 'detailUnit')
            ->leftJoin('article.tax', 'tax')
            ->leftJoin('detail.attribute', 'detailAttribute')
            ->leftJoin('article.supplier', 'supplier')
            ->addGroupBy('detail.id');

        return $builder;
    }

    /**
     * Helper function to prevent duplicate source code
     * to get the full query builder result for the current resource result mode
     * using the query paginator.
     *
     * @param QueryBuilder $builder
     *
     * @return array
     */
    private function getFullResult(QueryBuilder $builder)
    {
        $query = $builder->getQuery();
        $query->setHydrationMode($this->getResultMode());
        $paginator = $this->getManager()->createPaginator($query);

        return $paginator->getIterator()->getArrayCopy();
    }

    /**
     * Helper function to prevent duplicate source code
     * to get a single row of the query builder result for the current resource result mode
     * using the query paginator.
     *
     * @param QueryBuilder $builder
     *
     * @return array
     */
    private function getSingleResult(QueryBuilder $builder)
    {
        $query = $builder->getQuery();
        $query->setHydrationMode($this->getResultMode());
        $paginator = $this->getManager()->createPaginator($query);

        return $paginator->getIterator()->current();
    }

    /**
     * Helper function which selects all categories of the passed
     * article id.
     * This function returns only the directly assigned categories.
     * To prevent a big data, this function selects only the category name and id.
     *
     * @param $articleId
     * @param $mainCategoriesId
     *
     * @return array
     */
    public function getArticleCategories($articleId,$mainCategoriesId)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select(array('categories.id'))
            ->from('Shopware\Models\Category\Category', 'categories', 'categories.id')
            ->where(':articleId MEMBER OF categories.articles')
            ->andWhere('categories.path LIKE :path')
            ->setParameter('articleId', $articleId)
            ->setParameter('path', '%|' . $mainCategoriesId . '|%');

        return $this->getFullResult($builder);
    }

    public function getArticleSeoUrl($articleId,$shopId)
    {
        $connection = Shopware()->Container()->get('dbal_connection');

        $url = $connection->fetchColumn(

            "SELECT path 
            FROM `s_core_rewrite_urls`
            WHERE main = 1
            AND subshopID = :subId 
            AND org_path = :orgPath",

            array(
                'subId' => $shopId,
                'orgPath' => 'sViewport=detail&sArticle='.$articleId
            )
        );

        return $url;
    }

    /**
     * Helper function which selects all similar articles
     * of the passed article id.
     *
     * @param $articleId
     *
     * @return mixed
     */
    public function getArticleSimilar($articleId)
    {
        $builder = $this->getManager()->getConnection()->createQueryBuilder();

        $builder->select(['similarArticles.id', 'similarArticles.name','similarVariant.ordernumber as number'])
            ->from('s_articles_similar', 'similar')
            ->innerJoin('similar', 's_articles', 'product', 'product.id = similar.articleID')
            ->innerJoin('similar', 's_articles', 'similarArticles', 'similarArticles.id = similar.relatedArticle')
            ->innerJoin('similarArticles', 's_articles_details', 'similarVariant', 'similarVariant.id = similarArticles.main_detail_id')
            ->where('product.id = :id')
            ->setParameter(':id', $articleId)
            ->setMaxResults(10);

        $statement = $builder->execute();

        return $statement->fetchAll();
    }

    /**
     * Helper function which selects all accessory articles
     * of the passed article id.
     *
     * @param $articleId
     *
     * @return mixed
     */
    public function getArticleRelated($articleId)
    {
        $builder = $this->getManager()->getConnection()->createQueryBuilder();

        $builder->select(['relatedArticles.id', 'relatedArticles.name', 'relatedVariant.ordernumber as number']);

        $builder->from('s_articles_relationships', 'relation')
            ->innerJoin('relation', 's_articles', 'product', 'product.id = relation.articleID')
            ->innerJoin('relation', 's_articles', 'relatedArticles', 'relatedArticles.id = relation.relatedArticle')
            ->innerJoin('relatedArticles', 's_articles_details', 'relatedVariant', 'relatedVariant.id = relatedArticles.main_detail_id')
            ->where('product.id = :id')
            ->setParameter(':id', $articleId)
            ->setMaxResults(10);;

        $statement = $builder->execute();

        return $statement->fetchAll();
    }

    /**
     * Get price lists
     *
     * @param int $articleDetailId
     * @param int $tax
     * @param int $customerGroup
     * @param bool $calcBrutto
     * @param bool $loadFallback
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getPrices($articleDetailId, $tax, $customerGroup, $calcBrutto, $loadFallback = false)
    {
        $builder = $this->getManager()->createQueryBuilder();

        $builder->select(array('prices', 'customerGroup'))
            ->from('Shopware\Models\Article\Price', 'prices')
            ->join('prices.customerGroup', 'customerGroup')
            ->where('prices.articleDetailsId = ?1')
            ->andWhere('customerGroup.id = ?2')
            ->setParameter(1, $articleDetailId)
            ->setParameter(2, $customerGroup)
            ->addOrderBy('prices.from', 'ASC');

        $prices = $this->getFullResult($builder);

        # No own prices found?
        if ( !$prices && $loadFallback ) {

            # Load prices from fallback customer group EK
            $builder = $this->getManager()->createQueryBuilder();

            $builder->select(array('prices', 'customerGroup'))
                ->from('Shopware\Models\Article\Price', 'prices')
                ->join('prices.customerGroup', 'customerGroup')
                ->where('prices.articleDetailsId = ?1')
                ->andWhere("customerGroup.key = 'EK'")
                ->setParameter(1, $articleDetailId)
                ->addOrderBy('prices.from', 'ASC');

            $prices = $this->getFullResult($builder);
        }

        $priceList = array();
        foreach ( $prices as $price ) {

            $pr = array(
                'priceNetto' => $price['price'],
                'priceBrutto' => ( $calcBrutto ? round($price['price'] * (($tax + 100) / 100),2) : $price['price']),
                'pseudoPriceNetto' => $price['pseudoPrice'],
                'pseudoPriceBrutto' => round($price['pseudoPrice'] * (($tax + 100) / 100),2)
            );

            $priceList[$this->filterFieldNames($price['customerGroupKey'])]['from_'.$price['from'].'_to_'.$price['to']] = $pr;
        }

        return $priceList;
    }

    public function getDetailImages($detailId)
    {
        $builder = $this->getManager()->createQueryBuilder();

        $builder->select(['images', 'imageParent', 'media'])
            ->from(Image::class, 'images')
            ->innerJoin('images.articleDetail', 'articleDetail')
            ->innerJoin('images.parent', 'imageParent')
            ->leftJoin('imageParent.media', 'media')
            ->where('articleDetail.id = :detailId')
            ->setParameter('detailId', $detailId)
            ->orderBy('imageParent.main', 'ASC')
            ->addOrderBy('imageParent.position', 'ASC');

        return $this->getFullResult($builder);
    }

    public function getArticleImages($articleId)
    {
        $builder = $this->getManager()->createQueryBuilder();

        $builder->select(['images', 'media'])
            ->from(Image::class, 'images')
            ->leftJoin('images.children', 'children')
            ->leftJoin('images.media', 'media')
            ->where('images.articleId = :articleId')
            ->andWhere('images.parentId IS NULL')
            ->andWhere('images.articleDetailId IS NULL')
            ->andWhere('children.id IS NULL')
            ->setParameter('articleId', $articleId)
            ->orderBy('images.main', 'ASC')
            ->addOrderBy('images.position', 'ASC');

        return $this->getFullResult($builder);
    }

    /**
     * Get article properties
     *
     * @param int $detailId
     * @param array $propertyIds
     * @param string $shopVersion
     * @return array
     */
    public function getArticleProperties($detailId, $propertyIds, $shopVersion)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select(array(
            'detail',
            'article',
            'propertyValues',
            'propertyValuesAttributes',
            'propertyOption',
            'propertyGroup',
        ))
            ->from('Shopware\Models\Article\Detail', 'detail')
            ->join('detail.article', 'article')
            ->join('article.propertyValues', 'propertyValues')
            ->leftJoin('propertyValues.attribute', 'propertyValuesAttributes')
            ->join('propertyValues.option', 'propertyOption')
            ->join('article.propertyGroup', 'propertyGroup')
            ->where('detail.id = :detailId')
            ->setParameter('detailId', $detailId);

        if (version_compare($shopVersion, '5.5.0', '>=')) {
            $builder
                ->andWhere('propertyOption.id IN (:propertyIds)')
                ->setParameter('propertyIds', $propertyIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
        } else {
            $builder
                ->andWhere('propertyOption.name IN (:propertyIds)')
                ->setParameter('propertyIds', $propertyIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
        }

        return $this->getSingleResult($builder);
    }

    /**
     * Get detail configurator options
     *
     * @param $detailId
     * @return array
     */
    public function getDetailConfiguratiorOptions($detailId)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select(array(
            'detail',
            'configuratorOptions',
            'configuratorGroups'
        ))
            ->from('Shopware\Models\Article\Detail', 'detail')
            ->join('detail.configuratorOptions', 'configuratorOptions')
            ->join('configuratorOptions.group', 'configuratorGroups')
            ->where('detail.id = :detailId')
            ->setParameter('detailId', $detailId);

        return $this->getSingleResult($builder);
    }

    /**
     * Get excluded customer groups
     *
     * @param $detailId
     * @return array|bool
     */
    public function getExcludedCustomerGroups($detailId)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select(array(
            'detail',
            'article',
            'excludedCustomerGroups'
        ))
            ->from('Shopware\Models\Article\Detail', 'detail')
            ->join('detail.article', 'article')
            ->join('article.customerGroups', 'excludedCustomerGroups')
            ->where('detail.id = :detailId')
            ->setParameter('detailId', $detailId);

        $groups = $this->getSingleResult($builder);

        return ( $groups['article'] ? $groups['article']['customerGroups'] : false );
    }

    /**
     * Returns the configured article seo categories.
     * This categories are used for the seo url generation.
     *
     * @param $articleId
     * @param $shopId
     *
     * @return array
     */
    public function getArticleSeoCategory($articleId,$shopId)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select(array('seoCategories.categoryId'))
            ->from('Shopware\Models\Article\SeoCategory', 'seoCategories')
            ->innerJoin('seoCategories.category', 'category')
            ->where('seoCategories.articleId = :articleId')
            ->andWhere('seoCategories.shop = :shop')
            ->setParameter('articleId', $articleId)
            ->setParameter('shop', $shopId);

        return $this->getSingleResult($builder);
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
