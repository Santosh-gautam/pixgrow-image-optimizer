<?php
/**
 * Plugin Name:       PixGrow Image Optimizer
 * Plugin URI:        https://hisantosh.com/pixgrow-image-optimizer/
 * Description:       Compress and resize images directly in your browser using WebAssembly (Wasm). Save 100% server CPU and prevent execution timeouts. Includes automatic backup, restore, and static path replacement.
 * Version:           1.0.2
 * Author:            Hisantosh
 * Author URI:        https://hisantosh.com
 * License:           GPLv2 or later
 * Text Domain:       pixgrow-image-optimizer
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define constants
define( 'PIXGROW_VERSION', '1.0.2' );
define( 'PIXGROW_PATH', plugin_dir_path( __FILE__ ) );
define( 'PIXGROW_URL', plugin_dir_url( __FILE__ ) );

// Include core classes
require_once PIXGROW_PATH . 'includes/class-pixgrow-admin.php';
require_once PIXGROW_PATH . 'includes/class-pixgrow-logger.php';
require_once PIXGROW_PATH . 'includes/class-webp-handler.php';
require_once PIXGROW_PATH . 'includes/class-webp-delivery.php';

// Initialize core components
add_action( 'plugins_loaded', 'pixgrow_init' );
add_action( 'plugins_loaded', 'pixgrow_migrate_legacy_settings', 5 );

function pixgrow_init() {
	// Initialize classes to hook into WordPress
	new PixGrow_Admin();
	new PixGrow_WebP_Handler();
	new PixGrow_WebP_Delivery();
}

/**
 * Migration routine to move legacy options into unified settings array options.
 * Atomic and idempotent to handle partial executions safely.
 */
function pixgrow_migrate_legacy_settings() {
	// If the migration flag is set, but the new options don't exist in the database,
	// force-run the migration to heal the broken migration state.
	$pixgrow_settings_exist = ( false !== get_option( 'pixgrow_settings' ) || false !== get_option( 'pixgrow_pro_settings' ) );
	if ( ! $pixgrow_settings_exist ) {
		delete_option( 'pixgrow_settings_migrated' );
	}

	if ( get_option( 'pixgrow_settings_migrated' ) ) {
		return;
	}

	// 1. Prepare Core settings in memory from wasmpress_settings or pixgrow_settings
	$settings = get_option( 'wasmpress_settings', false );
	if ( false === $settings ) {
		$settings = get_option( 'pixgrow_settings', array() );
	}
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$delete_on_uninstall = get_option( 'wasmpress_delete_data_on_uninstall', false );
	if ( false === $delete_on_uninstall ) {
		$delete_on_uninstall = get_option( 'pixgrow_delete_data_on_uninstall' );
	}
	if ( false !== $delete_on_uninstall ) {
		$settings['delete_data_on_uninstall'] = (int) $delete_on_uninstall;
	}
	if ( ! isset( $settings['backup_enabled'] ) ) {
		$settings['backup_enabled'] = 1;
	}
	if ( ! isset( $settings['replace_confirm'] ) ) {
		$settings['replace_confirm'] = 1;
	}
	if ( ! isset( $settings['debug_options'] ) ) {
		$settings['debug_options'] = 0;
	}

	// 2. Prepare Pro settings in memory from wasmpress_pro_settings or pixgrow_pro_settings
	$pro_settings = get_option( 'wasmpress_pro_settings', false );
	if ( false === $pro_settings ) {
		$pro_settings = get_option( 'pixgrow_pro_settings', array() );
	}
	if ( ! is_array( $pro_settings ) ) {
		$pro_settings = array();
	}
	$auto_optimize = get_option( 'wasmpress_auto_optimize_on_upload', false );
	if ( false === $auto_optimize ) {
		$auto_optimize = get_option( 'pixgrow_auto_optimize_on_upload' );
	}
	if ( false !== $auto_optimize ) {
		$pro_settings['auto_optimize_on_upload'] = (int) $auto_optimize;
	}
	$defaults = array(
		'format'               => 'webp',
		'quality'              => 80,
		'resize'               => 0,
		'max_width'            => 1920,
		'background_queue'     => 1,
		'queue_behavior'       => 'pause_on_error',
		'retry_handling'       => 'manual',
		'transparent_handling' => 'webp',
		'logs_preference'      => 'all',
		'future_safe'          => 0,
	);
	foreach ( $defaults as $key => $val ) {
		if ( ! isset( $pro_settings[ $key ] ) ) {
			$pro_settings[ $key ] = $val;
		}
	}

	// 3. Update arrays in DB (Atomic write check)
	update_option( 'pixgrow_settings', $settings );
	update_option( 'pixgrow_pro_settings', $pro_settings );

	// 4. Delete legacy keys only after successful new option writes
	if ( false !== $delete_on_uninstall ) {
		delete_option( 'pixgrow_delete_data_on_uninstall' );
		delete_option( 'wasmpress_delete_data_on_uninstall' );
	}
	if ( false !== $auto_optimize ) {
		delete_option( 'pixgrow_auto_optimize_on_upload' );
		delete_option( 'wasmpress_auto_optimize_on_upload' );
	}

	// Log migration success
	PixGrow_Logger::log( '[Migration] WasmPress settings migrated successfully', 'info' );

	// 5. Save migration completed flag
	update_option( 'pixgrow_settings_migrated', 1 );
}

