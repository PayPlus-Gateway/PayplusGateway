=== PayPlus Payment Gateway ===
Contributors: PayPlus LTD
Tags: Payment Gateway, Credit Cards, Charges and Refunds, PayPlus, Subscriptions, Tokenization, Magento, Magento payment gateway, Magento payplus, capture payplus Magento
Requires at least: 3.0.1
Tested up to: 7.4
Requires PHP: 7.4
Stable tag: 1.0.0
PlugIn URL: https://www.payplus.co.il/magento
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

PayPlus.co.il Payment Gateway for Magento extends the functionality of Magento to accept payments from credit/debit cards and choose another alternative method on a single payment page. With PayPlus Gateway, You can choose a dedicated domain for your own payment page

== Description ==
<h3>PayPlus Payment Gateway for Magento</h3>
Makes your website accept debit and credit cards on your Magento store in a safe way and design your own payment page with high functionalities. SSL is not required.

<h3>Before you install this plugin:</h3>
To receive your account credentials you have to contact first PayPlus and to join the service before installing this Plugin

<h3>Plugin Disclaimer:</h3>
PayPlus does not accept liability for any damage, loss, cost (including legal costs), expenses, indirect losses or consequential damage of any kind which may be suffered or incurred by the user from the use of this service.

Before installation, it is important to know that this plugin relies on third-party services.
However, the third-party so mentioned is the PayPlus core engine at their servers - the providers of this plugin.

By being a payment processor, just like many of its kind, it must send some transaction details to the third-party server (itself) for token generation and transaction logging statistics and connecting to invoices.

It is this transfer back and forth of data between your Magento and the PayPlus servers that we would like to bring to your attention clearly and plainly.

The main links to PayPlus, its terms and conditions, and privacy policy are as listed:
- Home Page: https://www.payplus.co.il
- Plugin Instruction page: https://www.payplus.co.il/magento
- Terms and Conditions: https://www.payplus.co.il/privacy

The above records, the transaction details, are not treated as belonging to PayPlus and are never used for any other purposes.

The external files referenced by this plugin, due to Magento policy recommendations, are all included in the plugin directory.

== Installation ==
1. Download the zip package and extract it.

2. Create folder "Payplus" inside your Magento's installation files in app/code.

3. The new structure should look like this:
- <magento root>/app/code/Payplus/PayplusGateway/<extension files and folders>
4. Run the following commands:

- bin/magento setup:upgrade

- bin/magento setup:static-content:deploy -f

- bin/magento cache:clean

- bin/magento cache:flush

5. Once complete, head to the admin panel/payment methods of your Magento 2 installation:

- Stores - Configuration - Sales - Payment methods.

6. Locate the PayPlus configuration section where you can enable, add credentials and customize the behavior of the extension.