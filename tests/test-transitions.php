<?php
/**
 * Standalone test for Order Flow Control transition logic.
 * Run: php tests/test-transitions.php
 */

echo "=== WooCommerce Order Flow Control — Transition Tests ===\n\n";

$passed = 0;
$failed = 0;

function get_allowed_transitions() {
	return [
		'processing'       => [ 'ready-production', 'in-production', 'completed', 'refunded' ],
		'ready-production' => [ 'in-production', 'completed', 'refunded' ],
		'in-production'    => [ 'completed', 'refunded' ],
		'completed'        => [ 'refunded' ],
		'refunded'         => [],
	];
}

function is_locked_status( $status ) {
	$status = str_replace( 'wc-', '', $status );
	return array_key_exists( $status, get_allowed_transitions() );
}

function is_transition_allowed( $from, $to ) {
	$from = str_replace( 'wc-', '', $from );
	$to   = str_replace( 'wc-', '', $to );

	if ( $from === $to ) return true;
	if ( ! is_locked_status( $from ) ) return true;

	$allowed = get_allowed_transitions();
	return in_array( $to, $allowed[ $from ] ?? [], true );
}

function assert_allowed( $from, $to, &$passed, &$failed ) {
	if ( is_transition_allowed( $from, $to ) ) {
		echo "  ✓ {$from} → {$to} (allowed)\n";
		$passed++;
	} else {
		echo "  ✗ FAIL: {$from} → {$to} should be ALLOWED but was BLOCKED\n";
		$failed++;
	}
}

function assert_blocked( $from, $to, &$passed, &$failed ) {
	if ( ! is_transition_allowed( $from, $to ) ) {
		echo "  ✓ {$from} → {$to} (blocked)\n";
		$passed++;
	} else {
		echo "  ✗ FAIL: {$from} → {$to} should be BLOCKED but was ALLOWED\n";
		$failed++;
	}
}

// --- Test 1: Unlocked statuses (pending, on-hold) can go anywhere ---
echo "Test 1: Unlocked statuses have no restrictions\n";
$all_statuses = ['pending', 'on-hold', 'processing', 'ready-production', 'in-production', 'completed', 'refunded', 'cancelled', 'failed'];

foreach ( ['pending', 'on-hold'] as $from ) {
	foreach ( $all_statuses as $to ) {
		if ( $from === $to ) continue;
		assert_allowed( $from, $to, $passed, $failed );
	}
}
echo "\n";

// --- Test 2: Same-status transitions always allowed ---
echo "Test 2: Same-status transitions always allowed\n";
foreach ( $all_statuses as $status ) {
	assert_allowed( $status, $status, $passed, $failed );
}
echo "\n";

// --- Test 3: Forward transitions from locked statuses ---
echo "Test 3: Forward transitions (should be ALLOWED)\n";
assert_allowed( 'processing', 'ready-production', $passed, $failed );
assert_allowed( 'processing', 'in-production', $passed, $failed );
assert_allowed( 'processing', 'completed', $passed, $failed );
assert_allowed( 'processing', 'refunded', $passed, $failed );
assert_allowed( 'ready-production', 'in-production', $passed, $failed );
assert_allowed( 'ready-production', 'completed', $passed, $failed );
assert_allowed( 'ready-production', 'refunded', $passed, $failed );
assert_allowed( 'in-production', 'completed', $passed, $failed );
assert_allowed( 'in-production', 'refunded', $passed, $failed );
assert_allowed( 'completed', 'refunded', $passed, $failed );
echo "\n";

// --- Test 4: Backward transitions from locked statuses ---
echo "Test 4: Backward transitions (should be BLOCKED)\n";
assert_blocked( 'processing', 'pending', $passed, $failed );
assert_blocked( 'processing', 'on-hold', $passed, $failed );
assert_blocked( 'processing', 'cancelled', $passed, $failed );
assert_blocked( 'ready-production', 'pending', $passed, $failed );
assert_blocked( 'ready-production', 'on-hold', $passed, $failed );
assert_blocked( 'ready-production', 'processing', $passed, $failed );
assert_blocked( 'in-production', 'pending', $passed, $failed );
assert_blocked( 'in-production', 'on-hold', $passed, $failed );
assert_blocked( 'in-production', 'processing', $passed, $failed );
assert_blocked( 'in-production', 'ready-production', $passed, $failed );
assert_blocked( 'completed', 'pending', $passed, $failed );
assert_blocked( 'completed', 'on-hold', $passed, $failed );
assert_blocked( 'completed', 'processing', $passed, $failed );
assert_blocked( 'completed', 'ready-production', $passed, $failed );
assert_blocked( 'completed', 'in-production', $passed, $failed );
assert_blocked( 'completed', 'cancelled', $passed, $failed );
echo "\n";