// Register activation hook
register_activation_hook( __FILE__, 'pixgrow_activate' );

/**
 * Perform activation tasks (e.g. creating the backup directory)
 * Built with safety wrappers to gracefully handle strict filesystem write restrictions.
 */
function pixgrow_activate() {
	$backup_dir = pixgrow_get_backup_dir();
	if ( ! file_exists( $backup_dir ) ) {
		wp_mkdir_p( $backup_dir );
	}

	// Double check directory availability and write files safely using WP_Filesystem
	if ( file_exists( $backup_dir ) && wp_is_writable( $backup_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$index_file = $backup_dir . '/index.php';
		$htaccess_file = $backup_dir . '/.htaccess';

		if ( $wp_filesystem ) {
			if ( ! $wp_filesystem->exists( $index_file ) ) {
				$wp_filesystem->put_contents( $index_file, '', FS_CHMOD_FILE );
			}
			if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
				$wp_filesystem->put_contents( $htaccess_file, "Deny from all\n", FS_CHMOD_FILE );
			}
		} else {
			// Fallback if WP_Filesystem initialization failed
			if ( ! file_exists( $index_file ) ) {
				@file_put_contents( $index_file, '' );
			}
			if ( ! file_exists( $htaccess_file ) ) {
				@file_put_contents( $htaccess_file, "Deny from all\n" );
			}
		}
	}
}

/**
 * Resolves the path to the backup directory.
 * If salt doesn't exist, it is generated and saved.
 *
 * @param int  $attachment_id Optional attachment ID.
 * @param bool $relative      Whether to return a relative path from the site root.
 * @return string
 */
function pixgrow_get_backup_dir( $attachment_id = 0, $relative = false ) {
	$upload_dir = wp_upload_dir();
	
	// Fetch or generate site-specific secure unique salt
	$salt = get_option( 'pixgrow_backup_salt' );
	if ( ! $salt ) {
		$random_data = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, true, true ) : uniqid( wp_rand(), true );
		$salt = md5( uniqid( $random_data, true ) );
		update_option( 'pixgrow_backup_salt', $salt );
	}
	
	$folder_name = 'pixgrow/backups-' . $salt;
	
	if ( $relative ) {
		$base = 'wp-content/uploads/' . $folder_name;
	} else {
		$base = $upload_dir['basedir'] . '/' . $folder_name;
	}
	
	if ( $attachment_id > 0 ) {
		return $base . '/attachment_' . $attachment_id;
	}
	
	return $base;
}

/**
 * Retrieves original and compressed sizes for an attachment.
 * Decoupled global helper replacing class static method in Free.
 *
 * @param int $attachment_id Attachment ID.
 * @return array Size data.
 */
