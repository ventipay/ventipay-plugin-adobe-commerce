<?php

namespace VentiPay\Gateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use VentiPay\Gateway\Helper\CurrencyHelper;

class RefundObserver implements ObserverInterface
{
  protected $config;
  protected $curl;

    public function __construct (
        ScopeConfigInterface $scopeConfig,
        Curl $curl
    )
    {
        $this->config = $scopeConfig;
        $this->curl = $curl;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        $creditMemo = $observer->getEvent()->getCreditmemo();
        $order = $creditMemo->getOrder();
        $payment = $order->getPayment();
        $paymentMethod = $payment->getMethod();

        if ($paymentMethod !== 'ventipay_gateway') {
          return;
        }

        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId) {
          throw new \Magento\Framework\Exception\LocalizedException(__('no_ventipay_checkout_id'));
        } elseif (strpos($checkoutId, 'chk_') !== 0) {
          throw new \Magento\Framework\Exception\LocalizedException(__('invalid_id'));
        }

        $currency = strtolower($order->getOrderCurrencyCode());
        $grandTotal = $creditMemo->getBaseGrandTotal();

        $amountFormatted = CurrencyHelper::transformNumber($grandTotal, $currency);

        if ($amountFormatted === false) {
          throw new \Magento\Framework\Exception\LocalizedException(__('Moneda no soportada.'));
        }

        $apiKey = $this->config->getValue(
          'payment/ventipay_gateway/merchant_gateway_key',
          \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        );

        $this->curl->setCredentials($apiKey, '');
        $this->curl->addHeader('Content-Type', 'application/json');
        
        $statusCode = null;

        $this->curl->post(
          'https://api.ventipay.com/v1/checkouts/' . $checkoutId . '/refund',
          json_encode([
            'destination' => 'payment_method',
            'amount' => $amountFormatted
          ])
        );

        $statusCode = $this->curl->getStatus();

        if ($statusCode === 200) {
          return;
        }

        $this->curl->post(
          'https://api.ventipay.com/v1/checkouts/' . $checkoutId . '/refund',
          json_encode([
            'destination' => 'customer_balance',
            'amount' => $amountFormatted
          ])
        );

        $statusCode = $this->curl->getStatus();

        if ($statusCode === 200) {
          return;
        }

        throw new \Magento\Framework\Exception\LocalizedException(__('No hemos podido realizar el reembolso'));
    }
}
