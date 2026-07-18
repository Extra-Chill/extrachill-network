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
 * This is deliberately below the signed-32-bit-safe 2^28 draw domain.
 */
const EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT = 500000;

/**
 * Maximum accepted sum of all variant weights.
 *
 * This keeps addition overflow checks explicit and the allocation range safely
 * below the 2^28 hash draw used by rejection sampling.
 */
const EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT = 1000000;

/** 2^28 allocation domain; its maximum value fits signed 32-bit PHP. */
const EXTRACHILL_EXPERIMENT_DRAW_RANGE = 268435456;

/** Short lifetime for a browser exposure proof. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL = 3600;

/** Accepted clock skew for tokens issued slightly in the future. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW = 60;

/** Shared atomic-cache group for consumed exposure proofs. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP = 'extrachill_experiment_exposures';

/** Network option containing live state for registered experiment versions. */
const EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION = 'extrachill_experiment_lifecycle';

/** The only supported allocation policy. */
const EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY = 'weighted_random';

/** Bounded lifecycle states. */
const EXTRACHILL_EXPERIMENT_STATES = array( 'inactive', 'active', 'paused', 'completed' );

/**
 * Make exposure proof consumption network-global on multisite caches.
 */
function extrachill_register_experiment_cache_group() {
	if ( function_exists( 'wp_cache_add_global_groups' ) ) {
		wp_cache_add_global_groups( array( EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP ) );
	}
}
add_action( 'init', 'extrachill_register_experiment_cache_group', 0 );

/**
 * Return registered experiment definitions.
 *
 * A definition has this shape:
 *
 *     'stable-key' => array(
 *         'key'                 => 'stable-key',
 *         'definition_version'  => 1,
 *         'assignment_policy'   => 'weighted_random',
 *         'default_state'       => 'inactive',
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
 * @param mixed  $definition     Candidate definition.
 * @param string $registered_key Optional filter registration key.
 * @return array<string, mixed>|null Normalized definition, or null when invalid.
 */
function extrachill_normalize_experiment_definition( $definition, $registered_key = '' ) {
	if ( ! is_array( $definition ) ) {
		return null;
	}

	$registered_key = (string) $registered_key;
	$is_legacy      = ! isset( $definition['key'] )
		&& ! isset( $definition['definition_version'] )
		&& ! isset( $definition['assignment_policy'] )
		&& ! isset( $definition['default_state'] );
	$key            = isset( $definition['key'] ) ? (string) $definition['key'] : $registered_key;
	$version        = isset( $definition['definition_version'] ) ? $definition['definition_version'] : ( $is_legacy ? 1 : 0 );
	$policy         = isset( $definition['assignment_policy'] ) ? (string) $definition['assignment_policy'] : ( $is_legacy ? EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY : '' );
	$default_state  = isset( $definition['default_state'] ) ? (string) $definition['default_state'] : ( $is_legacy ? 'active' : '' );
	if (
		! $is_legacy
		&& ( ! isset( $definition['key'] )
			|| ! isset( $definition['definition_version'] )
			|| ! isset( $definition['assignment_policy'] )
			|| ! isset( $definition['default_state'] ) )
	) {
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
		( '' !== $registered_key && $key !== $registered_key )
		|| ( '' !== $key && 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key ) )
		|| ! is_int( $version )
		|| $version <= 0
		|| EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY !== $policy
		|| ! in_array( $default_state, EXTRACHILL_EXPERIMENT_STATES, true )
		|| '' === $default
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
		'key'                  => $key,
		'definition_version'   => $version,
		'assignment_policy'    => $policy,
		'default_state'        => $default_state,
		'default_variant'      => $default,
		'control_variant'      => $control,
		'variants'             => $variants,
		'surfaces'             => $surfaces,
		'eligibility_callback' => $definition['eligibility_callback'],
		'total_weight'         => $total_weight,
	);
}

/**
 * Return valid code-owned definitions indexed by their declared stable key.
 *
 * The original keyed definition shape is migrated as version 1, weighted
 * random, and active so the already-shipped assignment contract is preserved.
 * New definitions must explicitly provide all lifecycle fields.
 *
 * @return array<string, array<string, mixed>>
 */
function extrachill_get_normalized_experiment_definitions() {
	$normalized = array();
	foreach ( extrachill_get_experiment_definitions() as $key => $definition ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key ) ) {
			continue;
		}
		$candidate = extrachill_normalize_experiment_definition( $definition, $key );
		if ( null !== $candidate ) {
			$normalized[ $key ] = $candidate;
		}
	}

	return $normalized;
}

