<?php
/**
 * Unit tests for WOFC_Transition_Manager.
 *
 * Uses Brain Monkey to mock WordPress/WooCommerce functions.
 * Tests exercise the real class, not a duplicate.
 *
 * @package WOFC\Tests
 */

namespace WOFC\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WOFC_Transition_Manager;

class TransitionManagerTest extends TestCase {

	/**
	 * Default transition rules used across tests.
	 */
	private static $default_rules = [
		'processing'       => [ 'ready-production', 'in-production', 'completed', 'refunded' ],
		'ready-production' => [ 'in-production', 'completed', 'refunded' ],
		'in-production'    => [ 'completed', 'refunded' ],
		'completed'        => [ 'refunded' ],
		'refunded'         => [],
	];

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();

		// Default mocks — most tests use the standard rule set.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( WOFC_Transition_Manager::OPTION_TRANSITIONS === $key ) {
				return self::$default_rules;
			}
			if ( WOFC_Transition_Manager::OPTION_ENABLED === $key ) {
				return 'yes';
			}
			return $default;
		} );

		// Let apply_filters pass through by default.
		Functions\when( 'apply_filters' )->alias( function () {
			$args = func_get_args();
			return $args[1]; // Return the value (second argument).
		} );

		// Flush cache between tests.
		WOFC_Transition_Manager::flush_cache();
	}

	protected function tear_down() {
		Monkey\tearDown();
		WOFC_Transition_Manager::flush_cache();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Test 1: Unlocked statuses have no restrictions
	// -----------------------------------------------------------------

	public function test_unlocked_status_allows_any_target() {
		$targets = [ 'processing', 'completed', 'refunded', 'cancelled', 'on-hold' ];
		foreach ( $targets as $to ) {
			$this->assertTrue(
				WOFC_Transition_Manager::is_transition_allowed( 'pending', $to ),
				"pending → {$to} should be allowed (unlocked status)"
			);
		}
	}

	public function test_on_hold_is_unlocked() {
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'on-hold', 'pending' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'on-hold', 'cancelled' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'on-hold', 'completed' ) );
	}

	// -----------------------------------------------------------------
	// Test 2: Same-status transitions always allowed
	// -----------------------------------------------------------------

	public function test_same_status_always_allowed() {
		$statuses = [ 'pending', 'processing', 'completed', 'refunded', 'cancelled' ];
		foreach ( $statuses as $status ) {
			$this->assertTrue(
				WOFC_Transition_Manager::is_transition_allowed( $status, $status ),
				"{$status} → {$status} should always be allowed"
			);
		}
	}

	// -----------------------------------------------------------------
	// Test 3: Forward transitions from locked statuses
	// -----------------------------------------------------------------

	public function test_forward_transitions_allowed() {
		$cases = [
			[ 'processing', 'ready-production' ],
			[ 'processing', 'in-production' ],
			[ 'processing', 'completed' ],
			[ 'processing', 'refunded' ],
			[ 'ready-production', 'in-production' ],
			[ 'ready-production', 'completed' ],
			[ 'ready-production', 'refunded' ],
			[ 'in-production', 'completed' ],
			[ 'in-production', 'refunded' ],
			[ 'completed', 'refunded' ],
		];
		foreach ( $cases as [ $from, $to ] ) {
			$this->assertTrue(
				WOFC_Transition_Manager::is_transition_allowed( $from, $to ),
				"{$from} → {$to} should be allowed (forward)"
			);
		}
	}

	// -----------------------------------------------------------------
	// Test 4: Backward transitions from locked statuses
	// -----------------------------------------------------------------

	public function test_backward_transitions_blocked() {
		$cases = [
			[ 'processing', 'pending' ],
			[ 'processing', 'on-hold' ],
			[ 'processing', 'cancelled' ],
			[ 'ready-production', 'pending' ],
			[ 'ready-production', 'processing' ],
			[ 'in-production', 'processing' ],
			[ 'in-production', 'ready-production' ],
			[ 'completed', 'pending' ],
			[ 'completed', 'processing' ],
			[ 'completed', 'in-production' ],
			[ 'completed', 'cancelled' ],
		];
		foreach ( $cases as [ $from, $to ] ) {
			$this->assertFalse(
				WOFC_Transition_Manager::is_transition_allowed( $from, $to ),
				"{$from} → {$to} should be blocked (backward)"
			);
		}
	}

	// -----------------------------------------------------------------
	// Test 5: Dead-end status blocks everything
	// -----------------------------------------------------------------

	public function test_dead_end_blocks_all_targets() {
		$targets = [ 'pending', 'processing', 'completed', 'on-hold', 'cancelled' ];
		foreach ( $targets as $to ) {
			$this->assertFalse(
				WOFC_Transition_Manager::is_transition_allowed( 'refunded', $to ),
				"refunded → {$to} should be blocked (dead-end)"
			);
		}
	}

	// -----------------------------------------------------------------
	// Test 6: wc- prefix stripping
	// -----------------------------------------------------------------

	public function test_wc_prefix_stripping() {
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'wc-pending', 'wc-processing' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_transition_allowed( 'wc-processing', 'wc-pending' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'wc-processing', 'wc-completed' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'wc-completed', 'wc-refunded' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_transition_allowed( 'wc-completed', 'wc-processing' ) );
	}

	public function test_mixed_prefix_handling() {
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'wc-processing', 'completed' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_transition_allowed( 'processing', 'wc-pending' ) );
	}

	// -----------------------------------------------------------------
	// Test 7: wofc_allowed_transitions filter modifies rules
	// -----------------------------------------------------------------

	public function test_filter_modifies_rules() {
		Functions\when( 'apply_filters' )->alias( function () {
			$args = func_get_args();
			if ( 'wofc_allowed_transitions' === $args[0] ) {
				$rules = $args[1];
				$rules['completed'][] = 'pending'; // Allow backward
				return $rules;
			}
			return $args[1];
		} );
		WOFC_Transition_Manager::flush_cache();

		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'completed', 'pending' ) );
	}

	// -----------------------------------------------------------------
	// Test 8: wofc_is_transition_allowed filter overrides decision
	// -----------------------------------------------------------------

	public function test_filter_overrides_specific_transition() {
		Functions\when( 'apply_filters' )->alias( function () {
			$args = func_get_args();
			if ( 'wofc_is_transition_allowed' === $args[0] ) {
				return true; // Force allow everything.
			}
			return $args[1];
		} );
		WOFC_Transition_Manager::flush_cache();

		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'completed', 'pending' ) );
	}

	public function test_filter_can_block_normally_allowed_transition() {
		Functions\when( 'apply_filters' )->alias( function () {
			$args = func_get_args();
			if ( 'wofc_is_transition_allowed' === $args[0] ) {
				return false; // Force block everything.
			}
			return $args[1];
		} );
		WOFC_Transition_Manager::flush_cache();

		$this->assertFalse( WOFC_Transition_Manager::is_transition_allowed( 'processing', 'completed' ) );
	}

	// -----------------------------------------------------------------
	// Test 9: flush_cache clears the runtime cache
	// -----------------------------------------------------------------

	public function test_flush_cache_clears_cached_rules() {
		// First call populates cache.
		WOFC_Transition_Manager::get_allowed_transitions();

		// Now change what get_option returns.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( WOFC_Transition_Manager::OPTION_TRANSITIONS === $key ) {
				return [ 'processing' => [ 'completed' ] ]; // Reduced rules.
			}
			if ( WOFC_Transition_Manager::OPTION_ENABLED === $key ) {
				return 'yes';
			}
			return $default;
		} );

		// Without flush, old cache is still used.
		$this->assertArrayHasKey( 'refunded', WOFC_Transition_Manager::get_allowed_transitions() );

		// After flush, new rules are loaded.
		WOFC_Transition_Manager::flush_cache();
		$rules = WOFC_Transition_Manager::get_allowed_transitions();
		$this->assertArrayNotHasKey( 'refunded', $rules );
		$this->assertEquals( [ 'completed' ], $rules['processing'] );
	}

	// -----------------------------------------------------------------
	// Test 10: is_enabled() === false allows everything
	// -----------------------------------------------------------------

	public function test_disabled_allows_everything() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( WOFC_Transition_Manager::OPTION_ENABLED === $key ) {
				return 'no';
			}
			if ( WOFC_Transition_Manager::OPTION_TRANSITIONS === $key ) {
				return self::$default_rules;
			}
			return $default;
		} );
		WOFC_Transition_Manager::flush_cache();

		// Even backward transitions are allowed when disabled.
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'completed', 'pending' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_transition_allowed( 'refunded', 'processing' ) );
	}

	// -----------------------------------------------------------------
	// Test 11: is_locked_status
	// -----------------------------------------------------------------

	public function test_locked_status_detection() {
		$this->assertTrue( WOFC_Transition_Manager::is_locked_status( 'processing' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_locked_status( 'completed' ) );
		$this->assertTrue( WOFC_Transition_Manager::is_locked_status( 'refunded' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_locked_status( 'pending' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_locked_status( 'on-hold' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_locked_status( 'cancelled' ) );
	}

	public function test_locked_status_with_wc_prefix() {
		$this->assertTrue( WOFC_Transition_Manager::is_locked_status( 'wc-processing' ) );
		$this->assertFalse( WOFC_Transition_Manager::is_locked_status( 'wc-pending' ) );
	}

	// -----------------------------------------------------------------
	// Test 12: clean_status
	// -----------------------------------------------------------------

	public function test_clean_status_strips_prefix() {
		$this->assertEquals( 'processing', WOFC_Transition_Manager::clean_status( 'wc-processing' ) );
		$this->assertEquals( 'pending', WOFC_Transition_Manager::clean_status( 'pending' ) );
		$this->assertEquals( 'completed', WOFC_Transition_Manager::clean_status( 'wc-completed' ) );
	}

	// -----------------------------------------------------------------
	// Test 13: get_allowed_targets
	// -----------------------------------------------------------------

	public function test_get_allowed_targets_for_locked_status() {
		$targets = WOFC_Transition_Manager::get_allowed_targets( 'processing' );
		$this->assertEquals( [ 'ready-production', 'in-production', 'completed', 'refunded' ], $targets );
	}

	public function test_get_allowed_targets_for_dead_end() {
		$targets = WOFC_Transition_Manager::get_allowed_targets( 'refunded' );
		$this->assertEquals( [], $targets );
	}

	public function test_get_allowed_targets_for_unlocked_status() {
		$targets = WOFC_Transition_Manager::get_allowed_targets( 'pending' );
		$this->assertEquals( [], $targets );
	}

	// -----------------------------------------------------------------
	// Test 14: Fallback to defaults when option is empty
	// -----------------------------------------------------------------

	public function test_fallback_to_defaults_when_option_empty() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( WOFC_Transition_Manager::OPTION_TRANSITIONS === $key ) {
				return [];
			}
			if ( WOFC_Transition_Manager::OPTION_ENABLED === $key ) {
				return 'yes';
			}
			return $default;
		} );
		WOFC_Transition_Manager::flush_cache();

		$rules = WOFC_Transition_Manager::get_allowed_transitions();
		$this->assertArrayHasKey( 'processing', $rules );
		$this->assertArrayHasKey( 'refunded', $rules );
	}

	// -----------------------------------------------------------------
	// Test 15: Order ID is passed to filter
	// -----------------------------------------------------------------

	public function test_order_id_passed_to_filter() {
		$captured_order_id = null;

		Functions\when( 'apply_filters' )->alias( function () use ( &$captured_order_id ) {
			$args = func_get_args();
			if ( 'wofc_is_transition_allowed' === $args[0] ) {
				$captured_order_id = $args[4]; // Fifth arg = order_id.
				return $args[1];
			}
			return $args[1];
		} );
		WOFC_Transition_Manager::flush_cache();

		WOFC_Transition_Manager::is_transition_allowed( 'processing', 'pending', 42 );
		$this->assertEquals( 42, $captured_order_id );
	}
}
