<?php

namespace SPC\Utils;

use SPC\Constants;
use SPC\Modules\Dashboard;
use SPC\Services\Settings_Store;

class Helpers {

	private const BYPASS_CACHE_REASON_HEADER = 'X-WP-CF-Super-Cache-Disabled-Reason';

	/**
	 * Check if the request is a cacheable request
	 *
	 * @param string $reason The reason why the request is not cacheable.
	 *
	 * @return void
	 */
	public static function bypass_reason_header( $reason = '' ) {
		if ( empty( $reason ) ) {
			return;
		}

		header( sprintf( '%s: %s', self::BYPASS_CACHE_REASON_HEADER, $reason ) );
	}

	/**
	 * Checks if we have the cache bypass reason header.
	 *
	 * @return bool
	 */
	public static function has_cache_bypass_reason_header() {
		return null !== self::get_cache_bypass_reason_header();
	}

	/**
	 * Get the reason recorded by the fallback cache for bypassing the current request.
	 *
	 * @return string|null
	 */
	public static function get_cache_bypass_reason_header(): ?string {
		foreach ( headers_list() as $header ) {
			$parts = explode( ':', $header, 2 );

			if ( 2 === count( $parts ) && 0 === strcasecmp( trim( $parts[0] ), self::BYPASS_CACHE_REASON_HEADER ) ) {
				return trim( $parts[1] );
			}
		}

		return null;
	}

	/**
	 * Decide whether a fallback-cache rejection must also bypass shared edge caches.
	 *
	 * The trailing-slash check historically only prevented disk caching. On sites whose
	 * canonical permalink structure is intentionally unslashed (including plain
	 * permalinks), preserve that edge-cache behaviour. Every other fallback rejection
	 * represents a response that must not be stored in a shared cache.
	 *
	 * @param string|null $reason Fallback-cache bypass reason.
	 *
	 * @return bool
	 */
	public static function should_demote_fallback_cache_bypass( ?string $reason ): bool {
		if ( null === $reason ) {
			return false;
		}

		if ( ! in_array( $reason, [ 'Not a slashed URL', 'URL Without Trailing Slash' ], true ) ) {
			return true;
		}

		$permalink_structure = (string) get_option( 'permalink_structure', '' );

		return '' !== $permalink_structure && '/' === substr( $permalink_structure, -1 );
	}

	/**
	 * Media types that page caches may store verbatim.
	 *
	 * @var string[]
	 */
	private const HTML_MEDIA_TYPES = [ 'text/html', 'application/xhtml+xml' ];

	/**
	 * Media types of the non-HTML public documents WordPress serves: feeds, XML sitemaps
	 * and robots.txt. Only honoured on requests WordPress identifies as one of those.
	 *
	 * @var string[]
	 */
	private const PUBLIC_DOCUMENT_MEDIA_TYPES = [
		'application/rss+xml',
		'application/atom+xml',
		'application/rdf+xml',
		'application/xml',
		'text/xml',
		'text/plain',
	];

