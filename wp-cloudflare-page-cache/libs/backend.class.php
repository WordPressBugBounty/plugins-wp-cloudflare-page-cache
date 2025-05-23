<?php

use SPC\Constants;

defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

class SWCFPC_Backend {
	/**
	 * The main plugin class.
	 *
	 * @var \SW_CLOUDFLARE_PAGECACHE
	 */
	private $main_instance = null;
	private $debug_msg     = '';

	function __construct( $main_instance ) {
		$this->main_instance = $main_instance;
		$this->actions();
	}


	function actions() {

		add_action( 'init', [ $this, 'export_config' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'load_custom_wp_admin_styles_and_script' ] );

		// Modify Script Attributes based of the script handle
		add_filter( 'script_loader_tag', [ $this, 'modify_script_attributes' ], 10, 2 );

		add_action( 'admin_menu', [ $this, 'add_admin_menu_pages' ] );

		if ( is_admin() && is_user_logged_in() && current_user_can( 'manage_options' ) ) {

			// Action rows
			add_filter( 'post_row_actions', [ $this, 'add_post_row_actions' ], PHP_INT_MAX, 2 );
			add_filter( 'page_row_actions', [ $this, 'add_post_row_actions' ], PHP_INT_MAX, 2 );

		}

		if ( $this->main_instance->get_single_config( 'cf_remove_purge_option_toolbar', 0 ) == 0 && $this->main_instance->can_current_user_purge_cache() ) {

			// Load assets on frontend too
			add_action( 'wp_enqueue_scripts', [ $this, 'load_custom_wp_admin_styles_and_script' ] );

			// Admin toolbar options
			add_action( 'admin_bar_menu', [ $this, 'add_toolbar_items' ], PHP_INT_MAX );

			// Ajax nonce
			add_action( 'wp_footer', [ $this, 'add_ajax_nonce_everywhere' ] );

		}

		// Ajax nonce
		add_action( 'admin_footer', [ $this, 'add_ajax_nonce_everywhere' ] );


		// Ajax import config
		add_action( 'wp_ajax_swcfpc_import_config_file', [ $this, 'ajax_import_config_file' ] );


		// Footer
		if ( ! empty( $_GET['page'] ) ) {
			if ( strpos( $_GET['page'], 'wp-cloudflare-super-page-cache-' ) === 0 ) {
				add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ], 1 );
			}
		}

	}

	/**
	 * Load deps for dashboard dependencies.
	 */
	function load_dashboard_deps() {
		add_filter( 'themeisle-sdk/survey/' . SWCFPC_PRODUCT_SLUG, array( $this, 'get_survey_metadata' ), 10, 2 );
		add_filter( 'themeisle_sdk_blackfriday_data', array( $this, 'add_black_friday_data' ) );
		do_action( 'themeisle_internal_page', SWCFPC_PRODUCT_SLUG, 'dashboard' );
	}


	function load_custom_wp_admin_styles_and_script() {
		$screen = ( is_admin() && function_exists( 'get_current_screen' ) ) ? get_current_screen() : false;

		// Don't load the scripts for Oxygen Builder visual editor pages
		$page_action = $_GET['action'] ?? false;

		$on_oxygen_ct_builder_page = isset( $_GET['ct_builder'] ) && $_GET['ct_builder'] === 'true';
		$on_oxygen_builder_page    = ( substr( $page_action, 0, strlen( 'oxy_render' ) ) === 'oxy_render' ) ? true : false;

		$wp_scripts = wp_scripts();

		$plugin_version = $this->main_instance->get_plugin_version();

		wp_register_style( 'swcfpc_sweetalert_css', SWCFPC_PLUGIN_URL . 'assets/css/sweetalert2.min.css', [], '11.7.20' );
		wp_register_style( 'swcfpc_admin_css', SWCFPC_PLUGIN_URL . 'assets/css/style.min.css', [ 'swcfpc_sweetalert_css' ], $plugin_version );

		wp_register_script( 'swcfpc_sweetalert_js', SWCFPC_PLUGIN_URL . 'assets/js/sweetalert2.min.js', [], '11.7.20', true );
		wp_register_script( 'swcfpc_admin_js', SWCFPC_PLUGIN_URL . 'assets/js/backend.min.js', [ 'swcfpc_sweetalert_js' ], $plugin_version, true );
		wp_localize_script(
			'swcfpc_admin_js',
			'swcfpcOptions',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'cacheEnabled' => $this->main_instance->get_single_config( 'cf_cache_enabled', 0 ),
			]
		);

		// Making sure we are not adding the following scripts for AMP endpoints as they are not gonna work anyway and will be striped out by the AMP system
		if (
			! (
				( function_exists( 'amp_is_request' ) && ( ! is_admin() && amp_is_request() ) ) ||
				( function_exists( 'ampforwp_is_amp_endpoint' ) && ( ! is_admin() && ampforwp_is_amp_endpoint() ) ) ||
				( is_object( $screen ) && $screen->base === 'woofunnels_page_wfob' ) ||
				is_customize_preview() ||
				$on_oxygen_ct_builder_page ||
				$on_oxygen_builder_page
			)
		) {
			wp_enqueue_style( 'swcfpc_admin_css' );
			wp_enqueue_script( 'swcfpc_admin_js' );
		}
	}

	function modify_script_attributes( $tag, $handle ) {
		// List of scripts added by this plugin
		$plugin_scripts = [
			'swcfpc_sweetalert_js',
			'swcfpc_admin_js',
		];

		// Check if handle is any of the above scripts made sure we load them as defer
		if ( ! empty( $tag ) && in_array( $handle, $plugin_scripts ) ) {
			return str_replace( ' id', ' defer id', $tag );
		}

		return $tag;
	}


	function add_ajax_nonce_everywhere() {

		?>

		<div id="swcfpc-ajax-nonce" style="display:none;"><?php echo wp_create_nonce( 'ajax-nonce-string' ); ?></div>

		<?php

	}


	function add_debug_string( $title, $content ) {

		$this->debug_msg .= '<hr>';
		$this->debug_msg .= "<br><h2>{$title}</h2><div>{$content}</div>";

	}


	function add_toolbar_items( $admin_bar ) {
		$screen = is_admin() ? get_current_screen() : false;

		// Make sure we don't add the following admin bar menu as it is not gonna work for AMP endpoints anyway
		if (
			( function_exists( 'amp_is_request' ) && ( ! is_admin() && amp_is_request() ) ) ||
			( function_exists( 'ampforwp_is_amp_endpoint' ) && ( ! is_admin() && ampforwp_is_amp_endpoint() ) ) ||
			( is_object( $screen ) && $screen->base === 'woofunnels_page_wfob' ) ||
			is_customize_preview()
		) {
			return;
		}

		if ( $this->main_instance->get_single_config( 'cf_remove_purge_option_toolbar', 0 ) == 0 ) {

			$swpfpc_toolbar_container_url_query_arg_admin = [
				'page' => 'wp-cloudflare-super-page-cache-index',
				$this->main_instance->get_cache_controller()->get_cache_buster() => 1,
			];

			if ( $this->main_instance->get_single_config( Constants::SETTING_REMOVE_CACHE_BUSTER, 1 ) > 0 ) {
				$swpfpc_toolbar_container_url_query_arg_admin = [
					'page' => 'wp-cloudflare-super-page-cache-index',
				];
			}

			$admin_bar->add_menu(
				[
					'id'    => 'wp-cloudflare-super-page-cache-toolbar-container',
					'title' => '<span class="ab-icon"></span><span class="ab-label">' . __( 'Cache', 'wp-cloudflare-page-cache' ) . '</span>',
					'href'  => current_user_can( 'manage_options' ) ? add_query_arg( $swpfpc_toolbar_container_url_query_arg_admin, admin_url( 'options-general.php' ) ) : '#',
				]
			);

			if ( $this->main_instance->get_single_config( 'cf_cache_enabled', 0 ) > 0 ) {

				global $post;

				$admin_bar->add_menu(
					[
						'id'     => 'wp-cloudflare-super-page-cache-toolbar-purge-all',
						'parent' => 'wp-cloudflare-super-page-cache-toolbar-container',
						'title'  => __( 'Purge whole cache', 'wp-cloudflare-page-cache' ),
						// 'href' => add_query_arg( array( 'page' => 'wp-cloudflare-super-page-cache-index', $this->objects['cache_controller']->get_cache_buster() => 1, 'swcfpc-purge-cache' => 1), admin_url('options-general.php' ) ),
						'href'   => '#',
					]
				);

				if ( $this->main_instance->get_single_config( 'cf_purge_only_html', 0 ) > 0 ) {

					$admin_bar->add_menu(
						[
							'id'     => 'wp-cloudflare-super-page-cache-toolbar-force-purge-everything',
							'parent' => 'wp-cloudflare-super-page-cache-toolbar-container',
							'title'  => __( 'Force purge everything', 'wp-cloudflare-page-cache' ),
							// 'href' => add_query_arg( array( 'page' => 'wp-cloudflare-super-page-cache-index', $this->objects['cache_controller']->get_cache_buster() => 1, 'swcfpc-purge-cache' => 1), admin_url('options-general.php' ) ),
							'href'   => '#',
						]
					);

				}

				if ( is_object( $post ) ) {

					$admin_bar->add_menu(
						[
							'id'     => 'wp-cloudflare-super-page-cache-toolbar-purge-single',
							'parent' => 'wp-cloudflare-super-page-cache-toolbar-container',
							'title'  => __( 'Purge cache for this page only', 'wp-cloudflare-page-cache' ),
							'href'   => "#{$post->ID}",
						]
					);

				}           
			}       
		}

	}


	function add_post_row_actions( $actions, $post ) {
		if ( ! in_array( $post->post_type, [ 'shop_order', 'shop_subscription' ] ) ) {
			$actions['swcfpc_single_purge'] = '<a class="swcfpc_action_row_single_post_cache_purge" data-post_id="' . $post->ID . '" href="#" target="_blank">' . __( 'Purge Cache', 'wp-cloudflare-page-cache' ) . '</a>';
		}

		return $actions;

	}


	function add_admin_menu_pages() {

		$hook = add_submenu_page(
			'options-general.php',
			__( 'Super Page Cache', 'wp-cloudflare-page-cache' ),
			__( 'Super Page Cache', 'wp-cloudflare-page-cache' ),
			'manage_options',
			'wp-cloudflare-super-page-cache-index',
			[ $this, 'admin_menu_page_index' ]
			// "data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/Pg0KPCEtLSBHZW5lcmF0b3I6IEFkb2JlIElsbHVzdHJhdG9yIDE5LjAuMCwgU1ZHIEV4cG9ydCBQbHVnLUluIC4gU1ZHIFZlcnNpb246IDYuMDAgQnVpbGQgMCkgIC0tPg0KPHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgdmlld0JveD0iMCAwIDUxMi4wMTYgNTEyLjAxNiIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgNTEyLjAxNiA1MTIuMDE2OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBzdHlsZT0iZmlsbDojRkZDRTU0OyIgZD0iTTE3LjI1LDQ5My4xMzJjMy42MjUtMTAuMTg4LDguMzQ0LTIzLjE0MSwxMy42MjUtMzYuNTYzYzE5Ljg3NS01MC42NDIsMzAuNDA3LTY1Ljc4MiwzNC45MzgtNzAuMjk4DQoJYzYuNzgxLTYuNzk3LDE1LjE4OC0xMS4zNzUsMjQuMzEzLTEzLjI2NmwzLjE1Ni0wLjY1NmwzNS4zNDQtMzUuNzVsNDIuMzEyLDQ4Ljg3NWwtMzIuOTA2LDMxLjUxNmwtMC42ODgsMy4yMzUNCgljLTEuODc1LDkuMTI1LTYuNDY5LDE3LjUzMS0xMy4yNSwyNC4zNDRjLTQuNTMxLDQuNS0xOS42NTYsMTUuMDYyLTcwLjI4MiwzNC45MjNDNDAuMzc2LDQ4NC43NTcsMjcuNDA2LDQ4OS41MDcsMTcuMjUsNDkzLjEzMnoiLz4NCjxwYXRoIHN0eWxlPSJmaWxsOiNGNkJCNDI7IiBkPSJNMTI5LjE1OCwzMjAuOTQzTDg3Ljk3LDM2Mi41ODRjLTEwLjcxOSwyLjIxOS0yMS4xMjYsNy42MDktMjkuNjg4LDE2LjE3Mg0KCUMzNi40MDcsNDAwLjYzLDAsNTEwLjM2NiwwLDUxMC4zNjZzMTA5LjcyLTM2LjM5MSwxMzEuNjI2LTU4LjI4MmM4LjUzMS04LjU0NywxMy45MzgtMTguOTY5LDE2LjE1Ni0yOS43MDNsMzcuODEyLTM2LjIyDQoJTDEyOS4xNTgsMzIwLjk0M3ogTTEzMy4wNjQsNDA3LjAwNWwtNC43ODEsNC41OTRsLTEuMzQ0LDYuNDg0Yy0xLjQ2OSw3LjA3OS01LjA2MiwxMy42NDItMTAuMzc1LDE4Ljk1NA0KCWMtMS43NSwxLjc1LTEzLjIxOSwxMS41NzgtNjYuNTYzLDMyLjUxN2MtNS4wOTQsMS45ODQtMTAuMDk0LDMuOTA2LTE0LjkwNiw1LjcwM2MxLjgxMi00LjgxMiwzLjcxOS05LjgxMiw1LjcxOS0xNC44NzYNCgljMjAuOTM4LTUzLjM2LDMwLjc1LTY0LjgyOSwzMi41MzEtNjYuNTc5YzUuMzEzLTUuMzI4LDExLjg3Ni04LjkwNiwxOC45MzgtMTAuMzU5bDYuMzEyLTEuMzEybDQuNTMxLTQuNTc4bDI0Ljk2OS0yNS4yODENCglsMjguMTU2LDMyLjUxNkwxMzMuMDY0LDQwNy4wMDV6Ii8+DQo8Zz4NCgk8cGF0aCBzdHlsZT0iZmlsbDojREE0NDUzOyIgZD0iTTE5OS45MDksNDIzLjM5N2M1Ljk2OS0yLjc5NywxMS45MzgtNS43NjcsMTcuODc1LTguODc2bDEyMS41MDEtODYuNzgxDQoJCWM0Ljk2OS00LjY0MSw5Ljg3NS05LjM5MSwxNC43MTktMTQuMjAzYzIuNzgxLTIuODEyLDUuNTYzLTUuNjI1LDguMjgyLTguNDY5Yy0wLjQ2OSw1NS4zNTktMjUuODQ1LDExNS45MjMtNzQuMDMyLDE2NC4xMjcNCgkJYy0xNi4wNjIsMTYuMDQ3LTMzLjQ2OSwyOS41NjItNTEuNjI1LDQwLjQ4NGMtMC4xMjUsMC4wNzgtMC44NDUsMC41LTAuODQ1LDAuNWMtNC4wMzEsMi4xODgtOS4xODgsMS41NzgtMTIuNTk0LTEuODI4DQoJCWMtMS4xMjUtMS4xNDEtMS45MzgtMi40NjktMi40MzgtMy44NzVjMCwwLTAuMzc1LTEuMTA5LTAuNDY5LTEuNTk0bC0yMS45MzgtNzguNzY3DQoJCUMxOTguODc4LDQyMy44ODEsMTk5LjM3OCw0MjMuNjMxLDE5OS45MDksNDIzLjM5N3oiLz4NCgk8cGF0aCBzdHlsZT0iZmlsbDojREE0NDUzOyIgZD0iTTIwNy41MzQsMTUwLjI2OWMtMi44NDQsMi43MzQtNS42NTYsNS41MTYtOC40NjksOC4zMTJjLTQuODEzLDQuODI4LTkuNTYzLDkuNzM0LTE0LjE4OCwxNC43MDMNCgkJYy0yMS4yODEsMy04Ni44MTIsMTIxLjUxNy04Ni44MTIsMTIxLjUxN2MtMy4wOTQsNS45MzgtNi4wNjIsMTEuODkyLTguODc1LDE3Ljg3NmMtMC4yNSwwLjUxNi0wLjQ2OSwxLjAzMS0wLjcxOSwxLjU0Nw0KCQlMOS42ODgsMjkyLjI4NWMtMC40NjktMC4wOTQtMS41OTQtMC40NjktMS41OTQtMC40NjljLTEuNDA2LTAuNS0yLjcxOS0xLjMxMi0zLjg3NS0yLjQ1M2MtMy40MDYtMy40MDYtNC04LjU0Ny0xLjgxMi0xMi41OTQNCgkJYzAsMCwwLjQwNi0wLjcwMywwLjUtMC44MjhjMTAuOTA2LTE4LjE1NywyNC40MDYtMzUuNTYzLDQwLjQ2OS01MS42MjVDOTEuNTk1LDE3Ni4wOTcsMTUyLjE1OCwxNTAuNzIyLDIwNy41MzQsMTUwLjI2OXoiLz4NCjwvZz4NCjxwYXRoIHN0eWxlPSJmaWxsOiNFNkU5RUQ7IiBkPSJNMTk3LjAwMywxNTEuMDVjLTYwLjQwOCw2MC40MjItMTAzLjk3LDEyOS40MzgtMTI4LjI1MiwxOTYuMjk5DQoJYy0xLjI4MSwzLjc1LTAuNDY5LDguMDMxLDIuNTMxLDExLjAxNmw4Mi45MDcsODIuOTM4YzMsMi45NjksNy4yODEsMy43OTcsMTEuMDMxLDIuNTE2DQoJYzY2Ljg3Ni0yNC4yODIsMTM1Ljg3Ny02Ny44MjksMTk2LjI4NS0xMjguMjUxYzkzLjg3Ni05My44NDUsMTQ2LjU2My0yMDcuMDgxLDE1MC41MDEtMzAzLjY0NWMwLjEyNS0yLjg3NS0wLjkwNi02LjA0Ny0zLjA5NC04LjI1DQoJYy0yLjIxOS0yLjIwMy01LjM3NS0zLjIzNC04LjI4MS0zLjEwOUM0MDQuMDY5LDQuNTAxLDI5MC44NDgsNTcuMjA1LDE5Ny4wMDMsMTUxLjA1eiIvPg0KPGc+DQoJPHBhdGggc3R5bGU9ImZpbGw6IzQzNEE1NDsiIGQ9Ik0zMTcuNTk4LDIzNy41MzVjLTExLjM3NSwwLTIyLjA2Mi00LjQzOC0zMC4wOTQtMTIuNDY5Yy04LjAzMS04LjA0Ny0xMi40NjktMTguNzM1LTEyLjQ2OS0zMC4xMQ0KCQlzNC40MzgtMjIuMDYzLDEyLjQ2OS0zMC4xMWM4LjAzMS04LjAzMSwxOC43NS0xMi40NjksMzAuMDk0LTEyLjQ2OWMxMS4zNzUsMCwyMi4wNjIsNC40MzgsMzAuMTI1LDEyLjQ2OQ0KCQljMTYuNTk1LDE2LjYxLDE2LjU5NSw0My42MjUsMCw2MC4yMmMtOC4wNjIsOC4wMzEtMTguNzUsMTIuNDY5LTMwLjA5NCwxMi40NjlDMzE3LjU5OCwyMzcuNTM1LDMxNy41OTgsMjM3LjUzNSwzMTcuNTk4LDIzNy41MzV6Ig0KCQkvPg0KCTxwYXRoIHN0eWxlPSJmaWxsOiM0MzRBNTQ7IiBkPSJNMjI3LjI4NCwzMjcuODQ5Yy0xMS4zNzUsMC0yMi4wNjItNC40MjItMzAuMDk0LTEyLjQ2OWMtOC4wMzItOC4wMzEtMTIuNDctMTguNzM1LTEyLjQ3LTMwLjA5NQ0KCQljMC0xMS4zNzUsNC40MzgtMjIuMDc4LDEyLjQ3LTMwLjEyNWM4LjAzMS04LjAzMSwxOC43MTktMTIuNDY5LDMwLjA5NC0xMi40NjljMTEuMzc2LDAsMjIuMDYzLDQuNDM4LDMwLjEyNiwxMi40NjkNCgkJYzE2LjU5NCwxNi42MSwxNi41OTQsNDMuNjI2LDAsNjAuMjJDMjQ5LjM0NywzMjMuNDI3LDIzOC42NiwzMjcuODQ5LDIyNy4yODQsMzI3Ljg0OUwyMjcuMjg0LDMyNy44NDl6Ii8+DQo8L2c+DQo8Zz4NCgk8cGF0aCBzdHlsZT0iZmlsbDojQ0NEMUQ5OyIgZD0iTTM1NS4yNTQsMTU3LjMzMWMtMTAuMDYyLTEwLjA0Ny0yMy40MzgtMTUuNTk0LTM3LjY1Ni0xNS41OTRjLTE0LjE4OCwwLTI3LjU2Miw1LjU0Ny0zNy42MjUsMTUuNTk0DQoJCWMtMTAuMDMxLDEwLjA0Ny0xNS41OTQsMjMuNDIyLTE1LjU5NCwzNy42MjVjMCwxNC4yMTksNS41NjIsMjcuNTc5LDE1LjU5NCwzNy42NDFjMTAuMDYyLDEwLjA0NiwyMy40MzgsMTUuNTc4LDM3LjYyNSwxNS41NzgNCgkJYzE0LjIxOSwwLDI3LjU5NC01LjUzMSwzNy42NTYtMTUuNTc4QzM3Ni4wMDUsMjExLjg0NywzNzYuMDA1LDE3OC4wODIsMzU1LjI1NCwxNTcuMzMxeiBNMzQwLjE5MiwyMTcuNTM1DQoJCWMtNi4yNSw2LjIzNC0xNC40MDYsOS4zNTktMjIuNTk0LDkuMzU5Yy04LjE1NiwwLTE2LjM0NC0zLjEyNS0yMi41NjItOS4zNTljLTEyLjQ2OS0xMi40NjktMTIuNDY5LTMyLjY4OCwwLTQ1LjE1Nw0KCQljNi4yMTktNi4yMzQsMTQuNDA2LTkuMzQ0LDIyLjU2Mi05LjM0NGM4LjE4OCwwLDE2LjM0NCwzLjEwOSwyMi41OTQsOS4zNDRDMzUyLjY2LDE4NC44NDcsMzUyLjY2LDIwNS4wNjYsMzQwLjE5MiwyMTcuNTM1eiIvPg0KCTxwYXRoIHN0eWxlPSJmaWxsOiNDQ0QxRDk7IiBkPSJNMjI3LjI4NCwyMzIuMDY3Yy0xNC4yMTksMC0yNy41NjIsNS41MzEtMzcuNjI2LDE1LjU3OGMtMTAuMDYyLDEwLjA0Ni0xNS41OTQsMjMuNDIyLTE1LjU5NCwzNy42NDENCgkJYzAsMTQuMjA0LDUuNTMxLDI3LjU2MywxNS41OTQsMzcuNjI2YzEwLjA2MywxMC4wNDcsMjMuNDA3LDE1LjU5NCwzNy42MjYsMTUuNTk0YzE0LjIyLDAsMjcuNTk1LTUuNTQ3LDM3LjY1OC0xNS41OTQNCgkJYzIwLjc1LTIwLjc1LDIwLjc1LTU0LjUxNywwLTc1LjI2N0MyNTQuODc5LDIzNy41OTgsMjQxLjUwNCwyMzIuMDY3LDIyNy4yODQsMjMyLjA2N3ogTTI0OS44NzksMzA3Ljg0OQ0KCQljLTYuMjUsNi4yNS0xNC40MDcsOS4zNTktMjIuNTk1LDkuMzU5Yy04LjE1NiwwLTE2LjM0NC0zLjEwOS0yMi41NjItOS4zNTljLTEyLjQ3LTEyLjQ3LTEyLjQ3LTMyLjY4OCwwLTQ1LjE1Nw0KCQljNi4yMTktNi4yMzUsMTQuNDA2LTkuMzQ0LDIyLjU2Mi05LjM0NGM4LjE4OCwwLDE2LjM0NSwzLjEwOSwyMi41OTUsOS4zNDRDMjYyLjM0OCwyNzUuMTYsMjYyLjM0OCwyOTUuMzc5LDI0OS44NzksMzA3Ljg0OXoiLz4NCjwvZz4NCjxwYXRoIHN0eWxlPSJmaWxsOiNEQTQ0NTM7IiBkPSJNNDc5LjIyNSwxNDUuODE2TDM2Ni43NTUsMzMuMzYxYzQ1LjgxMy0xOS45MjIsOTEuNDctMzEuMDYzLDEzMy44NzYtMzIuNzk3DQoJYzIuOTA2LTAuMTI1LDYuMDYyLDAuOTA2LDguMjgxLDMuMTA5YzIuMTg4LDIuMjAzLDMuMjE5LDUuMzc1LDMuMDk0LDguMjVDNTEwLjI4Nyw1NC4zNjEsNDk5LjEzMSwxMDAuMDAzLDQ3OS4yMjUsMTQ1LjgxNnoiLz4NCjxnPg0KPC9nPg0KPGc+DQo8L2c+DQo8Zz4NCjwvZz4NCjxnPg0KPC9nPg0KPGc+DQo8L2c+DQo8Zz4NCjwvZz4NCjxnPg0KPC9nPg0KPGc+DQo8L2c+DQo8Zz4NCjwvZz4NCjxnPg0KPC9nPg0KPGc+DQo8L2c+DQo8Zz4NCjwvZz4NCjxnPg0KPC9nPg0KPGc+DQo8L2c+DQo8Zz4NCjwvZz4NCjwvc3ZnPg0K"
		);

		add_action(
			"load-$hook",
			function() {
				$this->load_dashboard_deps();
			}
		);

		add_submenu_page(
			'wp-cloudflare-super-page-cache-index',
			__( 'Settings', 'wp-cloudflare-page-cache' ),
			__( 'Settings', 'wp-cloudflare-page-cache' ),
			'manage_options',
			'wp-cloudflare-super-page-cache-index',
			[ $this, 'admin_menu_page_index' ]
		);

		add_submenu_page(
			'',
			__( 'Super Page Cache Nginx Settings', 'wp-cloudflare-page-cache' ),
			__( 'Super Page Cache Nginx Settings', 'wp-cloudflare-page-cache' ),
			'manage_options',
			'wp-cloudflare-super-page-cache-nginx-settings',
			[ $this, 'admin_menu_page_nginx_settings' ]
		);

	}


	function admin_menu_page_index() {

		if ( ! current_user_can( 'manage_options' ) ) {
			die( __( 'Permission denied', 'wp-cloudflare-page-cache' ) );
		}

		$error_msg      = '';
		$success_msg    = '';
		$domain_found   = false;
		$domain_zone_id = '';
		$wizard_active  = true;
		
		$has_pro = defined( 'SPC_PRO_PATH' );

		if ( $this->main_instance->has_cloudflare_api_zone_id() && $this->main_instance->get_cache_controller()->is_cache_enabled() ) {
			$wizard_active = false;
		}

		// Save settings
		if ( isset( $_POST['swcfpc_submit_general'] ) ) {

			// Verify nonce
			if ( ! isset( $_POST['swcfpc_index_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['swcfpc_index_nonce'] ), 'swcfpc_index_nonce' ) ) {
				die( __( 'Permission denied', 'wp-cloudflare-page-cache' ) );
			}
			$this->main_instance->set_single_config( 'cf_auth_mode', (int) $_POST['swcfpc_cf_auth_mode'] );
			$this->main_instance->set_single_config( 'cf_email', sanitize_email( $_POST['swcfpc_cf_email'] ) );
			$this->main_instance->set_single_config( 'cf_apikey', sanitize_text_field( $_POST['swcfpc_cf_apikey'] ) );
			$this->main_instance->set_single_config( 'cf_apitoken', sanitize_text_field( $_POST['swcfpc_cf_apitoken'] ) );
			$this->main_instance->set_single_config( 'cf_apitoken_domain', sanitize_text_field( $_POST['swcfpc_cf_apitoken_domain'] ) );

			// Force refresh on Cloudflare api class
			if (
				isset( $_POST['swcfpc_cf_apitoken'] ) && strlen( trim( $_POST['swcfpc_cf_apitoken'] ) ) > 0 ||
				( isset( $_POST['swcfpc_cf_email'] ) && strlen( trim( $_POST['swcfpc_cf_email'] ) ) > 0 && isset( $_POST['swcfpc_cf_apikey'] ) &&
				strlen( trim( $_POST['swcfpc_cf_apikey'] ) ) > 0 )
			) {
				$this->main_instance->get_cloudflare_handler()->set_auth_mode( (int) $_POST['swcfpc_cf_auth_mode'] );
				$this->main_instance->get_cloudflare_handler()->set_api_key( sanitize_text_field( $_POST['swcfpc_cf_apikey'] ) );
				$this->main_instance->get_cloudflare_handler()->set_api_email( sanitize_text_field( $_POST['swcfpc_cf_email'] ) );
				$this->main_instance->get_cloudflare_handler()->set_api_token( sanitize_text_field( $_POST['swcfpc_cf_apitoken'] ) );

				if ( isset( $_POST['swcfpc_cf_apitoken_domain'] ) && strlen( trim( $_POST['swcfpc_cf_apitoken_domain'] ) ) > 0 ) {
					$this->main_instance->get_cloudflare_handler()->set_api_token_domain( sanitize_text_field( $_POST['swcfpc_cf_apitoken_domain'] ) );
				}

				// Logs
				$this->main_instance->set_single_config( 'log_enabled', (int) $_POST['swcfpc_log_enabled'] );

				// Log max file size
				$this->main_instance->set_single_config( 'log_+max_file_size', (int) $_POST['swcfpc_log_max_file_size'] );

				// Log verbosity
				$this->main_instance->set_single_config( 'log_verbosity', sanitize_text_field( $_POST['swcfpc_log_verbosity'] ) );

				if ( $this->main_instance->get_single_config( 'log_enabled', 0 ) > 0 ) {
					$this->main_instance->get_logger()->enable_logging();
				} else {
					$this->main_instance->get_logger()->disable_logging();
				}

				// Purge whole cache before passing to html only cache purging, to avoid to unable to purge already cached pages not in list
				if ( $this->main_instance->get_cache_controller()->is_cache_enabled() && (int) $_POST['swcfpc_cf_purge_only_html'] > 0 && $this->main_instance->get_single_config( 'cf_purge_only_html', 0 ) == 0 ) {
					$this->main_instance->get_cache_controller()->purge_all( false, false, true );
				}

				// Additional page rule for backend bypassing
				if ( isset( $_POST['swcfpc_cf_bypass_backend_page_rule'] ) ) {

					if ( $this->main_instance->get_single_config( 'cf_woker_enabled', 0 ) == 0 && (int) $_POST['swcfpc_cf_woker_enabled'] == 0 ) {

						if ( ( (int) $_POST['swcfpc_cf_bypass_backend_page_rule'] > 0 && $this->main_instance->get_single_config( 'cf_bypass_backend_page_rule', 0 ) == 0 ) || ( (int) $_POST['swcfpc_cf_bypass_backend_page_rule'] == 0 && $this->main_instance->get_single_config( 'cf_bypass_backend_page_rule', 0 ) > 0 ) ) {
							$cf_error = '';
							$this->main_instance->get_cloudflare_handler()->disable_page_cache( $cf_error );
						}                   
					}

					$this->main_instance->set_single_config( 'cf_bypass_backend_page_rule', (int) $_POST['swcfpc_cf_bypass_backend_page_rule'] );

				}

				// Worker mode
				if ( isset( $_POST['swcfpc_cf_woker_enabled'] ) ) {

					if ( ( (int) $_POST['swcfpc_cf_woker_enabled'] == 0 && $this->main_instance->get_single_config( 'cf_woker_enabled', 0 ) > 0 ) || ( (int) $_POST['swcfpc_cf_woker_enabled'] > 0 && $this->main_instance->get_single_config( 'cf_woker_enabled', 0 ) == 0 ) ) {
						$cf_error = '';
						$this->main_instance->get_cache_controller()->purge_all( false, false, true );
						$this->main_instance->get_cloudflare_handler()->disable_page_cache( $cf_error );
					}

					$this->main_instance->set_single_config( 'cf_woker_enabled', (int) $_POST['swcfpc_cf_woker_enabled'] );

					if ( (int) $_POST['swcfpc_cf_woker_enabled'] > 0 ) {
						$this->main_instance->get_cloudflare_handler()->enable_worker_mode();
					}
				}

				// Cookies to exclude from cache in worker mode
				if ( isset( $_POST['swcfpc_cf_worker_bypass_cookies'] ) ) {

					$excluded_cookies_cf        = [];
					$excluded_cookies_cf_parsed = explode( "\n", $_POST['swcfpc_cf_worker_bypass_cookies'] );

					foreach ( $excluded_cookies_cf_parsed as $single_cookie_cf ) {

						if ( strlen( trim( $single_cookie_cf ) ) > 0 ) {
							$excluded_cookies_cf[] = trim( sanitize_text_field( $single_cookie_cf ) );
						}                   
					}

					if ( count( $excluded_cookies_cf ) > 0 ) {
						$this->main_instance->set_single_config( 'cf_worker_bypass_cookies', $excluded_cookies_cf );
					} else {
						$this->main_instance->set_single_config( 'cf_worker_bypass_cookies', [] );
					}               
				}

				if ( count( $this->main_instance->get_single_config( 'cf_zoneid_list', [] ) ) == 0 && ( $zone_id_list = $this->main_instance->get_cloudflare_handler()->get_zone_id_list( $error_msg ) ) ) {

					$this->main_instance->set_single_config( 'cf_zoneid_list', $zone_id_list );

					if ( $this->main_instance->get_single_config( 'cf_auth_mode', SWCFPC_AUTH_MODE_API_KEY ) == SWCFPC_AUTH_MODE_API_TOKEN && isset( $_POST['swcfpc_cf_apitoken_domain'] ) && strlen( trim( $_POST['swcfpc_cf_apitoken_domain'] ) ) > 0 ) {
						$this->main_instance->set_single_config( 'cf_zoneid', $zone_id_list[ $this->main_instance->get_single_config( 'cf_apitoken_domain', '' ) ] );
					}               
				}
			}

			// Salvataggio immediato per consentire di applicare subito i settaggi di connessione
			$this->main_instance->update_config();

			if ( isset( $_POST['swcfpc_cf_post_per_page'] ) && (int) $_POST['swcfpc_cf_post_per_page'] >= 0 ) {
				$this->main_instance->set_single_config( 'cf_post_per_page', (int) $_POST['swcfpc_cf_post_per_page'] );
			}

			if ( isset( $_POST['swcfpc_cf_maxage'] ) && (int) $_POST['swcfpc_cf_maxage'] >= 0 ) {
				$this->main_instance->set_single_config( 'cf_maxage', (int) $_POST['swcfpc_cf_maxage'] );
			}

			if ( isset( $_POST['swcfpc_cf_browser_maxage'] ) && (int) $_POST['swcfpc_cf_browser_maxage'] >= 0 ) {
				$this->main_instance->set_single_config( 'cf_browser_maxage', (int) $_POST['swcfpc_cf_browser_maxage'] );
			}

			if ( isset( $_POST['swcfpc_cf_zoneid'] ) ) {
				$zone_id = trim( sanitize_text_field( $_POST['swcfpc_cf_zoneid'] ) );
				if ( $zone_id !== $this->main_instance->get_single_config( 'cf_zoneid', '' ) ) {
					$this->main_instance->set_single_config( 'cf_zoneid', $zone_id );
					$cloudflare = new \SWCFPC_Cloudflare( $this->main_instance );
					$cloudflare->pull_existing_cache_rule();
					$ruleset_rule_id = $this->main_instance->get_single_config( 'cf_cache_settings_ruleset_rule_id', '' );

					// Automatically enable Cloudflare page cache on domain zone change if it was not enabled before.
					if (
						! empty( $zone_id ) &&
						$this->main_instance->get_cache_controller()->is_cache_enabled() &&
						$cloudflare->is_enabled() &&
						empty( $ruleset_rule_id ) &&
						empty( $this->main_instance->get_single_config( 'cf_woker_route_id', '' ) ) // Do not use both Worker and Page Rules.
					) {
						$cloudflare->enable_page_cache( $error_msg );
					}
				}
			}

			if ( isset( $_POST['swcfpc_cf_bypass_404'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_404', (int) $_POST['swcfpc_cf_bypass_404'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_404', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_single_post'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_single_post', (int) $_POST['swcfpc_cf_bypass_single_post'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_single_post', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_author_pages'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_author_pages', (int) $_POST['swcfpc_cf_bypass_author_pages'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_author_pages', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_search_pages'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_search_pages', (int) $_POST['swcfpc_cf_bypass_search_pages'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_search_pages', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_feeds'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_feeds', (int) $_POST['swcfpc_cf_bypass_feeds'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_feeds', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_category'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_category', (int) $_POST['swcfpc_cf_bypass_category'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_category', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_tags'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_tags', (int) $_POST['swcfpc_cf_bypass_tags'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_tags', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_archives'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_archives', (int) $_POST['swcfpc_cf_bypass_archives'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_archives', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_home'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_home', (int) $_POST['swcfpc_cf_bypass_home'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_home', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_front_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_front_page', (int) $_POST['swcfpc_cf_bypass_front_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_front_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_pages'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_pages', (int) $_POST['swcfpc_cf_bypass_pages'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_pages', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_amp'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_amp', (int) $_POST['swcfpc_cf_bypass_amp'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_amp', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_ajax'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_ajax', (int) $_POST['swcfpc_cf_bypass_ajax'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_ajax', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_query_var'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_query_var', (int) $_POST['swcfpc_cf_bypass_query_var'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_query_var', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_wp_json_rest'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_wp_json_rest', (int) $_POST['swcfpc_cf_bypass_wp_json_rest'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_wp_json_rest', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_sitemap'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_sitemap', (int) $_POST['swcfpc_cf_bypass_sitemap'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_sitemap', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_file_robots'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_file_robots', (int) $_POST['swcfpc_cf_bypass_file_robots'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_file_robots', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_logged_in'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_logged_in', (int) $_POST['swcfpc_cf_bypass_logged_in'] );
			}

			// Varnish
			if ( isset( $_POST['swcfpc_cf_varnish_support'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_support', (int) $_POST['swcfpc_cf_varnish_support'] );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_hostname'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_hostname', $_POST['swcfpc_cf_varnish_hostname'] );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_port'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_port', (int) $_POST['swcfpc_cf_varnish_port'] );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_auto_purge'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_auto_purge', (int) $_POST['swcfpc_cf_varnish_auto_purge'] );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_cw'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_cw', (int) $_POST['swcfpc_cf_varnish_cw'] );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_purge_method'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_purge_method', sanitize_text_field( $_POST['swcfpc_cf_varnish_purge_method'] ) );
			}

			if ( isset( $_POST['swcfpc_cf_varnish_purge_all_method'] ) ) {
				$this->main_instance->set_single_config( 'cf_varnish_purge_all_method', sanitize_text_field( $_POST['swcfpc_cf_varnish_purge_all_method'] ) );
			}

			// EDD
			if ( isset( $_POST['swcfpc_cf_bypass_edd_checkout_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_edd_checkout_page', (int) $_POST['swcfpc_cf_bypass_edd_checkout_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_edd_checkout_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_edd_login_redirect_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_edd_login_redirect_page', (int) $_POST['swcfpc_cf_bypass_edd_login_redirect_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_edd_login_redirect_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_edd_purchase_history_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_edd_purchase_history_page', (int) $_POST['swcfpc_cf_bypass_edd_purchase_history_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_edd_purchase_history_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_edd_success_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_edd_success_page', (int) $_POST['swcfpc_cf_bypass_edd_success_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_edd_success_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_edd_failure_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_edd_failure_page', (int) $_POST['swcfpc_cf_bypass_edd_failure_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_edd_failure_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_auto_purge_edd_payment_add'] ) ) {
				$this->main_instance->set_single_config( 'cf_auto_purge_edd_payment_add', (int) $_POST['swcfpc_cf_auto_purge_edd_payment_add'] );
			}


			// WooCommerce
			if ( isset( $_POST['swcfpc_cf_auto_purge_woo_product_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_auto_purge_woo_product_page', (int) $_POST['swcfpc_cf_auto_purge_woo_product_page'] );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_cart_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_cart_page', (int) $_POST['swcfpc_cf_bypass_woo_cart_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_cart_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_account_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_account_page', (int) $_POST['swcfpc_cf_bypass_woo_account_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_account_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_checkout_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_checkout_page', (int) $_POST['swcfpc_cf_bypass_woo_checkout_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_checkout_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_checkout_pay_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_checkout_pay_page', (int) $_POST['swcfpc_cf_bypass_woo_checkout_pay_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_checkout_pay_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_shop_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_shop_page', (int) $_POST['swcfpc_cf_bypass_woo_shop_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_shop_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_pages'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_pages', (int) $_POST['swcfpc_cf_bypass_woo_pages'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_pages', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_product_tax_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_tax_page', (int) $_POST['swcfpc_cf_bypass_woo_product_tax_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_tax_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_product_tag_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_tag_page', (int) $_POST['swcfpc_cf_bypass_woo_product_tag_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_tag_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_product_cat_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_cat_page', (int) $_POST['swcfpc_cf_bypass_woo_product_cat_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_cat_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_bypass_woo_product_page'] ) ) {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_page', (int) $_POST['swcfpc_cf_bypass_woo_product_page'] );
			} else {
				$this->main_instance->set_single_config( 'cf_bypass_woo_product_page', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_auto_purge_woo_scheduled_sales'] ) ) {
				$this->main_instance->set_single_config( 'cf_auto_purge_woo_scheduled_sales', (int) $_POST['swcfpc_cf_auto_purge_woo_scheduled_sales'] );
			} else {
				$this->main_instance->set_single_config( 'cf_auto_purge_woo_scheduled_sales', 0 );
			}

			// Swift Performance (Lite/Pro)
			if ( isset( $_POST['swcfpc_cf_spl_purge_on_flush_all'] ) ) {
				$this->main_instance->set_single_config( 'cf_spl_purge_on_flush_all', (int) $_POST['swcfpc_cf_spl_purge_on_flush_all'] );
			} else {
				$this->main_instance->set_single_config( 'cf_spl_purge_on_flush_all', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_spl_purge_on_flush_single_post'] ) ) {
				$this->main_instance->set_single_config( 'cf_spl_purge_on_flush_single_post', (int) $_POST['swcfpc_cf_spl_purge_on_flush_single_post'] );
			} else {
				$this->main_instance->set_single_config( 'cf_spl_purge_on_flush_single_post', 0 );
			}

			// W3TC
			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_minfy'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_minfy', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_minfy'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_minfy', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_posts'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_posts', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_posts'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_posts', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_objectcache'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_objectcache', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_objectcache'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_objectcache', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_fragmentcache'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_fragmentcache', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_fragmentcache'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_fragmentcache', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_dbcache'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_dbcache', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_dbcache'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_dbcache', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_w3tc_purge_on_flush_all'] ) ) {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_all', (int) $_POST['swcfpc_cf_w3tc_purge_on_flush_all'] );
			} else {
				$this->main_instance->set_single_config( 'cf_w3tc_purge_on_flush_all', 0 );
			}

			// LITESPEED CACHE
			if ( isset( $_POST['swcfpc_cf_litespeed_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_cache_flush', (int) $_POST['swcfpc_cf_litespeed_purge_on_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_cache_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_litespeed_purge_on_ccss_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_ccss_flush', (int) $_POST['swcfpc_cf_litespeed_purge_on_ccss_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_ccss_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_litespeed_purge_on_cssjs_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_cssjs_flush', (int) $_POST['swcfpc_cf_litespeed_purge_on_cssjs_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_cssjs_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_litespeed_purge_on_object_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_object_cache_flush', (int) $_POST['swcfpc_cf_litespeed_purge_on_object_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_object_cache_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_litespeed_purge_on_single_post_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_single_post_flush', (int) $_POST['swcfpc_cf_litespeed_purge_on_single_post_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_litespeed_purge_on_single_post_flush', 0 );
			}

			// AUTOPTIMIZE
			if ( isset( $_POST['swcfpc_cf_autoptimize_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_autoptimize_purge_on_cache_flush', (int) $_POST['swcfpc_cf_autoptimize_purge_on_cache_flush'] );
			}

			// HUMMINGBIRD
			if ( isset( $_POST['swcfpc_cf_hummingbird_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_hummingbird_purge_on_cache_flush', (int) $_POST['swcfpc_cf_hummingbird_purge_on_cache_flush'] );
			}

			// WP-OPTIMIZE
			if ( isset( $_POST['swcfpc_cf_wp_optimize_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_optimize_purge_on_cache_flush', (int) $_POST['swcfpc_cf_wp_optimize_purge_on_cache_flush'] );
			}

			// WP PERFORMANCE
			if ( isset( $_POST['swcfpc_cf_wp_performance_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_performance_purge_on_cache_flush', (int) $_POST['swcfpc_cf_wp_performance_purge_on_cache_flush'] );
			}

			// WP ROCKET
			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_post_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_post_flush', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_post_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_post_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_domain_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_domain_flush', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_domain_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_domain_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_cache_dir_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_cache_dir_flush', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_cache_dir_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_cache_dir_flush', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_clean_files'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_files', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_clean_files'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_files', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_clean_cache_busting'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_cache_busting', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_clean_cache_busting'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_cache_busting', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_clean_minify'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_minify', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_clean_minify'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_clean_minify', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_ccss_generation_complete'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_ccss_generation_complete', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_ccss_generation_complete'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_ccss_generation_complete', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_purge_on_rucss_job_complete'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_rucss_job_complete', (int) $_POST['swcfpc_cf_wp_rocket_purge_on_rucss_job_complete'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_rocket_purge_on_rucss_job_complete', 0 );
			}

			if ( isset( $_POST['swcfpc_cf_wp_rocket_disable_cache'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_rocket_disable_cache', (int) $_POST['swcfpc_cf_wp_rocket_disable_cache'] );
			}

			// WP Super Cache
			if ( isset( $_POST['swcfpc_cf_wp_super_cache_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wp_super_cache_on_cache_flush', (int) $_POST['swcfpc_cf_wp_super_cache_on_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wp_super_cache_on_cache_flush', 0 );
			}

			// Flying Press
			if ( isset( $_POST['swcfpc_cf_flypress_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_flypress_purge_on_cache_flush', (int) $_POST['swcfpc_cf_flypress_purge_on_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_flypress_purge_on_cache_flush', 0 );
			}

			// WP Asset Clean Up
			if ( isset( $_POST['swcfpc_cf_wpacu_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wpacu_purge_on_cache_flush', (int) $_POST['swcfpc_cf_wpacu_purge_on_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_wpacu_purge_on_cache_flush', 0 );
			}

			// Nginx Helper
			if ( isset( $_POST['swcfpc_cf_nginx_helper_purge_on_cache_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_nginx_helper_purge_on_cache_flush', (int) $_POST['swcfpc_cf_nginx_helper_purge_on_cache_flush'] );
			} else {
				$this->main_instance->set_single_config( 'cf_nginx_helper_purge_on_cache_flush', 0 );
			}

			// YASR
			if ( isset( $_POST['swcfpc_cf_yasr_purge_on_rating'] ) ) {
				$this->main_instance->set_single_config( 'cf_yasr_purge_on_rating', (int) $_POST['swcfpc_cf_yasr_purge_on_rating'] );
			}

			// Strip cookies
			if ( isset( $_POST['swcfpc_cf_strip_cookies'] ) ) {
				$this->main_instance->set_single_config( 'cf_strip_cookies', (int) $_POST['swcfpc_cf_strip_cookies'] );
			}

			// Purge cache lock
			if ( isset( $_POST['swcfpc_cf_purge_cache_lock'] ) ) {
				$this->main_instance->set_single_config( 'cf_purge_cache_lock', (int) $_POST['swcfpc_cf_purge_cache_lock'] );
			}

			// Htaccess
			if ( isset( $_POST['swcfpc_cf_cache_control_htaccess'] ) ) {
				$this->main_instance->set_single_config( 'cf_cache_control_htaccess', (int) $_POST['swcfpc_cf_cache_control_htaccess'] );
			}

			// Purge HTML pages only
			if ( isset( $_POST['swcfpc_cf_purge_only_html'] ) ) {
				$this->main_instance->set_single_config( 'cf_purge_only_html', (int) $_POST['swcfpc_cf_purge_only_html'] );
			}

			// Disable cache purging using queue
			if ( isset( $_POST['swcfpc_cf_disable_cache_purging_queue'] ) ) {
				$this->main_instance->set_single_config( 'cf_disable_cache_purging_queue', (int) $_POST['swcfpc_cf_disable_cache_purging_queue'] );
			}

			// Purge on upgrader process complete
			if ( isset( $_POST['swcfpc_cf_auto_purge_on_upgrader_process_complete'] ) ) {
				$this->main_instance->set_single_config( 'cf_auto_purge_on_upgrader_process_complete', (int) $_POST['swcfpc_cf_auto_purge_on_upgrader_process_complete'] );
			}

			// OPCache
			if ( isset( $_POST['swcfpc_cf_opcache_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_opcache_purge_on_flush', (int) $_POST['swcfpc_cf_opcache_purge_on_flush'] );
			}

			// WPEngine
			if ( isset( $_POST['swcfpc_cf_wpengine_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_wpengine_purge_on_flush', (int) $_POST['swcfpc_cf_wpengine_purge_on_flush'] );
			}

			// SpinupWP
			if ( isset( $_POST['swcfpc_cf_spinupwp_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_spinupwp_purge_on_flush', (int) $_POST['swcfpc_cf_spinupwp_purge_on_flush'] );
			}

			// Kinsta
			if ( isset( $_POST['swcfpc_cf_kinsta_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_kinsta_purge_on_flush', (int) $_POST['swcfpc_cf_kinsta_purge_on_flush'] );
			}

			// Siteground
			if ( isset( $_POST['swcfpc_cf_siteground_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_siteground_purge_on_flush', (int) $_POST['swcfpc_cf_siteground_purge_on_flush'] );
			}

			// Object cache
			if ( isset( $_POST['swcfpc_cf_object_cache_purge_on_flush'] ) ) {
				$this->main_instance->set_single_config( 'cf_object_cache_purge_on_flush', (int) $_POST['swcfpc_cf_object_cache_purge_on_flush'] );
			}

			// Prefetch URLs in viewport
			if ( isset( $_POST['swcfpc_cf_prefetch_urls_viewport'] ) ) {
				$this->main_instance->set_single_config( 'cf_prefetch_urls_viewport', (int) $_POST['swcfpc_cf_prefetch_urls_viewport'] );
			}

			// Redirect (301) for all URLs that for any reason have been indexed together with the cache buster
			if ( isset( $_POST['swcfpc_cf_seo_redirect'] ) ) {
				$this->main_instance->set_single_config( 'cf_seo_redirect', (int) $_POST['swcfpc_cf_seo_redirect'] );
			}

			// Purge cache URL secret key
			if ( isset( $_POST['swcfpc_cf_purge_url_secret_key'] ) ) {
				$this->main_instance->set_single_config( 'cf_purge_url_secret_key', trim( sanitize_text_field( $_POST['swcfpc_cf_purge_url_secret_key'] ) ) );
			}

			// Remove purge option from toolbar
			if ( isset( $_POST['swcfpc_cf_remove_purge_option_toolbar'] ) ) {
				$this->main_instance->set_single_config( 'cf_remove_purge_option_toolbar', (int) $_POST['swcfpc_cf_remove_purge_option_toolbar'] );
			}

			// Disable metabox from single post/page
			if ( isset( $_POST['swcfpc_cf_disable_single_metabox'] ) ) {
				$this->main_instance->set_single_config( 'cf_disable_single_metabox', (int) $_POST['swcfpc_cf_disable_single_metabox'] );
			}

			// Enable fallback page cache
			if ( isset( $_POST['swcfpc_cf_fallback_cache'] ) ) {

				if ( ! $this->main_instance->get_cache_controller()->is_cache_enabled() || ( $this->main_instance->get_single_config( 'cf_fallback_cache', 0 ) > 0 && (int) $_POST['swcfpc_cf_fallback_cache'] == 0 ) || (int) $_POST['swcfpc_cf_fallback_cache_curl'] > 0 ) {
					$this->main_instance->get_fallback_cache_handler()->fallback_cache_advanced_cache_disable();
				}

				if ( $this->main_instance->get_cache_controller()->is_cache_enabled() && (int) $_POST['swcfpc_cf_fallback_cache'] > 0 && (int) $_POST['swcfpc_cf_fallback_cache_curl'] == 0 ) {
					$this->main_instance->get_fallback_cache_handler()->fallback_cache_advanced_cache_enable();
				}

				$this->main_instance->set_single_config( 'cf_fallback_cache', (int) $_POST['swcfpc_cf_fallback_cache'] );

			}

			if ( isset( $_POST['swcfpc_cf_fallback_cache_auto_purge'] ) ) {
				$this->main_instance->set_single_config( 'cf_fallback_cache_auto_purge', (int) $_POST['swcfpc_cf_fallback_cache_auto_purge'] );
			}

			if ( isset( $_POST['swcfpc_cf_fallback_cache_curl'] ) ) {
				$this->main_instance->set_single_config( 'cf_fallback_cache_curl', (int) $_POST['swcfpc_cf_fallback_cache_curl'] );
			}

			if ( isset( $_POST['swcfpc_cf_fallback_cache_save_headers'] ) ) {
				$this->main_instance->set_single_config( 'cf_fallback_cache_save_headers', (int) $_POST['swcfpc_cf_fallback_cache_save_headers'] );
			}

			if ( isset( $_POST['swcfpc_cf_fallback_cache_prevent_cache_urls_without_trailing_slash'] ) ) {
				$this->main_instance->set_single_config( 'cf_fallback_cache_prevent_cache_urls_without_trailing_slash', (int) $_POST['swcfpc_cf_fallback_cache_prevent_cache_urls_without_trailing_slash'] );
			}

			if ( isset( $_POST['swcfpc_cf_fallback_cache_ttl'] ) && (int) $_POST['swcfpc_cf_fallback_cache_ttl'] >= 0 ) {
				$this->main_instance->set_single_config( 'cf_fallback_cache_ttl', (int) $_POST['swcfpc_cf_fallback_cache_ttl'] );
			}

			// URLs to exclude from cache
			if ( isset( $_POST['swcfpc_cf_fallback_cache_excluded_urls'] ) ) {

				$excluded_urls = [];

				// $excluded_urls = str_replace( array('http:', 'https:', 'ftp:'), '', $_POST['swcfpc_cf_excluded_urls']);
				$parsed_excluded_urls = explode( "\n", $_POST['swcfpc_cf_fallback_cache_excluded_urls'] );

				foreach ( $parsed_excluded_urls as $single_url ) {

					if ( trim( $single_url ) == '' ) {
						continue;
					}

					$parsed_url = parse_url( str_replace( [ "\r", "\n" ], '', $single_url ) );

					if ( $parsed_url && isset( $parsed_url['path'] ) ) {

						$uri = $parsed_url['path'];

						// Force trailing slash
						if ( strlen( $uri ) > 1 && $uri[ strlen( $uri ) - 1 ] != '/' && $uri[ strlen( $uri ) - 1 ] != '*' ) {
							$uri .= '/';
						}

						if ( isset( $parsed_url['query'] ) ) {
							$uri .= "?{$parsed_url['query']}";
						}

						if ( ! in_array( $uri, $excluded_urls ) ) {
							$excluded_urls[] = $uri;
						}                   
					}               
				}

				if ( count( $excluded_urls ) > 0 ) {
					$this->main_instance->set_single_config( 'cf_fallback_cache_excluded_urls', $excluded_urls );
				} else {
					$this->main_instance->set_single_config( 'cf_fallback_cache_excluded_urls', [] );
				}           
			}

			// Enable preloader
			if ( isset( $_POST['swcfpc_cf_preloader'] ) ) {
				$this->main_instance->set_single_config( 'cf_preloader', (int) $_POST['swcfpc_cf_preloader'] );
			}

			// Automatically start preloader on page purge
			if ( isset( $_POST['swcfpc_cf_preloader_start_on_purge'] ) ) {
				$this->main_instance->set_single_config( 'cf_preloader_start_on_purge', (int) $_POST['swcfpc_cf_preloader_start_on_purge'] );
			}

			// Preloading logic
			if ( isset( $_POST['swcfpc_cf_preloader_nav_menus'] ) && is_array( $_POST['swcfpc_cf_preloader_nav_menus'] ) && count( $_POST['swcfpc_cf_preloader_nav_menus'] ) > 0 ) {
				$this->main_instance->set_single_config( 'cf_preloader_nav_menus', $_POST['swcfpc_cf_preloader_nav_menus'] );
			} else {
				$this->main_instance->set_single_config( 'cf_preloader_nav_menus', [] );
			}

			if ( isset( $_POST['swcfpc_cf_preload_last_urls'] ) ) {
				$this->main_instance->set_single_config( 'cf_preload_last_urls', (int) $_POST['swcfpc_cf_preload_last_urls'] );
			} else {
				$this->main_instance->set_single_config( 'cf_preload_last_urls', 0 );
			}

			// Preloader URL secret key
			if ( isset( $_POST['swcfpc_cf_preloader_url_secret_key'] ) ) {
				$this->main_instance->set_single_config( 'cf_preloader_url_secret_key', trim( sanitize_text_field( $_POST['swcfpc_cf_preloader_url_secret_key'] ) ) );
			}

			// Purge roles
			if ( isset( $_POST['swcfpc_purge_roles'] ) && is_array( $_POST['swcfpc_purge_roles'] ) && count( $_POST['swcfpc_purge_roles'] ) > 0 ) {
				$this->main_instance->set_single_config( 'cf_purge_roles', $_POST['swcfpc_purge_roles'] );
			} else {
				$this->main_instance->set_single_config( 'cf_purge_roles', [] );
			}

			/**
			 * @var \SWCFPC_Cloudflare $cloudflare
			 */
			$cloudflare      = $this->main_instance->get_cloudflare_handler();
			$ruleset_rule_id = $this->main_instance->get_single_config( 'cf_cache_settings_ruleset_rule_id', '' );

			if (
				isset( $_POST['swcfpc_enable_cache_rule'] ) &&
				(int) $_POST['swcfpc_enable_cache_rule'] > 0 &&
				$this->main_instance->get_cache_controller()->is_cache_enabled() &&
				$this->main_instance->get_cloudflare_handler()->is_enabled() &&
				empty( $ruleset_rule_id ) &&
				empty( $this->main_instance->get_single_config( 'cf_woker_route_id', '' ) ) // Do not use both Worker and Page Rules.
			) {
				$cloudflare->enable_page_cache( $error_msg );
			} elseif (
				isset( $_POST['swcfpc_enable_cache_rule'] ) &&
				0 === (int) $_POST['swcfpc_enable_cache_rule']
			) {
				$cloudflare->delete_legacy_page_rules( $error_msg );
				$cloudflare->delete_cache_rule( $error_msg );
			}


			do_action( 'swcfpc_after_settings_update', $_POST );

			// Update htaccess
			$this->main_instance->get_cache_controller()->write_htaccess( $error_msg );

			// Save config
			$this->main_instance->update_config();
			$success_msg = __( 'Settings updated successfully', 'wp-cloudflare-page-cache' );

			if ( $this->main_instance->get_single_config( 'cf_fallback_cache', 0 ) > 0 && $this->main_instance->get_single_config( 'cf_fallback_cache_curl', 0 ) == 0 ) {
				$this->main_instance->get_fallback_cache_handler()->fallback_cache_save_config();
			}

			$cloudflare->update_cache_rule_if_diff();
		}

		require_once SWCFPC_PLUGIN_PATH . 'libs/views/settings.php';
	}


	function admin_menu_page_nginx_settings() {

		if ( ! current_user_can( 'manage_options' ) ) {
			die( __( 'Permission denied', 'wp-cloudflare-page-cache' ) );
		}

		$nginx_lines = $this->main_instance->get_cache_controller()->get_nginx_rules();

		require_once SWCFPC_PLUGIN_PATH . 'libs/views/nginx.php';

	}


	function admin_footer_text( $footer_text ) {

		$stars = '<span class="wporg-ratings rating-stars"><span class="dashicons dashicons-star-filled" style="color:#ffb900 !important;"></span><span class="dashicons dashicons-star-filled" style="color:#ffb900 !important;"></span><span class="dashicons dashicons-star-filled" style="color:#ffb900 !important;"></span><span class="dashicons dashicons-star-filled" style="color:#ffb900 !important;"></span><span class="dashicons dashicons-star-filled" style="color:#ffb900 !important;"></span></span>';

		$rate_us = '<a href="' . SWCFPC_PLUGIN_REVIEWS_URL . '?filter=5#new-post" rel="noopener noreferer" target="_blank">'
				. sprintf( __( 'Rate %1$s on %2$s', 'wp-cloudflare-page-cache' ), '<strong>' . __( 'Super Page Cache', 'wp-cloudflare-page-cache' ) . $stars . '</strong>', 'WordPress.org' )
			. '</a>';

		$forum = '<a href="' . SWCFPC_PLUGIN_FORUM_URL . '" target="_blank">' . __( 'Visit support forum', 'wp-cloudflare-page-cache' ) . '</a>';

		$footer_text = $rate_us . ' | ' . $forum;

		return $footer_text;

	}


	function export_config() {

		if ( isset( $_GET['swcfpc_export_config'] ) && current_user_can( 'manage_options' ) ) {

			$config   = json_encode( $this->main_instance->get_config() );
			$filename = 'swcfpc_config.json';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/octet-stream' );
			header( "Content-Disposition: attachment; filename={$filename}" );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Connection: Keep-Alive' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Pragma: public' );
			header( 'Content-Length: ' . strlen( $config ) );

			die( $config );

		}

	}


	function ajax_import_config_file() {

		check_ajax_referer( 'ajax-nonce-string', 'security' );

		$return_array = [ 'status' => 'ok' ];

		if ( ! current_user_can( 'manage_options' ) ) {
			$return_array['status'] = 'error';
			$return_array['error']  = __( 'Permission denied', 'wp-cloudflare-page-cache' );
			die( json_encode( $return_array ) );
		}

		$data = stripslashes( $_POST['data'] );
		$data = json_decode( $data, true );

		if ( ! is_array( $data ) || ! isset( $data['config_file'] ) ) {
			$return_array['status'] = 'error';
			$return_array['error']  = __( 'Invalid data', 'wp-cloudflare-page-cache' );
			die( json_encode( $return_array ) );
		}

		$import_config = json_decode( trim( $data['config_file'] ), true );

		if ( ! is_array( $import_config ) ) {
			$return_array['status'] = 'error';
			$return_array['error']  = __( 'Invalid config file', 'wp-cloudflare-page-cache' );
			die( json_encode( $return_array ) );
		}

		$this->main_instance->get_cache_controller()->reset_all();

		unset( $import_config['cf_zoneid'] );
		unset( $import_config['cf_zoneid_list'] );
		unset( $import_config['cf_email'] );
		unset( $import_config['cf_apitoken'] );
		unset( $import_config['cf_apikey'] );
		unset( $import_config['cf_token'] );
		unset( $import_config['cf_old_bc_ttl'] );
		unset( $import_config['cf_page_rule_id'] );
		unset( $import_config['cf_woker_id'] );
		unset( $import_config['cf_woker_route_id'] );
		unset( $import_config['cf_cache_enabled'] );
		unset( $import_config['cf_apitoken_domain'] );
		unset( $import_config['cf_preloader_nav_menus'] );

		$default_config = $this->main_instance->get_config();
		$default_config = array_merge( $default_config, $import_config );
		$this->main_instance->set_config( $default_config );
		$this->main_instance->update_config();

		$return_array['success_msg'] = __( 'Configurations imported successfully. Now you must re-enter the Cloudflare API key or token and re-enable the page cache.', 'wp-cloudflare-page-cache' );

		die( json_encode( $return_array ) );

	}

	/**
	 * Get the survey metadata.
	 *
	 * @param array $data The data for survey in Formbrick format.
	 * @param string $page_slug The slug of the page.
	 * 
	 * @return array The survey metadata.
	 */
	function get_survey_metadata( $data, $page_slug ) {
		$free_slug_key    = 'wp_cloudflare_page_cache';
		$current_slug_key = $this->get_product_key( SWCFPC_PRODUCT_SLUG );
		
		$current_time = time();
		$install_date = get_option( $current_slug_key . '_install', $current_time );

		if ( defined( 'SPC_PRO_PATH' ) ) {
			$install_date = min( $install_date, get_option( $free_slug_key . '_install', $current_time ) );
		}

		$install_days_number = intval( ( $current_time - $install_date ) / DAY_IN_SECONDS );

		$plugin_data    = get_plugin_data( SWCFPC_BASEFILE, false, false );
		$plugin_version = '';

		if ( ! empty( $plugin_data['Version'] ) ) {
			$plugin_version = $plugin_data['Version'];
		}

		$data = [
			'environmentId' => 'clt8lntxw0zbu5zwkn3q2ybkq',
			'attributes'    => [
				'plugin_version'      => $plugin_version,
				'install_days_number' => $install_days_number,
				'license_status'      => apply_filters( 'product_spc_license_status', 'invalid' ),
				'plan'                => apply_filters( 'product_spc_license_plan', 0 ),
			],
		];

		$license = apply_filters( 'product_spc_license_key', false );
		if ( ! empty( $license ) ) {
			$data['attributes']['license_key'] = apply_filters( 'themeisle_sdk_secret_masking', $license );
		}

		return $data;
	}

	/**
	 * Get the product key based on the file path.
	 * 
	 * @param string $product_slug The slug of the product.
	 * 
	 * @return string - The product key.
	 */
	private function get_product_key( $product_slug ) {
		return str_replace( '-', '_', strtolower( trim( $product_slug ) ) );
	}

	/**
	 * Set the black friday data.
	 *
	 * @param array $configs The configuration array for the loaded products.
	 * @return array
	 */
	public function add_black_friday_data( $configs ) {
		$config = $configs['default'];

		// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
		$message_template = __( 'Our biggest sale of the year: %1$sup to %2$s OFF%3$s on %4$s. Don\'t miss this limited-time offer.', 'wp-cloudflare-page-cache' );
		$product_label    = 'Super Page Cache';
		$discount         = '70%';
		
		$plan    = apply_filters( 'product_spc_license_plan', 0 );
		$license = apply_filters( 'product_spc_license_key', false );
		$is_pro  = 0 < $plan;

		if ( $is_pro ) {
			// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
			$message_template = __( 'Get %1$sup to %2$s off%3$s when you upgrade your %4$s plan or renew early.', 'wp-cloudflare-page-cache' );
			$product_label    = 'Super Page Cache Pro';
			$discount         = '30%';
		}

		$product_label = sprintf( '<strong>%s</strong>', $product_label );
		$url_params    = array(
			'utm_term' => $is_pro ? 'plan-' . $plan : 'free',
			'lkey'     => ! empty( $license ) ? $license : false,
		);

		$config['message']  = sprintf( $message_template, '<strong>', $discount, '</strong>', $product_label );
		$config['sale_url'] = add_query_arg(
			$url_params,
			tsdk_translate_link( tsdk_utmify( 'https://themeisle.link/spc-bf', 'bfcm', 'spc' ) )
		);

		$configs[ SWCFPC_PRODUCT_SLUG ] = $config;

		return $configs;
	}
}
