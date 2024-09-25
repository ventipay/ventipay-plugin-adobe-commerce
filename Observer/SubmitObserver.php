<?php

namespace VentiPay\Gateway\Observer;

use Magento\Framework\Event\ObserverInterface;

class SubmitObserver implements ObserverInterface
{
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $paymentMethod = $order->getPayment()->getMethod();

        if ($paymentMethod !== 'ventipay_gateway') {
          return;
        }

        $order->setCanSendNewEmailFlag(false);
        $order->save();
    }
}
