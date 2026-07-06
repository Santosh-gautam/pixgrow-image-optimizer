<?php
/**
 * WebP handler class to coordinate server-side WebP compression and fallback companion uploads.
 *
 * @package PixGrow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PixGrow_WebP_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_pixgrow_compress_attachment', array( $this, 'ajax_compress_attachment' ) );
		add_action( 'wp_ajax_pixgrow_save_companion_webp', array( $this, 'ajax_save_companion_webp' ) );
	}

	/**
	 * AJAX handler to compress an attachment server-side.
	 */
	public function ajax_compress_attachment() {
		check_ajax_referer( 'pixgrow_nonce', 'security' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pixgrow-image-optimizer' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pixgrow-image-optimizer' ) ) );
		}

		$main_file = get_attached_file( $attachment_id );
		if ( ! $main_file || ! file_exists( $main_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Main attachment file not found.', 'pixgrow-image-optimizer' ) ) );
		}

		$original_ext = strtolower( pathinfo( $main_file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $original_ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unsupported image format.', 'pixgrow-image-optimizer' ) ) );
		}

		// Verify server WebP support
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			wp_send_json_error( array(
				'code'    => 'server_webp_unsupported',
				'message' => __( 'Server does not support WebP compression via GD/Imagick.', 'pixgrow-image-optimizer' ),
			) );
		}

		$webp_file = $main_file . '.webp';

		// Load image editor
		$editor = wp_get_image_editor( $main_file );
		if ( is_wp_error( $editor ) ) {
			wp_send_json_error( array( 'message' => $editor->get_error_message() ) );
		}

		// Retrieve Pro/Free compression settings (Quality & Resize)
		$pro_settings = get_option( 'pixgrow_pro_settings', array() );
		$resize       = isset( $pro_settings['resize'] ) ? (int) $pro_settings['resize'] : 0;
		$max_width    = isset( $pro_settings['max_width'] ) ? (int) $pro_settings['max_width'] : 1920;
		$quality      = isset( $pro_settings['quality'] ) ? (int) $pro_settings['quality'] : 80;

		// Perform resize on main image if requested
		if ( $resize && $max_width ) {
			$size = $editor->get_size();
			if ( $size && $size['width'] > $max_width ) {
				$editor->resize( $max_width, null, false );
			}
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $webp_file, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}

		// Compress intermediate thumbnails if they exist
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$base_dir = dirname( $main_file );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_name => $size_data ) {
				$thumb_path = $base_dir . '/' . $size_data['file'];
				if ( file_exists( $thumb_path ) ) {
					$thumb_webp_path = $thumb_path . '.webp';
					$thumb_editor    = wp_get_image_editor( $thumb_path );
					if ( ! is_wp_error( $thumb_editor ) ) {
						$thumb_editor->set_quality( $quality );
						$thumb_editor->save( $thumb_webp_path, 'image/webp' );
					}
				}
			}
		}

		// Record meta metrics under Option B (non-destructive)
		$this->write_meta_metrics( $attachment_id, $main_file, $webp_file );

		PixGrow_Logger::log( sprintf( 'Compressed attachment %d (%s) to WebP server-side.', $attachment_id, basename( $main_file ) ), 'success' );

		wp_send_json_success( array(
			'message'       => __( 'Attachment optimized successfully.', 'pixgrow-image-optimizer' ),
			'new_size'      => size_format( filesize( $webp_file ) ),
			'url'           => wp_get_attachment_url( $attachment_id ),
			'relative_path' => _wp_relative_upload_path( $webp_file ),
		) );
	}

	/**
	 * AJAX handler to upload and save WebP companion files when server WebP is missing.
	 */
	public function ajax_save_companion_webp() {
		check_ajax_referer( 'pixgrow_nonce', 'security' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pixgrow-image-optimizer' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pixgrow-image-optimizer' ) ) );
		}

		$main_file = get_attached_file( $attachment_id );
		if ( ! $main_file || ! file_exists( $main_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Main attachment file not found.', 'pixgrow-image-optimizer' ) ) );
		}

		// Initialize the WordPress Filesystem API.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( $wp_filesystem ) {
			$upload_dir = wp_get_upload_dir();
			$basedir    = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
			if ( $basedir ) {
				$basedir = rtrim( $basedir, '/' ) . '/';
			}

			// Save uploaded main companion WebP
			$webp_file = $main_file . '.webp';
			if ( isset( $_FILES['main_file'] ) && is_array( $_FILES['main_file'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$main_file_data = $_FILES['main_file'];
				$main_error     = isset( $main_file_data['error'] ) ? absint( $main_file_data['error'] ) : UPLOAD_ERR_NO_FILE;
				$main_tmp_name  = isset( $main_file_data['tmp_name'] ) ? wp_unslash( $main_file_data['tmp_name'] ) : '';

				if ( UPLOAD_ERR_OK === $main_error && ! empty( $main_tmp_name ) && is_uploaded_file( $main_tmp_name ) ) {
					// Verify destination path is inside wp_get_upload_dir()['basedir']
					if ( $basedir && strpos( wp_normalize_path( $webp_file ), $basedir ) === 0 ) {
						if ( $wp_filesystem->move( $main_tmp_name, $webp_file, true ) ) {
							$wp_filesystem->chmod( $webp_file, 0644 );
						}
					}
				}
			}

			// Save uploaded companion thumbnail WebPs
			$meta     = wp_get_attachment_metadata( $attachment_id );
			$base_dir = dirname( $main_file );

			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_name => $size_data ) {
					$file_key = 'thumb_' . $size_name;
					if ( isset( $_FILES[ $file_key ] ) && is_array( $_FILES[ $file_key ] ) ) {
						// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$thumb_file_data = $_FILES[ $file_key ];
						$thumb_error     = isset( $thumb_file_data['error'] ) ? absint( $thumb_file_data['error'] ) : UPLOAD_ERR_NO_FILE;
						$thumb_tmp_name  = isset( $thumb_file_data['tmp_name'] ) ? wp_unslash( $thumb_file_data['tmp_name'] ) : '';

						if ( UPLOAD_ERR_OK === $thumb_error && ! empty( $thumb_tmp_name ) && is_uploaded_file( $thumb_tmp_name ) ) {
							$thumb_path      = $base_dir . '/' . $size_data['file'];
							$thumb_webp_path = $thumb_path . '.webp';
							// Verify destination path is inside wp_get_upload_dir()['basedir']
							if ( $basedir && strpos( wp_normalize_path( $thumb_webp_path ), $basedir ) === 0 ) {
								if ( $wp_filesystem->move( $thumb_tmp_name, $thumb_webp_path, true ) ) {
									$wp_filesystem->chmod( $thumb_webp_path, 0644 );
								}
							}
						}
					}
				}
			}
		}

		// Record meta metrics under Option B (non-destructive)
		$this->write_meta_metrics( $attachment_id, $main_file, $webp_file );

		PixGrow_Logger::log( sprintf( 'Saved Canvas fallback WebP companion for attachment %d (%s).', $attachment_id, basename( $main_file ) ), 'success' );

		wp_send_json_success( array(
			'message'       => __( 'Canvas companion WebPs saved successfully.', 'pixgrow-image-optimizer' ),
			'new_size'      => size_format( file_exists( $webp_file ) ? filesize( $webp_file ) : filesize( $main_file ) ),
			'url'           => wp_get_attachment_url( $attachment_id ),
			'relative_path' => _wp_relative_upload_path( $webp_file ),
		) );
	}

	/**
	 * Writes optimization post meta metrics to DB under Option B constraints.
	 *
	 * @param int    $attachment_id
	 * @param string $main_file
	 * @param string $webp_file
	 */
	private function write_meta_metrics( $attachment_id, $main_file, $webp_file ) {
		$original_size = @filesize( $main_file ) ?: 0;
		$compressed    = ( file_exists( $webp_file ) && is_readable( $webp_file ) ) ? @filesize( $webp_file ) : 0;

		update_post_meta( $attachment_id, '_pixgrow_original_size', $original_size );
		update_post_meta( $attachment_id, '_pixgrow_compressed_size', $compressed ?: $original_size );
		update_post_meta( $attachment_id, '_pixgrow_original_filename', basename( $main_file ) );
		update_post_meta( $attachment_id, '_pixgrow_optimized', 1 );
		update_post_meta( $attachment_id, '_pixgrow_webp_exists', 1 );
		update_post_meta( $attachment_id, '_pixgrow_optimized_time', current_time( 'mysql' ) );

		clean_post_cache( $attachment_id );
	}
}
