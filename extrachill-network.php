<?php
/**
 * Plugin Name: Extra Chill Network
 * Plugin URI: https://extrachill.com
 * Description: Network administration foundation for the ExtraChill Platform. Provides network-wide Cloudflare Turnstile integration and consolidated network admin menu.
 * Version: 2.17.2
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

define( 'EXTRACHILL_NETWORK_VERSION', '2.17.2' );
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
 * @return void
 */
function extrachill_network_init() {
	extrachill_network_boot_foundation();
	extrachill_network_register_default_feature_providers();
	extrachill_network_boot_feature_providers();
}
