<?php
/**
 * Standalone tests for deterministic experiment assignment.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// Standalone WordPress stubs intentionally mirror global APIs without full
// production docblocks or output escaping.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_URL', 'https://example.com/wp-content/plugins/extrachill-network/' );

$GLOBALS['experiment_filters']   = array();
$GLOBALS['experiment_user_id']   = 0;
$GLOBALS['experiment_blog_id']   = 1;
$GLOBALS['experiment_enqueued']  = array();
$GLOBALS['experiment_abilities'] = array();

function add_action() {}
function wp_register_script() {}
function wp_localize_script() {}
function __( $value ) {
	return $value;
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['experiment_abilities'][ $name ] = $args;
}
function rest_url( $path ) {
	return 'https://example.com/wp-json/' . $path;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['experiment_enqueued'][] = $handle;
}
function get_current_user_id() {
	return $GLOBALS['experiment_user_id'];
}
function get_current_blog_id() {
	return $GLOBALS['experiment_blog_id'];
}
function apply_filters( $name, $value, ...$args ) {
	if ( empty( $GLOBALS['experiment_filters'][ $name ] ) ) {
		return $value;
	}

	foreach ( $GLOBALS['experiment_filters'][ $name ] as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

require dirname( __DIR__ ) . '/inc/core/experiments.php';
require dirname( __DIR__ ) . '/inc/Abilities/ExperimentAssignmentAbility.php';

$failures = 0;
function experiment_check( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

function experiment_definition( bool $eligible = true ): array {
	return array(
		'default_variant'      => 'control',
		'control_variant'      => 'control',
		'variants'             => array(
			'control'   => 2,
			'treatment' => 3,
		),
		'surfaces'             => array( 'single-post-bridge' ),
		'eligibility_callback' => static function () use ( $eligible ): bool {
			return $eligible;
		},
	);
}

$normalized = extrachill_normalize_experiment_definition( experiment_definition() );
experiment_check( 'valid definitions normalize', is_array( $normalized ) );
experiment_check( 'first control bucket is control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 0 ) );
experiment_check( 'last control bucket is control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 1 ) );
experiment_check( 'first treatment bucket is treatment', 'treatment' === extrachill_experiment_variant_for_bucket( $normalized, 2 ) );
experiment_check( 'last treatment bucket is treatment', 'treatment' === extrachill_experiment_variant_for_bucket( $normalized, 4 ) );
experiment_check( 'out-of-range buckets fail to control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 5 ) );

$invalid                          = experiment_definition();
$invalid['variants']['treatment'] = 0;
experiment_check( 'zero weights invalidate configuration', null === extrachill_normalize_experiment_definition( $invalid ) );
$invalid                          = experiment_definition();
$invalid['variants']['treatment'] = '3';
experiment_check( 'non-integer weights invalidate configuration', null === extrachill_normalize_experiment_definition( $invalid ) );
$invalid                    = experiment_definition();
$invalid['control_variant'] = 'treatment';
experiment_check( 'default must explicitly equal control', null === extrachill_normalize_experiment_definition( $invalid ) );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition() );
	},
);

$missing_subject = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'missing subject fails to control', 'control' === $missing_subject['variant'] );
experiment_check( 'missing subject remains unmeasured', false === $missing_subject['measurement_eligible'] );

$GLOBALS['experiment_filters']['extrachill_experiment_subject_key'] = array(
	static function (): string {
		return 'visitor-123';
	},
);
$privacy_excluded = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'privacy exclusion fails to control', 'control' === $privacy_excluded['variant'] );
experiment_check( 'privacy exclusion remains unmeasured', false === $privacy_excluded['measurement_eligible'] );

$GLOBALS['experiment_filters']['extrachill_experiment_measurement_eligible'] = array(
	static function (): bool {
		return true;
	},
);
$assigned = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'provider-approved subject receives an assignment', true === $assigned['measurement_eligible'] );
experiment_check( 'assignment stays within registered variants', in_array( $assigned['variant'], array( 'control', 'treatment' ), true ) );

$first_blog_variant            = $assigned['variant'];
$GLOBALS['experiment_blog_id'] = 9;
$second_blog_assignment        = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'assignment is stable across multisite blogs', $first_blog_variant === $second_blog_assignment['variant'] );
experiment_check(
	'explicit subject allocation is deterministic',
	extrachill_allocate_experiment_variant( 'geo-bridge-holdout', $normalized, 'visitor-123' )
		=== extrachill_allocate_experiment_variant( 'geo-bridge-holdout', $normalized, 'visitor-123' )
);

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition( false ) );
	},
);
$denied = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'consumer denial cannot be escalated by assignment', 'control' === $denied['variant'] );
experiment_check( 'consumer denial remains unmeasured', false === $denied['measurement_eligible'] );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition() );
	},
);
$markup = extrachill_experiment_attributes(
	'geo-bridge-holdout',
	'single-post-bridge',
	array( 'post_id' => 42 )
);
experiment_check( 'markup contains the stable experiment key', false !== strpos( $markup, 'data-ec-experiment-key="geo-bridge-holdout"' ) );
experiment_check( 'markup contains only the declared control', false !== strpos( $markup, 'data-ec-experiment-variant="control"' ) );
experiment_check( 'markup contains no subject identity', false === strpos( $markup, 'visitor-123' ) );
experiment_check( 'markup enqueues the browser assignment path', in_array( 'extrachill-experiment-assignment', $GLOBALS['experiment_enqueued'], true ) );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function () use ( $invalid ): array {
		return array( 'broken' => $invalid );
	},
);
$broken = extrachill_resolve_experiment_assignment( 'broken', 'single-post-bridge' );
experiment_check( 'invalid configuration falls back to control', 'control' === $broken['variant'] );
experiment_check( 'invalid configuration remains unmeasured', false === $broken['measurement_eligible'] );

$ability = new \ExtraChillNetwork\Abilities\ExperimentAssignmentAbility();
$ability->register();
$ability_args = $GLOBALS['experiment_abilities']['extrachill/resolve-experiment-assignment'];
experiment_check( 'assignment uses the public core Abilities API', true === $ability_args['meta']['show_in_rest'] );
experiment_check( 'assignment ability is read-only', true === $ability_args['meta']['annotations']['readonly'] );
experiment_check( 'ability context is bounded to ten scalar properties', 10 === $ability_args['input_schema']['properties']['context']['maxProperties'] );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All experiment assignment tests passed.\n";
exit( 0 );
