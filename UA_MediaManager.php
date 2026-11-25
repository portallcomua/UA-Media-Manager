<?php
/**
 * Plugin Name: UA MediaManager
 * Plugin URI: https://github.com/portallcomua/ua-mediamanager
 * Description: Папки та підпапки для WordPress Media Library, переміщення медіа, один оригінал без стандартних дублікатів розмірів, віртуальне масштабування, пошук невикористовуваних файлів та безпечне очищення.
 * Version: 1.1.0
 * Author: portallcomua
 * License: GPL-2.0-or-later
 * Text Domain: ua-mediamanager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UA_MediaManager {

	const VERSION       = '1.1.0';
	const TAX           = 'uam_folder';
	const NONCE         = 'uam_nonce';
	const OPTION        = 'uam_options';
	const TRASH_FOLDER  = 'uam-trash';

	public function __construct() {

		register_activation_hook(
			__FILE__,
			array( __CLASS__, 'activate' )
		);

		add_action(
			'init',
			array( $this, 'register_taxonomy' )
		);

		add_action(
			'admin_menu',
			array( $this, 'admin_menu' )
		);

		add_action(
			'admin_enqueue_scripts',
			array( $this, 'admin_assets' )
		);

		/*
		 * Поле вибору папки у стандартному Media Modal
		 */
		add_filter(
			'attachment_fields_to_edit',
			array( $this, 'attachment_folder_field' ),
			20,
			2
		);

		add_filter(
			'attachment_fields_to_save',
			array( $this, 'save_attachment_folder_field' ),
			20,
			2
		);

		add_filter(
			'media_row_actions',
			array( $this, 'media_row_action' ),
			20,
			2
		);

		add_filter(
			'manage_media_columns',
			array( $this, 'media_column' )
		);

		add_action(
			'manage_media_custom_column',
			array( $this, 'media_column_value' ),
			10,
			2
		);

		/*
		 * Головна функція плагіна:
		 * WordPress більше не створює фізичні копії
		 * -150x150, -300x300, -768x..., тощо.
		 */
		add_filter(
			'intermediate_image_sizes_advanced',
			array( $this, 'disable_intermediate_sizes' ),
			999,
			2
		);

		/*
		 * Не робимо автоматичний big-image файл.
		 */
		add_filter(
			'big_image_size_threshold',
			array( $this, 'disable_big_image_downsize' ),
			999
		);

		/*
		 * Розміри картинок генеруються з одного оригіналу
		 * через динамічний endpoint.
		 */
		add_filter(
			'wp_get_attachment_image_src',
			array( $this, 'virtual_image_src' ),
			20,
			4
		);

		add_filter(
			'wp_calculate_image_srcset',
			array( $this, 'disable_srcset_for_virtual_images' ),
			20,
			5
		);

		/*
		 * AJAX
		 */
		add_action(
			'wp_ajax_uam_create_folder',
			array( $this, 'ajax_create_folder' )
		);

		add_action(
			'wp_ajax_uam_delete_folder',
			array( $this, 'ajax_delete_folder' )
		);

		add_action(
			'wp_ajax_uam_move_media',
			array( $this, 'ajax_move_media' )
		);

		add_action(
			'wp_ajax_uam_move_to_trash',
			array( $this, 'ajax_move_to_trash' )
		);

		add_action(
			'wp_ajax_uam_restore_media',
			array( $this, 'ajax_restore_media' )
		);

		add_action(
			'wp_ajax_uam_delete_permanently',
			array( $this, 'ajax_delete_permanently' )
		);

		add_action(
			'wp_ajax_uam_scan_unused',
			array( $this, 'ajax_scan_unused' )
		);

		add_action(
			'wp_ajax_uam_download_zip',
			array( $this, 'ajax_download_zip' )
		);

		add_action(
			'wp_ajax_uam_dynamic_image',
			array( $this, 'ajax_dynamic_image' )
		);

		add_action(
			'wp_ajax_nopriv_uam_dynamic_image',
			array( $this, 'ajax_dynamic_image' )
		);

		add_action(
			'add_attachment',
			array( $this, 'assign_new_attachment' )
		);

		/*
		 * Додаємо сортування за папкою у стандартній медіатеці
		 */
		add_filter(
			'manage_upload_sortable_columns',
			array( $this, 'sortable_media_column' )
		);

		add_action(
			'pre_get_posts',
			array( $this, 'filter_media_by_folder' )
		);
	}

	/**
	 * Активація
	 */
	public static function activate() {

		$self = new self();

		$self->register_taxonomy();

		/*
		 * Системна папка "Непотріб"
		 */
		if ( ! term_exists( self::TRASH_FOLDER, self::TAX ) ) {

			wp_insert_term(
				'Непотріб',
				self::TAX,
				array(
					'slug' => self::TRASH_FOLDER,
				)
			);
		}

		if ( ! get_option( self::OPTION ) ) {

			add_option(
				self::OPTION,
				array(
					'disable_sizes' => 1,
					'virtual_sizes' => 1,
					'trash_slug'    => self::TRASH_FOLDER,
				)
			);
		}

		flush_rewrite_rules();
	}

	/**
	 * Таксономія папок
	 *
	 * Вона імітує файлову структуру:
	 *
	 * Фото
	 * ├── Команда
	 * │   ├── Директор
	 * │   └── Працівники
	 * └── Товари
	 *
	 * Один attachment належить одній папці.
	 */
	public function register_taxonomy() {

		register_taxonomy(
			self::TAX,
			array( 'attachment' ),
			array(
				'labels' => array(
					'name'          => 'UA MediaManager — Папки',
					'singular_name' => 'Папка',
					'add_new_item'  => 'Створити папку',
					'edit_item'     => 'Редагувати папку',
				),

				'public'             => false,
				'show_ui'            => false,
				'show_admin_column' => false,
				'hierarchical'       => true,
				'show_in_rest'       => false,
				'rewrite'            => false,
			)
		);
	}

	/**
	 * Меню
	 */
	public function admin_menu() {

		add_media_page(
			'UA MediaManager',
			'UA MediaManager',
			'upload_files',
			'ua-mediamanager',
			array( $this, 'render_admin' )
		);
	}

	/**
	 * Assets
	 */
	public function admin_assets( $hook ) {

		if ( $hook !== 'media_page_ua-mediamanager' ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'dashicons' );
		
		// Додаємо стилі для покращеного UI
		wp_add_inline_style(
			'dashicons',
			'
			.uam-folder-active {
				background: #f0f6fc !important;
				border-left: 3px solid #2271b1;
			}
			.uam-card-in-trash {
				opacity: 0.6;
				border-color: #b32d2e;
			}
			.uam-trash-badge {
				background: #b32d2e;
				color: #fff;
				padding: 2px 8px;
				border-radius: 10px;
				font-size: 10px;
				margin-left: 6px;
			}
			'
		);
	}

	/**
	 * Налаштування
	 */
	private function options() {

		return wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'disable_sizes' => 1,
				'virtual_sizes' => 1,
				'trash_slug'    => self::TRASH_FOLDER,
			)
		);
	}

	/**
	 * AJAX security
	 */
	private function check_nonce() {

		if ( ! current_user_can( 'upload_files' ) ) {

			wp_send_json_error(
				'Недостатньо прав.',
				403
			);
		}

		check_ajax_referer(
			self::NONCE,
			'nonce'
		);
	}

	/**
	 * Отримати фізичний шлях папки
	 */
	private function folder_path( $term_id ) {

		$parts = array();

		$term = get_term(
			(int) $term_id,
			self::TAX
		);

		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		while ( $term && ! is_wp_error( $term ) ) {

			array_unshift(
				$parts,
				sanitize_file_name( $term->slug )
			);

			if ( ! $term->parent ) {
				break;
			}

			$term = get_term(
				$term->parent,
				self::TAX
			);
		}

		return implode(
			'/',
			$parts
		);
	}

	/**
	 * Фізично перемістити attachment
	 */
	private function move_attachment_file(
		$attachment_id,
		$term_id
	) {

		$file = get_attached_file(
			$attachment_id
		);

		if ( ! $file || ! file_exists( $file ) ) {

			return new WP_Error(
				'file_missing',
				'Файл не знайдено.'
			);
		}

		$uploads = wp_upload_dir();

		$root = trailingslashit(
			$uploads['basedir']
		);

		$basename = wp_basename(
			$file
		);

		/*
		 * Коренева папка.
		 */
		if ( ! $term_id ) {

			update_post_meta(
				$attachment_id,
				'_uam_folder',
				0
			);

			return true;
		}

		$folder = $this->folder_path(
			$term_id
		);

		if ( $folder === '' ) {

			update_post_meta(
				$attachment_id,
				'_uam_folder',
				0
			);

			return true;
		}

		$dest_dir = trailingslashit(
			$root . $folder
		);

		if ( ! wp_mkdir_p( $dest_dir ) ) {

			return new WP_Error(
				'mkdir_failed',
				'Не вдалося створити папку.'
			);
		}

		$dest = $dest_dir . $basename;

		if (
			wp_normalize_path( $file ) ===
			wp_normalize_path( $dest )
		) {

			update_post_meta(
				$attachment_id,
				'_uam_folder',
				(int) $term_id
			);

			return true;
		}

		/*
		 * Якщо такий файл вже існує,
		 * WordPress робить безпечне унікальне ім'я.
		 */
		if ( file_exists( $dest ) ) {

			$unique = wp_unique_filename(
				$dest_dir,
				$basename
			);

			$dest = $dest_dir . $unique;
		}

		if ( ! @rename( $file, $dest ) ) {

			return new WP_Error(
				'move_failed',
				'Не вдалося перемістити файл.'
			);
		}

		$new_relative = ltrim(
			str_replace(
				wp_normalize_path( $root ),
				'',
				wp_normalize_path( $dest )
			),
			'/'
		);

		update_attached_file(
			$attachment_id,
			$new_relative
		);

		update_post_meta(
			$attachment_id,
			'_wp_attached_file',
			$new_relative
		);

		update_post_meta(
			$attachment_id,
			'_uam_folder',
			(int) $term_id
		);

		/*
		 * Прибираємо стару інформацію про фізичні thumbnails.
		 */
		$metadata = wp_get_attachment_metadata(
			$attachment_id
		);

		if ( is_array( $metadata ) ) {

			$metadata['file']  = $new_relative;
			$metadata['sizes'] = array();

			wp_update_attachment_metadata(
				$attachment_id,
				$metadata
			);
		}

		return true;
	}

	/**
	 * Новий attachment
	 */
	public function assign_new_attachment( $attachment_id ) {

		if (
			! get_post_meta(
				$attachment_id,
				'_uam_folder',
				true
			)
		) {

			update_post_meta(
				$attachment_id,
				'_uam_folder',
				0
			);
		}
	}

	/**
	 * Поле папки у Media Modal
	 */
	public function attachment_folder_field(
		$form_fields,
		$post
	) {

		if ( ! current_user_can( 'upload_files' ) ) {
			return $form_fields;
		}

		$selected = (int) get_post_meta(
			$post->ID,
			'_uam_folder',
			true
		);

		$form_fields['uam_folder'] = array(
			'label' => 'UA MediaManager — папка',
			'input' => 'html',
			'html'  => $this->folder_select_html(
				'attachments[' . $post->ID . '][uam_folder]',
				$selected
			),
			'helps' => 'Оберіть папку. Файл буде фізично переміщено до неї.',
		);

		return $form_fields;
	}

	/**
	 * Зберегти папку attachment
	 */
	public function save_attachment_folder_field(
		$post,
		$attachment
	) {

		if ( isset( $attachment['uam_folder'] ) ) {

			$term_id = absint(
				$attachment['uam_folder']
			);

			if (
				$term_id &&
				term_exists(
					$term_id,
					self::TAX
				)
			) {

				wp_set_object_terms(
					$post['ID'],
					array( $term_id ),
					self::TAX,
					false
				);

				$this->move_attachment_file(
					$post['ID'],
					$term_id
				);

			} else {

				wp_set_object_terms(
					$post['ID'],
					array(),
					self::TAX,
					false
				);

				update_post_meta(
					$post['ID'],
					'_uam_folder',
					0
				);
			}
		}

		return $post;
	}

	/**
	 * Select папок
	 */
	private function folder_select_html(
		$name,
		$selected = 0
	) {

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$out =
			'<select name="' .
			esc_attr( $name ) .
			'">';

		$out .=
			'<option value="0">' .
			'— Коренева папка —' .
			'</option>';

		if ( ! is_wp_error( $terms ) ) {

			foreach ( $terms as $term ) {
				
				// Пропускаємо системну папку "Непотріб" у виборі
				if ( $term->slug === self::TRASH_FOLDER ) {
					continue;
				}

				$out .=
					'<option value="' .
					(int) $term->term_id .
					'" ' .
					selected(
						$selected,
						$term->term_id,
						false
					) .
					'>' .
					esc_html(
						$this->term_label( $term )
					) .
					'</option>';
			}
		}

		$out .= '</select>';

		return $out;
	}

	/**
	 * Назва папки з відступами
	 */
	private function term_label( $term ) {

		$depth = 0;
		$p     = $term->parent;

		while ( $p ) {

			$parent = get_term(
				$p,
				self::TAX
			);

			if ( ! $parent || is_wp_error( $parent ) ) {
				break;
			}

			$depth++;
			$p = (int) $parent->parent;
		}

		return str_repeat(
			'— ',
			$depth
		) . $term->name;
	}

	/**
	 * Посилання з Media Library
	 */
	public function media_row_action(
		$actions,
		$post
	) {

		if ( current_user_can( 'upload_files' ) ) {

			$actions['uam_folder'] =
				'<a href="' .
				esc_url(
					admin_url(
						'upload.php?page=ua-mediamanager&folder=' .
						$post->ID
					)
				) .
				'">📁 UA MediaManager</a>';
		}

		return $actions;
	}

	/**
	 * Колонка папки
	 */
	public function media_column( $columns ) {

		$columns['uam_folder'] = 'UA Папка';

		return $columns;
	}

	/**
	 * Значення колонки
	 */
	public function media_column_value(
		$column,
		$post_id
	) {

		if ( $column !== 'uam_folder' ) {
			return;
		}

		$terms = get_the_terms(
			$post_id,
			self::TAX
		);

		if (
			$terms &&
			! is_wp_error( $terms )
		) {
			
			$names = wp_list_pluck(
				$terms,
				'name'
			);
			
			// Якщо це папка "Непотріб" - виділяємо червоним
			foreach ( $terms as $term ) {
				if ( $term->slug === self::TRASH_FOLDER ) {
					echo '<span style="color:#b32d2e;">🗑️ ' . esc_html( $term->name ) . '</span>';
					return;
				}
			}

			echo esc_html(
				implode(
					', ',
					$names
				)
			);

		} else {

			echo '—';
		}
	}

	/**
	 * Сортування за папкою
	 */
	public function sortable_media_column( $columns ) {
		$columns['uam_folder'] = 'uam_folder';
		return $columns;
	}

	/**
	 * Фільтр медіа за папкою
	 */
	public function filter_media_by_folder( $query ) {
		global $pagenow;
		
		if (
			$pagenow === 'upload.php' &&
			isset( $_GET['uam_folder'] ) &&
			! empty( $_GET['uam_folder'] )
		) {
			$folder = absint( $_GET['uam_folder'] );
			$query->set(
				'tax_query',
				array(
					array(
						'taxonomy' => self::TAX,
						'field'    => 'term_id',
						'terms'    => $folder,
					),
				)
			);
		}
	}

	/**
	 * Дерево папок
	 */
	private function render_tree(
		$parent = 0,
		$active = 0
	) {

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'parent'     => $parent,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if (
			is_wp_error( $terms ) ||
			! $terms
		) {
			return;
		}

		echo '<ul class="uam-tree">';

		foreach ( $terms as $term ) {

			$count = (int) $term->count;
			$is_active = ( $active === $term->term_id );
			$is_trash = ( $term->slug === self::TRASH_FOLDER );

			$children = get_terms(
				array(
					'taxonomy'   => self::TAX,
					'hide_empty' => false,
					'parent'     => $term->term_id,
					'fields'     => 'ids',
				)
			);

			echo
				'<li class="uam-folder' . 
				( $is_active ? ' uam-folder-active' : '' ) . 
				'" data-id="' .
				(int) $term->term_id .
				'">';

			echo
				'<button class="uam-folder-btn" type="button">' .
				( $is_trash ? '🗑️ ' : '📁 ' ) .
				esc_html( $term->name ) .
				' <span class="uam-count">' .
				$count .
				'</span>' .
				'</button>';

			/*
			 * Системну папку не можна видаляти.
			 */
			if ( $term->slug !== self::TRASH_FOLDER ) {

				echo
					'<button class="uam-folder-delete" ' .
					'type="button" ' .
					'title="Видалити папку" ' .
					'data-id="' .
					(int) $term->term_id .
					'">×</button>';
			}

			if ( $children ) {
				$this->render_tree(
					$term->term_id,
					$active
				);
			}

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Отримати медіа
	 */
	private function get_media(
		$folder = 0,
		$search = '',
		$limit = 500
	) {

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $search !== '' ) {
			$args['s'] = $search;
		}

		if ( $folder > 0 ) {

			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAX,
					'field'    => 'term_id',
					'terms'    => $folder,
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Картка медіа
	 */
	private function media_card( $post ) {

		$id   = $post->ID;
		$mime = get_post_mime_type( $id );
		$url  = wp_get_attachment_url( $id );

		$is_image = wp_attachment_is_image( $id );
		
		// Перевіряємо, чи файл у "Непотріб"
		$terms = wp_get_object_terms( $id, self::TAX );
		$in_trash = false;
		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( $terms as $term ) {
				if ( $term->slug === self::TRASH_FOLDER ) {
					$in_trash = true;
					break;
				}
			}
		}

		echo
			'<div class="uam-card' . 
			( $in_trash ? ' uam-card-in-trash' : '' ) . 
			'" data-id="' .
			(int) $id .
			'">';

		echo
			'<label class="uam-check">' .
			'<input type="checkbox" ' .
			'class="uam-media-check" ' .
			'value="' .
			(int) $id .
			'">' .
			'</label>';

		echo '<div class="uam-thumb">';

		if ( $is_image ) {

			echo wp_get_attachment_image(
				$id,
				array( 180, 140 ),
				false,
				array(
					'loading' => 'lazy',
				)
			);

		} else {

			$icon =
				$mime === 'application/pdf'
					? 'dashicons-pdf'
					: 'dashicons-media-default';

			echo
				'<span class="dashicons ' .
				$icon .
				'"></span>';
		}

		echo '</div>';

		echo
			'<div class="uam-card-name" ' .
			'title="' .
			esc_attr(
				get_the_title( $id )
			) .
			'">' .
			esc_html(
				get_the_title( $id )
			) .
			'</div>';

		echo
			'<div class="uam-card-meta">' .
			esc_html( $mime ) .
			( $in_trash ? ' <span class="uam-trash-badge">🗑️</span>' : '' ) .
			'</div>';

		echo
			'<a href="' .
			esc_url( $url ) .
			'" target="_blank" class="uam-open">' .
			'Відкрити' .
			'</a>';

		echo '</div>';
	}

	/**
	 * Основна адмін-сторінка
	 */
	public function render_admin() {

		if ( ! current_user_can( 'upload_files' ) ) {

			wp_die(
				'Недостатньо прав.'
			);
		}

		$folder = isset( $_GET['folder'] )
			? absint( $_GET['folder'] )
			: 0;

		$search = isset( $_GET['s'] )
			? sanitize_text_field(
				wp_unslash(
					$_GET['s']
				)
			)
			: '';

		$media = $this->get_media(
			$folder,
			$search
		);

		?>
		<div class="wrap uam-wrap">

			<style>
				.uam-wrap {
					max-width: 1500px;
				}

				.uam-head {
					display: flex;
					gap: 12px;
					align-items: center;
					justify-content: space-between;
					margin: 15px 0;
				}

				.uam-head h1 {
					margin: 0;
				}

				.uam-layout {
					display: grid;
					grid-template-columns: 280px 1fr;
					gap: 18px;
				}

				.uam-panel {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					padding: 15px;
				}

				.uam-tree {
					list-style: none;
					margin: 10px 0 0 0;
					padding-left: 16px;
				}

				.uam-tree > li {
					padding: 2px 0;
				}

				.uam-folder-btn {
					border: 0;
					background: none;
					cursor: pointer;
					padding: 7px 5px;
					font-size: 14px;
					text-align: left;
					width: 85%;
				}

				.uam-folder-btn:hover {
					color: #2271b1;
				}

				.uam-folder-active .uam-folder-btn {
					font-weight: 600;
					color: #2271b1;
				}

				.uam-folder-delete {
					border: 0;
					background: none;
					color: #b32d2e;
					cursor: pointer;
					float: right;
					font-size: 16px;
				}

				.uam-count {
					color: #777;
					font-size: 11px;
				}

				.uam-toolbar {
					display: flex;
					flex-wrap: wrap;
					gap: 8px;
					margin-bottom: 14px;
					align-items: center;
				}

				.uam-toolbar input[type=text] {
					min-width: 260px;
				}

				.uam-grid {
					display: grid;
					grid-template-columns:
						repeat(
							auto-fill,
							minmax(170px,1fr)
						);
					gap: 12px;
				}

				.uam-card {
					position: relative;
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 7px;
					padding: 8px;
					min-height: 210px;
				}

				.uam-card-in-trash {
					opacity: 0.7;
					border-color: #b32d2e;
					background: #fdf0f0;
				}

				.uam-thumb {
					height: 140px;
					display: flex;
					align-items: center;
					justify-content: center;
					background: #f6f7f7;
					border-radius: 4px;
					overflow: hidden;
				}

				.uam-thumb img {
					width: 100%;
					height: 100%;
					object-fit: contain;
				}

				.uam-thumb .dashicons {
					font-size: 54px;
					width: 54px;
					height: 54px;
				}

				.uam-check {
					position: absolute;
					top: 8px;
					left: 8px;
					z-index: 2;
				}

				.uam-check input {
					width: 18px;
					height: 18px;
				}

				.uam-card-name {
					font-weight: 600;
					font-size: 12px;
					margin-top: 7px;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
				}

				.uam-card-meta {
					font-size: 10px;
					color: #777;
					margin-top: 3px;
				}

				.uam-trash-badge {
					background: #b32d2e;
					color: #fff;
					padding: 1px 6px;
					border-radius: 8px;
					font-size: 9px;
					margin-left: 4px;
				}

				.uam-open {
					font-size: 11px;
				}

				.uam-modal {
					display: none;
					position: fixed;
					inset: 0;
					background: rgba(0,0,0,.45);
					z-index: 100000;
					align-items: center;
					justify-content: center;
				}

				.uam-modal-box {
					background: #fff;
					border-radius: 8px;
					padding: 25px;
					width: min(600px,90vw);
					max-height: 85vh;
					overflow: auto;
				}

				.uam-modal-close {
					float: right;
					font-size: 20px;
					cursor: pointer;
					background: none;
					border: 0;
					color: #777;
				}

				.uam-modal-close:hover {
					color: #000;
				}

				.uam-premium {
					border-left: 4px solid #dba617;
					padding: 10px 12px;
					background: #fff9e8;
					margin-bottom: 15px;
				}

				.uam-unused-table {
					width: 100%;
					border-collapse: collapse;
					background: #fff;
				}

				.uam-unused-table th,
				.uam-unused-table td {
					padding: 8px;
					border-bottom: 1px solid #eee;
					text-align: left;
				}

				.uam-unused-table tr:hover {
					background: #f6f7f7;
				}

				.uam-danger {
					color: #b32d2e !important;
				}

				.uam-muted {
					color: #646970;
				}

				.uam-empty {
					padding: 40px;
					text-align: center;
					color: #777;
					background: #fff;
					border: 1px dashed #ccc;
				}

				.uam-progress {
					display: none;
					margin: 10px 0;
				}

				.uam-actions {
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					margin-top: 10px;
				}

				@media(max-width:900px) {
					.uam-layout {
						grid-template-columns: 1fr;
					}
				}
			</style>

			<div class="uam-head">
				<h1>
					📁 UA MediaManager
					<small>
						<?php echo esc_html( self::VERSION ); ?>
					</small>
				</h1>
				<div>
					<button
						class="button button-primary"
						id="uam-new-folder">
						+ Нова папка
					</button>
					<button
						class="button"
						id="uam-upload">
						+ Додати медіа
					</button>
				</div>
			</div>

			<div class="uam-premium">
				<strong>✨ Premium функції:</strong>
				пошук невикористовуваних медіа → «Непотріб» → архів → остаточне видалення.
				<br>
				<strong>✅ Базова версія:</strong>
				папки, підпапки, переміщення, один оригінал без стандартних дублікатів розмірів.
			</div>

			<div class="uam-layout">
				<aside class="uam-panel">
					<strong>📂 Папки</strong>
					<p>
						<button
							class="button"
							id="uam-root">
							📁 Всі медіа
						</button>
					</p>
					<?php
					$this->render_tree( 0, $folder );
					?>
					<div class="uam-trash" style="margin-top:15px;border-top:1px solid #ddd;padding-top:12px;">
						<button
							class="button"
							id="uam-unused">
							🔍 Знайти невикористовувані
						</button>
						<button
							class="button"
							id="uam-view-trash"
							style="margin-top:5px;">
							🗑️ Переглянути «Непотріб»
						</button>
					</div>
				</aside>

				<main>
					<div class="uam-panel">
						<div class="uam-toolbar">
							<form method="get" style="display:flex;gap:6px;flex-wrap:wrap;">
								<input
									type="hidden"
									name="page"
									value="ua-mediamanager">
								<input
									type="text"
									name="s"
									value="<?php echo esc_attr( $search ); ?>"
									placeholder="🔍 Пошук медіа…"
									style="min-width:200px;">
								<button class="button">Пошук</button>
							</form>

							<button
								class="button"
								id="uam-move-selected"
								disabled>
								📂 Перемістити
							</button>

							<button
								class="button"
								id="uam-trash-selected"
								disabled>
								🗑️ У «Непотріб»
							</button>

							<button
								class="button uam-danger"
								id="uam-delete-selected"
								disabled
								style="display:none;">
								❌ Видалити назавжди
							</button>
						</div>

						<div
							id="uam-progress"
							class="uam-progress notice notice-info">
							<p>⏳ Обробка…</p>
						</div>

						<?php
						if ( ! $media ) {
							echo
								'<div class="uam-empty">' .
								'📭 У цій папці медіа немає.' .
								'</div>';
						} else {
							echo
								'<div class="uam-grid">';
							foreach (
								$media as $post
							) {
								$this->media_card(
									$post
								);
							}
							echo '</div>';
						}
						?>
					</div>
				</main>
			</div>

			<!-- Модальне вікно -->
			<div
				id="uam-modal"
				class="uam-modal">
				<div class="uam-modal-box">
					<button
						class="uam-modal-close">
						×
					</button>
					<div id="uam-modal-content"></div>
				</div>
			</div>

			<script>
			jQuery(function($){

				const nonce =
					'<?php
					echo esc_js(
						wp_create_nonce(
							self::NONCE
						)
					);
					?>';

				const ajax =
					'<?php
					echo esc_js(
						admin_url(
							'admin-ajax.php'
						)
					);
					?>';

				let selectedFolder =
					<?php
					echo (int) $folder;
					?>;


				function post(
					action,
					data
				){
					data = data || {};
					data.action = action;
					data.nonce  = nonce;
					return $.post(
						ajax,
						data
					);
				}

				function openModal(html){
					$('#uam-modal-content')
						.html(html);
					$('#uam-modal')
						.css(
							'display',
							'flex'
						);
				}

				function closeModal(){
					$('#uam-modal')
						.hide();
				}

				$('.uam-modal-close')
					.on(
						'click',
						closeModal
					);

				$('#uam-modal')
					.on(
						'click',
						function(e){
							if (
								e.target === this
							){
								closeModal();
							}
						}
					);

				$('#uam-root')
					.on(
						'click',
						function(){
							location.href =
								'<?php
								echo esc_js(
									admin_url(
										'upload.php?page=ua-mediamanager'
									)
								);
								?>';
						}
					);

				$('#uam-view-trash')
					.on(
						'click',
						function(){
							// Знаходимо ID папки "Непотріб"
							$.post(
								ajax,
								{
									action: 'uam_get_trash_id',
									nonce: nonce
								}
							)
							.done(function(r){
								if ( r.success && r.data ) {
									location.href =
										'<?php
										echo esc_js(
											admin_url(
												'upload.php?page=ua-mediamanager&folder='
											)
										);
										?>' + r.data;
								}
							});
						}
					);

				$('.uam-folder-btn')
					.on(
						'click',
						function(){
							location.href =
								'<?php
								echo esc_js(
									admin_url(
										'upload.php?page=ua-mediamanager&folder='
									)
								);
								?>' +
								$(this)
									.closest(
										'.uam-folder'
									)
									.data('id');
						}
					);

				// Нова папка
				$('#uam-new-folder')
					.on(
						'click',
						function(){
							openModal(
								'<h2>📁 Нова папка</h2>' +
								'<p><input id="uam-folder-name" class="widefat" placeholder="Назва папки"></p>' +
								'<p>Батьківська папка:</p>' +
								'<p>' +
								'<?php
								echo $this->folder_select_html(
									'uam_parent',
									0
								);
								?>' +
								'</p>' +
								'<p><button class="button button-primary" id="uam-create">Створити</button></p>'
							);
						}
					);

				$(document)
					.on(
						'click',
						'#uam-create',
						function(){
							let name =
								$('#uam-folder-name')
								.val()
								.trim();
							let parent =
								$(
									'[name=uam_parent]'
								)
								.val();
							if ( ! name ){
								alert('Вкажіть назву папки.');
								return;
							}
							post(
								'uam_create_folder',
								{
									name: name,
									parent: parent
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										location.reload();
									} else {
										alert(
											r.data ||
											'Помилка'
										);
									}
								}
							);
						}
					);

				// Видалення папки
				$('.uam-folder-delete')
					.on(
						'click',
						function(e){
							e.stopPropagation();
							let id =
								$(this)
								.data('id');
							if (
								! confirm(
									'Видалити папку? Файли не будуть видалені.'
								)
							){
								return;
							}
							post(
								'uam_delete_folder',
								{
									term_id: id
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										location.reload();
									} else {
										alert(
											r.data ||
											'Помилка'
										);
									}
								}
							);
						}
					);

				// Вибрані файли
				function checked(){
					return $(
						'.uam-media-check:checked'
					)
					.map(
						function(){
							return this.value;
						}
					)
					.get();
				}

				$(document)
					.on(
						'change',
						'.uam-media-check',
						function(){
							let n =
								checked()
								.length;
							$(
								'#uam-move-selected,' +
								'#uam-trash-selected'
							)
							.prop(
								'disabled',
								!n
							);
							
							// Показуємо кнопку видалення, якщо є файли в "Непотріб"
							let inTrash = false;
							$('.uam-media-check:checked').each(function(){
								if ($(this).closest('.uam-card-in-trash').length) {
									inTrash = true;
								}
							});
							if (inTrash && n > 0) {
								$('#uam-delete-selected').show().prop('disabled', false);
							} else {
								$('#uam-delete-selected').hide().prop('disabled', true);
							}
						}
					);

				// Переміщення
				$('#uam-move-selected')
					.on(
						'click',
						function(){
							let ids =
								checked();
							openModal(
								'<h2>📂 Перемістити медіа</h2>' +
								'<p>Виберіть папку призначення:</p>' +
								'<p>' +
								'<?php
								echo $this->folder_select_html(
									'uam_dest',
									0
								);
								?>' +
								'</p>' +
								'<p><button class="button button-primary" id="uam-do-move">Перемістити</button></p>'
							);
							$('#uam-do-move')
								.data(
									'ids',
									ids
								);
						}
					);

				$(document)
					.on(
						'click',
						'#uam-do-move',
						function(){
							let ids =
								$(this)
								.data('ids');
							let dest =
								$(
									'[name=uam_dest]'
								)
								.val();
							post(
								'uam_move_media',
								{
									ids: ids,
									dest: dest
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										location.reload();
									} else {
										alert(
											r.data ||
											'Помилка'
										);
									}
								}
							);
						}
					);

				// У Непотріб
				$('#uam-trash-selected')
					.on(
						'click',
						function(){
							let ids =
								checked();
							if (
								! confirm(
									'Перемістити вибрані файли в «Непотріб»?'
								)
							){
								return;
							}
							post(
								'uam_move_to_trash',
								{
									ids: ids
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										location.reload();
									} else {
										alert(
											r.data ||
											'Помилка'
										);
									}
								}
							);
						}
					);

				// Остаточне видалення
				$('#uam-delete-selected')
					.on(
						'click',
						function(){
							let ids =
								checked();
							if (
								! confirm(
									'⚠️ ВИДАЛИТИ НАЗАВЖДИ вибрані файли? Цю дію не можна скасувати!'
								)
							){
								return;
							}
							post(
								'uam_delete_permanently',
								{
									ids: ids
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										location.reload();
									} else {
										alert(
											(r.data && r.data.errors) 
												? r.data.errors.join('\n')
												: 'Помилка'
										);
									}
								}
							);
						}
					);

				// WordPress Media
				$('#uam-upload')
					.on(
						'click',
						function(){
							const frame =
								wp.media(
									{
										title: 'Додати медіа',
										button: { text: 'Додати' },
										multiple: true
									}
								);
							frame.on(
								'select',
								function(){
									let items =
										frame
										.state()
										.get('selection')
										.toJSON();
									if (
										selectedFolder
									){
										let ids =
											items.map(
												function(x){
													return x.id;
												}
											);
										post(
											'uam_move_media',
											{
												ids: ids,
												dest: selectedFolder
											}
										)
										.done(
											function(){
												location.reload();
											}
										);
									}
								}
							);
							frame.open();
						}
					);

				// Premium: пошук невикористовуваних
				$('#uam-unused')
					.on(
						'click',
						function(){
							$('#uam-progress')
								.show();
							post(
								'uam_scan_unused'
							)
							.done(
								function(r){
									$('#uam-progress')
										.hide();
									if (
										! r.success
									){
										alert(
											r.data ||
											'Помилка'
										);
										return;
									}
									let rows = '';
									if ( r.data.items && r.data.items.length > 0 ) {
										r.data.items
											.forEach(
												function(x){
													rows +=
														'<tr>' +
														'<td><input type="checkbox" class="uam-unused" value="' + x.id + '"></td>' +
														'<td>' + x.name + '</td>' +
														'<td><a href="' + x.url + '" target="_blank">' + x.url + '</a></td>' +
														'</tr>';
												}
											);
									} else {
										rows = '<tr><td colspan="3">✅ Невикористовуваних медіа не знайдено.</td></tr>';
									}
									openModal(
										'<h2>🔍 Невикористовувані медіа</h2>' +
										'<p class="uam-muted">Знайдено: <strong>' + (r.data.items ? r.data.items.length : 0) + '</strong> файлів.</p>' +
										'<p class="uam-muted">⚠️ Результат потрібно перевірити вручну перед остаточним видаленням.</p>' +
										'<table class="uam-unused-table">' +
										'<thead><tr><th></th><th>Файл</th><th>URL</th></tr></thead>' +
										'<tbody>' + rows + '</tbody>' +
										'</table>' +
										'<div class="uam-actions">' +
										'<button class="button" id="uam-unused-trash">🗑️ Перенести вибрані в «Непотріб»</button> ' +
										'<button class="button" id="uam-download">📦 Завантажити архів «Непотріб»</button>' +
										'<button class="button uam-danger" id="uam-unused-delete" style="display:none;">❌ Видалити вибрані назавжди</button>' +
										'</div>'
									);
									
									// Показуємо кнопку видалення, якщо є вибрані
									$(document).on('change', '.uam-unused', function() {
										if ($('.uam-unused:checked').length > 0) {
											$('#uam-unused-delete').show();
										} else {
											$('#uam-unused-delete').hide();
										}
									});
								}
							);
						}
					);

				// Непотріб -> архів
				$(document)
					.on(
						'click',
						'#uam-download',
						function(){
							window.location =
								ajax +
								'?action=uam_download_zip' +
								'&nonce=' +
								encodeURIComponent(
									nonce
								);
						}
					);

				// Перенесення знайдених у Непотріб
				$(document)
					.on(
						'click',
						'#uam-unused-trash',
						function(){
							let ids =
								$('.uam-unused:checked')
								.map(
									function(){
										return this.value;
									}
								)
								.get();
							if (
								! ids.length
							){
								alert('Оберіть хоча б один файл.');
								return;
							}
							post(
								'uam_move_to_trash',
								{
									ids: ids
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										closeModal();
										location.reload();
									} else {
										alert(
											r.data ||
											'Помилка'
										);
									}
								}
							);
						}
					);

				// Остаточне видалення зі знайдених
				$(document)
					.on(
						'click',
						'#uam-unused-delete',
						function(){
							let ids =
								$('.uam-unused:checked')
								.map(
									function(){
										return this.value;
									}
								)
								.get();
							if (
								! ids.length
							){
								alert('Оберіть хоча б один файл.');
								return;
							}
							if (
								! confirm(
									'⚠️ ВИДАЛИТИ НАЗАВЖДИ вибрані файли? Цю дію не можна скасувати!'
								)
							){
								return;
							}
							post(
								'uam_delete_permanently',
								{
									ids: ids
								}
							)
							.done(
								function(r){
									if (
										r.success
									){
										closeModal();
										location.reload();
									} else {
										alert(
											(r.data && r.data.errors) 
												? r.data.errors.join('\n')
												: 'Помилка'
										);
									}
								}
							);
						}
					);

			});
			</script>

		</div>
		<?php
	}

	/**
	 * Створення папки
	 */
	public function ajax_create_folder() {

		$this->check_nonce();

		$name = isset( $_POST['name'] )
			? sanitize_text_field(
				wp_unslash(
					$_POST['name']
				)
			)
			: '';

		$parent = isset( $_POST['parent'] )
			? absint(
				$_POST['parent']
			)
			: 0;

		if ( $name === '' ) {
			wp_send_json_error(
				'Вкажіть назву.'
			);
		}

		if (
			$parent &&
			! term_exists(
				$parent,
				self::TAX
			)
		) {
			$parent = 0;
		}

		$result = wp_insert_term(
			$name,
			self::TAX,
			array(
				'parent' => $parent,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				$result->get_error_message()
			);
		}

		wp_send_json_success(
			array(
				'term_id' =>
					$result['term_id'],
			)
		);
	}

	/**
	 * Видалення папки
	 *
	 * Самі медіа не видаляються.
	 */
	public function ajax_delete_folder() {

		$this->check_nonce();

		$id = absint(
			$_POST['term_id'] ?? 0
		);

		if ( ! $id ) {
			wp_send_json_error(
				'Невірна папка.'
			);
		}

		$term = get_term(
			$id,
			self::TAX
		);

		if (
			! $term ||
			is_wp_error( $term )
		) {
			wp_send_json_error(
				'Папку не знайдено.'
			);
		}

		if (
			$term->slug ===
			self::TRASH_FOLDER
		) {
			wp_send_json_error(
				'Системну папку «Непотріб» видаляти не можна.'
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'tax_query' => array(
					array(
						'taxonomy' => self::TAX,
						'field'    => 'term_id',
						'terms'    => $id,
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			wp_set_object_terms(
				$post->ID,
				array(),
				self::TAX,
				false
			);
			update_post_meta(
				$post->ID,
				'_uam_folder',
				0
			);
		}

		$result =
			wp_delete_term(
				$id,
				self::TAX
			);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				$result->get_error_message()
			);
		}

		wp_send_json_success();
	}

	/**
	 * Отримати ID папки "Непотріб"
	 */
	public function ajax_get_trash_id() {
		$this->check_nonce();
		
		$trash = get_term_by(
			'slug',
			self::TRASH_FOLDER,
			self::TAX
		);
		
		if ( $trash ) {
			wp_send_json_success( $trash->term_id );
		} else {
			wp_send_json_error( 'Папку "Непотріб" не знайдено.' );
		}
	}

	/**
	 * Переміщення медіа
	 */
	public function ajax_move_media() {

		$this->check_nonce();

		$ids = isset( $_POST['ids'] )
			? (array) $_POST['ids']
			: array();

		$dest = absint(
			$_POST['dest'] ?? 0
		);

		if (
			$dest &&
			! term_exists(
				$dest,
				self::TAX
			)
		) {
			wp_send_json_error(
				'Папку не знайдено.'
			);
		}

		$errors = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			wp_set_object_terms(
				$id,
				$dest
					? array( $dest )
					: array(),
				self::TAX,
				false
			);

			$result =
				$this->move_attachment_file(
					$id,
					$dest
				);

			if (
				is_wp_error(
					$result
				)
			) {
				$errors[] =
					$result->get_error_message();
			}
		}

		if ( $errors ) {
			wp_send_json_error(
				implode(
					"\n",
					$errors
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * Перемістити в "Непотріб"
	 */
	public function ajax_move_to_trash() {

		$this->check_nonce();

		$trash =
			get_term_by(
				'slug',
				self::TRASH_FOLDER,
				self::TAX
			);

		if ( ! $trash ) {
			wp_send_json_error(
				'Системну папку не знайдено.'
			);
		}

		$ids =
			(array) (
				$_POST['ids'] ?? array()
			);

		$errors = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			wp_set_object_terms(
				$id,
				array(
					$trash->term_id,
				),
				self::TAX,
				false
			);

			$result =
				$this->move_attachment_file(
					$id,
					$trash->term_id
				);

			if (
				is_wp_error(
					$result
				)
			) {
				$errors[] =
					$result->get_error_message();
			}
		}

		if ( $errors ) {
			wp_send_json_error(
				implode(
					"\n",
					$errors
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * Відновлення
	 */
	public function ajax_restore_media() {

		$this->check_nonce();

		$ids =
			(array) (
				$_POST['ids'] ?? array()
			);

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			wp_set_object_terms(
				$id,
				array(),
				self::TAX,
				false
			);

			update_post_meta(
				$id,
				'_uam_folder',
				0
			);
		}

		wp_send_json_success();
	}

	/**
	 * Перевірка використання медіа
	 */
	private function post_references_media(
		$attachment_id
	) {
		global $wpdb;

		$url =
			wp_get_attachment_url(
				$attachment_id
			);

		$guid =
			get_post_field(
				'guid',
				$attachment_id
			);

		$file =
			get_attached_file(
				$attachment_id
			);

		$patterns = array_filter(
			array(
				$url,
				$guid,
				$file
					? wp_basename( $file )
					: '',
			)
		);

		foreach ( $patterns as $needle ) {
			$like =
				'%' .
				$wpdb->esc_like(
					$needle
				) .
				'%';

			$found =
				$wpdb->get_var(
					$wpdb->prepare(
						"
						SELECT ID
						FROM {$wpdb->posts}
						WHERE ID <> %d
						AND post_status NOT IN ('trash','auto-draft','inherit')
						AND (
							post_content LIKE %s
							OR post_excerpt LIKE %s
							OR post_title LIKE %s
						)
						LIMIT 1
						",
						$attachment_id,
						$like,
						$like,
						$like
					)
				);

			if ( $found ) {
				return true;
			}
		}

		// Перевірка meta
		$found =
			$wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT post_id
					FROM {$wpdb->postmeta}
					WHERE
						meta_value = %s
						OR meta_value LIKE %s
					LIMIT 1
					",
					(string) $attachment_id,
					'%\"' .
					$wpdb->esc_like(
						(string) $attachment_id
					) .
					'\"%'
				)
			);

		return (bool) $found;
	}

	/**
	 * Перевірка attachment
	 */
	private function is_attachment_unused(
		$id
	) {
		global $wpdb;

		$count =
			$wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(*)
					FROM {$wpdb->postmeta}
					WHERE
						meta_key IN (
							'_thumbnail_id'
						)
						AND meta_value = %s
					",
					(string) $id
				)
			);

		return ! $count;
	}

	/**
	 * Сканування невикористовуваних
	 */
	public function ajax_scan_unused() {
		$this->check_nonce();

		$posts =
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'all',
				)
			);

		$items = array();

		foreach ( $posts as $post ) {
			if (
				$this->post_references_media(
					$post->ID
				)
			) {
				continue;
			}

			if (
				$this->is_attachment_unused(
					$post->ID
				)
			) {
				$items[] =
					array(
						'id'   => $post->ID,
						'name' => get_the_title( $post->ID ),
						'url'  => wp_get_attachment_url( $post->ID ),
					);
			}
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'count' => count( $items ),
			)
		);
	}

	/**
	 * Остаточне видалення
	 */
	public function ajax_delete_permanently() {
		$this->check_nonce();

		$ids =
			(array) (
				$_POST['ids'] ?? array()
			);

		$deleted = 0;
		$errors  = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			// Перевіряємо, чи файл у "Непотріб"
			$terms = wp_get_object_terms( $id, self::TAX );
			$in_trash = false;
			if ( ! is_wp_error( $terms ) && $terms ) {
				foreach ( $terms as $term ) {
					if ( $term->slug === self::TRASH_FOLDER ) {
						$in_trash = true;
						break;
					}
				}
			}

			if ( ! $in_trash ) {
				$errors[] = 'ID ' . $id . ' не знаходиться в «Непотріб»';
				continue;
			}

			$result =
				wp_delete_attachment(
					$id,
					true
				);

			if ( $result ) {
				$deleted++;
			} else {
				$errors[] =
					'Не вдалося видалити ID ' .
					$id;
			}
		}

		if ( $errors ) {
			wp_send_json_error(
				array(
					'deleted' => $deleted,
					'errors'  => $errors,
				)
			);
		}

		wp_send_json_success(
			array(
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * Відносний шлях файлу для ZIP
	 */
	private function folder_relative_file(
		$id,
		$file
	) {
		$terms =
			wp_get_object_terms(
				$id,
				self::TAX
			);

		if (
			is_wp_error( $terms ) ||
			! $terms
		) {
			return wp_basename(
				$file
			);
		}

		return
			trailingslashit(
				$this->folder_path(
					$terms[0]->term_id
				)
			) .
			wp_basename(
				$file
			);
	}

	/**
	 * ZIP папки Непотріб
	 */
	public function ajax_download_zip() {
		if (
			! current_user_can(
				'upload_files'
			)
		) {
			wp_die(
				'Forbidden',
				403
			);
		}

		check_ajax_referer(
			self::NONCE,
			'nonce'
		);

		if (
			! class_exists(
				'ZipArchive'
			)
		) {
			wp_die(
				'На сервері не увімкнено PHP ZipArchive.'
			);
		}

		$trash =
			get_term_by(
				'slug',
				self::TRASH_FOLDER,
				self::TAX
			);

		if ( ! $trash ) {
			wp_die(
				'Папку не знайдено.'
			);
		}

		$ids =
			get_objects_in_term(
				$trash->term_id,
				self::TAX
			);

		if (
			is_wp_error( $ids )
		) {
			wp_die(
				'Помилка.'
			);
		}

		$tmp =
			wp_tempnam(
				'ua-mediamanager-trash.zip'
			);

		$zip =
			new ZipArchive();

		if (
			$zip->open(
				$tmp,
				ZipArchive::CREATE |
				ZipArchive::OVERWRITE
			) !== true
		) {
			wp_die(
				'Не вдалося створити ZIP.'
			);
		}

		foreach ( $ids as $id ) {
			$file =
				get_attached_file(
					$id
				);

			if (
				! $file ||
				! is_file( $file )
			) {
				continue;
			}

			$relative =
				$this->folder_relative_file(
					$id,
					$file
				);

			$zip->addFile(
				$file,
				$relative
			);
		}

		$zip->close();

		nocache_headers();

		header(
			'Content-Type: application/zip'
		);

		header(
			'Content-Disposition: attachment; filename="ua-mediamanager-nepotrib-' .
			date( 'Y-m-d-His' ) .
			'.zip"'
		);

		header(
			'Content-Length: ' .
			filesize( $tmp )
		);

		readfile( $tmp );

		@unlink( $tmp );

		exit;
	}

	/**
	 * Забороняємо стандартні фізичні thumbnails
	 */
	public function disable_intermediate_sizes(
		$sizes,
		$metadata = null
	) {
		$options =
			$this->options();

		if (
			! empty(
				$options['disable_sizes']
			)
		) {
			return array();
		}

		return $sizes;
	}

	/**
	 * Забороняємо big image копію
	 */
	public function disable_big_image_downsize() {
		$options =
			$this->options();

		if (
			! empty(
				$options['disable_sizes']
			)
		) {
			return false;
		}

		return 2560;
	}

	/**
	 * Визначити необхідний розмір
	 */
	private function requested_dimensions(
		$size,
		$attachment_id
	) {
		if (
			is_array( $size )
		) {
			return array(
				absint(
					$size[0]
				),
				absint(
					$size[1]
				),
				! empty(
					$size[2]
				),
			);
		}

		if (
			$size === 'full' ||
			$size === 'original'
		) {
			return array(
				0,
				0,
				false,
			);
		}

		$sizes =
			wp_get_registered_image_subsizes();

		if (
			isset(
				$sizes[$size]
			)
		) {
			return array(
				absint(
					$sizes[$size]['width']
				),
				absint(
					$sizes[$size]['height']
				),
				! empty(
					$sizes[$size]['crop']
				),
			);
		}

		if (
			is_string( $size ) &&
			preg_match(
				'/^(\\d+)x(\\d+)$/',
				$size,
				$matches
			)
		) {
			return array(
				absint(
					$matches[1]
				),
				absint(
					$matches[2]
				),
				false,
			);
		}

		return array(
			0,
			0,
			false,
		);
	}

	/**
	 * Віртуальний розмір картинки
	 */
	public function virtual_image_src(
		$image,
		$attachment_id,
		$size,
		$icon
	) {
		$options =
			$this->options();

		if (
			empty(
				$options['virtual_sizes']
			)
		) {
			return $image;
		}

		if (
			! wp_attachment_is_image(
				$attachment_id
			)
		) {
			return $image;
		}

		list(
			$width,
			$height,
			$crop
		) =
			$this->requested_dimensions(
				$size,
				$attachment_id
			);

		if (
			! $width &&
			! $height
		) {
			return $image;
		}

		$original =
			get_attached_file(
				$attachment_id
			);

		if (
			! $original ||
			! file_exists( $original )
		) {
			return $image;
		}

		$metadata =
			wp_get_attachment_metadata(
				$attachment_id
			);

		$original_width =
			! empty(
				$metadata['width']
			)
			? (int) $metadata['width']
			: 0;

		$original_height =
			! empty(
				$metadata['height']
			)
			? (int) $metadata['height']
			: 0;

		if (
			! $original_width ||
			! $original_height
		) {
			$info =
				@getimagesize(
					$original
				);

			if ( $info ) {
				$original_width =
					$info[0];
				$original_height =
					$info[1];
			}
		}

		if (
			! $original_width ||
			! $original_height
		) {
			return $image;
		}

		if ( ! $width ) {
			$width =
				round(
					$original_width *
					(
						$height /
						$original_height
					)
				);
		}

		if ( ! $height ) {
			$height =
				round(
					$original_height *
					(
						$width /
						$original_width
					)
				);
		}

		// Не збільшуємо маленьку картинку
		if (
			$width >= $original_width &&
			$height >= $original_height
		) {
			return array(
				wp_get_attachment_url(
					$attachment_id
				),
				$original_width,
				$original_height,
				false,
			);
		}

		$url =
			add_query_arg(
				array(
					'action' =>
						'uam_dynamic_image',
					'id' =>
						$attachment_id,
					'w' =>
						$width,
					'h' =>
						$height,
					'crop' =>
						$crop
							? 1
							: 0,
				),
				admin_url(
					'admin-ajax.php'
				)
			);

		return array(
			$url,
			$width,
			$height,
			true,
		);
	}

	/**
	 * Вимикаємо стандартний srcset
	 */
	public function disable_srcset_for_virtual_images(
		$sources,
		$size_array,
		$image_src,
		$image_meta,
		$attachment_id
	) {
		return array();
	}

	/**
	 * Динамічне створення зображення
	 */
	public function ajax_dynamic_image() {
		$id =
			absint(
				$_GET['id'] ?? 0
			);

		$width =
			absint(
				$_GET['w'] ?? 0
			);

		$height =
			absint(
				$_GET['h'] ?? 0
			);

		$crop =
			! empty(
				$_GET['crop']
			);

		if (
			! $id ||
			! $width ||
			! $height ||
			! wp_attachment_is_image(
				$id
			)
		) {
			wp_die(
				'Bad request',
				400
			);
		}

		$file =
			get_attached_file(
				$id
			);

		if (
			! $file ||
			! is_file( $file )
		) {
			wp_die(
				'Not found',
				404
			);
		}

		$mime =
			get_post_mime_type(
				$id
			);

		$allowed =
			array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
			);

		if (
			! in_array(
				$mime,
				$allowed,
				true
			)
		) {
			wp_die(
				'Unsupported image',
				415
			);
		}

		$editor =
			wp_get_image_editor(
				$file
			);

		if (
			is_wp_error( $editor )
		) {
			wp_die(
				'Image editor error',
				500
			);
		}

		$editor->set_quality(
			85
		);

		if ( $crop ) {
			$result =
				$editor->resize(
					$width,
					$height,
					true
				);
		} else {
			$result =
				$editor->resize(
					$width,
					$height,
					false
				);
		}

		if (
			is_wp_error(
				$result
			)
		) {
			wp_die(
				$result->get_error_message(),
				500
			);
		}

		$img =
			$editor->get_image();

		if ( ! $img ) {
			wp_die(
				'Image rendering error',
				500
			);
		}

		$quality = 85;

		nocache_headers();

		header(
			'Cache-Control: public, max-age=86400'
		);

		switch ( $mime ) {
			case 'image/jpeg':
				header(
					'Content-Type: image/jpeg'
				);
				imagejpeg(
					$img,
					null,
					$quality
				);
				break;

			case 'image/png':
				header(
					'Content-Type: image/png'
				);
				imagepng(
					$img,
					null,
					6
				);
				break;

			case 'image/gif':
				header(
					'Content-Type: image/gif'
				);
				imagegif(
					$img
				);
				break;

			case 'image/webp':
				header(
					'Content-Type: image/webp'
				);
				imagewebp(
					$img,
					null,
					$quality
				);
				break;
		}

		if (
			function_exists(
				'imagedestroy'
			)
		) {
			imagedestroy(
				$img
			);
		}

		exit;
	}
}

// Додаємо AJAX для отримання ID папки "Непотріб"
add_action(
	'wp_ajax_uam_get_trash_id',
	array( 'UA_MediaManager', 'ajax_get_trash_id' )
);

new UA_MediaManager();