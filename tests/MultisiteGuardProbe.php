<?php
/**
 * Child-process probe: extrachill_network_init() branches correctly on
 * is_multisite() and the runtime guard matches the activation guard's
 * intent (#268).
 *
 * Invoked with one CLI arg: `multisite` or `single`. Prints a JSON result
 * line to stdout for the parent (multisite-guard-smoke.php) to assert on.
 *
 * Requiring the real extrachill-network.php pulls in the entire foundation
 * and feature-provider chain on the multisite branch. Every file in that
 * chain only calls `add_action()` / `add_filter()` at top level (verified
 * by grep across inc/core, inc/theme, inc/taxonomy, inc/integrations,
 * inc/commerce, inc/cross-site-links, inc/community-activity, inc/assets.php,
 * inc/cache) — the boot() callbacks in FeatureProviders.php are themselves
 * only `require_once` sequences plus an `if ( function_exists( 'wp_register_ability' ) )`
 * guard that stays false here, so no ability classes are constructed. That
 * keeps this probe's stub surface small without skipping any real code path.
 */

$mode = $argv[1] ?? '';
if ( ! in_array( $mode, array( 'multisite', 'single' ), true ) ) {
	fwrite( STDERR, "Usage: php MultisiteGuardProbe.php <multisite|single>\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['probe_mode']             = $mode;
$GLOBALS['probe_actions']          = array();
$GLOBALS['probe_current_user_can'] = false;

function is_multisite() {
	return 'multisite' === $GLOBALS['probe_mode'];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}

function plugin_dir_url( $file ) {
	unset( $file );
	return 'https://example.test/wp-content/plugins/extrachill-network/';
}

function register_activation_hook( ...$args ) {
	unset( $args );
}

function add_action( $hook, $callback, ...$rest ) {
	unset( $rest );
	$GLOBALS['probe_actions'][ $hook ][] = $callback;
}

function add_filter( ...$args ) {
	unset( $args );
}

function apply_filters( $hook, $value, ...$args ) {
	unset( $hook, $args );
	return $value;
}

function do_action( ...$args ) {
	unset( $args );
}

function get_site_option( $name, $default = false ) {
	unset( $name );
	return $default;
}

function is_admin() {
	return false;
}

function is_network_admin() {
	return false;
}

function current_user_can( $capability ) {
	unset( $capability );
	return $GLOBALS['probe_current_user_can'];
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

require dirname( __DIR__ ) . '/extrachill-network.php';

// Simulate the plugins_loaded hook WordPress would fire.
extrachill_network_init();

$notice_registered = isset( $GLOBALS['probe_actions']['admin_notices'] )
	&& in_array( 'extrachill_network_multisite_required_notice', $GLOBALS['probe_actions']['admin_notices'], true );

// Exercise the notice callback's own permission gate directly.
$GLOBALS['probe_current_user_can'] = false;
ob_start();
extrachill_network_multisite_required_notice();
$notice_output_without_permission = ob_get_clean();

$GLOBALS['probe_current_user_can'] = true;
ob_start();
extrachill_network_multisite_required_notice();
$notice_output_with_permission = ob_get_clean();

$providers = $GLOBALS['extrachill_network_feature_providers'] ?? array();

echo wp_json_encode_stub(
	array(
		'foundation_booted'                 => function_exists( 'ec_send_email' ),
		'blog_ids_loaded'                   => function_exists( 'ec_get_blog_id' ),
		'provider_count'                    => count( $providers ),
		'provider_statuses'                 => array_map(
			static function ( $provider ) {
				return $provider['status'];
			},
			$providers
		),
		'notice_registered'                 => $notice_registered,
		'notice_output_without_permission'  => $notice_output_without_permission,
		'notice_output_with_permission_has' => false !== strpos( $notice_output_with_permission, 'multisite' ),
	)
), "\n";

/**
 * json_encode() wrapper kept local so this probe has zero dependency on
 * wp_json_encode() (not stubbed above — nothing under test needs it).
 *
 * @param mixed $data Data to encode.
 * @return string JSON.
 */
function wp_json_encode_stub( $data ) {
	return (string) json_encode( $data );
}
