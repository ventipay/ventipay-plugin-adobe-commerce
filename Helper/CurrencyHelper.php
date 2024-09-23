<?php
namespace VentiPay\Gateway\Helper;

class CurrencyHelper {
  const SUPPORTED_CURRENCIES = [
    'clf' => ['precision' => 4],
    'clp' => ['precision' => 0],
    'eur' => ['precision' => 2],
    'usd' => ['precision' => 2]
  ];

  public static function transformNumber ($number, $currency) {
    if (!isset(self::SUPPORTED_CURRENCIES[$currency])) {
      return false;
    }

    $getCurrency = self::SUPPORTED_CURRENCIES[$currency];

    if ($getCurrency['precision'] === 0) {
        return $number;
    }

    return $number * (10 ** $getCurrency['precision']);
  }

  public static function isSupported ($currency) {
    if (!isset(self::SUPPORTED_CURRENCIES[$currency])) {
      return false;
    }

    return true;
  }
}
