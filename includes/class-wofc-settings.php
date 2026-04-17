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
		<style>
			.wofc-matrix-wrap { overflow-x: auto; margin-top: 4px; }
			table.wofc-matrix { border-collapse: collapse; min-width: 600px; }
			table.wofc-matrix th,
			table.wofc-matrix td { padding: 8px 6px; border: 1px solid #e0e0e0; text-align: center; vertical-align: middle; }
			table.wofc-matrix thead th { background: #f8f9fa; font-size: 12px; font-weight: 600; white-space: nowrap; }
			table.wofc-matrix thead th:first-child { text-align: left; min-width: 160px; }
			table.wofc-matrix tbody td:first-child { text-align: left; font-weight: 600; white-space: nowrap; background: #f8f9fa; }
			table.wofc-matrix tbody tr:nth-child(even) { background: #fafafa; }
			table.wofc-matrix tbody tr:nth-child(even) td:first-child { background: #f0f0f1; }
			table.wofc-matrix tbody tr.wofc-row-locked { background: #fef8ee; }
			table.wofc-matrix tbody tr.wofc-row-locked:nth-child(even) { background: #fdf3e1; }
			table.wofc-matrix tbody tr.wofc-row-locked td:first-child { background: #fcefd4; border-left: 3px solid #dba617; }
			table.wofc-matrix .wofc-self { color: #c0c0c0; }
			table.wofc-matrix input[type="checkbox"] { margin: 0; }
			table.wofc-matrix .wofc-lock-col { background: #f8f9fa; border-right: 2px solid #c3c4c7; }
			table.wofc-matrix thead .wofc-lock-col { font-size: 11px; min-width: 70px; }
			table.wofc-matrix tbody tr.wofc-row-locked .wofc-lock-col { background: #fcefd4; }
		</style>
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
				<script>
					document.addEventListener( 'change', function( e ) {
						if ( ! e.target.classList.contains( 'wofc-lock-toggle' ) ) return;
						var row = e.target.closest( 'tr' );
						if ( e.target.checked ) {
							row.classList.add( 'wofc-row-locked' );
						} else {
							row.classList.remove( 'wofc-row-locked' );
						}
					});
				</script>
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

		$valid_statuses = array_map(
			[ 'WOFC_Transition_Manager', 'clean_status' ],
			array_keys( wc_get_order_statuses() )
		);

		$lock_flags = isset( $_POST['wofc_lock'] ) ? wp_unslash( $_POST['wofc_lock'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$matrix     = isset( $_POST['wofc_matrix'] ) ? wp_unslash( $_POST['wofc_matrix'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rules      = [];

		foreach ( $valid_statuses as $from ) {
			if ( ! isset( $lock_flags[ $from ] ) ) {
				continue; // Not locked → unrestricted, skip
			}

			$targets = [];
			if ( isset( $matrix[ $from ] ) && is_array( $matrix[ $from ] ) ) {
				foreach ( array_keys( $matrix[ $from ] ) as $to ) {
					$to = sanitize_text_field( $to );
					if ( in_array( $to, $valid_statuses, true ) && $to !== $from ) {
						$targets[] = $to;
					}
				}
			}

			$rules[ $from ] = $targets;
		}

		update_option( WOFC_Transition_Manager::OPTION_TRANSITIONS, $rules );
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