/**
 * Read and validate the bounded live-state option.
 *
 * @return array{valid: bool, states: array<string, array{definition_version: int, state: string}>}
 */
function extrachill_get_experiment_lifecycle_option() {
	$value = get_site_option( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION, false );
	if ( false === $value ) {
		return array(
			'valid'  => true,
			'states' => array(),
		);
	}
	if ( ! is_array( $value ) ) {
		return array(
			'valid'  => false,
			'states' => array(),
		);
	}

	$states          = array();
	$registered_keys = array_keys( extrachill_get_normalized_experiment_definitions() );
	foreach ( $value as $key => $record ) {
		if (
			! is_string( $key )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key )
			|| ! in_array( $key, $registered_keys, true )
			|| ! is_array( $record )
			|| array( 'definition_version', 'state' ) !== array_keys( $record )
			|| ! is_int( $record['definition_version'] )
			|| $record['definition_version'] <= 0
			|| ! is_string( $record['state'] )
			|| ! in_array( $record['state'], EXTRACHILL_EXPERIMENT_STATES, true )
		) {
			return array(
				'valid'  => false,
				'states' => array(),
			);
		}
		$states[ $key ] = $record;
	}

	return array(
		'valid'  => true,
		'states' => $states,
	);
}

/**
 * Resolve live state for the current code definition version.
 *
 * Missing and older-version state uses the reviewed code default. Corrupt or
 * future-version state fails closed and never broadens eligibility.
 *
 * @param array<string, mixed>      $definition Normalized definition.
 * @param array<string, mixed>|null $lifecycle Optional preloaded option data.
 * @return string Effective lifecycle state.
 */
function extrachill_get_experiment_state( array $definition, $lifecycle = null ) {
	$lifecycle = is_array( $lifecycle ) ? $lifecycle : extrachill_get_experiment_lifecycle_option();
	if ( empty( $lifecycle['valid'] ) ) {
		return 'inactive';
	}

	$key    = $definition['key'];
	$record = isset( $lifecycle['states'][ $key ] ) ? $lifecycle['states'][ $key ] : null;
	if ( null === $record || $record['definition_version'] < $definition['definition_version'] ) {
		return $definition['default_state'];
	}
	if ( $record['definition_version'] !== $definition['definition_version'] ) {
		return 'inactive';
	}

	return $record['state'];
}

/**
 * Whether a registered experiment is active for a consumer surface/context.
 *
 * Consumers may call this before rendering candidates or enqueueing assets.
 * It does not replace consumer authorization; the eligibility callback must
 * compose every applicable capability or rollout check itself.
 *
 * @param string               $experiment_key Stable experiment key.
 * @param string               $surface Consumer surface, or empty for lifecycle-only checks.
 * @param array<string, mixed> $context Consumer-owned eligibility context.
 * @return bool Whether the current definition is active and consumer-eligible.
 */
function extrachill_experiment_is_active( $experiment_key, $surface = '', array $context = array() ) {
	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if ( null === $definition || 'active' !== extrachill_get_experiment_state( $definition ) ) {
		return false;
	}
	if ( '' === $surface ) {
		return true;
	}
	if ( ! in_array( $surface, $definition['surfaces'], true ) ) {
		return false;
	}

	try {
		return (bool) call_user_func( $definition['eligibility_callback'], $context, $surface );
	} catch ( \Throwable $error ) {
		return false;
	}
}

/**
 * Return audit-safe definitions and effective live states.
 *
 * @return array<int, array<string, mixed>>
 */
function extrachill_list_experiments() {
	$lifecycle = extrachill_get_experiment_lifecycle_option();
	$output    = array();
	foreach ( extrachill_get_normalized_experiment_definitions() as $definition ) {
		$output[] = array(
			'key'                => $definition['key'],
			'definition_version' => $definition['definition_version'],
			'assignment_policy'  => $definition['assignment_policy'],
			'default_state'      => $definition['default_state'],
			'state'              => extrachill_get_experiment_state( $definition, $lifecycle ),
			'default_variant'    => $definition['default_variant'],
			'control_variant'    => $definition['control_variant'],
			'variants'           => $definition['variants'],
			'surfaces'           => $definition['surfaces'],
		);
	}

	return $output;
}

/**
 * Transition one registered experiment's current code version.
 *
 * @param string $experiment_key Stable experiment key.
 * @param int    $definition_version Expected current code version.
 * @param string $new_state Requested state.
 * @return array<string, mixed>|\WP_Error Normalized changed state.
 */
