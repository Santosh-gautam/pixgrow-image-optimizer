<?php

/**
 * Admin class to handle menu pages, asset enqueuing, and rendering dashboard.
 *
 * @package PixGrow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PixGrow_Admin
{

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Register menu page
		add_action('admin_menu', array($this, 'add_admin_menu'));
		// Enqueue scripts and styles
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		// AJAX endpoint for fetching library status
		add_action('wp_ajax_pixgrow_get_library_stats', array($this, 'ajax_get_library_stats'));
		// AJAX endpoint for saving uninstall settings
		add_action('wp_ajax_pixgrow_save_uninstall_setting', array($this, 'ajax_save_uninstall_setting'));
		// AJAX endpoint for saving all settings
		add_action('wp_ajax_pixgrow_save_all_settings', array($this, 'ajax_save_all_settings'));
		// AJAX endpoint for resetting settings to defaults
		add_action('wp_ajax_pixgrow_reset_settings', array($this, 'ajax_reset_settings'));
		// AJAX endpoint to save bulk queue state
		add_action('wp_ajax_pixgrow_save_queue_state', array($this, 'ajax_save_queue_state'));
		// AJAX endpoint to retrieve bulk queue state
		add_action('wp_ajax_pixgrow_get_queue_state', array($this, 'ajax_get_queue_state'));
		// AJAX endpoint to extend concurrency lock (heartbeat lock refresh)
		add_action('wp_ajax_pixgrow_heartbeat_lock', array($this, 'ajax_heartbeat_lock'));
		// AJAX endpoint to fetch system diagnostics
		add_action('wp_ajax_pixgrow_get_diagnostics', array($this, 'ajax_get_diagnostics'));
		// AJAX endpoint to retrieve system logs
		add_action('wp_ajax_pixgrow_get_logs', array($this, 'ajax_get_logs'));
		// AJAX endpoint to clear system logs
		add_action('wp_ajax_pixgrow_clear_logs', array($this, 'ajax_clear_logs'));
		// AJAX endpoints for paginated lists
		add_action('wp_ajax_pixgrow_get_queue_page', array($this, 'ajax_get_queue_page'));
		add_action('wp_ajax_pixgrow_get_history_page', array($this, 'ajax_get_history_page'));
	}

	/**
	 * Adds PixGrow to the WordPress Admin Menu.
	 */
	public function add_admin_menu()
	{
		$menu_title = apply_filters('pixgrow_menu_title', __('PixGrow', 'pixgrow-image-optimizer'));

		add_menu_page(
			__('PixGrow', 'pixgrow-image-optimizer'),
			$menu_title,
			'manage_options',
			'pixgrow-image-optimizer',
			array($this, 'render_dashboard_page'),
			'dashicons-performance',
			80
		);

		add_submenu_page(
			'pixgrow-image-optimizer',
			__('Bulk Compressor', 'pixgrow-image-optimizer'),
			__('Bulk Compressor', 'pixgrow-image-optimizer'),
			'manage_options',
			'pixgrow-image-optimizer',
			array($this, 'render_dashboard_page')
		);

		add_submenu_page(
			'pixgrow-image-optimizer',
			__('Documentation', 'pixgrow-image-optimizer'),
			__('Documentation', 'pixgrow-image-optimizer'),
			'manage_options',
			'pixgrow-docs',
			array($this, 'render_docs_page')
		);
	}

	/**
	 * Enqueues CSS and JS assets for the PixGrow admin panel.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets($hook)
	{
		if ( false === stripos( $hook, 'pixgrow' ) ) {
			return;
		}

		// Load Freemius assets for the Account tab
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ('account' === $active_tab && function_exists('pixgrow_pro_fs')) {
			$fs = pixgrow_pro_fs();
			if (is_object($fs) && method_exists($fs, '_account_page_load')) {
				$fs->_account_page_load();
			}
		}

		// CSS
		wp_enqueue_style(
			'PixGrow-admin-css',
			PIXGROW_URL . 'assets/css/admin.css',
			array(),
			PIXGROW_VERSION
		);

		// JS
		wp_enqueue_script(
			'pixgrow-admin',
			PIXGROW_URL . 'assets/js/admin.js',
			array('jquery'),
			PIXGROW_VERSION,
			true
		);

		$vars = array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'security' => wp_create_nonce('pixgrow_nonce'),
			'is_pro' => class_exists('PixGrow_Pro_Features'), // Detect Pro activation
			'is_pro_licensed' => class_exists('PixGrow_Pro_Features') && function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed(),
			'worker_url' => '',
			'replace_confirm' => (int) pixgrow_get_setting('replace_confirm', 1),
			'format' => pixgrow_get_pro_setting('format', 'webp'),
			'quality' => (int) pixgrow_get_pro_setting('quality', 80),
			'resize' => (int) pixgrow_get_pro_setting('resize', 0),
			'max_width' => (int) pixgrow_get_pro_setting('max_width', 1920),
			'sizes' => $this->get_registered_image_sizes(),
			'messages' => array(
				'confirm_restore' => __('Are you sure you want to restore this image to its original quality?', 'pixgrow-image-optimizer'),
				'batch_limit_reached' => __('Free version batch limit reached (20 images). Upgrade to Pro for unlimited batch compression or click Continue to process the next batch.', 'pixgrow-image-optimizer'),
				'optimize_now' => __('Optimize Images Now', 'pixgrow-image-optimizer')
			)
		);

		// Allow Pro addon to extend these parameters
		$vars = apply_filters('pixgrow_localize_vars', $vars);

		wp_localize_script(
			'pixgrow-admin',
			'pixgrow_vars',
			$vars
		);
	}

	/**
	 * Retrieve all registered image sizes with width, height, and crop parameters.
	 *
	 * @return array Registered image sizes.
	 */
	private function get_registered_image_sizes() {
		global $_wp_additional_image_sizes;
		$sizes = array();
		$default_keys = array( 'thumbnail', 'medium', 'medium_large', 'large' );
		foreach ( $default_keys as $size ) {
			$sizes[ $size ] = array(
				'width'  => (int) get_option( "{$size}_size_w" ),
				'height' => (int) get_option( "{$size}_size_h" ),
				'crop'   => (bool) get_option( "{$size}_crop" ),
			);
		}
		if ( ! empty( $_wp_additional_image_sizes ) ) {
			foreach ( $_wp_additional_image_sizes as $size => $size_args ) {
				$sizes[ $size ] = array(
					'width'  => (int) $size_args['width'],
					'height' => (int) $size_args['height'],
					'crop'   => (bool) $size_args['crop'],
				);
			}
		}
		return $sizes;
	}

	/**
	 * AJAX endpoint to fetch image stats.
	 */
	public function ajax_get_library_stats()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		// Track developer telemetry
		$start_time = microtime(true);
		$start_mem = memory_get_peak_usage();
		$initial_queries = $GLOBALS['wpdb']->num_queries;

		$stats = $this->get_library_stats();

		$duration = (microtime(true) - $start_time) * 1000;
		$end_mem = memory_get_peak_usage();
		$queries_run = $GLOBALS['wpdb']->num_queries - $initial_queries;

		$stats['telemetry'] = array(
			'time_ms' => round($duration, 2),
			'queries' => $queries_run,
			'peak_mem_mb' => round($end_mem / 1024 / 1024, 2)
		);

		wp_send_json_success($stats);
	}

	/**
	 * AJAX endpoint to get a paginated unoptimized queue segment.
	 */
	public function ajax_get_queue_page()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		global $wpdb;

		// Telemetry
		$start_time = microtime(true);
		$start_mem = memory_get_peak_usage();
		$initial_queries = $wpdb->num_queries;

		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$per_page = isset($_POST['per_page']) ? absint( wp_unslash($_POST['per_page']) ) : 20;
		if (!in_array($per_page, array(20, 50, 100), true)) {
			$per_page = 20;
		}

		$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
		$format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : 'all';
		$include = isset($_POST['include']) ? array_map('intval', (array) $_POST['include']) : array();
		$exclude = isset($_POST['exclude']) ? array_map('intval', (array) $_POST['exclude']) : array();

		$offset = ($page - 1) * $per_page;

		$args = array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $per_page,
			'offset' => $offset,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'DESC',
		);

		$post_mime_types = array();
		if ('jpg' === $format) {
			$post_mime_types[] = 'image/jpeg';
		} elseif ('png' === $format) {
			$post_mime_types[] = 'image/png';
		} else {
			$post_mime_types = array('image/jpeg', 'image/png');
		}
		$args['post_mime_type'] = $post_mime_types;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'] = array(
			'relation' => 'AND',
			array(
				'key' => '_pixgrow_optimized',
				'compare' => 'NOT EXISTS',
			),
			array(
				'relation' => 'OR',
				array(
					'key' => '_pixgrow_invalid_skipped',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => '_pixgrow_invalid_skipped',
					'value' => '1',
					'compare' => '!=',
				),
			),
		);

		if (!empty($include)) {
			$args['post__in'] = $include;
		}
		if (!empty($exclude)) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			$args['post__not_in'] = $exclude;
		}
		if (!empty($search)) {
			$args['s'] = $search;
		}

		$query_obj = new WP_Query($args);
		$results = $query_obj->posts;
		$total_items = (int) $query_obj->found_posts;

		if (!empty($results)) {
			get_posts(array(
				'post__in' => $results,
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'posts_per_page' => count($results),
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			));
		}

		$list = array();
		foreach ($results as $id) {
			$id = (int) $id;
			$file_path = get_attached_file($id);

			if (!$file_path || !file_exists($file_path) || !is_readable($file_path)) {
				update_post_meta($id, '_pixgrow_invalid_skipped', '1');
				continue;
			}

			delete_post_meta($id, '_pixgrow_invalid_skipped');

			$list[] = array(
				'id' => $id,
				'url' => wp_get_attachment_url($id),
				'thumbnail_url' => pixgrow_get_attachment_thumb_base64($id) ?: wp_get_attachment_thumb_url($id) ?: wp_get_attachment_url($id),
				'name' => basename($file_path),
				'title' => get_the_title($id) ?: basename($file_path),
				'size' => size_format(filesize($file_path)),
				'used_in' => pixgrow_get_image_usage($id)
			);
		}

		$duration = (microtime(true) - $start_time) * 1000;
		$end_mem = memory_get_peak_usage();
		$queries_run = $wpdb->num_queries - $initial_queries;

		wp_send_json_success(array(
			'items' => $list,
			'total_items' => $total_items,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($total_items / $per_page),
			'telemetry' => array(
				'time_ms' => round($duration, 2),
				'queries' => $queries_run,
				'peak_mem_mb' => round($end_mem / 1024 / 1024, 2)
			)
		));
	}

	/**
	 * AJAX endpoint to get a paginated optimized history segment.
	 */
	public function ajax_get_history_page()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		global $wpdb;

		// Telemetry
		$start_time = microtime(true);
		$start_mem = memory_get_peak_usage();
		$initial_queries = $wpdb->num_queries;

		$page = isset($_POST['page']) ? max(1, absint(wp_unslash($_POST['page']))) : 1;
		$per_page = isset($_POST['per_page']) ? absint(wp_unslash($_POST['per_page'])) : 20;
		if (!in_array($per_page, array(20, 50, 100, 99999), true)) {
			$per_page = 20;
		}

		$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
		$format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : 'all';
		$date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
		$date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';

		$offset = ($page - 1) * $per_page;

		$args = array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $per_page,
			'offset' => $offset,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'DESC',
		);

		$post_mime_types = array();
		if ('all' !== $format) {
			$mime_types = array(
				'webp' => 'image/webp',
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png' => 'image/png'
			);
			if (isset($mime_types[$format])) {
				$post_mime_types[] = $mime_types[$format];
			}
		} else {
			$post_mime_types = array('image/webp', 'image/jpeg', 'image/png');
		}
		$args['post_mime_type'] = $post_mime_types;

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key' => '_pixgrow_optimized',
				'value' => '1',
			),
		);

		if (!empty($date_from)) {
			$meta_query[] = array(
				'key' => '_pixgrow_optimized_time',
				'value' => $date_from . ' 00:00:00',
				'compare' => '>=',
				'type' => 'DATETIME',
			);
		}
		if (!empty($date_to)) {
			$meta_query[] = array(
				'key' => '_pixgrow_optimized_time',
				'value' => $date_to . ' 23:59:59',
				'compare' => '<=',
				'type' => 'DATETIME',
			);
		}
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'] = $meta_query;

		if (!empty($search)) {
			$args['s'] = $search;
		}

		$query_obj = new WP_Query($args);
		$results = $query_obj->posts;
		$total_items = (int) $query_obj->found_posts;

		if (!empty($results)) {
			get_posts(array(
				'post__in' => $results,
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'posts_per_page' => count($results),
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			));
		}

		$list = array();
		foreach ($results as $id) {
			$id = (int) $id;
			$file_path = get_attached_file($id);
			$sizes = pixgrow_get_attachment_sizes($id);
			$opt_time = get_post_meta($id, '_pixgrow_optimized_time', true);
			if (!$opt_time) {
				$post = get_post($id);
				$opt_time = $post ? $post->post_date : '';
			}

			$list[] = array(
				'id' => $id,
				'url' => wp_get_attachment_url($id),
				'thumbnail_url' => pixgrow_get_attachment_thumb_base64($id) ?: wp_get_attachment_thumb_url($id) ?: wp_get_attachment_url($id),
				'name' => ($file_path && '' !== $file_path) ? basename($file_path) : get_the_title($id),
				'title' => get_the_title($id) ?: (($file_path && '' !== $file_path) ? basename($file_path) : ''),
				'size' => size_format($sizes['compressed']),
				'orig_size' => size_format($sizes['original']),
				'savings' => $sizes['savings'] . '%',
				'opt_time' => $opt_time,
				'used_in' => pixgrow_get_image_usage($id)
			);
		}

		$duration = (microtime(true) - $start_time) * 1000;
		$end_mem = memory_get_peak_usage();
		$queries_run = $wpdb->num_queries - $initial_queries;

		wp_send_json_success(array(
			'items' => $list,
			'total_items' => $total_items,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($total_items / $per_page),
			'telemetry' => array(
				'time_ms' => round($duration, 2),
				'queries' => $queries_run,
				'peak_mem_mb' => round($end_mem / 1024 / 1024, 2)
			)
		));
	}

	/**
	 * AJAX endpoint to save uninstall data removal preferences.
	 */
	public function ajax_save_uninstall_setting()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$post_data = wp_unslash($_POST);
		$delete_data = isset($post_data['delete_data']) ? absint($post_data['delete_data']) : 0;
		update_option('pixgrow_delete_data_on_uninstall', $delete_data);

		wp_send_json_success(array('message' => __('Uninstall setting updated.', 'pixgrow-image-optimizer')));
	}

	/**
	 * Compiles statistics about the Media Library.
	 *
	 * @return array
	 */
	private function get_library_stats()
	{
		global $wpdb;

		// 1. Get total optimized images
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$optimized_images = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				'_pixgrow_optimized'
			)
		);

		// 2. Get total invalid skipped count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$skipped_invalid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				'_pixgrow_invalid_skipped'
			)
		);

		// 3. Get total unoptimized count (images in wp_posts of type attachment that do not have optimized meta key)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unoptimized_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
				 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s 
				 WHERE p.post_type = 'attachment' 
				 AND p.post_mime_type IN ('image/jpeg', 'image/png') 
				 AND pm.meta_value IS NULL",
				'_pixgrow_optimized'
			)
		);

		// We subtract skipped_invalid from unoptimized_count for the "Needs Optimization" count
		$needs_optimization = max(0, $unoptimized_count - $skipped_invalid);

		// Total images = optimized + unoptimized
		$total_images = $optimized_images + $unoptimized_count;

		// Get first 100 attachments for scanner dropdown
		$scan_images = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$scan_results = $wpdb->get_results(
			"SELECT p.ID, p.post_title, pm.meta_value as file_path 
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' 
			 AND p.post_mime_type IN ('image/jpeg', 'image/png') 
			 ORDER BY p.ID DESC LIMIT 100"
		);
		if (!empty($scan_results)) {
			foreach ($scan_results as $post) {
				$scan_images[] = array(
					'id' => (int) $post->ID,
					'name' => $post->file_path ? basename($post->file_path) : $post->post_title
				);
			}
		}
		return array(
			'total' => $total_images,
			'optimized' => $optimized_images,
			'unoptimized' => $needs_optimization,
			'skipped_invalid' => $skipped_invalid,
			'scan_images' => $scan_images,
			'queue' => array(), // Send empty queue here since the UI now fetches pages dynamically
			'history' => array()  // Send empty history here since the UI now fetches pages dynamically
		);
	}

	public function render_dashboard_page()
	{
		$pixgrow_pro_settings = get_option('pixgrow_pro_settings', array());
		$pixgrow_quality = isset($pixgrow_pro_settings['quality']) ? (int) $pixgrow_pro_settings['quality'] : 80;
		$pixgrow_format = isset($pixgrow_pro_settings['format']) ? $pixgrow_pro_settings['format'] : 'webp';
		$pixgrow_resize = isset($pixgrow_pro_settings['resize']) ? (int) $pixgrow_pro_settings['resize'] : 0;
		$pixgrow_max_width = isset($pixgrow_pro_settings['max_width']) ? (int) $pixgrow_pro_settings['max_width'] : 1920;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
		if (class_exists('PixGrow_Pro_Features') && 'pricing' === $active_tab) {
			$active_tab = 'account';
		}
		if (!in_array($active_tab, array('dashboard', 'scanner', 'history', 'logs', 'settings', 'pricing', 'account', 'diagnostics'), true)) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap PixGrow-wrap pixgrow-wrap wasmpress-wrap">
			<?php
			$is_pro_installed = class_exists('PixGrow_Pro_Features');
			$is_pro_licensed = $is_pro_installed && function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed();
			if ($is_pro_installed && !$is_pro_licensed):
				$license_url = admin_url('admin.php?page=pixgrow-image-optimizer&tab=account');
				?>
				<div class="pixgrow-license-ribbon wasmpress-license-ribbon">
					<div class="ribbon-text" style="display: flex; align-items: center; gap: 8px;">
						<span style="font-size: 16px;">🔑</span>
						<span>
							<strong><?php esc_html_e('PixGrow Pro is active but unlicensed.', 'pixgrow-image-optimizer'); ?></strong>
							<?php esc_html_e('Activate your license to unlock automation and static replacements.', 'pixgrow-image-optimizer'); ?>
						</span>
					</div>
					<a href="<?php echo esc_url($license_url); ?>" class="button button-primary" style="background: linear-gradient(135deg, #f59e0b, #d97706) !important; border: none; color: #000000 !important; font-weight: 700; border-radius: 6px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); text-decoration: none; padding: 4px 12px !important; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; white-space: nowrap; height: 28px !important; margin: 0; box-sizing: border-box;">
						<?php esc_html_e('Activate License', 'pixgrow-image-optimizer'); ?>
					</a>
				</div>
			<?php endif; ?>
			<!-- Glassmorphic Dashboard Header -->
			<div class="PixGrow-header pixgrow-header wasmpress-header">
				<div class="PixGrow-branding pixgrow-branding wasmpress-branding">
					<!-- <span class="dashicons dashicons-performance header-icon"></span> -->
					<img src="<?php echo esc_url(PIXGROW_URL . 'assets/images/pixgrow-logo-1.png'); ?>" alt="PixGrow Logo"
						class="header-icon">
					<?php
					$is_pro_installed = class_exists('PixGrow_Pro_Features');
					$is_pro_licensed = $is_pro_installed && function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed();

					if ($is_pro_installed):
						?>
						<h1><span class="badge" style="background: linear-gradient(135deg, #818cf8, #6366f1) !important; color:#ffffff !important; box-shadow: 0 0 10px rgba(99, 102, 241, 0.3); border: 1px solid rgba(99, 102, 241, 0.5);"><?php esc_html_e('PRO', 'pixgrow-image-optimizer'); ?></span>
							<?php if ($is_pro_licensed): ?>
								<span class="badge"
									style="background:#22c55e !important; color:#ffffff !important; box-shadow: 0 0 10px rgba(34, 197, 94, 0.3); border: 1px solid rgba(34, 197, 94, 0.5);"><?php esc_html_e('Active', 'pixgrow-image-optimizer'); ?></span>
							<?php else: ?>
								<span class="badge"
									style="background:#eab308 !important; color:#000000 !important; box-shadow: 0 0 10px rgba(234, 179, 8, 0.3); border: 1px solid rgba(234, 179, 8, 0.5);"><?php esc_html_e('Unlicensed', 'pixgrow-image-optimizer'); ?></span>
							<?php endif; ?>
						</h1>
					<?php else: ?>
						<h1><span class="badge"><?php esc_html_e('Free', 'pixgrow-image-optimizer'); ?></span></h1>
					<?php endif; ?>
				</div>
				<h2 class="PixGrow-hero-tagline pixgrow-hero-tagline wasmpress-hero-tagline"
					style="margin: 6px 0 2px 0; color: #ffffff; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.2px;">
					<?php esc_html_e('Zero-Server-CPU Image Optimization', 'pixgrow-image-optimizer'); ?></h2>
				<p class="description" style="margin: 0; color: #94a3b8; font-size: 0.95rem;">
					<?php esc_html_e('WebAssembly-powered browser compression.', 'pixgrow-image-optimizer'); ?></p>
			</div>



			<?php
			$active_indicator = $is_pro_licensed ? '<span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981; margin-left:6px; vertical-align:middle; box-shadow:0 0 5px #10b981;"></span>' : '';
			?>
			<h2 class="nav-tab-wrapper PixGrow-nav-tab-wrapper pixgrow-nav-tab-wrapper wasmpress-nav-tab-wrapper">
				<a href="?page=pixgrow-image-optimizer&tab=dashboard"
					class="nav-tab <?php echo esc_attr( 'dashboard' === $active_tab ? 'nav-tab-active' : '' ); ?>"
					data-tab="dashboard"><span class="dashicons dashicons-dashboard"></span>
					<?php esc_html_e('Bulk Compressor', 'pixgrow-image-optimizer'); ?>		<?php echo wp_kses_post($active_indicator); ?></a>
				<a href="?page=pixgrow-image-optimizer&tab=scanner"
					class="nav-tab <?php echo esc_attr( 'scanner' === $active_tab ? 'nav-tab-active' : '' ); ?>" data-tab="scanner"><span
						class="dashicons dashicons-search"></span>
					<?php esc_html_e('Static Path Scanner', 'pixgrow-image-optimizer'); ?>		<?php echo wp_kses_post($active_indicator); ?></a>
				<a href="?page=pixgrow-image-optimizer&tab=history"
					class="nav-tab <?php echo esc_attr( 'history' === $active_tab ? 'nav-tab-active' : '' ); ?>" data-tab="history"><span
						class="dashicons dashicons-backup"></span>
					<?php esc_html_e('History & Restore', 'pixgrow-image-optimizer'); ?>		<?php echo wp_kses_post($active_indicator); ?></a>
				<a href="?page=pixgrow-image-optimizer&tab=logs"
					class="nav-tab <?php echo esc_attr( 'logs' === $active_tab ? 'nav-tab-active' : '' ); ?>" data-tab="logs"><span
						class="dashicons dashicons-list-view"></span>
					<?php esc_html_e('Optimization Logs', 'pixgrow-image-optimizer'); ?></a>
				<a href="?page=pixgrow-image-optimizer&tab=diagnostics"
					class="nav-tab <?php echo esc_attr( 'diagnostics' === $active_tab ? 'nav-tab-active' : '' ); ?>"
					data-tab="diagnostics"><span class="dashicons dashicons-admin-tools"></span>
					<?php esc_html_e('Diagnostics & System Logs', 'pixgrow-image-optimizer'); ?></a>
				<a href="?page=pixgrow-image-optimizer&tab=settings"
					class="nav-tab <?php echo esc_attr( 'settings' === $active_tab ? 'nav-tab-active' : '' ); ?>" data-tab="settings"><span
						class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e('Settings', 'pixgrow-image-optimizer'); ?></a>
				<?php if (class_exists('PixGrow_Pro_Features')): ?>
					<a href="?page=pixgrow-image-optimizer&tab=account"
						class="nav-tab nav-tab-pro <?php echo esc_attr( 'account' === $active_tab ? 'nav-tab-active' : '' ); ?>"
						data-tab="account"><span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e('Account', 'pixgrow-image-optimizer'); ?>			<?php echo wp_kses_post($active_indicator); ?></a>
				<?php else: ?>
					<a href="?page=pixgrow-image-optimizer&tab=pricing"
						class="nav-tab nav-tab-pro <?php echo esc_attr( 'pricing' === $active_tab ? 'nav-tab-active' : '' ); ?>"
						data-tab="pricing"><span class="dashicons dashicons-star-filled"></span>
						<?php esc_html_e('Go Premium (Pro)', 'pixgrow-image-optimizer'); ?></a>
				<?php endif; ?>
			</h2>

			<div class="PixGrow-content-wrapper pixgrow-content-wrapper wasmpress-content-wrapper">

				<?php if ('dashboard' === $active_tab): ?>
					<!-- TAB 1: DASHBOARD & BULK COMPRESSOR -->
					<div id="tab-dashboard" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
						<div class="PixGrow-dashboard-hero-grid pixgrow-dashboard-hero-grid wasmpress-dashboard-hero-grid"
							style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 24px; margin-bottom: 24px; align-items: stretch;">

							<!-- Hero Left Action Card -->
							<div class="PixGrow-card pixgrow-card wasmpress-card PixGrow-hero-action-card pixgrow-hero-action-card wasmpress-hero-action-card"
								style="display: flex; flex-direction: column; justify-content: space-between; padding: 24px; height: 100%; box-sizing: border-box;">
								<div>
									<h3
										style="margin-top: 0; margin-bottom: 10px; font-size: 1.3rem; font-weight: 700; color: #ffffff;">
										<?php esc_html_e('Optimize Your Media Library', 'pixgrow-image-optimizer'); ?></h3>
									<p style="margin: 0 0 20px 0; color: #cbd5e1; font-size: 0.95rem; line-height: 1.5;">
										<?php esc_html_e('Improve image performance without slowing your server.', 'pixgrow-image-optimizer'); ?>
									</p>
								</div>

								<!-- Action Buttons Area -->
								<div class="bulk-action-section"
									style="margin: 0; padding: 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
									<button id="btn-start-bulk" class="button button-primary button-hero btn-glow"
										style="margin: 0; padding: 10px 24px; height: auto; font-weight: 700; font-size: 1rem;"><span
											class="dashicons dashicons-image-rotate"
											style="vertical-align: middle; margin-top: -3px; margin-right: 6px;"></span><?php esc_html_e('Optimize Images Now', 'pixgrow-image-optimizer'); ?></button>
									<button id="btn-stop-bulk" class="button button-secondary button-hero"
										style="display:none; margin: 0; padding: 10px 24px; height: auto; font-weight: 700; font-size: 1rem;"><span
											class="dashicons dashicons-controls-pause"
											style="vertical-align: middle; margin-top: -3px; margin-right: 6px;"></span><?php esc_html_e('Pause Queue', 'pixgrow-image-optimizer'); ?></button>

									<a href="admin.php?page=pixgrow-image-optimizer&tab=history" id="btn-view-history"
										class="button button-secondary button-hero"
										style="margin: 0; padding: 10px 24px; height: auto; font-weight: 600; font-size: 1rem; border: 1px solid rgba(255, 255, 255, 0.15) !important; color: #cbd5e1 !important; display: inline-flex; align-items: center; text-decoration: none; line-height: 1.3;"><span
											class="dashicons dashicons-backup"
											style="margin-right: 6px; font-size: 1.15rem; width: 1.15rem; height: 1.15rem; vertical-align: middle;"></span><?php esc_html_e('View History', 'pixgrow-image-optimizer'); ?></a>
								</div>
							</div>

							<!-- Hero Right Stats Card -->
							<div class="PixGrow-card pixgrow-card wasmpress-card PixGrow-stats-card pixgrow-stats-card wasmpress-stats-card"
								style="padding: 24px; height: 100%; box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between;">
								<h3
									style="margin-top: 0; margin-bottom: 12px; font-size: 1.1rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
									<?php esc_html_e('Media Library Stats', 'pixgrow-image-optimizer'); ?></h3>
								<div class="stats-grid" style="margin: 0; grid-template-columns: repeat(4, 1fr) !important;">
									<div class="stat-box">
										<span class="stat-number" id="stats-total">-</span>
										<span
											class="stat-label"><?php esc_html_e('Total Images', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="stat-box">
										<span class="stat-number" id="stats-optimized">-</span>
										<span
											class="stat-label"><?php esc_html_e('Optimized', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="stat-box">
										<span class="stat-number text-highlight" id="stats-unoptimized">-</span>
										<span
											class="stat-label"><?php esc_html_e('Needs Optimization', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="stat-box">
										<span class="stat-number" id="stats-skipped" style="color: #f87171 !important;">-</span>
										<span
											class="stat-label"><?php esc_html_e('Invalid Skipped', 'pixgrow-image-optimizer'); ?></span>
									</div>
								</div>
							</div>

						</div>

						<!-- Progress Bar -->
						<div class="progress-section" style="display:none;">
							<div class="progress-info">
								<span
									id="progress-status"><?php esc_html_e('Compressing...', 'pixgrow-image-optimizer'); ?></span>
								<span id="progress-percent">0%</span>
							</div>
							<div class="progress-bar-bg">
								<div class="progress-bar-fill" style="width: 0%;"></div>
							</div>
						</div>

						<!-- Batch Limit Notice -->
						<div class="pixgrow-limit-notice" style="display:none; margin-top: 15px; padding: 16px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px;">
							<div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
								<div style="display: flex; align-items: center; gap: 10px;">
									<span class="dashicons dashicons-yes-alt" style="color: #fbbf24; font-size: 1.5rem; width: 1.5rem; height: 1.5rem;"></span>
									<span style="color: #cbd5e1; font-size: 0.95rem; font-weight: 500;">
										<?php esc_html_e('5 images processed successfully. Continue with next batch?', 'pixgrow-image-optimizer'); ?>
									</span>
								</div>
								<div style="display: flex; gap: 10px; align-items: center;">
									<button id="btn-continue-batch" class="button button-primary" style="background: #fbbf24; border-color: #f59e0b; color: #0f172a; font-weight: 700; border-radius: 6px; padding: 4px 16px; height: 30px; line-height: 28px;">
										<?php esc_html_e('Continue Batch', 'pixgrow-image-optimizer'); ?>
									</button>
									<a href="admin.php?page=pixgrow-image-optimizer&tab=pricing" class="button button-secondary" style="border-color: rgba(255, 255, 255, 0.15) !important; color: #ffffff !important; border-radius: 6px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; height: 30px;">
										<span class="dashicons dashicons-star-filled" style="color: #fbbf24; font-size: 12px; margin-right: 4px;"></span>
										<?php esc_html_e('Upgrade to Pro', 'pixgrow-image-optimizer'); ?>
									</a>
								</div>
							</div>
						</div>

						<!-- Real-time Console Log -->
						<div class="PixGrow-console-log pixgrow-console-log wasmpress-console-log" style="display:none; margin-top:15px;">
							<h5
								style="margin: 0 0 8px 0; color:#94a3b8; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:6px;">
								<span class="dashicons dashicons-editor-code"
									style="font-size:1rem; width:1rem; height:1rem;"></span>
								<?php esc_html_e('Live Optimization Logs', 'pixgrow-image-optimizer'); ?>
							</h5>
							<div class="PixGrow-log-box pixgrow-log-box wasmpress-log-box"
								style="height:150px; overflow-y:auto; background:rgba(15, 23, 42, 0.6); border:1px solid rgba(255, 255, 255, 0.05); border-radius:6px; padding:12px; font-family:monospace; font-size:0.8rem; line-height:1.4; color:#38bdf8;">
								<div class="log-row info">
									<?php esc_html_e('[System] Initializing bulk compression queue...', 'pixgrow-image-optimizer'); ?>
								</div>
							</div>
						</div>

					</div>

					<!-- Queue Table -->
					<div class="PixGrow-card pixgrow-card wasmpress-card queue-list-card table-card">
						<h3><?php esc_html_e('Optimization Queue', 'pixgrow-image-optimizer'); ?></h3>

						<!-- Queue Search and Filter Controls -->
						<div class="queue-controls-bar"
							style="display:flex; justify-content:space-between; align-items:center; margin: 15px 24px 10px 24px; flex-wrap: wrap; gap: 10px;">
							<div class="queue-search-box" style="position:relative;">
								<span class="dashicons dashicons-search"
									style="color:#94a3b8; position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:1.1rem; pointer-events:none;"></span>
								<input type="text" id="queue-search-input"
									placeholder="<?php esc_attr_e('Search queue...', 'pixgrow-image-optimizer'); ?>"
									style="padding-left:32px; width:220px; border-radius:6px; height:36px; font-size:0.9rem;">
							</div>
							<div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
								<div class="queue-filter-box">
									<select id="queue-format-filter"
										style="border-radius:6px; height:36px; font-size:0.9rem; padding: 0 10px; width:150px;">
										<option value="all"><?php esc_html_e('All Formats', 'pixgrow-image-optimizer'); ?>
										</option>
										<option value="jpg"><?php esc_html_e('JPEG / JPG', 'pixgrow-image-optimizer'); ?></option>
										<option value="png"><?php esc_html_e('PNG', 'pixgrow-image-optimizer'); ?></option>
									</select>
								</div>
								<div class="queue-pagesize-box">
									<select id="queue-per-page"
										style="border-radius:6px; height:36px; font-size:0.9rem; padding: 0 10px; width:100px; box-sizing:border-box;">
										<option value="20">20 / page</option>
										<option value="50">50 / page</option>
										<option value="100">100 / page</option>
									</select>
								</div>
								<div class="queue-pagination-info" style="color:#94a3b8; font-size:0.9rem; font-weight:500;"></div>
							</div>
						</div>

						<div class="PixGrow-table-responsive pixgrow-table-responsive wasmpress-table-responsive">
							<table class="PixGrow-table pixgrow-table wasmpress-table">
								<thead>
									<tr>
										<th scope="col" class="column-thumbnail">
											<?php esc_html_e('Thumbnail', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Filename', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Original Size', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Used In', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-status">
											<?php esc_html_e('Status', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-actions">
											<?php esc_html_e('Actions', 'pixgrow-image-optimizer'); ?></th>
									</tr>
								</thead>
								<tbody id="queue-tbody">
									<tr>
										<td colspan="6" class="text-center">
											<?php esc_html_e('Loading library data...', 'pixgrow-image-optimizer'); ?></td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Queue Pagination Buttons Bar -->
						<div class="queue-pagination-bar"
							style="display:none; justify-content:center; align-items:center; gap:6px; margin: 20px 24px 24px 24px;">
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ('scanner' === $active_tab): ?>
				<!-- TAB 2: STATIC PATH SCANNER -->
				<div id="tab-scanner" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<?php if (function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed()): ?>
						<!-- PRO version: Render fully functional scanner cards -->
						<div class="PixGrow-card pixgrow-card wasmpress-card">
							<h3><span class="dashicons dashicons-search"></span>
								<?php esc_html_e('Hardcoded Static Image Reference Scanner', 'pixgrow-image-optimizer'); ?></h3>
							<p class="description">
								<?php esc_html_e('When you compress images and change their formats (e.g. from banner.jpg to banner.webp), hardcoded links inside your posts, pages, or active theme files (PHP/CSS) might break or continue loading the old unoptimized image. Use this tool to scan and list all references.', 'pixgrow-image-optimizer'); ?>
							</p>

							<div class="scan-selection-box">
								<label
									for="scan-attachment-select"><strong><?php esc_html_e('Select Image to Scan:', 'pixgrow-image-optimizer'); ?></strong></label>
								<select id="scan-attachment-select" class="widefat">
									<!-- Dynamically populated -->
								</select>
								<button id="btn-run-scan" class="button button-primary"><span class="dashicons dashicons-search"></span>
									<?php esc_html_e('Scan for References', 'pixgrow-image-optimizer'); ?></button>
							</div>

							<div id="scan-results-container" style="display:none;">
								<hr>
								<h4 id="scan-results-title"></h4>

								<!-- DB Scan Results -->
								<div class="scan-result-section">
									<h5><span class="dashicons dashicons-database"></span>
										<?php esc_html_e('Database References (Posts/Pages)', 'pixgrow-image-optimizer'); ?></h5>
									<div id="db-scan-list">
										<p class="text-muted">
											<?php esc_html_e('No scans performed yet.', 'pixgrow-image-optimizer'); ?></p>
									</div>
								</div>

								<!-- File Scan Results -->
								<div class="scan-result-section">
									<h5><span class="dashicons dashicons-editor-code"></span>
										<?php esc_html_e('Theme Code References (.php, .css, .js)', 'pixgrow-image-optimizer'); ?>
									</h5>
									<div id="theme-scan-list">
										<p class="text-muted">
											<?php esc_html_e('No scans performed yet.', 'pixgrow-image-optimizer'); ?></p>
									</div>
								</div>

								<!-- Pro Replace Trigger Banner -->
								<?php if ( ! $is_pro_licensed ) : ?>
									<div class="pro-replace-banner-box">
										<div class="pro-replace-banner-content">
											<h4><span class="dashicons dashicons-star-filled"></span>
												<?php esc_html_e('Need 1-Click Auto Replace?', 'pixgrow-image-optimizer'); ?></h4>
											<p><?php esc_html_e('Manually updating filenames in database records and theme templates is slow. Upgrade to PixGrow Pro to enable 1-Click Search-and-Replace, which automatically updates all hardcoded paths on the fly!', 'pixgrow-image-optimizer'); ?>
											</p>
										</div>
										<div class="pro-replace-banner-action">
											<button
												class="button button-primary btn-glow btn-open-pricing-tab"><?php esc_html_e('Upgrade to Pro', 'pixgrow-image-optimizer'); ?></button>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<!-- Pro Image Reference Audit Card -->
						<div class="PixGrow-card pixgrow-card wasmpress-card PixGrow-audit-card pixgrow-audit-card wasmpress-audit-card">
							<h3><span class="dashicons dashicons-analytics"></span>
								<?php esc_html_e('Pro Image Reference Audit', 'pixgrow-image-optimizer'); ?>
								<?php if ($is_pro_licensed): ?>
									<span class="PixGrow-pro-badge pixgrow-pro-badge wasmpress-pro-badge"
										style="background: rgba(52, 211, 153, 0.15) !important; color: #34d399 !important; border: 1px solid rgba(52, 211, 153, 0.3) !important; font-size: 0.75rem !important; padding: 3px 8px !important; border-radius: 4px !important; font-weight: 700 !important; display: inline-flex !important; align-items: center !important; gap: 4px !important; vertical-align: middle !important; margin-left: 8px !important;">🟢
										Activated</span>
								<?php else: ?>
									<span class="pro-badge pro-badge-lock"><?php esc_html_e('Pro', 'pixgrow-image-optimizer'); ?></span>
								<?php endif; ?>
							</h3>
							<p class="description">
								<?php esc_html_e('Scan all optimized images at once to locate any outdated hardcoded references in your database contents and theme files.', 'pixgrow-image-optimizer'); ?>
							</p>

							<div class="audit-action-bar" style="margin-bottom: 20px;">
								<button id="btn-run-audit" 
									class="button button-primary<?php echo ! $is_pro_licensed ? ' pixgrow-pro-lock-click' : ''; ?>" 
									data-feature="scanner">
									<span class="dashicons dashicons-analytics"></span>
									<?php if ($is_pro_licensed) : ?>
										<span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981; margin-right:6px; vertical-align:middle; box-shadow:0 0 5px #10b981;"></span>
									<?php endif; ?>
									<?php esc_html_e('Audit Image References', 'pixgrow-image-optimizer'); ?>
									<?php if ($is_pro_licensed) : ?>
										<?php esc_html_e(' (Pro)', 'pixgrow-image-optimizer'); ?>
									<?php endif; ?>
								</button>
							</div>

							<!-- Audit Progress Section -->
							<div id="pixgrow-audit-progress-section"
								style="display:none; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:24px; margin-bottom:24px; box-shadow:0 4px 15px rgba(0,0,0,0.15);">
								<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
									<span id="audit-progress-status-label"
										style="font-weight:700; color:#fbbf24; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
										<span class="spinner is-active" style="float:none; margin:0; vertical-align:middle;"></span>
										<?php esc_html_e('Analyzing database records...', 'pixgrow-image-optimizer'); ?>
									</span>
									<span id="audit-progress-percent"
										style="font-weight:800; color:#ffffff; font-size:1.15rem; font-family:monospace;">0%</span>
								</div>

								<!-- Animated Progress Bar -->
								<div class="audit-progress-bar-bg"
									style="width:100%; height:12px; background:rgba(255,255,255,0.05); border-radius:9999px; overflow:hidden; border:1px solid rgba(255,255,255,0.08); margin-bottom:24px; position:relative;">
									<div id="audit-progress-bar-fill" class="audit-progress-bar-fill"
										style="width:0%; height:100%; background:linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899); border-radius:9999px; transition:width 0.4s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 12px rgba(139, 92, 246, 0.5);">
									</div>
								</div>

								<!-- Checklist steps -->
								<div class="audit-steps-checklist" style="display:flex; flex-direction:column; gap:12px;">
									<div class="audit-step-item" id="step-db-analysis"
										style="display:flex; align-items:center; gap:10px; color:#cbd5e1; font-size:0.92rem; font-weight:500;">
										<span class="step-check dashicons dashicons-clock" style="color:#64748b;"></span>
										<span><?php esc_html_e('Analyzing database records...', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="audit-step-item" id="step-image-scan"
										style="display:flex; align-items:center; gap:10px; color:#cbd5e1; font-size:0.92rem; font-weight:500;">
										<span class="step-check dashicons dashicons-clock" style="color:#64748b;"></span>
										<span><?php esc_html_e('Scanning optimized images...', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="audit-step-item" id="step-theme-scan"
										style="display:flex; align-items:center; gap:10px; color:#cbd5e1; font-size:0.92rem; font-weight:500;">
										<span class="step-check dashicons dashicons-clock" style="color:#64748b;"></span>
										<span><?php esc_html_e('Checking theme files...', 'pixgrow-image-optimizer'); ?></span>
									</div>
									<div class="audit-step-item" id="step-upload-scan"
										style="display:flex; align-items:center; gap:10px; color:#cbd5e1; font-size:0.92rem; font-weight:500;">
										<span class="step-check dashicons dashicons-clock" style="color:#64748b;"></span>
										<span><?php esc_html_e('Checking uploads...', 'pixgrow-image-optimizer'); ?></span>
									</div>
								</div>

								<div
									style="margin-top:20px; font-size:0.82rem; color:#94a3b8; line-height:1.45; border-top:1px solid rgba(255,255,255,0.05); padding-top:12px; display:flex; align-items:center; gap:6px;">
									<span class="dashicons dashicons-info"
										style="font-size:1rem; width:1rem; height:1rem; color:#94a3b8;"></span>
									<span><?php esc_html_e('Please wait. This can take up to 1–2 minutes on large websites.', 'pixgrow-image-optimizer'); ?></span>
								</div>
							</div>

							<div id="audit-results-container" style="display:none;">
								<div class="audit-results-table-wrapper" style="overflow-x:auto;">
									<table class="PixGrow-table pixgrow-table wasmpress-table audit-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e('Image', 'pixgrow-image-optimizer'); ?></th>
												<th scope="col"><?php esc_html_e('Found In', 'pixgrow-image-optimizer'); ?></th>
												<th scope="col"><?php esc_html_e('Reference Type', 'pixgrow-image-optimizer'); ?></th>
												<th scope="col"><?php esc_html_e('Status', 'pixgrow-image-optimizer'); ?></th>
												<th scope="col" class="column-actions">
													<?php esc_html_e('Action', 'pixgrow-image-optimizer'); ?></th>
											</tr>
										</thead>
										<tbody id="audit-tbody">
											<!-- Populated dynamically via JS -->
										</tbody>
									</table>
								</div>

								<!-- Audit Pagination Controls -->
								<div class="audit-pagination-info-wrapper"
									style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; flex-wrap:wrap; gap:10px;">
									<div class="audit-pagination-info" style="color:#94a3b8; font-size:0.9rem; font-weight:500;"></div>
									<div class="audit-pagination-bar"
										style="display:none; justify-content:center; align-items:center; gap:6px;"></div>
								</div>
							</div>
						</div>
					<?php else: ?>
						<!-- FREE version: Render gorgeous Premium Showcase Lock card -->
						<div class="PixGrow-card pixgrow-card wasmpress-card premium-lock-showcase-card"
							style="text-align: center; padding: 50px 30px; background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.08) 0%, rgba(15, 23, 42, 0.95) 100%); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);">
							<div class="lock-icon-wrapper" style="margin-bottom: 24px;">
								<span class="dashicons dashicons-lock"
									style="font-size: 64px; width: 64px; height: 64px; color: #f59e0b; background: rgba(245, 158, 11, 0.1); padding: 20px; border-radius: 50%; border: 1px solid rgba(245, 158, 11, 0.25); box-shadow: 0 0 20px rgba(245, 158, 11, 0.15); display: inline-block; line-height: 64px;"></span>
							</div>

							<h2
								style="font-size: 1.85rem; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 12px; font-family: inherit;">
								<?php esc_html_e('Hardcoded Static Image Reference Scanner', 'pixgrow-image-optimizer'); ?></h2>
							<p
								style="color: #cbd5e1; font-size: 1.05rem; line-height: 1.6; max-width: 650px; margin: 0 auto 35px auto;">
								<?php esc_html_e('Converting JPG/PNG attachments to WebP can cause hardcoded image links inside post contents, pages, custom posts, and theme template files (PHP/CSS) to break. The premium reference engine scans and auto-updates all references on the fly!', 'pixgrow-image-optimizer'); ?>
							</p>

							<div class="premium-features-list"
								style="display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; margin-bottom: 45px; text-align: left; max-width: 750px; margin-left: auto; margin-right: auto;">
								<div class="premium-feature-item"
									style="flex: 1 1 200px; background: rgba(255, 255, 255, 0.02); padding: 20px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.03);">
									<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap" style="margin: 0 0 8px 0; color: #8b5cf6; font-size: 1rem;">
										<span class="dashicons dashicons-search" style="color: #8b5cf6;"></span>
										<?php esc_html_e('Deep DB & Code Scan', 'pixgrow-image-optimizer'); ?></h4>
									<p style="margin: 0; font-size: 0.88rem; color: #94a3b8; line-height: 1.4;">
										<?php esc_html_e('Scans all posts, pages, and active theme templates recursively in milliseconds.', 'pixgrow-image-optimizer'); ?>
									</p>
								</div>
								<div class="premium-feature-item"
									style="flex: 1 1 200px; background: rgba(255, 255, 255, 0.02); padding: 20px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.03);">
									<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap" style="margin: 0 0 8px 0; color: #ec4899; font-size: 1rem;">
										<span class="dashicons dashicons-update" style="color: #ec4899;"></span>
										<?php esc_html_e('1-Click Auto Replace', 'pixgrow-image-optimizer'); ?></h4>
									<p style="margin: 0; font-size: 0.88rem; color: #94a3b8; line-height: 1.4;">
										<?php esc_html_e('Updates all outdated path references on the fly with safe recovery backup checkpoints.', 'pixgrow-image-optimizer'); ?>
									</p>
								</div>
								<div class="premium-feature-item"
									style="flex: 1 1 200px; background: rgba(255, 255, 255, 0.02); padding: 20px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.03);">
									<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap" style="margin: 0 0 8px 0; color: #06b6d4; font-size: 1rem;">
										<span class="dashicons dashicons-yes" style="color: #06b6d4;"></span>
										<?php esc_html_e('Safe 1-Click Rollback', 'pixgrow-image-optimizer'); ?></h4>
									<p style="margin: 0; font-size: 0.88rem; color: #94a3b8; line-height: 1.4;">
										<?php esc_html_e('Restore all modified database entries and theme templates cleanly at any time.', 'pixgrow-image-optimizer'); ?>
									</p>
								</div>
							</div>

							<div class="premium-action-bar">
								<button class="button button-primary btn-glow btn-open-pricing-tab"
									style="background: linear-gradient(135deg, #ec4899, #8b5cf6) !important; border: none; padding: 12px 30px; font-size: 1.05rem; font-weight: 700; height: auto; line-height: 24px; border-radius: 8px; text-decoration: none; display: inline-block; box-shadow: 0 4px 15px rgba(236, 72, 153, 0.35); text-shadow: none; cursor: pointer;"><?php esc_html_e('Upgrade to Pro & Unlock Scanner →', 'pixgrow-image-optimizer'); ?></button>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ('history' === $active_tab): ?>
				<!-- TAB 3: HISTORY & RESTORE -->
				<div id="tab-history" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<div class="PixGrow-card pixgrow-card wasmpress-card table-card">
						<h3><span class="dashicons dashicons-backup"></span>
								<?php esc_html_e('Optimization History & Backups', 'pixgrow-image-optimizer'); ?></h3>
						<div class="PixGrow-card-header pixgrow-card-header wasmpress-card-header" >
							
							<p class="description">
								<?php esc_html_e('Below is a list of all images optimized by PixGrow. You can restore them to their original backup states at any time.', 'pixgrow-image-optimizer'); ?>
							</p>
							<!-- Pro Search and Pagination Info Controls -->
						<div class="history-controls-bar"
							style="margin: 6px 24px 10px 24px;">
							<div style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
								<div class="history-search-box" style="display:none; position:relative;">
									<span class="dashicons dashicons-search"
										style="color:#94a3b8; position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:1.1rem; pointer-events:none;"></span>
									<input type="text" id="history-search-input"
										placeholder="<?php esc_attr_e('Search optimized images...', 'pixgrow-image-optimizer'); ?>"
										style="padding-left:32px; width:220px; border-radius:6px; height:36px; font-size:0.9rem;">
								</div>
								<div class="history-filter-box" style="display:none;">
									<select id="history-format-filter"
										style="border-radius:6px; height:36px; font-size:0.9rem; padding: 0 10px; width:150px;">
										<option value="all"><?php esc_html_e('All Formats', 'pixgrow-image-optimizer'); ?>
										</option>
										<option value="webp"><?php esc_html_e('WebP Format', 'pixgrow-image-optimizer'); ?>
										</option>
										<option value="jpg"><?php esc_html_e('JPEG / JPG Format', 'pixgrow-image-optimizer'); ?>
										</option>
									</select>
								</div>
								<div class="history-pagesize-box" style="display:none;">
									<select id="history-per-page"
										style="border-radius:6px; height:36px; font-size:0.9rem; padding: 0 10px; width:100px; box-sizing:border-box;">
										<option value="20">20 / page</option>
										<option value="50">50 / page</option>
										<option value="100">100 / page</option>
									</select>
								</div>
							</div>
							<div class="history-pagination-info" style="color:#94a3b8; font-size:0.9rem; font-weight:500;"></div>
						</div>
						</div>


						

						<div class="PixGrow-table-responsive pixgrow-table-responsive wasmpress-table-responsive">
							<table class="PixGrow-table pixgrow-table wasmpress-table">
								<thead>
									<tr>
										<th scope="col" class="column-thumbnail">
											<?php esc_html_e('Thumbnail', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Media Title', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Filename', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Used In', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Original Size', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Current Size', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Savings', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-actions">
											<?php esc_html_e('Restore Actions', 'pixgrow-image-optimizer'); ?></th>
									</tr>
								</thead>
								<tbody id="history-tbody">
									<tr class="pixgrow-history-loading-row">
										<td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8; font-weight: 500;">
											<span class="spinner is-active" style="float: none; margin: 0 8px 0 0; vertical-align: middle;"></span>
											<?php esc_html_e('Loading optimization history...', 'pixgrow-image-optimizer'); ?>
										</td>
									</tr>
									<?php for ($i = 0; $i < 5; $i++): ?>
										<tr class="skeleton-row">
											<td class="column-thumbnail"><div class="pixgrow-skeleton pixgrow-skeleton-circle"></div></td>
											<td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 150px;"></div></td>
											<td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 50px;"></div></td>
											<td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px;"></div></td>
											<td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px;"></div></td>
											<td><div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 50px;"></div></td>
											<td>
												<div style="display:flex; justify-content:flex-end; gap:6px;">
													<div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 60px; height: 26px;"></div>
													<div class="pixgrow-skeleton pixgrow-skeleton-text" style="width: 80px; height: 26px;"></div>
												</div>
											</td>
										</tr>
									<?php endfor; ?>
								</tbody>
							</table>
						</div>

						<!-- Pro Pagination Buttons Bar -->
						<div class="history-pagination-bar"
							style="display:none; justify-content:center; align-items:center; gap:6px; margin: 20px 24px 24px 24px;">
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ('logs' === $active_tab): ?>
				<!-- TAB 5: OPTIMIZATION LOGS -->
				<div id="tab-logs" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<div class="PixGrow-card pixgrow-card wasmpress-card table-card">
						<h3><span class="dashicons dashicons-list-view"></span>
							<?php esc_html_e('Audit Optimization Logs', 'pixgrow-image-optimizer'); ?></h3>

						<div class="PixGrow-table-responsive pixgrow-table-responsive wasmpress-table-responsive">
							<table class="PixGrow-table pixgrow-table wasmpress-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e('Date & Time', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col"><?php esc_html_e('Filename', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Original Size', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Optimized Size', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-size">
											<?php esc_html_e('Savings', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" class="column-status">
											<?php esc_html_e('Status', 'pixgrow-image-optimizer'); ?></th>
									</tr>
								</thead>
								<tbody id="logs-tbody">
									<tr>
										<td colspan="6" class="text-center">
											<?php esc_html_e('No optimization logs found. Start optimizing images from the Bulk Compressor to generate logs here.', 'pixgrow-image-optimizer'); ?>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Logs Pagination Controls -->
						<div class="logs-pagination-info-wrapper"
							style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:10px; padding:0 24px 24px 24px;">
							<div class="logs-pagination-info" style="color:#94a3b8; font-size:0.9rem; font-weight:500;"></div>
							<div class="logs-pagination-bar"
								style="display:none; justify-content:center; align-items:center; gap:6px;"></div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ('diagnostics' === $active_tab): ?>
				<!-- TAB 8: DIAGNOSTICS & SYSTEM LOGS -->
				<div id="tab-diagnostics" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<div class="PixGrow-diagnostics-grid pixgrow-diagnostics-grid wasmpress-diagnostics-grid"
						style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 24px; margin-bottom: 24px; align-items: stretch;">

						<!-- Left Card: Diagnostics Parameters -->
						<div class="PixGrow-card pixgrow-card wasmpress-card"
							style="padding: 24px; box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between;">
							<div>
								<h3
									style="margin-top: 0; margin-bottom: 12px; font-size: 1.15rem; color: #ffffff; font-weight: 700; display:flex; align-items:center; gap:8px;">
									<span class="dashicons dashicons-admin-tools" style="color:#38bdf8;"></span>
									<?php esc_html_e('System Telemetry', 'pixgrow-image-optimizer'); ?>
								</h3>
								<p class="description" style="margin-bottom: 20px; color:#cbd5e1;">
									<?php esc_html_e('Check your WordPress site environment and performance configuration.', 'pixgrow-image-optimizer'); ?>
								</p>

								<div class="telemetry-table-wrapper" style="overflow-x: auto;">
									<table class="PixGrow-telemetry-table pixgrow-telemetry-table wasmpress-telemetry-table"
										style="width: 100%; border-collapse: collapse; color: #cbd5e1; font-size: 0.9rem;">
										<tbody>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('PixGrow Version', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-PixGrow-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('PHP Version', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-php-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('WordPress Version', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-wp-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('PHP Memory Limit', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-mem-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('WP Memory Limit', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-wpmem-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Disk Free Space', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-disk-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Uploads Directory Writable', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-uploads-writable-val">-
												</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Backups Directory Writable', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-backups-writable-val">-
												</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Logs Directory Writable', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-logs-writable-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Supported Image Library', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-codecs-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Telemetry: Stats Build Time', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-buildtime-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Telemetry: AJAX Roundtrip', 'pixgrow-image-optimizer'); ?>
												</td>
												<td style="padding: 10px 0; text-align: right;" id="diag-ajaxresponse-val">-</td>
											</tr>
											<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Telemetry: Peak Memory', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-peakmem-val">-</td>
											</tr>
											<tr style="border-bottom: none;">
												<td style="padding: 10px 0; font-weight: 600; color: #94a3b8;">
													<?php esc_html_e('Telemetry: Query Count', 'pixgrow-image-optimizer'); ?></td>
												<td style="padding: 10px 0; text-align: right;" id="diag-querycount-val">-</td>
											</tr>
										</tbody>
									</table>
								</div>
							</div>
							<div style="margin-top: 20px;">
								<button id="btn-refresh-diagnostics" class="button button-primary btn-glow"
									style="width:100%; height:38px; line-height:36px; font-weight:700;"><span
										class="dashicons dashicons-update"
										style="vertical-align:middle; margin-right:4px;"></span><?php esc_html_e('Refresh Telemetry', 'pixgrow-image-optimizer'); ?></button>
							</div>
						</div>

						<!-- Right Card: Debug Log Console -->
						<div class="PixGrow-card pixgrow-card wasmpress-card"
							style="padding: 24px; box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between;">
							<div>
								<div
									style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px; flex-wrap:wrap; gap:10px;">
									<h3
										style="margin: 0; font-size: 1.15rem; color: #ffffff; font-weight: 700; display:flex; align-items:center; gap:8px;">
										<span class="dashicons dashicons-list-view" style="color:#ef4444;"></span>
										<?php esc_html_e('System Debug Logs', 'pixgrow-image-optimizer'); ?>
									</h3>
									<div style="display:flex; gap:8px;">
										<button id="btn-refresh-debug-logs" class="button button-secondary"
											style="height:32px; font-weight:600; font-size:0.85rem;"><span
												class="dashicons dashicons-update"
												style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-2px;"></span>
											<?php esc_html_e('Refresh', 'pixgrow-image-optimizer'); ?></button>
										<button id="btn-clear-debug-logs" class="button button-secondary"
											style="height:32px; font-weight:600; color:#ef4444; border-color:rgba(239,68,68,0.2) !important; font-size:0.85rem;"><span
												class="dashicons dashicons-trash"
												style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-2px;"></span>
											<?php esc_html_e('Clear Logs', 'pixgrow-image-optimizer'); ?></button>
									</div>
								</div>
								<p class="description" style="margin-bottom: 20px; color:#cbd5e1;">
									<?php esc_html_e('View real-time error logs and system events generated by PixGrow operations. Log size is automatically constrained to 1MB.', 'pixgrow-image-optimizer'); ?>
								</p>

								<div class="PixGrow-console-log pixgrow-console-log wasmpress-console-log" style="display:block; margin-top:0;">
									<div id="pixgrow-debug-log-console"
										style="height:320px; overflow-y:auto; background:rgba(15, 23, 42, 0.9); border:1px solid rgba(255, 255, 255, 0.08); border-radius:8px; padding:16px; font-family:monospace; font-size:0.85rem; line-height:1.5; color:#38bdf8; white-space:pre-wrap; word-break:break-all; box-shadow: inset 0 2px 8px rgba(0,0,0,0.5);">
										<?php esc_html_e('Loading debug logs...', 'pixgrow-image-optimizer'); ?>
									</div>
								</div>
							</div>
						</div>

					</div>
				</div>
			<?php endif; ?>

			<?php if ('settings' === $active_tab): ?>
				<!-- TAB 6: SETTINGS -->
				<div id="tab-settings" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active PixGrow-settings-page pixgrow-settings-page wasmpress-settings-page">
					<!-- Dirty Notice Bar -->
					<div id="pixgrow-settings-dirty-notice" class="PixGrow-settings-notice pixgrow-settings-notice wasmpress-settings-notice notice notice-warning"
						style="display:none;">
						<span class="dashicons dashicons-warning"></span>
						<span><?php esc_html_e('You have unsaved settings changes. Please click Save Settings to apply them.', 'pixgrow-image-optimizer'); ?></span>
					</div>

					<?php
					$format = pixgrow_get_pro_setting('format', 'webp');
					$quality = (int) pixgrow_get_pro_setting('quality', 80);
					$auto_optimize = (int) pixgrow_get_pro_setting('auto_optimize_on_upload', 0);

					$format_label = esc_html__('WebP (Recommended)', 'pixgrow-image-optimizer');
					if ('jpeg' === $format) {
						$format_label = esc_html__('MozJPEG', 'pixgrow-image-optimizer');
					} elseif ('smart' === $format) {
						$format_label = esc_html__('Smart Format (Pro)', 'pixgrow-image-optimizer');
					}

					$auto_label = $auto_optimize ? esc_html__('Active (Auto)', 'pixgrow-image-optimizer') : esc_html__('Disabled', 'pixgrow-image-optimizer');
					?>

					<!-- Top Settings Summary Card -->
					<div class="PixGrow-settings-summary-grid pixgrow-settings-summary-grid wasmpress-settings-summary-grid">
						<div class="PixGrow-summary-tile pixgrow-summary-tile wasmpress-summary-tile">
							<span class="tile-icon dashicons dashicons-image-filter"></span>
							<div class="tile-content">
								<span class="tile-label"><?php esc_html_e('Target Format', 'pixgrow-image-optimizer'); ?></span>
								<span class="tile-value value-format"><?php echo esc_html($format_label); ?></span>
							</div>
						</div>
						<div class="PixGrow-summary-tile pixgrow-summary-tile wasmpress-summary-tile">
							<span class="tile-icon dashicons dashicons-performance"></span>
							<div class="tile-content">
								<span
									class="tile-label"><?php esc_html_e('Compression Quality', 'pixgrow-image-optimizer'); ?></span>
								<span class="tile-value value-quality"><?php echo esc_html($quality); ?>%</span>
							</div>
						</div>
						<div class="PixGrow-summary-tile pixgrow-summary-tile wasmpress-summary-tile">
							<span class="tile-icon dashicons dashicons-cloud-upload"></span>
							<div class="tile-content">
								<span
									class="tile-label"><?php esc_html_e('Auto-Optimize Uploads', 'pixgrow-image-optimizer'); ?></span>
								<span class="tile-value value-auto"><?php echo esc_html($auto_label); ?></span>
							</div>
						</div>
						<div class="PixGrow-summary-tile pixgrow-summary-tile wasmpress-summary-tile">
							<span class="tile-icon dashicons dashicons-shield"></span>
							<div class="tile-content">
								<span
									class="tile-label"><?php esc_html_e('Safety Auto-Backups', 'pixgrow-image-optimizer'); ?></span>
								<span
									class="tile-value value-backups"><?php esc_html_e('Safe (Active)', 'pixgrow-image-optimizer'); ?></span>
							</div>
						</div>
					</div>

					<form id="pixgrow-settings-form" method="post">
						<div class="PixGrow-settings-main-grid pixgrow-settings-main-grid wasmpress-settings-main-grid">
							<div class="settings-opt-profile-col">
								<?php $this->render_general_settings(); ?>
							</div>
							<?php do_action( 'pixgrow_admin_additional_settings' ); ?>
						</div>

						<details class="PixGrow-collapsible-advanced-settings pixgrow-collapsible-advanced-settings wasmpress-collapsible-advanced-settings" style="margin-top: 24px;">
							<summary class="PixGrow-collapsible-summary pixgrow-collapsible-summary wasmpress-collapsible-summary">
								<span class="summary-title-wrapper">
									<span class="dashicons dashicons-admin-tools"></span>
									<strong><?php esc_html_e('Advanced & Safety Configuration', 'pixgrow-image-optimizer'); ?></strong>
								</span>
								<span class="summary-chevron dashicons dashicons-chevron-right"></span>
							</summary>
							<div class="advanced-settings-wrapper" style="margin-top: 16px;">
								<?php $this->render_advanced_safety_settings(); ?>
							</div>
						</details>

						<div class="PixGrow-settings-actions pixgrow-settings-actions wasmpress-settings-actions" style="margin-top: 24px; display: flex; gap: 12px;">
							<button type="submit" id="btn-save-settings" class="button button-primary button-hero btn-glow"><span
									class="dashicons dashicons-saved"></span>
								<?php esc_html_e('Save Settings', 'pixgrow-image-optimizer'); ?></button>
							<button type="button" id="btn-reset-settings" class="button button-secondary button-hero"><span
									class="dashicons dashicons-update"></span>
								<?php esc_html_e('Reset to Defaults', 'pixgrow-image-optimizer'); ?></button>
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php if ('pricing' === $active_tab): ?>
				<!-- TAB 4: PRICING / PRO TICKET -->
				<div id="tab-pricing" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<?php $this->render_pricing_table_content(); ?>
				</div>
			<?php endif; ?>

			<?php if ('account' === $active_tab): ?>
				<!-- TAB 4B: ACCOUNT (PRO ONLY) -->
				<div id="tab-account" class="PixGrow-tab-content pixgrow-tab-content wasmpress-tab-content PixGrow-tab-active pixgrow-tab-active wasmpress-tab-active">
					<?php $this->render_pricing_table_content(); ?>
				</div>
			<?php endif; ?>

		</div>
		</div>

		<!-- Server Error Modal popup -->
		<div id="pixgrow-error-modal" class="PixGrow-modal pixgrow-modal wasmpress-modal" style="display:none;">
			<div class="PixGrow-modal-content pixgrow-modal-content wasmpress-modal-content">
				<span class="PixGrow-modal-close pixgrow-modal-close wasmpress-modal-close">&times;</span>
				<h4 class="modal-title"><span class="dashicons dashicons-warning"></span> Server Limit or Crash Detected</h4>
				<div class="modal-body">
					<p>We encountered a server error while optimizing <strong><span id="modal-err-filename"></span></strong>:
					</p>
					<div class="modal-err-details" id="modal-err-details"></div>
					<p class="modal-explanation">
						This typically happens when your web server runs out of memory or execution time limits while saving
						backups or regenerating WordPress thumbnails.
					</p>
					<p class="modal-solution">
						<strong>To fix this and process large image queues (up to 10,000+ images):</strong>
					</p>
					<ul class="modal-solution-list">
						<li>Increase your PHP memory limit to at least <strong>512M</strong> (e.g.,
							<code>memory_limit = 512M</code> in <code>php.ini</code>).</li>
						<li>Increase your PHP execution time to at least <strong>300s</strong> (e.g.,
							<code>max_execution_time = 300</code> in <code>php.ini</code>).</li>
						<li>Configure htaccess: add <code>php_value memory_limit 512M</code> to your <code>.htaccess</code>
							file.</li>
						<li>Contact your hosting provider if you are on shared hosting to remove request timeout limits.</li>
					</ul>
				</div>
				<div class="modal-actions">
					<button class="button button-primary btn-close-modal">Got it, close</button>
				</div>
			</div>
		</div>

		<!-- Compare Modal popup (Pro feature) -->
		<?php do_action( 'pixgrow_admin_footer' ); ?>

		<!-- Premium Feature Unlock Modal -->
		<div id="pixgrow-pro-lock-modal" style="display:none;">
			<div class="PixGrow-pro-lock-content pixgrow-pro-lock-content wasmpress-pro-lock-content">
				<span class="dashicons dashicons-lock PixGrow-pro-lock-icon pixgrow-pro-lock-icon wasmpress-pro-lock-icon"></span>
				<h3 class="PixGrow-pro-lock-title pixgrow-pro-lock-title wasmpress-pro-lock-title"><?php esc_html_e('Unlock Premium Feature', 'pixgrow-image-optimizer'); ?>
				</h3>
				<p class="PixGrow-pro-lock-desc pixgrow-pro-lock-desc wasmpress-pro-lock-desc">
					<?php esc_html_e('This is a premium feature. Upgrade to PixGrow Pro to unlock next-generation compression formats, unlimited batch uploads, automatic background optimizations, and hardcoded image link search-and-replace autopilot!', 'pixgrow-image-optimizer'); ?>
				</p>
				<div class="PixGrow-pro-lock-actions pixgrow-pro-lock-actions wasmpress-pro-lock-actions">
					<button
						class="button button-primary btn-lock-buy btn-glow"><?php esc_html_e('Upgrade to Pro →', 'pixgrow-image-optimizer'); ?></button>
					<button
						class="button button-link btn-lock-account"><?php esc_html_e('Already purchased? Enter license key', 'pixgrow-image-optimizer'); ?></button>
					<button
						class="button button-secondary btn-lock-close"><?php esc_html_e('Close', 'pixgrow-image-optimizer'); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the documentation submenu page HTML.
	 */
	public function render_docs_page()
	{
		?>
		<div class="wrap PixGrow-wrap pixgrow-wrap wasmpress-wrap">
			<!-- Header -->
			<div class="PixGrow-header pixgrow-header wasmpress-header">
				<div class="PixGrow-branding pixgrow-branding wasmpress-branding">
					<!-- <span class="dashicons dashicons-performance header-icon"></span> -->
					<img src="<?php echo esc_url(PIXGROW_URL . 'assets/images/pixgrow-logo-1.png'); ?>" alt="PixGrow Logo"
						class="header-icon" style="width:32px; height:32px; margin-right:8px;">
					<h1>PixGrow <span
							class="badge"><?php echo esc_html(class_exists('PixGrow_Pro_Features') ? 'PRO' : 'Free'); ?></span>
					</h1>
				</div>
				<p class="description">
					<?php esc_html_e('Plugin Documentation & Help Guide — Get the most out of your client-side image compressor.', 'pixgrow-image-optimizer'); ?>
				</p>
			</div>

			<!-- User Documentation Content -->
			<div class="PixGrow-card pixgrow-card wasmpress-card">
				<h3><span class="dashicons dashicons-editor-help"></span>
					<?php esc_html_e('User Documentation', 'pixgrow-image-optimizer'); ?></h3>

				<div class="PixGrow-docs-container pixgrow-docs-container wasmpress-docs-container" style="margin-top:20px; display:flex; flex-direction:column; gap:20px;">

					<div class="docs-section" style="border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:20px;">
						<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap"
							style="color:#ffffff; font-size:1.15rem; margin-top:0; margin-bottom:8px;"><span
								class="dashicons dashicons-yes-alt text-success" style="color:#34d399;"></span> 1. What is
							Client-Side Wasm Compression?</h4>
						<p style="color:#cbd5e1; line-height:1.6; margin:0;">
							Unlike traditional plugins that compress images on your web server (which exhausts server CPU,
							triggers 504 gateway timeouts, and slows down your backend), <strong>PixGrow</strong> works
							completely inside your browser using WebAssembly.
							When you click compress, your browser downloads the image, processes it in memory using WebAssembly
							codecs (WebP/MozJPEG), and uploads the optimized version back to the server.
						</p>
					</div>

					<div class="docs-section" style="border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:20px;">
						<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap"
							style="color:#ffffff; font-size:1.15rem; margin-top:0; margin-bottom:8px;"><span
								class="dashicons dashicons-yes-alt text-success" style="color:#34d399;"></span> 2. Bulk
							Compressor Guide</h4>
						<p style="color:#cbd5e1; line-height:1.6; margin:0 0 10px 0;">
							Go to the <strong>Bulk Compressor</strong> tab, configure your quality slider (80% is recommended
							for best quality/size ratio), choose if you want to resize large images (e.g. limit width to
							1920px), and click <strong>Start Bulk Optimization</strong>.
						</p>
						<p style="color:#cbd5e1; line-height:1.6; margin:0;">
							<strong>Important:</strong> Keep your browser tab open while compressing! Since WebAssembly runs
							locally, closing the tab pauses the compression queue.
						</p>
					</div>

					<div class="docs-section" style="border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:20px;">
						<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap"
							style="color:#ffffff; font-size:1.15rem; margin-top:0; margin-bottom:8px;"><span
								class="dashicons dashicons-yes-alt text-success" style="color:#34d399;"></span> 3. Backup and
							1-Click Restore</h4>
						<p style="color:#cbd5e1; line-height:1.6; margin:0;">
							Every image optimized is automatically backed up to
							<code>wp-content/uploads/pixgrow/backups/attachment_{id}/</code> before replacement.
							If you ever need to revert to the original uncompressed image, go to the <strong>History &
								Restore</strong> tab and click <strong>Restore Original</strong> (or click <strong>Undo</strong>
							in the Queue list). This immediately restores the backup copy and restores database metadata.
						</p>
					</div>

					<div class="docs-section" style="border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:20px;">
						<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap"
							style="color:#ffffff; font-size:1.15rem; margin-top:0; margin-bottom:8px;"><span
								class="dashicons dashicons-yes-alt text-success" style="color:#34d399;"></span> 4. Hardcoded
							Static Reference Scanner</h4>
						<p style="color:#cbd5e1; line-height:1.6; margin:0;">
							If you convert a `.jpg` or `.png` to `.webp`, files hardcoded inside theme code or database
							posts/pages might break. Use the <strong>Static Path Scanner</strong> to scan.
							The Free version lists the files/records containing references. PixGrow Pro lets you click
							<strong>Replace Code</strong> or <strong>Replace All DB</strong> to rewrite them on-the-fly with
							1-click.
						</p>
					</div>

					<div class="docs-section" style="padding-bottom:10px;">
						<h4 class="PixGrow-flex-center-gap pixgrow-flex-center-gap wasmpress-flex-center-gap"
							style="color:#ffffff; font-size:1.15rem; margin-top:0; margin-bottom:8px;"><span
								class="dashicons dashicons-yes-alt text-success" style="color:#34d399;"></span> 5.
							Troubleshooting Server Limits (500/504 Errors)</h4>
						<p style="color:#cbd5e1; line-height:1.6; margin:0 0 10px 0;">
							If your server halts or shows errors during backup or replacement, it is likely because WordPress is
							running out of resources while saving the file or regenerating intermediate thumbnails.
						</p>
						<p style="color:#cbd5e1; line-height:1.6; margin:0 0 10px 0;">
							You can resolve this by adding the following settings to your server configuration:
						</p>
						<ul style="list-style-type:disc; padding-left:20px; color:#cbd5e1; line-height:1.6; margin:0;">
							<li><strong>Increase Memory Limit:</strong> In <code>php.ini</code>, set
								<code>memory_limit = 512M</code> (or higher).</li>
							<li><strong>Increase Execution Time:</strong> In <code>php.ini</code>, set
								<code>max_execution_time = 300</code>.</li>
							<li><strong>Configure .htaccess:</strong> Alternatively, add
								<code>php_value memory_limit 512M</code> to your <code>.htaccess</code> file.</li>
						</ul>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders General settings card.
	 */
	private function render_general_settings()
	{
		$is_pro_installed = class_exists('PixGrow_Pro_Features');
		$is_pro_licensed = $is_pro_installed && function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed();
		$format = pixgrow_get_pro_setting('format', 'webp');
		$quality = (int) pixgrow_get_pro_setting('quality', 80);
		$resize = (int) pixgrow_get_pro_setting('resize', 0);
		$max_width = (int) pixgrow_get_pro_setting('max_width', 1920);
		?>
		<div class="PixGrow-card pixgrow-card wasmpress-card settings-section-card">
			<h3><span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e('Compression Settings', 'pixgrow-image-optimizer'); ?></h3>
			<p class="description">
				<?php esc_html_e('Configure your image optimization format, visual quality, and scaling preferences.', 'pixgrow-image-optimizer'); ?>
			</p>

			<div class="settings-form-row">
				<label
					for="setting-format"><strong><?php esc_html_e('Target Format:', 'pixgrow-image-optimizer'); ?></strong></label>
				<select id="setting-format" name="format" class="widefat">
					<option value="webp" <?php selected($format, 'webp'); ?>>
						<?php esc_html_e('Convert to WebP (Recommended)', 'pixgrow-image-optimizer'); ?></option>
					<option value="jpeg" <?php selected($format, 'jpeg'); ?>>
						<?php esc_html_e('Convert to MozJPEG', 'pixgrow-image-optimizer'); ?></option>
					<?php do_action( 'pixgrow_admin_target_formats', $format ); ?>
				</select>
			</div>

			<div class="settings-form-row">
				<label for="setting-quality">
					<strong><?php esc_html_e('Compression Quality:', 'pixgrow-image-optimizer'); ?></strong>
					<span id="quality-val"
						style="float: right; font-weight: bold; color: #38bdf8;"><?php echo esc_html($quality); ?>%</span>
				</label>
				<input type="range" id="setting-quality" name="quality" min="10" max="100"
					value="<?php echo esc_attr($quality); ?>" class="widefat">
				<span
					class="description-small"><?php esc_html_e('Higher visual quality results in larger file sizes. 80% is recommended.', 'pixgrow-image-optimizer'); ?></span>
			</div>

			<div class="settings-form-row">
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" id="setting-resize" name="resize" value="1" <?php checked($resize, 1); ?>>
					<strong><?php esc_html_e('Resize Large Images', 'pixgrow-image-optimizer'); ?></strong>
				</label>
			</div>

			<div id="resize-dimensions" class="settings-form-row"
				style="<?php echo esc_attr( $resize ? 'display: block;' : 'display: none;' ); ?> margin-left: 20px; border-left: 2px solid rgba(255, 255, 255, 0.05); padding-left: 15px;">
				<label
					for="setting-max-width"><strong><?php esc_html_e('Maximum Width (px):', 'pixgrow-image-optimizer'); ?></strong></label>
				<input type="number" id="setting-max-width" name="max_width" value="<?php echo esc_attr($max_width); ?>"
					min="320" max="9999" class="small-text">
				<p class="description-small">
					<?php esc_html_e('Images wider than this threshold will be scaled down automatically during compression.', 'pixgrow-image-optimizer'); ?>
				</p>
			</div>
		</div>
		<?php
	}


	/**
	 * Renders Rules settings card.
	 */
	private function render_rules_settings()
	{
		// Deprecated settings card to support restructured Advanced Settings visual grouping
	}

	/**
	 * Renders the unified Advanced & Safety Configuration settings card.
	 */
	private function render_advanced_safety_settings()
	{
		$backup_enabled = 1; // Locked to 1 for security
		$delete_data = (int) pixgrow_get_setting('delete_data_on_uninstall', 0);
		$replace_confirm = (int) pixgrow_get_setting('replace_confirm', 1);
		$debug_options = (int) pixgrow_get_setting('debug_options', 0);
		?>
		<div class="PixGrow-card pixgrow-card wasmpress-card settings-section-card">
			<h3><span class="dashicons dashicons-admin-tools"></span>
				<?php esc_html_e('Advanced & Safety Configuration', 'pixgrow-image-optimizer'); ?></h3>
			<p class="description">
				<?php esc_html_e('Configure safety defaults, developer debug logs, path replacements, and format fallback rules.', 'pixgrow-image-optimizer'); ?>
			</p>

			<!-- Safety Auto-Backup Safeguard -->
			<div class="settings-form-row">
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" id="setting-backup-enabled" value="1" checked disabled>
					<input type="hidden" name="backup_enabled" value="1">
					<strong><?php esc_html_e('Optimize Safely with Auto-Backups (Recommended)', 'pixgrow-image-optimizer'); ?></strong>
				</label>
				<span
					class="description-small"><?php esc_html_e('Automatically stores copies of original attachments under wp-content/uploads/pixgrow/backups/ before compression.', 'pixgrow-image-optimizer'); ?></span>
			</div>

			<!-- Data Deletion Preference -->
			<div class="settings-form-row">
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" id="setting-delete-data" name="delete_data_on_uninstall" value="1" <?php checked($delete_data, 1); ?>>
					<strong><?php esc_html_e('Delete Plugin Backups on Uninstall', 'pixgrow-image-optimizer'); ?></strong>
				</label>
				<span
					class="description-small"><?php esc_html_e('Permanently deletes all backups and attachment metadata records when the plugin is deleted.', 'pixgrow-image-optimizer'); ?></span>
			</div>

			<!-- User Confirmations -->
			<div class="settings-form-row"
				style="margin-top: 15px; border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 15px;">
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" id="setting-replace-confirm" name="replace_confirm" value="1" <?php checked($replace_confirm, 1); ?>>
					<strong><?php esc_html_e('Enable 1-Click Replace Confirmations', 'pixgrow-image-optimizer'); ?></strong>
				</label>
				<span
					class="description-small"><?php esc_html_e('Display a warning prompt before executing search-and-replace routines.', 'pixgrow-image-optimizer'); ?></span>
			</div>

			<!-- Debug Logging -->
			<div class="settings-form-row">
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" id="setting-debug-options" name="debug_options" value="1" <?php checked($debug_options, 0); ?>>
					<strong><?php esc_html_e('Enable Developer Debug Logs', 'pixgrow-image-optimizer'); ?></strong>
				</label>
				<span
					class="description-small"><?php esc_html_e('Enable detailed debugging messages inside browser consoles.', 'pixgrow-image-optimizer'); ?></span>
			</div>

			<?php do_action( 'pixgrow_admin_advanced_safety_settings' ); ?>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint to save all settings (Free & Pro).
	 */
	public function ajax_save_all_settings()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		// 1. Unslash and deep sanitize the entire $_POST superglobal for safety and compliance
		$post_data = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );

		// 2. Process and save Free core settings
		$settings = array(
			'delete_data_on_uninstall' => isset($post_data['delete_data_on_uninstall']) ? 1 : 0,
			'backup_enabled' => isset($post_data['backup_enabled']) ? 1 : 0,
			'replace_confirm' => isset($post_data['replace_confirm']) ? 1 : 0,
			'debug_options' => isset($post_data['debug_options']) ? 1 : 0,
		);
		update_option('pixgrow_settings', $settings);

		// Free also handles General Settings (which are technically shared/pro options in pixgrow_pro_settings)
		$pro_settings = get_option('pixgrow_pro_settings', array());
		if (isset($post_data['format'])) {
			$format = sanitize_text_field($post_data['format']);
			if (in_array($format, array('webp', 'jpeg', 'jpg'), true)) {
				$pro_settings['format'] = $format;
			}
		}
		if (isset($post_data['quality'])) {
			$quality = absint($post_data['quality']);
			if ($quality >= 10 && $quality <= 100) {
				$pro_settings['quality'] = $quality;
			}
		}
		$pro_settings['resize'] = isset($post_data['resize']) ? 1 : 0;
		if (isset($post_data['max_width'])) {
			$pro_settings['max_width'] = absint($post_data['max_width']);
		}

		update_option('pixgrow_pro_settings', $pro_settings);

		// 3. Fire action hook so Pro addon can serialize and save premium settings
		do_action('pixgrow_save_settings_fields', $post_data);

		wp_send_json_success(array('message' => __('Settings saved successfully.', 'pixgrow-image-optimizer')));
	}

	/**
	 * AJAX endpoint to reset settings to defaults.
	 */
	public function ajax_reset_settings()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		delete_option('pixgrow_settings');
		delete_option('pixgrow_pro_settings');
		delete_option('pixgrow_delete_data_on_uninstall');
		delete_option('pixgrow_auto_optimize_on_upload');

		wp_send_json_success(array('message' => __('Settings reset to defaults successfully.', 'pixgrow-image-optimizer')));
	}



	/**
	 * Renders the Pro pricing comparison table.
	 */
	/**
	 * Renders the Pro pricing comparison table.
	 */
	private function render_pricing_table_content()
	{
		$fs = null;
		$license = null;
		$is_pro_installed = class_exists('PixGrow_Pro_Features');
		$is_pro_licensed = $is_pro_installed && function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed();
		$is_sandbox = ((defined('PIXGROW_QA_TESTING') && PIXGROW_QA_TESTING) || (defined('WASMPRESS_QA_TESTING') && WASMPRESS_QA_TESTING)) && function_exists('pixgrow_is_local_host') && pixgrow_is_local_host();
		$is_pro = false;
		$license_status = __('Unlicensed', 'pixgrow-image-optimizer');
		$plan_status = __('Free Core', 'pixgrow-image-optimizer');
		$license_badge_class = 'badge-unlicensed';
		$plan_badge_class = 'badge-free';
		$license_type = __('Free Core', 'pixgrow-image-optimizer');
		$renewal_status = '';

		if ($is_sandbox) {
			$is_pro = true;
			$license_status = __('QA Testing Mode Enabled', 'pixgrow-image-optimizer');
			$plan_status = __('Local Development Environment', 'pixgrow-image-optimizer');
			$license_badge_class = 'badge-active';
			$plan_badge_class = 'badge-pro';
			$license_type = __('Premium Features Available For Testing', 'pixgrow-image-optimizer');
			$renewal_status = __('Never Expires (Development Sandbox)', 'pixgrow-image-optimizer');
		} elseif (function_exists('pixgrow_pro_is_licensed') && pixgrow_pro_is_licensed()) {
			$is_pro = true;
			$license_status = __('Active', 'pixgrow-image-optimizer');
			$plan_status = __('Pro Active', 'pixgrow-image-optimizer');
			$license_badge_class = 'badge-active';
			$plan_badge_class = 'badge-pro';
			$license_type = __('Pro Yearly', 'pixgrow-image-optimizer');
			$renewal_status = __('Renews on 2027-05-30', 'pixgrow-image-optimizer');
		}

		if (!$is_sandbox && function_exists('pixgrow_pro_fs')) {
			$fs = pixgrow_pro_fs();
			if (is_object($fs)) {
				$is_trial = false;
				if (method_exists($fs, 'is_trial')) {
					$is_trial = $fs->is_trial();
				}

				if ($is_trial) {
					$plan_status = __('Trial', 'pixgrow-image-optimizer');
					$plan_badge_class = 'badge-trial';
					$license_status = __('Trial Active', 'pixgrow-image-optimizer');
					$license_badge_class = 'badge-trial';
					$license_type = __('Pro Trial', 'pixgrow-image-optimizer');
					$renewal_status = __('Expires soon', 'pixgrow-image-optimizer');
				} else {
					$is_paying = false;
					if (method_exists($fs, 'is_paying')) {
						$is_paying = $fs->is_paying();
					}

					if ($is_paying) {
						$is_pro = true;
						$license_status = __('Active', 'pixgrow-image-optimizer');
						$plan_status = __('Pro Active', 'pixgrow-image-optimizer');
						$license_badge_class = 'badge-active';
						$plan_badge_class = 'badge-pro';
						$license_type = __('Pro Active', 'pixgrow-image-optimizer');
					} else {
						$is_fs_premium = false;
						if (method_exists($fs, 'is_premium')) {
							$is_fs_premium = $fs->is_premium();
						}

						if ($is_fs_premium) {
							$plan_status = __('Pro Unlicensed', 'pixgrow-image-optimizer');
							$plan_badge_class = 'badge-pro';
							$license_type = __('Pro Unlicensed', 'pixgrow-image-optimizer');
						}
					}
				}

				// Try to get real plan title & renewal date safely if they exist
				if (method_exists($fs, 'get_site')) {
					try {
						$site = $fs->get_site();
						if (is_object($site) && isset($site->plan)) {
							$license_type = esc_html($site->plan->title);
						}
					} catch (Exception $e) {
						// silence any exceptions
					}
				}
				$license = is_object($fs) ? $fs->_get_license() : null;
				$license_id = is_object($license) ? $license->id : null;
				if ($license_id && method_exists($fs, '_get_subscription')) {
					try {
						$sub = $fs->_get_subscription($license_id);
						if (is_object($sub) && isset($sub->next_payment)) {
							/* translators: %s: formatted date string when the subscription renews */
							$renewal_status = sprintf(__('Renews on %s', 'pixgrow-image-optimizer'), date_i18n(get_option('date_format'), strtotime($sub->next_payment)));
						} elseif ($is_pro) {
							$renewal_status = __('Lifetime License', 'pixgrow-image-optimizer');
						}
					} catch (Exception $e) {
						// silence any exceptions
					}
				}
				if (is_object($license) && !empty($license->expiration) && empty($renewal_status)) {
					try {
						/* translators: %s: formatted date string when the license expires */
						$renewal_status = sprintf(__('Expires on %s', 'pixgrow-image-optimizer'), date_i18n(get_option('date_format'), strtotime($license->expiration)));
					} catch (Exception $e) {
						// silence any exceptions
					}
				}
			}
		}
		?>
		<div class="PixGrow-account-page pixgrow-account-page wasmpress-account-page">
			<!-- Account Center Hero Banner -->
			<div class="PixGrow-hero-banner pixgrow-hero-banner wasmpress-hero-banner"
				style="background: linear-gradient(135deg, rgba(79, 70, 229, 0.12) 0%, rgba(99, 102, 241, 0.04) 100%); border: 1px solid rgba(99, 102, 241, 0.18); border-radius: 16px; padding: 32px; margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; gap: 24px; flex-wrap: wrap; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);">
				<div class="hero-info" style="flex: 1; min-width: 280px;">
					<h2
						style="font-size: 1.75rem; font-weight: 800; color: #ffffff; margin: 0 0 12px 0; display: flex; align-items: center; gap: 10px; line-height: 1.2;">
						<span class="dashicons dashicons-awards"
							style="color: #fbbf24; font-size: 2rem; width: 2rem; height: 2rem;"></span>
						<?php esc_html_e('PixGrow Account Center', 'pixgrow-image-optimizer'); ?>
					</h2>
					<p style="color: #cbd5e1; font-size: 0.95rem; line-height: 1.5; margin: 0 0 20px 0; max-width: 600px;">
						<?php esc_html_e('Manage your PixGrow optimization licenses, pricing plans, and advanced local optimization capabilities.', 'pixgrow-image-optimizer'); ?>
					</p>

					<div class="hero-badges" style="display: flex; gap: 24px; flex-wrap: wrap; align-items: center;">
						<div class="hero-badge-item" style="display: flex; align-items: center; gap: 8px;">
							<span
								style="color: #94a3b8; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Current Plan:', 'pixgrow-image-optimizer'); ?></span>
							<span
								class="badge <?php echo esc_attr($plan_badge_class); ?>"><?php echo esc_html($plan_status); ?></span>
						</div>
						<div class="hero-badge-item" style="display: flex; align-items: center; gap: 8px;">
							<span
								style="color: #94a3b8; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('License Status:', 'pixgrow-image-optimizer'); ?></span>
							<span
								class="badge <?php echo esc_attr($license_badge_class); ?>"><?php echo esc_html($license_status); ?></span>
						</div>
					</div>
				</div>

				<?php if (!$is_sandbox): ?>
					<div class="hero-ctas" style="display: flex; flex-direction: column; gap: 12px; min-width: 220px;">
						<?php if ($is_pro_installed && !$is_pro_licensed): ?>
							<a href="#PixGrow-license-key" id="hero-activate-cta" class="button button-primary btn-glow"
								style="background: #fbbf24; border-color: #f59e0b; color: #0f172a; height: 42px; line-height: 40px; font-weight: 700; text-align: center; padding: 0 24px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s ease; text-decoration: none; box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);">
								<span class="dashicons dashicons-key" style="margin-top: 1px;"></span>
								<?php esc_html_e('Activate License Key', 'pixgrow-image-optimizer'); ?>
							</a>
						<?php endif; ?>
						<?php if (!$is_pro_licensed): ?>
							<a href="https://www.hisantosh.com/PixGrow/pro/" target="_blank" class="button button-secondary"
								style="height: 42px; line-height: 40px; font-weight: 600; text-align: center; padding: 0 24px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s ease; border-color: rgba(255, 255, 255, 0.15) !important; color: #ffffff;">
								<span class="dashicons dashicons-star-filled" style="color: #fbbf24; margin-top: 1px;"></span>
								<?php esc_html_e('Upgrade to Pro Plan', 'pixgrow-image-optimizer'); ?>
							</a>
						<?php else: ?>
							<div class="pixgrow-activated-badge-pulse"
								style="background: linear-gradient(135deg, #f59e0b, #fb923c) !important; color: #ffffff !important; height: 42px; line-height: 42px; font-weight: 700; text-align: center; padding: 0 24px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 0 12px rgba(245, 158, 11, 0.4); font-size: 0.95rem;">
								<span class="dashicons dashicons-yes-alt" style="font-size: 1.25rem; width: 1.25rem; height: 1.25rem; margin-top: 1px;"></span>
								<?php esc_html_e('✓ Activated', 'pixgrow-image-optimizer'); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Section Title: Your Account -->
			<div class="PixGrow-section-heading pixgrow-section-heading wasmpress-section-heading"
				style="margin: 32px 0 20px 0; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; gap: 10px;">
				<span class="dashicons dashicons-admin-users"
					style="color: #6366f1; font-size: 1.4rem; width: 1.4rem; height: 1.4rem; line-height: 1.4;"></span>
				<h3 style="font-size: 1.25rem; font-weight: 700; color: #ffffff; margin: 0;">
					<?php esc_html_e('Your Account', 'pixgrow-image-optimizer'); ?></h3>
			</div>

			<!-- Main Content Row: 60/40 Layout ratio -->
			<div class="PixGrow-account-grid pixgrow-account-grid wasmpress-account-grid">
				<!-- License Management Card (60%) -->
				<div class="PixGrow-account-card pixgrow-account-card wasmpress-account-card PixGrow-license-card pixgrow-license-card wasmpress-license-card">
					<h3><span class="dashicons dashicons-key"></span>
						<?php esc_html_e('License Management', 'pixgrow-image-optimizer'); ?></h3>

					<?php if (!$is_pro_installed): ?>
						<!-- Scenario 1: Free Plugin Only Installed -->
						<div class="license-free-notice" style="margin-top: 16px;">
							<p style="color: #ffffff; font-size: 0.95rem; font-weight: 600; margin-bottom: 12px;">
								<?php esc_html_e('You are using PixGrow Free.', 'pixgrow-image-optimizer'); ?></p>
							<p style="color: #cbd5e1; font-size: 0.9rem; line-height: 1.5; margin-bottom: 16px;">
								<?php esc_html_e('To activate a Pro license:', 'pixgrow-image-optimizer'); ?></p>
							<ol
								style="color: #cbd5e1; font-size: 0.9rem; line-height: 1.6; padding-left: 20px; margin-bottom: 24px;">
								<li><?php esc_html_e('Purchase PixGrow Pro.', 'pixgrow-image-optimizer'); ?></li>
								<li><?php esc_html_e('Install and activate the PixGrow Pro Addon plugin.', 'pixgrow-image-optimizer'); ?>
								</li>
								<li><?php esc_html_e('Return here to activate your license.', 'pixgrow-image-optimizer'); ?></li>
							</ol>
							<div style="display: flex; gap: 12px;">
								<a href="https://www.hisantosh.com/PixGrow/pro/" target="_blank"
									class="button button-primary btn-glow"
									style="background: #fbbf24; border-color: #f59e0b; color: #0f172a; height: 40px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; border-radius: 8px; padding: 0 20px; text-decoration: none; font-size: 0.9rem; gap: 6px; transition: all 0.3s ease;">
									<span class="dashicons dashicons-star-filled"></span>
									<?php esc_html_e('Upgrade to Pro', 'pixgrow-image-optimizer'); ?>
								</a>
								<button id="btn-view-pricing-tab" class="button button-secondary"
									style="height: 40px; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; border-radius: 8px; padding: 0 20px; font-size: 0.9rem; color: #ffffff; border-color: rgba(255, 255, 255, 0.15) !important;">
									<span class="dashicons dashicons-tag"></span>
									<?php esc_html_e('View Pricing', 'pixgrow-image-optimizer'); ?>
								</button>
							</div>
						</div>
					<?php elseif (!$is_pro_licensed): ?>
						<!-- Scenario 2: Pro Addon Installed But Not Licensed -->
						<p class="description" style="margin: 8px 0 20px 0; font-size: 0.88rem; color:#cbd5e1; line-height: 1.5;">
							<?php esc_html_e('Enter your PixGrow Pro license key below to activate premium features.', 'pixgrow-image-optimizer'); ?>
						</p>
						<div class="license-form-container" style="display:flex; flex-direction:column; gap:16px;">
							<input type="text" id="pixgrow-license-key"
								placeholder="<?php esc_attr_e('Enter License Key (e.g. wp_...)', 'pixgrow-image-optimizer'); ?>"
								class="widefat"
								style="height:42px; box-sizing:border-box; width: 100%; border-radius: 8px; font-family: monospace;">
							<button id="btn-activate-license" class="button button-primary btn-glow widefat"
								style="background:#fbbf24; border-color:#f59e0b; color:#0f172a; height:42px; font-weight:700; width: 100%; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 0.95rem; gap: 8px; transition: all 0.3s ease;">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e('Activate License Key', 'pixgrow-image-optimizer'); ?>
							</button>
							<div class="license-links"
								style="display:flex; justify-content:space-between; font-size:0.85rem; margin-top:4px;">
								<a href="https://www.hisantosh.com/PixGrow/support/" target="_blank"
									style="color:#818cf8; text-decoration:underline; font-weight: 600;"><?php esc_html_e('Get License Key', 'pixgrow-image-optimizer'); ?></a>
								<a href="https://www.hisantosh.com/PixGrow/docs/" target="_blank"
									style="color:#94a3b8; text-decoration:underline;"><?php esc_html_e('Licensing Help & FAQ', 'pixgrow-image-optimizer'); ?></a>
							</div>
						</div>
					<?php else:
						$masked_key = '';
						if (!$is_sandbox) {
							if (is_object($license) && isset($license->secret_key)) {
								if (class_exists('FS_Plugin_License') && method_exists('FS_Plugin_License', 'mask_secret_key_for_html')) {
									$masked_key = FS_Plugin_License::mask_secret_key_for_html($license->secret_key);
								} else {
									$key = $license->secret_key;
									$masked_key = substr($key, 0, 4) . str_repeat('•', max(0, strlen($key) - 8)) . substr($key, -4);
								}
							}
						}
						$install_id = is_object($fs) && is_object($fs->get_site()) ? $fs->get_site()->id : '';
						$blog_id = is_multisite() ? get_current_blog_id() : '';
						?>
						<!-- Scenario 3: Pro Addon Installed And Licensed -->
						<div class="license-active-info"
							style="margin-top: 16px; display: flex; flex-direction: column; gap: 12px;">
							<div class="license-info-row"
								style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px;">
								<span
									style="color: #cbd5e1; font-weight: 500;"><?php esc_html_e('License Status:', 'pixgrow-image-optimizer'); ?></span>
								<span style="color: #34d399; font-weight: 700;"><?php echo esc_html($license_status); ?></span>
							</div>
							<div class="license-info-row"
								style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px;">
								<span
									style="color: #cbd5e1; font-weight: 500;"><?php esc_html_e('Plan:', 'pixgrow-image-optimizer'); ?></span>
								<span style="color: #ffffff; font-weight: 600;"><?php echo esc_html($plan_status); ?></span>
							</div>
							<?php if (!empty($renewal_status)): ?>
								<div class="license-info-row"
									style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px;">
									<span
										style="color: #cbd5e1; font-weight: 500;"><?php esc_html_e('Expiration Date:', 'pixgrow-image-optimizer'); ?></span>
									<span style="color: #cbd5e1; font-weight: 500;"><?php echo esc_html($renewal_status); ?></span>
								</div>
							<?php endif; ?>
							<?php if (!$is_sandbox && !empty($masked_key)): ?>
								<div class="license-info-row"
									style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px;">
									<span
										style="color: #cbd5e1; font-weight: 500;"><?php esc_html_e('License Key:', 'pixgrow-image-optimizer'); ?></span>
									<code
										style="color: #ffffff; font-family: monospace; font-size: 0.9rem;"><?php echo esc_html($masked_key); ?></code>
								</div>
							<?php endif; ?>

							<?php if (!$is_sandbox): ?>
								<div style="display: flex; gap: 12px; margin-top: 12px;">
									<a href="https://users.freemius.com/" target="_blank" class="button button-secondary"
										style="height: 40px; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; border-radius: 8px; padding: 0 16px; font-size: 0.88rem; color: #ffffff; border-color: rgba(255, 255, 255, 0.15) !important;">
										<span class="dashicons dashicons-admin-links"></span>
										<?php esc_html_e('Manage Subscription', 'pixgrow-image-optimizer'); ?>
									</a>
									<button id="btn-sync-license" class="button button-secondary"
										style="height: 40px; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; border-radius: 8px; padding: 0 16px; font-size: 0.88rem; color: #ffffff; border-color: rgba(255, 255, 255, 0.15) !important;">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e('Sync License', 'pixgrow-image-optimizer'); ?>
									</button>
									<button id="btn-deactivate-license" class="button button-link"
										style="color: #ef4444; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: none; cursor: pointer; font-size: 0.88rem; transition: color 0.2s ease;">
										<span class="dashicons dashicons-lock" style="color: #ef4444;"></span>
										<?php esc_html_e('Deactivate License', 'pixgrow-image-optimizer'); ?>
									</button>
								</div>

								<!-- Hidden native Freemius deactivation form -->
								<form id="pixgrow-deactivate-form"
									action="<?php echo esc_url(admin_url('admin.php?page=pixgrow-image-optimizer-pro')); ?>"
									method="POST" style="display: none;">
									<input type="hidden" name="fs_action" value="deactivate_license">
									<input type="hidden" name="install_id" value="<?php echo esc_attr($install_id); ?>">
									<input type="hidden" name="blog_id" value="<?php echo esc_attr($blog_id); ?>">
									<?php wp_nonce_field(trim("deactivate_license:{$blog_id}:{$install_id}", ':')); ?>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<!-- Account Status Card (40%) -->
				<div class="PixGrow-account-card pixgrow-account-card wasmpress-account-card PixGrow-status-card pixgrow-status-card wasmpress-status-card">
					<h3><span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e('Account Status', 'pixgrow-image-optimizer'); ?></h3>
					<div class="status-rows" style="display:flex; flex-direction:column; gap:16px; margin-top:20px;">
						<div class="status-row"
							style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom:12px;">
							<span
								class="status-label"><?php esc_html_e('Plugin Version:', 'pixgrow-image-optimizer'); ?></span>
							<span
								style="color:#ffffff; font-weight:600; font-family:monospace;"><?php echo esc_html(PIXGROW_VERSION); ?></span>
						</div>
						<div class="status-row"
							style="display:flex; justify-content:space-between; align-items:center; <?php echo ($is_pro && !empty($renewal_status)) ? 'border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom:12px;' : ''; ?>">
							<span class="status-label"><?php esc_html_e('License Type:', 'pixgrow-image-optimizer'); ?></span>
							<span style="color:#ffffff; font-weight:600;"><?php echo esc_html($license_type); ?></span>
						</div>
						<?php if ($is_pro && !empty($renewal_status)): ?>
							<div class="status-row" style="display:flex; justify-content:space-between; align-items:center;">
								<span
									class="status-label"><?php esc_html_e('Renewal Status:', 'pixgrow-image-optimizer'); ?></span>
								<span
									style="color:#cbd5e1; font-weight:500; font-size: 0.9rem;"><?php echo esc_html($renewal_status); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php if (!$is_sandbox): ?>
				<!-- 2. Middle Section: Pricing plans card -->
				<div id="pixgrow-pricing-plans" class="PixGrow-card pixgrow-card wasmpress-card pricing-card" style="margin-top:32px;">
					<div class="pricing-header" style="text-align:center; margin-bottom:36px;">
						<h2 style="font-size:2rem; font-weight:800; color:#ffffff; margin:0 0 10px 0;">
							<?php esc_html_e('PixGrow Pro Pricing Plans', 'pixgrow-image-optimizer'); ?></h2>
						<p class="tagline"
							style="color:#cbd5e1; font-size:1.05rem; max-width:600px; margin:0 auto; line-height:1.45;">
							<?php esc_html_e('Unlock high-performance client-side compression, unthrottled queues, automated uploads, and path replacements.', 'pixgrow-image-optimizer'); ?>
						</p>
					</div>

					<div class="pricing-grid">
						<!-- Monthly Pro -->
						<div class="pricing-box">
							<div>
								<h4 style="color:#ffffff; font-size:1.3rem; margin-top:0; font-weight:700; margin-bottom:12px;">
									<?php esc_html_e('Monthly Pro', 'pixgrow-image-optimizer'); ?></h4>
								<div class="price" style="font-size: 2.2rem; font-weight:800; color:#ffffff; margin-bottom:6px;">$15
									<span class="period" style="font-size:0.95rem; font-weight:400; color:#94a3b8;">/
										<?php esc_html_e('month', 'pixgrow-image-optimizer'); ?></span></div>
								<p class="price-desc"
									style="color:#cbd5e1; font-size:0.9rem; margin-top:0; margin-bottom:20px; line-height:1.45;">
									<?php esc_html_e('Perfect for active blogs & small WooCommerce stores.', 'pixgrow-image-optimizer'); ?>
								</p>
								<ul class="pricing-features" style="list-style:none; padding:0; margin:0 0 24px 0;">
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><?php esc_html_e('WebP & MozJPEG Target Format support', 'pixgrow-image-optimizer'); ?>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><?php esc_html_e('Unthrottled Bulk Queue (No limits)', 'pixgrow-image-optimizer'); ?>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><?php esc_html_e('Automated Background Uploads', 'pixgrow-image-optimizer'); ?>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><?php esc_html_e('Visual Comparison Slider', 'pixgrow-image-optimizer'); ?>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><?php esc_html_e('Priority Ticket Support', 'pixgrow-image-optimizer'); ?>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#64748b; text-decoration:line-through;">
										<span class="dashicons dashicons-no text-danger"
											style="color:#f87171;"></span><?php esc_html_e('Live Chat Support', 'pixgrow-image-optimizer'); ?>
									</li>
								</ul>
							</div>

							<div class="buy-pro-cta-container" style="margin-top: 16px;">
								<a href="https://www.hisantosh.com/PixGrow/pro/" target="_blank"
									class="button button-primary btn-glow widefat buy-pro-btn"
									style="height:40px; display:flex; align-items:center; justify-content:center; text-decoration: none; border-radius: 8px; font-weight: 700;">
									<?php esc_html_e('Get Started Pro →', 'pixgrow-image-optimizer'); ?>
								</a>
							</div>
						</div>

						<!-- Yearly Pro -->
						<div class="pricing-box pricing-premium" style="position: relative;">
							<div class="popular-badge"
								style="position:absolute; top:-12px; right:20px; background:#fbbf24; color:#0f172a; font-size:0.75rem; font-weight:800; padding:4px 14px; border-radius:9999px; text-transform:uppercase; letter-spacing:0.5px; box-shadow: 0 2px 8px rgba(251,191,36,0.3);">
								<?php esc_html_e('Best Value', 'pixgrow-image-optimizer'); ?></div>
							<div>
								<h4 style="color:#ffffff; font-size:1.3rem; margin-top:0; font-weight:700; margin-bottom:12px;">
									<?php esc_html_e('Yearly Pro', 'pixgrow-image-optimizer'); ?></h4>
								<div class="price" style="font-size: 2.2rem; font-weight:800; color:#ffffff; margin-bottom:6px;">
									$120 <span class="period" style="font-size:0.95rem; font-weight:400; color:#94a3b8;">/
										<?php esc_html_e('year', 'pixgrow-image-optimizer'); ?></span></div>
								<p class="price-desc"
									style="color:#cbd5e1; font-size:0.9rem; margin-top:0; margin-bottom:20px; line-height:1.45;">
									<?php esc_html_e('Best for agencies, active publishers, and eCommerce.', 'pixgrow-image-optimizer'); ?>
								</p>
								<ul class="pricing-features" style="list-style:none; padding:0; margin:0 0 24px 0;">
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><strong><?php esc_html_e('WebP & MozJPEG Target Format support', 'pixgrow-image-optimizer'); ?></strong>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><strong><?php esc_html_e('Unthrottled Bulk Queue (No limits)', 'pixgrow-image-optimizer'); ?></strong>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><strong><?php esc_html_e('Automated Background Uploads', 'pixgrow-image-optimizer'); ?></strong>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><strong><?php esc_html_e('1-Click Reference Autopilot', 'pixgrow-image-optimizer'); ?></strong>
									</li>
									<li
										style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:0.92rem; color:#cbd5e1;">
										<span class="dashicons dashicons-yes text-success"
											style="color:#34d399; font-weight: bold;"></span><strong><?php esc_html_e('Direct Live Support (24/7)', 'pixgrow-image-optimizer'); ?></strong>
									</li>
								</ul>
							</div>

							<div class="buy-pro-cta-container" style="margin-top: 16px;">
								<a href="https://www.hisantosh.com/PixGrow/pro/" target="_blank"
									class="button button-primary btn-glow widefat buy-pro-btn btn-buy-yearly"
									style="height:40px; display:flex; align-items:center; justify-content:center; text-decoration: none; border-radius: 8px; font-weight: 700;">
									<?php esc_html_e('Buy Yearly Pro →', 'pixgrow-image-optimizer'); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

				<!-- 3. Bottom Section: Feature Comparison Density Map (Collapsed by Default) -->
				<details class="PixGrow-collapsible-density pixgrow-collapsible-density wasmpress-collapsible-density" style="margin-top: 32px;">
					<summary class="PixGrow-collapsible-summary pixgrow-collapsible-summary wasmpress-collapsible-summary"
						style="display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.45); border: 1px solid rgba(255, 255, 255, 0.08); padding: 16px 20px; border-radius: 8px; cursor: pointer; color: #ffffff; font-weight: 600; font-size: 1rem; transition: all 0.3s ease;">
						<span style="display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-analytics" style="color: #fbbf24;"></span>
							<?php esc_html_e('Compare Free vs Pro Features', 'pixgrow-image-optimizer'); ?>
						</span>
						<span class="summary-chevron dashicons dashicons-arrow-right-alt2"
							style="transition: transform 0.3s ease; color: #94a3b8;"></span>
					</summary>
					<div class="PixGrow-collapsible-content pixgrow-collapsible-content wasmpress-collapsible-content"
						style="padding: 24px; border: 1px solid rgba(255, 255, 255, 0.08); border-top: none; border-radius: 0 0 8px 8px; background: rgba(15, 23, 42, 0.2);">
						<div class="PixGrow-table-responsive pixgrow-table-responsive wasmpress-table-responsive">
							<table class="PixGrow-table pixgrow-table wasmpress-table PixGrow-comparison-table pixgrow-comparison-table wasmpress-comparison-table"
								style="margin: 0 !important; width: 100% !important;">
								<thead>
									<tr>
										<th scope="col" style="text-align:left;">
											<?php esc_html_e('Capability / Feature', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" style="text-align:center; width:150px;">
											<?php esc_html_e('PixGrow Free', 'pixgrow-image-optimizer'); ?></th>
										<th scope="col" style="text-align:center; width:180px; color:#fbbf24;">
											<?php esc_html_e('PixGrow Pro', 'pixgrow-image-optimizer'); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><strong><?php esc_html_e('Compression Engine', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('WebAssembly browser-powered local processing', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-included"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-included"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Target Formats Support', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Convert attachments dynamically', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#cbd5e1; font-weight:500;">
											<?php esc_html_e('WebP & MozJPEG', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="formats"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Smart Format Routing', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Batch Upload Queue limits', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Optimize image library in bulk clicks', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-included"><?php esc_html_e('Unlimited', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Unlimited', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Automated Background upload', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Optimize images instantly on media library upload', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#cbd5e1; font-weight:500;">
											<?php esc_html_e('Manual queue run', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="automation"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Automated on Upload', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Static path search & replacement', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Scan and replace hardcoded theme/content links', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#cbd5e1; font-weight:500;">
											<?php esc_html_e('Scan references', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="scanner"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('1-Click Auto Update', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Visual Comparison slider', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Side-by-side quality slide comparison overlays', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="compare"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Pro Feature', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-included"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Original Attachment Restore', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Instant 1-click restore to original raw image', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="restore"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Pro Feature', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center;"><span
												class="badge-feature badge-feature-included"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Safe 1-Click Rollback', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Restore all path replacements dynamically', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#94a3b8; font-weight:400;">
											<?php esc_html_e('Not Applicable', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="scanner"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Excel CSV logs export', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Download full spreadsheet files of audit metrics', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#94a3b8; font-weight:400;">
											<?php esc_html_e('Pro Feature', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="csv"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Included', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e('Customer support SLA', 'pixgrow-image-optimizer'); ?></strong><br><span
												style="font-size:0.8rem; color:#94a3b8; font-weight:400;"><?php esc_html_e('Help desk response guarantees', 'pixgrow-image-optimizer'); ?></span>
										</td>
										<td style="text-align:center; color:#cbd5e1; font-weight:400;">
											<?php esc_html_e('Online Manuals', 'pixgrow-image-optimizer'); ?></td>
										<td class="PixGrow-pro-lock-click pixgrow-pro-lock-click wasmpress-pro-lock-click" data-feature="support"
											style="text-align:center; cursor:pointer;"><span
												class="badge-feature badge-feature-pro"><?php esc_html_e('Ticket / Live support', 'pixgrow-image-optimizer'); ?></span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</details>
			<?php endif; ?>

			<!-- 4. Advanced Account Tools (Collapsed by Default) -->
			<details class="PixGrow-collapsible-advanced pixgrow-collapsible-advanced wasmpress-collapsible-advanced" style="margin-top: 32px;">
				<summary class="PixGrow-collapsible-summary pixgrow-collapsible-summary wasmpress-collapsible-summary"
					style="display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.45); border: 1px solid rgba(255, 255, 255, 0.08); padding: 16px 20px; border-radius: 8px; cursor: pointer; color: #ffffff; font-weight: 600; font-size: 1rem; transition: all 0.3s ease;">
					<span style="display: flex; align-items: center; gap: 8px;">
						<span class="dashicons dashicons-admin-tools" style="color: #6366f1;"></span>
						<?php esc_html_e('Advanced Account Tools', 'pixgrow-image-optimizer'); ?>
					</span>
					<span class="summary-chevron dashicons dashicons-arrow-right-alt2"
						style="transition: transform 0.3s ease; color: #94a3b8;"></span>
				</summary>
				<div class="PixGrow-collapsible-content pixgrow-collapsible-content wasmpress-collapsible-content"
					style="padding: 24px; border: 1px solid rgba(255, 255, 255, 0.08); border-top: none; border-radius: 0 0 8px 8px; background: rgba(15, 23, 42, 0.2);">
					<!-- Freemius tools available notification -->
					<p style="color: #cbd5e1; font-size: 0.9rem; margin-top: 0;">
						<?php esc_html_e('Advanced tools and external license integrations are available when the PixGrow Pro Addon is active.', 'pixgrow-image-optimizer'); ?>
					</p>

					<div class="advanced-links"
						style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.85rem;">
						<div style="display: flex; gap: 16px;">
							<a href="https://www.hisantosh.com/PixGrow/terms/" target="_blank"
								style="color: #94a3b8; text-decoration: underline;"><?php esc_html_e('Terms of Service', 'pixgrow-image-optimizer'); ?></a>
							<a href="https://www.hisantosh.com/PixGrow/privacy/" target="_blank"
								style="color: #94a3b8; text-decoration: underline;"><?php esc_html_e('Privacy Policy', 'pixgrow-image-optimizer'); ?></a>
						</div>
						<a href="https://users.freemius.com/" target="_blank"
							style="color: #818cf8; text-decoration: underline; font-weight: 500;"><?php esc_html_e('Freemius User Portal →', 'pixgrow-image-optimizer'); ?></a>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint to save bulk queue state in user_meta.
	 */
	public function ajax_save_queue_state()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$user_id = get_current_user_id();
		$state = isset($_POST['state']) ? map_deep(wp_unslash($_POST['state']), 'sanitize_text_field') : array();

		if (!is_array($state)) {
			wp_send_json_error(array('message' => __('Invalid queue state format.', 'pixgrow-image-optimizer')));
		}

		// Sanitize the queue state array recursively
		$sanitized_state = array();
		if (isset($state['status'])) {
			$sanitized_state['status'] = sanitize_key($state['status']);
		}
		if (isset($state['current_item'])) {
			$sanitized_state['current_item'] = absint($state['current_item']);
		}
		if (isset($state['queue_items']) && is_array($state['queue_items'])) {
			$sanitized_state['queue_items'] = array_map('absint', $state['queue_items']);
		}
		if (isset($state['processed_items']) && is_array($state['processed_items'])) {
			$sanitized_state['processed_items'] = array_map('absint', $state['processed_items']);
		}
		if (isset($state['failed_items']) && is_array($state['failed_items'])) {
			$sanitized_state['failed_items'] = array();
			foreach ($state['failed_items'] as $id => $msg) {
				$sanitized_state['failed_items'][absint($id)] = sanitize_text_field($msg);
			}
		}
		$sanitized_state['timestamp'] = time();

		if (empty($sanitized_state['queue_items']) && empty($sanitized_state['processed_items'])) {
			delete_user_meta($user_id, '_pixgrow_bulk_queue_state');
		} else {
			update_user_meta($user_id, '_pixgrow_bulk_queue_state', $sanitized_state);
		}

		wp_send_json_success(array('message' => __('Queue state saved.', 'pixgrow-image-optimizer')));
	}

	/**
	 * AJAX endpoint to retrieve bulk queue state from user_meta.
	 */
	public function ajax_get_queue_state()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$user_id = get_current_user_id();
		$state = get_user_meta($user_id, '_pixgrow_bulk_queue_state', true);

		if (!is_array($state)) {
			$state = array();
		}

		wp_send_json_success($state);
	}

	/**
	 * AJAX endpoint to extend concurrency lock (heartbeat lock refresh).
	 */
	public function ajax_heartbeat_lock()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$attachment_id = isset($_POST['attachment_id']) ? absint(wp_unslash($_POST['attachment_id'])) : 0;
		if (!$attachment_id) {
			wp_send_json_error(array('message' => __('Invalid attachment ID.', 'pixgrow-image-optimizer')));
		}

		$lock_key = 'pixgrow_lock_post_' . $attachment_id;
		set_transient($lock_key, 1, 60); // Extend for another 60 seconds

		wp_send_json_success(array('message' => __('Lock extended.', 'pixgrow-image-optimizer')));
	}

	/**
	 * AJAX endpoint to fetch system diagnostics telemetry parameters.
	 */
	public function ajax_get_diagnostics()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$start_time = microtime(true);
		$start_mem = memory_get_peak_usage();
		$initial_queries = $GLOBALS['wpdb']->num_queries;

		$upload_dir = wp_upload_dir();
		$salt = get_option('pixgrow_backup_salt');
		if (!$salt) {
			$random_data = function_exists('wp_generate_password') ? wp_generate_password(32, true, true) : uniqid(wp_rand(), true);
			$salt = md5(uniqid($random_data, true));
			update_option('pixgrow_backup_salt', $salt);
		}

		$backup_dir = $upload_dir['basedir'] . '/pixgrow/backups-' . $salt;
		$log_dir = $upload_dir['basedir'] . '/pixgrow/logs-' . $salt;

		$diagnostics = array(
			'php_version' => PHP_VERSION,
			'wp_version' => get_bloginfo('version'),
			'pixgrow_version' => PIXGROW_VERSION,
			'memory_limit' => ini_get('memory_limit'),
			'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : 'N/A',
			'wp_max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : 'N/A',
			'upload_dir' => array(
				'path' => $upload_dir['basedir'],
				'writable' => wp_is_writable($upload_dir['basedir']),
			),
			'backup_dir' => array(
				'path' => $backup_dir,
				'writable' => file_exists($backup_dir) ? wp_is_writable($backup_dir) : wp_is_writable($upload_dir['basedir']),
			),
			'log_dir' => array(
				'path' => $log_dir,
				'writable' => file_exists($log_dir) ? wp_is_writable($log_dir) : wp_is_writable($upload_dir['basedir']),
			),
			'codecs' => array(
				'gd' => extension_loaded('gd') ? 'GD loaded' : 'GD not loaded',
				'imagick' => extension_loaded('imagick') ? 'Imagick loaded' : 'Imagick not loaded',
			),
			'disk_free' => 'N/A',
		);

		if (extension_loaded('gd')) {
			$gd_info = gd_info();
			$gd_formats = array();
			if (!empty($gd_info['WebP Support'])) {
				$gd_formats[] = 'WebP';
			}
			if (!empty($gd_info['JPEG Support']) || !empty($gd_info['JPG Support'])) {
				$gd_formats[] = 'JPEG';
			}
			if (!empty($gd_info['PNG Support'])) {
				$gd_formats[] = 'PNG';
			}
			$diagnostics['codecs']['gd_formats'] = implode(', ', $gd_formats);
		}

		if (extension_loaded('imagick')) {
			$im_formats = array();
			try {
				$im = new Imagick();
				$formats = $im->queryFormats();
				foreach (array('WEBP', 'JPEG', 'PNG') as $f) {
					if (in_array($f, $formats, true)) {
						$im_formats[] = $f;
					}
				}
			} catch (Exception $e) {
				// Imagick query failed
			}
			$diagnostics['codecs']['imagick_formats'] = !empty($im_formats) ? implode(', ', $im_formats) : 'None detected';
		}

		if (function_exists('disk_free_space')) {
			$free_space = @disk_free_space($upload_dir['basedir']);
			$total_space = @disk_total_space($upload_dir['basedir']);
			if (false !== $free_space && false !== $total_space) {
				$diagnostics['disk_free'] = size_format($free_space) . ' / ' . size_format($total_space);
			}
		}

		$duration = (microtime(true) - $start_time) * 1000;
		$end_mem = memory_get_peak_usage();
		$queries_run = $GLOBALS['wpdb']->num_queries - $initial_queries;

		$diagnostics['telemetry'] = array(
			'time_ms' => round($duration, 2),
			'queries' => $queries_run,
			'peak_mem_mb' => round($end_mem / 1024 / 1024, 2)
		);

		wp_send_json_success($diagnostics);
	}

	/**
	 * AJAX endpoint to retrieve system logs.
	 */
	public function ajax_get_logs()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$upload_dir = wp_upload_dir();
		$salt = get_option('pixgrow_backup_salt');
		if (!$salt) {
			wp_send_json_error(array('message' => __('System salt not initialized.', 'pixgrow-image-optimizer')));
		}

		$log_file = $upload_dir['basedir'] . '/pixgrow/logs-' . $salt . '/pixgrow_debug.log';

		if (!file_exists($log_file)) {
			wp_send_json_success(array('logs' => __('No logs generated yet.', 'pixgrow-image-optimizer')));
		}

		$content = file_get_contents($log_file);
		if (false === $content) {
			wp_send_json_error(array('message' => __('Failed to read log file.', 'pixgrow-image-optimizer')));
		}

		wp_send_json_success(array('logs' => esc_html($content)));
	}

	/**
	 * AJAX endpoint to clear system logs.
	 */
	public function ajax_clear_logs()
	{
		check_ajax_referer('pixgrow_nonce', 'security');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'pixgrow-image-optimizer')));
		}

		$upload_dir = wp_upload_dir();
		$salt = get_option('pixgrow_backup_salt');
		if (!$salt) {
			wp_send_json_error(array('message' => __('System salt not initialized.', 'pixgrow-image-optimizer')));
		}

		$log_file = $upload_dir['basedir'] . '/pixgrow/logs-' . $salt . '/pixgrow_debug.log';

		if (file_exists($log_file)) {
			$header = "[LOG ROTATED/CLEARED AT " . current_time('mysql') . "]\n";
			if (false === @file_put_contents($log_file, $header)) {
				wp_send_json_error(array('message' => __('Failed to clear log file.', 'pixgrow-image-optimizer')));
			}
		}

		wp_send_json_success(array('message' => __('Logs cleared successfully.', 'pixgrow-image-optimizer')));
	}
}

/**
 * Helper to get the post/page/product usage info for an image attachment.
 */
function pixgrow_get_image_usage( $attachment_id )
{
	global $wpdb;

	// 1. Check Featured Image
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
		(string) $attachment_id
	));

	// 2. Check WooCommerce Product Gallery
	if (!$post_id) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value) LIMIT 1",
			$attachment_id
		));
	}

	// 3. Check wp-image-[id] in post_content
	if (!$post_id) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM $wpdb->posts WHERE post_content LIKE %s AND post_status = 'publish' LIMIT 1",
			'%' . $wpdb->esc_like('wp-image-' . $attachment_id) . '%'
		));
	}

	// 4. Check filename in post_content
	if (!$post_id) {
		$file = get_attached_file($attachment_id);
		if ($file) {
			$filename = basename($file);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_id = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_content LIKE %s AND post_status = 'publish' LIMIT 1",
				'%' . $wpdb->esc_like($filename) . '%'
			));
		}
	}

	if ($post_id) {
		$post = get_post($post_id);
		if ($post) {
			return array(
				'title' => $post->post_title ?: __('(Untitled)', 'pixgrow-image-optimizer'),
				'type' => $post->post_type,
				'edit_url' => get_edit_post_link($post_id)
			);
		}
	}

	return array(
		'title' => '',
		'type' => '',
		'edit_url' => ''
	);
}

/**
 * Helper to get the base64-encoded thumbnail data URI of an attachment.
 */
function pixgrow_get_attachment_thumb_base64($id)
{
	$metadata = wp_get_attachment_metadata($id);
	$thumb_path = '';
	if (is_array($metadata) && isset($metadata['sizes']['thumbnail']['file'])) {
		$thumb_file = $metadata['sizes']['thumbnail']['file'];
		$thumb_path = dirname(get_attached_file($id)) . '/' . $thumb_file;
	}
	if (!$thumb_path || !file_exists($thumb_path)) {
		$thumb_path = get_attached_file($id);
	}
	if ($thumb_path && file_exists($thumb_path)) {
		$size = @filesize($thumb_path);
		if ($size > 0 && $size < 3 * 1024 * 1024) {
			$content = @file_get_contents($thumb_path);
			if (false !== $content) {
				$ext = strtolower(pathinfo($thumb_path, PATHINFO_EXTENSION));
				$mime_types = array(
					'jpg' => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png' => 'image/png',
					'gif' => 'image/gif',
					'webp' => 'image/webp'
				);
				$mime = isset($mime_types[$ext]) ? $mime_types[$ext] : 'image/' . $ext;
				return 'data:' . $mime . ';base64,' . base64_encode($content);
			}
		}
	}
	return '';
}

