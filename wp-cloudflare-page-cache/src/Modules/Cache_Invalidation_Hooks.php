<?php

namespace SPC\Modules;

use SPC\Constants;
use SPC\Services\Settings_Store;
use SPC\Utils\Logger;

defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Registers WP hooks that invalidate the cache when content changes.
 *
 * Owns: comment moderation, post create/edit/trash/delete, theme & menu changes,
 * Elementor save and generated-files clear events, and the programmatic `swcfpc_purge_cache` action.
 *
 * Each callback delegates to {@see Cache_Controller::purge_all()} or
 * {@see Cache_Controller::purge_urls()} (static facades) so the per-request
 * `$purge_all_already_done` dedup is preserved without taking a Cache_Controller
 * dependency on this class.
 */
class Cache_Invalidation_Hooks implements Module_Interface {
	/**
	 * Posts purged in this request, by {@see self::purged_post_key()}. One change fires several post
	 * hooks; the post is purged once.
	 *
	 * @var array<string, true>
	 */
	private static $purged_posts = [];

	/**
	 * Post whose comment count WordPress is updating, by {@see self::purged_post_key()}. The update
	 * fires `clean_post_cache` and `edit_post` for it, which are not post edits.
	 *
	 * @var string
	 */
	private static $comment_count_post = '';

