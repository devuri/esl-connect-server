# ESL Connect Server

Server-side plan enforcement for [Easy Software License](https://developer.developer.developer/easy-software-license). This plugin runs exclusively on the PremiumPocket license server and handles license limit enforcement so customers never see the enforcement logic.

## Overview

ESL Connect coordinates with customer stores to enforce plan-based license limits:

| Plan | License Limit |
|------|---------------|
| Solo | 500 |
| Studio | Unlimited |
| Agency | Unlimited |

Since ESL customers receive the full plugin codebase, client-side enforcement can be bypassed. ESL Connect keeps all enforcement logic server-side — customers only see API responses.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [Easy Software License](https://developer.developer.developer/easy-software-license) plugin
- [Easy Digital Downloads](https://developer.developer.developer/) with Recurring Payments

## Installation

1. Upload the `esl-connect-server` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure the required filters (see [Configuration](#configuration))
4. Verify installation by checking the health endpoint

```bash
curl https://your-site.com/wp-json/esl-connect/v1/health
```

## Configuration

Add these filters to your theme's `functions.php` or a must-use plugin:

### Required

```php
// Set your ESL product ID in EDD
add_filter( 'ppk_esl_connect_server_esl_product_id', function(): int {
    return 123; // Replace with your actual product ID
});

// Map EDD price IDs to plan names
add_filter( 'ppk_esl_connect_server_price_plan_map', function( array $map ): array {
    return [
        1 => 'solo',    // Price ID 1 = Solo
        2 => 'studio',  // Price ID 2 = Studio
        3 => 'agency',  // Price ID 3 = Agency
    ];
});
```

### Optional

```php
// Customize plan limits (defaults shown)
add_filter( 'ppk_esl_connect_server_plan_limits', function( array $limits ): array {
    return [
        'solo'   => 500,
        'studio' => null, // unlimited
        'agency' => null, // unlimited
    ];
});

// Adjust rate limit (default: 60 requests/minute)
add_filter( 'ppk_esl_connect_server_rate_limit', function( int $limit ): int {
    return 60;
});

// Customize upgrade URL shown to customers at limit
add_filter( 'ppk_esl_connect_server_upgrade_url', function( string $url ): string {
    return 'https://your-site.com/pricing/';
});
```

## How It Works

### Connection Flow

1. Customer purchases ESL and activates their license
2. Server creates a connected store record with derived credentials
3. Credentials are returned in the activation response
4. Customer's ESL instance stores credentials and uses them for API calls

### License Creation Flow

```
Customer Store                          ESL Connect Server
     │                                         │
     │  POST /license/reserve                  │
     │  (signed request)                       │
     ├────────────────────────────────────────►│
     │                                         │
     │                            Validate signature
     │                            Check rate limit
     │                            Verify plan limits
     │                            Increment count
     │                                         │
     │  { "allowed": true, "remaining": 344 } │
     │◄────────────────────────────────────────┤
     │                                         │
     │  Create license locally                 │
     │                                         │
```

### Security

- **HMAC-SHA256 signatures** on all mutating requests
- **5-minute timestamp window** prevents replay attacks
- **Rate limiting** at 60 requests/minute per store
- **Server-authoritative counts** — client cannot manipulate
- **Fail-closed design** — network errors deny license creation

## API Endpoints

All endpoints are under `/wp-json/esl-connect/v1/`

### `POST /license/reserve`

Check entitlement and reserve a license slot before creation.

**Headers:**
```
Content-Type: application/json
X-ESL-Timestamp: 1704067200
X-ESL-Signature: <hmac-sha256>
```

**Request:**
```json
{
    "store_token": "a1b2c3d4...",
    "license_key_hash": "sha256_of_license_key",
    "product_id": "42"
}
```

**Response (allowed):**
```json
{
    "success": true,
    "allowed": true,
    "data": {
        "license_count": 156,
        "license_limit": 500,
        "remaining": 344,
        "plan": "solo"
    }
}
```

**Response (denied):**
```json
{
    "success": false,
    "allowed": false,
    "error": "license_limit_reached",
    "message": "You've reached your plan limit. Upgrade to continue creating licenses.",
    "data": {
        "license_count": 500,
        "license_limit": 500,
        "remaining": 0,
        "plan": "solo",
        "upgrade_url": "https://your-site.com/pricing/"
    }
}
```

### `POST /license/release`

Release a license slot when a license is deleted.

**Request:**
```json
{
    "store_token": "a1b2c3d4...",
    "license_key_hash": "sha256_of_license_key"
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "license_count": 155,
        "license_limit": 500,
        "remaining": 345
    }
}
```

### `GET /status`

Get current connection status and plan entitlements.

**Request:**
```
GET /status?store_token=a1b2c3d4...
```

**Response:**
```json
{
    "success": true,
    "data": {
        "connected": true,
        "plan": "solo",
        "license_count": 156,
        "license_limit": 500,
        "remaining": 344,
        "usage_percent": 31.2,
        "is_unlimited": false,
        "upgrade_available": true,
        "next_plan": "studio"
    }
}
```

### `POST /sync`

Reconcile local count with server count.

**Request:**
```json
{
    "store_token": "a1b2c3d4...",
    "reported_count": 150
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "server_count": 156,
        "reported_count": 150,
        "difference": 6,
        "action": "server_authoritative",
        "license_limit": 500
    }
}
```

### `GET /health`

Service health check (no authentication required).

**Response:**
```json
{
    "status": "healthy",
    "version": "0.1.0",
    "database": "connected",
    "connected_stores": 127,
    "total_stores": 134,
    "events_today": 1543,
    "stores_at_limit": 7,
    "denials_today": 23,
    "timestamp": "2026-01-15T12:00:00+00:00"
}
```

## Admin Dashboard

The plugin adds an **ESL Connect** menu to WordPress admin with two pages:

### Stores

View all connected stores with filtering and pagination:
- Store URL and masked token
- Plan badge (Solo/Studio/Agency)
- Usage display (count/limit or "Unlimited")
- Connection status
- Last seen timestamp

### Health

Service health overview:
- Version and database status
- Connected/total store counts
- Today's events and denials
- API endpoint reference

## Database Tables

### `{prefix}_esl_connect_stores`

Connected store registry with plan entitlements.

| Column | Description |
|--------|-------------|
| `store_token` | SHA-256 identifier derived from license key |
| `store_secret_hash` | HMAC key for request signing |
| `esl_license_id` | Foreign key to ESL licenses table |
| `plan` | Current plan: solo, studio, agency |
| `license_count` | Current licenses created |
| `license_limit` | Maximum allowed (NULL = unlimited) |
| `is_connected` | Active connection status |
| `over_limit` | Flagged if over limit after downgrade |

### `{prefix}_esl_connect_events`

Audit log of all API interactions.

| Column | Description |
|--------|-------------|
| `event_type` | license_reserved, license_released, reserve_denied, sync |
| `license_key_hash` | SHA-256 of affected license key |
| `count_before` | License count before event |
| `count_after` | License count after event |
| `allowed` | Whether action was permitted |
| `denial_reason` | Reason if denied |

## Hooks Reference

### Filters

```php
// Customize plan limits
ppk_esl_connect_server_plan_limits

// Adjust rate limit per store
ppk_esl_connect_server_rate_limit

// Set ESL product ID
ppk_esl_connect_server_esl_product_id

// Map price IDs to plans
ppk_esl_connect_server_price_plan_map

// Customize upgrade URL
ppk_esl_connect_server_upgrade_url
```

### Actions

```php
// Plugin fully loaded
ppk_esl_connect_server_loaded

// Store connected
ppk_esl_connect_server_store_connected

// Store disconnected
ppk_esl_connect_server_store_disconnected

// License slot reserved
ppk_esl_connect_server_license_reserved

// License slot released
ppk_esl_connect_server_license_released

// Store hit limit
ppk_esl_connect_server_limit_reached

// Store over limit after downgrade
ppk_esl_connect_server_store_over_limit

// Sync performed
ppk_esl_connect_server_sync
```

## Client Integration

Customer stores need the `ConnectClient` and `ConnectHooks` classes added to their ESL plugin. See [Client Integration](./docs/client-integration.md) for details.

## File Structure

```
esl-connect-server/
├── esl-connect-server.php          # Bootstrap
├── src/
│   ├── Plugin.php                  # Main orchestration
│   ├── Database/
│   │   └── Installer.php           # Table creation
│   ├── Store/
│   │   └── StoreManager.php        # Business logic
│   ├── Api/
│   │   ├── ConnectController.php   # REST endpoints
│   │   └── RequestValidator.php    # Auth & rate limiting
│   ├── Integrations/
│   │   └── EslIntegration.php      # ESL/EDD hooks
│   └── Admin/
│       └── AdminPage.php           # Dashboard UI
├── readme.txt                      # WordPress.org readme
└── CHANGELOG.md
```

## Coding Standards

This plugin follows:
- PSR-12 coding style in `src/` directory
- WordPress Coding Standards for hooks and database
- PHPStan level 6 compatibility
- `declare(strict_types=1)` in all class files

## License

GPL v2 or later. See [LICENSE](./LICENSE) for details.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for version history.
