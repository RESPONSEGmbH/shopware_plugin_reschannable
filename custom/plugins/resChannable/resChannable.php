<?php
/**
 * This Software is the property of RESPONSE GmbH and is protected
 * by copyright law - it is NOT Freeware.
 * Any unauthorized use of this software without a valid license
 * is a violation of the license agreement and will be prosecuted by
 * civil and criminal law.
 * http://www.response-gmbh.de
 *
 * @copyright (C) RESPONSE GmbH
 * @author RESPONSE GmbH <response@response-gmbh.de>
 * @link http://www.response-gmbh.de
 */

namespace resChannable;

use Doctrine\Common\EventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Models\User\User;

class resChannable extends Plugin
{

    /**
    * {@inheritdoc}
    */
    public static function getSubscribedEvents()
    {
        return array(
            'Enlight_Controller_Action_PreDispatch' => 'addTemplateDir',
            'Enlight_Controller_Dispatcher_ControllerPath_Api_resChannableApi' => 'onGetReschannableApiController',
            'Enlight_Controller_Front_StartDispatch' => 'onEnlightControllerFrontStartDispatch',
            'Enlight_Controller_Action_PostDispatchSecure_Backend_Index' => 'onPostDispatchSecureBackendIndex',
            'product_stock_was_changed' => 'onProductStockWasChanged'
        );
    }

    /**
     * onProductStockWasChanged
     *
     * @param EventArgs $eventArgs
     */
    public function onProductStockWasChanged(EventArgs $eventArgs)
    {
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName($this->getName());

        if ( !$config['apiAllowRealTimeUpdates'] )
            return;

        $webhook = $this->container->get('reschannable_service_plugin.webhook');
        $webhook->updateChannableForAllShops($eventArgs->get('number'));
    }

    /**
     * onPostDispatchSecureBackendIndex
     *
     * @param \Enlight_Event_EventArgs $args
     */
    public function onPostDispatchSecureBackendIndex(\Enlight_Event_EventArgs $args)
    {
        $this->container->get('Template')->addTemplateDir(
            $this->getPath() . '/Resources/views/'
        );
        $args->getSubject()->View()->extendsTemplate('backend/reschannable/index/header.tpl');
    }

    /**
     * Get Channable API Controller
     *
     * @return string
     */
    public function onGetReschannableApiController()
    {
        return $this->getPath() . '/Controllers/Api/resChannableApi.php';
    }

    /**
     * Register namespaces
     */
    public function onEnlightControllerFrontStartDispatch()
    {
        $this->container->get('loader')->registerNamespace('Shopware\Components', $this->getPath() . '/Components/');
    }

    /**
     * Add template directory
     *
     * @param \Enlight_Controller_ActionEventArgs $args
     */
    public function addTemplateDir(\Enlight_Controller_ActionEventArgs $args)
    {
        $args->getSubject()->View()->addTemplateDir($this->getPath() . '/Resources/views');
    }

    /**
     * Install handler
     *
     * @param InstallContext $context
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function install(InstallContext $context)
    {
        $this->createApiUser();

        $this->createSchema();
    }

    /**
     * Creates database schema
     */
    private function createSchema()
    {
        $tool = new SchemaTool($this->container->get('models'));
        $classes = $this->getModelMetaData();

        try {

            $tool->createSchema($classes);

        } catch ( \Exception $exception ) {
        }
    }

    /**
     * Get model meta data e.g. for article assignement
     *
     * @return array
     */
    private function getModelMetaData()
    {
        return array($this->container->get('models')->getClassMetadata(Models\resChannableArticle\resChannableArticle::class));
    }

    /**
     * Creates the api user
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function createApiUser()
    {
        $apiKey = $this->getGeneratedApiKey(40);

        $mail = Shopware()->Config()->get('mail');

        $password = $this->getGeneratedPassword(12);

        /** @var User $user */
        $user = Shopware()->Models()->getRepository('Shopware\Models\User\User');
        $user = $user->findOneBy(array('username' => 'ChannableApiUser'));

        if (!$user) {

            $user = new User();
            $user->setUsername('ChannableApiUser');
            $user->setName('Channable API User');
            $user->setActive(true);
            $user->setRoleId(1);
            $user->setLocaleId(1);
            $user->setEncoder('bcrypt');
            $user->setApiKey($apiKey);
            $user->setEmail($mail);
            $user->setPassword($password);
            $user->setDisabledCache(true);

            Shopware()->Models()->persist($user);
            Shopware()->Models()->flush();
        }
    }

    /**
     * Generates random api key for user creation
     *
     * @param int $length
     * @return string
     */
    private function getGeneratedApiKey($length)
    {
        $chars = '0123456789';
        $chars .= 'abcdefghijklmnopqrstuvwxyz';
        $chars .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $key = '';
        $length = strlen($chars);
        for ($i=0; $i<$length; $i++) {
            $key .= $chars[rand(0,$length-1)];
        }
        return $key;
    }

    /**
     * Generates random password for user creation
     *
     * @param int $length
     * @return string
     */
    private function getGeneratedPassword($length)
    {
        $chars = '0123456789';
        $chars .= 'abcdefghijklmnopqrstuvwxyz';
        $chars .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $chars .= '?!.$%-';

        $key = '';
        $length = strlen($chars);
        for ($i=0; $i<$length; $i++) {
            $key .= $chars[rand(0,$length-1)];
        }
        return $key;
    }

}
