# Guestlist Integration

Standalone WordPress plugin project for the Guestlist API connection. This first version has no WooCommerce or ACF requirement.

## Install

Copy this directory into `wp-content/plugins/guestlist-integration`, activate **Guestlist Integration**, then configure it under **Settings → Guestlist Integration**. Use the API URL without a trailing slash. The settings are stored in the WordPress option `guestlist_integration_settings`.

The API client is available to project-specific code through `guestlist_integration_client()`:

```php
$response = guestlist_integration_client()->register_franchise($payload);
```

The client handles Guestlist login, token caching, bearer authorization, and one retry after HTTP 401. It also exposes `change_franchise_status($backstage_id, 'disable'|'reenable', $reason)`.

## Project adapters

The plugin now provides a stable adapter contract for every WordPress project. Projects map their own users, orders, ACF fields, custom post types, or external events into a normalized Guestlist payload, then call:

```php
guestlist_integration_register_franchise($payload, [
    'project' => 'example-project',
    'source_id' => $local_franchise_id,
]);

guestlist_integration_change_franchise_status($backstage_id, 'disable', 'Subscription cancelled');
```

Alternatively, projects can emit the `guestlist_integration_register_franchise` and `guestlist_integration_change_franchise_status` actions directly.

### Adapter hooks

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `guestlist_integration_register_franchise` | `$payload, $context` | Send a normalized franchise-registration payload. |
| `guestlist_integration_change_franchise_status` | `$backstage_id, $action, $reason, $context` | Disable or re-enable a Guestlist franchise. |
| `guestlist_integration_franchise_payload` | `$payload, $context` | Filter the registration payload before it is sent. |
| `guestlist_integration_request_succeeded` | `$operation, $response, $context` | Record or react to a successful API request. |
| `guestlist_integration_request_failed` | `$operation, $error, $context` | Log, retry, or alert on a `WP_Error` or non-2xx API response. |

For example, a project can add local metadata to every outgoing registration and log failures without changing this plugin:

```php
add_filter('guestlist_integration_franchise_payload', function (array $payload, array $context): array {
    $payload['source_project'] = $context['project'] ?? 'unknown';

    return $payload;
}, 10, 2);

add_action('guestlist_integration_request_failed', function (string $operation, WP_Error $error, array $context): void {
    error_log(sprintf('Guestlist %s failed for %s: %s', $operation, $context['source_id'] ?? 'unknown', $error->get_error_message()));
}, 10, 3);
```

The adapter deliberately does not assume WooCommerce, ACF, user meta names, or a franchise schema. Those belong in a small project-specific mapping layer, while this plugin owns authentication, API transport, retries, and consistent result hooks.

## Backstage migration boundary

This project is an isolated foundation. Do not activate it alongside the current theme integration expecting a complete replacement. The existing theme still owns entitlement rules, WooCommerce product flags, account UI, franchise payload mapping, seat sync, and the inbound booking endpoint. Those pieces need to move into an optional Backstage adapter before the theme's `inc/guestlist-sync.php` and `apis/endpoints/guestlist-booking-link.php` are removed.

The current theme uses ACF options (`guestlist_api_url`, `guestlist_api_email`, `guestlist_api_password`, `guestlist_enabled`, `guestlist_default_currency`). Transfer their values to the plugin settings during migration. The optional `booking_webhook_secret` setting is reserved for the inbound webhook adapter; no webhook route is registered by this foundation.
