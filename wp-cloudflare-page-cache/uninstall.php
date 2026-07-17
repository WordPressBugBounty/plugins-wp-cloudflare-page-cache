<?php

// if uninstall.php is not called by WordPress, die

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

delete_option( 'swcfpc_config' );
delete_option( 'swcfpc_version' );
delete_option( 'swcfpc_previous_version' );
delete_option( 'swcfpc_preloader_lock' );
delete_option( 'swcfpc_purge_cache_lock' );
delete_option( 'swcfpc_fc_ttl_registry' );

$parts = parse_url( home_url() );

if ( file_exists( WP_CONTENT_DIR . "/wp-cloudflare-super-page-cache/{$parts['host']}/debug.log" ) ) {
	@unlink( WP_CONTENT_DIR . "/wp-cloudflare-super-page-cache/{$parts['host']}/debug.log" );
}

if ( defined( 'SWCFPC_ADVANCED_CACHE' ) ) {
	@unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
}

$config_file_path = ABSPATH . 'wp-config.php';
if ( is_file( dirname( ABSPATH ) . '/wp-config.php' ) ) {
    $config_file_path = dirname( ABSPATH ) . '/wp-config.php';
}

$plugin_storage_main_path = WP_CONTENT_DIR . '/wp-cloudflare-super-page-cache/';

if ( file_exists( $config_file_path ) && is_writable( $config_file_path ) ) {

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	// Get content of the config file.
	$config_contents = $wp_filesystem->get_contents( $config_file_path );
	if( $config_contents === false ) {
		error_log( 'WP Cloudflare Super Page Cache Uninstall: Unable to read wp-config.php' );
	} else if( empty( $config_contents ) ) {
		error_log( 'WP Cloudflare Super Page Cache Uninstall: wp-config.php is empty' );
	} else {
		$config_file       = preg_split( '/\R/u', $config_contents );
		$config_file_count = count( $config_file );

		// Get WP_CACHE constant define to remove
		$constant = "define('WP_CACHE', false); // Added by WP Cloudflare Super Page Cache";

		// Lets find out if the constant WP_CACHE is defined by our plugin
		$is_wp_cache_exist = false;

		for ( $i = 0; $i < $config_file_count; ++$i ) {

			if ( ! preg_match( '/^define\(\s*\'([A-Z_]+)\',(.*)\)/', $config_file[ $i ], $match ) ) {
				continue;
			}

			if ( 'WP_CACHE' === $match[1] && strpos( $config_file[ $i ], 'Added by WP Cloudflare Super Page Cache' ) !== false ) {
				$is_wp_cache_exist = true;
				break;
			}
		}

		// Only modify if our WP_CACHE define exists
		if ( $is_wp_cache_exist ) {
			$new_config_contents = preg_replace( '/^\s*define\(\s*\'WP_CACHE\'\s*,\s*([^\s\)]*)\s*\).+Added by WP Cloudflare Super Page Cache.*/m', '', $config_contents );

			// Check if preg_replace succeeded
			if ( $new_config_contents === null ) {
				error_log( 'WP Cloudflare Super Page Cache Uninstall: preg_replace failed with error: ' . preg_last_error() );
			} else {
				$wp_filesystem->put_contents( $config_file_path, $new_config_contents, FS_CHMOD_FILE );
			}
		}
	}

}

$timestamp = wp_next_scheduled( 'swcfpc_cache_purge_cron' );
wp_unschedule_event( $timestamp, 'swcfpc_cache_purge_cron' );

foreach ( swcfpc_uninstall_get_all_site_directories() as $plugin_storage_path ) {
	if ( file_exists( $plugin_storage_path ) ) {
		delete_directory_recursive( $plugin_storage_path );
	}
}

$multisite_paths_manifest = $plugin_storage_main_path . 'multisite-paths.json';

if ( file_exists( $multisite_paths_manifest ) ) {
	@unlink( $multisite_paths_manifest );
}

if ( file_exists( $plugin_storage_main_path ) && is_directory_empty( $plugin_storage_main_path ) ) {
	rmdir( $plugin_storage_main_path );
}

/**
 * Resolve a single site's on-disk content directory from its home URL.
 *
 * @param string $home_url
 *
 * @return string
 */
function swcfpc_uninstall_get_site_directory( $home_url ) {
	$parts = parse_url( $home_url );
	$host  = ! empty( $parts['host'] ) ? $parts['host'] : '';

	if ( is_multisite() && ! is_subdomain_install() ) {
		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		if ( '' !== $path ) {
			$host .= '_' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $path );
		}
	}

	return WP_CONTENT_DIR . "/wp-cloudflare-super-page-cache/{$host}";
}

/**
 * Resolve every site's content directory on the network (just the current
 * site's on non-multisite installs), so uninstall doesn't orphan subsites'
 * directories on multisite subdirectory installs.
 *
 * @return array<int, string>
 */
function swcfpc_uninstall_get_all_site_directories() {
	$directories = [ swcfpc_uninstall_get_site_directory( home_url() ) ];

	if ( ! is_multisite() ) {
		return $directories;
	}

	foreach ( get_sites( [ 'fields' => 'ids' ] ) as $site_id ) {
		switch_to_blog( $site_id );
		$directories[] = swcfpc_uninstall_get_site_directory( home_url() );
		restore_current_blog();
	}

	return array_values( array_unique( $directories ) );
}

function delete_directory_recursive( $dir ) {
	if ( ! class_exists( 'RecursiveDirectoryIterator' ) || ! class_exists( 'RecursiveIteratorIterator' ) ) {
		return false;
	}

	$it    = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
	$files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $files as $file ) {

		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}   
	}

	rmdir( $dir );

	return true;

}


function is_directory_empty( $dir ) { 
	$handle = opendir( $dir );

	while ( false !== ( $entry = readdir( $handle ) ) ) {
		if ( $entry != '.' && $entry != '..' ) {
			closedir( $handle );
			return false;
		}
	}

	closedir( $handle );

	return true;

}
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'spc_assets_rules`' );