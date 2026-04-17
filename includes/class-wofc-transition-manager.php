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

	/** @var bool Guard flag to prevent re-entrant calls during prevention. */
	private static $preventing = false;

	/**
	 * Register all enforcement hooks.
	 *
	 * @since 3.0.0
	 */
	public static function init() {
		add_action( 'woocommerce_before_order_object_save', [ __CLASS__, 'prevent_transition' ], 5, 2 );
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
			update_option( self::OPTION_TRANSITIONS, self::get_default_transitions(), false );
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
		 * @since 2.0.0
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
	 * Prevent invalid status transitions before they are committed to the database.
	 *
	 * Hooks into woocommerce_before_order_object_save which fires before the
	 * data store persists the order. If the transition is not allowed, the status
	 * is reverted on the in-memory object and the pending status_transition is
	 * cleared so no downstream hooks (emails, stock, timestamps) fire.
	 *
	 * @since 3.0.0
	 * @param WC_Abstract_Order $order      Order object being saved.
	 * @param mixed             $data_store Data store instance.
	 */
	public static function prevent_transition( $order, $data_store = null ) {
		if ( self::$preventing ) {
			return;
		}

		if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
			return;
		}

		$changes = $order->get_changes();
		if ( ! isset( $changes['status'] ) ) {
			return;
		}

		$to   = self::clean_status( $changes['status'] );
		$from = self::get_original_status( $order );

		if ( ! $from || $from === $to ) {
			return;
		}

		if ( self::is_transition_allowed( $from, $to, $order->get_id() ) ) {
			return;
		}

		// Revert the status on the object before it reaches the data store.
		self::$preventing = true;
		$order->set_status( $from );
		self::$preventing = false;

		// Clear the pending status_transition so no post-save hooks fire.
		self::clear_status_transition( $order );

		$order->add_order_note( sprintf(
			/* translators: 1: source status name, 2: target status name */
			__( 'Status change from "%1$s" to "%2$s" blocked by Order Flow Control — only forward transitions allowed.', 'wofc' ),
			wc_get_order_status_name( $from ),
			wc_get_order_status_name( $to )
		) );

		// Audit log (P1.3).
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Blocked transition on order #%d: %s → %s (user #%d, context: %s)',
					$order->get_id(),
					$from,
					$to,
					get_current_user_id(),
					self::detect_context()
				),
				[ 'source' => 'wofc' ]
			);
		}

		/**
		 * Fired when a status transition is prevented.
		 *
		 * @since 2.0.0
		 * @param int    $order_id
		 * @param string $from Original status (clean slug).
		 * @param string $to   Attempted target status (clean slug).
		 */
		do_action( 'wofc_transition_blocked', $order->get_id(), $from, $to );
	}

	/**
	 * REST API block: return WP_Error for invalid transitions.
	 *
	 * @since 2.0.0
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
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf(
						'Blocked REST transition on order #%d: %s → %s (user #%d)',
						$order->get_id(),
						$from,
						$to,
						get_current_user_id()
					),
					[ 'source' => 'wofc' ]
				);
			}

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
	 * @since 2.0.0
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

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the original (pre-change) status from the order's internal data store.
	 *
	 * Uses Closure::bind to access WC_Data::$data['status'] which holds the
	 * value loaded from the database, unaffected by set_prop() changes.
	 *
	 * @since 3.0.0
	 * @param WC_Order $order
	 * @return string|null Clean status slug, or null if unavailable.
	 */
	private static function get_original_status( $order ) {
		$original = Closure::bind( function () {
			return $this->data['status'] ?? null;
		}, $order, \WC_Data::class )();

		return $original ? self::clean_status( $original ) : null;
	}

	/**
	 * Clear the pending status_transition on the order object.
	 *
	 * This prevents WC_Order::status_transition() from firing any post-save
	 * hooks (emails, woocommerce_order_status_changed, etc.) for a transition
	 * that was blocked.
	 *
	 * @since 3.0.0
	 * @param WC_Order $order
	 */
	private static function clear_status_transition( $order ) {
		Closure::bind( function () {
			$this->status_transition = false;
		}, $order, \WC_Order::class )();
	}

	/**
	 * Classify the current request context for logging.
	 *
	 * @since 3.0.0
	 * @return string One of: cli, cron, ajax, rest, admin, frontend.
	 */
	private static function detect_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return 'rest';
		}
		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}
}
