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

    /** Resolve the selected local ID and send the fixed Guestlist API field. */
    public function register_franchise(array $payload, array $context = []) {
        $field = 'bakstagefranchise_id';
        $source = Guestlist_Integration_Settings::get('franchise_id_source');
        $key = Guestlist_Integration_Settings::get('franchise_id_key');

        if ('integration_id' === $source) {
            $id = $payload['integration_id'] ?? ($payload[$field] ?? null);
        } elseif ('payload_field' === $source && is_string($key) && '' !== $key) {
            $id = $payload[$key] ?? null;
        } elseif (in_array($source, ['user_id', 'user_meta'], true)) {
            // Never infer the franchise owner from the logged-in administrator.
            $user_id = $context['user_id'] ?? null;
            if (!self::valid_id($user_id) || !get_userdata((int) $user_id)) {
                return new WP_Error('guestlist_invalid_user', __('Registration requires a valid user_id in context.', 'guestlist-integration'));
            }
            if ('user_meta' === $source && (!is_string($key) || '' === $key)) {
                return new WP_Error('guestlist_invalid_mapping', __('Enter a user meta key for the franchise ID.', 'guestlist-integration'));
            }
            $id = 'user_id' === $source ? $user_id : get_user_meta((int) $user_id, $key, true);
        } else {
            return new WP_Error('guestlist_invalid_mapping', __('Invalid franchise ID source.', 'guestlist-integration'));
        }

        if (!self::valid_id($id)) {
            return new WP_Error('guestlist_invalid_id', __('The selected franchise ID source must contain a positive integer.', 'guestlist-integration'));
        }
        if (array_key_exists($field, $payload) && (!self::valid_id($payload[$field]) || (string) $payload[$field] !== (string) $id)) {
            return new WP_Error('guestlist_conflicting_id', __('Registration payload contains conflicting franchise IDs.', 'guestlist-integration'));
        }

        unset($payload['integration_id']);
        if ('payload_field' === $source) {
            unset($payload[$key]);
        }
        $payload[$field] = $id;

        return $this->request('POST', '/api/franchise/register', $payload);
    }

    private static function valid_id($id): bool {
        return (is_int($id) || is_string($id))
            && false !== filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    public function change_franchise_status(int $integration_id, string $action, string $reason = '') {
        if ($integration_id <= 0 || !in_array($action, ['disable', 'reenable'], true)) {
            return new WP_Error('guestlist_invalid_action', __('Invalid franchise action.', 'guestlist-integration'));
        }

        $integration = Guestlist_Integration_Settings::get('integration_name');
        if (!is_string($integration) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $integration)) {
            return new WP_Error('guestlist_invalid_mapping', __('Invalid integration name.', 'guestlist-integration'));
        }

        return $this->request('POST', sprintf('/api/franchise/%s/%d/%s', $integration, $integration_id, $action),
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