function extrachill_transition_experiment_state( $experiment_key, $definition_version, $new_state ) {
	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if ( null === $definition ) {
		return new \WP_Error( 'experiment_not_registered', __( 'Experiment is not registered.', 'extrachill-network' ), array( 'status' => 404 ) );
	}
	if ( $definition['definition_version'] !== $definition_version ) {
		return new \WP_Error( 'experiment_definition_version_mismatch', __( 'Experiment definition version does not match current code.', 'extrachill-network' ), array( 'status' => 409 ) );
	}
	if ( ! in_array( $new_state, EXTRACHILL_EXPERIMENT_STATES, true ) ) {
		return new \WP_Error( 'invalid_experiment_state', __( 'Experiment lifecycle state is invalid.', 'extrachill-network' ), array( 'status' => 400 ) );
	}

	$lifecycle = extrachill_get_experiment_lifecycle_option();
	if ( empty( $lifecycle['valid'] ) ) {
		return new \WP_Error( 'invalid_experiment_lifecycle_option', __( 'Experiment lifecycle storage is corrupt.', 'extrachill-network' ), array( 'status' => 500 ) );
	}
	$old_state = extrachill_get_experiment_state( $definition, $lifecycle );
	$allowed   = array(
		'inactive'  => array( 'active' ),
		'active'    => array( 'paused', 'completed' ),
		'paused'    => array( 'active', 'completed' ),
		'completed' => array(),
	);
	if ( $old_state !== $new_state && ! in_array( $new_state, $allowed[ $old_state ], true ) ) {
		return new \WP_Error( 'invalid_experiment_state_transition', __( 'Experiment lifecycle transition is invalid.', 'extrachill-network' ), array( 'status' => 409 ) );
	}

	$result = array(
		'experiment_key'     => $definition['key'],
		'definition_version' => $definition['definition_version'],
		'previous_state'     => $old_state,
		'state'              => $new_state,
	);
	if ( $old_state === $new_state ) {
		return $result;
	}

	$lifecycle['states'][ $definition['key'] ] = array(
		'definition_version' => $definition['definition_version'],
		'state'              => $new_state,
	);
	if ( ! update_site_option( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION, $lifecycle['states'] ) ) {
		return new \WP_Error( 'experiment_state_update_failed', __( 'Experiment lifecycle state could not be saved.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	do_action( 'extrachill_experiment_state_changed', $result );
	return $result;
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
 * Reduce one bounded draw to a bucket without modulo bias.
 *
 * @param int $draw  Unsigned 28-bit draw.
 * @param int $total Number of buckets.
 * @return int|null Bucket, or null when the draw/total is invalid or rejected.
 */
function extrachill_experiment_reduce_draw( $draw, $total ) {
	$draw  = (int) $draw;
	$total = (int) $total;
	if (
		$draw < 0
		|| $draw >= EXTRACHILL_EXPERIMENT_DRAW_RANGE
		|| $total <= 0
		|| $total > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT
	) {
		return null;
	}

	$limit = EXTRACHILL_EXPERIMENT_DRAW_RANGE - ( EXTRACHILL_EXPERIMENT_DRAW_RANGE % $total );
	if ( $draw >= $limit ) {
		return null;
	}

	return $draw % $total;
}

/**
 * Convert a deterministic seed to an unbiased zero-based bucket.
 *
 * SHA-256 supplies successive unsigned 28-bit draws, each no larger than
 * 268,435,455 and therefore safe when PHP_INT_SIZE is 4. Values in the
 * incomplete high tail are rejected before modulo reduction. Rehashing with a
 * counter supplies more draws without a biased fallback.
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

	$round = 0;

	while ( true ) {
		$hash = hash( 'sha256', $seed . "\0" . $round );
		for ( $offset = 0; $offset <= 56; $offset += 7 ) {
			$bucket = extrachill_experiment_reduce_draw( hexdec( substr( $hash, $offset, 7 ) ), $total );
			if ( null !== $bucket ) {
				return $bucket;
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
 * @param string|null           $nonce       Optional 128-bit hex nonce for tests.
 * @return string Signed exposure token.
 */
function extrachill_experiment_exposure_token( array $metadata, $subject_key, array $context, $issued_at = null, $nonce = null ) {
	$issued_at = null === $issued_at ? time() : (int) $issued_at;
	if ( null === $nonce ) {
		try {
			$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return '';
		}
	} else {
		$nonce = (string) $nonce;
	}
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
		return '';
	}
	$message   = implode(
		"\0",
		array(
			$metadata['experiment_key'],
			(string) $metadata['definition_version'],
			$metadata['assignment_policy'],
			$metadata['variant'],
			$metadata['surface'],
			(string) $issued_at,
			$nonce,
			extrachill_experiment_context_json( $context ),
			$subject_key,
		)
	);
	$signature = hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );

	return $issued_at . '.' . $nonce . '.' . $signature;
}

/**
 * Validate an exposure proof and return trusted bounded metadata.
 *
 * @param string               $experiment_key Experiment key.
 * @param int                  $definition_version Code definition version.
 * @param string               $assignment_policy Allocation policy.
 * @param string               $variant        Assigned variant.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer context.
 * @param string               $token          Signed assignment proof.
 * @param int|null             $now            Optional current time for tests.
 * @return array<string, mixed>|null Trusted metadata, or null when invalid.
 */
function extrachill_validate_experiment_exposure( $experiment_key, $definition_version, $assignment_policy, $variant, $surface, array $context, $token, $now = null ) {
	if ( 1 !== preg_match( '/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', (string) $token, $matches ) ) {
		return null;
	}

	$now       = null === $now ? time() : (int) $now;
	$issued_at = (int) $matches[1];
	if ( $issued_at > $now + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW || $issued_at < $now - EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL ) {
		return null;
	}

	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if (
		null === $definition
		|| 'active' !== extrachill_get_experiment_state( $definition )
		|| $definition['definition_version'] !== $definition_version
		|| $definition['assignment_policy'] !== $assignment_policy
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
		'experiment_key'     => (string) $experiment_key,
		'definition_version' => $definition['definition_version'],
		'assignment_policy'  => $definition['assignment_policy'],
		'variant'            => (string) $variant,
		'surface'            => (string) $surface,
	);
	$expected = extrachill_experiment_exposure_token( $metadata, $subject_key, $context, $issued_at, $matches[2] );

	return hash_equals( $expected, (string) $token ) ? $metadata : null;
}

/**
 * Atomically claim one validated exposure proof before emitting its hook.
 *
 * The persistent object-cache `add` operation is the compare-and-set boundary:
 * exactly one concurrent request can create a digest key. Installations without
 * a shared external object cache fail closed rather than claiming replay safety
 * that the request-local core cache cannot provide.
 *
 * @param string   $token Validated exposure token.
 * @param int|null $now   Optional current time for tests.
 * @return bool True only for the first consumer of this token.
 */
function extrachill_consume_experiment_exposure_token( $token, $now = null ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && ! wp_using_ext_object_cache() ) {
		return false;
	}
	if ( 1 !== preg_match( '/^(\d{10})\.[a-f0-9]{32}\.[a-f0-9]{64}$/', (string) $token, $matches ) ) {
		return false;
	}

	$now       = null === $now ? time() : (int) $now;
	$issued_at = (int) $matches[1];
	$ttl       = $issued_at + EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL - $now;
	if (
		$issued_at > $now + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW
		|| $ttl <= 0
		|| $ttl > EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW
	) {
		return false;
	}

	$digest = hash( 'sha256', (string) $token );

	return wp_cache_add(
		'exposure_' . $digest,
		1,
		EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP,
		$ttl
	);
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
			'definition_version'   => 0,
			'assignment_policy'    => '',
			'variant'              => '',
			'surface'              => $surface,
			'measurement_eligible' => false,
			'exposure_token'       => '',
		);
	}

	$definitions = extrachill_get_normalized_experiment_definitions();
	$result      = array(
		'experiment_key'       => $experiment_key,
		'definition_version'   => 0,
		'assignment_policy'    => '',
		'variant'              => '',
		'surface'              => $surface,
		'measurement_eligible' => false,
		'exposure_token'       => '',
	);
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;

	if ( null === $definition ) {
		return $result;
	}

	$result['definition_version'] = $definition['definition_version'];
	$result['assignment_policy']  = $definition['assignment_policy'];
	if ( 'active' !== extrachill_get_experiment_state( $definition ) ) {
		return $result;
	}

	$result['variant'] = $definition['default_variant'];
	if ( ! extrachill_experiment_is_active( $experiment_key, $surface, $context ) ) {
		return $result;
	}

	$subject_key = extrachill_experiment_subject( $experiment_key, $surface, $context );
	if ( '' === $subject_key ) {
		return $result;
	}

	$metadata = array(
		'experiment_key'     => $experiment_key,
		'definition_version' => $definition['definition_version'],
		'assignment_policy'  => $definition['assignment_policy'],
		'variant'            => extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key ),
		'surface'            => $surface,
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
	 * @param array{experiment_key: string, definition_version: int, assignment_policy: string, variant: string, surface: string} $metadata Bounded assignment metadata.
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

	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;

	if ( null === $definition || ! extrachill_experiment_is_active( $experiment_key, $surface, $context ) ) {
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
