<?php

use Shopware\Models\User\User;

class Shopware_Controllers_Backend_ReschannableAutoConnect extends Enlight_Controller_Action
{

    public function indexAction()
    {
        die('');
    }

    public function getUrlAction()
    {
        /** @var User $user */
        $user = Shopware()->Models()->getRepository('Shopware\Models\User\User');
        $user = $user->findOneBy(array('username' => 'ChannableApiUser'));

        if ( !$user )
            throw new Shopware\Components\Api\Exception\NotFoundException('Channable API user not found.');

        $sApiKey = $user->getApiKey();

        $shopValue = $this->Request()->getParam('shopValue');

        preg_match_all('/[0-9]+/',$shopValue,$shops);

        if ( !$shops )
            throw new Shopware\Components\Api\Exception\NotFoundException('Shop not found.');

        $shopValue = $shops[0];
        $shopId = $shopValue[0];

        $shop = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop')->find($shopId);

        $basePath = $shop->getBasePath();
        $host = $shop->getHost();

        $scheme = 'http';
        $secure = $shop->getSecure();

        if ($secure)
            $scheme = 'https';

        $mainShop = $shop->getMain();
        if ($mainShop) {
            $scheme = 'http';

            $secure = $mainShop->getSecure();

            $basePath = $mainShop->getBasePath();
            $host = $mainShop->getHost();

            if ($secure)
                $scheme = 'https';
        }

        $path = $scheme . '://' . $host . $basePath;

        $url = Shopware()->Snippets()->getNamespace('api/resChannable')->get(
            'autoConnectUrl'
        ) . '?url='.$path.'&api_key='.$sApiKey.'&username=ChannableApiUser&shop='.$shopId;

        echo json_encode(array('url' => $url));
    }

}