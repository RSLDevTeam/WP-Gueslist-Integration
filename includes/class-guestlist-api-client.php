<?php

defined('ABSPATH') || exit;

final class Guestlist_Integration_Api_Client {
    private const TOKEN_TRANSIENT = 'guestlist_integration_api_token';

    public function is_configured(): bool {
        return 'yes' === Guestlist_Integration_Settings::get('enabled')
            && (bool) Guestlist_Integration_Settings::get('api_url')
            && (bool) Guestlist_Integration_Settings::get('api_email')
            && (bool) Guestlist_Integration_Settings::get('api_password');
    }

    public function request(string $method, string $path, array $payload = [], bool $retry = true) {
        if (!$this->is_configured()) {
            return new WP_Error('guestlist_not_configured', __('Guestlist connection is not configured.', 'guestlist-integration'));
        }

        if (!preg_match('#^/api/[a-zA-Z0-9/_-]+$#', $path)) {
            return new WP_Error('guestlist_invalid_path', __('Invalid Guestlist API path.', 'guestlist-integration'));
        }

        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }

        $response = wp_remote_request(Guestlist_Integration_Settings::get('api_url') . $path, [
            'method' => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $payload ? wp_json_encode($payload) : null,
        ]);

        if ($retry && !is_wp_error($response) && 401 === (int) wp_remote_retrieve_response_code($response)) {
            delete_transient(self::TOKEN_TRANSIENT);

            return $this->request($method, $path, $payload, false);
        }

        return $response;
    }

    public function register_franchise(array $payload) {
        return $this->request('POST', '/api/franchise/register', $payload);
    }

    public function change_franchise_status(int $backstage_id, string $action, string $reason = '') {
        if ($backstage_id <= 0 || !in_array($action, ['disable', 'reenable'], true)) {
            return new WP_Error('guestlist_invalid_action', __('Invalid franchise action.', 'guestlist-integration'));
        }

        return $this->request('POST', sprintf('/api/franchise/backstage/%d/%s', $backstage_id, $action),
            'disable' === $action && $reason ? ['reason' => sanitize_text_field($reason)] : []);
    }

    private function token() {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if ($cached) {
            return $cached;
        }

        $response = wp_remote_post(Guestlist_Integration_Settings::get('api_url') . '/api/login', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode([
                'email' => Guestlist_Integration_Settings::get('api_email'),
                'password' => Guestlist_Integration_Settings::get('api_password'),
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || !is_array($body) || empty($body['access_token'])) {
            return new WP_Error('guestlist_auth_failed', __('Guestlist authentication failed.', 'guestlist-integration'));
        }

        $token = sanitize_text_field($body['access_token']);
        set_transient(self::TOKEN_TRANSIENT, $token, max(60, absint($body['expires_in'] ?? HOUR_IN_SECONDS) - 60));

        return $token;
    }
}
