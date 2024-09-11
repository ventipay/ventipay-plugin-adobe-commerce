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

class Checkout extends Action implements CsrfAwareActionInterface
{
    protected $resultJsonFactory;
    protected $orderRepository;
    protected $curl;
    protected $config;
    protected $request;
    protected $invoiceManagement;
    protected $invoiceRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        InvoiceManagementInterface $invoiceManagement,
        InvoiceRepositoryInterface $invoiceRepository
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if ($this->request->getMethod() !== 'POST') {
          return $resultJson->setData([
            'message' => 'route_not_found'
          ])->setHttpResponseCode(404);
        };

        $orderId = $this->getRequest()->getParam('orderId');

        if (!$orderId) {
          return $resultJson->setData([
            'message' => 'orderid_is_required'
          ])->setHttpResponseCode(400);
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
          return $resultJson->setData([
            'message' => 'order_not_pending_payment',
            'status' => $order->getStatus()
          ])->setHttpResponseCode(400);
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId) {
          return $resultJson->setData([
            'message' => 'checkout_id_not_found'
          ])->setHttpResponseCode(404);
        } elseif (strpos($checkoutId, 'chk_') !== 0) {
          return $resultJson->setData([
            'message' => 'checkout_id_invalid'
          ])->setHttpResponseCode(406);
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

        $payment->setIsTransactionPending(0);
        $payment->setIsTransactionClosed(true);
        $payment->save();
        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

        $comment = __('Payment authorized');
        $order->addStatusHistoryComment($comment, $payment->getOrder()->getStatus());

        $this->orderRepository->save($order);

        if ($order->canInvoice()) {
          $invoice = $this->invoiceManagement->prepareInvoice($order);

          if ($invoice) {
            $invoice->register();
            $this->invoiceRepository->save($invoice);
          }
        }

        return $resultJson->setData([
          'ventipay_checkout_id' =>  $checkoutId,
          'id' => $response->id,
          'status' => $response->status,
          'refunded' => $response->refunded,
          'message' => 'OK'
        ]);
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