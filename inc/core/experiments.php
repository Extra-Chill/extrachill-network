<?php
/**
 * Deterministic experiment assignment.
 *
 * Network owns allocation only. Consumers register code-owned definitions and
 * decide eligibility; identity/privacy providers contribute through generic
 * filters; measurement consumers listen for browser events without Network
 * persisting them.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return registered experiment definitions.
 *
 * A definition has this shape:
 *
 *     array(
 *         'default_variant'     => 'control',
 *         'control_variant'     => 'control',
 *         'variants'            => array( 'control' => 50, 'treatment' => 50 ),
 *         'surfaces'            => array( 'single-post-bridge' ),
 *         'eligibility_callback' => static function ( array $context, string $surface ): bool {},
 *     )
 *
 * Eligibility callbacks must re-resolve their own context and compose any
 * applicable Users capability/rollout gate. Assignment is never authorization.
 *
 * @return array<string, array<string, mixed>>
 */
function extrachill_get_experiment_definitions() {
	$definitions = apply_filters( 'extrachill_experiment_definitions', array() );

	return is_array( $definitions ) ? $definitions : array();
}

/**
 * Validate and normalize one code-owned experiment definition.
 *
 * @param mixed $definition Candidate definition.
 * @return array<string, mixed>|null Normalized definition, or null when invalid.
 */
function extrachill_normalize_experiment_definition( $definition ) {
	if ( ! is_array( $definition ) ) {
		return null;
	}

	$default  = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : '';
	$control  = isset( $definition['control_variant'] ) ? (string) $definition['control_variant'] : '';
	$variants = isset( $definition['variants'] ) && is_array( $definition['variants'] )
		? $definition['variants']
		: array();
	$surfaces = isset( $definition['surfaces'] ) && is_array( $definition['surfaces'] )
		? array_values( array_unique( $definition['surfaces'] ) )
		: array();

	if (
		'' === $default
		|| $default !== $control
		|| count( $variants ) < 2
		|| ! isset( $variants[ $default ] )
		|| empty( $surfaces )
		|| empty( $definition['eligibility_callback'] )
		|| ! is_callable( $definition['eligibility_callback'] )
	) {
		return null;
	}

	$total_weight = 0;
	foreach ( $variants as $variant => $weight ) {
		if (
			! is_string( $variant )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $variant )
			|| ! is_int( $weight )
			|| $weight <= 0
		) {
			return null;
		}
		$total_weight += $weight;
	}

	foreach ( $surfaces as $surface ) {
		if ( ! is_string( $surface ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $surface ) ) {
			return null;
		}
	}

	if ( $total_weight <= 0 ) {
		return null;
	}

	return array(
		'default_variant'      => $default,
		'control_variant'      => $control,
		'variants'             => $variants,
		'surfaces'             => $surfaces,
		'eligibility_callback' => $definition['eligibility_callback'],
		'total_weight'         => $total_weight,
	);
}

/**
 * Select a weighted variant for a zero-based bucket.
 *
 * @param array<string, mixed> $definition Normalized definition.
 * @param int                  $bucket     Zero-based bucket.
 * @return string Selected variant.
 */
function extrachill_experiment_variant_for_bucket( array $definition, $bucket ) {
	$default = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'control';
	$total   = isset( $definition['total_weight'] ) ? (int) $definition['total_weight'] : 0;

	if ( $total <= 0 || $bucket < 0 || $bucket >= $total || empty( $definition['variants'] ) ) {
		return $default;
	}

	$boundary = 0;
	foreach ( $definition['variants'] as $variant => $weight ) {
		$boundary += (int) $weight;
		if ( $bucket < $boundary ) {
			return (string) $variant;
		}
	}

	return $default;
}

/**
 * Pure deterministic allocation from an explicit subject key.
 *
 * Blog/site identity is intentionally absent from the hash, so a subject keeps
 * the same assignment across the multisite network.
 *
 * @param string               $experiment_key Stable experiment key.
 * @param array<string, mixed> $definition     Normalized definition.
 * @param string               $subject_key    Explicit stable subject key.
 * @return string Selected variant.
 */
function extrachill_allocate_experiment_variant( $experiment_key, array $definition, $subject_key ) {
	$default = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'control';
	$total   = isset( $definition['total_weight'] ) ? (int) $definition['total_weight'] : 0;

	if ( '' === $experiment_key || '' === $subject_key || $total <= 0 ) {
		return $default;
	}

	$hash   = hash( 'sha256', $experiment_key . "\0" . $subject_key );
	$bucket = (int) ( hexdec( substr( $hash, 0, 8 ) ) % $total );

	return extrachill_experiment_variant_for_bucket( $definition, $bucket );
}