	/**
	 * Check whether response headers describe a response that page caches may store.
	 *
	 * HTML is always acceptable. Non-HTML public documents (feeds, sitemaps, robots.txt)
	 * are only acceptable when the caller confirms WordPress is serving one of those —
	 * see {@see self::is_public_document_request()} — because the disk cache replays
	 * bodies as text/html, the minifier mutates them, and a custom `text/plain` or XML
	 * endpoint may carry user-specific data. Binary responses, exports and attachments
	 * are never cacheable.
	 *
	 * Responses without an explicit content type are allowed because WordPress
	 * themes commonly rely on the web server's default HTML content type.
	 *
	 * @param array<string|int, mixed>|object|null $headers                Response headers. Defaults to the current response.
	 * @param bool                                 $allow_public_documents Accept the public-document media types as well.
	 *
	 * @return bool
	 */
	public static function is_cacheable_response_headers( $headers = null, bool $allow_public_documents = false ) {
		foreach ( self::normalize_headers( $headers ) as [ $name, $value ] ) {
			if ( 'content-disposition' === $name && 'attachment' === self::header_token( $value ) ) {
				return false;
			}

			if ( 'content-type' === $name && ! self::is_cacheable_media_type( self::header_token( $value ), $allow_public_documents ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Media types that identify a file download or export rather than a web document:
	 * PDFs, archives, office documents, CSV/TSV exports and the generic binary types used
	 * by `readfile()` handlers. Matched exactly, or by prefix when the entry ends in `/`
	 * or `.`.
	 *
	 * @var string[]
	 */
	private const DOWNLOAD_MEDIA_TYPES = [
		'application/pdf',
		'application/octet-stream',
		'application/download',
		'application/x-download',
		'application/force-download',
		'application/zip',
		'application/x-zip-compressed',
		'application/gzip',
		'application/x-gzip',
		'application/x-tar',
		'application/x-7z-compressed',
		'application/x-rar-compressed',
		'application/vnd.rar',
		'application/msword',
		'application/vnd.ms-excel',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.',
		'application/vnd.oasis.opendocument.',
		'application/rtf',
		'application/epub+zip',
		'text/csv',
		'application/csv',
		'text/tab-separated-values',
	];

	/**
	 * Check whether response headers describe a file download: an `attachment`
	 * disposition, or one of {@see self::DOWNLOAD_MEDIA_TYPES}.
	 *
	 * Deliberately a deny-list. It is used on responses the plugin did not promote
	 * itself (a handler that exited before `template_redirect`), where everything that
	 * is not recognisably a download must keep whatever headers it had.
	 *
	 * @param array<string|int, mixed>|object|null $headers Response headers. Defaults to the current response.
	 *
	 * @return bool
	 */
	public static function is_download_response( $headers = null ): bool {
		/**
		 * Filter the media types treated as file downloads on cache-eligible URLs.
		 *
		 * @param string[] $media_types Lower-cased media types; a trailing `/` or `.` matches by prefix.
		 */
		$download_types = apply_filters( 'swcfpc_download_media_types', self::DOWNLOAD_MEDIA_TYPES );
		$download_types = is_array( $download_types ) ? array_map( 'strtolower', $download_types ) : [];

		foreach ( self::normalize_headers( $headers ) as [ $name, $value ] ) {
			if ( 'content-disposition' === $name && 'attachment' === self::header_token( $value ) ) {
				return true;
			}

			if ( 'content-type' !== $name ) {
				continue;
			}

			$media_type = self::header_token( $value );

			foreach ( $download_types as $download_type ) {
				$is_prefix = in_array( substr( $download_type, -1 ), [ '/', '.' ], true );

				if ( $media_type === $download_type || ( $is_prefix && strpos( $media_type, $download_type ) === 0 ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Flatten response headers into lower-cased `[ name, value ]` pairs.
	 *
	 * Accepts `headers_list()` output (`Name: value` strings), associative arrays with
	 * string or array values, and Requests' `CaseInsensitiveDictionary`.
	 *
	 * @param array<string|int, mixed>|object|null $headers Response headers. Defaults to the current response.
	 *
	 * @return array<int, array{string, string}>
	 */
	private static function normalize_headers( $headers ): array {
		if ( null === $headers ) {
			$headers = headers_list();
		}

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) && ! $headers instanceof \Traversable ) {
			return [];
		}

		$pairs = [];

		foreach ( $headers as $name => $value ) {
			if ( is_int( $name ) ) {
				$parts = explode( ':', (string) $value, 2 );

				if ( count( $parts ) !== 2 ) {
					continue;
				}

				[ $name, $value ] = $parts;
			}

			$name = strtolower( trim( (string) $name ) );

			foreach ( is_array( $value ) ? $value : [ $value ] as $single_value ) {
				$pairs[] = [ $name, trim( (string) $single_value ) ];
			}
		}

		return $pairs;
	}

	/**
	 * First token of a header value, lower-cased: the media type of a Content-Type, the
	 * disposition type of a Content-Disposition.
	 *
	 * @param string $value Header value.
	 *
	 * @return string
	 */
	private static function header_token( string $value ): string {
		return strtolower( trim( explode( ';', $value, 2 )[0] ) );
	}

	/**
	 * Whether the response declares its own freshness lifetime: a `Cache-Control` header
	 * with a positive `max-age` or `s-maxage`.
	 *
	 * Used to tell an explicit third-party caching policy (a plugin serving dynamic CSS
	 * with `public, max-age=300`) apart from the absence of one, which Cloudflare would
	 * resolve to its default edge TTL. Only the former is honoured on responses the
	 * plugin did not promote itself.
	 *
	 * @param array<string|int, mixed>|object|null $headers Response headers. Defaults to the current response.
	 *
	 * @return bool
	 */
	public static function response_declares_cache_lifetime( $headers = null ): bool {
		foreach ( self::normalize_headers( $headers ) as [ $name, $value ] ) {
			if ( 'cache-control' !== $name || ! preg_match_all( '/(?:^|[\\s,])(?:s-)?max-?age\\s*=\\s*"?(\\d+)/i', $value, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $seconds ) {
				if ( (int) $seconds > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether WordPress is serving a public, non-HTML document on this request:
	 * a feed, an XML sitemap (core or third-party such as Yoast) or robots.txt.
	 *
	 * Safe to call at any point of the request: before the main query has run the
	 * conditionals are all false, and third-party sitemaps are matched on the URL.
	 *
	 * @return bool
	 */
	public static function is_public_document_request(): bool {
		$wp_query = $GLOBALS['wp_query'] ?? null;

		if ( $wp_query instanceof \WP_Query && ( $wp_query->is_feed() || $wp_query->is_robots() ) ) {
			return true;
		}

		// Core sitemaps (`/wp-sitemap.xml`, `/wp-sitemap-posts-post-1.xml`, `/wp-sitemap.xsl`) have no
		// conditional tag; WP_Sitemaps identifies them by query var.
		if ( $wp_query instanceof \WP_Query && ( '' !== (string) $wp_query->get( 'sitemap' ) || '' !== (string) $wp_query->get( 'sitemap-stylesheet' ) ) ) {
			return true;
		}

		return self::is_sitemap_request_uri( $_SERVER['REQUEST_URI'] ?? '' );
	}

	/**
	 * Whether a request URI points at an XML sitemap (`/sitemap_index.xml`, `/post-sitemap.xml`, ...).
	 *
	 * @param string $request_uri Request URI.
	 *
	 * @return bool
	 */
	public static function is_sitemap_request_uri( string $request_uri ): bool {
		$path = (string) parse_url( $request_uri, PHP_URL_PATH );

		return strcasecmp( $path, '/sitemap_index.xml' ) === 0 || (bool) preg_match( '/[a-zA-Z0-9]-sitemap\.xml$/', $path );
	}

	/**
	 * Check whether a media type may be stored by page caches.
	 *
	 * @param string $media_type             Lower-cased media type without parameters.
	 * @param bool   $allow_public_documents Accept the public-document media types as well.
	 *
	 * @return bool
	 */
	private static function is_cacheable_media_type( string $media_type, bool $allow_public_documents ): bool {
		if ( in_array( $media_type, self::HTML_MEDIA_TYPES, true ) ) {
			return true;
		}

		if ( ! $allow_public_documents ) {
			return false;
		}

		/**
		 * Filter the non-HTML media types Cloudflare may cache on cache-eligible URLs.
		 *
		 * @param string[] $media_types Lower-cased media types without parameters.
		 */
		$media_types = apply_filters( 'swcfpc_cacheable_media_types', self::PUBLIC_DOCUMENT_MEDIA_TYPES );

		return is_array( $media_types ) && in_array( $media_type, array_map( 'strtolower', $media_types ), true );
	}

	/**
	 * Get the second level domain of the site.
	 *
	 * @return string
	 */
	public static function get_second_level_domain() {
		$site_hostname = parse_url( home_url(), PHP_URL_HOST );

		if ( is_null( $site_hostname ) ) {
			return '';
		}

		// get the domain name from the hostname
		$site_domain = preg_replace( '/^www\./', '', $site_hostname );

		return $site_domain;
	}

	/**
	 * Get the menu icon.
	 *
	 * @param string $fill The fill color of the icon.
	 *
	 * @return string
	 */
	public static function get_menu_icon( $fill = '#a7aaad' ) {
		$svg = '<svg width="185" height="229" viewBox="0 0 185 229" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#spc-clip-path)"><path d="M12.322 180.443L160.356 210.236L159.512 114.191H166.264L144.489 88.8715V189.305L35.7846 165.167C31.7335 164.492 29.286 158.078 29.286 153.942V128.623V36.8825L139.003 60.5139V43.0435L12.4064 15.6143L12.322 180.443Z" fill="' . esc_attr( $fill ) . '"/><path d="M134.446 124.992V161.114C134.469 162.372 134.21 163.618 133.69 164.763C133.169 165.907 132.399 166.921 131.437 167.73C130.474 168.539 129.343 169.122 128.126 169.438C126.909 169.754 125.637 169.793 124.402 169.554L42.1988 150.733L38.3165 143.475L42.1988 142.884L36.0377 131.913L115.962 149.89V131.322L46.0811 116.721C44.1149 116.362 42.3402 115.316 41.0729 113.771C39.8055 112.225 39.1278 110.28 39.1604 108.281V69.7116C39.1289 68.4282 39.3906 67.1545 39.9255 65.9875C40.4605 64.8204 41.2546 63.7908 42.2475 62.977C43.2403 62.1631 44.4058 61.5865 45.6551 61.291C46.9044 60.9956 48.2047 60.989 49.457 61.2718L133.855 78.9953L124.74 87.4351L128.875 87.9415L122.039 96.9721L55.7868 83.1308V101.192L128.031 116.721C129.875 117.177 131.511 118.241 132.675 119.742C133.839 121.243 134.463 123.093 134.446 124.992Z" fill="' . esc_attr( $fill ) . '"/><path d="M166.686 121.026V219.097L6.49863 184.325V8.35538L138.919 36.3755V29.1172L0 0L0.75958 190.992L173.438 228.38L173.016 138.581H185L166.686 121.026Z" fill="' . esc_attr( $fill ) . '"/></g><defs><clipPath id="spc-clip-path"><rect width="185" height="228.38" fill="white"/></clipPath></defs></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Check if the current page is a SPC admin page.
	 *
	 * @return bool
	 */
	public static function is_spc_admin_page() {
		if ( ! is_admin() ) {
			return false;
		}

		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}

		// About page is not a SPC page.
		if ( strpos( $_GET['page'], 'ti-about' ) === 0 ) {
			return false;
		}

		if ( strpos( $_GET['page'], Dashboard::PAGE_SLUG ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the plugin content directory path.
	 *
	 * @return string
	 */
	private static function get_site_directory_slug() {
		$parts = parse_url( home_url() );
		$host  = ! empty( $parts['host'] ) ? $parts['host'] : '';

		if ( ! is_multisite() || is_subdomain_install() ) {
			return $host;
		}

		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		if ( '' === $path ) {
			return $host;
		}

		return $host . '_' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $path );
	}

	/**
	 * Get the path to the multisite paths manifest file.
	 *
	 * @return string
	 */
	public static function get_multisite_paths_manifest_file() {
		return WP_CONTENT_DIR . '/wp-cloudflare-super-page-cache/multisite-paths.json';
	}

	/**
	 * Register the current site's path in the shared multisite-paths manifest.
	 *
	 * @return void
	 */
	private static function register_multisite_path() {
		if ( ! is_multisite() || is_subdomain_install() ) {
			return;
		}

		$parts = parse_url( home_url() );
		$host  = ! empty( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		if ( '' === $host || '' === $path ) {
			return;
		}

		$file     = self::get_multisite_paths_manifest_file();
		$decoded  = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : [];
		$manifest = is_array( $decoded ) ? $decoded : [];

		if ( empty( $manifest[ $host ] ) || ! is_array( $manifest[ $host ] ) ) {
			$manifest[ $host ] = [];
		}

		if ( in_array( $path, $manifest[ $host ], true ) ) {
			return;
		}

		$manifest[ $host ][] = $path;

		file_put_contents( $file, wp_json_encode( $manifest ), LOCK_EX );
	}

	/**
	 * Get the plugin content directory path.
	 *
	 * @return string
	 */
	public static function get_plugin_content_dir() {
		return WP_CONTENT_DIR . '/wp-cloudflare-super-page-cache/' . self::get_site_directory_slug();
	}

	/**
	 * Create the plugin content directory tree and its nginx.conf file.
	 *
	 * @return void
	 */
	public static function create_plugin_content_dir() {
		$path = WP_CONTENT_DIR . '/wp-cloudflare-super-page-cache/';

		if ( ! file_exists( $path ) && wp_mkdir_p( $path ) ) {
			file_put_contents( "{$path}index.php", '<?php // Silence is golden' );
		}

		$path .= self::get_site_directory_slug();

		if ( ! file_exists( $path ) && wp_mkdir_p( $path ) ) {
			file_put_contents( "{$path}/index.php", '<?php // Silence is golden' );
		}

		$nginx_conf = "{$path}/nginx.conf";

		if ( ! file_exists( $nginx_conf ) ) {
			file_put_contents( $nginx_conf, '' );
		}

		self::register_multisite_path();
	}

	/**
	 * Delete the plugin's host-scoped content directory recursively.
	 *
	 * @return void
	 */
	public static function delete_plugin_content_dir() {
		$path = self::get_plugin_content_dir();

		if ( file_exists( $path ) ) {
			self::delete_directory_recursive( $path );
		}

		self::deregister_multisite_path();
	}

	/**
	 * Remove the current site's path from the shared multisite-paths manifest.
	 *
	 * @return void
	 */
	private static function deregister_multisite_path() {
		if ( ! is_multisite() || is_subdomain_install() ) {
			return;
		}

		$parts = parse_url( home_url() );
		$host  = ! empty( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		if ( '' === $host || '' === $path ) {
			return;
		}

		$file = self::get_multisite_paths_manifest_file();

		if ( ! file_exists( $file ) ) {
			return;
		}

		$decoded  = json_decode( (string) file_get_contents( $file ), true );
		$manifest = is_array( $decoded ) ? $decoded : [];

		if ( empty( $manifest[ $host ] ) || ! is_array( $manifest[ $host ] ) ) {
			return;
		}

		$manifest[ $host ] = array_values( array_diff( $manifest[ $host ], [ $path ] ) );

		if ( empty( $manifest[ $host ] ) ) {
			unset( $manifest[ $host ] );
		}

		file_put_contents( $file, wp_json_encode( $manifest ), LOCK_EX );
	}

	/**
	 * Delete a directory and everything under it.
	 *
	 * @param string $dir Directory to delete.
	 *
	 * @return bool
	 */
	public static function delete_directory_recursive( $dir ) {
		if ( ! class_exists( 'RecursiveDirectoryIterator' ) || ! class_exists( 'RecursiveIteratorIterator' ) ) {
			return false;
		}

		$it    = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
		$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );

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

	/**
	 * Check whether the Pro plugin is installed on disk.
	 *
	 * Used to keep the Free → Pro upgrade non-destructive: when the Pro plugin
	 * is present (already active, or its activation/installation is underway),
	 * deactivating the Free plugin must not wipe the shared settings.
	 *
	 * @return bool
	 */
	public static function is_pro_installed() {
		// Pro is loaded in the current request (both active, or activation in progress).
		if ( defined( 'SPC_PRO_PATH' ) ) {
			return true;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( basename( $plugin_file ) === 'wp-cloudflare-super-page-cache-pro.php' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the plugin content directory url.
	 *
	 * @return string
	 */
	public static function get_plugin_content_dir_url() {
		$parts = parse_url( home_url() );
		$host  = ! empty( $parts['host'] ) ? $parts['host'] : '';

		return str_replace(
			[
				"https://{$host}",
				"http://{$host}",
			],
			'',
			content_url( 'wp-cloudflare-super-page-cache/' . self::get_site_directory_slug() )
		);
	}

	/**
	 * Get the current url.
	 *
	 * @return string
	 */
	public static function get_current_url() {
		return function_exists( 'swcfpc_normalize_url' ) ? swcfpc_normalize_url( null ) : null;
	}
	/**
	 * Get the current full url.
	 *
	 * @return string
	 */
	public static function get_current_absolute_url() {
		$current_url = self::get_current_url();
		$host        = $_SERVER['HTTP_HOST'] ?? '';
		if ( empty( $host ) ) {
			return $current_url;
		}
		/**
		 * If the current url does not start with the host, add the host to the current url.
		 * The scheme is just to normalize the url.
		 */
		return strpos( $current_url, $host ) === 0 ? "http://{$current_url}" : "http://{$host}/{$current_url}";
	}
	/**
	 * Get the url id.
	 *
	 * @param string $url The url.
	 *
	 * @return int
	 */
	public static function get_url_id( $url ) {
		srand( crc32( $url ) );
		$random_id = rand();
		srand();
		return $random_id;
	}

	/**
	 * Get the wp-config.php path.
	 *
	 * wp-config.php can be placed one level up from the root directory.
	 *
	 * @return string
	 */
	public static function get_wp_config_path() {
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}

		if ( is_file( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}

		return ABSPATH . 'wp-config.php';
	}

	/**
	 * Check if the current page is the login page.
	 *
	 * @return bool
	 */
	public static function is_login_page() {
		return in_array( $GLOBALS['pagenow'] ?? '', [ 'wp-login.php', 'wp-register.php' ], true );
	}

	/**
	 * Check if the current URL has a trailing slash.
	 *
	 * @return bool
	 */
	public static function does_current_url_have_trailing_slash() {
		return (bool) preg_match( '/\/$/', $_SERVER['REQUEST_URI'] ?? '' );
	}

	/**
	 * Check if the current request is an API request.
	 *
	 * @return bool
	 */
	public static function is_api_request() {
		$rest_base    = trim( parse_url( rest_url(), PHP_URL_PATH ), '/' );
		$request_path = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || strpos( $request_path, $rest_base ) === 0 ) {
			return true;
		}

		if ( strpos( $request_path, 'wc-api' ) === 0 ) {
			return true;
		}

		if ( strpos( $request_path, 'edd-api' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Case-insensitive wildcard match (`*` expands to `.*`).
	 *
	 * @param string $pattern The pattern to match.
	 * @param string $subject The subject to match.
	 *
	 * @return bool
	 */
	public static function wildcard_match( $pattern, $subject ) {
		$pattern = '#^' . preg_quote( $pattern ) . '$#i';
		$pattern = str_replace( '\*', '.*', $pattern );

		return (bool) preg_match( $pattern, $subject );
	}

	/**
	 * Rebuild a URL from a `parse_url()` result.
	 *
	 * @param array<string, string|int> $parsed_url The parsed URL.
	 *
	 * @return string
	 */
	public static function get_unparsed_url( $parsed_url ) {
		$scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
	}

	/**
	 * Get the list of query params to ignore when computing the cache key.
	 *
	 * @return array<int, string>
	 */
	public static function get_ignored_query_params() {
		return apply_filters( 'swcfpc_ignored_query_params', Constants::IGNORED_QUERY_PARAMS );
	}

	/**
	 * Get the home URL, with multisite + scheme/pagenow handling.
	 *
	 * @param int|null    $blog_id The blog ID.
	 * @param string      $path    The path.
	 * @param string|null $scheme  The scheme.
	 *
	 * @return string
	 */
	public static function get_home_url( $blog_id = null, $path = '', $scheme = null ) {
		global $pagenow;

		if ( empty( $blog_id ) || ! is_multisite() ) {
			$url = get_option( 'home' );
		} else {
			switch_to_blog( $blog_id );
			$url = get_option( 'home' );
			restore_current_blog();
		}

		if ( ! in_array( $scheme, [ 'http', 'https', 'relative' ], true ) ) {
			if ( is_ssl() && ! is_admin() && 'wp-login.php' !== $pagenow ) {
				$scheme = 'https';
			} else {
				$scheme = parse_url( $url, PHP_URL_SCHEME );
			}
		}

		$url = set_url_scheme( $url, $scheme );

		if ( $path && is_string( $path ) ) {
			$url .= '/' . ltrim( $path, '/' );
		}

		return $url;
	}

	/**
	 * Get the home URL for the current blog.
	 *
	 * @param string      $path   The path.
	 * @param string|null $scheme The scheme.
	 *
	 * @return string
	 */
	public static function home_url( $path = '', $scheme = null ) {
		return self::get_home_url( null, $path, $scheme );
	}

	/**
	 * Check if a URL points to a different host than the current site.
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	public static function is_external_link( $url ) {
		$source = parse_url( home_url() );
		$target = parse_url( $url );

		if ( ! $source || empty( $source['host'] ) || ! $target || empty( $target['host'] ) ) {
			return false;
		}

		return strcasecmp( $target['host'], $source['host'] ) !== 0;
	}

	/**
	 * Check if the current user can purge the cache.
	 *
	 * @return bool
	 */
	public static function can_current_user_purge_cache() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$allowed_roles = Settings_Store::get_instance()->get( Constants::SETTING_PURGE_ROLES );
		if ( count( $allowed_roles ) < 1 ) {
			return false;
		}

		$user = wp_get_current_user();
		foreach ( $allowed_roles as $role_name ) {
			if ( in_array( $role_name, (array) $user->roles, true ) ) {
				return true;
			}
		}

		return false;
	}
}
