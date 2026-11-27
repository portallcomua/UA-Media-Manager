<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Public GitHub Releases updater. Releases must use a version tag (for example v2.1.0).
 * For production, publish a ZIP asset named ua-mediamanager.zip with the plugin folder at its root.
 */
final class UAMM_GitHub_Updater {
    const REPO = 'portallcomua/UA-Media-Manager';
    const CACHE = 'uamm_github_release_v1';

    public static function boot() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array(__CLASS__, 'fix_package_directory'), 10, 4);
    }

    private static function release() {
        $cached = get_site_transient(self::CACHE);
        if (false !== $cached) return $cached;
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
            'timeout' => 12,
            'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'UA-MediaManager-WordPress-Updater'),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_site_transient(self::CACHE, array(), 15 * MINUTE_IN_SECONDS);
            return array();
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) $data = array();
        set_site_transient(self::CACHE, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private static function version_from_tag($tag) {
        return ltrim((string)$tag, "vV ");
    }

    private static function package_url($release) {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === 'ua-mediamanager.zip' && !empty($asset['browser_download_url'])) {
                    return esc_url_raw($asset['browser_download_url']);
                }
            }
        }
        if (!empty($release['zipball_url'])) return esc_url_raw($release['zipball_url']);
        return '';
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        $plugin = plugin_basename(UAMM_FILE);
        $release = self::release();
        if (empty($release['tag_name'])) return $transient;
        $version = self::version_from_tag($release['tag_name']);
        $package = self::package_url($release);
        if (!$package || !version_compare($version, UAMM_VERSION, '>')) return $transient;
        $item = (object) array(
            'slug' => 'ua-mediamanager',
            'plugin' => $plugin,
            'new_version' => $version,
            'url' => 'https://github.com/' . self::REPO,
            'package' => $package,
            'tested' => '',
            'requires' => '6.2',
            'requires_php' => '7.4',
        );
        $transient->response[$plugin] = $item;
        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'ua-mediamanager') return $result;
        $release = self::release();
        if (empty($release['tag_name'])) return $result;
        return (object) array(
            'name' => 'UA MediaManager',
            'slug' => 'ua-mediamanager',
            'version' => self::version_from_tag($release['tag_name']),
            'author' => '<a href="https://github.com/portallcomua">UA Server</a>',
            'homepage' => 'https://github.com/' . self::REPO,
            'download_link' => self::package_url($release),
            'sections' => array(
                'description' => __('Менеджер медіабібліотеки WordPress від UA Server.', 'ua-mediamanager'),
                'changelog' => !empty($release['body']) ? wp_kses_post($release['body']) : __('Дивіться журнал змін GitHub.', 'ua-mediamanager'),
            ),
        );
    }

    public static function fix_package_directory($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(UAMM_FILE)) return $source;
        $desired = trailingslashit($remote_source) . dirname(plugin_basename(UAMM_FILE));
        if (basename(untrailingslashit($source)) === basename($desired)) return $source;
        global $wp_filesystem;
        if (!$wp_filesystem || !$wp_filesystem->move($source, $desired, true)) return new WP_Error('uamm_update_folder', __('Не вдалося підготувати папку плагіна після оновлення.', 'ua-mediamanager'));
        return trailingslashit($desired);
    }
}
UAMM_GitHub_Updater::boot();
