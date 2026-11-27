<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Physical uploads folders and attachment relocation.
 * The module keeps WordPress attachment IDs and rewrites URL references in content,
 * post meta and options while preserving serialized data.
 */
final class UAMM_Physical_Folders {
    public static function boot() {
        add_action('plugins_loaded', array(__CLASS__, 'replace_ajax'), 30);
    }

    public static function replace_ajax() {
        if (class_exists('UA_MediaManager')) {
            remove_action('wp_ajax_uamm_action', array(UA_MediaManager::instance(), 'ajax_action'));
        }
        add_action('wp_ajax_uamm_action', array(__CLASS__, 'ajax_action'));
    }

    public static function ajax_action() {
        check_ajax_referer(UA_MediaManager::NONCE, 'nonce');
        $action = sanitize_key($_POST['do'] ?? '');
        if (!in_array($action, array('create_folder', 'move', 'rename'), true)) {
            UA_MediaManager::instance()->ajax_action();
            return;
        }
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Недостатньо прав.', 'ua-mediamanager')), 403);
        }
        if ($action === 'create_folder') {
            self::create_folder();
        }
        self::move_attachments();
    }

    private static function upload_paths() {
        $u = wp_upload_dir();
        $root = realpath($u['basedir']);
        if (!$root || !is_dir($root)) {
            wp_send_json_error(array('message' => __('Не вдалося визначити папку uploads.', 'ua-mediamanager')), 500);
        }
        return array($u, wp_normalize_path($root));
    }

    private static function safe_relative($path) {
        $path = trim(str_replace('\\', '/', (string)$path), '/');
        if ($path === '' || strpos($path, '..') !== false || strpos($path, ':') !== false || strpos($path, "\0") !== false) {
            return false;
        }
        $parts = array_filter(explode('/', $path), 'strlen');
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..' || !preg_match('/^[\pL\pN _.-]+$/u', $part)) return false;
        }
        return implode('/', $parts);
    }

    private static function folder_path($term_id) {
        list($u, $root) = self::upload_paths();
        $relative = get_term_meta($term_id, 'uamm_physical_path', true);
        if (!$relative) {
            $term = get_term($term_id, UA_MediaManager::TAX);
            if (!$term || is_wp_error($term)) return false;
            $relative = 'uamm/' . sanitize_title($term->slug);
            update_term_meta($term_id, 'uamm_physical_path', $relative);
        }
        $relative = self::safe_relative($relative);
        if (!$relative) return false;
        $absolute = $root . '/' . $relative;
        if (!wp_mkdir_p($absolute)) return false;
        $real = realpath($absolute);
        if (!$real || strpos(wp_normalize_path($real) . '/', $root . '/') !== 0) return false;
        return array($relative, wp_normalize_path($real));
    }

    private static function create_folder() {
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $parent = absint($_POST['parent'] ?? 0);
        if ($name === '') wp_send_json_error(array('message' => __('Вкажіть назву папки.', 'ua-mediamanager')));
        $parent_path = 'uamm';
        if ($parent) {
            if (!term_exists($parent, UA_MediaManager::TAX)) wp_send_json_error(array('message' => __('Батьківську папку не знайдено.', 'ua-mediamanager')));
            $p = self::folder_path($parent);
            if (!$p) wp_send_json_error(array('message' => __('Не вдалося відкрити батьківську папку.', 'ua-mediamanager')));
            $parent_path = $p[0];
        }
        $slug = sanitize_title($name);
        if (!$slug) wp_send_json_error(array('message' => __('Некоректна назва папки.', 'ua-mediamanager')));
        $term = wp_insert_term($name, UA_MediaManager::TAX, array('parent' => $parent));
        if (is_wp_error($term)) wp_send_json_error(array('message' => $term->get_error_message()));
        $relative = self::safe_relative($parent_path . '/' . $slug);
        list($u, $root) = self::upload_paths();
        $absolute = $relative ? $root . '/' . $relative : '';
        if (!$relative || !wp_mkdir_p($absolute)) {
            wp_delete_term((int)$term['term_id'], UA_MediaManager::TAX);
            wp_send_json_error(array('message' => __('Не вдалося створити фізичну папку в uploads.', 'ua-mediamanager')));
        }
        update_term_meta((int)$term['term_id'], 'uamm_physical_path', $relative);
        wp_send_json_success(array('message' => __('Фізичну папку створено.', 'ua-mediamanager')));
    }

    private static function replace_nested($value, $map) {
        if (is_string($value)) return strtr($value, $map);
        if (is_array($value)) {
            foreach ($value as $key => $item) $value[$key] = self::replace_nested($item, $map);
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $key => $item) $value->$key = self::replace_nested($item, $map);
        }
        return $value;
    }

    private static function rewrite_references($map) {
        global $wpdb;
        foreach ($map as $old => $new) {
            if ($old === $new || $old === '') continue;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $old, $new, '%' . $wpdb->esc_like($old) . '%'
            ));
        }
        $tables = array($wpdb->postmeta, $wpdb->options);
        foreach ($tables as $table) {
            foreach ($map as $old => $new) {
                if ($old === $new || $old === '') continue;
                $rows = $wpdb->get_results($wpdb->prepare("SELECT " . ($table === $wpdb->postmeta ? 'meta_id AS row_id, meta_value AS row_value' : 'option_id AS row_id, option_value AS row_value') . " FROM {$table} WHERE " . ($table === $wpdb->postmeta ? 'meta_value' : 'option_value') . " LIKE %s", '%' . $wpdb->esc_like($old) . '%'));
                foreach ($rows as $row) {
                    $raw = $row->row_value;
                    $value = maybe_unserialize($raw);
                    $changed = self::replace_nested($value, array($old => $new));
                    if ($changed !== $value) {
                        $stored = is_array($changed) || is_object($changed) ? maybe_serialize($changed) : $changed;
                        if ($table === $wpdb->postmeta) {
                            $wpdb->update($wpdb->postmeta, array('meta_value' => $stored), array('meta_id' => (int)$row->row_id));
                        } else {
                            $wpdb->update($wpdb->options, array('option_value' => $stored), array('option_id' => (int)$row->row_id));
                        }
                    }
                }
            }
        }
    }


    private static function rename_attachment() {
        $id = absint($_POST['id'] ?? 0);
        $new_name = sanitize_file_name(wp_unslash($_POST['name'] ?? ''));
        if (!$id || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
            wp_send_json_error(array('message' => __('Немає доступу до цього файлу.', 'ua-mediamanager')));
        }
        $source = get_attached_file($id);
        $meta = wp_get_attachment_metadata($id);
        if (!$source || !is_file($source) || !is_array($meta)) {
            wp_send_json_error(array('message' => __('Файл або метадані не знайдено.', 'ua-mediamanager')));
        }
        $real_source = realpath($source);
        list($u, $root) = self::upload_paths();
        if (!$real_source || strpos(wp_normalize_path($real_source), $root . '/') !== 0) {
            wp_send_json_error(array('message' => __('Дозволено перейменовувати лише файли всередині uploads.', 'ua-mediamanager')));
        }
        if ($new_name === '' || strpos($new_name, '/') !== false || strpos($new_name, '\\\\') !== false) {
            wp_send_json_error(array('message' => __('Вкажіть коректну нову назву файлу.', 'ua-mediamanager')));
        }
        $old_base = basename($source);
        $old_ext = pathinfo($old_base, PATHINFO_EXTENSION);
        $new_ext = pathinfo($new_name, PATHINFO_EXTENSION);
        if (!$new_ext && $old_ext) $new_name .= '.' . $old_ext;
        elseif (strtolower($new_ext) !== strtolower($old_ext)) {
            wp_send_json_error(array('message' => __('Зміна розширення файлу заборонена.', 'ua-mediamanager')));
        }
        if ($new_name === $old_base) wp_send_json_error(array('message' => __('Назва не змінилася.', 'ua-mediamanager')));
        $old_stem = pathinfo($old_base, PATHINFO_FILENAME);
        $new_stem = pathinfo($new_name, PATHINFO_FILENAME);
        $dir = dirname($source);
        $files = array(array('key' => 'main', 'old' => $old_base, 'new' => $new_name));
        foreach ($meta['sizes'] ?? array() as $key => $size) {
            if (empty($size['file'])) continue;
            $old = basename($size['file']);
            $new = preg_replace('/^' . preg_quote($old_stem, '/') . '(?=[.-])/', $new_stem, $old);
            if ($new === $old && $old_stem !== '') $new = $new_stem . '-' . $old;
            $files[] = array('key' => 'size:' . $key, 'old' => $old, 'new' => $new);
        }
        foreach ($meta['backup_sizes'] ?? array() as $key => $size) {
            if (empty($size['file'])) continue;
            $old = basename($size['file']);
            $new = preg_replace('/^' . preg_quote($old_stem, '/') . '(?=[.-])/', $new_stem, $old);
            if ($new === $old && $old_stem !== '') $new = $new_stem . '-' . $old;
            $files[] = array('key' => 'backup:' . $key, 'old' => $old, 'new' => $new);
        }
        if (!empty($meta['original_image'])) {
            $old = basename($meta['original_image']);
            $new = preg_replace('/^' . preg_quote($old_stem, '/') . '(?=[.-])/', $new_stem, $old);
            if ($new === $old) $new = $new_stem . '-' . $old;
            $files[] = array('key' => 'original_image', 'old' => $old, 'new' => $new);
        }
        foreach ($files as $file) {
            if ($file['old'] !== $file['new'] && file_exists($dir . '/' . $file['new'])) {
                wp_send_json_error(array('message' => __('Файл із такою назвою вже існує. Нічого не змінено.', 'ua-mediamanager')));
            }
        }
        $moves = array();
        foreach ($files as $file) {
            $from = $dir . '/' . $file['old'];
            $to = $dir . '/' . $file['new'];
            if (!is_file($from) || $from === $to) continue;
            if (!@rename($from, $to)) {
                foreach (array_reverse($moves) as $move) @rename($move[1], $move[0]);
                wp_send_json_error(array('message' => __('Не вдалося перейменувати всі файли. Зміни скасовано.', 'ua-mediamanager')));
            }
            $moves[] = array($from, $to, $file['key']);
        }
        foreach ($moves as $move) {
            $key = $move[2];
            if (strpos($key, 'size:') === 0) $meta['sizes'][substr($key, 5)]['file'] = basename($move[1]);
            elseif (strpos($key, 'backup:') === 0) $meta['backup_sizes'][substr($key, 7)]['file'] = basename($move[1]);
            elseif ($key === 'original_image') $meta['original_image'] = basename($move[1]);
        }
        $relative = ltrim(str_replace(wp_normalize_path($root), '', wp_normalize_path($source)), '/');
        $new_relative = (dirname($relative) === '.' ? '' : dirname($relative) . '/') . $new_name;
        $meta['file'] = $new_relative;
        $url_base = trailingslashit($u['baseurl']);
        $map = array();
        foreach ($files as $file) {
            $map[$url_base . (dirname($relative) === '.' ? '' : dirname($relative) . '/') . $file['old']] =
                $url_base . (dirname($new_relative) === '.' ? '' : dirname($new_relative) . '/') . $file['new'];
        }
        update_post_meta($id, '_wp_attached_file', $new_relative);
        wp_update_attachment_metadata($id, $meta);
        self::rewrite_references($map);
        wp_send_json_success(array('message' => __('Файл та його відомі розміри перейменовано. Посилання оновлено.', 'ua-mediamanager')));
    }

    private static function move_attachments() {
        $folder = absint($_POST['folder'] ?? 0);
        if (!$folder || !term_exists($folder, UA_MediaManager::TAX)) {
            wp_send_json_error(array('message' => __('Оберіть папку призначення.', 'ua-mediamanager')));
        }
        $destination = self::folder_path($folder);
        if (!$destination) wp_send_json_error(array('message' => __('Не вдалося відкрити папку призначення.', 'ua-mediamanager')));
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['ids'] ?? array())))));
        if (!$ids) wp_send_json_error(array('message' => __('Не вибрано файли.', 'ua-mediamanager')));
        list($u, $root) = self::upload_paths();
        $done = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) { $failed++; continue; }
            $source = get_attached_file($id);
            $meta = wp_get_attachment_metadata($id);
            if (!$source || !is_file($source) || !is_array($meta)) { $failed++; continue; }
            $real_source = realpath($source);
            if (!$real_source || strpos(wp_normalize_path($real_source), $root . '/') !== 0) { $failed++; continue; }
            $source_dir = dirname($source);
            $dest_dir = $destination[1];
            $files = array();
            $files[] = array('key' => 'main', 'name' => basename($source));
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size_key => $size) {
                    if (!empty($size['file'])) $files[] = array('key' => 'size:' . $size_key, 'name' => basename($size['file']));
                }
            }
            if (!empty($meta['original_image'])) $files[] = array('key' => 'original_image', 'name' => basename($meta['original_image']));
            if (!empty($meta['backup_sizes']) && is_array($meta['backup_sizes'])) {
                foreach ($meta['backup_sizes'] as $backup_key => $backup) {
                    if (!empty($backup['file'])) $files[] = array('key' => 'backup:' . $backup_key, 'name' => basename($backup['file']));
                }
            }
            $moves = array();
            $failed_file = false;
            foreach ($files as $file) {
                $from = $file['key'] === 'main' ? $source : $source_dir . '/' . $file['name'];
                if (!is_file($from)) continue;
                $to = $dest_dir . '/' . $file['name'];
                if (file_exists($to) || !@rename($from, $to)) { $failed_file = true; break; }
                $moves[] = array($from, $to, $file['key']);
            }
            if ($failed_file) {
                foreach (array_reverse($moves) as $move) @rename($move[1], $move[0]);
                $failed++;
                continue;
            }
            $old_relative = ltrim(str_replace(wp_normalize_path($root), '', wp_normalize_path($source)), '/');
            $new_relative = $destination[0] . '/' . basename($source);
            $meta['file'] = $new_relative;
            foreach ($moves as $move) {
                $key = $move[2];
                if (strpos($key, 'size:') === 0) {
                    $size_key = substr($key, 5);
                    $meta['sizes'][$size_key]['file'] = basename($move[1]);
                } elseif (strpos($key, 'backup:') === 0) {
                    $backup_key = substr($key, 7);
                    $meta['backup_sizes'][$backup_key]['file'] = basename($move[1]);
                }
            }
            $old_url_base = trailingslashit($u['baseurl']);
            $map = array($old_url_base . $old_relative => $old_url_base . $new_relative);
            foreach ($meta['sizes'] ?? array() as $size) {
                if (!empty($size['file'])) $map[$old_url_base . dirname($old_relative) . '/' . $size['file']] = $old_url_base . $destination[0] . '/' . $size['file'];
            }
            if (!empty($meta['original_image'])) {
                $meta['original_image'] = basename($meta['original_image']);
            }
            update_post_meta($id, '_wp_attached_file', $new_relative);
            wp_update_attachment_metadata($id, $meta);
            self::rewrite_references($map);
            wp_set_object_terms($id, $folder, UA_MediaManager::TAX, false);
            $done++;
        }
        wp_send_json_success(array('message' => sprintf(__('Фізично переміщено: %1$d. Не вдалося перемістити: %2$d.', 'ua-mediamanager'), $done, $failed)));
    }
}
UAMM_Physical_Folders::boot();
