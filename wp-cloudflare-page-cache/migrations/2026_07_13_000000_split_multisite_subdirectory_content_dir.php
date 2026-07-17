<?php

use SPC\Utils\Helpers;
use SPC\Utils\Logger;

/**
 * Migration to split the plugin content directory for multisite subdirectory installs.
 */
return new class extends \ThemeisleSDK\Modules\Abstract_Migration {
	private const LEGACY_DIR_PURGED_OPTION = 'swcfpc_legacy_multisite_dir_purged';

	public function up() {
		if ( ! is_multisite() || is_subdomain_install() ) {
			return;
		}

		Helpers::create_plugin_content_dir();

		if ( get_site_option( self::LEGACY_DIR_PURGED_OPTION ) ) {
			return;
		}

		update_site_option( self::LEGACY_DIR_PURGED_OPTION, 1 );

		$parts      = parse_url( home_url() );
		$legacy_dir = WP_CONTENT_DIR . '/wp-cloudflare-super-page-cache/' . ( ! empty( $parts['host'] ) ? $parts['host'] : '' );

		foreach ( [ 'fallback_cache', 'cached_html_pages' ] as $sub_dir ) {
			$legacy_sub_dir = "{$legacy_dir}/{$sub_dir}";

			if ( file_exists( $legacy_sub_dir ) ) {
				Logger::log( 'migration::split_multisite_subdirectory_content_dir', "Purging stale shared cache directory {$legacy_sub_dir}." );
				Helpers::delete_directory_recursive( $legacy_sub_dir );
			}
		}
	}
};
