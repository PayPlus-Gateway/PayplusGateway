# Changelog

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
