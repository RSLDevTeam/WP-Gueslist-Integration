# Guestlist Integration

Standalone WordPress plugin project for the Guestlist API connection. This first version has no WooCommerce or ACF requirement.

## Install

Copy this directory into `wp-content/plugins/guestlist-integration`, activate **Guestlist Integration**, then configure it under **Settings → Guestlist Integration**. Use the API URL without a trailing slash. The settings are stored in the WordPress option `guestlist_integration_settings`.

The API client is available to project-specific code through `guestlist_integration_client()`:

```php
$response = guestlist_integration_client()->register_franchise($payload);
```

The client handles Guestlist login, token caching, bearer authorization, and one retry after HTTP 401. It also exposes `change_franchise_status($backstage_id, 'disable'|'reenable', $reason)`.

## Backstage migration boundary

This project is an isolated foundation. Do not activate it alongside the current theme integration expecting a complete replacement. The existing theme still owns entitlement rules, WooCommerce product flags, account UI, franchise payload mapping, seat sync, and the inbound booking endpoint. Those pieces need to move into an optional Backstage adapter before the theme's `inc/guestlist-sync.php` and `apis/endpoints/guestlist-booking-link.php` are removed.

The current theme uses ACF options (`guestlist_api_url`, `guestlist_api_email`, `guestlist_api_password`, `guestlist_enabled`, `guestlist_default_currency`). Transfer their values to the plugin settings during migration. The optional `booking_webhook_secret` setting is reserved for the inbound webhook adapter; no webhook route is registered by this foundation.
