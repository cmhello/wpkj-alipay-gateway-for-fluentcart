# Changelog

All notable changes to WPKJ FluentCart Alipay Payment will be documented in this file.

## [1.0.0] - 2025-10-19

### Fixed
- **Settings Field Types**: Changed private key and public key field types from `textarea` to `password`
  - FluentCart framework does not support `textarea` type for settings fields
  - Using `password` type ensures proper rendering and security for sensitive credentials
  - All credential fields now display correctly in the admin settings page

### Technical Details
- Field types supported by FluentCart: `text`, `password`, `html_attr`, `notice`, `tabs`, `provider`, etc.
- Private keys and public keys are sensitive data that should use `password` type
- Keys are encrypted before storage using FluentCart's helper functions

### Added
- Initial release of WPKJ FluentCart Alipay Payment Gateway
- Multi-platform payment support (PC Web, Mobile WAP, Alipay App)
- Automatic client detection
- RSA2 signature encryption
- Webhook notification support
- Refund functionality
- Multi-currency support (14+ currencies)
- Test/Sandbox mode
- Comprehensive logging
- Full internationalization support

### Notes
- When entering RSA keys in admin panel, paste the key content without headers/footers
- The plugin will automatically format keys with proper headers/footers for API use
- Both test and live credentials can be configured in separate tabs
