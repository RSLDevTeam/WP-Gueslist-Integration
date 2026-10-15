<?php
// Run with: php tests/integration-mapping.php (no WordPress install required).
define('ABSPATH', __DIR__);
$settings = ['enabled' => 'yes', 'api_url' => 'https://example.test', 'api_email' => 'test@example.test', 'api_password' => 'test'];
$requests = [];
$errors = [];
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function __($text, $domain) { return $text; }
function get_option($key, $default) { return $GLOBALS['settings']; }
function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_transient($key) { return 'test-token'; }
function wp_json_encode($value) { return json_encode($value); }
function wp_remote_request($url, $args) {
    $GLOBALS['requests'][] = ['url' => $url, 'args' => $args];
    return ['response' => ['code' => 200]];
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_email($value) { return $value; }
function esc_url_raw($value) { return $value; }
function wp_parse_url($value, $component) { return parse_url($value, $component); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function add_settings_error($option, $code, $message) { $GLOBALS['errors'][] = $code; }
function register_setting($group, $option, $args) {}
function add_settings_section($id, $title, $callback, $page) {}
function add_settings_field($id, $title, $callback, $page, $section, $args) {
    $GLOBALS['fields'][$id] = $title;
}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../includes/class-guestlist-settings.php';
require __DIR__ . '/../includes/class-guestlist-api-client.php';
Guestlist_Integration_Settings::register_settings();
check(($fields['integration_name'] ?? '') === 'Integration name', 'Integration name setting registered');
check(!isset($fields['integration_slug']) && !isset($fields['integration_id_field']), 'Separate mapping fields removed');
$client = new Guestlist_Integration_Api_Client();
$client->change_franchise_status(42, 'disable', 'Cancelled');
check($requests[0]['url'] === 'https://example.test/api/franchise/backstage/42/disable', 'Default status route');
check(json_decode($requests[0]['args']['body'], true) === ['reason' => 'Cancelled'], 'Disable reason');
$client->register_franchise(['backstage_id' => 42, 'name' => 'Existing']);
check(json_decode($requests[1]['args']['body'], true) === ['backstage_id' => 42, 'name' => 'Existing'], 'Legacy payload unchanged');
$client->register_franchise(['integration_id' => 42]);
check(json_decode($requests[2]['args']['body'], true) === ['backstage_id' => 42], 'Default ID mapping');
$settings['integration_name'] = 'partner';
$client->change_franchise_status(42, 'reenable', 'Ignored');
check($requests[3]['url'] === 'https://example.test/api/franchise/partner/42/reenable', 'Custom status route');
check($requests[3]['args']['body'] === null, 'Reenable omits reason');
$client->register_franchise(['integration_id' => 42, 'name' => 'Custom']);
check(json_decode($requests[4]['args']['body'], true) === ['name' => 'Custom', 'partner_id' => 42], 'Custom mapping preserves other fields');
$client->register_franchise(['integration_id' => 42, 'partner_id' => 42]);
check(json_decode($requests[5]['args']['body'], true) === ['partner_id' => 42], 'Matching IDs accepted');
$count = count($requests);
check($client->register_franchise(['integration_id' => 42, 'partner_id' => 43])->code === 'guestlist_conflicting_id', 'Conflicting IDs rejected');
check(is_wp_error($client->change_franchise_status(0, 'disable')), 'Invalid ID rejected');
check(is_wp_error($client->change_franchise_status(42, 'delete')), 'Invalid action rejected');
$settings['integration_name'] = '../bad';
check(is_wp_error($client->change_franchise_status(42, 'disable')), 'Unsafe stored slug rejected');
$settings['integration_name'] = [];
check(is_wp_error($client->register_franchise(['integration_id' => 42])), 'Malformed stored mapping rejected');
check(count($requests) === $count, 'Invalid requests never sent');
$settings['integration_name'] = 'integration';
$client->register_franchise(['integration_id' => 42]);
check(json_decode($requests[$count]['args']['body'], true) === ['integration_id' => 42], 'Identity mapping');
$settings['integration_name'] = 'partner';
$valid = Guestlist_Integration_Settings::sanitize(['integration_name' => 'custom-site']);
check($valid['integration_name'] === 'custom-site', 'Custom name saved');
check(!isset($valid['integration_slug']) && !isset($valid['integration_id_field']), 'Only the name is stored');
foreach (['', '../bad', "partner\n", ['bad']] as $invalid) {
    $result = Guestlist_Integration_Settings::sanitize(['integration_name' => $invalid]);
    check($result['integration_name'] === 'partner', 'Invalid settings preserve saved values');
}
$omitted = Guestlist_Integration_Settings::sanitize([]);
check($omitted['integration_name'] === 'partner', 'Omitted settings preserved');
echo "Integration mapping checks passed.\n";