	public function init() {
		// Programmatic purge entry point used by 3rd-party code.
		add_action( 'swcfpc_purge_cache', [ $this, 'purge_cache_programmatically' ], PHP_INT_MAX, 1 );

		// Comments.
		add_action( 'transition_comment_status', [ $this, 'purge_cache_when_comment_is_approved' ], PHP_INT_MAX, 3 );
		add_action( 'comment_post', [ $this, 'purge_cache_when_new_comment_is_added' ], PHP_INT_MAX, 3 );
		add_action( 'delete_comment', [ $this, 'purge_cache_when_comment_is_deleted' ], PHP_INT_MAX );
		add_filter( 'pre_wp_update_comment_count_now', [ $this, 'note_comment_count_update' ], PHP_INT_MAX, 3 );
		add_action( 'wp_update_comment_count', [ $this, 'purge_cache_on_comment_count_update' ], PHP_INT_MAX, 1 );

		// Theme / menu / customizer / permalinks / page-builder generated CSS.
		$theme_actions = [
			'wp_update_nav_menu',
			'update_option_theme_mods_' . get_option( 'stylesheet' ),
			'avada_clear_dynamic_css_cache',
			// Fires without a post ID after Elementor deletes the generated CSS of every page.
			'elementor/core/files/clear_cache',
			'switch_theme',
			'customize_save_after',
			'permalink_structure_changed',
		];

		foreach ( $theme_actions as $action ) {
			add_action( $action, [ $this, 'purge_cache_on_theme_edit' ], PHP_INT_MAX );
		}

		// Post lifecycle.
		$post_actions = [
			'deleted_post',
			'wp_trash_post',
			'clean_post_cache',
			'edit_post',
			'delete_attachment',
			'elementor/editor/after_save',
		];

		foreach ( $post_actions as $action ) {
			add_action( $action, [ $this, 'purge_cache_on_post_edit' ], PHP_INT_MAX, 1 );
		}

		// Dispatch the tag-purge before the post row is gone so listeners can
		// still read post data (`deleted_post` fires after deletion and has no
		// way to look up the author / terms / type).
		add_action( 'before_delete_post', [ $this, 'purge_tags_before_delete_post' ], PHP_INT_MAX, 1 );

		add_action( 'transition_post_status', [ $this, 'purge_cache_when_post_is_published' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Public API — `do_action( 'swcfpc_purge_cache', $urls )`. Empty/non-array `$urls`
	 * triggers a full purge, otherwise the listed URLs are purged. Bypasses the queue.
	 *
	 * @param array<int, string>|mixed $urls
	 * @return void
	 */
	public function purge_cache_programmatically( $urls ) {
		if ( ! is_array( $urls ) || count( $urls ) == 0 ) {
			Cache_Controller::purge_all( true, false );
		} else {
			Cache_Controller::purge_urls( $urls, false );
		}
	}

	/**
	 * @param string      $new_status
	 * @param string      $old_status
	 * @param \WP_Comment $comment
	 * @return void
	 */
	public function purge_cache_when_comment_is_approved( $new_status, $old_status, $comment ) {
		if ( Settings_Store::get_instance()->get( Constants::SETTING_PURGE_ON_COMMENT, 0 ) == 0 || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		if ( $old_status == $new_status || $new_status !== 'approved' ) {
			return;
		}

		$current_action = function_exists( 'current_action' ) ? current_action() : '';
		$post_id        = (int) $comment->comment_post_ID;

		Cache_Controller::purge_urls( [ get_permalink( $post_id ) ] );
		self::dispatch_post_tag_purge( $post_id, [ 'scope' => 'comment' ] );

		Logger::log( 'cache_invalidation_hooks::purge_cache_when_comment_is_approved', "Purge Cloudflare cache for only post {$post_id} - Fired action: {$current_action}" );
	}

	/**
	 * @param int                  $comment_id
	 * @param int|string           $comment_approved
	 * @param array<string, mixed> $commentdata
	 * @return void
	 */
	public function purge_cache_when_new_comment_is_added( $comment_id, $comment_approved, $commentdata ) {
		if ( Settings_Store::get_instance()->get( Constants::SETTING_PURGE_ON_COMMENT, 0 ) == 0 || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		if ( ! isset( $commentdata['comment_post_ID'] ) ) {
			return;
		}

		$current_action = function_exists( 'current_action' ) ? current_action() : '';
		$post_id        = (int) $commentdata['comment_post_ID'];

		Cache_Controller::purge_urls( [ get_permalink( $post_id ) ] );
		self::dispatch_post_tag_purge( $post_id, [ 'scope' => 'comment' ] );

		Logger::log( 'cache_invalidation_hooks::purge_cache_when_new_comment_is_added', "Purge Cloudflare cache for only post {$post_id} - Fired action: {$current_action}" );
	}

	/**
	 * @return void
	 */
	public function purge_cache_when_comment_is_deleted( int $comment_id ) {
		if ( Settings_Store::get_instance()->get( Constants::SETTING_PURGE_ON_COMMENT, 0 ) == 0 || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		$current_action = function_exists( 'current_action' ) ? current_action() : '';

		$comment = get_comment( $comment_id );
		$post_id = (int) $comment->comment_post_ID;
		Cache_Controller::purge_urls( [ get_permalink( $post_id ) ] );
		self::dispatch_post_tag_purge( $post_id, [ 'scope' => 'comment' ] );

		Logger::log( 'cache_invalidation_hooks::purge_cache_when_comment_is_deleted', "Purge Cloudflare cache for only post {$post_id} - Fired action: {$current_action}" );
	}

	/**
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 * @return void
	 */
	public function purge_cache_when_post_is_published( $new_status, $old_status, $post ) {
		$auto_purge       = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE );
		$auto_purge_whole = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE_WHOLE );

		if ( ( ! $auto_purge && ! $auto_purge_whole ) || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		$going_live   = in_array( $old_status, [ 'future', 'draft', 'pending' ], true ) && in_array( $new_status, [ 'publish', 'private' ], true );
		$being_hidden = 'publish' === $old_status && in_array( $new_status, [ 'future', 'draft', 'pending' ], true );

		if ( ! $going_live && ! $being_hidden ) {
			return;
		}

		// Going live first fires clean_post_cache, which already purged the post. Hiding a post always purges.
		if ( $going_live && isset( self::$purged_posts[ self::purged_post_key( $post->ID ) ] ) ) {
			// A whole-cache purge from clean_post_cache sends no tag purge; going live always did.
			if ( $auto_purge_whole ) {
				self::dispatch_post_tag_purge( $post->ID );
			}

			return;
		}

		$current_action = function_exists( 'current_action' ) ? current_action() : '';

		if ( $auto_purge_whole ) {
			Cache_Controller::purge_all();
			self::dispatch_post_tag_purge( $post->ID );
			Logger::log( 'cache_invalidation_hooks::purge_cache_when_post_is_published', "Purge whole cache (fired action: {$current_action}" );
			return;
		}

		Cache_Controller::purge_urls( self::get_post_related_links( $post->ID ) );
		self::dispatch_post_tag_purge( $post->ID );
		Logger::log( 'cache_invalidation_hooks::purge_cache_when_post_is_published', "Purge cache for only post id {$post->ID} and related contents - Fired action: {$current_action}" );
	}

	/**
	 * @param mixed $post_id
	 * @return void
	 */
	public function purge_cache_on_post_edit( $post_id ) {
		$post_id        = (int) $post_id;
		$current_action = function_exists( 'current_action' ) ? current_action() : '';

		// A comment count update: purge_cache_on_comment_count_update() purges the post page. Checked
		// first, so the marker is cleared at the update's last hook also for a post purged already.
		if ( self::$comment_count_post === self::purged_post_key( $post_id ) && in_array( $current_action, [ 'clean_post_cache', 'edit_post' ], true ) ) {
			if ( 'edit_post' === $current_action ) {
				self::$comment_count_post = '';
			}

			return;
		}

		if ( isset( self::$purged_posts[ self::purged_post_key( $post_id ) ] ) ) {
			return;
		}

		// The pings cron (pingbacks, trackbacks, enclosures) records what was pinged, not content.
		if ( doing_action( 'do_pings' ) ) {
			return;
		}

		global $pagenow;
		if ( $pagenow === 'nav-menus.php' ) {
			return;
		}

		$auto_purge       = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE );
		$auto_purge_whole = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE_WHOLE );

		if ( ( ! $auto_purge && ! $auto_purge_whole ) || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		$valid_post_status = [ 'publish', 'trash', 'private' ];
		$this_post_status  = get_post_status( $post_id );

		if ( ! get_permalink( $post_id ) || ! in_array( $this_post_status, $valid_post_status, true ) ) {
			return;
		}

		if ( is_int( wp_is_post_autosave( $post_id ) ) || is_int( wp_is_post_revision( $post_id ) ) ) {
			return;
		}

		// WordPress refreshes an expired oEmbed cache while a page is viewed: not a content change.
		if ( 'oembed_cache' === get_post_type( $post_id ) ) {
			return;
		}

		self::$purged_posts[ self::purged_post_key( $post_id ) ] = true;

		if ( $auto_purge_whole ) {
			Cache_Controller::purge_all();
			return;
		}

		if ( ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			return;
		}

		Cache_Controller::purge_urls( self::get_post_related_links( $post_id ) );
		self::dispatch_post_tag_purge( $post_id );
		Logger::log( 'cache_invalidation_hooks::purge_cache_on_post_edit', "Purge Cloudflare cache for only post id {$post_id} and related contents - Fired action: {$current_action}" );
	}

	/**
	 * Note the post whose comment count is about to be updated, see {@see self::$comment_count_post}.
	 *
	 * @param mixed $new_count The new count, or null to let WordPress count. Returned unchanged.
	 * @param mixed $old_count
	 * @param mixed $post_id
	 * @return mixed
	 */
	public function note_comment_count_update( $new_count, $old_count, $post_id ) {
		self::$comment_count_post = self::purged_post_key( (int) $post_id );

		return $new_count;
	}

	/**
	 * WordPress updates a post's comment count whenever one of its comments is added, edited,
	 * approved, unapproved, spammed, trashed or deleted, by any means (comment form, REST API,
	 * import), also when counting was deferred. Purge the post page, as "Purge on comment" asks,
	 * instead of the related pages or the whole cache.
	 *
	 * @param mixed $post_id
	 * @return void
	 */
	public function purge_cache_on_comment_count_update( $post_id ) {
		$post_id = (int) $post_id;

		if ( Settings_Store::get_instance()->get( Constants::SETTING_PURGE_ON_COMMENT, 0 ) == 0 || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			return;
		}

		Cache_Controller::purge_urls( [ $permalink ] );
		self::dispatch_post_tag_purge( $post_id, [ 'scope' => 'comment' ] );

		Logger::log( 'cache_invalidation_hooks::purge_cache_on_comment_count_update', "Purge Cloudflare cache for only post {$post_id} - comments changed" );
	}

	/**
	 * Key of a post in {@see self::$purged_posts}: post IDs repeat across the sites of a network.
	 *
	 * @param int $post_id The post ID.
	 * @return string
	 */
	private static function purged_post_key( int $post_id ): string {
		return get_current_blog_id() . ':' . $post_id;
	}

	/**
	 * @param mixed $post_id
	 * @return void
	 */
	public function purge_tags_before_delete_post( $post_id ) {
		$post_id = (int) $post_id;

		$auto_purge       = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE );
		$auto_purge_whole = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE_WHOLE );

		if ( ( ! $auto_purge && ! $auto_purge_whole ) || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		if ( ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			return;
		}

		self::dispatch_post_tag_purge( $post_id );
	}

	/**
	 * Signal that any tag-based caches associated with `$post_id` should be
	 * invalidated. Listeners (e.g. the pro Cache_Tags module) translate the
	 * post id into a tag set and dispatch the actual purge — when the listener
	 * is absent (free plugin or feature disabled), the call is a no-op.
	 *
	 * @param int                 $post_id
	 * @param array<string,mixed> $args Optional. `scope=comment` narrows the
	 *                                  invalidation to just the post tag.
	 *
	 * @return void
	 */
	private static function dispatch_post_tag_purge( $post_id, array $args = [] ) {
		do_action( 'spc_purge_post_tags', (int) $post_id, $args );
	}

	/**
	 * Purge the whole cache after a site-wide (theme-level) change: menu save, theme mods /
	 * customizer save, theme switch, permalink structure change, Avada dynamic CSS flush,
	 * Elementor generated-files clear.
	 *
	 * These changes affect every page, so a whole-cache purge is the default even when the
	 * "related pages only" auto-purge mode is enabled. Sites where that is too expensive
	 * (e.g. very large catalogues) can opt out per action through the
	 * `swcfpc_purge_cache_on_theme_edit` filter.
	 *
	 * @return void
	 */
	public function purge_cache_on_theme_edit() {
		$auto_purge       = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE );
		$auto_purge_whole = Settings_Manager::is_on( Constants::SETTING_AUTO_PURGE_WHOLE );

		if ( ( ! $auto_purge && ! $auto_purge_whole ) || ! Settings_Store::get_instance()->is_cache_enabled() ) {
			return;
		}

		$current_action = function_exists( 'current_action' ) ? current_action() : '';

		/**
		 * Filters whether a theme-level change should purge the whole cache.
		 *
		 * @param bool   $should_purge     Whether to purge the whole cache. Default true.
		 * @param string $current_action   The WordPress action that triggered the purge
		 *                                 (e.g. `wp_update_nav_menu`, `customize_save_after`,
		 *                                 `switch_theme`, `permalink_structure_changed`,
		 *                                 `elementor/core/files/clear_cache`).
		 * @param bool   $auto_purge_whole Whether the "purge whole cache" auto-purge mode is
		 *                                 enabled. Return this value to only purge everything
		 *                                 when that mode is on.
		 */
		$should_purge = apply_filters( 'swcfpc_purge_cache_on_theme_edit', true, $current_action, $auto_purge_whole );

		if ( ! $should_purge ) {
			Logger::log( 'cache_invalidation_hooks::purge_cache_on_theme_edit', "Whole cache purge skipped by filter - Fired action: {$current_action}" );
			return;
		}

		Cache_Controller::purge_all();
		Logger::log( 'cache_invalidation_hooks::purge_cache_on_theme_edit', "Purge whole cache - Fired action: {$current_action}" );
	}

	/**
	 * Build the list of paginated archive URLs for a given base URL and item count.
	 *
	 * @param string $base_url   Un-paginated archive URL.
	 * @param int    $item_count Total number of items shown on the archive.
	 *
	 * @return array<int, string>
	 */
	private static function get_paginated_urls( $base_url, $item_count ) {
		global $wp_rewrite;

		$per_page = (int) Settings_Store::get_instance()->get( Constants::SETTING_POSTS_PER_PAGE );

		if ( $per_page <= 0 || $item_count <= 0 ) {
			return [];
		}

		// $item_count reflects the archive *after* this status change, so a shrinking
		// archive (e.g. a post being unpublished) would otherwise leave the page that
		// just dropped below the boundary stale forever. Purge one page beyond the
		// current max so that boundary page is caught too.
		$pages_number = ceil( $item_count / $per_page ) + 1;
		$max_pages    = $pages_number > 10 ? 10 : $pages_number;
		$urls         = [];

		for ( $i = 2; $i <= $max_pages; $i++ ) {
			// A query-string archive URL (plain permalinks, or a taxonomy without rewrites) pages with `paged`.
			if ( ! $wp_rewrite->using_permalinks() || false !== strpos( $base_url, '?' ) ) {
				$urls[] = add_query_arg( 'paged', $i, $base_url );
				continue;
			}
			$urls[] = trailingslashit( $base_url ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $i, 'paged' );
		}

		return $urls;
	}

	/**
	 * Build the list of URLs that should be purged when a given post changes.
	 *
	 * @param int $post_id
	 *
	 * @return array<int, string>
	 */
	public static function get_post_related_links( $post_id ) {
		$listofurls = apply_filters( 'swcfpc_post_related_url_init', [], $post_id );
		$post_type  = get_post_type( $post_id );

		$listofurls[] = get_permalink( $post_id );

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			// Only a taxonomy with public archives has term pages to purge.
			if ( ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy->name );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );

				if ( is_wp_error( $term_link ) ) {
					continue;
				}

				$listofurls[] = $term_link;
				$listofurls   = array_merge( $listofurls, self::get_paginated_urls( $term_link, (int) $term->count ) );
			}
		}