// --- Test 5: Refunded is a dead end ---
echo "Test 5: Refunded is a dead end (nothing allowed)\n";
foreach ( $all_statuses as $to ) {
	if ( $to === 'refunded' ) continue;
	assert_blocked( 'refunded', $to, $passed, $failed );
}
echo "\n";

// --- Test 6: wc- prefix handling ---
echo "Test 6: wc- prefix stripping works correctly\n";
assert_allowed( 'wc-pending', 'wc-processing', $passed, $failed );
assert_blocked( 'wc-processing', 'wc-pending', $passed, $failed );
assert_allowed( 'wc-processing', 'wc-completed', $passed, $failed );
assert_allowed( 'wc-completed', 'wc-refunded', $passed, $failed );
assert_blocked( 'wc-completed', 'wc-processing', $passed, $failed );
echo "\n";

// --- Test 7: Cancelled not reachable from locked statuses ---
echo "Test 7: Cancelled blocked from all locked statuses\n";
foreach ( ['processing', 'ready-production', 'in-production', 'completed', 'refunded'] as $from ) {
	assert_blocked( $from, 'cancelled', $passed, $failed );
}
echo "\n";

// --- Test 8: Skip-transitions (jumping ahead) ---
echo "Test 8: Skip-transitions allowed (e.g. processing → completed)\n";
assert_allowed( 'processing', 'completed', $passed, $failed );
assert_allowed( 'processing', 'in-production', $passed, $failed );
assert_allowed( 'ready-production', 'completed', $passed, $failed );
echo "\n";

// --- Test 9: Save logic — Rollback Lock preserves dead ends ---
echo "Test 9: Save logic — Rollback Lock preserves dead-end statuses\n";

function simulate_save( $lock_flags, $matrix ) {
	$valid_statuses = ['pending', 'on-hold', 'processing', 'ready-production', 'in-production', 'completed', 'refunded', 'cancelled', 'failed'];
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
	return $rules;
}

// 9a: Locked with no targets = dead end (preserved)
$rules = simulate_save(
	[ 'refunded' => '1', 'completed' => '1' ],
	[ 'completed' => [ 'refunded' => '1' ] ]
);
if ( array_key_exists( 'refunded', $rules ) && $rules['refunded'] === [] ) {
	echo "  ✓ Rollback Lock + no targets = dead end preserved\n";
	$passed++;
} else {
	echo "  ✗ FAIL: refunded should be dead end but was removed or has targets\n";
	$failed++;
}

// 9b: No Rollback Lock = not in rules (unrestricted)
$rules = simulate_save(
	[ 'completed' => '1' ],
	[ 'completed' => [ 'refunded' => '1' ] ]
);
if ( ! array_key_exists( 'refunded', $rules ) ) {
	echo "  ✓ No Rollback Lock = not in rules (unrestricted)\n";
	$passed++;
} else {
	echo "  ✗ FAIL: refunded without Rollback Lock should not be in rules\n";
	$failed++;
}

// 9c: Locked with targets = normal locked status
$rules = simulate_save(
	[ 'processing' => '1' ],
	[ 'processing' => [ 'completed' => '1', 'refunded' => '1' ] ]
);
if ( array_key_exists( 'processing', $rules ) && $rules['processing'] === [ 'completed', 'refunded' ] ) {
	echo "  ✓ Rollback Lock + targets = locked with allowed transitions\n";
	$passed++;
} else {
	echo "  ✗ FAIL: processing should be locked with [completed, refunded]\n";
	$failed++;
}

// 9d: Self-transitions are filtered out
$rules = simulate_save(
	[ 'processing' => '1' ],
	[ 'processing' => [ 'processing' => '1', 'completed' => '1' ] ]
);
if ( $rules['processing'] === [ 'completed' ] ) {
	echo "  ✓ Self-transition filtered out\n";
	$passed++;
} else {
	echo "  ✗ FAIL: self-transition should be filtered\n";
	$failed++;
}
echo "\n";

// --- Summary ---
$total = $passed + $failed;
echo "=== Results: {$passed}/{$total} passed";
if ( $failed > 0 ) {
	echo ", {$failed} FAILED ===\n";
	exit(1);
} else {
	echo " — ALL PASSED ===\n";
	exit(0);
}
