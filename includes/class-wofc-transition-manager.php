<?php
/**
 * Core transition logic for WooCommerce Order Flow Control.
 *
 * @package WOFC
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class WOFC_Transition_Manager {

	const OPTION_TRANSITIONS = 'wofc_transition_rules';
	const OPTION_ENABLED     = 'wofc_enabled';

	/** @var array|null Runtime cache for transition rules. */
	private static $cache = null;

	/** @var bool Guard flag to prevent infinite loops during revert. */
	private static $reverting = false;

	/**
	 * Register all enforcement hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'enforce_transition' ], 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', [ __CLASS__, 'enforce_rest_transition' ], 10, 2 );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'filter_order_statuses' ], 99 );
	}

	/**
	 * Write default transition rules on activation (only if no option exists yet).
	 *
	 * @since 2.0.0
	 */
	public static function set_defaults() {
		if ( false === get_option( self::OPTION_TRANSITIONS ) ) {
			update_option( self::OPTION_TRANSITIONS, self::get_default_transitions(), true );
		}
		if ( false === get_option( self::OPTION_ENABLED ) ) {
			update_option( self::OPTION_ENABLED, 'yes', true );
		}
	}

	/**
	 * Hard-coded fallback rules. Used as defaults on first activation.
	 *
	 * @since 2.0.0
	 * @return array<string, string[]>
	 */
	public static function get_default_transitions() {
		return [
			'processing'       => [ 'ready-production', 'in-production', 'completed', 'refunded' ],
			'ready-production' => [ 'in-production', 'completed', 'refunded' ],
			'in-production'    => [ 'completed', 'refunded' ],
			'completed'        => [ 'refunded' ],
			'refunded'         => [],
		];
	}

	/**
	 * Get current transition rules (cached, filterable).
	 *
	 * @since 2.0.0
	 * @return array<string, string[]>
	 */
	public static function get_allowed_transitions() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$rules = get_option( self::OPTION_TRANSITIONS, [] );

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			$rules = self::get_default_transitions();
		}

		/**
		 * Filter the transition rules at runtime.
		 *
		 * @since 1.0.0
		 * @param array<string, string[]> $rules Transition rules.
		 */
		self::$cache = apply_filters( 'wofc_allowed_transitions', $rules );

		return self::$cache;
	}

	/**
	 * Whether the plugin enforcement is enabled.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Check if a status is locked (= present as a key in the transition map).
	 *
	 * @since 2.0.0
	 * @param string $status Status slug (with or without wc- prefix).
	 * @return bool
	 */
	public static function is_locked_status( $status ) {
		return array_key_exists( self::clean_status( $status ), self::get_allowed_transitions() );
	}

	/**
	 * Check if a transition is allowed.
	 *
	 * @since 2.0.0
	 * @param string $from     Current status slug.
	 * @param string $to       Target status slug.
	 * @param int    $order_id Optional order ID (passed to filter).
	 * @return bool
	 */
	public static function is_transition_allowed( $from, $to, $order_id = 0 ) {
		$from = self::clean_status( $from );
		$to   = self::clean_status( $to );

		if ( $from === $to ) {
			return true;
		}

		if ( ! self::is_enabled() ) {
			return true;
		}

		if ( ! self::is_locked_status( $from ) ) {
			return true;
		}

		$rules   = self::get_allowed_transitions();
		$allowed = in_array( $to, $rules[ $from ] ?? [], true );

		/**
		 * Override whether a specific transition is allowed.
		 *
		 * @since 2.0.0
		 * @param bool   $allowed  Whether the transition is currently allowed.
		 * @param string $from     Source status (without wc- prefix).
		 * @param string $to       Target status (without wc- prefix).
		 * @param int    $order_id Order ID (0 when called from non-order contexts).
		 */
		return apply_filters( 'wofc_is_transition_allowed', $allowed, $from, $to, $order_id );
	}

	/**
	 * Get the list of statuses an order can move to from a given status.
	 *
	 * @since 2.0.0
	 * @param string $from Current status slug.
	 * @return string[]
	 */
	public static function get_allowed_targets( $from ) {
		$from  = self::clean_status( $from );
		$rules = self::get_allowed_transitions();

		return $rules[ $from ] ?? [];
	}

	/**
	 * Strip the wc- prefix from a status slug.
	 *
	 * @since 2.0.0
	 * @param string $status
	 * @return string
	 */
	public static function clean_status( $status ) {
		return (string) str_replace( 'wc-', '', $status );
	}

	/**
	 * Invalidate the runtime cache. Call after saving settings.
	 *
	 * @since 2.0.0
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Programmatic block: revert invalid status transitions.
	 *
	 * @since 1.0.0
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 */
	public static function enforce_transition( $order_id, $from, $to ) {
		if ( self::$reverting ) {
			return;
		}

		if ( self::is_transition_allowed( $from, $to, $order_id ) ) {
			return;
		}

		self::$reverting = true;

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->set_status( $from, sprintf(
				/* translators: %s: target status name */
				__( 'Status change to "%s" blocked by Order Flow Control — only forward transitions allowed.', 'wofc' ),
				wc_get_order_status_name( $to )
			) );
			$order->save();
		}

		self::$reverting = false;

		/**
		 * Fired when a status transition is blocked and reverted.
		 *
		 * @since 2.0.0
		 * @param int    $order_id
		 * @param string $from Original status.
		 * @param string $to   Attempted target status.
		 */
		do_action( 'wofc_transition_blocked', $order_id, $from, $to );
	}

	/**
	 * REST API block: return WP_Error for invalid transitions.
	 *
	 * @since 1.0.0
	 * @param WC_Order        $order
	 * @param WP_REST_Request $request
	 * @return WC_Order|WP_Error
	 */
	public static function enforce_rest_transition( $order, $request ) {
		if ( ! isset( $request['status'] ) ) {
			return $order;
		}

		$from = $order->get_status();
		$to   = self::clean_status( $request['status'] );

		if ( ! self::is_transition_allowed( $from, $to, $order->get_id() ) ) {
			return new WP_Error(
				'wofc_invalid_transition',
				sprintf(
					/* translators: 1: source status, 2: target status */
					__( 'Status transition from "%1$s" to "%2$s" is not allowed. Only forward transitions are permitted once an order is locked.', 'wofc' ),
					$from,
					$to
				),
				[ 'status' => 403 ]
			);
		}

		return $order;
	}

	/**
	 * Admin UI: filter the status dropdown to only show allowed transitions.
	 *
	 * @since 1.0.0
	 * @param array $statuses
	 * @return array
	 */
	public static function filter_order_statuses( $statuses ) {
		if ( ! is_admin() || ! self::is_enabled() ) {
			return $statuses;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return $statuses;
		}

		global $theorder;
		$order = $theorder;

		if ( ! $order && isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( $_GET['id'] ) );
		} elseif ( ! $order && isset( $_GET['post'] ) ) {
			$order = wc_get_order( absint( $_GET['post'] ) );
		}

		if ( ! $order ) {
			return $statuses;
		}

		$current = $order->get_status();

		if ( ! self::is_locked_status( $current ) ) {
			return $statuses;
		}

		$allowed   = self::get_allowed_targets( $current );
		$allowed[] = $current;

		$filtered = [];
		foreach ( $statuses as $slug => $label ) {
			if ( in_array( self::clean_status( $slug ), $allowed, true ) ) {
				$filtered[ $slug ] = $label;
			}
		}

		return $filtered;
	}
}
