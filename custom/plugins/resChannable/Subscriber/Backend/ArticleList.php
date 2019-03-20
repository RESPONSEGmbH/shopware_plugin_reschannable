<?php
namespace resChannable\Subscriber\Backend;

use Enlight\Event\SubscriberInterface;

class ArticleList implements SubscriberInterface
{

    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PreDispatch_Backend_ArticleList' => 'onPreDispatchBackendArticleList',
            'Enlight_Controller_Action_PostDispatch_Backend_ArticleList' => 'onPostDispatchBackendArticleList'
        ];
    }

    public function __construct()
    {

    }

    /**
     * Starts the webhook after checking stock changes
     *
     * @param \Enlight_Event_EventArgs $args
     */
    public function onPostDispatchBackendArticleList(\Enlight_Event_EventArgs $args)
    {
        $request = $args->getRequest();

        if ( $request->getActionName() == 'saveSingleEntity' ) {

            if ( $this->resChannablePostUpdates ) {

                $webhook = Shopware()->Container()->get('reschannable_service_plugin.webhook');
                $webhook->updateChannableForAllShops($this->resChannablePostData['number']);

            }
        }
    }

    /**
     * onPreDispatchBackendArticleList
     *
     * @param \Enlight_Event_EventArgs $args
     */
    public function onPreDispatchBackendArticleList(\Enlight_Event_EventArgs $args)
    {
        $request = $args->getRequest();

        if ( $request->getActionName() == 'saveSingleEntity' ) {

            $this->resChannablePostUpdates = false;

            # new data
            $data = $request->getParams();

            # new stock
            $newStock = $data['Detail_inStock'];

            # new price
            $newPrice = $data['Price_price'];

            # old data
            $detailRepository = $this->getDetailRepository();
            $detail = $detailRepository
                ->find((int) $data['Detail_id']);

            $oldStock = $detail->getInStock();

            $article = $detail->getArticle();

            $prices = $this->getPrices($data['Detail_id'],$article->getTax()->getTax());

            $oldPrice = $prices[0]['price'];

            # Start hook if new stock or price
            if ( $newStock <> $oldStock || $newPrice <> $oldPrice ) {

                $this->resChannablePostUpdates = true;

                $number = $detail->getNumber();

                $this->resChannablePostData = array(
                    'number' => $number
                );

                # Continue post in onPostDispatchBackendArticleList after saving article
            }
        }
    }

    /**
     * Internal helper function to get access to the article repository.
     *
     * @return Shopware\Models\Article\Repository
     */
    protected function getDetailRepository()
    {
        return Shopware()->Models()->getRepository('Shopware\Models\Article\Detail');
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
                $price['price'] = round($price['price'] / 100 * (100 + $tax),2);
                $price['pseudoPrice'] = $price['pseudoPrice'] / 100 * (100 + $tax);
            }
            $prices[$key] = $price;
        }

        return $prices;
    }

}