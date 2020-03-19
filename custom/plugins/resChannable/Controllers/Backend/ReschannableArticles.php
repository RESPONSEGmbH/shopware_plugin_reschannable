<?php

use resChannable\Models\resChannableArticle\resChannableArticle;
use Doctrine\DBAL\Query\QueryBuilder;

class Shopware_Controllers_Backend_ReschannableArticles extends Shopware_Controllers_Backend_Application
{

    protected $model = resChannableArticle::class;
    protected $alias = 'resChannableArticle';

    /**
     * returns a JSON string to the view the articles for the article mapping
     */
    public function getArticlesAction()
    {
        $offset = $this->Request()->getParam('start', 0);
        $limit = $this->Request()->getParam('limit', 20);
        $search = $this->Request()->getParam('search', '');
        /** @var QueryBuilder $builder */
        $builder = $this->get('dbal_connection')->createQueryBuilder();
        $builder->select(array(
            'SQL_CALC_FOUND_ROWS details.id as articleId',
            'articles.name',
            'details.ordernumber as number',
            'suppliers.name as supplierName',
        ));
        $builder->from('s_articles', 'articles')
            ->join('articles', 's_articles_supplier', 'suppliers', 'articles.supplierID = suppliers.id')
            ->join('articles', 's_articles_details', 'details', 'articles.id = details.articleID')
            ->leftJoin('details', 'reschannable_articles', 'reschannable_articles', 'details.id = reschannable_articles.detailID')
            ->andWhere('reschannable_articles.detailID IS NULL');

        if (!empty($search)) {
            $builder->andWhere('(articles.name LIKE :search OR details.ordernumber LIKE :search OR suppliers.name LIKE :search)');
            $builder->setParameter('search', '%' . $search . '%');
        }

        $builder->setFirstResult($offset);
        $builder->setMaxResults($limit);
        $result = $builder->execute()->fetchAll();

        $count = $this->get('dbal_connection')->fetchColumn('SELECT FOUND_ROWS()');

        $this->View()->assign(array(
            'success' => true,
            'data' => $result,
            'total' => (int) $count,
        ));
    }

    /**
    * Controller action which is used to get a paginated
    * list of all assigned channable articles.
    */
    public function getAssignedArticlesAction()
    {
        $offset = $this->Request()->getParam('start', 0);
        $limit = $this->Request()->getParam('limit', 20);
        $search = $this->Request()->getParam('search', '');

        $builder = Shopware()->Models()->createQueryBuilder();
        $builder->select(array(
            'details.id as articleId',
            'articles.name',
            'details.number',
            'suppliers.name as supplierName',
        ));

        $builder->from('resChannable\Models\resChannableArticle\resChannableArticle', 'resChannableArticle')
            ->join('resChannableArticle.detail', 'details')
            ->join('details.article', 'articles')
            ->join('articles.supplier', 'suppliers');

        if (!empty($search)) {
            $builder->andWhere('(articles.name LIKE :search OR suppliers.name LIKE :search OR details.number LIKE :search)');
            $builder->setParameter('search', '%' . $search . '%');
        }

        $builder->setFirstResult($offset)
            ->setMaxResults($limit);

        $query = $builder->getQuery();

        $query->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = $this->getModelManager()->createPaginator($query);

        $data = $paginator->getIterator()->getArrayCopy();
        $count = $paginator->count();

        $this->View()->assign(array(
            'success' => true,
            'data' => $data,
            'total' => $count,
        ));
    }

    /**
     * Controller action which can be accessed over an request.
     * This function adds the passed article.
     */
    public function addChannableArticlesAction()
    {
        $this->View()->assign(
            $this->addChannableArticles(
                json_decode($this->Request()->getParam('ids'))
            )
        );
    }

    /**
     * Helper function to add multiple articles to channable.
     *
     * @param array $articleIds
     *
     * @return array
     */
    protected function addChannableArticles($articleIds)
    {
        if (empty($articleIds)) {
            return array('success' => false, 'error' => 'No articles selected');
        }

        $counter = 0;
        foreach ($articleIds as $articleId) {
            if (empty($articleId)) {
                continue;
            }

            $article = new resChannableArticle();
            $article->setDetailID($articleId);

            Shopware()->Models()->persist($article);
            Shopware()->Models()->flush();

            ++$counter;
        }

        return array('success' => true, 'counter' => $counter);
    }

    /**
     * Controller action which can be accessed over an request.
     * This function adds the passed article ids.
     */
    public function removeChannableArticlesAction()
    {
        $this->View()->assign(
            $this->removeChannableArticles(
                json_decode($this->Request()->getParam('ids'))
            )
        );
    }

    /**
     * Internal function which is used to remove the passed article ids
     *
     * @param array $articleIds
     *
     * @return array
     */
    protected function removeChannableArticles($articleIds)
    {
        if (empty($articleIds)) {
            return array('success' => false, 'error' => 'No articles selected');
        }

        $entityManager = Shopware()->Container()->get('models');

        $counter = 0;
        foreach ($articleIds as $articleId) {
            if (empty($articleId)) {
                continue;
            }

            $repository = $entityManager->getRepository(resChannableArticle::class);

            $article = $repository->findOneBy(array(
                'detailID' => $articleId
            ));

            if ($article) {
                $entityManager->remove($article);
                $entityManager->flush($article);
            }

            ++$counter;
        }

        return array('success' => true, 'counter' => $counter);
    }

    public function getPropertiesAction()
    {
        /** @var QueryBuilder $builder */
        $builder = $this->get('dbal_connection')->createQueryBuilder();
        $builder->select(array(
        'propertyGroup.id',
        'propertyGroup.name'
        ));
        $builder->from('s_filter_options', 'propertyGroup');
        $data = $builder->execute()->fetchAll();

        $this->view->assign([
            'data' => $data,
            'total' => count($data),
        ]);
    }

}