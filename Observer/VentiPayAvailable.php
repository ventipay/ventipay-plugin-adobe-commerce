<?php

namespace VentiPay\Gateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class VentiPayAvailable implements ObserverInterface
{
  protected $config;

    public function __construct (
        ScopeConfigInterface $scopeConfig,
    )
    {
        $this->config = $scopeConfig;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        if ($observer->getEvent()->getMethodInstance()->getCode() === "ventipay_gateway") {

          $isActive = $this->config->getValue(
            'payment/ventipay_gateway/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
          );

          $isAvailable = false;

          if ($isActive) {
            $isAvailable = true;
          }

          $checkResult = $observer->getEvent()->getResult();
          $checkResult->setData('is_available', $isAvailable);
        }
    }
}
