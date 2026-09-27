<?php
/**
 * Seed the anonymous event submission journey.
 *
 * Production serves the public form at events.extrachill.com/submit/ (a page
 * whose only interactive content is the extrachill/event-submission block).
 * The rig boots an empty network, so this seed recreates that page on the
 * events site, resolved by domain, never by blog ID.
 *
 * It also configures Cloudflare's documented always-pass Turnstile TEST site
 * key so the block renders its real widget container, exactly as it does in
 * production. The server-side check is satisfied by this journey's fixture
 * mu-plugin through the product's own bypass seam.
 *
 * @package ExtraChillNetwork
 */

$sites = get_sites(
	array(
		'domain' => 'events.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $sites ) ) {
	throw new RuntimeException( 'events.extrachill.com is not in the booted topology.' );
}
$events_blog_id = (int) $sites[0]->blog_id;

update_site_option( 'ec_turnstile_site_key', '1x00000000000000000000AA' );
update_site_option( 'ec_turnstile_secret_key', '1x0000000000000000000000000000000AA' );

// Clear evidence from any earlier run in the same boot.
delete_site_option( 'ec_submission_journey_rest' );
delete_site_option( 'ec_submission_journey_observations' );
delete_site_option( 'ec_submission_journey_exceptions' );

switch_to_blog( $events_blog_id );

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

wp_set_current_user( 1 );

$content = "<!-- wp:paragraph -->\n<p>Our calendar is fully automated. We gather as many data sources as possible, but sometimes, we miss things. If you know an event that is missing, this is how you add it.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:extrachill/event-submission /-->";

$existing = get_page_by_path( 'submit' );
$postarr  = array(
	'post_title'   => 'Submit an Event',
	'post_name'    => 'submit',
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_content' => $content,
);
if ( $existing ) {
	$postarr['ID'] = $existing->ID;
}
$page_id = wp_insert_post( $postarr, true );
if ( is_wp_error( $page_id ) ) {
	restore_current_blog();
	throw new RuntimeException( esc_html( 'Failed to seed the submit page: ' . $page_id->get_error_message() ) );
}

$fixture = array(
	'schema'          => 'extrachill-network/journey-fixture/anonymous-event-submission/v1',
	'events_blog_id'  => $events_blog_id,
	'page_id'         => (int) $page_id,
	'url'             => get_permalink( $page_id ),
	'event_title'     => 'Rig Anonymous Submission Night',
	'submitter_email' => 'rig-anonymous-submitter@example.test',
	'submitter_name'  => 'Rig Anonymous Submitter',
	'block_available' => WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/event-submission' ),
);

restore_current_blog();
wp_set_current_user( 0 );

update_site_option( 'ec_rig_journey_fixture_anonymous_event_submission', $fixture );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $fixture ) ) );
