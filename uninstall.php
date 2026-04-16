<?php
/**
 * Uninstall WooCommerce Order Flow Control.
 *
 * Removes all plugin options from the database.
 *
 * @package WOFC
 * @since   2.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wofc_transition_rules' );
delete_option( 'wofc_enabled' );
