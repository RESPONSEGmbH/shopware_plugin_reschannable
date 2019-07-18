<?php
namespace resChannable\Subscriber\Backend;

use Enlight\Event\SubscriberInterface;

class Article implements SubscriberInterface
{

    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PreDispatch_Backend_Article' => 'onPreDispatchBackendArticle',
            'Enlight_Controller_Action_PostDispatch_Backend_Article' => 'onPostDispatchBackendArticle',
        ];
    }

    public function __construct()
    {
    }

    public function onPostDispatchBackendArticle(\Enlight_Event_EventArgs $args)
    {
        $request = $args->getRequest();

        $action = $request->getActionName();

        if ( $action == 'save' || $action == 'saveDetail' ) {

            if ( $this->resChannablePostUpdates ) {

                $webhook = Shopware()->Container()->get('reschannable_service_plugin.webhook');
                $webhook->updateChannableForAllShops($this->resChannablePostData['number']);
            }
        }
    }

    /**
     * onPreDispatchBackendArticle
     *
     * @param \Enlight_Event_EventArgs $args
     */
    public function onPreDispatchBackendArticle(\Enlight_Event_EventArgs $args)
    {
        $request = $args->getRequest();

        if ( $request->getActionName() == 'save' ) {

            if ($request->has('id')) {

                $this->resChannablePostUpdates = false;

                # new data
                $data = $request->getParams();

                # new stock
                $newStock = $data['mainDetail'][0]['inStock'];

                # new price
                $newPrice = round($data['mainPrices'][0]['price'],2);

                # new lastStock
                $newLastStock = $data['lastStock'];

                # old data
                $detail = $this->getDetailRepository()
                    ->find((int) $data['mainDetailId']);
                /** @var \Shopware\Models\Article\Article $article */
                $article = $detail->getArticle();

                $oldStock = $detail->getInStock();
                $oldLastStock = $article->getLastStock();

                $prices = $this->getPrices($data['mainDetailId'],$article->getTax()->getTax());

                $oldPrice = $prices[0]['price'];

                # Start hook if new stock or price
                if ( $newStock <> $oldStock || $newPrice <> $oldPrice || $newLastStock != $oldLastStock ) {

                    $number = $detail->getNumber();

                    $this->resChannablePostUpdates = true;

                    $this->resChannablePostData = array(
                        'number' => $number
                    );

                    # Continue post in onPostDispatchBackendArticle after saving article
                }
            }
        }

        if ( $request->getActionName() == 'saveDetail' ) {

            if ($request->has('id')) {

                $this->resChannablePostUpdates = false;

                # new data
                $data = $request->getParams();

                # new stock
                $newStock = $data['inStock'];

                # new price
                $newPrice = round($data['price'],2);

                # new lastStock
                $newLastStock = $data['lastStock'];

                # old data
                $detail = $this->getDetailRepository()
                    ->find((int) $data['id']);
                /** @var \Shopware\Models\Article\Article $article */
                $article = $detail->getArticle();

                $oldStock = $detail->getInStock();
                $oldLastStock = $article->getLastStock();

                $prices = $this->getPrices($data['id'],$article->getTax()->getTax());

                $oldPrice = $prices[0]['price'];

                # Start hook if new stock or price
                if ( $newStock <> $oldStock || $newPrice <> $oldPrice || $newLastStock != $oldLastStock ) {

                    $number = $detail->getNumber();

                    $this->resChannablePostUpdates = true;

                    $this->resChannablePostData = array(
                        'number' => $number
                    );

                    # Continue post in onPostDispatchBackendArticle after saving article
                }
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