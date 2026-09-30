<?php

namespace SPC\Modules;

use SPC\Constants;
use SPC\Services\Cloudflare_Integration;
use SPC\Services\Settings_Store;
use SPC\Utils\Cache_Tester;
use SPC\Utils\Helpers;
use WP_Error;

defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Registers the plugin abilities with the WordPress Abilities API (WP 6.9+).
 */
class Abilities implements Module_Interface {
	public const CATEGORY = 'super-page-cache';

	private const TYPE_BOOLEAN = 'boolean';
	private const TYPE_INTEGER = 'integer';
	private const TYPE_LIST    = 'array';

	private const MAX_PURGE_URLS   = 100;
	private const MAX_PURGE_POSTS  = 50;
	private const MAX_PURGE_TAGS   = 1000;
	private const MAX_PRELOAD_URLS = 50;

	/**
	 * Cache policy fields exposed by the abilities, mapped to the plugin setting keys.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const POLICY_FIELDS = [
		'disk_cache_enabled'            => [ Constants::SETTING_ENABLE_FALLBACK_CACHE, self::TYPE_BOOLEAN ],
		'cloudflare_cache_rule_enabled' => [ Constants::ENABLE_CACHE_RULE, self::TYPE_BOOLEAN ],
		'cloudflare_max_age'            => [ Constants::SETTING_CACHE_MAX_AGE, self::TYPE_INTEGER ],
		'browser_max_age'               => [ Constants::SETTING_BROWSER_CACHE_MAX_AGE, self::TYPE_INTEGER ],
		'disk_cache_ttl'                => [ Constants::SETTING_FALLBACK_CACHE_LIFESPAN, self::TYPE_INTEGER ],
		'stale_while_revalidate'        => [ Constants::SETTING_STALE_WHILE_REVALIDATE, self::TYPE_BOOLEAN ],
		'stale_while_revalidate_ttl'    => [ Constants::SETTING_STALE_WHILE_REVALIDATE_TTL, self::TYPE_INTEGER ],
		'excluded_urls'                 => [ Constants::SETTING_EXCLUDED_URLS, self::TYPE_LIST ],
		'excluded_cookies'              => [ Constants::SETTING_EXCLUDED_COOKIES, self::TYPE_LIST ],
		'bypass_404'                    => [ Constants::SETTING_BYPASS_404, self::TYPE_BOOLEAN ],
		'bypass_single_post'            => [ Constants::SETTING_BYPASS_SINGLE_POST, self::TYPE_BOOLEAN ],
		'bypass_pages'                  => [ Constants::SETTING_BYPASS_PAGES, self::TYPE_BOOLEAN ],
		'bypass_front_page'             => [ Constants::SETTING_BYPASS_FRONT_PAGE, self::TYPE_BOOLEAN ],
		'bypass_home'                   => [ Constants::SETTING_BYPASS_HOME, self::TYPE_BOOLEAN ],
		'bypass_archives'               => [ Constants::SETTING_BYPASS_ARCHIVES, self::TYPE_BOOLEAN ],
		'bypass_tags'                   => [ Constants::SETTING_BYPASS_TAGS, self::TYPE_BOOLEAN ],
		'bypass_category'               => [ Constants::SETTING_BYPASS_CATEGORY, self::TYPE_BOOLEAN ],
		'bypass_feeds'                  => [ Constants::SETTING_BYPASS_FEEDS, self::TYPE_BOOLEAN ],
		'bypass_search_pages'           => [ Constants::SETTING_BYPASS_SEARCH_PAGES, self::TYPE_BOOLEAN ],
		'bypass_author_pages'           => [ Constants::SETTING_BYPASS_AUTHOR_PAGES, self::TYPE_BOOLEAN ],
		'bypass_amp'                    => [ Constants::SETTING_BYPASS_AMP, self::TYPE_BOOLEAN ],
		'bypass_ajax'                   => [ Constants::SETTING_BYPASS_AJAX, self::TYPE_BOOLEAN ],
		'bypass_query_var'              => [ Constants::SETTING_BYPASS_QUERY_VAR, self::TYPE_BOOLEAN ],
		'bypass_wp_json_rest'           => [ Constants::SETTING_BYPASS_WP_JSON_REST, self::TYPE_BOOLEAN ],
		'bypass_sitemap'                => [ Constants::SETTING_BYPASS_SITEMAP, self::TYPE_BOOLEAN ],
		'bypass_robots_txt'             => [ Constants::SETTING_BYPASS_ROBOTS_TXT, self::TYPE_BOOLEAN ],
	];

	/**
	 * @return void
	 */
	public function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Super Page Cache', 'wp-cloudflare-page-cache' ),
				'description' => __( 'Page cache policy, purging, testing and preloading.', 'wp-cloudflare-page-cache' ),
			]
		);
	}

	/**
	 * @return void
	 */
	public function register_abilities() {
		$empty_input = [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
			'default'              => [],
		];

		wp_register_ability(
			'spc/get-cache-policy',
			[
				'label'               => __( 'Get cache policy', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Returns the effective page cache policy: enabled state, TTLs, excluded URLs and cookies, and the bypass rules. Lists the fields locked by wp-config.php constants.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $empty_input,
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'page_cache_enabled' => [ 'type' => 'boolean' ],
						'policy'             => $this->get_policy_schema(),
						'overridden'         => [
							'type'        => 'array',
							'description' => 'Policy fields managed by wp-config.php constants. They cannot be updated.',
							'items'       => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'get_cache_policy' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => $this->get_meta( true, false, true ),
			]
		);

		wp_register_ability(
			'spc/update-cache-policy',
			[
				'label'               => __( 'Update cache policy', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Updates page cache policy fields (enabled state, TTLs in seconds, excluded URLs and cookies, bypass rules). Only the provided fields change; list fields are replaced as a whole. Some fields purge the whole cache and re-sync the Cloudflare cache rule when changed.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'policy'  => $this->get_policy_schema(),
						'dry_run' => [
							'type'        => 'boolean',
							'description' => 'Report what would change without saving.',
							'default'     => false,
						],
					],
					'required'             => [ 'policy' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'dry_run'  => [ 'type' => 'boolean' ],
						'updated'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'rejected' => [
							'type'        => 'array',
							'description' => 'Fields skipped because they are managed by wp-config.php constants.',
							'items'       => [ 'type' => 'string' ],
						],
						'policy'   => $this->get_policy_schema(),
					],
				],
				'execute_callback'    => [ $this, 'update_cache_policy' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => $this->get_meta( false, false, true ),
			]
		);

		wp_register_ability(
			'spc/purge-cache',
			[
				'label'               => __( 'Purge Cache', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Purges the whole cache, a list of site URLs, posts (with their related archive URLs) or cache tags. Purging by tags requires Super Page Cache Pro with Cache Tags enabled.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'scope'    => [
							'type' => 'string',
							'enum' => [ 'all', 'urls', 'posts', 'tags' ],
						],
						'urls'     => [
							'type'        => 'array',
							'description' => 'Absolute URLs of this site. Used with scope "urls".',
							'maxItems'    => self::MAX_PURGE_URLS,
							'items'       => [ 'type' => 'string' ],
						],
						'post_ids' => [
							'type'        => 'array',
							'description' => 'Post IDs. Used with scope "posts".',
							'maxItems'    => self::MAX_PURGE_POSTS,
							'items'       => [ 'type' => 'integer' ],
						],
						'tags'     => [
							'type'        => 'array',
							'description' => 'Cache tags, for example "spc:post:12" or "spc:post:*". Used with scope "tags".',
							'maxItems'    => self::MAX_PURGE_TAGS,
							'items'       => [ 'type' => 'string' ],
						],
						'force'    => [
							'type'        => 'boolean',
							'description' => 'Tags only: bypass the wildcard guardrails.',
							'default'     => false,
						],
						'dry_run'  => [
							'type'        => 'boolean',
							'description' => 'Resolve the targets without purging.',
							'default'     => false,
						],
					],
					'required'             => [ 'scope' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'scope'      => [ 'type' => 'string' ],
						'dry_run'    => [ 'type' => 'boolean' ],
						'purged'     => [ 'type' => 'boolean' ],
						'urls'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'items'      => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'target' => [ 'type' => 'string' ],
									'status' => [ 'type' => 'string' ],
									'code'   => [ 'type' => 'string' ],
								],
							],
						],
						'tag_result' => [
							'type'                 => 'object',
							'additionalProperties' => true,
						],
					],
				],
				'execute_callback'    => [ $this, 'purge_cache' ],
				'permission_callback' => [ Helpers::class, 'can_current_user_purge_cache' ],
				'meta'                => $this->get_meta( false, true, false ),
			]
		);

		wp_register_ability(
			'spc/test-cache',
			[
				'label'               => __( 'Test Cache', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Runs the plugin cache test against its static test page and reports the Cloudflare and disk cache results. The tested URL is fixed by the plugin.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $empty_input,
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'overall_status' => [ 'type' => 'string' ],
						'message'        => [ 'type' => 'string' ],
						'test_url'       => [ 'type' => 'string' ],
						'cloudflare'     => $this->get_test_section_schema(),
						'disk_cache'     => $this->get_test_section_schema(),
						'configuration'  => [
							'type'       => 'object',
							'properties' => [
								'cloudflare_enabled' => [ 'type' => 'boolean' ],
								'disk_cache_enabled' => [ 'type' => 'boolean' ],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'test_cache' ],
				'permission_callback' => [ Helpers::class, 'can_current_user_purge_cache' ],
				'meta'                => $this->get_meta( true, false, true ),
			]
		);

		wp_register_ability(
			'spc/start-preload',
			[
				'label'               => __( 'Start Preloader', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Starts the cache preloader for the configured sources (menus, sitemaps, latest posts), or for a given list of site URLs. Only one preload can run at a time. The preload runs in the background; pass the returned job_id to spc/get-status to follow it.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'urls' => [
							'type'        => 'array',
							'description' => 'Optional absolute URLs of this site to preload instead of the configured sources.',
							'maxItems'    => self::MAX_PRELOAD_URLS,
							'items'       => [ 'type' => 'string' ],
						],
					],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'started' => [ 'type' => 'boolean' ],
						'job_id'  => [
							'type'        => 'string',
							'description' => 'Reference of this preload run for spc/get-status.',
						],
						'mode'    => [ 'type' => 'string' ],
						'urls'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'start_preload' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => array_merge(
					$this->get_meta( false, true, false ),
					[
						'task' => [
							'mode'           => 'poll',
							'status_ability' => 'spc/get-status',
						],
					]
				),
			]
		);

		wp_register_ability(
			'spc/get-status',
			[
				'label'               => __( 'Get cache status', 'wp-cloudflare-page-cache' ),
				'description'         => __( 'Returns the preloader state and the cache and Cloudflare connection health. Pass the job_id returned by spc/start-preload to follow that preload run.', 'wp-cloudflare-page-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'job_id' => [
							'type'        => 'string',
							'description' => 'Optional preload run reference returned by spc/start-preload. Without it the state describes the current preload run, if any.',
						],
					],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'state'          => [
							'type'        => 'string',
							'description' => 'Preload run state.',
							'enum'        => [ 'working', 'completed', 'failed', 'cancelled' ],
						],
						'progress'       => [
							'type'       => 'object',
							'properties' => [
								'current' => [ 'type' => 'integer' ],
								'total'   => [ 'type' => 'integer' ],
								'message' => [ 'type' => 'string' ],
							],
						],
						'plugin_version' => [ 'type' => 'string' ],
						'pro_active'     => [ 'type' => 'boolean' ],
						'upgrade_url'    => [
							'type'        => 'string',
							'description' => 'Where to get Super Page Cache Pro. Empty when Pro is active.',
						],
						'preload'        => [
							'type'       => 'object',
							'properties' => [
								'enabled'        => [ 'type' => 'boolean' ],
								'running'        => [ 'type' => 'boolean' ],
								'stale_lock'     => [ 'type' => 'boolean' ],
								'started_at'     => [ 'type' => 'string' ],
								'start_on_purge' => [ 'type' => 'boolean' ],
							],
						],
						'provider'       => [
							'type'       => 'object',
							'properties' => [
								'connected'                => [ 'type' => 'boolean' ],
								'api_enabled'              => [ 'type' => 'boolean' ],
								'cache_rule_configured'    => [ 'type' => 'boolean' ],
								'cache_enabled'            => [ 'type' => 'boolean' ],
								'disk_cache_enabled'       => [ 'type' => 'boolean' ],
								'cache_engine_operational' => [ 'type' => 'boolean' ],
								'advanced_cache_write_failed' => [ 'type' => 'boolean' ],
								'credentials_need_attention' => [ 'type' => 'boolean' ],
								'cache_tags_enabled'       => [ 'type' => 'boolean' ],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => $this->get_meta( true, false, true ),
			]
		);
	}

	/**
	 * Same capability as the settings dashboard and its REST routes.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_cache_policy() {
		$settings   = Settings_Store::get_instance();
		$overridden = [];

		foreach ( self::POLICY_FIELDS as $name => $field ) {
			if ( $settings->is_overridden( $field[0] ) ) {
				$overridden[] = $name;
			}
		}

		return [
			'page_cache_enabled' => $settings->is_cache_enabled(),
			'policy'             => $this->read_policy(),
			'overridden'         => $overridden,
		];
	}

	/**
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_cache_policy( $input = [] ) {
		$input   = is_array( $input ) ? $input : [];
		$policy  = isset( $input['policy'] ) && is_array( $input['policy'] ) ? $input['policy'] : [];
		$dry_run = ! empty( $input['dry_run'] );
		$payload = [];

		foreach ( $policy as $name => $value ) {
			if ( ! isset( self::POLICY_FIELDS[ $name ] ) ) {
				return new WP_Error(
					'spc_invalid_policy_field',
					/* translators: %s: policy field name. */
					sprintf( __( 'Unknown policy field: %s', 'wp-cloudflare-page-cache' ), sanitize_key( (string) $name ) ),
					[ 'status' => 400 ]
				);
			}

			list( $key, $type ) = self::POLICY_FIELDS[ $name ];

			if ( self::TYPE_BOOLEAN === $type ) {
				$payload[ $key ] = true === $value || ( is_scalar( $value ) && rest_sanitize_boolean( (string) $value ) ) ? 1 : 0;
				continue;
			}

			if ( self::TYPE_INTEGER === $type ) {
				if ( ! is_numeric( $value ) || (int) $value < 0 ) {
					return new WP_Error(
						'spc_invalid_policy_value',
						/* translators: %s: policy field name. */
						sprintf( __( '%s must be a non-negative integer.', 'wp-cloudflare-page-cache' ), $name ),
						[ 'status' => 400 ]
					);
				}

				$payload[ $key ] = (int) $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				return new WP_Error(
					'spc_invalid_policy_value',
					/* translators: %s: policy field name. */
					sprintf( __( '%s must be a list of strings.', 'wp-cloudflare-page-cache' ), $name ),
					[ 'status' => 400 ]
				);
			}

			$payload[ $key ] = array_values( array_map( 'sanitize_text_field', array_filter( $value, 'is_string' ) ) );
		}

		if ( empty( $payload ) ) {
			return new WP_Error( 'spc_empty_policy', __( 'No policy fields were provided.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		$names_by_key = [];

		foreach ( self::POLICY_FIELDS as $name => $field ) {
			$names_by_key[ $field[0] ] = $name;
		}

		$settings_manager = new Settings_Manager();

		if ( $dry_run ) {
			$settings = Settings_Store::get_instance();
			$updated  = [];
			$rejected = [];

			foreach ( $payload as $key => $value ) {
				$value = $settings_manager->sanitize_setting_value( $key, $value, $payload );

				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- stored values may be numeric strings.
				if ( $value == $settings->get( $key ) ) {
					continue;
				}

				if ( $settings->is_overridden( $key ) ) {
					$rejected[] = $names_by_key[ $key ];
					continue;
				}

				$updated[] = $names_by_key[ $key ];
			}

			return [
				'dry_run'  => true,
				'updated'  => $updated,
				'rejected' => $rejected,
				'policy'   => $this->read_policy(),
			];
		}

		try {
			// @phpstan-ignore argument.type (the method takes a key => value map; its docblock shape is inaccurate)
			$result = $settings_manager->update_settings( $payload, true );
		} catch ( \Exception $e ) {
			return new WP_Error( 'spc_policy_update_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		$to_names = static function ( $keys ) use ( $names_by_key ) {
			$names = [];

			foreach ( (array) $keys as $key ) {
				if ( isset( $names_by_key[ $key ] ) ) {
					$names[] = $names_by_key[ $key ];
				}
			}

			return $names;
		};

		return [
			'dry_run'  => false,
			'updated'  => $to_names( $result['updated'] ),
			'rejected' => $to_names( $result['rejected'] ),
			'policy'   => $this->read_policy(),
		];
	}

	/**
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function purge_cache( $input = [] ) {
		$input   = is_array( $input ) ? $input : [];
		$scope   = isset( $input['scope'] ) ? (string) $input['scope'] : '';
		$dry_run = ! empty( $input['dry_run'] );

		switch ( $scope ) {
			case 'all':
				if ( ! $dry_run && ! Cache_Controller::purge_all( false, false ) ) {
					return $this->purge_failed_error();
				}

				return [
					'scope'   => $scope,
					'dry_run' => $dry_run,
					'purged'  => ! $dry_run,
				];

			case 'urls':
				$targets = isset( $input['urls'] ) && is_array( $input['urls'] ) ? array_values( $input['urls'] ) : [];

				if ( empty( $targets ) || count( $targets ) > self::MAX_PURGE_URLS ) {
					return $this->invalid_targets_error( 'urls', self::MAX_PURGE_URLS );
				}

				$items = [];
				$urls  = [];

				foreach ( $targets as $target ) {
					$url = $this->sanitize_site_url( $target );

					if ( '' === $url ) {
						$items[] = [
							'target' => is_string( $target ) ? sanitize_text_field( $target ) : '',
							'status' => 'skipped',
							'code'   => 'invalid_url',
						];
						continue;
					}

					$urls[]  = $url;
					$items[] = [
						'target' => $url,
						'status' => $dry_run ? 'resolved' : 'purged',
						'code'   => '',
					];
				}

				return $this->purge_url_list( $scope, $urls, $items, $dry_run );

			case 'posts':
				$targets = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_values( $input['post_ids'] ) : [];

				if ( empty( $targets ) || count( $targets ) > self::MAX_PURGE_POSTS ) {
					return $this->invalid_targets_error( 'post_ids', self::MAX_PURGE_POSTS );
				}

				$items = [];
				$urls  = [];

				foreach ( $targets as $target ) {
					$post_id = absint( $target );

					if ( $post_id < 1 || ! get_post( $post_id ) ) {
						$items[] = [
							'target' => (string) $post_id,
							'status' => 'skipped',
							'code'   => 'post_not_found',
						];
						continue;
					}

					$urls    = array_merge( $urls, (array) Cache_Invalidation_Hooks::get_post_related_links( $post_id ) );
					$items[] = [
						'target' => (string) $post_id,
						'status' => $dry_run ? 'resolved' : 'purged',
						'code'   => '',
					];
				}

				$urls = array_values( array_unique( array_filter( $urls, 'is_string' ) ) );

				return $this->purge_url_list( $scope, $urls, $items, $dry_run );

			case 'tags':
				return $this->purge_tags( $input, $dry_run );
		}

		return new WP_Error( 'spc_invalid_scope', __( 'Scope must be one of: all, urls, posts, tags.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function test_cache() {
		try {
			$results = ( new Cache_Tester() )->cli_test();
		} catch ( \Exception $e ) {
			return new WP_Error( 'spc_cache_test_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		foreach ( [ 'cloudflare', 'disk_cache' ] as $section ) {
			if ( isset( $results[ $section ]['errors'] ) && is_array( $results[ $section ]['errors'] ) ) {
				$results[ $section ]['errors'] = array_values( array_map( 'wp_strip_all_tags', $results[ $section ]['errors'] ) );
			}
		}

		$results['configuration']['cloudflare_enabled'] = ! empty( $results['configuration']['cloudflare_enabled'] );
		$results['configuration']['disk_cache_enabled'] = ! empty( $results['configuration']['disk_cache_enabled'] );

		return $results;
	}

	/**
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function start_preload( $input = [] ) {
		$input    = is_array( $input ) ? $input : [];
		$settings = Settings_Store::get_instance();

		if ( ! $settings->get( Constants::SETTING_ENABLE_PRELOADER ) ) {
			return new WP_Error( 'spc_preloader_disabled', __( 'Preloader is not enabled', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		if ( ! Preloader_Process::can_start() ) {
			return new WP_Error( 'spc_preloader_running', __( 'Unable to start the preloader. Another preloading process is currently running.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		if ( ! $settings->is_cache_enabled() ) {
			return new WP_Error( 'spc_cache_disabled', __( 'You cannot start the preloader while the page cache is disabled.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		if ( empty( $input['urls'] ) ) {
			Preloader_Process::start_for_all_urls();

			return $this->preload_started( 'all', [] );
		}

		if ( ! is_array( $input['urls'] ) || count( $input['urls'] ) > self::MAX_PRELOAD_URLS ) {
			return $this->invalid_targets_error( 'urls', self::MAX_PRELOAD_URLS );
		}

		$urls = array_values( array_unique( array_filter( array_map( [ $this, 'sanitize_site_url' ], $input['urls'] ) ) ) );

		if ( empty( $urls ) ) {
			return new WP_Error( 'spc_invalid_urls', __( 'None of the provided targets could be resolved to a URL of this site.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		Preloader_Process::start_for_urls( $urls );

		return $this->preload_started( 'urls', $urls );
	}

	/**
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_status( $input = [] ) {
		$input  = is_array( $input ) ? $input : [];
		$job_id = isset( $input['job_id'] ) && is_scalar( $input['job_id'] ) ? trim( (string) $input['job_id'] ) : '';

		if ( '' !== $job_id && ( ! ctype_digit( $job_id ) || strlen( $job_id ) > 10 || (int) $job_id < 1 ) ) {
			return new WP_Error( 'spc_invalid_job_id', __( 'Invalid job_id. Use the value returned by spc/start-preload.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		$settings     = Settings_Store::get_instance();
		$cloudflare   = new Cloudflare_Integration();
		$lock         = (int) get_option( 'swcfpc_preloader_lock', 0 );
		$lock_age     = $lock > 0 ? time() - $lock : 0;
		$write_failed = false !== get_option( Constants::KEY_ADVANCED_CACHE_WRITE_FAILED, false );

		$task = $this->get_preload_task_state( (int) $job_id, $lock );

		return [
			'state'          => $task['state'],
			'progress'       => $task['progress'],
			'plugin_version' => defined( 'SWCFPC_VERSION' ) ? (string) SWCFPC_VERSION : '',
			'pro_active'     => defined( 'SPC_PRO_PATH' ),
			'upgrade_url'    => defined( 'SPC_PRO_PATH' ) ? '' : $this->get_upgrade_url( 'status' ),
			'preload'        => [
				'enabled'        => (bool) $settings->get( Constants::SETTING_ENABLE_PRELOADER ),
				'running'        => $lock > 0 && $lock_age <= 15 * MINUTE_IN_SECONDS,
				'stale_lock'     => $lock > 0 && $lock_age > 15 * MINUTE_IN_SECONDS,
				'started_at'     => $lock > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $lock ) : '',
				'start_on_purge' => (bool) $settings->get( Constants::SETTING_PRELOADER_START_ON_PURGE ),
			],
			'provider'       => [
				'connected'                   => (bool) $settings->is_cloudflare_connected(),
				'api_enabled'                 => (bool) $cloudflare->is_enabled(),
				'cache_rule_configured'       => (bool) $cloudflare->has_cache_rule(),
				'cache_enabled'               => $settings->is_cache_enabled(),
				'disk_cache_enabled'          => (bool) $settings->get( Constants::SETTING_ENABLE_FALLBACK_CACHE ),
				'cache_engine_operational'    => $settings->is_cache_engine_operational( null, $write_failed ),
				'advanced_cache_write_failed' => $write_failed,
				'credentials_need_attention'  => (bool) $settings->has_unreadable_active_cloudflare_credentials(),
				'cache_tags_enabled'          => $this->is_cache_tags_available(),
			],
		];
	}

	/**
	 * The preloader lock timestamp identifies the run; the plugin stores no other job reference.
	 *
	 * @param string             $mode Preload mode.
	 * @param array<int, string> $urls Requested URLs.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function preload_started( string $mode, array $urls ) {
		$lock = (int) get_option( 'swcfpc_preloader_lock', 0 );

		if ( $lock < 1 ) {
			return new WP_Error( 'spc_nothing_to_preload', __( 'The preloader did not start: no URLs to preload were found.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		return [
			'started' => true,
			'job_id'  => (string) $lock,
			'mode'    => $mode,
			'urls'    => $urls,
		];
	}

	/**
	 * Derives the state of a preload run from the preloader lock.
	 *
	 * @param int $job  Lock timestamp of the run to follow, 0 for the current run.
	 * @param int $lock Current preloader lock timestamp, 0 when unlocked.
	 *
	 * @return array{state: string, progress: array{current: int, total: int, message: string}}
	 */
	private function get_preload_task_state( int $job, int $lock ) {
		$job = $job > 0 ? $job : $lock;

		if ( $job < 1 ) {
			return $this->preload_task_state( 'completed', 0, 0, __( 'No preload is running.', 'wp-cloudflare-page-cache' ) );
		}

		if ( $job !== $lock ) {
			return $this->preload_task_state( 'completed', 0, 0, __( 'The preloader lock of this run was released. Per-URL results are only available in the plugin log.', 'wp-cloudflare-page-cache' ) );
		}

		if ( time() - $lock > 15 * MINUTE_IN_SECONDS ) {
			return $this->preload_task_state( 'failed', 0, 0, __( 'The preloader lock is stale: the run did not report completion within 15 minutes.', 'wp-cloudflare-page-cache' ) );
		}

		$total   = $this->count_preload_actions( $job, '' );
		$pending = $this->count_preload_actions( $job, 'pending' ) + $this->count_preload_actions( $job, 'in-progress' );

		return $this->preload_task_state(
			'working',
			max( 0, $total - $pending ),
			$total,
			/* translators: 1: number of processed URLs, 2: number of queued URLs. */
			sprintf( __( 'Preload running: %1$d of %2$d queued URLs processed.', 'wp-cloudflare-page-cache' ), max( 0, $total - $pending ), $total )
		);
	}

	/**
	 * Counts the preloader URL actions queued in Action Scheduler since the run started.
	 *
	 * @param int    $since  Run start timestamp.
	 * @param string $status Action Scheduler status, empty for any.
	 *
	 * @return int
	 */
	private function count_preload_actions( int $since, string $status ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$args = [
			'hook'         => 'spc_preloader_job',
			'group'        => Constants::ACTION_SCHEDULER_GROUP,
			'date'         => $since,
			'date_compare' => '>=',
			'per_page'     => 500,
		];

		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		return count( as_get_scheduled_actions( $args, 'ids' ) );
	}

	/**
	 * @param string $state   Task state.
	 * @param int    $current Processed items.
	 * @param int    $total   Total items.
	 * @param string $message Progress message.
	 *
	 * @return array{state: string, progress: array{current: int, total: int, message: string}}
	 */
	private function preload_task_state( string $state, int $current, int $total, string $message ) {
		return [
			'state'    => $state,
			'progress' => [
				'current' => $current,
				'total'   => $total,
				'message' => $message,
			],
		];
	}

	/**
	 * @param array<string, mixed> $input   Ability input.
	 * @param bool                 $dry_run Whether to skip the purge.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function purge_tags( array $input, bool $dry_run ) {
		if ( ! class_exists( '\SPC_Pro\Services\Tag_Purge_Orchestrator' ) || ! class_exists( '\SPC_Pro\Services\Tag_Normalizer' ) ) {
			$upgrade_url = $this->get_upgrade_url( 'cache-tags' );

			return new WP_Error(
				'spc_pro_required',
				sprintf(
					/* translators: %s: URL of the Super Page Cache Pro upgrade page. */
					__( 'Purging by cache tags requires Super Page Cache Pro. Upgrade: %s', 'wp-cloudflare-page-cache' ),
					$upgrade_url
				),
				[
					'status'      => 403,
					'upgrade_url' => $upgrade_url,
				]
			);
		}

		if ( ! $this->is_cache_tags_available() ) {
			return new WP_Error( 'spc_cache_tags_disabled', __( 'Cache Tags are not enabled.', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		$tags = isset( $input['tags'] ) && is_array( $input['tags'] ) ? array_values( $input['tags'] ) : [];

		if ( empty( $tags ) || count( $tags ) > self::MAX_PURGE_TAGS ) {
			return $this->invalid_targets_error( 'tags', self::MAX_PURGE_TAGS );
		}

		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) ) {
				return new WP_Error(
					'spc_invalid_tag',
					/* translators: %s: input field name. */
					sprintf( __( '%s must be a list of strings.', 'wp-cloudflare-page-cache' ), 'tags' ),
					[ 'status' => 400 ]
				);
			}
		}

		if ( ! empty( \SPC_Pro\Services\Tag_Normalizer::invalid_tags( $tags ) ) ) {
			return new WP_Error( 'spc_invalid_tag', __( 'Invalid tag. Use [a-z0-9:_-] and only trailing wildcard patterns like "spc:post:*".', 'wp-cloudflare-page-cache' ), [ 'status' => 400 ] );
		}

		$orchestrator = apply_filters( 'spc_cache_tags_orchestrator_factory', null );

		if ( ! is_object( $orchestrator ) || ! method_exists( $orchestrator, 'purge_tags' ) ) {
			$orchestrator = new \SPC_Pro\Services\Tag_Purge_Orchestrator();
		}

		$result = $orchestrator->purge_tags( $tags, \SPC_Pro\Services\Tag_Purge_Orchestrator::MODE_AUTO, ! empty( $input['force'] ), $dry_run )->to_array();

		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'spc_tag_purge_' . sanitize_key( (string) $result['error'] ),
				__( 'The tag purge was rejected.', 'wp-cloudflare-page-cache' ),
				[
					'status' => 400,
					'result' => $result,
				]
			);
		}

		return [
			'scope'      => 'tags',
			'dry_run'    => $dry_run,
			'purged'     => ! $dry_run,
			'tag_result' => $result,
		];
	}

	/**
	 * @param string                           $scope   Purge scope.
	 * @param array<int, string>               $urls    URLs to purge.
	 * @param array<int, array<string, string>> $items   Per-target results.
	 * @param bool                             $dry_run Whether to skip the purge.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function purge_url_list( string $scope, array $urls, array $items, bool $dry_run ) {
		if ( empty( $urls ) ) {
			return new WP_Error(
				'spc_no_valid_targets',
				__( 'None of the provided targets could be resolved to a URL of this site.', 'wp-cloudflare-page-cache' ),
				[
					'status' => 400,
					'items'  => $items,
				]
			);
		}

		if ( ! $dry_run && ! Cache_Controller::purge_urls( $urls, false ) ) {
			return $this->purge_failed_error();
		}

		return [
			'scope'   => $scope,
			'dry_run' => $dry_run,
			'purged'  => ! $dry_run,
			'urls'    => $urls,
			'items'   => $items,
		];
	}

	/**
	 * @param mixed $url Raw URL.
	 *
	 * @return string Sanitized URL, or an empty string when it is not an http(s) URL of this site.
	 */
	private function sanitize_site_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = esc_url_raw( trim( $url ), [ 'http', 'https' ] );

		if ( '' === $url || ! wp_parse_url( $url, PHP_URL_HOST ) || Helpers::is_external_link( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * The upgrade page the settings dashboard links to, tagged as coming from an ability.
	 *
	 * @param string $area Gated feature, used as the campaign.
	 *
	 * @return string
	 */
	private function get_upgrade_url( string $area ) {
		return esc_url_raw( tsdk_translate_link( tsdk_utmify( 'https://themeisle.com/plugins/super-page-cache-pro/upgrade/', $area, 'mcp' ) ) );
	}

	/**
	 * @return bool
	 */
	private function is_cache_tags_available() {
		return class_exists( '\SPC_Pro\Modules\Cache_Tags' ) && defined( 'SPC_PRO_PATH' ) && \SPC_Pro\Modules\Cache_Tags::is_enabled();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_policy() {
		$settings = Settings_Store::get_instance();
		$policy   = [];

		foreach ( self::POLICY_FIELDS as $name => $field ) {
			$value = $settings->get( $field[0] );

			if ( self::TYPE_BOOLEAN === $field[1] ) {
				$policy[ $name ] = (int) $value > 0;
			} elseif ( self::TYPE_INTEGER === $field[1] ) {
				$policy[ $name ] = (int) $value;
			} else {
				$value           = is_array( $value ) ? $value : explode( "\n", (string) $value );
				$policy[ $name ] = array_values(
					array_filter(
						array_map( 'strval', array_filter( $value, 'is_scalar' ) ),
						static function ( $line ) {
							return '' !== $line;
						}
					)
				);
			}
		}

		return $policy;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_policy_schema() {
		$properties = [];

		foreach ( self::POLICY_FIELDS as $name => $field ) {
			if ( self::TYPE_LIST === $field[1] ) {
				$properties[ $name ] = [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				];
				continue;
			}

			$properties[ $name ] = [ 'type' => $field[1] ];

			if ( self::TYPE_INTEGER === $field[1] ) {
				$properties[ $name ]['minimum']     = 0;
				$properties[ $name ]['description'] = 'Seconds.';
			}
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_test_section_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'status'  => [ 'type' => 'string' ],
				'message' => [ 'type' => 'string' ],
				'errors'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'additionalProperties' => true,
		];
	}

	/**
	 * @param bool $is_readonly    Whether the ability only reads data.
	 * @param bool $is_destructive Whether the ability discards data.
	 * @param bool $is_idempotent  Whether repeated calls have no extra effect.
	 *
	 * @return array<string, mixed>
	 */
	private function get_meta( bool $is_readonly, bool $is_destructive, bool $is_idempotent ) {
		return [
			'annotations'  => [
				'readonly'    => $is_readonly,
				'destructive' => $is_destructive,
				'idempotent'  => $is_idempotent,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * @param string $field Input field name.
	 * @param int    $max   Maximum number of entries.
	 *
	 * @return WP_Error
	 */
	private function invalid_targets_error( string $field, int $max ) {
		return new WP_Error(
			'spc_invalid_targets',
			/* translators: 1: input field name, 2: maximum number of entries. */
			sprintf( __( '"%1$s" must be a non-empty list of at most %2$d entries.', 'wp-cloudflare-page-cache' ), $field, $max ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * @return WP_Error
	 */
	private function purge_failed_error() {
		return new WP_Error( 'spc_purge_failed', __( 'Failed to purge cache. Please try again later.', 'wp-cloudflare-page-cache' ), [ 'status' => 500 ] );
	}
}
