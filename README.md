# Plugin oficial de VentiPay para Adobe Commerce (Magento)

Acepta pagos con VentiPay en tiendas Adobe Commerce (Magento)

## Requisitos

* PHP 7+
* Adobe Commerce 2.4.6

## Versionamiento

Usamos [SemVer](https://semver.org) para organizar el versionamiento, así que puedes actualizar de manera segura y regular cualquier versión menor y de patch.

## Changelog

Usamos la [página de releases](https://github.com/ventipay/ventipay-plugin-adobe-commerce/releases) de GitHub para documentar los cambios.

## Instalación

```
composer require ventipay/adobe-commerce-checkout
php bin/magento module:enable VentiPay_Gateway --clear-static-content
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

## Uso

Lo primero es conseguir tu API Key. Puedes obtenerla desde el [Dashboard](https://dashboard.ventipay.com/).

Luego, en la sección Métodos de Pago de la configuración de Adobe Commerce, debes habilitar el método de pago "VentiPay" y configurar tu API Key

## Licencia

OSL