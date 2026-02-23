# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

# 1.5.1 - 2025-12-02
### Fixed
- Fixed response handling in `Paysera\DeliveryApi\MerchantClient\MerchantClient::validateProjectCredentials()`

# 1.5.0 - 2025-11-24
### Added
- Added `validateProjectCredentials()` method for validating project credentials without authentication
- Added `ProjectCredentials` entity class for credentials validation

## 1.4.0 - 2025-11-24
### Fixed
- Fixed working with files

## 1.3.0 - 2025-11-17
### Added
- Added customs declaration for orders

## 1.2.1 - 2025-04-29
- Reverted change for a gateway list for test mode

## 1.2.0 - 2025-03-25
- Library refreshed according the latest documentation.
- Added support for a `test_mode` when creating order.

## 1.1.0 - 2024-09-10
- Library refreshed according latest documentation.
- Added support for a `orders-prepaid` API method.
- Added possibility to create `prepaid` orders.

## 1.0.0 - 2024-01-26
- Library refreshed according the latest documentation.
- Added order_notification object to the Order resource to receive notifications when the order is updated.
- Added shipping methods list receiving for specific gateway resource.

## 0.6.0 - 2021-09-06
- Library refreshed according the latest documentation

## 0.5.0 - 2021-05-14
- Library refreshed according the latest documentation

## 0.4.0 - 2021-04-29
- Library refreshed according the latest documentation

## 0.3.0 - 2021-02-23
- Some endpoints fixed, "packages-suggested" endpoint removed

## 0.2.0 - 2021-02-19
- "GET /packages" and "GET packages/{id}" endpoints removed
- Paysera.File type temp fix

## 0.1.0 - 2021-02-09
- Generated library from RAML 
