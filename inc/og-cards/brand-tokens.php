<?php
/**
 * Brand Tokens Bridge for Data Machine Image Templates.
 *
 * Reads the Extra Chill design system (root.css generated from
 * @extrachill/tokens) and feeds the colors/fonts/labels to the
 * Data Machine `datamachine/image_template/brand_tokens` filter.
 *
 * The theme stays a passive token publisher — it does not know that
 * Data Machine exists. This bridge file is the single point where the
 * EC platform tells DM how to look. Any DM-rendered image (OG cards,
 * social cards, etc.) network-wide picks up EC branding from here.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve an absolute path to a theme font file.
 *
 * GD only reads TTF/OTF — woff/woff2 are useless here. Returns null
 * when the requested file is missing so the caller can fall back to a
 * system font without throwing.
 *
 * @param string $filename Font filename relative to the active theme's /assets/fonts/.
 * @return string|null
 */
function font_path( string $filename ): ?string {
	$path = get_template_directory() . '/assets/fonts/' . $filename;
	return file_exists( $path ) ? $path : null;
}

/**
 * Map the current blog ID to the short label shown on OG cards.
 *
 * Returns an empty string for the main site so cards there read just
 * "Extra Chill" without a trailing separator. Filterable for new sites
 * or per-surface overrides.
 *
 * @return string
 */
function site_label(): string {
	$label = match ( (int) get_current_blog_id() ) {
		1       => '',
		2       => 'Community',
		3       => 'Shop',
		4       => 'Artists',
		7       => 'Events',
		9       => 'Newsletter',
		10      => 'Docs',
		11      => 'Wire',
		12      => 'Studio',
		default => '',
	};

	/**
	 * Filter the OG card site label for the current blog.
	 *
	 * @param string $label    Default label resolved from blog ID.
	 * @param int    $blog_id  Current blog ID.
	 */
	return (string) apply_filters( 'extrachill_og_card_site_label', $label, (int) get_current_blog_id() );
}

/**
 * Provide Extra Chill brand tokens to Data Machine image templates.
 *
 * Colors mirror the `:root` vars in the active theme's root.css. Fonts
 * point at the TTF files shipped with the theme.
 *
 * @param array  $tokens      Default tokens from Data Machine.
 * @param string $template_id Template requesting tokens (unused here — same brand for all templates).
 * @param mixed  $context     Optional context (typically a WP_Post).
 * @return array
 */
function provide_tokens( array $tokens, string $template_id = '', $context = null ): array {
	$colors = array(
		// Mirrors the EC light-mode palette from root.css.
		'background'      => '#ffffff',
		'background_dark' => '#000000',
		'surface'         => '#f1f5f9',
		'accent'          => '#53940b',
		'accent_hover'    => '#3d6b08',
		'accent_2'        => '#36454f',
		'accent_3'        => '#00c8e3',
		'text_primary'    => '#000000',
		'text_muted'      => '#6b7280',
		'text_inverse'    => '#ffffff',
		'header_bg'       => '#000000',
		'border'          => '#dddddd',
	);

	$fonts = array(
		'heading' => font_path( 'WilcoLoftSans-Treble.ttf' ),
		'body'    => font_path( 'helvetica.ttf' ),
		// Theme ships Lobster only as woff2 — fall back to the heading face
		// so brand strips still use a theme-shipped font, not system DejaVu.
		'brand'   => font_path( 'WilcoLoftSans-Treble.ttf' ),
		'mono'    => font_path( 'helvetica.ttf' ),
	);

	$tokens['colors']     = array_merge( (array) ( $tokens['colors'] ?? array() ), $colors );
	$tokens['fonts']      = array_merge( (array) ( $tokens['fonts'] ?? array() ), $fonts );
	$tokens['brand_text'] = 'Extra Chill';
	$tokens['site_label'] = site_label();

	return $tokens;
}

add_filter( 'datamachine/image_template/brand_tokens', __NAMESPACE__ . '\\provide_tokens', 10, 3 );

/**
 * Load and cache badge color pairs from the theme's root.css.
 *
 * `root.css` is generated from @extrachill/tokens at build time and is the
 * production source of truth on disk. We parse it once per request and
 * extract the `--badge-*-bg` / `--badge-*-text` pairs into a flat lookup
 * map keyed by the slug fragment (e.g. 'location-austin').
 *
 * @return array<string, array{bg: string, text: string}>
 */
function badge_token_map(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$path = get_template_directory() . '/assets/css/root.css';
	if ( ! file_exists( $path ) ) {
		$cache = array();
		return $cache;
	}

	$raw = file_get_contents( $path );
	if ( ! $raw ) {
		$cache = array();
		return $cache;
	}

	if ( ! preg_match_all( '/--badge-([a-z0-9-]+?)-(bg|text):\s*([#a-fA-F0-9rgba(),.\s]+?);/', $raw, $matches, PREG_SET_ORDER ) ) {
		$cache = array();
		return $cache;
	}

	$pairs = array();
	foreach ( $matches as $match ) {
		$key  = $match[1];
		$role = $match[2];
		$val  = trim( $match[3] );

		$pairs[ $key ][ $role ] = $val;
	}

	$out = array();
	foreach ( $pairs as $key => $value ) {
		if ( isset( $value['bg'], $value['text'] ) ) {
			$out[ $key ] = array(
				'bg'   => $value['bg'],
				'text' => $value['text'],
			);
		}
	}

	$cache = $out;
	return $cache;
}

/**
 * Resolve badge colors for a single taxonomy term.
 *
 * @param \WP_Term $term     Term object.
 * @param string   $tax_slug Taxonomy slug (matches the badge naming, e.g. 'location').
 * @return array{bg: string, text: string}|null
 */
function term_badge_colors( \WP_Term $term, string $tax_slug ): ?array {
	$tokens = badge_token_map();
	$key    = $tax_slug . '-' . $term->slug;
	return $tokens[ $key ] ?? null;
}
