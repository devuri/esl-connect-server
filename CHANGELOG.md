# Changelog

All notable changes to ESL Connect Server will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-01-04

### Added

- Initial release of ESL Connect Server
- Database tables: `esl_connect_stores`, `esl_connect_events`
- REST API endpoints:
  - `POST /esl-connect/v1/license/reserve` - Check limit and reserve slot
  - `POST /esl-connect/v1/license/release` - Release slot on deletion
  - `GET /esl-connect/v1/status` - Get plan entitlements
  - `POST /esl-connect/v1/sync` - Reconcile counts
  - `GET /esl-connect/v1/health` - Service health check
- HMAC-SHA256 request signing with 5-minute timestamp window
- Rate limiting: 60 requests/minute per store (configurable via filter)
- Plan limits: Solo (500), Studio (unlimited), Agency (unlimited)
- ESL Integration:
  - Automatic store creation on license activation
  - Store disconnection on license deactivation
  - Plan updates on subscription upgrade/downgrade
- Admin dashboard:
  - Connected stores list with pagination and filtering
  - Health stats page
  - Usage indicators and status badges
- Audit logging of all reserve/release/sync operations
- Filters for customization:
  - `ppk_esl_connect_server_plan_limits`
  - `ppk_esl_connect_server_rate_limit`
  - `ppk_esl_connect_server_esl_product_id`
  - `ppk_esl_connect_server_price_plan_map`
  - `ppk_esl_connect_server_upgrade_url`
- Actions for extensibility:
  - `ppk_esl_connect_server_loaded`
  - `ppk_esl_connect_server_store_connected`
  - `ppk_esl_connect_server_store_disconnected`
  - `ppk_esl_connect_server_license_reserved`
  - `ppk_esl_connect_server_license_released`
  - `ppk_esl_connect_server_limit_reached`
  - `ppk_esl_connect_server_store_over_limit`
  - `ppk_esl_connect_server_sync`
