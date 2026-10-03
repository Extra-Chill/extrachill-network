<?php
/**
 * Journey fixture mu-plugin: returning-artist-link-page.
 *
 * Each browser path ends by posting "I got here" to this sink, only after
 * every assertion in that path passed (a failed assertion ends the step
 * first). grade.php turns the recorded markers into graded cases, so a
 * broken path is a finding rather than an unjudged browser-step failure.
 *
 * @package ExtraChillNetwork
 */

add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		$reached = isset( $_GET['returning_artist_reached'] ) ? sanitize_key( wp_unslash( $_GET['returning_artist_reached'] ) ) : '';
		if ( '' === $reached ) {
			return;
		}
		$log             = get_site_option( 'ec_rig_returning_artist_reached', array() );
		$log[ $reached ] = time();
		update_site_option( 'ec_rig_returning_artist_reached', $log );
		status_header( 204 );
		exit;
	},
	0
);
