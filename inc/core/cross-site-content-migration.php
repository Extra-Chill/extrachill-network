<?php
/**
 * Cross-Site Content Migration
 *
 * Generic network primitive that moves ONE post and all of its media from any
 * blog in the network to any other blog. Built for the Extra Chill multisite
 * where a submission can be authored on one subsite (e.g. Studio, blog 12) and
 * needs to live on another (e.g. the main editorial site, blog 1), but the
 * function is fully general — no site is special-cased.
 *
 * Design guarantees:
 *   - Non-destructive by default. The source post/attachments are NEVER touched
 *     unless the caller explicitly passes `delete_source => true` AND the
 *     migration verified successfully. A dry-run or a partial failure never
 *     deletes anything.
 *   - Dry-run does zero writes on either blog — it reports what WOULD happen.
 *   - Missing source files are REPORTED, never silently skipped.
 *   - Content is rewritten so every old attachment ID and old upload URL is
 *     remapped to the new dest ID/URL: image block `"id":N`, `wp-image-N`
 *     classes, gallery `ids` arrays, and raw `.../uploads/sites/<src>/...` URLs.
 *
 * Uses core APIs only: switch_to_blog() for cross-blog reads/writes,
 * media_handle_sideload()-style sideloading (wp_insert_attachment +
 * wp_generate_attachment_metadata) for attachments.
 *
 * @package ExtraChillMultisite
 * @since 1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrate a single post (and its media) from a source blog to a dest blog.
 *
 * @param int   $source_blog_id Source blog ID.
 * @param int   $post_id        Post ID on the source blog.
 * @param int   $dest_blog_id   Destination blog ID.
 * @param array $args           {
 *     Optional. Migration options.
 *
 *     @type string $status        Destination status. Default '' (preserve source status;
 *                                 a source `pending` maps to `pending` on dest).
 *     @type bool   $delete_source Delete source post + migrated attachments after a
 *                                 verified successful migration. Default false.
 *     @type bool   $dry_run       Report what would happen without writing anything. Default false.
 *     @type int    $migrated_by   User ID to stamp as the migrator. Default current user (or 0).
 * }
 * @return array|WP_Error Structured result, or WP_Error on unrecoverable failure. {
 *     @type bool   $dry_run          Whether this was a dry run.
 *     @type int    $source_blog_id   Source blog ID.
 *     @type int    $source_post_id   Source post ID.
 *     @type int    $dest_blog_id     Destination blog ID.
 *     @type int    $dest_post_id     New post ID on dest (0 on dry-run).
 *     @type string $dest_status      Final status applied on dest.
 *     @type array  $attachment_map   old_attachment_id => new_attachment_id.
 *     @type array  $url_map          old_url => new_url.
 *     @type int    $attachments_total   Count of distinct source attachments considered.
 *     @type int    $attachments_migrated Count actually created on dest (0 on dry-run).
 *     @type array  $missing_files    Attachments whose underlying file was missing on disk.
 *     @type int    $featured_image_id New featured image ID on dest (0 if none / dry-run).
 *     @type bool   $source_deleted   Whether the source was deleted.
 * }
 */
