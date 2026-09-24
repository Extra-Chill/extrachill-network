<?php
/**
 * Plugin Name: Extra Chill Network
 * Plugin URI: https://extrachill.com
 * Description: Network administration foundation for the ExtraChill Platform. Provides network-wide Cloudflare Turnstile integration and consolidated network admin menu.
 * Version: 2.19.7
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.3
 * Text Domain: extrachill-network
 * Domain Path: /languages
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_NETWORK_VERSION', '2.19.7' );
define( 'EXTRACHILL_NETWORK_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_NETWORK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( EXTRACHILL_NETWORK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Foundation/bootstrap.php';
require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/FeatureProviders.php';

register_activation_hook( __FILE__, 'extrachill_network_activate' );

/**
 * Prevent activation outside WordPress multisite.
 *
 * @return void
 */
function extrachill_network_activate() {
	if ( is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );

	// Only wp_die() in interactive admin contexts. Non-interactive callers
	// (WP-CLI, WordPress Playground bootstrap, automated test runners) need
	// the plugin file to load without terminating the PHP process — the
	// runtime guards inside extrachill_network_init() prevent any actual
	// multisite-only behavior from firing on single-site installs.
	$is_interactive_admin = is_admin()
		&& ! defined( 'WP_CLI' )
		&& ! wp_doing_ajax();

	if ( $is_interactive_admin ) {
		wp_die( 'Extra Chill Network plugin requires a WordPress multisite installation.' );
	}
}

add_action( 'plugins_loaded', 'extrachill_network_init' );

/**
 * Load network-owned runtime integrations.
 *
 * Runtime counterpart to the activation guard in extrachill_network_activate().
 * That guard only stops *activation* outside multisite; a plugin that is
 * force-loaded without going through WordPress's normal activation flow
 * (a test fixture, a WP-CLI scaffold, `muplugin_loaded`) never hits it.
 * Every integration this plugin boots eventually reaches `switch_to_blog()`
 * or another multisite-only API somewhere in its call graph, so booting
 * them off multisite doesn't gracefully degrade — it just moves the fatal
 * deeper into the request, onto whichever hook happens to fire first. Bail
 * here instead, before any of that code loads.
 *
 * `inc/core/blog-ids.php` is the one exception: its blog-ID/site-URL
 * helpers (`ec_get_blog_id()`, `ec_get_site_url()`, etc.) are pure lookups
 * against hardcoded constants — no `switch_to_blog()`, no other
 * multisite-only API — and dozens of other network plugins call them
 * directly. Loading it unconditionally costs nothing and keeps those
 * plugins from trading one undefined-function fatal for another.
 *
 * @return void
 */
function extrachill_network_init() {
	if ( ! is_multisite() ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/blog-ids.php';
		add_action( 'admin_notices', 'extrachill_network_multisite_required_notice' );
		return;
	}

	extrachill_network_boot_foundation();
	extrachill_network_register_default_feature_providers();
	extrachill_network_boot_feature_providers();
}

/**
 * Notify plugin managers that Extra Chill Network requires multisite.
 *
 * Fires only when extrachill_network_init() took the non-multisite branch
 * above, i.e. the plugin was loaded outside its declared `Network: true`
 * requirement without going through register_activation_hook().
 *
 * @return void
 */
function extrachill_network_multisite_required_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Extra Chill Network requires a WordPress multisite installation. Network integrations on this site are disabled.', 'extrachill-network' )
	);
}
