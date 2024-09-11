<?php
namespace VentiPay\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'ventipay_gateway';

    protected $methodCode = self::CODE;
    protected $method;

    public function __construct(PaymentHelper $paymentHelper)
    {
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                self::CODE => [
                    'title' => $this->method->getTitle(),
                    'isActive' => $this->method->isAvailable(),
                    'additionalData' => [
                        // other data here
                    ]
                ]
            ]
        ];

        return $config;
    }
}