function ec_migrate_post( int $source_blog_id, int $post_id, int $dest_blog_id, array $args = array() ) {
	if ( ! is_multisite() ) {
		return new WP_Error( 'ec_migrate_not_multisite', 'Cross-site migration requires a multisite install.' );
	}

	$defaults = array(
		'status'        => '',
		'delete_source' => false,
		'dry_run'       => false,
		'migrated_by'   => (int) get_current_user_id(),
	);
	$args     = wp_parse_args( $args, $defaults );

	$source_blog_id  = (int) $source_blog_id;
	$post_id         = (int) $post_id;
	$dest_blog_id    = (int) $dest_blog_id;
	$dry_run         = (bool) $args['dry_run'];
	$delete_source   = (bool) $args['delete_source'];
	$override_status = (string) $args['status'];
	$migrated_by     = (int) $args['migrated_by'];

	if ( $source_blog_id <= 0 || $dest_blog_id <= 0 || $post_id <= 0 ) {
		return new WP_Error( 'ec_migrate_invalid_args', 'source_blog_id, post_id, and dest_blog_id must all be positive integers.' );
	}

	if ( $source_blog_id === $dest_blog_id ) {
		return new WP_Error( 'ec_migrate_same_blog', 'Source and destination blog IDs are identical — nothing to migrate.' );
	}

	if ( ! get_site( $source_blog_id ) ) {
		return new WP_Error( 'ec_migrate_bad_source_blog', sprintf( 'Source blog %d does not exist.', $source_blog_id ) );
	}
	if ( ! get_site( $dest_blog_id ) ) {
		return new WP_Error( 'ec_migrate_bad_dest_blog', sprintf( 'Destination blog %d does not exist.', $dest_blog_id ) );
	}

	// ---------------------------------------------------------------------
	// 1. Read the source post + gather its attachments (on the source blog).
	// ---------------------------------------------------------------------
	$source = ec_migrate_read_source( $source_blog_id, $post_id );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$source_post   = $source['post'];
	$attachments   = $source['attachments']; // id => descriptor (title/alt/caption/desc/mime/file path/url).
	$missing_files = $source['missing_files'];

	// Resolve destination status.
	$dest_status = '' !== $override_status ? $override_status : (string) $source_post['post_status'];
	if ( '' === $dest_status ) {
		$dest_status = 'pending';
	}

	// ---------------------------------------------------------------------
	// DRY RUN — report intent, write nothing.
	// ---------------------------------------------------------------------
	if ( $dry_run ) {
		return array(
			'dry_run'              => true,
			'source_blog_id'       => $source_blog_id,
			'source_post_id'       => $post_id,
			'dest_blog_id'         => $dest_blog_id,
			'dest_post_id'         => 0,
			'dest_status'          => $dest_status,
			'attachment_map'       => array(),
			'url_map'              => array(),
			'attachments_total'    => count( $attachments ),
			'attachments_migrated' => 0,
			'missing_files'        => array_values( $missing_files ),
			'featured_image_id'    => 0,
			'source_deleted'       => false,
			'would_delete_source'  => $delete_source,
			'source_title'         => $source_post['post_title'],
		);
	}

	// ---------------------------------------------------------------------
	// 2. Copy each attachment file into the DEST blog and create new
	// attachments there. Build old_id=>new_id and old_url=>new_url maps.
	// ---------------------------------------------------------------------
	$copy = ec_migrate_copy_attachments( $attachments, $dest_blog_id );
	if ( is_wp_error( $copy ) ) {
		return $copy;
	}

	$attachment_map  = $copy['attachment_map'];
	$url_map         = $copy['url_map'];
	$new_attachments = $copy['new_attachment_ids'];
	// Merge any files that turned out missing during copy into the report.
	foreach ( $copy['missing_files'] as $mf ) {
		$missing_files[ $mf['id'] ] = $mf;
	}

	// ---------------------------------------------------------------------
	// 3. Rewrite content with the ID/URL maps and create the dest post.
	// ---------------------------------------------------------------------
	$new_content = ec_migrate_rewrite_post_content(
		(string) $source_post['post_content'],
		$attachment_map,
		$url_map
	);

	$featured_dest_id = 0;
	if ( $source_post['featured_image_id'] > 0 && isset( $attachment_map[ $source_post['featured_image_id'] ] ) ) {
		$featured_dest_id = (int) $attachment_map[ $source_post['featured_image_id'] ];
	}

	$create = ec_migrate_create_dest_post(
		$dest_blog_id,
		$source_post,
		$new_content,
		$dest_status,
		$featured_dest_id,
		$new_attachments,
		array(
			'source_blog_id' => $source_blog_id,
			'source_post_id' => $post_id,
			'migrated_by'    => $migrated_by,
		)
	);

	if ( is_wp_error( $create ) ) {
		// Migration failed mid-flight. Do NOT delete the source. Attachments
		// already created on dest are left for manual cleanup and reported.
		return $create;
	}

	$dest_post_id = (int) $create;

	// ---------------------------------------------------------------------
	// 4/6. Only delete the source if explicitly requested AND we verified a
	// successful migration (dest post exists). Never on dry-run/partial.
	// ---------------------------------------------------------------------
	$source_deleted = false;
	if ( $delete_source && $dest_post_id > 0 ) {
		$deleted        = ec_migrate_delete_source( $source_blog_id, $post_id, array_keys( $attachments ) );
		$source_deleted = ! is_wp_error( $deleted ) && $deleted;
	}

	return array(
		'dry_run'              => false,
		'source_blog_id'       => $source_blog_id,
		'source_post_id'       => $post_id,
		'dest_blog_id'         => $dest_blog_id,
		'dest_post_id'         => $dest_post_id,
		'dest_status'          => $dest_status,
		'attachment_map'       => $attachment_map,
		'url_map'              => $url_map,
		'attachments_total'    => count( $attachments ),
		'attachments_migrated' => count( $new_attachments ),
		'missing_files'        => array_values( $missing_files ),
		'featured_image_id'    => $featured_dest_id,
		'source_deleted'       => $source_deleted,
		'source_title'         => $source_post['post_title'],
	);
}