/**
 * Resolve an assignment while preserving consumer and privacy boundaries.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Bounded consumer surface.
 * @param array<string, mixed> $context        Consumer-owned eligibility context.
 * @return array<string, mixed> Neutral assignment metadata.
 */
function extrachill_resolve_experiment_assignment( $experiment_key, $surface, array $context = array() ) {
	$experiment_key = (string) $experiment_key;
	$surface        = (string) $surface;
	if (
		1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $experiment_key )
		|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $surface )
	) {
		return array(
			'experiment_key'       => $experiment_key,
			'variant'              => 'control',
			'surface'              => $surface,
			'measurement_eligible' => false,
		);
	}

	$definitions    = extrachill_get_experiment_definitions();
	$raw_definition = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	$fallback       = is_array( $raw_definition )
		&& isset( $raw_definition['default_variant'], $raw_definition['control_variant'] )
		&& $raw_definition['default_variant'] === $raw_definition['control_variant']
		&& is_string( $raw_definition['default_variant'] )
		&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $raw_definition['default_variant'] )
			? $raw_definition['default_variant']
			: 'control';
	$result         = array(
		'experiment_key'       => $experiment_key,
		'variant'              => $fallback,
		'surface'              => $surface,
		'measurement_eligible' => false,
	);
	$definition     = extrachill_normalize_experiment_definition( $raw_definition );

	if ( null === $definition || ! in_array( $surface, $definition['surfaces'], true ) ) {
		return $result;
	}

	$result['variant'] = $definition['default_variant'];
	try {
		$eligible = (bool) call_user_func( $definition['eligibility_callback'], $context, $surface );
	} catch ( \Throwable $error ) {
		$eligible = false;
	}

	if ( ! $eligible ) {
		return $result;
	}

	$user_id          = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$subject_key      = $user_id > 0 ? 'wp-user:' . $user_id : '';
	$subject_key      = (string) apply_filters(
		'extrachill_experiment_subject_key',
		$subject_key,
		$experiment_key,
		$surface,
		$context
	);
	$subject_key      = trim( $subject_key );
	$privacy_eligible = (bool) apply_filters(
		'extrachill_experiment_measurement_eligible',
		false,
		$experiment_key,
		$surface,
		$context
	);

	if ( '' === $subject_key || strlen( $subject_key ) > 256 || ! $privacy_eligible ) {
		return $result;
	}

	$result['variant']              = extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key );
	$result['measurement_eligible'] = true;

	return $result;
}

/**
 * Register the cache-neutral browser assignment asset.
 */
function extrachill_register_experiment_assignment_script() {
	$path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/js/experiment-assignment.js';
	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_register_script(
		'extrachill-experiment-assignment',
		EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/js/experiment-assignment.js',
		array(),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
	wp_localize_script(
		'extrachill-experiment-assignment',
		'ecExperimentAssignment',
		array(
			'endpoint' => rest_url( 'wp-abilities/v1/abilities/extrachill/resolve-experiment-assignment/run' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_experiment_assignment_script', 5 );

/**
 * Build cache-neutral data attributes for an experiment surface.
 *
 * The output deliberately contains only code/config values and the declared
 * control. It never embeds a subject or request-specific assignment.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer eligibility context.
 * @return string Escaped HTML attributes, or an empty string for invalid config.
 */
function extrachill_experiment_attributes( $experiment_key, $surface, array $context = array() ) {
	if (
		1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) $experiment_key )
		|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) $surface )
	) {
		return '';
	}

	$definitions = extrachill_get_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] )
		? extrachill_normalize_experiment_definition( $definitions[ $experiment_key ] )
		: null;

	if ( null === $definition || ! in_array( $surface, $definition['surfaces'], true ) ) {
		return '';
	}

	wp_enqueue_script( 'extrachill-experiment-assignment' );

	return sprintf(
		'data-ec-experiment-key="%s" data-ec-experiment-surface="%s" data-ec-experiment-variant="%s" data-ec-experiment-context="%s"',
		esc_attr( $experiment_key ),
		esc_attr( $surface ),
		esc_attr( $definition['default_variant'] ),
		esc_attr( wp_json_encode( $context ) )
	);
}
