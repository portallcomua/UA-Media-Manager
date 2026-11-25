<?php
/**
 * Plugin Name: UA MediaManager
 * Plugin URI: https://github.com/portallcomua/UA-Media-Manager
 * Description: Зручний менеджер медіабібліотеки WordPress: папки, резервні копії, карантин, пошук невикористовуваних файлів та безпечне керування зображеннями.
 * Version: 2.0.0
 * Author: UA Server
 * Text Domain: ua-mediamanager
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
define('UAMM_VERSION', '2.0.0');
define('UAMM_FILE', __FILE__);
define('UAMM_DIR', plugin_dir_path(__FILE__));
define('UAMM_URL', plugin_dir_url(__FILE__));

final class UA_MediaManager {
    const TAX = 'uamm_folder';
    const NONCE = 'uamm_admin_nonce';
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_taxonomy'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_uamm_backup', array($this, 'download_backup'));
        add_action('admin_post_uamm_quarantine_zip', array($this, 'download_quarantine'));
        add_action('admin_post_uamm_restore', array($this, 'restore_upload'));
        add_action('wp_ajax_uamm_action', array($this, 'ajax_action'));
        add_filter('plugin_action_links_' . plugin_basename(UAMM_FILE), array($this, 'plugin_links'));
    }

    public function register_taxonomy() {
        register_taxonomy(self::TAX, array('attachment'), array(
            'labels' => array('name' => __('Папки медіа', 'ua-mediamanager'), 'singular_name' => __('Папка', 'ua-mediamanager')),
            'public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'hierarchical' => true, 'rewrite' => false, 'query_var' => false,
        ));
    }

    public function admin_menu() {
        add_menu_page('UA MediaManager', 'UA MediaManager', 'upload_files', 'ua-mediamanager', array($this, 'render_dashboard'), 'dashicons-images-alt2',  ಜೊತೆ 26);
        add_submenu_page('ua-mediamanager', __('Медіабібліотека', 'ua-mediamanager'), __('Медіабібліотека', 'ua-mediamanager'), 'upload_files', 'ua-mediamanager', array($this, 'render_dashboard'));
        add_submenu_page('ua-mediamanager', __('Карантин', 'ua-mediamanager'), __('Карантин', 'ua-mediamanager'), 'delete_posts', 'ua-mediamanager-quarantine', array($this, 'render_quarantine'));
        add_submenu_page('ua-mediamanager', __('Налаштування', 'ua-mediamanager'), __('Налаштування', 'ua-mediamanager'), 'manage_options', 'ua-mediamanager-settings', array($this, 'render_settings'));
    }

    public function assets($hook) {
        if (strpos((string)$hook, 'ua-mediamanager') === false) return;
        wp_enqueue_style('uamm-admin', UAMM_URL . 'assets/admin.css', array(), UAMM_VERSION);
        wp_enqueue_script('uamm-admin', UAMM_URL . 'assets/admin.js', array('jquery'), UAMM_VERSION, true);
        wp_localize_script('uamm-admin', 'UAMM', array(
            'ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(self::NONCE),
            'confirmDelete' => __('Остаточно видалити вибрані файли? Цю дію неможливо скасувати.', 'ua-mediamanager'),
            'working' => __('Обробка…', 'ua-mediamanager'),
        ));
    }

    public function plugin_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=ua-mediamanager')) . '">' . esc_html__('Відкрити', 'ua-mediamanager') . '</a>');
        return $links;
    }

    private function require_capability($cap = 'upload_files') {
        if (!current_user_can($cap)) wp_die(esc_html__('Недостатньо прав.', 'ua-mediamanager'));
    }

    private function bytes($bytes) {
        return size_format(max(0, (float)$bytes), 2);
    }

    private function media_stats() {
        global $wpdb;
        $count = (int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status <> 'trash'");
        $base = wp_upload_dir();
        $size = 0;
        if (!empty($base['basedir']) && is_dir($base['basedir'])) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base['basedir'], FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) if ($file->isFile()) $size += $file->getSize();
            } catch (Exception $e) {}
        }
        return array('count' => $count, 'bytes' => $size);
    }

    public function render_dashboard() {
        $this->require_capability();
        $stats = $this->media_stats();
        $folders = get_terms(array('taxonomy'=>self::TAX, 'hide_empty'=>false));
        if (is_wp_error($folders)) $folders = array();
        $sort = isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'date';
        $dir = isset($_GET['dir']) && 'asc' === $_GET['dir'] ? 'ASC' : 'DESC';
        $allowed = array('date'=>'date', 'name'=>'title', 'type'=>'post_mime_type');
        if (!isset($allowed[$sort])) $sort = 'date';
        $args = array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>60,'paged'=>max(1, absint($_GET['paged'] ?? 1)),'orderby'=>$allowed[$sort],'order'=>$dir);
        if (!empty($_GET['folder'])) $args['tax_query'] = array(array('taxonomy'=>self::TAX,'field'=>'term_id','terms'=>absint($_GET['folder'])));
        if (!empty($_GET['s'])) $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
        $items = new WP_Query($args);
        ?>
        <div class="wrap uamm-wrap">
          <header class="uamm-header"><div><span class="uamm-kicker">UA SERVER · MEDIA TOOLS</span><h1>UA MediaManager <small>2.0.0</small></h1><p><?php esc_html_e('Керуйте файлами, папками та резервними копіями в одному місці.', 'ua-mediamanager'); ?></p></div>
          <div class="uamm-header-actions"><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uamm_backup'),'uamm_backup')); ?>">↓ <?php esc_html_e('Завантажити всю бібліотеку', 'ua-mediamanager'); ?></a><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ua-mediamanager-settings')); ?>"><?php esc_html_e('Налаштування', 'ua-mediamanager'); ?></a></div></header>
          <section class="uamm-stats">
            <article><span><?php esc_html_e('Файлів у медіатеці', 'ua-mediamanager'); ?></span><strong><?php echo esc_html(number_format_i18n($stats['count'])); ?></strong></article>
            <article><span><?php esc_html_e('Обсяг папки uploads', 'ua-mediamanager'); ?></span><strong><?php echo esc_html($this->bytes($stats['bytes'])); ?></strong></article>
            <article><span><?php esc_html_e('Папок менеджера', 'ua-mediamanager'); ?></span><strong><?php echo esc_html(count($folders)); ?></strong></article>
            <article><span><?php esc_html_e('Безпечний режим', 'ua-mediamanager'); ?></span><strong class="uamm-safe"><?php esc_html_e('Увімкнено', 'ua-mediamanager'); ?></strong></article>
          </section>
          <div class="uamm-toolbar">
            <form method="get" class="uamm-search"><input type="hidden" name="page" value="ua-mediamanager"><input type="search" name="s" value="<?php echo esc_attr(wp_unslash($_GET['s'] ?? '')); ?>" placeholder="<?php esc_attr_e('Пошук файлів…', 'ua-mediamanager'); ?>"><button class="button"><?php esc_html_e('Знайти', 'ua-mediamanager'); ?></button></form>
            <div class="uamm-sort"><?php esc_html_e('Сортувати:', 'ua-mediamanager'); ?>
              <a href="<?php echo esc_url($this->sort_url('name')); ?>"><?php esc_html_e('Назва', 'ua-mediamanager'); ?></a> ·
              <a href="<?php echo esc_url($this->sort_url('type')); ?>"><?php esc_html_e('Тип', 'ua-mediamanager'); ?></a> ·
              <a href="<?php echo esc_url($this->sort_url('date')); ?>"><?php esc_html_e('Дата', 'ua-mediamanager'); ?></a>
              <a href="<?php echo esc_url($this->sort_url($sort, $dir==='ASC'?'desc':'asc')); ?>"><?php echo $dir==='ASC'?'↑':'↓'; ?></a>
            </div>
          </div>
          <div class="uamm-layout"><aside class="uamm-sidebar"><h2><?php esc_html_e('Папки', 'ua-mediamanager'); ?></h2><a class="uamm-folder <?php echo empty($_GET['folder'])?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=ua-mediamanager')); ?>">▦ <?php esc_html_e('Усі файли', 'ua-mediamanager'); ?></a>
            <?php foreach ($folders as $folder): ?><a class="uamm-folder <?php echo absint($_GET['folder'] ?? 0)===$folder->term_id?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(array('page'=>'ua-mediamanager','folder'=>$folder->term_id),admin_url('admin.php'))); ?>">📁 <?php echo esc_html($folder->name); ?> <em><?php echo esc_html($folder->count); ?></em></a><?php endforeach; ?>
            <div class="uamm-new-folder"><input type="text" id="uamm-folder-name" placeholder="<?php esc_attr_e('Нова папка', 'ua-mediamanager'); ?>"><button class="button" id="uamm-create-folder"><?php esc_html_e('Створити', 'ua-mediamanager'); ?></button></div>
            <p class="description"><?php esc_html_e('Папки в цій версії віртуальні: вони не змінюють URL файлів і не ламають посилання на сайті.', 'ua-mediamanager'); ?></p>
          </aside><main class="uamm-main">
            <div class="uamm-list-actions"><label><input type="checkbox" id="uamm-select-all"> <?php esc_html_e('Вибрати все на сторінці', 'ua-mediamanager'); ?></label><button class="button" data-action="quarantine"><?php esc_html_e('До карантину', 'ua-mediamanager'); ?></button><select id="uamm-move-folder"><option value=""><?php esc_html_e('Перемістити до папки…', 'ua-mediamanager'); ?></option><?php foreach($folders as $folder): ?><option value="<?php echo esc_attr($folder->term_id); ?>"><?php echo esc_html($folder->name); ?></option><?php endforeach; ?></select><button class="button" id="uamm-move"><?php esc_html_e('Перемістити', 'ua-mediamanager'); ?></button></div>
            <div class="uamm-grid">
            <?php if ($items->have_posts()): while($items->have_posts()): $items->the_post(); $id=get_the_ID(); $url=wp_get_attachment_url($id); $mime=get_post_mime_type($id); ?>
              <label class="uamm-item"><input class="uamm-check" type="checkbox" value="<?php echo esc_attr($id); ?>"><span class="uamm-thumb"><?php echo wp_get_attachment_image($id,'medium',false,array('loading'=>'lazy')); ?></span><strong><?php echo esc_html(get_the_title($id) ?: wp_basename($url)); ?></strong><small><?php echo esc_html($mime); ?></small><small><?php echo esc_html(get_the_date('', $id)); ?></small><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php esc_html_e('Деталі', 'ua-mediamanager'); ?></a></label>
            <?php endwhile; wp_reset_postdata(); else: ?><p><?php esc_html_e('Файлів не знайдено.', 'ua-mediamanager'); ?></p><?php endif; ?>
            </div>
            <?php echo wp_kses_post(paginate_links(array('total'=>$items->max_num_pages,'current'=>max(1,absint($_GET['paged'] ?? 1)),'format'=>'&paged=%#%'))); ?>
          </main></div>
          <div class="uamm-notice" id="uamm-notice" aria-live="polite"></div>
          <section class="uamm-safety-note"><strong><?php esc_html_e('Важливо про безпеку', 'ua-mediamanager'); ?></strong><p><?php esc_html_e('Перед масовими операціями завантажте повний ZIP-архів. Карантин переміщує вкладення до кошика WordPress, звідки його можна відновити. Пошук невикористовуваних файлів у цій версії позначає лише попередніх кандидатів і не видаляє їх автоматично.', 'ua-mediamanager'); ?></p></section>
        </div><?php
    }

    private function sort_url($sort, $dir = null) {
        $args=array('page'=>'ua-mediamanager','sort'=>$sort,'dir'=>$dir ?: (($_GET['dir'] ?? 'desc')==='asc'?'asc':'desc'));
        if (!empty($_GET['folder'])) $args['folder']=absint($_GET['folder']);
        if (!empty($_GET['s'])) $args['s']=sanitize_text_field(wp_unslash($_GET['s']));
        return add_query_arg($args,admin_url('admin.php'));
    }

    public function render_quarantine() {
        $this->require_capability('delete_posts');
        $q=new WP_Query(array('post_type'=>'attachment','post_status'=>'trash','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC'));
        echo '<div class="wrap uamm-wrap"><h1>'.esc_html__('Карантин медіафайлів','ua-mediamanager').'</h1><p>'.esc_html__('Файли тут ще не видалені фізично. Їх можна повернути або остаточно видалити.','ua-mediamanager').'</p>';
        echo '<p><a class="button button-secondary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=uamm_quarantine_zip'),'uamm_quarantine_zip')).'">'.esc_html__('Завантажити файли карантину ZIP','ua-mediamanager').'</a></p><table class="widefat striped"><thead><tr><th>'.esc_html__('Файл','ua-mediamanager').'</th><th>'.esc_html__('Дата','ua-mediamanager').'</th><th>'.esc_html__('Дії','ua-mediamanager').'</th></tr></thead><tbody>';
        if ($q->have_posts()) { while($q->have_posts()) { $q->the_post(); $id=get_the_ID(); echo '<tr><td>'.esc_html(get_the_title($id)).'</td><td>'.esc_html(get_the_date('', $id)).'</td><td><button class="button" data-action="restore" data-id="'.esc_attr($id).'">'.esc_html__('Відновити','ua-mediamanager').'</button> <button class="button button-link-delete" data-action="delete" data-id="'.esc_attr($id).'">'.esc_html__('Видалити назавжди','ua-mediamanager').'</button></td></tr>'; } wp_reset_postdata(); } else echo '<tr><td colspan="3">'.esc_html__('Карантин порожній.','ua-mediamanager').'</td></tr>';
        echo '</tbody></table><div class="uamm-notice" id="uamm-notice"></div></div>';
    }

    public function render_settings() {
        $this->require_capability('manage_options');
        if (isset($_POST['uamm_save']) && check_admin_referer('uamm_settings')) {
            update_option('uamm_auto_folders', !empty($_POST['auto_folders']) ? 1 : 0);
            echo '<div class="notice notice-success"><p>'.esc_html__('Налаштування збережено.','ua-mediamanager').'</p></div>';
        }
        ?>
        <div class="wrap uamm-wrap"><h1><?php esc_html_e('Налаштування UA MediaManager','ua-mediamanager'); ?></h1>
        <form method="post"><?php wp_nonce_field('uamm_settings'); ?><table class="form-table"><tr><th><?php esc_html_e('Автоматична класифікація','ua-mediamanager'); ?></th><td><label><input type="checkbox" name="auto_folders" value="1" <?php checked(get_option('uamm_auto_folders',0)); ?>> <?php esc_html_e('Дозволити пропонувати папки за типом вкладення','ua-mediamanager'); ?></label><p class="description"><?php esc_html_e('Не переміщує файли фізично та не змінює URL.','ua-mediamanager'); ?></p></td></tr></table><p><button class="button button-primary" name="uamm_save" value="1"><?php esc_html_e('Зберегти','ua-mediamanager'); ?></button></p></form>
        <hr><h2><?php esc_html_e('Перевірка невикористовуваних файлів','ua-mediamanager'); ?></h2><p><?php esc_html_e('Сканер перевіряє вміст записів і метадані WordPress. Він не може достовірно прочитати всі нестандартні сховища сторонніх плагінів, тому результат є лише списком кандидатів для ручної перевірки.','ua-mediamanager'); ?></p><button class="button" id="uamm-scan-unused"><?php esc_html_e('Знайти можливі невикористовувані','ua-mediamanager'); ?></button><div id="uamm-scan-result"></div>
        <hr><h2><?php esc_html_e('Оптимізація','ua-mediamanager'); ?></h2><p><?php esc_html_e('Поточна версія не видаляє похідні розміри автоматично: це може зламати адаптивні зображення тем і WooCommerce. Спочатку створіть резервну копію та перевірте сумісність.','ua-mediamanager'); ?></p></div><?php
    }

    public function ajax_action() {
        check_ajax_referer(self::NONCE, 'nonce');
        $action=sanitize_key($_POST['do'] ?? '');
        $ids=array_map('absint',(array)($_POST['ids'] ?? array()));
        $ids=array_values(array_filter($ids));
        if ($action==='create_folder') {
            $this->require_ajax_cap('upload_files');
            $name=sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            if (!$name) wp_send_json_error(array('message'=>__('Вкажіть назву папки.','ua-mediamanager')));
            $term=wp_insert_term($name,self::TAX);
            if (is_wp_error($term)) wp_send_json_error(array('message'=>$term->get_error_message()));
            wp_send_json_success(array('message'=>__('Папку створено. Оновіть сторінку.','ua-mediamanager')));
        }
        if ($action==='move') {
            $this->require_ajax_cap('upload_files'); $folder=absint($_POST['folder'] ?? 0);
            if (!$folder || !term_exists($folder,self::TAX)) wp_send_json_error(array('message'=>__('Оберіть папку.','ua-mediamanager')));
            foreach($ids as $id) if (get_post_type($id)==='attachment') wp_set_object_terms($id,$folder,self::TAX,false);
            wp_send_json_success(array('message'=>__('Файли додано до папки. Фізичні шляхи не змінювалися.','ua-mediamanager')));
        }
        if ($action==='quarantine') {
            $this->require_ajax_cap('delete_posts'); $done=0;
            foreach($ids as $id) if (get_post_type($id)==='attachment' && current_user_can('delete_post',$id)) { if (wp_trash_post($id)) $done++; }
            wp_send_json_success(array('message'=>sprintf(__('До карантину переміщено: %d.','ua-mediamanager'),$done)));
        }
        if ($action==='restore' || $action==='delete') {
            $this->require_ajax_cap('delete_posts'); $id=absint($_POST['id'] ?? 0);
            if (get_post_type($id)!=='attachment' || get_post_status($id)!=='trash' || !current_user_can('delete_post',$id)) wp_send_json_error(array('message'=>__('Файл не знайдено в карантині або немає прав.','ua-mediamanager')));
            if ($action==='restore') { $r=wp_untrash_post($id); if (!$r) wp_send_json_error(array('message'=>__('Не вдалося відновити файл.','ua-mediamanager'))); }
            else { if (!wp_delete_attachment($id,true)) wp_send_json_error(array('message'=>__('Не вдалося видалити файл.','ua-mediamanager'))); }
            wp_send_json_success(array('message'=>$action==='restore'?__('Файл відновлено.','ua-mediamanager'):__('Файл остаточно видалено.','ua-mediamanager')));
        }
        if ($action==='scan_unused') {
            $this->require_ajax_cap('manage_options');
            global $wpdb;
            $attachments=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>250,'orderby'=>'ID','order'=>'ASC'));
            $candidates=array();
            foreach($attachments as $att) {
                $url=wp_get_attachment_url($att->ID);
                if (!$url) continue;
                $like='%'.$wpdb->esc_like($url).'%';
                $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_type IN ('post','page','product','wp_block','wp_template','wp_template_part') AND post_content LIKE %s",$like));
                if (!$used) {
                    $meta=wp_get_attachment_metadata($att->ID);
                    $parent=wp_get_post_parent_id($att->ID);
                    if (!$parent && empty($meta['sizes'])) $candidates[]=array('title'=>get_the_title($att->ID),'url'=>$url);
                }
                if (count($candidates)>=50) break;
            }
            wp_send_json_success(array('message'=>sprintf(__('Попередньо знайдено кандидатів: %d. Перевірте кожен файл вручну; сканер охоплює лише частину можливих посилань. Перевірено не більше 250 вкладень.','ua-mediamanager'),count($candidates)),'items'=>$candidates));
        }
        wp_send_json_error(array('message'=>__('Невідома дія.','ua-mediamanager')));
    }

    private function require_ajax_cap($cap) {
        if (!current_user_can($cap)) wp_send_json_error(array('message'=>__('Недостатньо прав.','ua-mediamanager')),403);
    }

    private function stream_zip($files, $name, $root) {
        if (!class_exists('ZipArchive')) wp_die(esc_html__('На сервері не увімкнено PHP ZipArchive.','ua-mediamanager'));
        $tmp=wp_tempnam($name.'.zip');
        if (!$tmp) wp_die(esc_html__('Не вдалося створити тимчасовий архів.','ua-mediamanager'));
        $zip=new ZipArchive();
        if ($zip->open($tmp,ZipArchive::OVERWRITE)!==true) { @unlink($tmp); wp_die(esc_html__('Не вдалося створити ZIP.','ua-mediamanager')); }
        foreach($files as $file) {
            if (!is_file($file)) continue;
            $relative=ltrim(str_replace(wp_normalize_path($root),'',wp_normalize_path($file)),'/');
            if ($relative!=='' && strpos($relative,'../')===false) $zip->addFile($file,$relative);
        }
        $zip->close();
        if (!is_file($tmp)) wp_die(esc_html__('Архів не створено.','ua-mediamanager'));
        nocache_headers(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.sanitize_file_name($name).'.zip"'); header('Content-Length: '.filesize($tmp));
        readfile($tmp); @unlink($tmp); exit;
    }

    public function download_backup() {
        $this->require_capability('manage_options'); check_admin_referer('uamm_backup');
        $u=wp_upload_dir(); $root=$u['basedir']; $files=array();
        if (is_dir($root)) { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $f) if($f->isFile()) $files[]=$f->getPathname(); }
        $this->stream_zip($files,'ua-media-library-backup',$root);
    }

    public function download_quarantine() {
        $this->require_capability('delete_posts'); check_admin_referer('uamm_quarantine_zip');
        $q=get_posts(array('post_type'=>'attachment','post_status'=>'trash','numberposts'=>-1));
        $u=wp_upload_dir(); $root=$u['basedir']; $files=array();
        foreach($q as $p) { $path=get_attached_file($p->ID); if($path && is_file($path)) $files[]=$path; $m=wp_get_attachment_metadata($p->ID); if(!empty($m['sizes']) && $path) foreach($m['sizes'] as $s) { $candidate=path_join(dirname($path),$s['file']); if(is_file($candidate)) $files[]=$candidate; } }
        $this->stream_zip(array_unique($files),'ua-media-quarantine',$root);
    }

    public function restore_upload() {
        $this->require_capability('manage_options');
        wp_die(esc_html__('Відновлення ZIP-архіву буде доступне в наступному етапі після реалізації перевірки структури, безпечного розпакування та зіставлення вкладень. Поки що використовуйте відновлення файлів через FTP або резервну копію хостингу.','ua-mediamanager'));
    }
}
UA_MediaManager::instance();