		$listofurls[] = get_author_posts_url( get_post_field( 'post_author', $post_id ) );
		$listofurls[] = get_author_feed_link( get_post_field( 'post_author', $post_id ) );

		if ( get_post_type_archive_link( $post_type ) == true ) {
			$listofurls[] = get_post_type_archive_link( $post_type );
			$listofurls[] = get_post_type_archive_feed_link( $post_type );
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && $post->post_status === 'trash' ) {
			$listofurls = array_merge( $listofurls, self::get_trashed_post_published_links( $post ) );
		}

		$published_posts_count = (int) wp_count_posts( 'post' )->publish;

		if ( defined( 'SWCFPC_HOME_PAGE_SHOWS_POSTS' ) && \SWCFPC_HOME_PAGE_SHOWS_POSTS ) {
			$home_url     = home_url( '/' );
			$listofurls[] = $home_url;

			// A static front page isn't paginated - only the "posts page" mode below is.
			if ( get_option( 'show_on_front' ) !== 'page' ) {
				$listofurls = array_merge( $listofurls, self::get_paginated_urls( $home_url, $published_posts_count ) );
			}
		}

		$posts_page_id = (int) get_option( 'page_for_posts', 0 );
		if ( $posts_page_id > 0 && get_option( 'show_on_front' ) === 'page' ) {
			$page_link = get_permalink( $posts_page_id );
			if ( is_string( $page_link ) && ! empty( $page_link ) ) {
				$listofurls[] = $page_link;
				$listofurls   = array_merge( $listofurls, self::get_paginated_urls( $page_link, $published_posts_count ) );
			}
		}

