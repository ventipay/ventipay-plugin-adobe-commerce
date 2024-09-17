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

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        RedirectFactory $resultRedirectFactory,
        InvoiceManagementInterface $invoiceManagement,
        InvoiceRepositoryInterface $invoiceRepository
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
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
          return $resultJson->setData([
            'message' => 'order_not_found'
          ])->setHttpResponseCode(404);
        }

        if ($order->getStatus() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
          return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId) {
          return $resultJson->setData([
            'message' => 'checkout_id_not_found'
          ]);
        } elseif (strpos($checkoutId, 'chk_') !== 0) {
          return $resultJson->setData([
            'message' => 'invalid_id'
          ]);
        }

        $apiKey = $this->config->getValue(
          'payment/ventipay_gateway/merchant_gateway_key',
          \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
          $order->getStoreId()
        );

        $this->curl->setCredentials($apiKey, '');
        $this->curl->get('https://api.ventipay.com/v1/checkouts/' . $checkoutId);
        
        $body = $this->curl->getBody();
        $response = json_decode($body);

        if (!isset($response)) {
          return $resultJson->setData([
            'message' => 'no_response'
          ])->setHttpResponseCode(400);
        } elseif ($response->refunded) {
          return $resultJson->setData([
            'message' => 'payment_refunded'
          ])->setHttpResponseCode(304);
        } elseif ($response->status !== 'paid') {
          return $resultJson->setData([
            'message' => 'no_status_paid',
            'status' => $response->status
          ])->setHttpResponseCode(400);
        }

        $payment->capture(null);
        $order->save();

        return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
    }
}
