<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Author Robert Hillebrand - hillebrand@i-ways.de - i-ways sales solutions GmbH
 * Copyright i-ways sales solutions GmbH © 2015. All Rights Reserved.
 * License http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Iways\PayPalPlus\Helper;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\View\LayoutFactory;

/**
 * Class Data
 * @package Iways\PayPalPlus\Helper
 */
class Data extends \Magento\Payment\Helper\Data
{
    /**
     * @var \Magento\Framework\Session\Generic
     */
    protected $generic;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Iways\PayPalPlus\Model\ApiFactory
     */
    protected $payPalPlusApiFactory;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Config\Model\ResourceModel\Config
     */
    protected $configResource;

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * @var \Magento\Framework\App\ProductMetadata
     */
    protected $productMetaData;

    /**
     * Data constructor.
     * @param \Magento\Framework\App\Helper\Context $context
     * @param LayoutFactory $layoutFactory
     * @param \Magento\Payment\Model\Method\Factory $paymentMethodFactory
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param \Magento\Framework\App\Config\Initial $initialConfig
     * @param \Magento\Framework\Session\Generic $generic
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Iways\PayPalPlus\Model\ApiFactory $payPalPlusApiFactory
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Magento\Config\Model\ResourceModel\Config $configResource
     * @param \Magento\Framework\App\ProductMetadata $productMetaData
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        LayoutFactory $layoutFactory,
        \Magento\Payment\Model\Method\Factory $paymentMethodFactory,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\App\Config\Initial $initialConfig,
        \Magento\Framework\Session\Generic $generic,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Iways\PayPalPlus\Model\ApiFactory $payPalPlusApiFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Config\Model\ResourceModel\Config $configResource,
        \Magento\Framework\App\ProductMetadata $productMetaData,
        TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context, $layoutFactory, $paymentMethodFactory, $appEmulation, $paymentConfig, $initialConfig);
        $this->generic = $generic;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->payPalPlusApiFactory = $payPalPlusApiFactory;
        $this->messageManager = $messageManager;
        $this->configResource = $configResource;
        $this->cacheTypeList = $cacheTypeList;
        $this->productMetaData = $productMetaData;
    }

    /**
     * Show Exception if debug mode.
     *
     * @param \Exception $e
     */
    public function handleException(\Exception $e)
    {
        if ($this->scopeConfig->getValue('iways_paypalplus/dev/debug', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->messageManager->addWarning($e->getData());
        }
    }

    /**
     * Build webhook listener url
     *
     * @return string
     */
    public function getWebhooksUrl()
    {
        return str_replace(
            'http://',
            'https://',
            $this->_getUrl(
                'paypalplus/webhooks/index/',
                [
                    '_forced_secure' => true,
                    '_nosid' => true,
                ]
            )
        );
    }

    /**
     * Get url wrapper for security urls and form key
     *
     * @param $url
     * @param array $params
     * @param bool|true $formKey
     * @return string
     */
    public function getUrl($url, $params = array(), $formKey = true)
    {
        $isSecure = $this->request->isSecure();
        if ($isSecure) {
            $params['_forced_secure'] = true;
        } else {
            $params['_secure'] = true;
        }
        if ($formKey) {
            $params['form_key'] = $this->generic->getFormKey();
        }
        return $this->_getUrl($url, $params);
    }

    /**
     * Get deafult country id for different supported checkouts
     *
     * @return mixed
     */
    public function getDefaultCountryId()
    {
        return $this->scopeConfig->getValue('payment/account/merchant_country', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Save Store Config
     * @param $key
     * @param $value
     * @param null $storeId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function saveStoreConfig($key, $value, $storeId = null)
    {
        if (!$storeId) {
            $storeId = $this->storeManager->getStore()->getId();
        }
        $this->configResource->saveConfig(
            $key,
            $value,
            'stores',
            $storeId
        );
        $this->cacheTypeList->cleanType('config');
        return true;
    }

    /**
     * Reset web profile id
     *
     * @return boolean
     */
    public function resetWebProfileId()
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->configResource->saveConfig(
                'iways_paypalplus/dev/web_profile_id',
                false,
                'stores',
                $store->getId()
            );
        }
        $this->cacheTypeList->cleanType('config');
        return true;
    }

    /**
     * Request payment experience from PayPal for current quote.
     *
     * @return string
     */
    public function getPaymentExperience()
    {
        if ($this->scopeConfig->getValue('payment/iways_paypalplus_payment/active', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            return $this->payPalPlusApiFactory->create()->getPaymentExperience();
        }
        return false;
    }

    /**
     * Convert due date
     *
     * @param $date
     * @return string
     */
    public function convertDueDate($date)
    {
        $dateArray = explode('-', $date);
        $dateArray = array_reverse($dateArray);
        return implode('.', $dateArray);
    }
}