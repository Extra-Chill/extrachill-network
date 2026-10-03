<?php
/**
 * Fixture mu-plugin: keep calendar-going-share renders free of broken images.
 *
 * The rig blocks external hosts, so WordPress emoji images (s.w.org) and
 * Gravatar avatars render as broken-image icons on camera. Use native emoji
 * glyphs and a local inline avatar instead. Sandbox-only; never deployed.
 *
 * @package ExtraChillNetwork
 */

remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
add_filter( 'emoji_svg_url', '__return_false' );

add_filter(
	'pre_get_avatar_data',
	static function ( $args ) {
		$svg         = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#53940b"/><circle cx="32" cy="25" r="11" fill="#fff"/><path d="M12 56c3-11 11-17 20-17s17 6 20 17z" fill="#fff"/></svg>';
		$args['url'] = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline fixture avatar.
		return $args;
	}
);
