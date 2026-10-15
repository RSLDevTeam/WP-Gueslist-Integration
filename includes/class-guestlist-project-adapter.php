<?php

defined('ABSPATH') || exit;

/**
 * Bridges project-specific events to the Guestlist API client.
 *
 * Projects should emit the documented actions instead of calling endpoint
 * methods directly. This keeps their domain mapping separate from this plugin.
 */
final class Guestlist_Integration_Project_Adapter {
    public static function register(): void {
        add_action('guestlist_integration_register_franchise', [__CLASS__, 'register_franchise'], 10, 2);
        add_action('guestlist_integration_change_franchise_status', [__CLASS__, 'change_franchise_status'], 10, 4);
    }

    /**
     * Register a franchise from a normalized project payload.
     *
     * @param array $payload Guestlist franchise-registration payload.
     * @param array $context Optional project context; never sent to the API.
     */
    public static function register_franchise(array $payload, array $context = []): void {
        $payload = apply_filters('guestlist_integration_franchise_payload', $payload, $context);

        if (empty($payload)) {
            do_action('guestlist_integration_request_failed', 'register_franchise', new WP_Error(
                'guestlist_empty_payload',
                __('Guestlist franchise payload is empty.', 'guestlist-integration')
            ), $context);
            return;
        }

        self::complete('register_franchise', guestlist_integration_client()->register_franchise($payload), $context);
    }

    /**
     * Change a franchise's Guestlist status from a project lifecycle event.
     *
     * @param int    $integration_id Project integration ID used by Guestlist.
     * @param string $action disable or reenable.
     * @param string $reason Optional human-readable reason for a disable.
     * @param array  $context Optional project context; never sent to the API.
     */
    public static function change_franchise_status(int $integration_id, string $action, string $reason = '', array $context = []): void {
        self::complete(
            'change_franchise_status',
            guestlist_integration_client()->change_franchise_status($integration_id, $action, $reason),
            $context
        );
    }

    private static function complete(string $operation, $response, array $context): void {
        if (is_wp_error($response)) {
            do_action('guestlist_integration_request_failed', $operation, $response, $context);
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            do_action('guestlist_integration_request_failed', $operation, new WP_Error(
                'guestlist_api_request_failed',
                sprintf(__('Guestlist API request failed with HTTP %d.', 'guestlist-integration'), $status),
                ['response' => $response]
            ), $context);
            return;
        }

        do_action('guestlist_integration_request_succeeded', $operation, $response, $context);
    }
}