function pixgrow_get_attachment_sizes( $attachment_id ) {
	$original_size = get_post_meta( $attachment_id, '_pixgrow_original_size', true );
	$compressed_size = get_post_meta( $attachment_id, '_pixgrow_compressed_size', true );

	$file_path = get_attached_file( $attachment_id );
	$current_size = ( $file_path && file_exists( $file_path ) ) ? @filesize( $file_path ) : 0;

	if ( ! $original_size ) {
		$original_size = $current_size;
	}
	if ( ! $compressed_size ) {
		$compressed_size = $current_size;
	}

	return array(
		'original'   => $original_size,
		'compressed' => $compressed_size,
		'savings'    => $original_size > $compressed_size ? round( (($original_size - $compressed_size) / $original_size) * 100 ) : 0
	);
}


/**
 * Retrieves a core PixGrow setting from the unified settings option array.
 * Includes backward compatibility fallbacks for older standalone options.
 *
 * @param string $key     Setting key to retrieve.
 * @param mixed  $default Default value if not set.
 * @return mixed
 */
function pixgrow_get_setting( $key, $default = '' ) {
	$settings = get_option( 'pixgrow_settings', array() );
	if ( is_array( $settings ) && isset( $settings[ $key ] ) ) {
		return $settings[ $key ];
	}

	// Backward compatibility fallback
	if ( 'delete_data_on_uninstall' === $key ) {
		return get_option( 'pixgrow_delete_data_on_uninstall', $default );
	}
	if ( 'backup_enabled' === $key ) {
		return 1; // Default to true for safety
	}
	if ( 'replace_confirm' === $key ) {
		return 1; // Default to true for safety
	}
	if ( 'debug_options' === $key ) {
		return 0; // Default to false
	}

	return $default;
}

/**
 * Retrieves a premium PixGrow Pro setting from the unified pro settings option array.
 * Includes backward compatibility fallbacks for older standalone options.
 *
 * @param string $key     Setting key to retrieve.
 * @param mixed  $default Default value if not set.
 * @return mixed
 */
function pixgrow_get_pro_setting( $key, $default = '' ) {
	$pro_settings = get_option( 'pixgrow_pro_settings', false );
	if ( false === $pro_settings ) {
		$pro_settings = get_option( 'wasmpress_pro_settings', array() );
	}
	if ( is_array( $pro_settings ) && isset( $pro_settings[ $key ] ) ) {
		return $pro_settings[ $key ];
	}

	// Backward compatibility fallbacks
	if ( 'auto_optimize_on_upload' === $key ) {
		$auto_optimize = get_option( 'pixgrow_auto_optimize_on_upload', false );
		if ( false === $auto_optimize ) {
			$auto_optimize = get_option( 'wasmpress_auto_optimize_on_upload', $default );
		}
		return $auto_optimize;
	}

	// Default fallback values for new Pro options
	$defaults = array(
		'format'               => 'webp',
		'quality'              => 80,
		'resize'               => 0,
		'max_width'            => 1920,
		'background_queue'     => 1,
		'queue_behavior'       => 'pause_on_error',
		'retry_handling'       => 'manual',
		'transparent_handling' => 'webp',
		'logs_preference'      => 'all',
		'future_safe'          => 0,
	);

	if ( isset( $defaults[ $key ] ) ) {
		return $defaults[ $key ];
	}

	return $default;
}

// Add plugin action links in plugins list screen
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pixgrow_add_action_links' );

function pixgrow_add_action_links( $links ) {
	$settings_url = admin_url( 'admin.php?page=pixgrow-image-optimizer&tab=settings' );
	$docs_url     = admin_url( 'admin.php?page=pixgrow-docs' );
	
	$action_links = array(
		'settings' => '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'pixgrow-image-optimizer' ) . '</a>',
		'docs'     => '<a href="' . esc_url( $docs_url ) . '">' . esc_html__( 'Documentation', 'pixgrow-image-optimizer' ) . '</a>',
	);

	return array_merge( $action_links, $links );
}



