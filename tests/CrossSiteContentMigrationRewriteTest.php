<?php
/**
 * Tests for the pure content-rewrite / attachment-id remap logic used by the
 * cross-site content migration primitive.
 *
 * Covers the two pure (WP-free) functions in
 * inc/core/cross-site-content-migration.php:
 *   - ec_migrate_extract_referenced_ids() — pulls attachment IDs out of block
 *     markup and inline HTML.
 *   - ec_migrate_rewrite_post_content()   — remaps old attachment IDs and URLs
 *     to their new dest equivalents.
 *
 * These are the load-bearing correctness surface of the migration: if the
 * remap is wrong, a migrated post silently points at the source blog's media.
 * Both functions are pure strings-in / strings-out, so this suite runs without
 * any DB writes, switch_to_blog(), or file I/O.
 *
 * @package ExtraChill\Multisite
 */

declare( strict_types=1 );

/**
 * Content-rewrite / id-remap tests.
 *
 * @group cross-site-migration
 */
class CrossSiteContentMigrationRewriteTest extends WP_UnitTestCase {

	/**
	 * Ensure the pure functions are loaded (the file loads unconditionally in
	 * production; require it here so the suite is self-contained).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		$file = dirname( __DIR__ ) . '/inc/core/cross-site-content-migration.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Image block `"id":N` is extracted.
	 */
	public function test_extract_image_block_id(): void {
		$content = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="x.jpg" class="wp-image-42"/></figure><!-- /wp:image -->';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 42, $ids );
	}

	/**
	 * Gallery `"ids":[...]` arrays are extracted individually.
	 */
	public function test_extract_gallery_ids_array(): void {
		$content = '<!-- wp:gallery {"ids":[10,11,12]} -->';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 10, $ids );
		$this->assertContains( 11, $ids );
		$this->assertContains( 12, $ids );
	}

	/**
	 * The wp-image-N classes and data-id="N" wrappers are extracted.
	 */
	public function test_extract_wp_image_class_and_data_id(): void {
		$content = '<img class="wp-image-77" /><li data-id="88"></li>';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 77, $ids );
		$this->assertContains( 88, $ids );
	}

	/**
	 * Empty content yields no ids.
	 */
	public function test_extract_empty_content(): void {
		$this->assertSame( array(), ec_migrate_extract_referenced_ids( '' ) );
	}

	/**
	 * Image block id + wp-image class both remap to the new id.
	 */
	public function test_rewrite_image_block_and_class(): void {
		$content = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="x.jpg" class="wp-image-42"/></figure><!-- /wp:image -->';
		$out     = ec_migrate_rewrite_post_content( $content, array( 42 => 500 ), array() );

		$this->assertStringContainsString( '"id":500', $out );
		$this->assertStringContainsString( 'wp-image-500', $out );
		$this->assertStringNotContainsString( '"id":42', $out );
		$this->assertStringNotContainsString( 'wp-image-42', $out );
	}

	/**
	 * Gallery ids array remaps every member.
	 */
	public function test_rewrite_gallery_ids_array(): void {
		$content = '<!-- wp:gallery {"ids":[10,11,12]} -->';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				10 => 110,
				11 => 111,
				12 => 112,
			),
			array()
		);

		$this->assertStringContainsString( '110', $out );
		$this->assertStringContainsString( '111', $out );
		$this->assertStringContainsString( '112', $out );
		$this->assertStringNotContainsString( '[10,11,12]', $out );
	}

	/**
	 * Raw upload URLs (including the multisite `sites/<n>/` segment) are remapped.
	 */
	public function test_rewrite_raw_sites_url(): void {
		$old_url = 'https://studio.extrachill.com/wp-content/uploads/sites/12/2026/01/photo.jpg';
		$new_url = 'https://extrachill.com/wp-content/uploads/2026/01/photo.jpg';
		$content = '<img src="' . $old_url . '" class="wp-image-42" />';

		$out = ec_migrate_rewrite_post_content(
			$content,
			array( 42 => 500 ),
			array( $old_url => $new_url )
		);

		$this->assertStringContainsString( $new_url, $out );
		$this->assertStringNotContainsString( $old_url, $out );
		$this->assertStringContainsString( 'wp-image-500', $out );
	}

	/**
	 * A short id must NOT clobber a longer id that contains it (12 vs 123).
	 */
	public function test_rewrite_id_boundaries_no_substring_clobber(): void {
		$content = '<!-- wp:image {"id":12} --><img class="wp-image-123" />';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				12  => 900,
				123 => 901,
			),
			array()
		);

		$this->assertStringContainsString( '"id":900', $out );
		$this->assertStringContainsString( 'wp-image-901', $out );
		// The 12 remap must not have turned 123 into 9003 or similar.
		$this->assertStringNotContainsString( 'wp-image-9003', $out );
		$this->assertStringNotContainsString( 'wp-image-12', $out );
	}

	/**
	 * A remap where the new id equals another old id must not chain-remap.
	 * old 5 -> new 6, old 6 -> new 7. The block with id 5 must land on 6,
	 * NOT get re-caught by the 6->7 rule and become 7.
	 */
	public function test_rewrite_no_chained_remap(): void {
		$content = '<!-- wp:image {"id":5} --><!-- wp:image {"id":6} -->';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				5 => 6,
				6 => 7,
			),
			array()
		);

		$this->assertStringContainsString( '"id":6', $out );
		$this->assertStringContainsString( '"id":7', $out );
		// Exactly one occurrence each — the 5->6 result was not re-caught by 6->7.
		$this->assertSame( 1, substr_count( $out, '"id":6' ) );
		$this->assertSame( 1, substr_count( $out, '"id":7' ) );
	}

	/**
	 * Empty maps are a no-op.
	 */
	public function test_rewrite_empty_maps_noop(): void {
		$content = '<!-- wp:image {"id":42} -->';
		$this->assertSame( $content, ec_migrate_rewrite_post_content( $content, array(), array() ) );
	}
}
