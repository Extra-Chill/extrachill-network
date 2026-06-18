<?php
/**
 * Forward-Surface Candidates for Internal Linking
 *
 * Data Machine's internal-linking task (`datamachine links crosslink`) discovers
 * link targets only from same-site posts that share a category or tag with the
 * source post. On Extra Chill's main blog that means a residual song-meaning
 * page can only ever link to *other* residual pages — it deepens the very silo
 * the linking pass is meant to break.
 *
 * This hook adds the network dimension Data Machine core deliberately does not
 * have. For a source post it takes the shared cross-site taxonomy terms
 * (artist, venue, location, festival), resolves where those terms have live
 * content on sibling sites via the existing cross-site term-link primitive, and
 * offers those sibling URLs as additional linking candidates — biased above the
 * same-site catalog so traffic is routed toward forward surfaces (events,
 * community, news wire) rather than back into the silo.
 *
 * Data Machine core stays network-agnostic: all Extra Chill site knowledge
 * (which taxonomies are shared, which sites count as forward surfaces, how to
 * resolve a term's cross-site URL) lives here, behind the
 * `datamachine_internal_linking_candidates` filter.
 *
 * @package ExtraChillMultisite
 * @since   1.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-site taxonomies that meaningfully connect a post to forward surfaces.
 *
 * These are the shared-entity taxonomies (not the generic category/post_tag
 * Data Machine already matches on) whose terms exist across sites and resolve
 * to a real archive/profile URL via extrachill_get_cross_site_term_links().
 *
 * @return array<int, string> Taxonomy slugs, in priority order.
 */
function extrachill_internal_linking_cross_site_taxonomies() {
	/**
	 * Filter the taxonomies used to discover cross-site internal-linking targets.
	 *
	 * @since 1.19.0
	 *
	 * @param array<int, string> $taxonomies Taxonomy slugs.
	 */
	return apply_filters(
		'extrachill_internal_linking_cross_site_taxonomies',
		array( 'artist', 'venue', 'festival', 'location' )
	);
}

/**
 * Site keys treated as forward surfaces.
 *
 * Candidates on these sites get a score boost so they outrank same-site
 * residual catalog candidates when Data Machine re-ranks the merged list. Any
 * other cross-site target (e.g. shop) is still offered, just without the boost.
 *
 * @return array<int, string> Forward-surface site keys.
 */
function extrachill_internal_linking_forward_surface_keys() {
	/**
	 * Filter which sibling sites count as "forward surfaces" for linking bias.
	 *
	 * @since 1.19.0
	 *
	 * @param array<int, string> $keys Site keys (see ec_get_blog_ids()).
	 */
	return apply_filters(
		'extrachill_internal_linking_forward_surface_keys',
		array( 'events', 'community', 'wire' )
	);
}

/**
 * Inject cross-site forward-surface candidates into internal linking.
 *
 * Hooked to Data Machine's `datamachine_internal_linking_candidates` filter.
 * Returns the original same-site candidates plus any cross-site candidates
 * discovered for the source post's shared-taxonomy terms. Forward-surface
 * candidates carry a score above the highest same-site score so they win
 * Data Machine's re-rank without discarding the in-catalog options.
 *
 * @param array  $candidates   Same-site candidates discovered by Data Machine core.
 * @param int    $post_id      Source post being linked from.
 * @param string $source_title Source post title (unused; kept for filter contract).
 * @param array  $categories   Source post category term IDs (unused here).
 * @param array  $tags         Source post tag term IDs (unused here).
 * @param int    $limit        Maximum candidates Data Machine will keep.
 * @return array Merged candidate list.
 */
function extrachill_add_cross_site_linking_candidates( $candidates, $post_id, $source_title, $categories, $tags, $limit ) {
	unset( $source_title, $categories, $tags, $limit );

	$post_id = absint( $post_id );
	if ( $post_id <= 0 ) {
		return $candidates;
	}

	// Requires the cross-site term-link primitive from this plugin.
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return $candidates;
	}

	$candidates = is_array( $candidates ) ? $candidates : array();

	// Highest existing same-site score — forward-surface candidates are
	// boosted just above it so they rank first without erasing the catalog.
	$max_same_site_score = 0.0;
	foreach ( $candidates as $candidate ) {
		if ( isset( $candidate['score'] ) && (float) $candidate['score'] > $max_same_site_score ) {
			$max_same_site_score = (float) $candidate['score'];
		}
	}

	$forward_keys  = extrachill_internal_linking_forward_surface_keys();
	$forward_boost = (float) apply_filters( 'extrachill_internal_linking_forward_boost', 100.0, $post_id );
	$seen_urls     = array();
	$cross_site    = array();

	// Avoid offering a URL the post already links to.
	foreach ( $candidates as $candidate ) {
		if ( ! empty( $candidate['url'] ) ) {
			$seen_urls[ trailingslashit( (string) $candidate['url'] ) ] = true;
		}
	}

	foreach ( extrachill_internal_linking_cross_site_taxonomies() as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term ) {
			$links = extrachill_get_cross_site_term_links( $term, $taxonomy );
			if ( empty( $links ) || ! is_array( $links ) ) {
				continue;
			}

			foreach ( $links as $link ) {
				if ( empty( $link['url'] ) ) {
					continue;
				}

				$url     = (string) $link['url'];
				$url_key = trailingslashit( $url );
				if ( isset( $seen_urls[ $url_key ] ) ) {
					continue;
				}
				$seen_urls[ $url_key ] = true;

				$site_key   = isset( $link['site_key'] ) ? (string) $link['site_key'] : '';
				$is_forward = in_array( $site_key, $forward_keys, true );
				$term_name  = isset( $link['term_name'] ) ? (string) $link['term_name'] : $term->name;
				$site_label = isset( $link['label'] ) ? (string) $link['label'] : ucfirst( $site_key );

				// Title is what the AI weaves the anchor around. Use the term
				// name so the link reads naturally in prose (e.g. the artist or
				// venue the source post is about).
				$title = $term_name;

				// Score: forward surfaces boosted above same-site; others sit
				// just below the boost but still above a zero-score catalog tail.
				$score = $is_forward
					? $max_same_site_score + $forward_boost
					: $max_same_site_score + ( $forward_boost / 2 );

				$cross_site[] = array(
					'id'      => 0,
					'url'     => $url,
					'title'   => $title,
					'excerpt' => sprintf(
						/* translators: 1: term name, 2: sibling site label. */
						__( '%1$s on %2$s', 'extrachill-multisite' ),
						$term_name,
						$site_label
					),
					'score'   => $score,
				);
			}
		}
	}

	if ( empty( $cross_site ) ) {
		return $candidates;
	}

	return array_merge( $candidates, $cross_site );
}
add_filter( 'datamachine_internal_linking_candidates', 'extrachill_add_cross_site_linking_candidates', 10, 6 );
