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
use Psr\Log\LoggerInterface;

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
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        InvoiceManagementInterface $invoiceManagement,
        InvoiceRepositoryInterface $invoiceRepository,
        OrderSender $orderSender,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if ($this->request->getMethod() !== 'POST') {
            return $resultJson->setData(['message' => 'route_not_found'])->setHttpResponseCode(200);
        }

        $orderId = $this->request->getParam('order_id');
        if (!$orderId) {
            return $resultJson->setData(['message' => 'orderid_is_required'])->setHttpResponseCode(200);
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $resultJson->setData(['message' => 'order_not_found'])->setHttpResponseCode(200);
        }

        $this->logger->info('[VentiPay] Webhook received.', [
            'order_id' => $orderId,
            'order_status' => $order->getStatus()
        ]);

        if ($order->getStatus() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            return $resultJson->setData([
                'message' => 'order_not_pending_payment',
                'status' => $order->getStatus()
            ])->setHttpResponseCode(200);
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId || strpos($checkoutId, 'chk_') !== 0) {
            return $resultJson->setData([
                'message' => 'checkout_id_invalid',
                'checkout_id' => $checkoutId
            ])->setHttpResponseCode(200);
        }

        $apiKey = $this->config->getValue(
            'payment/ventipay_gateway/merchant_gateway_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );

        $params = ['expand' => ['payment_method']];

        try {
            $this->curl->setCredentials($apiKey, '');
            $this->curl->get('https://api.ventipay.com/v1/checkouts/' . $checkoutId . '?' . http_build_query($params));
        } catch (\Exception $e) {
            $this->logger->error('[VentiPay] API call failed: ' . $e->getMessage());
            return $resultJson->setData(['message' => 'ventipay_call_failed'])->setHttpResponseCode(500);
        }

        if ($this->curl->getStatus() !== 200) {
            return $resultJson->setData(['message' => 'checkout_not_found'])->setHttpResponseCode(200);
        }

        $response = json_decode($this->curl->getBody());

        if (!isset($response) || !isset($response->status)) {
            return $resultJson->setData(['message' => 'no_response'])->setHttpResponseCode(400);
        }

        if (!empty($response->refunded)) {
            return $resultJson->setData(['message' => 'payment_refunded'])->setHttpResponseCode(200);
        }

        if ($response->status === 'paid') {
            if (isset($response->payment_method)) {
                $payment->setAdditionalInformation('ventipay_payment_method_brand', $response->payment_method->brand ?? null);
                $payment->setAdditionalInformation('ventipay_payment_method_funding', $response->payment_method->funding ?? null);
                $payment->setAdditionalInformation('ventipay_payment_method_last4', $response->payment_method->last4 ?? null);
            }

            // Avoid duplicate capture
            if ($payment->getAmountPaid() == 0) {
                $payment->capture(null);
            }

            // Prevent duplicate emails
            $order->setCanSendNewEmailFlag(true);
            if (!$order->getEmailSent()) {
                try {
                    $this->orderSender->send($order);
                    $order->setEmailSent(true);
                } catch (\Throwable $e) {
                    $this->logger->warning('[VentiPay] Order email send skipped (mailer error): ' . $e->getMessage());
                }
            }

            $this->orderRepository->save($order);

            return $resultJson->setData([
                'ventipay_checkout_id' => $checkoutId,
                'id' => $response->id,
                'status' => $response->status,
                'refunded' => $response->refunded,
                'message' => 'OK'
            ])->setHttpResponseCode(200);
        }

        return $resultJson->setData([
            'message' => 'no_status_paid',
            'status' => $response->status
        ])->setHttpResponseCode(200);
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
