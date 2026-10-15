<?php

defined('ABSPATH') || exit;

/** Connect GitHub release assets to the native WordPress updater. */
final class Guestlist_Integration_Updater {
    private const REPOSITORY = 'RSLDevTeam/WP-Gueslist-Integration';
    private const CACHE_KEY = 'guestlist_integration_release_v1';
    private const ASSET = 'guestlist-integration.zip';

    public static function register(): void {
        add_filter('update_plugins_github.com', [self::class, 'check_update'], 10, 4);
        add_filter('upgrader_pre_download', [self::class, 'download_package'], 10, 4);
    }

    private static function headers(string $accept): array {
        return ['Accept' => $accept, 'User-Agent' => 'Guestlist-Integration-WordPress'];
    }

    public static function check_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== plugin_basename(GUESTLIST_INTEGRATION_FILE)) {
            return $update;
        }

        $release = get_site_transient(self::CACHE_KEY);
        if (false === $release) {
            $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
                'headers' => self::headers('application/vnd.github+json'),
                'timeout' => 10,
                'redirection' => 0,
            ]);
            $release = [];
            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($data) && empty($data['draft']) && empty($data['prerelease'])
                    && isset($data['tag_name']) && is_string($data['tag_name'])
                    && preg_match('/^v?(\d+\.\d+\.\d+)$/D', $data['tag_name'], $matches)) {
                    foreach (($data['assets'] ?? []) as $asset) {
                        if (($asset['name'] ?? '') === self::ASSET && isset($asset['id']) && is_numeric($asset['id'])) {
                            $release = ['version' => $matches[1], 'asset_id' => (int) $asset['id']];
                            break;
                        }
                    }
                }
            }
            // Cache failures briefly too, to avoid hitting GitHub on every check.
            set_site_transient(self::CACHE_KEY, $release, empty($release) ? 5 * MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }

        if (empty($release['version']) || empty($release['asset_id'])) {
            return $update;
        }

        return [
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => dirname(plugin_basename(GUESTLIST_INTEGRATION_FILE)),
            'version' => $release['version'],
            'url' => 'https://github.com/' . self::REPOSITORY . '/releases/tag/v' . $release['version'],
            'package' => 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . $release['asset_id'],
            'requires' => '5.8',
            'requires_php' => '7.4',
        ];
    }

    /** Download our public release asset and validate its redirect destination. */
    public static function download_package($reply, string $package, $upgrader, array $hook_extra = []) {
        $prefix = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/';
        if (false !== $reply || !preg_match('#^' . preg_quote($prefix, '#') . '[0-9]+$#D', $package)) {
            return $reply;
        }

        $file = wp_tempnam(self::ASSET);
        if (!$file) {
            return new WP_Error('guestlist_update_tempfile', 'Could not create the Guestlist update temporary file.');
        }
        $response = wp_safe_remote_get($package, [
            'headers' => self::headers('application/octet-stream'),
            'timeout' => 60,
            'redirection' => 0,
            'stream' => true,
            'filename' => $file,
        ]);
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            return $file;
        }
        wp_delete_file($file);
        if (is_wp_error($response)) {
            return $response;
        }
        if (302 === wp_remote_retrieve_response_code($response)) {
            $location = wp_remote_retrieve_header($response, 'location');
            $host = wp_parse_url($location, PHP_URL_HOST);
            if ('https' === wp_parse_url($location, PHP_URL_SCHEME)
                && is_string($host) && preg_match('/(^|\.)githubusercontent\.com$/D', $host)) {
                // download_url fetches the validated public asset URL.
                return download_url($location);
            }
        }
        return new WP_Error('guestlist_update_download', 'Could not download the Guestlist release. Check that the repository and release ZIP are publicly accessible.');
    }
}
