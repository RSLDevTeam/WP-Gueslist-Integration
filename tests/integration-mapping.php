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
function get_userdata($id) { return 42 === $id ? (object) ['ID' => 42] : false; }
function get_user_meta($id, $key, $single) { return 'customer_id' === $key ? '987' : ''; }
Guestlist_Integration_Settings::register_settings();
check(isset($fields['franchise_id_source'], $fields['franchise_id_key']), 'Source controls registered');
$client = new Guestlist_Integration_Api_Client();
function sent_payload() {
    $requests = $GLOBALS['requests'];
    return json_decode($requests[count($requests) - 1]['args']['body'], true);
}
$client->register_franchise(['integration_id' => 42, 'name' => 'Example']);
check(sent_payload() === ['name' => 'Example', 'bakstagefranchise_id' => 42], 'Fixed API field');
$settings['integration_name'] = 'partner';
$client->register_franchise(['integration_id' => 42]);
check(sent_payload() === ['bakstagefranchise_id' => 42], 'Integration name never changes registration field');
$client->register_franchise(['bakstagefranchise_id' => 42]);
check(sent_payload() === ['bakstagefranchise_id' => 42], 'Explicit API field supported');
$client->register_franchise(['integration_id' => 42, 'bakstagefranchise_id' => '42']);
check(sent_payload() === ['bakstagefranchise_id' => 42], 'Equivalent numeric IDs accepted');
$count = count($requests);
check(is_wp_error($client->register_franchise(['integration_id' => 42, 'bakstagefranchise_id' => 43])), 'Conflicting ID rejected');
foreach ([null, '', 0, -1, [], true, 1.5, 'abc'] as $bad) {
    check(is_wp_error($client->register_franchise(['integration_id' => $bad])), 'Invalid ID rejected');
}
check(count($requests) === $count, 'Invalid IDs never sent');
$settings['franchise_id_source'] = 'user_id';
$client->register_franchise(['name' => 'User'], ['user_id' => 42]);
check(sent_payload() === ['name' => 'User', 'bakstagefranchise_id' => 42], 'User ID loaded from context');
$count = count($requests);
check(is_wp_error($client->register_franchise(['user_id' => 42])), 'Context user required');
check(is_wp_error($client->register_franchise([], ['user_id' => 99])), 'Unknown user rejected');
check(count($requests) === $count, 'Invalid user never sent');
$settings['franchise_id_source'] = 'user_meta';
$settings['franchise_id_key'] = 'customer_id';
$client->register_franchise(['name' => 'Meta'], ['user_id' => 42]);
check(sent_payload() === ['name' => 'Meta', 'bakstagefranchise_id' => '987'], 'Stored user meta fetched');
$settings['franchise_id_key'] = 'missing';
check(is_wp_error($client->register_franchise([], ['user_id' => 42])), 'Missing meta rejected');
$settings['franchise_id_source'] = 'payload_field';
$settings['franchise_id_key'] = 'customer_id';
$client->register_franchise(['customer_id' => 84, 'name' => 'Custom']);
check(sent_payload() === ['name' => 'Custom', 'bakstagefranchise_id' => 84], 'Custom source consumed and mapped');
check(is_wp_error($client->register_franchise(['integration_id' => 42])), 'No fallback from missing configured field');
$settings['franchise_id_source'] = 'invalid';
check(is_wp_error($client->register_franchise(['integration_id' => 42])), 'Invalid stored source rejected');
$settings['franchise_id_source'] = 'integration_id';
$client->change_franchise_status(42, 'disable', 'Cancelled');
check($requests[count($requests) - 1]['url'] === 'https://example.test/api/franchise/partner/42/disable', 'Status uses integration name');
check(sent_payload() === ['reason' => 'Cancelled'], 'Disable reason');
check(is_wp_error($client->change_franchise_status(0, 'disable')), 'Invalid status ID');
$settings['integration_name'] = '../bad';
check(is_wp_error($client->change_franchise_status(42, 'disable')), 'Invalid status route rejected');
$settings['integration_name'] = 'partner';
$valid = Guestlist_Integration_Settings::sanitize(['franchise_id_source' => 'user_meta', 'franchise_id_key' => 'customer_id']);
check($valid['franchise_id_source'] === 'user_meta' && $valid['franchise_id_key'] === 'customer_id', 'Custom settings saved');
foreach ([['franchise_id_source' => 'bad'], ['franchise_id_source' => 'payload_field', 'franchise_id_key' => ''], ['franchise_id_source' => []]] as $input) {
    $result = Guestlist_Integration_Settings::sanitize($input);
    check($result['franchise_id_source'] === 'integration_id', 'Invalid settings retain saved source');
}
foreach (['', '../bad', "partner\n", ['bad']] as $invalid) {
    $result = Guestlist_Integration_Settings::sanitize(['integration_name' => $invalid]);
    check($result['integration_name'] === 'partner', 'Invalid names retain saved value');
}
check(Guestlist_Integration_Settings::sanitize([])['integration_name'] === 'partner', 'Omitted name preserved');
// Verify public adapter forwards owner context even when mapping supplies the whole payload.
function apply_filters($hook, $payload, $context) { return $payload; }
function do_action($hook, ...$args) { $GLOBALS['last_action'] = $hook; }
function guestlist_integration_client() { return $GLOBALS['client']; }
require __DIR__ . '/../includes/class-guestlist-project-adapter.php';
$settings['franchise_id_source'] = 'user_id';
Guestlist_Integration_Project_Adapter::register_franchise([], ['user_id' => 42]);
check(sent_payload() === ['bakstagefranchise_id' => 42], 'Adapter resolves owner ID for empty initial payload');
check($last_action === 'guestlist_integration_request_succeeded', 'Adapter reports success');
Guestlist_Integration_Project_Adapter::register_franchise([], []);
check($last_action === 'guestlist_integration_request_failed', 'Adapter reports missing owner');
echo "Integration mapping checks passed.\n";
