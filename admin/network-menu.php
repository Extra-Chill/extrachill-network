<?php
/**
 * ExtraChill Network Admin Menu
 *
 * Top-level network admin menu for ExtraChill Platform settings.
 *
 * @package ExtraChill\Network
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ) {
	define( 'EXTRACHILL_NETWORK_MENU_SLUG', 'extrachill-network' );
}

add_action( 'network_admin_menu', 'ec_add_network_menu', 5 );

function ec_add_network_menu() {
	add_menu_page(
		'Extra Chill Network',
		'Extra Chill Network',
		'manage_network_options',
		EXTRACHILL_NETWORK_MENU_SLUG,
		null,
		'dashicons-admin-multisite',
		3
	);
}
