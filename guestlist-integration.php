<?php
/**
 * Plugin Name: Guestlist Integration
 * Description: Configurable WordPress integration with the Guestlist API.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Text Domain: guestlist-integration
 */

defined('ABSPATH') || exit;

define('GUESTLIST_INTEGRATION_VERSION', '0.1.0');
define('GUESTLIST_INTEGRATION_PATH', plugin_dir_path(__FILE__));

require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-settings.php';
require_once GUESTLIST_INTEGRATION_PATH . 'includes/class-guestlist-api-client.php';

add_action('plugins_loaded', static function () {
    Guestlist_Integration_Settings::register();
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
