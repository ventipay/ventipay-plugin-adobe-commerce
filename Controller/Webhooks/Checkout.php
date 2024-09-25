<?php
namespace VentiPay\Gateway\Controller\Webhooks;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Checkout extends Action implements CsrfAwareActionInterface
{
    protected $resultJsonFactory;
    protected $orderRepository;
    protected $curl;
    protected $config;
    protected $request;
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
        InvoiceManagementInterface $invoiceManagement,
        InvoiceRepositoryInterface $invoiceRepository,
        OrderSender $orderSender
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
        $this->orderSender = $orderSender;
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if ($this->request->getMethod() !== 'POST') {
          return $resultJson->setData([
            'message' => 'route_not_found'
          ])->setHttpResponseCode(200);
        };

        $orderId = $this->getRequest()->getParam('orderId');

        if (!$orderId) {
          return $resultJson->setData([
            'message' => 'orderid_is_required'
          ])->setHttpResponseCode(200);
        }

        $order = null;

        try {
          $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
          return $resultJson->setData([
            'message' => 'order_not_found'
          ])->setHttpResponseCode(200);
        }

        if ($order->getStatus() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
          return $resultJson->setData([
            'message' => 'order_not_pending_payment',
            'status' => $order->getStatus()
          ])->setHttpResponseCode(200);
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId) {
          return $resultJson->setData([
            'message' => 'checkout_id_not_found'
          ])->setHttpResponseCode(200);
        } elseif (strpos($checkoutId, 'chk_') !== 0) {
          return $resultJson->setData([
            'message' => 'checkout_id_invalid'
          ])->setHttpResponseCode(200);
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
          return $resultJson->setData([
            'message' => 'checkout_not_found'
          ])->setHttpResponseCode(200);
        }

        if ($statusCode !== 200) {
          return $resultJson->setData([
            'message' => 'failed'
          ])->setHttpResponseCode($statusCode);
        }
        
        $body = $this->curl->getBody();
        $response = json_decode($body);

        if (!isset($response)) {
          return $resultJson->setData([
            'message' => 'no_response'
          ])->setHttpResponseCode(400);
        } elseif ($response->refunded) {
          return $resultJson->setData([
            'message' => 'payment_refunded'
          ])->setHttpResponseCode(200);
        }

        if ($response->status === 'paid') {
          $payment->capture(null);

          $order->setCanSendNewEmailFlag(true);
          $order->save();

          try {
            $this->orderSender->send($order);
          } catch (\Exception $e) {}

          $order->save();

          return $resultJson->setData([
            'ventipay_checkout_id' =>  $checkoutId,
            'id' => $response->id,
            'status' => $response->status,
            'refunded' => $response->refunded,
            'message' => 'OK'
          ]);
        }

        return $resultJson->setData([
          'message' => 'no_status_paid',
          'status' => $response->status
        ])->setHttpResponseCode(400);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}