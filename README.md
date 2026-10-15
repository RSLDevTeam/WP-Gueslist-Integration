# Guestlist Integration

Standalone WordPress plugin project for the Guestlist API connection. This first version has no WooCommerce or ACF requirement.

## Install

Copy this directory into `wp-content/plugins/guestlist-integration`, activate **Guestlist Integration**, then configure it under **Settings → Guestlist Integration**. Use the API URL without a trailing slash. The settings are stored in the WordPress option `guestlist_integration_settings`.

The API client is available to project-specific code through `guestlist_integration_client()`:

```php
$response = guestlist_integration_client()->register_franchise($payload);
```

The client handles Guestlist login, token caching, bearer authorization, and one retry after HTTP 401. It also exposes `change_franchise_status($integration_id, 'disable'|'reenable', $reason)`.

## Franchise ID source and integration name

Registration always sends the API field `bakstagefranchise_id` (this spelling is the API contract). The **Integration name** setting only controls status URLs, for example `/api/franchise/partner/{id}/{action}`. It never changes the registration field name.

Under **Settings → Guestlist Integration**, choose **Franchise ID source**:

| Source | Where the value comes from |
| --- | --- |
| Supplied integration_id / API ID (default) | Payload `integration_id`, or an explicit `bakstagefranchise_id`. |
| WordPress user ID (user.id) | The WordPress user identified by `user_id` in registration context. |
| Custom field from registration data | The top-level payload field named in **Custom ID field / user meta key**. |
| Custom ID from user meta | The named user meta field for the context's `user_id`. |

For example, select **WordPress user ID (user.id)** and call:

```php
guestlist_integration_register_franchise([
    // Other registration fields required by your Guestlist API.
], ['user_id' => 123]); // Sends bakstagefranchise_id: 123.
```

For a custom payload ID, select **Custom field from registration data**, enter `customer_id` as the key, and provide `customer_id` in the payload. For a custom user ID stored in user meta, select **Custom ID from user meta**, enter the meta key, and provide `user_id` in context; the plugin reads the stored value automatically. The plugin uses the specified user, never the currently logged-in administrator. Direct client calls also accept context: `guestlist_integration_client()->register_franchise($payload, ['user_id' => 123])`.

The selected ID must be a positive integer. Missing IDs, nonexistent users, invalid source settings, and conflicting explicit `bakstagefranchise_id` values fail without sending a request. The mapping removes the local `integration_id` or selected custom payload field and sends its value as `bakstagefranchise_id`. All other registration fields remain unchanged. Existing adapters sending `backstage_id` or a name-derived field must switch to `integration_id`, `bakstagefranchise_id`, or configure that existing field as the custom source.

Status changes still take the resolved integration ID explicitly:

```php
guestlist_integration_change_franchise_status(123, 'disable', 'Subscription cancelled');
```

Integration names accept letters, numbers, underscores, and hyphens and must also be supported by your Guestlist server. Changing the WordPress setting does not create server routes.

## Project adapters

The plugin now provides a stable adapter contract for every WordPress project. Projects map their own users, orders, ACF fields, custom post types, or external events into a normalized Guestlist payload, then call:

```php
guestlist_integration_register_franchise($payload, [
    'project' => 'example-project',
    'source_id' => $local_franchise_id,
]);

guestlist_integration_change_franchise_status($integration_id, 'disable', 'Subscription cancelled');
```

Alternatively, projects can emit the `guestlist_integration_register_franchise` and `guestlist_integration_change_franchise_status` actions directly.

### Adapter hooks

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `guestlist_integration_register_franchise` | `$payload, $context` | Send a normalized franchise-registration payload. |
| `guestlist_integration_change_franchise_status` | `$integration_id, $action, $reason, $context` | Disable or re-enable a Guestlist franchise. |
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

## Automated releases

Pushes to `main` are versioned by `.github/workflows/release.yml`. The workflow updates both the WordPress plugin header and `GUESTLIST_INTEGRATION_VERSION`, creates an annotated `vX.Y.Z` tag, and publishes a GitHub Release with generated notes.

Use [Conventional Commit](https://www.conventionalcommits.org/) prefixes for changes that should release:

| Commit prefix | Version change |
| --- | --- |
| `fix:` or `perf:` | Patch: `0.1.0` → `0.1.1` |
| `feat:` | Minor: `0.1.0` → `0.2.0` |
| `feat!:` or a `BREAKING CHANGE:` footer | Major: `0.1.0` → `1.0.0` |

Commits such as `docs:`, `chore:`, and `test:` do not create a release. The first run creates the baseline tag from the plugin's current version. The repository's GitHub Actions workflow permissions must allow **Read and write permissions** for the `GITHUB_TOKEN`.

## Updates from WordPress

Requires WordPress 5.8 or later and PHP 7.4 or later. Releases now include an installable `guestlist-integration.zip` containing the plugin in a stable `guestlist-integration` folder. The plugin checks the latest published, stable GitHub release through WordPress's native update system. Drafts, prereleases, and releases without this ZIP are ignored.

Install this updater-enabled version once through **Plugins → Add New → Upload Plugin**, using the ZIP from a new GitHub release (confirm replacement if prompted). Installations made before this change cannot discover it automatically. After that, higher released versions appear under **Plugins → Update now** and **Dashboard → Updates**. To install future releases automatically, choose **Enable auto-updates** for this plugin on the Plugins screen. WordPress controls the schedule; publishing a release does not immediately update every site. The plugin must be active to register its update hooks.

Updates use the public GitHub repository and require no GitHub token, `wp-config.php` entry, hosting environment variable, or `.env` file. Release checks and ZIP downloads are unauthenticated. The repository and release ZIP must remain publicly accessible.

Release checks are cached for 15 minutes, or five minutes after a failure. After publishing a release or restoring public access, allow that cache to expire, then use **Dashboard → Updates → Check again**.

Existing releases without the ZIP asset will not be offered. Push this change with a `feat:` commit to `main` to create a new version and ZIP through the release workflow. WordPress settings are stored separately in the database and survive plugin-file updates; keep project-specific adapters outside this plugin directory so an update does not replace them.

## Backstage migration boundary

This project is an isolated foundation. Do not activate it alongside the current theme integration expecting a complete replacement. The existing theme still owns entitlement rules, WooCommerce product flags, account UI, franchise payload mapping, seat sync, and the inbound booking endpoint. Those pieces need to move into an optional Backstage adapter before the theme's `inc/guestlist-sync.php` and `apis/endpoints/guestlist-booking-link.php` are removed.

The current theme uses ACF options (`guestlist_api_url`, `guestlist_api_email`, `guestlist_api_password`, `guestlist_enabled`, `guestlist_default_currency`). Transfer their values to the plugin settings during migration. The optional `booking_webhook_secret` setting is reserved for the inbound webhook adapter; no webhook route is registered by this foundation.