		return $listofurls;
	}

	/**
	 * Get the URLs a trashed post had while it was published: its permalink and its comments feed.
	 *
	 * Core gives a trashed post a plain `?p=<id>` permalink and a `__trashed` slug, so both URLs
	 * are built from a published copy of the post that has the slug it had before it was trashed.
	 * A post that was neither published nor an attachment (`inherit`) before it was trashed, e.g. a draft,
	 * had no public URL, so it gets none.
	 *
	 * @param \WP_Post $post Trashed post.
	 *
	 * @return list<string>
	 */
	private static function get_trashed_post_published_links( \WP_Post $post ): array {
		global $wp_rewrite;

		// Core stores the status a post had before it was trashed; it is missing only when the post was trashed some other way.
		$status_before_trash = get_post_meta( $post->ID, '_wp_trash_meta_status', true );

		if ( is_string( $status_before_trash ) && ! in_array( $status_before_trash, [ '', 'publish', 'inherit' ], true ) ) {
			return [];
		}

		$desired_slug = get_post_meta( $post->ID, '_wp_desired_post_slug', true );
		$slug         = is_string( $desired_slug ) && $desired_slug !== ''
			? $desired_slug
			: (string) preg_replace( '/__trashed(?:-\d+)?$/', '', $post->post_name );

		// Without a slug, the permalink would point to an archive (e.g. `/feed/` or `/2026/10/`).
		if ( $slug === '' ) {
			return [];
		}

		$published_post              = clone $post;
		$published_post->post_status = 'publish';
		$published_post->post_name   = $slug;

		$permalink = get_permalink( $published_post );

		if ( ! is_string( $permalink ) || $permalink === '' ) {
			return [];
		}

		if ( ! $wp_rewrite->using_permalinks() ) {
			return [ $permalink, get_post_comments_feed_link( $post->ID ) ];
		}

		return [ $permalink, trailingslashit( $permalink ) . user_trailingslashit( 'feed', 'single_feed' ) ];
	}
}
