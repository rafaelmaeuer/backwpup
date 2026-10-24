<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin updates from GitHub releases.
 *
 * The plugin header "Update URI" points to the GitHub repository, so WordPress does not
 * look for updates on wordpress.org and asks the `update_plugins_github.com` filter instead.
 * A release is offered when its tag (e.g. v4.7.9) is newer than the installed version and it
 * has a zip asset named backwpup*.zip containing the plugin in a top level "backwpup" folder.
 */
class BackWPup_GitHub_Updater
{
    private const CACHE_KEY = 'backwpup_github_release';

    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    private const ERROR_CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * @var string plugin basename, e.g. backwpup/backwpup.php
     */
    private $plugin_file;

    /**
     * @var string plugin slug, e.g. backwpup
     */
    private $slug;

    /**
     * @param string $plugin_main_file absolute path of the plugin main file
     */
    public function __construct(string $plugin_main_file)
    {
        $this->plugin_file = plugin_basename($plugin_main_file);
        $this->slug = dirname($this->plugin_file);
    }

    public function init(): void
    {
        add_filter('update_plugins_github.com', [$this, 'check_update'], 10, 3);
        add_filter('plugins_api', [$this, 'plugin_information'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 0);
    }

    /**
     * Provides the update information for WordPress' plugin update check.
     *
     * @param array|false $update      the update data, false if none yet
     * @param array       $plugin_data plugin headers
     * @param string      $plugin_file plugin basename
     *
     * @return array|false
     */
    public function check_update($update, $plugin_data, $plugin_file)
    {
        if ($plugin_file !== $this->plugin_file) {
            return $update;
        }

        $release = $this->get_release($plugin_data['UpdateURI'] ?? '');
        if (!$release) {
            return $update;
        }

        return [
            'id' => $plugin_data['UpdateURI'],
            'slug' => $this->slug,
            'plugin' => $this->plugin_file,
            'version' => $release['version'],
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
            'requires' => $plugin_data['RequiresWP'] ?? '',
        ];
    }

    /**
     * Provides the data for the "View details" dialog.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     *
     * @return false|object|array
     */
    public function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file, false, false);
        $release = $this->get_release($plugin_data['UpdateURI'] ?? '');
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => $plugin_data['Name'],
            'slug' => $this->slug,
            'version' => $release['version'],
            'author' => $plugin_data['Author'],
            'homepage' => $release['url'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
            'requires' => $plugin_data['RequiresWP'] ?? '',
            'last_updated' => $release['published'],
            'download_link' => $release['package'],
            'sections' => [
                'changelog' => $release['notes'] !== ''
                    ? $release['notes']
                    : '<p>' . esc_html__('See the release on GitHub for details.', 'backwpup') . '</p>',
            ],
        ];
    }

    public function clear_cache(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * Fetches the latest published release from GitHub (cached).
     *
     * @param string $update_uri the "Update URI" plugin header, e.g. https://github.com/owner/repo
     *
     * @return array|null version, package, url, notes, published
     */
    private function get_release(string $update_uri): ?array
    {
        $repository = $this->get_repository($update_uri);
        if ($repository === '') {
            return null;
        }

        // "Check again" on the updates screen bypasses the cache.
        $force = isset($_GET['force-check']) && current_user_can('update_plugins');
        $cached = $force ? false : get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && ($cached['repository'] ?? '') === $repository) {
            return $cached['release'] ?: null;
        }

        $release = $this->fetch_release($repository);
        set_site_transient(
            self::CACHE_KEY,
            ['repository' => $repository, 'release' => $release ?: []],
            $release ? self::CACHE_TTL : self::ERROR_CACHE_TTL
        );

        return $release;
    }

    private function fetch_release(string $repository): ?array
    {
        $response = wp_remote_get(
            'https://api.github.com/repos/' . $repository . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.html+json',
                    'User-Agent' => 'BackWPup/' . BackWPup::get_plugin_data('Version') . '; ' . home_url(),
                ],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }

        $version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+(\.\d+)*$/', $version)) {
            return null;
        }

        $package = '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if (preg_match('/^backwpup.*\.zip$/i', $name) && strpos($url, 'https://github.com/') === 0) {
                $package = $url;
                break;
            }
        }
        if ($package === '') {
            return null;
        }

        return [
            'version' => $version,
            'package' => $package,
            'url' => esc_url_raw((string) ($data['html_url'] ?? 'https://github.com/' . $repository)),
            'notes' => wp_kses_post((string) ($data['body_html'] ?? '')),
            'published' => (string) ($data['published_at'] ?? ''),
        ];
    }

    /**
     * Extracts "owner/repo" from a GitHub Update URI.
     */
    private function get_repository(string $update_uri): string
    {
        $repository = '';
        if (preg_match('~^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$~', $update_uri, $matches)) {
            $repository = $matches[1];
        }

        /**
         * Filters the GitHub repository ("owner/repo") used for plugin updates.
         *
         * @param string $repository
         */
        return (string) apply_filters('backwpup_github_update_repository', $repository);
    }
}
