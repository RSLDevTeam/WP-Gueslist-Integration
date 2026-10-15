<?php
// Run with: php tests/updater.php (no WordPress install required).
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('GUESTLIST_INTEGRATION_FILE', '/plugins/guestlist-integration/guestlist-integration.php');
define('GUESTLIST_INTEGRATION_GITHUB_TOKEN', 'test-token');
$cache = false;
$requests = [];
$hooks = [];
$deleted = [];
$downloaded = [];
class WP_Error {}
function add_filter($hook, $callback, $priority, $arguments) { $GLOBALS['hooks'][$hook] = $arguments; }
function plugin_basename($file) { return 'guestlist-integration/guestlist-integration.php'; }
function get_site_transient($key) { return $GLOBALS['cache']; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'] = $value; $GLOBALS['ttl'] = $ttl; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_get($url, $args) { $GLOBALS['requests'][] = [$url, $args]; return $GLOBALS['response']; }
function wp_safe_remote_get($url, $args) { return wp_remote_get($url, $args); }
function wp_remote_retrieve_response_code($value) { return $value['status']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_remote_retrieve_header($value, $key) { return $value['headers'][$key] ?? ''; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function wp_tempnam($name) { return '/tmp/guestlist-test.zip'; }
function wp_delete_file($file) { $GLOBALS['deleted'][] = $file; }
function download_url($url) { $GLOBALS['downloaded'][] = $url; return '/tmp/downloaded.zip'; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require __DIR__ . '/../includes/class-guestlist-updater.php';
Guestlist_Integration_Updater::register();
check($hooks === ['update_plugins_github.com' => 4, 'upgrader_pre_download' => 4], 'Native hooks registered');
$asset = ['name' => 'guestlist-integration.zip', 'id' => 123];
$stable = ['tag_name' => 'v1.2.3', 'draft' => false, 'prerelease' => false, 'assets' => [$asset]];
$response = ['status' => 200, 'body' => json_encode($stable)];
$plugin = 'guestlist-integration/guestlist-integration.php';
check(Guestlist_Integration_Updater::check_update('other', [], 'other/plugin.php', []) === 'other', 'Other plugins untouched');
check(count($requests) === 0, 'No request for other plugins');
$offer = Guestlist_Integration_Updater::check_update(false, [], $plugin, []);
check($offer['version'] === '1.2.3', 'Stable version offered to core for comparison');
check($offer['package'] === 'https://api.github.com/repos/RSLDevTeam/WP-Gueslist-Integration/releases/assets/123', 'Exact release asset URL');
check(strpos(json_encode($offer), 'test-token') === false, 'Credentials absent from update metadata');
check($requests[0][1]['headers']['Authorization'] === 'Bearer test-token', 'API authenticated');
check($requests[0][1]['redirection'] === 0, 'Authenticated redirects disabled');
Guestlist_Integration_Updater::check_update(false, [], $plugin, []);
check(count($requests) === 1 && $ttl === 900, 'Successful check cached');
foreach ([array_merge($stable, ['draft' => true]), array_merge($stable, ['prerelease' => true]), array_merge($stable, ['tag_name' => 'v1.2.4-beta']), array_merge($stable, ['assets' => []]), array_merge($stable, ['tag_name' => []])] as $invalid) {
    $cache = false;
    $response = ['status' => 200, 'body' => json_encode($invalid)];
    check(Guestlist_Integration_Updater::check_update(false, [], $plugin, []) === false, 'Invalid or unpublished package ignored');
    check($ttl === 300, 'Invalid release cached briefly');
}
foreach ([new WP_Error(), ['status' => 403, 'body' => ''], ['status' => 200, 'body' => 'invalid JSON']] as $failure) {
    $cache = false;
    $response = $failure;
    check(Guestlist_Integration_Updater::check_update(false, [], $plugin, []) === false, 'API failure handled');
}
$package = $offer['package'];
check(Guestlist_Integration_Updater::download_package('existing', $package, null) === 'existing', 'Existing download result preserved');
check(Guestlist_Integration_Updater::download_package(false, 'https://example.test/file.zip', null) === false, 'Other downloads untouched');
$response = ['status' => 200];
check(Guestlist_Integration_Updater::download_package(false, $package, null) === '/tmp/guestlist-test.zip', 'Direct download supported');
$response = ['status' => 302, 'headers' => ['location' => 'https://release-assets.githubusercontent.com/asset?signature=example']];
check(Guestlist_Integration_Updater::download_package(false, $package, null) === '/tmp/downloaded.zip', 'Signed asset redirect supported');
check(count($downloaded) === 1 && count($deleted) === 1, 'Redirect uses unauthenticated download and cleans temporary file');
foreach (['https://evil.test/asset', 'https://githubusercontent.com.evil.test/asset', 'http://release-assets.githubusercontent.com/asset'] as $unsafe) {
    $response = ['status' => 302, 'headers' => ['location' => $unsafe]];
    check(is_wp_error(Guestlist_Integration_Updater::download_package(false, $package, null)), 'Unsafe redirect rejected');
}
check(count($downloaded) === 1, 'Unsafe redirect never downloaded');
$response = ['status' => 404];
check(is_wp_error(Guestlist_Integration_Updater::download_package(false, $package, null)), 'Unavailable asset reported');
echo "Updater checks passed.\n";
