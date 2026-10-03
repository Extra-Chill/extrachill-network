<?php
/**
 * Journey fixture mu-plugin: anonymous-event-submission.
 *
 * Mounted only when this journey is selected (journey.json
 * "fixtureMuPlugins"); it exists solely inside the disposable boot and can
 * never reach production. Each filter substitutes a dependency the sandbox
 * does not have, never product behavior:
 *
 * - Cloudflare Turnstile cannot be solved by an automated browser. Uses the
 *   product's own dev/test seam (`extrachill_bypass_turnstile_verification`),
 *   same as auth-multisite and musician-link-page-onboarding. The REST
 *   route's Turnstile permission_callback still runs; it just accepts.
 * - No SMTP: never attempt a live send.
 *
 * It also records evidence the grade needs and cannot otherwise see:
 * - what `/extrachill/v1/event-submissions` actually answered (status, error
 *   code, job id), captured at rest_post_dispatch;
 * - what the visitor saw in the form's status line, posted by the browser
 *   step to `?ec_submission_journey_observe=1`.
 *
 * @package ExtraChillNetwork
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );

add_filter( 'pre_wp_mail', '__return_true' );

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		if ( '/extrachill/v1/event-submissions' !== (string) $request->get_route() ) {
			return $response;
		}

		$data   = $response instanceof WP_REST_Response ? $response->get_data() : null;
		$status = $response instanceof WP_REST_Response ? (int) $response->get_status() : 0;

		$log   = get_site_option( 'ec_submission_journey_rest', array() );
		$log[] = array(
			'status'        => $status,
			'user'          => get_current_user_id(),
			'error_code'    => is_array( $data ) ? ( $data['code'] ?? null ) : null,
			'error_message' => is_array( $data ) ? ( $data['message'] ?? null ) : null,
			'job_id'        => is_array( $data ) ? (int) ( $data['job_id'] ?? 0 ) : 0,
			'message'       => is_array( $data ) && ! isset( $data['code'] ) ? ( $data['message'] ?? null ) : null,
		);
		update_site_option( 'ec_submission_journey_rest', array_slice( $log, -10 ) );

		return $response;
	},
	10,
	3
);

add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		if ( ! isset( $_GET['ec_submission_journey_observe'] ) ) {
			return;
		}
		$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
		$log   = get_site_option( 'ec_submission_journey_observations', array() );
		$log[] = is_array( $body ) ? $body : array( 'raw' => 'unparseable' );
		update_site_option( 'ec_submission_journey_observations', array_slice( $log, -20 ) );
		status_header( 204 );
		exit;
	},
	0
);

/*
 * Core's WP_Ability::invoke_callback() turns a thrown exception into a
 * generic WP_Error and drops the file, line, and trace. Wrap the submission
 * ability's callback so the grade can report where a failure came from.
 */
add_filter(
	'wp_register_ability_args',
	static function ( $args, $name ) {
		if ( 'extrachill/submit-event' !== $name || ! is_callable( $args['execute_callback'] ?? null ) ) {
			return $args;
		}
		$inner                    = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $inner ) {
			try {
				return $inner( $input );
			} catch ( \Throwable $e ) {
				$log   = get_site_option( 'ec_submission_journey_exceptions', array() );
				$log[] = array(
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'at'      => $e->getFile() . ':' . $e->getLine(),
					'trace'   => substr( $e->getTraceAsString(), 0, 4000 ),
				);
				update_site_option( 'ec_submission_journey_exceptions', array_slice( $log, -5 ) );
				throw $e;
			}
		};
		return $args;
	},
	10,
	2
);
