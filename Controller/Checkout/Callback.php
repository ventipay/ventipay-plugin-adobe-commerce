<?php
namespace VentiPay\Gateway\Controller\Checkout;

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
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteFactory;
use Magento\Customer\Model\Session as CustomerSession;

class Callback extends Action
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
    protected $logger;
    protected $checkoutSession;
    protected $quoteFactory;
    protected $customerSession;

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
        OrderSender $orderSender,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession,
        QuoteFactory $quoteFactory,
        CustomerSession $customerSession
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->invoiceManagement = $invoiceManagement;
        $this->invoiceRepository = $invoiceRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->customerSession = $customerSession;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = $this->request->getParam('order_id');

        if (!$orderId) {
            $this->logger->warning('[VentiPay Callback] Missing order_id param.');
            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->error('[VentiPay Callback] Order not found.', ['order_id' => $orderId]);
            return $resultRedirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $checkoutId = $payment->getAdditionalInformation('ventipay_checkout_id');

        if (!$checkoutId || strpos($checkoutId, 'chk_') !== 0) {
            $this->logger->error('[VentiPay Callback] Invalid or missing checkout ID.', ['order_id' => $order->getId()]);
            return $resultRedirect->setPath('checkout/cart');
        }

        if ($payment->getAmountPaid() > 0 || $order->getTotalPaid() > 0) {
            $this->logger->info('[VentiPay Callback] Order already paid. Skipping capture.', ['order_id' => $order->getId()]);
            return $resultRedirect->setPath('checkout/onepage/success');
        }

        $apiKey = $this->config->getValue(
            'payment/ventipay_gateway/merchant_gateway_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );

        try {
            $this->curl->setCredentials($apiKey, '');
            $this->curl->get('https://api.ventipay.com/v1/checkouts/' . $checkoutId);
        } catch (\Exception $e) {
            $this->logger->error('[VentiPay Callback] API request failed.', ['error' => $e->getMessage()]);
            return $resultRedirect->setPath('checkout/cart');
        }

        if ($this->curl->getStatus() !== 200) {
            $this->logger->error('[VentiPay Callback] Unexpected API status.', ['status' => $this->curl->getStatus()]);
            return $resultRedirect->setPath('checkout/cart');
        }

        $response = json_decode($this->curl->getBody());

        if (!isset($response->status) || $response->status !== 'paid') {
            $this->logger->warning('[VentiPay Callback] Checkout status not paid.', [
                'checkout_id' => $checkoutId,
                'status' => $response->status ?? null
            ]);

            // Restore quote for unpaid flow
            try {
                $quoteId = $order->getQuoteId();
                $quote = $this->quoteFactory->create()->load($quoteId);

                if ($quote->getId()) {
                    $quote->setIsActive(true)
                        ->setReservedOrderId(null)
                        ->setStoreId($order->getStoreId());

                    if ($order->getCustomerIsGuest()) {
                        // Guest: detach customer
                        $quote->setCustomerId(null)
                              ->setCustomerEmail($order->getCustomerEmail());
                    } else {
                        // Logged-in: sync with current session or fallback to order's customer_id
                        if ($this->customerSession->isLoggedIn()) {
                            $sessionCustomerId = $this->customerSession->getCustomerId();
                            $quote->setCustomerId($sessionCustomerId);
                        } else {
                            $quote->setCustomerId($order->getCustomerId());
                        }

                        $quote->setCustomerEmail($order->getCustomerEmail());
                    }

                    $this->checkoutSession->replaceQuote($quote);
                    $this->checkoutSession->getQuote()->save();

                    $this->logger->info('[VentiPay Callback] Quote restored and replaced in session.', [
                        'quote_id' => $quoteId,
                        'customer_id' => $quote->getCustomerId()
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('[VentiPay Callback] Failed to restore quote.', ['error' => $e->getMessage()]);
            }

            return $resultRedirect->setPath('checkout/cart');
        }

        // Payment successful — capture and confirm order
        try {
            $payment->capture(null);
            $order->setCanSendNewEmailFlag(true);
            $this->orderRepository->save($order);
            $this->logger->info('[VentiPay Callback] Order captured.', ['order_id' => $order->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('[VentiPay Callback] Capture failed.', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
                $this->logger->info('[VentiPay Callback] Order email sent.', ['order_id' => $order->getId()]);
            } else {
                $this->logger->info('[VentiPay Callback] Email already sent.', ['order_id' => $order->getId()]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[VentiPay Callback] Email send failed.', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $resultRedirect->setPath('checkout/onepage/success');
    }
}
