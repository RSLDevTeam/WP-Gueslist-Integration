<?php

defined('ABSPATH') || exit;

final class Guestlist_Integration_Settings {
    const OPTION = 'guestlist_integration_settings';

    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function all(): array {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return wp_parse_args($saved, [
            'enabled' => 'no',
            'api_url' => '',
            'api_email' => '',
            'api_password' => '',
            'default_currency' => 'gbp',
            'booking_webhook_secret' => '',
        ]);
    }

    public static function get(string $key, $default = '') {
        $settings = self::all();

        return $settings[$key] ?? $default;
    }

    public static function add_page(): void {
        add_options_page(
            __('Guestlist Integration', 'guestlist-integration'),
            __('Guestlist Integration', 'guestlist-integration'),
            'manage_options',
            'guestlist-integration',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('guestlist_integration', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => [],
        ]);

        add_settings_section('guestlist_connection', __('Connection', 'guestlist-integration'), '__return_false', 'guestlist-integration');

        foreach ([
            'enabled' => __('Enabled', 'guestlist-integration'),
            'api_url' => __('Guestlist API URL', 'guestlist-integration'),
            'api_email' => __('API email', 'guestlist-integration'),
            'api_password' => __('API password', 'guestlist-integration'),
            'default_currency' => __('Default currency', 'guestlist-integration'),
            'booking_webhook_secret' => __('Booking webhook secret', 'guestlist-integration'),
        ] as $key => $label) {
            add_settings_field($key, $label, [__CLASS__, 'render_field'], 'guestlist-integration', 'guestlist_connection', ['key' => $key]);
        }
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $current = self::all();
        $currency = strtolower(sanitize_text_field($input['default_currency'] ?? 'gbp'));
        $url = esc_url_raw(trim((string) ($input['api_url'] ?? '')));

        if ($url && 'https' !== wp_parse_url($url, PHP_URL_SCHEME)) {
            add_settings_error(self::OPTION, 'https_required', __('Use an HTTPS Guestlist API URL.', 'guestlist-integration'));
            $url = $current['api_url'];
        }

        return [
            'enabled' => !empty($input['enabled']) ? 'yes' : 'no',
            'api_url' => untrailingslashit($url),
            'api_email' => sanitize_email($input['api_email'] ?? ''),
            'api_password' => '' === (string) ($input['api_password'] ?? '') ? $current['api_password'] : (string) $input['api_password'],
            'default_currency' => in_array($currency, ['gbp', 'eur', 'usd'], true) ? $currency : 'gbp',
            'booking_webhook_secret' => '' === (string) ($input['booking_webhook_secret'] ?? '') ? $current['booking_webhook_secret'] : sanitize_text_field($input['booking_webhook_secret']),
        ];
    }

    public static function render_field(array $args): void {
        $key = $args['key'];
        $value = self::get($key);
        $name = self::OPTION . '[' . $key . ']';

        if ('enabled' === $key) {
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="yes" ' . checked('yes', $value, false) . '>';
        } elseif ('default_currency' === $key) {
            echo '<select name="' . esc_attr($name) . '">';
            foreach (['gbp', 'eur', 'usd'] as $currency) {
                echo '<option value="' . esc_attr($currency) . '" ' . selected($currency, $value, false) . '>' . esc_html(strtoupper($currency)) . '</option>';
            }
            echo '</select>';
        } else {
            $secret = in_array($key, ['api_password', 'booking_webhook_secret'], true);
            $type = $secret ? 'password' : ('api_email' === $key ? 'email' : 'url');
            echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . ($secret ? '' : esc_attr($value)) . '" autocomplete="off" ' . ($secret ? 'placeholder="' . esc_attr($value ? __('Saved; leave blank to keep', 'guestlist-integration') : '') . '"' : '') . '>';
        }
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>' . esc_html__('Guestlist Integration', 'guestlist-integration') . '</h1>';
        settings_errors(self::OPTION);
        echo '<form action="options.php" method="post">';
        settings_fields('guestlist_integration');
        do_settings_sections('guestlist-integration');
        submit_button();
        echo '</form></div>';
    }
}
