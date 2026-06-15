<?php
/**
 * Cross-Site Link Renderers
 *
 * Display components for rendering cross-site navigation buttons.
 * Uses button-3 button-small classes from theme root.css for consistent styling.
 *
 * @package ExtraChillMultisite
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render cross-site taxonomy links on archive pages
 *
 * Hooked to: extrachill_archive_below_description
 */
function extrachill_render_cross_site_taxonomy_links() {
	if ( ! is_tax() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->taxonomy ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $term->taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-cross-site-taxonomy-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div>';
}

/**
 * Render cross-site user links on author archives
 *
 * Hooked to: extrachill_after_author_bio
 *
 * @param int $user_id Author user ID.
 */
function extrachill_render_cross_site_user_links( $user_id ) {
	if ( ! $user_id || ! is_int( $user_id ) ) {
		return;
	}

	$links = extrachill_get_cross_site_user_links( $user_id );
	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-cross-site-user-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div>';
}

/**
 * Render cross-site links on artist profiles
 *
 * Called directly by artist platform plugin template.
 * Shows links to blog coverage, events, and shop.
 *
 * @param string $artist_slug Artist profile slug.
 */
function extrachill_render_cross_site_artist_profile_links( $artist_slug ) {
	if ( empty( $artist_slug ) ) {
		return;
	}

	$links = extrachill_get_cross_site_artist_links( $artist_slug );
	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-cross-site-artist-profile-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div>';
}

/**
 * Register the cross-site "Coverage" section on the artist profile hub.
 *
 * This is the foreign-plugin proof for the artist-profile section registry
 * (extrachill-artist-platform#62 / epic #61): a term-scoped section that
 * self-registers from its OWNING plugin via the `ec_artist_profile_sections`
 * filter. The artist platform never names this section — multisite owns the
 * cross-site coverage data and therefore owns the section that surfaces it.
 *
 * The section reuses the existing cross-site link aggregation
 * (extrachill_get_cross_site_artist_links / blog-archive-by-term), so it is the
 * same coverage block that previously rendered inline inside the profile's
 * About block — now a first-class, independently ordered hub section.
 *
 * @param array[] $sections       Registered sections.
 * @param int     $artist_id      Artist profile post ID (artist blog).
 * @param int     $artist_term_id Bound main-blog `artist` term_id (0 if unbound).
 * @return array[]
 */
function extrachill_register_artist_profile_coverage_section( $sections, $artist_id, $artist_term_id ) {
	$sections[] = array(
		'id'       => 'coverage',
		'label'    => __( 'Coverage', 'extrachill-multisite' ),
		'priority' => 30,
		'as_tab'   => false,
		'visible'  => 'extrachill_artist_profile_has_cross_site_coverage',
		'render'   => 'extrachill_render_artist_profile_coverage_section',
	);

	return $sections;
}
add_filter( 'ec_artist_profile_sections', 'extrachill_register_artist_profile_coverage_section', 10, 3 );

/**
 * Resolve the artist slug for a profile, preferring the stored term binding.
 *
 * The cross-site coverage helpers key off the artist slug (which equals the
 * `artist` term slug on the main blog). When the term binding (Primitive 1) is
 * available we resolve the slug from the bound term so a profile rename can't
 * silently desync coverage; otherwise we fall back to the profile post slug.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id (0 if unbound).
 * @return string Artist slug, or '' if none can be resolved.
 */
function extrachill_get_artist_profile_coverage_slug( $artist_id, $artist_term_id = 0 ) {
	$artist_term_id = (int) $artist_term_id;

	if ( $artist_term_id > 0 ) {
		$main_blog_id = ec_get_blog_id( 'main' );
		if ( $main_blog_id ) {
			$slug = '';
			switch_to_blog( $main_blog_id );
			try {
				$term = get_term( $artist_term_id, 'artist' );
				if ( $term && ! is_wp_error( $term ) ) {
					$slug = $term->slug;
				}
			} finally {
				restore_current_blog();
			}
			if ( ! empty( $slug ) ) {
				return $slug;
			}
		}
	}

	$post = get_post( (int) $artist_id );
	if ( $post && 'artist_profile' === $post->post_type && ! empty( $post->post_name ) ) {
		return $post->post_name;
	}

	return '';
}

/**
 * Visibility gate for the Coverage section.
 *
 * Hides the section when the artist has no cross-site coverage at all so new
 * artists don't see an empty block.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return bool
 */
function extrachill_artist_profile_has_cross_site_coverage( $artist_id, $artist_term_id = 0 ) {
	$slug = extrachill_get_artist_profile_coverage_slug( $artist_id, $artist_term_id );
	if ( empty( $slug ) ) {
		return false;
	}

	$links = extrachill_get_cross_site_artist_links( $slug );

	return ! empty( $links );
}

/**
 * Render the Coverage section for the artist profile hub.
 *
 * Echoes the cross-site coverage links (blog/events/shop) for the artist,
 * resolved via the term binding when present. Server-side render, no AJAX.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return void
 */
function extrachill_render_artist_profile_coverage_section( $artist_id, $artist_term_id = 0 ) {
	$slug = extrachill_get_artist_profile_coverage_slug( $artist_id, $artist_term_id );
	if ( empty( $slug ) ) {
		return;
	}

	extrachill_render_cross_site_artist_profile_links( $slug );
}

/**
 * Render a single cross-site link button
 *
 * Builds descriptive labels: "{Term Name} {Content Type} ({Count})"
 * Example: "Charleston Blog Posts (5)" instead of "Blog (5)"
 *
 * @param array  $link  Link data with 'url', 'label', optional 'term_name', and optional 'count'.
 * @param string $class Additional CSS class.
 */
function extrachill_cross_site_link_button( $link, $class = '' ) {
	if ( empty( $link['url'] ) || empty( $link['label'] ) ) {
		return;
	}

	$button_class = 'button-3 button-small ec-cross-site-link';
	if ( ! empty( $class ) ) {
		$button_class .= ' ' . esc_attr( $class );
	}

	// Build descriptive label: "{Term Name} {Content Type} ({Count})".
	$label_parts = array();

	if ( ! empty( $link['term_name'] ) ) {
		$label_parts[] = esc_html( $link['term_name'] );
	}

	$label_parts[] = esc_html( $link['label'] );

	$label = implode( ' ', $label_parts );

	// Only display count when it meets the minimum threshold (default: 3).
	$min_count = apply_filters( 'extrachill_cross_site_link_min_count_display', 3 );
	if ( isset( $link['count'] ) && $link['count'] >= $min_count ) {
		$label .= ' (' . (int) $link['count'] . ')';
	}

	printf(
		'<a href="%s" class="%s">%s</a>',
		esc_url( $link['url'] ),
		esc_attr( $button_class ),
		$label
	);
}
