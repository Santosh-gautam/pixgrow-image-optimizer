<?php
/**
 * PixGrow Rotatory Logger
 * Writes to a dedicated, security-hardened logs directory inside wp-content/uploads.
 *
 * @package PixGrow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PixGrow_Logger {

	/**
	 * Log a message to the daily rotatory log file.
	 *
	 * @param string $message
	 * @param string $type
	 */
	public static function log( $message, $type = 'info' ) {
		$upload_dir = wp_upload_dir();
		$salt = get_option( 'pixgrow_backup_salt' );
		if ( ! $salt ) {
			return; // Salt not initialized yet
		}

		$log_dir = $upload_dir['basedir'] . '/pixgrow/logs-' . $salt;

		// 1. Create directory if not exists
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		// 2. Harden directory with security files
		$htaccess_file = $log_dir . '/.htaccess';
		$index_file = $log_dir . '/index.php';
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents( $htaccess_file, "Deny from all\n" );
		}
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '' );
		}

		$log_file = $log_dir . '/pixgrow_debug.log';

		// 3. Size-based Rotation: Limit to 1MB (1,048,576 bytes)
		if ( file_exists( $log_file ) && filesize( $log_file ) > 1024 * 1024 ) {
			self::rotate_logs( $log_file );
		}

		// 4. Write log line
		$timestamp = current_time( 'mysql' );
		$log_line = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $type ), $message );
		@file_put_contents( $log_file, $log_line, FILE_APPEND );
	}

	/**
	 * Rotate logs by keeping the latter half (approx 500KB) of the log file.
	 *
	 * @param string $log_file
	 */
	private static function rotate_logs( $log_file ) {
		$content = file_get_contents( $log_file );
		if ( ! $content ) {
			return;
		}

		// Slice from the middle of the content to preserve latest logs
		$length = strlen( $content );
		$half_content = substr( $content, $length / 2 );
		
		// Find first newline to start cleanly at a log line boundary
		$first_newline = strpos( $half_content, "\n" );
		if ( false !== $first_newline ) {
			$half_content = substr( $half_content, $first_newline + 1 );
		}

		$header = "[LOG ROTATED AT " . current_time( 'mysql' ) . " - OLD ENTRIES TRUNCATED TO CONSTRAIN SIZE]\n";
		@file_put_contents( $log_file, $header . $half_content );
	}
}