/**
 * Read the source post and collect every attachment it owns or references.
 *
 * Runs inside switch_to_blog( $source_blog_id ). Returns a normalized post
 * descriptor plus an id-keyed attachment map (file path, url, meta) and a list
 * of attachments whose underlying file is missing on disk.
 *
 * @param int $source_blog_id Source blog ID.
 * @param int $post_id        Post ID on the source blog.
 * @return array|WP_Error
 */
function ec_migrate_read_source( int $source_blog_id, int $post_id ) {
	switch_to_blog( $source_blog_id );
	try {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return new WP_Error(
				'ec_migrate_source_not_found',
				sprintf( 'Post %d not found on blog %d (or is an attachment).', $post_id, $source_blog_id ),
				array( 'status' => 404 )
			);
		}

		$content = (string) $post->post_content;

		// Gather attachment IDs: children (parent = post) + referenced in content.
		$attachment_ids = ec_migrate_collect_attachment_ids( $post_id, $content );

		$attachments   = array();
		$missing_files = array();

		foreach ( $attachment_ids as $att_id ) {
			$att = get_post( $att_id );
			if ( ! $att || 'attachment' !== $att->post_type ) {
				continue;
			}

			$file_path = get_attached_file( $att_id );
			$url       = (string) wp_get_attachment_url( $att_id );

			$descriptor = array(
				'id'          => (int) $att_id,
				'title'       => (string) $att->post_title,
				'caption'     => (string) $att->post_excerpt,
				'description' => (string) $att->post_content,
				'alt'         => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
				'mime'        => (string) $att->post_mime_type,
				'file'        => (string) $file_path,
				'filename'    => $file_path ? basename( $file_path ) : '',
				'url'         => $url,
			);

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				$missing_files[ $att_id ] = array(
					'id'       => (int) $att_id,
					'filename' => $descriptor['filename'],
					'url'      => $url,
					'reason'   => 'file missing on disk',
				);
				// Still record the descriptor so URL remaps can proceed even if
				// the binary is gone; it just won't be copied.
			}

			$attachments[ $att_id ] = $descriptor;
		}

		$featured_image_id = (int) get_post_thumbnail_id( $post_id );

		return array(
			'post'          => array(
				'post_title'        => (string) $post->post_title,
				'post_content'      => $content,
				'post_excerpt'      => (string) $post->post_excerpt,
				'post_author'       => (int) $post->post_author,
				'post_status'       => (string) $post->post_status,
				'post_type'         => (string) $post->post_type,
				'post_date'         => (string) $post->post_date,
				'post_date_gmt'     => (string) $post->post_date_gmt,
				'post_name'         => (string) $post->post_name,
				'featured_image_id' => $featured_image_id,
				'terms'             => ec_migrate_collect_terms( $post_id, (string) $post->post_type ),
			),
			'attachments'   => $attachments,
			'missing_files' => $missing_files,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Collect all attachment IDs owned by or referenced in a post.
 *
 * Owned: children where post_parent = $post_id.
 * Referenced: `"id":N` (block attrs / gallery ids arrays) and `wp-image-N`
 * classes found in the content.
 *
 * Must be called inside the source blog context.
 *
 * @param int    $post_id Source post ID.
 * @param string $content Source post content.
 * @return int[] Unique, sorted attachment IDs.
 */
function ec_migrate_collect_attachment_ids( int $post_id, string $content ): array {
	$ids = array();

	// Children (parent = post).
	$children = get_children(
		array(
			'post_parent'    => $post_id,
			'post_type'      => 'attachment',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( (array) $children as $child_id ) {
		$ids[] = (int) $child_id;
	}

	// Referenced in content.
	foreach ( ec_migrate_extract_referenced_ids( $content ) as $ref_id ) {
		$ids[] = (int) $ref_id;
	}

	$ids = array_values( array_unique( array_filter( $ids ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Extract attachment IDs referenced in post content.
 *
 * Pure string function (no WP calls) so it is unit-testable in isolation.
 * Matches:
 *   - `"id":N`         — image/media block attrs and gallery `ids` arrays.
 *   - `wp-image-N`     — the class core adds to inline <img> tags.
 *   - `data-id="N"`    — gallery item wrappers.
 *
 * @param string $content Post content (block markup / HTML).
 * @return int[] Unique referenced attachment IDs.
 */
function ec_migrate_extract_referenced_ids( string $content ): array {
	$ids = array();

	if ( '' === $content ) {
		return $ids;
	}

	// "id":N  (JSON block attributes, gallery ids arrays like "ids":[1,2,3]).
	if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $m ) ) {
		foreach ( $m[1] as $n ) {
			$ids[] = (int) $n;
		}
	}

	// "ids":[1,2,3] arrays — capture the array body then pull each number.
	if ( preg_match_all( '/"ids"\s*:\s*\[([0-9,\s]*)\]/', $content, $m ) ) {
		foreach ( $m[1] as $list ) {
			foreach ( preg_split( '/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $n ) {
				$ids[] = (int) $n;
			}
		}
	}

	// wp-image-N class on inline images.
	if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
		foreach ( $m[1] as $n ) {
			$ids[] = (int) $n;
		}
	}

	// data-id="N" gallery wrappers.
	if ( preg_match_all( '/data-id\s*=\s*["\'](\d+)["\']/', $content, $m ) ) {
		foreach ( $m[1] as $n ) {
			$ids[] = (int) $n;
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Collect terms for the post, grouped by taxonomy.
 *
 * Must be called inside the source blog context.
 *
 * @param int    $post_id   Source post ID.
 * @param string $post_type Source post type.
 * @return array taxonomy => array of term names (slugs preserved separately).
 */
function ec_migrate_collect_terms( int $post_id, string $post_type ): array {
	$out        = array();
	$taxonomies = get_object_taxonomies( $post_type );

	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		$rows = array();
		foreach ( $terms as $term ) {
			$rows[] = array(
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}
		$out[ $taxonomy ] = $rows;
	}

	return $out;
}

/**
 * Copy attachment files into the destination blog and create new attachments.
 *
 * For each source attachment with a present file, sideloads a copy into the
 * dest blog's uploads and inserts a new attachment (preserving title, alt,
 * caption, description, mime). Builds old_id=>new_id and old_url=>new_url maps.
 *
 * @param array $attachments  id => descriptor (from ec_migrate_read_source).
 * @param int   $dest_blog_id Destination blog ID.
 * @return array|WP_Error {
 *     @type array $attachment_map     old_id => new_id.
 *     @type array $url_map            old_url => new_url.
 *     @type int[] $new_attachment_ids New attachment IDs on dest.
 *     @type array $missing_files      Descriptors skipped due to missing file.
 * }
 */
function ec_migrate_copy_attachments( array $attachments, int $dest_blog_id ) {
	$attachment_map     = array();
	$url_map            = array();
	$new_attachment_ids = array();
	$missing_files      = array();

	if ( empty( $attachments ) ) {
		return array(
			'attachment_map'     => $attachment_map,
			'url_map'            => $url_map,
			'new_attachment_ids' => $new_attachment_ids,
			'missing_files'      => $missing_files,
		);
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	switch_to_blog( $dest_blog_id );
	try {
		foreach ( $attachments as $old_id => $desc ) {
			$old_id = (int) $old_id;

			if ( empty( $desc['file'] ) || ! file_exists( $desc['file'] ) ) {
				$missing_files[] = array(
					'id'       => $old_id,
					'filename' => $desc['filename'],
					'url'      => $desc['url'],
					'reason'   => 'file missing on disk',
				);
				continue;
			}

			// Copy the source file to a temp path so media_handle_sideload can
			// move it into the dest uploads dir without touching the original.
			$tmp = wp_tempnam( $desc['filename'] );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy failure is handled explicitly below; suppress the raw PHP warning.
			if ( ! $tmp || ! @copy( $desc['file'], $tmp ) ) {
				if ( $tmp && file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				$missing_files[] = array(
					'id'       => $old_id,
					'filename' => $desc['filename'],
					'url'      => $desc['url'],
					'reason'   => 'failed to stage temp copy',
				);
				continue;
			}

			$file_array = array(
				'name'     => $desc['filename'],
				'tmp_name' => $tmp,
			);

			$new_id = media_handle_sideload(
				$file_array,
				0,
				null,
				array(
					'post_title'   => $desc['title'],
					'post_excerpt' => $desc['caption'],
					'post_content' => $desc['description'],
				)
			);

			if ( is_wp_error( $new_id ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				// Record as missing/failed rather than aborting the whole run.
				$missing_files[] = array(
					'id'       => $old_id,
					'filename' => $desc['filename'],
					'url'      => $desc['url'],
					'reason'   => 'sideload failed: ' . $new_id->get_error_message(),
				);
				continue;
			}

			$new_id = (int) $new_id;

			// Preserve alt text + mime intent.
			if ( '' !== $desc['alt'] ) {
				update_post_meta( $new_id, '_wp_attachment_image_alt', $desc['alt'] );
			}

			$new_url = (string) wp_get_attachment_url( $new_id );

			$attachment_map[ $old_id ] = $new_id;
			$new_attachment_ids[]      = $new_id;
			if ( '' !== $desc['url'] && '' !== $new_url ) {
				$url_map[ $desc['url'] ] = $new_url;
			}
		}
	} finally {
		restore_current_blog();
	}

	return array(
		'attachment_map'     => $attachment_map,
		'url_map'            => $url_map,
		'new_attachment_ids' => $new_attachment_ids,
		'missing_files'      => $missing_files,
	);
}

/**
 * Rewrite post content so old attachment IDs and URLs point at dest equivalents.
 *
 * Pure string function (no WP calls) — the load-bearing correctness surface,
 * unit-tested directly. Handles:
 *   - `"id":OLD`          => `"id":NEW`   (image/media block attrs).
 *   - `"ids":[a,OLD,b]`   => remapped array (gallery block ids).
 *   - `wp-image-OLD`      => `wp-image-NEW` (inline <img> classes).
 *   - `data-id="OLD"`     => `data-id="NEW"`.
 *   - raw old URL         => new URL (covers `.../uploads/sites/<src>/...`).
 *
 * URL replacement runs first (longest URLs first to avoid partial overlap),
 * then ID token replacement using word-boundary-safe patterns so `123` never
 * clobbers `1234`.
 *
 * @param string $content        Original post content.
 * @param array  $attachment_map old_id => new_id.
 * @param array  $url_map        old_url => new_url.
 * @return string Rewritten content.
 */
function ec_migrate_rewrite_post_content( string $content, array $attachment_map, array $url_map ): string {
	if ( '' === $content ) {
		return $content;
	}

	// 1. Raw URL replacement first. Sort by descending length so a longer URL
	// (e.g. a sized variant) is replaced before a shorter prefix of it.
	if ( ! empty( $url_map ) ) {
		$urls = array_keys( $url_map );
		usort(
			$urls,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);
		foreach ( $urls as $old_url ) {
			if ( '' === (string) $old_url ) {
				continue;
			}
			$content = str_replace( $old_url, (string) $url_map[ $old_url ], $content );
		}
	}

	// 2. ID token replacement. Use placeholders to avoid chained remaps
	// (old A -> new B, then new B matched again as an old key).
	if ( ! empty( $attachment_map ) ) {
		$placeholders = array();
		$i            = 0;

		foreach ( $attachment_map as $old_id => $new_id ) {
			$old_id = (int) $old_id;
			$new_id = (int) $new_id;
			if ( $old_id <= 0 || $new_id <= 0 ) {
				continue;
			}

			$token                  = '%%EC_MIGRATE_ID_' . $i . '%%';
			$placeholders[ $token ] = (string) $new_id;
			++$i;

			// "id":OLD  (JSON attr; allow whitespace).
			$content = preg_replace(
				'/("id"\s*:\s*)' . $old_id . '(?!\d)/',
				'${1}' . $token,
				$content
			);

			// wp-image-OLD class.
			$content = preg_replace(
				'/(wp-image-)' . $old_id . '(?!\d)/',
				'${1}' . $token,
				$content
			);

			// data-id="OLD".
			$content = preg_replace(
				'/(data-id\s*=\s*["\'])' . $old_id . '(["\'])/',
				'${1}' . $token . '${2}',
				$content
			);

			// Gallery ids arrays: a bare OLD inside "ids":[...]. Match the id
			// as a standalone number bounded by non-digits (commas, brackets,
			// spaces). Applied globally but bounded so 12 != 123.
			$content = preg_replace(
				'/(?<!\d)' . $old_id . '(?!\d)/',
				$token,
				$content
			);
		}

		// Swap placeholders in for their final new IDs.
		if ( ! empty( $placeholders ) ) {
			$content = strtr( $content, $placeholders );
		}
	}

	return $content;
}

/**
 * Create the migrated post on the destination blog.
 *
 * Runs inside switch_to_blog( $dest_blog_id ). Preserves author, date, title,
 * excerpt; applies the resolved status; sets the (remapped) featured image;
 * re-parents the new attachments to the new post; re-applies terms for
 * taxonomies that exist on the dest blog; and stamps provenance meta.
 *
 * @param int    $dest_blog_id     Destination blog ID.
 * @param array  $source_post      Normalized source post descriptor.
 * @param string $new_content      Rewritten content.
 * @param string $dest_status      Resolved destination status.
 * @param int    $featured_dest_id Remapped featured image ID (0 if none).
 * @param int[]  $new_attachments  New attachment IDs to re-parent.
 * @param array  $provenance       { source_blog_id, source_post_id, migrated_by }.
 * @return int|WP_Error New post ID, or WP_Error.
 */
function ec_migrate_create_dest_post(
	int $dest_blog_id,
	array $source_post,
	string $new_content,
	string $dest_status,
	int $featured_dest_id,
	array $new_attachments,
	array $provenance
) {
	switch_to_blog( $dest_blog_id );
	try {
		$postarr = array(
			'post_title'   => $source_post['post_title'],
			'post_content' => $new_content,
			'post_excerpt' => $source_post['post_excerpt'],
			'post_status'  => $dest_status,
			'post_author'  => (int) $source_post['post_author'],
			'post_type'    => $source_post['post_type'],
			'post_date'    => $source_post['post_date'],
		);

		// Preserve the slug if it does not collide on the dest blog.
		if ( ! empty( $source_post['post_name'] ) ) {
			$postarr['post_name'] = $source_post['post_name'];
		}

		$new_post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$new_post_id = (int) $new_post_id;

		// Re-parent migrated attachments to the new post.
		foreach ( $new_attachments as $att_id ) {
			wp_update_post(
				array(
					'ID'          => (int) $att_id,
					'post_parent' => $new_post_id,
				)
			);
		}

		// Featured image (remapped).
		if ( $featured_dest_id > 0 ) {
			set_post_thumbnail( $new_post_id, $featured_dest_id );
		}

		// Re-apply terms for taxonomies that exist on the dest blog. Terms are
		// created on dest if missing (by slug) — non-existent taxonomies are
		// skipped rather than erroring.
		if ( ! empty( $source_post['terms'] ) && is_array( $source_post['terms'] ) ) {
			foreach ( $source_post['terms'] as $taxonomy => $rows ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$slugs = array();
				foreach ( $rows as $row ) {
					$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
					$name = isset( $row['name'] ) ? (string) $row['name'] : '';
					if ( '' === $slug && '' === $name ) {
						continue;
					}
					$term = $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;
					if ( ! $term && $name ) {
						$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
						if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
							$term = get_term( (int) $created['term_id'], $taxonomy );
						}
					}
					if ( $term && ! is_wp_error( $term ) ) {
						$slugs[] = $term->slug;
					}
				}
				if ( ! empty( $slugs ) ) {
					wp_set_object_terms( $new_post_id, $slugs, $taxonomy, false );
				}
			}
		}

		// Provenance meta — auditable record of the migration.
		update_post_meta( $new_post_id, '_ec_migrated_from_blog', (int) $provenance['source_blog_id'] );
		update_post_meta( $new_post_id, '_ec_migrated_from_post', (int) $provenance['source_post_id'] );
		update_post_meta( $new_post_id, '_ec_migrated_at', current_time( 'mysql', true ) );
		update_post_meta( $new_post_id, '_ec_migrated_by', (int) $provenance['migrated_by'] );

		return $new_post_id;
	} finally {
		restore_current_blog();
	}
}

/**
 * Delete the source post and its migrated attachments.
 *
 * Only ever called after a verified successful migration (never on dry-run or
 * partial failure). Force-deletes so nothing lingers in the source trash.
 *
 * @param int   $source_blog_id  Source blog ID.
 * @param int   $post_id         Source post ID.
 * @param int[] $attachment_ids  Source attachment IDs to delete.
 * @return bool|WP_Error True on success.
 */
function ec_migrate_delete_source( int $source_blog_id, int $post_id, array $attachment_ids ) {
	switch_to_blog( $source_blog_id );
	try {
		foreach ( $attachment_ids as $att_id ) {
			$att_id = (int) $att_id;
			if ( $att_id > 0 ) {
				wp_delete_attachment( $att_id, true );
			}
		}

		$deleted = wp_delete_post( $post_id, true );

		if ( ! $deleted ) {
			return new WP_Error(
				'ec_migrate_source_delete_failed',
				sprintf( 'Migrated dest post created, but deleting source post %d on blog %d failed.', $post_id, $source_blog_id )
			);
		}

		return true;
	} finally {
		restore_current_blog();
	}
}
