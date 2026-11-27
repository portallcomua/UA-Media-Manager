<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Safe ZIP restore for UA MediaManager.
 * Restores files into uploads without overwriting existing files. Existing trashed
 * attachments whose original paths are restored are automatically untrashed.
 */
final class UAMM_Archive_Restore {
    public static function boot() {
        add_action('plugins_loaded', array(__CLASS__, 'replace_handler'), 20);
        add_action('admin_footer', array(__CLASS__, 'render_restore_form'));
        add_action('admin_notices', array(__CLASS__, 'restore_notice'));
    }

    public static function replace_handler() {
        if (class_exists('UA_MediaManager')) {
            remove_action('admin_post_uamm_restore', array(UA_MediaManager::instance(), 'restore_upload'));
        }
        add_action('admin_post_uamm_restore', array(__CLASS__, 'restore_upload'));
    }


    public static function restore_notice() {
        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== 'ua-mediamanager-quarantine' || !current_user_can('manage_options') || !isset($_GET['uamm_restored'])) {
            return;
        }
        $restored = absint($_GET['uamm_restored']);
        $skipped = absint($_GET['uamm_skipped'] ?? 0);
        $untrashed = absint($_GET['uamm_untrashed'] ?? 0);
        $errors = absint($_GET['uamm_errors'] ?? 0);\n        $imported = absint($_GET['uamm_imported'] ?? 0);
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
            __('Відновлення завершено. Файлів додано: %1$d; пропущено через наявні файли: %2$d; вкладень повернуто з кошика: %3$d; помилок: %4$d.', 'ua-mediamanager'),
            $restored, $skipped, $untrashed, $errors
        )) . '</p></div>';
    }

    public static function render_restore_form() {
        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== 'ua-mediamanager-quarantine' || !current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap uamm-wrap">
            <div class="uamm-restore-panel">
                <h2><?php echo esc_html__('Відновити з ZIP-архіву', 'ua-mediamanager'); ?></h2>
                <p><?php echo esc_html__('Завантажте ZIP, створений UA MediaManager. Файли будуть повернуті до uploads зі збереженням відносної структури. Наявні файли не перезаписуються.', 'ua-mediamanager'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="uamm_restore">
                    <?php wp_nonce_field('uamm_restore_upload', 'uamm_restore_nonce'); ?>
                    <input type="file" name="uamm_archive" accept=".zip,application/zip" required>
                    <button class="button button-primary" type="submit"><?php echo esc_html__('Перевірити та відновити ZIP', 'ua-mediamanager'); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    public static function restore_upload() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостатньо прав.', 'ua-mediamanager'), 403);
        }
        if (!isset($_POST['uamm_restore_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['uamm_restore_nonce'])), 'uamm_restore_upload')) {
            wp_die(esc_html__('Перевірка безпеки не пройдена.', 'ua-mediamanager'), 403);
        }
        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('На сервері не увімкнено PHP ZipArchive.', 'ua-mediamanager'));
        }
        if (empty($_FILES['uamm_archive']['tmp_name']) || !is_uploaded_file($_FILES['uamm_archive']['tmp_name'])) {
            wp_die(esc_html__('ZIP-файл не завантажено.', 'ua-mediamanager'));
        }
        $upload = wp_upload_dir();
        $root = realpath($upload['basedir']);
        if (!$root || !is_dir($root)) {
            wp_die(esc_html__('Не вдалося визначити папку uploads.', 'ua-mediamanager'));
        }
        $zip = new ZipArchive();
        if ($zip->open($_FILES['uamm_archive']['tmp_name']) !== true) {
            wp_die(esc_html__('Архів пошкоджений або не є ZIP-файлом.', 'ua-mediamanager'));
        }
        if ($zip->numFiles > 10000) {
            $zip->close();
            wp_die(esc_html__('В архіві забагато файлів. Ліміт — 10000.', 'ua-mediamanager'));
        }
        $restored = 0;
        $skipped = 0;
        $errors = 0;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || empty($stat['name']) || substr($stat['name'], -1) === '/') {
                continue;
            }
            $name = str_replace('\\', '/', $stat['name']);
            if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name) || preg_match('#(^|/)\.\.(/|$)#', $name) || strpos($name, "\0") !== false) {
                $errors++;
                continue;
            }
            $total += (int) $stat['size'];
            if ($total > 2147483648) {
                $zip->close();
                wp_die(esc_html__('Розмір архіву перевищує безпечний ліміт 2 ГБ.', 'ua-mediamanager'));
            }
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
            $parent = dirname($target);
            if (!wp_mkdir_p($parent)) {
                $errors++;
                continue;
            }
            $real_parent = realpath($parent);
            if (!$real_parent || strpos(wp_normalize_path($real_parent) . '/', wp_normalize_path($root) . '/') !== 0) {
                $errors++;
                continue;
            }
            if (file_exists($target)) {
                $skipped++;
                continue;
            }
            $stream = $zip->getStream($stat['name']);
            if (!$stream) {
                $errors++;
                continue;
            }
            $out = @fopen($target, 'xb');
            if (!$out) {
                fclose($stream);
                $errors++;
                continue;
            }
            $copied = stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            if ($copied === false || (int)$copied !== (int)$stat['size']) {
                @unlink($target);
                $errors++;
                continue;
            }
            $restored++;
            $file_type = wp_check_filetype($target);
            $is_generated_size = preg_match('/-\\d+x\\d+\\.(jpe?g|png|gif|webp)$/i', basename($target));
            if (!empty($file_type['type']) && !$is_generated_size) {
                $relative_path = ltrim(str_replace(wp_normalize_path($root), '', wp_normalize_path($target)), '/');
                $exists = get_posts(array(
                    'post_type' => 'attachment',
                    'post_status' => 'any',
                    'numberposts' => 1,
                    'fields' => 'ids',
                    'meta_key' => '_wp_attached_file',
                    'meta_value' => $relative_path,
                ));
                if (!$exists) {
                    $attachment_id = wp_insert_attachment(array(
                        'post_mime_type' => $file_type['type'],
                        'post_title' => sanitize_text_field(pathinfo(basename($target), PATHINFO_FILENAME)),
                        'post_status' => 'inherit',
                    ), $target);
                    if ($attachment_id && !is_wp_error($attachment_id)) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $attachment_meta = wp_generate_attachment_metadata($attachment_id, $target);
                        if (is_array($attachment_meta)) wp_update_attachment_metadata($attachment_id, $attachment_meta);
                        $imported++;
                    }
                }
            }
        }
        $zip->close();

        // Restore matching WordPress media records from Trash.
        $untrashed = 0;
        $trashed = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'trash',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => '_wp_attached_file',
        ));
        foreach ($trashed as $attachment_id) {
            $relative = get_post_meta($attachment_id, '_wp_attached_file', true);
            if ($relative && is_file(trailingslashit($root) . ltrim($relative, '/'))) {
                if (wp_untrash_post($attachment_id)) {
                    $untrashed++;
                }
            }
        }
        $url = add_query_arg(array(
            'page' => 'ua-mediamanager-quarantine',
            'uamm_restored' => $restored,
            'uamm_skipped' => $skipped,
            'uamm_untrashed' => $untrashed,\n            'uamm_imported' => $imported,
            'uamm_errors' => $errors,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
UAMM_Archive_Restore::boot();
