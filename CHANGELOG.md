# Changelog

## [1.3.3] - 2025-11-15

### Added

- Added automatic order synchronization feature to check and update status of cancelled or pending payment orders from today
- Added cron job that runs every 30 minutes to automatically sync today's orders with payment gateway status
- Added manual sync button in admin configuration with visual report showing sync results
- Added configuration options to enable/disable cron job and manual sync button visibility
- Orders with successful payments that were missed due to callback failures are now automatically processed and updated

### Enhanced

- Enhanced order status management by automatically detecting and processing successful payments that may have been missed

## [1.3.1] - 2025-09-21

### Added

- Added configurable auto-refresh feature for cart page after order cancellation to ensure proper page updates across all themes, particularly when accessing previously saved products - configurable through plugin settings.


## [1.3.0] - 2025-09-19

### Added

- Added universal iframe compatibility across all Magento themes with robust header detection and fallback mechanisms
- Added intelligent cache invalidation and inventory reindexing after order cancellation to ensure immediate stock updates
- Added proper postMessage handling for iframe payment completion with correct redirect flow to success pages
- Improved order cancellation logic with comprehensive logging and duplicate prevention

### Enhanced

- Enhanced automatic order cancellation feature for abandoned payment pages with better stock restoration
- Enhanced full-page iframe payment option with loading indicators and better error handling
- Enhanced theme compatibility for both "Next Page" and "Same Page" iframe modes

### Fixed

- Fixed stock availability display issues on cart page after order cancellation
- Fixed JavaScript errors in iframe initialization across different themes
- Fixed payment completion redirect flow to properly go to checkout success page instead of cart


## [1.2.7] - 2025-08-25

### Fix

- Fixed issue where the cart was emptied prematurely.

## [1.2.5] - 2025-08-23

### Fix

- Enhanced plugin version display functionality.
- Resolved null deprecation warnings for PHP 8.4 and later versions.

## [1.2.3] - 2025-08-18

### Tweak

- Added display of payment information for orders with multiple payment methods in the order details.

## [1.2.2] - 2025-08-17

### Fix

- Resolved an issue where orders with multiple credit card transactions and multipass were incorrectly set to "pending" instead of "processing".

## [1.1.7] - 2025-03-20

### Added

- Introduced an option to automatically add a "compensation" product to the cart when using weighted items, addressing rounding discrepancies between the Magento order total and the sum of individual item totals.
- Added a setting to enable or disable the automatic addition of the "compensation" product to the cart within the plugin configuration.

## [1.1.5] - 2025-02-10

### Added

- Added support for store-specific configuration for multi-store setups. If PayPlus is not enabled for a particular store ID, the email settings will not be overridden.

## [1.1.4] - 2024-12-03

### Fixed

- Minor fix - using $order->getBillingAddres() to use the order customer data for billing and tokens.

## [1.1.2] - 2024-12-03

### Fixed

- Tokens are now saved with customers billing information and not with the orders shipping information (which doesn't always exist since it's product dependant).
- This will fix failed token payments which are failing due to: If a token saved with shipping info it will not work with non-shipping order since the customer info will differ... when using billing info this is solved and is the proper way to view a transaction.

## [1.1.1] - 2024-11-14

### Added

- New feature for selecting status and state for J5 approval orders.

  Option to select status and state for approval (J5) orders in PayPlus gateway settings.

  Note:

  - Default status for new orders created in J5 is now **Processing**.
  - Default status for new orders approved in J5 is now **On-Hold**.

### Changed

- Removed check for product types in BaseOrderRequest.php - unclear why it was done.

## [1.1.0] - 2024-09-19

### Fixed

- Minor bug fixes - Fixed depreceated errors.
