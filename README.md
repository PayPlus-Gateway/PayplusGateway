# PayPlus Payment Gateway

Contributors: PayPlus LTD
Tags: Payment Gateway, Credit Cards, Charges and Refunds, PayPlus, Subscriptions, Tokenization, Magento, Magento payment gateway, Magento payplus, capture payplus Magento
Requires at least: 3.0.1
Tested up to: 7.4
Requires PHP: 7.4
Stable tag:1.1.2
PlugIn URL: https://www.payplus.co.il/magento
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

## Installation

On the Magento's root directory, type: composer require payplus-payment/payplus-gateway and then run the following commands:

- bin/magento setup:upgrade

- bin/magento setup:static-content:deploy -f

- bin/magento cache:clean

- bin/magento cache:flush

Once complete, head to the admin panel/payment methods of your Magento 2 installation:

- Stores - Configuration - Sales - Payment methods.

Locate the PayPlus configuration section where you can enable, add credentials and customize the behavior of the extension.
