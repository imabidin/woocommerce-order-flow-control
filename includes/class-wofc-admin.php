<?php
/**
 * Admin UI for WooCommerce Order Flow Control.
 *
 * Handles admin notices, bulk-action protection, and plugin action links.
 *
 * @package WOFC
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class WOFC_Admin {

	/**
	 * Register admin hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, 'show_blocked_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'show_bulk_blocked_notice' ] );
		add_action( 'wofc_transition_blocked', [ __CLASS__, 'set_blocked_redirect' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_settings_assets' ] );

		add_filter( 'plugin_action_links_' . WOFC_BASENAME, [ __CLASS__, 'add_plugin_action_links' ] );

		// Bulk action interception — HPOS + Legacy
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_actions' ], 5, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_actions' ], 5, 3 );
	}

	/**
	 * Show admin notice when a single-order transition was blocked.
	 *
	 * @since 2.0.0
	 */
	public static function show_blocked_notice() {
		if ( ! isset( $_GET['wofc_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$blocked_status = sanitize_text_field( wp_unslash( $_GET['wofc_blocked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>Order Flow Control:</strong> %s</p></div>',
			esc_html( sprintf(
				/* translators: %s: target status name */
				__( 'Status change to "%s" was blocked. Only forward transitions are allowed once an order is locked.', 'wofc' ),
				wc_get_order_status_name( $blocked_status )
			) )
		);
	}

	/**
	 * Set redirect query arg when a transition is blocked in admin context.
	 *
	 * @since 2.0.0
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 */
	public static function set_blocked_redirect( $order_id, $from, $to ) {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		add_filter( 'redirect_post_location', function ( $location ) use ( $to ) {
			return add_query_arg( 'wofc_blocked', urlencode( $to ), $location );
		} );
	}

	/**
	 * Intercept bulk status changes and skip orders where the transition is invalid.
	 *
	 * @since 2.0.0
	 * @param string $redirect_url
	 * @param string $action
	 * @param array  $order_ids
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect_url, $action, $order_ids ) {
		if ( 0 !== strpos( $action, 'mark_' ) ) {
			return $redirect_url;
		}

		if ( ! WOFC_Transition_Manager::is_enabled() ) {
			return $redirect_url;
		}

		$target_status = WOFC_Transition_Manager::clean_status( substr( $action, 5 ) );
		$blocked_count = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$current = $order->get_status();

			if ( ! WOFC_Transition_Manager::is_transition_allowed( $current, $target_status, $order_id ) ) {
				$blocked_count++;
			}
		}

		if ( $blocked_count > 0 ) {
			set_transient( 'wofc_bulk_blocked_' . get_current_user_id(), $blocked_count, 60 );
			$redirect_url = add_query_arg( 'wofc_bulk_blocked', '1', $redirect_url );
		}

		return $redirect_url;
	}

	/**
	 * Show admin notice after bulk action with blocked orders.
	 *
	 * @since 2.0.0
	 */
	public static function show_bulk_blocked_notice() {
		if ( ! isset( $_GET['wofc_bulk_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$user_id = get_current_user_id();
		$count   = get_transient( 'wofc_bulk_blocked_' . $user_id );
		delete_transient( 'wofc_bulk_blocked_' . $user_id );

		if ( ! $count ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Order Flow Control:</strong> %s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of blocked orders */
				_n(
					'%d order was skipped because the status transition is not allowed.',
					'%d orders were skipped because the status transition is not allowed.',
					$count,
					'wofc'
				),
				$count
			) )
		);
	}

	/**
	 * Enqueue CSS/JS for the settings tab.
	 *
	 * @since 3.0.0
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_settings_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		if ( ! isset( $_GET['tab'] ) || 'wofc' !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		wp_enqueue_style(
			'wofc-settings',
			plugins_url( 'assets/admin/settings.css', WOFC_FILE ),
			[],
			WOFC_VERSION
		);
		wp_enqueue_script(
			'wofc-settings',
			plugins_url( 'assets/admin/settings.js', WOFC_FILE ),
			[],
			WOFC_VERSION,
			true
		);
	}

	/**
	 * Add "Settings" link on the Plugins page.
	 *
	 * @since 2.0.0
	 * @param array $links
	 * @return array
	 */
	public static function add_plugin_action_links( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=wofc' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wofc' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}
}
