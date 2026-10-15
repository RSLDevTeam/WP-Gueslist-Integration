<?php
/**
 * Plugin Name: Guestlist Integration
 * Description: Configurable WordPress integration with the Guestlist API.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://github.com/RSLDevTeam/WP-Gueslist-Integration
 * Text Domain: guestlist-integration
 */

defined('ABSPATH') || exit;

define('GUESTLIST_INTEGRATION_VERSION', '0.1.0');
define('GUESTLIST_INTEGRATION_PATH', plugin_dir_path(__FILE__));
define('GUESTLIST_INTEGRATION_FILE', __FILE__);

require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-settings.php';
require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-api-client.php';
require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-project-adapter.php';
require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-updater.php';

Guestlist_Integration_Updater::register();

add_action('plugins_loaded', static function () {
    Guestlist_Integration_Settings::register();
    Guestlist_Integration_Project_Adapter::register();
});

/**
 * Return the API client for project adapters.
 *
 * @return Guestlist_Integration_Api_Client
 */
function guestlist_integration_client(): Guestlist_Integration_Api_Client {
    static $client = null;

    if (null === $client) {
        $client = new Guestlist_Integration_Api_Client();
    }

    return $client;
}

/**
 * Register a franchise through the standard project adapter contract.
 *
 * @param array $payload Guestlist franchise-registration payload.
 * @param array $context Optional project-only context.
 */
function guestlist_integration_register_franchise(array $payload, array $context = []): void {
    do_action('guestlist_integration_register_franchise', $payload, $context);
}

/**
 * Update a franchise's status through the standard project adapter contract.
 *
 * @param int    $integration_id Project integration ID used by Guestlist.
 * @param string $action disable or reenable.
 * @param string $reason Optional reason for a disable.
 * @param array  $context Optional project-only context.
 */
function guestlist_integration_change_franchise_status(int $integration_id, string $action, string $reason = '', array $context = []): void {
    do_action('guestlist_integration_change_franchise_status', $integration_id, $action, $reason, $context);
}
