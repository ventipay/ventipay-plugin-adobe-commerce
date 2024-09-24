<?php

namespace VentiPay\Gateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use VentiPay\Gateway\Helper\CurrencyHelper;

class VentiPayAvailable implements ObserverInterface
{
  protected $config;
  protected $storeManager;

    public function __construct (
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    )
    {
        $this->config = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        if ($observer->getEvent()->getMethodInstance()->getCode() === 'ventipay_gateway') {

          $isActive = $this->config->getValue(
            'payment/ventipay_gateway/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
          );

          $currency = strtolower($this->storeManager->getStore()->getCurrentCurrencyCode());

          $isAvailable = false;

          if ($isActive and CurrencyHelper::isSupported($currency)) {
            $isAvailable = true;
          }

          $checkResult = $observer->getEvent()->getResult();
          $checkResult->setData('is_available', $isAvailable);
        }
    }
}
