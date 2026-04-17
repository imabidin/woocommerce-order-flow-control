<?php
/**
 * WooCommerce settings tab for Order Flow Control.
 *
 * @package WOFC
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class WOFC_Settings extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'wofc';
		$this->label = __( 'Order Control', 'wofc' );

		parent::__construct();

		// Remove tab from the standard array — we render it manually after "Order Statuses"
		remove_filter( 'woocommerce_settings_tabs_array', [ $this, 'add_settings_page' ], 20 );
		add_action( 'woocommerce_settings_tabs', [ $this, 'print_tab_link' ], 10 );

		add_action( 'woocommerce_admin_field_wofc_transition_matrix', [ $this, 'render_transition_matrix' ] );
	}

	/**
	 * Render our tab link after all other tabs (including "Order Statuses").
	 *
	 * @since 2.0.0
	 */
	public function print_tab_link() {
		global $current_tab;

		$active = ( $current_tab === $this->id ) ? 'nav-tab-active' : '';

		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . $this->id ) ),
			esc_attr( $active ),
			esc_html( $this->label )
		);
	}

	/**
	 * Get settings for the default section.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		return [
			[
				'title' => __( 'Order Flow Control', 'wofc' ),
				'type'  => 'title',
				'desc'  => __( 'Enforce one-way order status transitions to protect accounting integrity. Once an order reaches a locked status, it can only move forward.', 'wofc' ),
				'id'    => 'wofc_general_section',
			],
			[
				'title'   => __( 'Enable Flow Control', 'wofc' ),
				'desc'    => __( 'Enforce status transition rules on all orders', 'wofc' ),
				'id'      => WOFC_Transition_Manager::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'type' => 'sectionend',
				'id'   => 'wofc_general_section',
			],
			[
				'title' => __( 'Transition Rules', 'wofc' ),
				'type'  => 'title',
				'desc'  => __( 'Use "Rollback Lock" to restrict a status. Locked statuses can only move to checked targets. Unlocked statuses are unrestricted.', 'wofc' ),
				'id'    => 'wofc_transitions_section',
			],
			[
				'type' => 'wofc_transition_matrix',
				'id'   => WOFC_Transition_Manager::OPTION_TRANSITIONS,
			],
			[
				'type' => 'sectionend',
				'id'   => 'wofc_transitions_section',
			],
		];
	}

	/**
	 * Render the transition matrix table.
	 *
	 * @since 2.0.0
	 * @param array $value Field definition.
	 */
	public function render_transition_matrix( $value ) {
		$rules        = WOFC_Transition_Manager::get_allowed_transitions();
		$all_statuses = wc_get_order_statuses();

		?>
		<tr valign="top">
			<td class="forminp" style="padding-left: 0; padding-right: 0;">
				<div class="wofc-matrix-wrap">
				<table class="wofc-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From ↓ / To →', 'wofc' ); ?></th>
							<th class="wofc-lock-col"><?php esc_html_e( 'Rollback Lock', 'wofc' ); ?></th>
							<?php foreach ( $all_statuses as $slug => $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_statuses as $from_slug => $from_label ) :
							$from     = WOFC_Transition_Manager::clean_status( $from_slug );
							$targets  = $rules[ $from ] ?? [];
							$is_locked = array_key_exists( $from, $rules );
							$row_class = $is_locked ? 'wofc-row-locked' : '';
						?>
						<tr class="<?php echo esc_attr( $row_class ); ?>" data-status="<?php echo esc_attr( $from ); ?>">
							<td>
								<?php echo esc_html( $from_label ); ?>
							</td>
							<td class="wofc-lock-col">
								<input type="checkbox"
									name="wofc_lock[<?php echo esc_attr( $from ); ?>]"
									value="1"
									class="wofc-lock-toggle"
									<?php checked( $is_locked ); ?>
								/>
							</td>
							<?php foreach ( $all_statuses as $to_slug => $to_label ) :
								$to      = WOFC_Transition_Manager::clean_status( $to_slug );
								$checked = in_array( $to, $targets, true );
								$is_self = ( $to === $from );
							?>
								<td>
									<?php if ( $is_self ) : ?>
										<span class="wofc-self">—</span>
									<?php else : ?>
										<input type="checkbox"
											name="wofc_matrix[<?php echo esc_attr( $from ); ?>][<?php echo esc_attr( $to ); ?>]"
											value="1"
											<?php checked( $checked ); ?>
										/>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description" style="margin-top: 8px;">
					<?php esc_html_e( 'Enable "Rollback Lock" to restrict a status — locked statuses can only transition to checked targets. A locked status with no targets is a dead end (e.g. Refunded). Unlocked rows are unrestricted.', 'wofc' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save settings — standard fields + custom matrix.
	 *
	 * @since 2.0.0
	 */
	public function save() {
		parent::save();

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'woocommerce-settings' ) ) {
			return;
		}

		$valid_statuses = array_map(
			[ 'WOFC_Transition_Manager', 'clean_status' ],
			array_keys( wc_get_order_statuses() )
		);

		$lock_flags_raw = isset( $_POST['wofc_lock'] ) ? (array) wp_unslash( $_POST['wofc_lock'] ) : [];
		$matrix_raw     = isset( $_POST['wofc_matrix'] ) ? (array) wp_unslash( $_POST['wofc_matrix'] ) : [];

		$lock_flags = [];
		foreach ( $lock_flags_raw as $k => $v ) {
			$lock_flags[ sanitize_key( $k ) ] = '1';
		}

		$matrix = [];
		foreach ( $matrix_raw as $from_key => $targets ) {
			if ( ! is_array( $targets ) ) {
				continue;
			}
			$clean_from = sanitize_key( $from_key );
			$matrix[ $clean_from ] = [];
			foreach ( array_keys( $targets ) as $to_key ) {
				$matrix[ $clean_from ][ sanitize_key( $to_key ) ] = '1';
			}
		}

		$rules = [];

		foreach ( $valid_statuses as $from ) {
			if ( ! isset( $lock_flags[ $from ] ) ) {
				continue;
			}

			$targets = [];
			if ( isset( $matrix[ $from ] ) && is_array( $matrix[ $from ] ) ) {
				foreach ( array_keys( $matrix[ $from ] ) as $to ) {
					if ( in_array( $to, $valid_statuses, true ) && $to !== $from ) {
						$targets[] = $to;
					}
				}
			}

			$rules[ $from ] = $targets;
		}

		update_option( WOFC_Transition_Manager::OPTION_TRANSITIONS, $rules, false );
		WOFC_Transition_Manager::flush_cache();

		/**
		 * Fired after transition rules are saved via settings page.
		 *
		 * @since 2.0.0
		 * @param array $rules The new transition rules.
		 */
		do_action( 'wofc_transitions_updated', $rules );
	}
}
