<?php
/**
 * Deterministic experiment assignment.
 *
 * Network owns allocation only. Consumers register code-owned definitions and
 * decide eligibility; identity/privacy providers contribute through generic
 * filters; measurement consumers listen to trusted server hooks without
 * Network persisting outcomes.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum accepted value for one variant weight.
 *
 * This is deliberately far below the 2^32 hash draw.
 */
const EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT = 500000;

/**
 * Maximum accepted sum of all variant weights.
 *
 * This keeps addition overflow checks explicit and the allocation range safely
 * below the 2^32 hash draw used by rejection sampling.
 */
const EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT = 1000000;

/** Short lifetime for a browser exposure proof. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL = 3600;

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
			|| $weight > EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT
			|| $total_weight > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT - $weight
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
 * Convert a deterministic seed to an unbiased zero-based bucket.
 *
 * SHA-256 supplies successive unsigned 32-bit draws. Values in the incomplete
 * high tail are rejected before modulo reduction, so every bucket receives the
 * same number of possible draws. Rehashing with a counter supplies more draws
 * without a biased fallback.
 *
 * @param string $seed  Deterministic allocation seed.
 * @param int    $total Number of buckets.
 * @return int|null Unbiased bucket, or null for an invalid total.
 */
function extrachill_experiment_unbiased_bucket( $seed, $total ) {
	$total = (int) $total;
	if ( $total <= 0 || $total > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) {
		return null;
	}

	$range = 4294967296;
	$limit = $range - ( $range % $total );
	$round = 0;

	while ( true ) {
		$hash = hash( 'sha256', $seed . "\0" . $round );
		for ( $offset = 0; $offset < 64; $offset += 8 ) {
			$draw = hexdec( substr( $hash, $offset, 8 ) );
			if ( $draw < $limit ) {
				return (int) ( $draw % $total );
			}
		}
		++$round;
	}
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

	$bucket = extrachill_experiment_unbiased_bucket( $experiment_key . "\0" . $subject_key, $total );
	if ( null === $bucket ) {
		return $default;
	}

	return extrachill_experiment_variant_for_bucket( $definition, $bucket );
}

/**
 * Resolve the current request's explicit subject and privacy eligibility.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer context.
 * @return string Eligible subject key, or an empty string.
 */
function extrachill_experiment_subject( $experiment_key, $surface, array $context ) {
	$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$subject_key = $user_id > 0 ? 'wp-user:' . $user_id : '';
	$subject_key = (string) apply_filters(
		'extrachill_experiment_subject_key',
		$subject_key,
		$experiment_key,
		$surface,
		$context
	);
	$subject_key = trim( $subject_key );
	$eligible    = (bool) apply_filters(
		'extrachill_experiment_measurement_eligible',
		false,
		$experiment_key,
		$surface,
		$context
	);

	return '' !== $subject_key && strlen( $subject_key ) <= 256 && $eligible ? $subject_key : '';
}

/**
 * Canonicalize bounded scalar context for exposure signing.
 *
 * @param array<string, mixed> $context Consumer context.
 * @return string Canonical JSON.
 */
function extrachill_experiment_context_json( array $context ) {
	$bounded = array();
	foreach ( $context as $key => $value ) {
		if ( is_string( $key ) && is_scalar( $value ) && ! is_null( $value ) ) {
			$bounded[ $key ] = $value;
		}
	}
	ksort( $bounded, SORT_STRING );

	return (string) wp_json_encode( $bounded );
}

/**
 * Create a short-lived proof binding exposure to a server assignment.
 *
 * @param array<string, string> $metadata    Assignment metadata.
 * @param string                $subject_key Resolved subject key.
 * @param array<string, mixed>  $context     Consumer context.
 * @param int|null              $issued_at   Optional issue time for tests.
 * @return string Signed exposure token.
 */
function extrachill_experiment_exposure_token( array $metadata, $subject_key, array $context, $issued_at = null ) {
	$issued_at = null === $issued_at ? time() : (int) $issued_at;
	$message   = implode(
		"\0",
		array(
			$metadata['experiment_key'],
			$metadata['variant'],
			$metadata['surface'],
			(string) $issued_at,
			extrachill_experiment_context_json( $context ),
			$subject_key,
		)
	);
	$signature = hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );

	return $issued_at . '.' . $signature;
}

/**
 * Validate an exposure proof and return trusted bounded metadata.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $variant        Assigned variant.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer context.
 * @param string               $token          Signed assignment proof.
 * @param int|null             $now            Optional current time for tests.
 * @return array<string, string>|null Trusted metadata, or null when invalid.
 */
function extrachill_validate_experiment_exposure( $experiment_key, $variant, $surface, array $context, $token, $now = null ) {
	if ( 1 !== preg_match( '/^(\d{10})\.([a-f0-9]{64})$/', (string) $token, $matches ) ) {
		return null;
	}

	$now       = null === $now ? time() : (int) $now;
	$issued_at = (int) $matches[1];
	if ( $issued_at > $now + 60 || $issued_at < $now - EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL ) {
		return null;
	}

	$definitions = extrachill_get_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] )
		? extrachill_normalize_experiment_definition( $definitions[ $experiment_key ] )
		: null;
	if (
		null === $definition
		|| ! in_array( $surface, $definition['surfaces'], true )
		|| ! isset( $definition['variants'][ $variant ] )
	) {
		return null;
	}

	try {
		$eligible = (bool) call_user_func( $definition['eligibility_callback'], $context, $surface );
	} catch ( \Throwable $error ) {
		$eligible = false;
	}
	$subject_key = $eligible ? extrachill_experiment_subject( $experiment_key, $surface, $context ) : '';
	if ( '' === $subject_key || extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key ) !== $variant ) {
		return null;
	}

	$metadata = array(
		'experiment_key' => (string) $experiment_key,
		'variant'        => (string) $variant,
		'surface'        => (string) $surface,
	);
	$expected = extrachill_experiment_exposure_token( $metadata, $subject_key, $context, $issued_at );

	return hash_equals( $expected, (string) $token ) ? $metadata : null;
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
			'exposure_token'       => '',
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
		'exposure_token'       => '',
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

	$subject_key = extrachill_experiment_subject( $experiment_key, $surface, $context );
	if ( '' === $subject_key ) {
		return $result;
	}

	$metadata = array(
		'experiment_key' => $experiment_key,
		'variant'        => extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key ),
		'surface'        => $surface,
	);
	$token    = extrachill_experiment_exposure_token( $metadata, $subject_key, $context );
	if ( '' === $token ) {
		return $result;
	}

	$result['variant']              = $metadata['variant'];
	$result['measurement_eligible'] = true;
	$result['exposure_token']       = $token;
	/**
	 * Fires after a privacy-eligible assignment is resolved server-side.
	 *
	 * Analytics may consume this hook for persistence. The payload deliberately
	 * excludes subject identity and consumer context.
	 *
	 * @param array{experiment_key: string, variant: string, surface: string} $metadata Bounded assignment metadata.
	 */
	do_action( 'extrachill_experiment_assignment', $metadata );

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
			'assignmentEndpoint' => rest_url( 'wp-abilities/v1/abilities/extrachill/resolve-experiment-assignment/run' ),
			'exposureEndpoint'   => rest_url( 'wp-abilities/v1/abilities/extrachill/record-experiment-exposure/run' ),
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
