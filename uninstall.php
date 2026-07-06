<?php
/**
 * PixGrow Uninstall Cleanup
 * Deletes all plugin-related metadata and backup directories when the plugin is deleted.
 *
 * @package PixGrow
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if the user opted to delete all backups and data on uninstall
$pixgrow_settings    = get_option( 'pixgrow_settings', array() );
$pixgrow_delete_data = isset( $pixgrow_settings['delete_data_on_uninstall'] ) ? (int) $pixgrow_settings['delete_data_on_uninstall'] : (int) get_option( 'pixgrow_delete_data_on_uninstall', 0 );
if ( ! $pixgrow_delete_data ) {
	// Exit early to preserve image backups and database metadata for the user
	return;
}

global $wpdb;

// 1. Delete all PixGrow attachment metadata records securely
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		'_pixgrow_%'
	)
);

// 2. Delete the local backups directory and all its files recursively
$pixgrow_upload_dir = wp_upload_dir();
$pixgrow_backup_salt = get_option( 'pixgrow_backup_salt' );
$pixgrow_backup_dir  = $pixgrow_backup_salt ? $pixgrow_upload_dir['basedir'] . '/pixgrow/backups-' . $pixgrow_backup_salt : $pixgrow_upload_dir['basedir'] . '/PixGrow/backups';
$pixgrow_log_dir     = $pixgrow_backup_salt ? $pixgrow_upload_dir['basedir'] . '/pixgrow/logs-' . $pixgrow_backup_salt : $pixgrow_upload_dir['basedir'] . '/PixGrow/logs';

if ( file_exists( $pixgrow_backup_dir ) || file_exists( $pixgrow_log_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		if ( file_exists( $pixgrow_backup_dir ) ) {
			$wp_filesystem->delete( $pixgrow_backup_dir, true );
		}
		if ( file_exists( $pixgrow_log_dir ) ) {
			$wp_filesystem->delete( $pixgrow_log_dir, true );
		}
		$pixgrow_parent_dir = $pixgrow_upload_dir['basedir'] . '/PixGrow';
		if ( is_dir( $pixgrow_parent_dir ) ) {
			$pixgrow_temp_files = array_diff( @scandir( $pixgrow_parent_dir ), array( '.', '..' ) );
			if ( empty( $pixgrow_temp_files ) ) {
				$wp_filesystem->delete( $pixgrow_parent_dir, true );
			}
		}
	}
}

// Delete the backup salt option
delete_option( 'pixgrow_backup_salt' );

// 3. Clean up the uninstall setting option itself
delete_option( 'pixgrow_delete_data_on_uninstall' );
delete_option( 'pixgrow_settings' );
