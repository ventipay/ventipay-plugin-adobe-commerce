<?php
namespace VentiPay\Gateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Success extends Action
{
    protected $resultJsonFactory;
    protected $orderRepository;
    protected $curl;
    protected $config;
    protected $request;
    protected $resultRedirectFactory;
    protected $invoiceManagement;
    protected $invoiceRepository;
    protected $orderSender;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        RedirectFactory $resultRedirectFactory,
        InvoiceManagementInterface $invoiceManagement,
        InvoiceRepositoryInterface $invoiceRepository,
        OrderSender $orderSender
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
        $this->orderSender = $orderSender;
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        $orderId = $this->getRequest()->getParam('orderId');

        if (!$orderId) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $order = null;

        try {
          $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if ($order->getStatus() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
          return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId || strpos($checkoutId, 'chk_') !== 0) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $apiKey = $this->config->getValue(
          'payment/ventipay_gateway/merchant_gateway_key',
          \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
          $order->getStoreId()
        );

        $this->curl->setCredentials($apiKey, '');
        $this->curl->get('https://api.ventipay.com/v1/checkouts/' . $checkoutId);

        $statusCode = $this->curl->getStatus();

        if ($statusCode === 404) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if ($statusCode !== 200) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart')
        }
        
        $body = $this->curl->getBody();
        $response = json_decode($body);

        if (!isset($response)) {
          return $this->resultRedirectFactory->create()->setPath('checkout/cart')
        }

        if ($response->status === 'paid') {
          $payment->capture(null);

          $order->setCanSendNewEmailFlag(true);
          $order->save();

          try {
            $this->orderSender->send($order);
          } catch (\Exception $e) {}
          $order->save();

          return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }

        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
