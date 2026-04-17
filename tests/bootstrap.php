<?php
/**
 * PHPUnit bootstrap for WOFC unit tests.
 *
 * Loads Brain Monkey and the real Transition Manager class
 * so tests exercise the actual plugin code.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Constants expected by the plugin.
define( 'ABSPATH', __DIR__ . '/' );
define( 'WOFC_VERSION', '3.0.0' );
define( 'WOFC_FILE', __DIR__ . '/../woocommerce-order-flow-control.php' );
define( 'WOFC_PATH', __DIR__ . '/../' );
define( 'WOFC_BASENAME', 'woocommerce-order-flow-control/woocommerce-order-flow-control.php' );

require_once __DIR__ . '/../includes/class-wofc-transition-manager.php';
