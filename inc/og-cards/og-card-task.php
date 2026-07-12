<?php
/**
 * OG Card Generation Task.
 *
 * SystemTask that renders an Open Graph card for a single post. Inherits
 * the Data Machine task machinery (jobs table, undo, CLI surface, admin
 * UI, settings toggle) by extending DataMachine\Engine\AI\System\Tasks\SystemTask.
 *
 * Unlike AI-driven tasks, this one is purely synchronous CPU work (GD
 * rasterization, ~50ms). The OG image filter calls render_for_post()
 * directly so the card is ready by the time the meta tag is emitted —
 * the SystemTask shell exists primarily to plug into the rest of DM's
 * task tooling (CLI run, undo, audit) without reinventing it.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OgCardGenerationTask extends SystemTask {

	/** @var string Post meta key holding the cached card URL. */
	public const META_URL = '_ec_og_card_url';

	/** @var string Post meta key holding the data signature at last render. */
	public const META_SIGNATURE = '_ec_og_card_signature';

	/** @var string Cache bucket directory under uploads/. */
	public const CACHE_BUCKET = 'og-cards';

	public function getTaskType(): string {
		return 'og_card_generation';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'OG Card Generation',
			'description'     => 'Render branded Open Graph cards for posts that lack a featured image.',
			'setting_key'     => 'og_card_generation_enabled',
			'default_enabled' => true,
			'trigger'         => 'On first share/crawler',
			'trigger_type'    => 'event',
			'supports_run'    => true,
		);
	}

	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * Execute card generation for a single post.
	 *
	 * Called by the DM job runner (e.g. when triggered via `wp datamachine
	 * system run og_card_generation --post_id=...`). The synchronous
	 * filter path bypasses this and calls render_for_post() directly.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params { post_id, force? }.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$post_id = absint( $params['post_id'] ?? 0 );
		$force   = ! empty( $params['force'] );

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->failJob( $jobId, "Post #{$post_id} not found" );
			return;
		}

		$result = self::render_for_post( $post, $force );

		if ( ! empty( $result['error'] ) ) {
			$this->failJob( $jobId, $result['error'] );
			return;
		}

		$effects = array();
		if ( ! empty( $result['cached_path'] ) ) {
			// Treat the cached file as a removable side-effect so the
			// generic SystemTask undo flow can clean it up on revert.
			$effects[] = array(
				'type'   => 'cached_file_created',
				'target' => array(
					'post_id'     => $post_id,
					'cached_path' => $result['cached_path'],
					'cached_url'  => $result['cached_url'],
				),
			);
		}

		$this->completeJob( $jobId, array(
			'post_id'      => $post_id,
			'cached_url'   => $result['cached_url'] ?? '',
			'cached_path'  => $result['cached_path'] ?? '',
			'reused_cache' => $result['reused_cache'] ?? false,
			'effects'      => $effects,
			'completed_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Render an OG card for a post and cache the result.
	 *
	 * Returns the cached URL and absolute path on success. Reuses the
	 * existing cache when the data signature hasn't changed unless $force
	 * is true.
	 *
	 * @param \WP_Post $post  Post object.
	 * @param bool     $force Force regeneration even if cache is valid.
	 * @return array { cached_url?, cached_path?, reused_cache?, error? }
	 */
	public static function render_for_post( \WP_Post $post, bool $force = false ): array {
		$template_id = template_id_for_post( $post );
		if ( null === $template_id ) {
			return array( 'error' => "No OG card template registered for post type '{$post->post_type}'" );
		}

		$data = resolve_card_data( $post );
		if ( empty( $data ) ) {
			return array( 'error' => "No data collector returned data for post #{$post->ID}" );
		}

		$signature       = self::signature_for( $data );
		$existing_url    = (string) get_post_meta( $post->ID, self::META_URL, true );
		$existing_sig    = (string) get_post_meta( $post->ID, self::META_SIGNATURE, true );
		$cached_path_for = self::cached_path_for( $post );

		if ( ! $force && $existing_url && $existing_sig === $signature && file_exists( $cached_path_for ) ) {
			return array(
				'cached_url'   => $existing_url,
				'cached_path'  => $cached_path_for,
				'reused_cache' => true,
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array( 'error' => 'Abilities API not available' );
		}

		$ability = wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			return array( 'error' => 'datamachine/render-image-template ability not registered' );
		}

		$result = $ability->execute(
			array(
				'template_id' => $template_id,
				'data'        => $data,
				'preset'      => 'open_graph',
				'format'      => 'png',
				'output'      => 'cached_file',
				'cache'       => array(
					'bucket' => self::CACHE_BUCKET,
					'key'    => self::cache_key_for( $post ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'Ability execution failed: ' . $result->get_error_message() );
		}

		if ( empty( $result['cached_urls'][0] ) ) {
			return array( 'error' => $result['message'] ?? 'Render produced no cached file' );
		}

		$url  = (string) $result['cached_urls'][0];
		$path = (string) ( $result['cached_paths'][0] ?? '' );

		update_post_meta( $post->ID, self::META_URL, $url );
		update_post_meta( $post->ID, self::META_SIGNATURE, $signature );

		return array(
			'cached_url'   => $url,
			'cached_path'  => $path,
			'reused_cache' => false,
		);
	}

	/**
	 * Stable cache key for a post (used as the file stem).
	 *
	 * Includes the blog ID so multisite cards from different sites do
	 * not collide when the bucket is on shared storage.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public static function cache_key_for( \WP_Post $post ): string {
		return sprintf( 'b%d-%s-%d', (int) get_current_blog_id(), $post->post_type, $post->ID );
	}

	/**
	 * Absolute filesystem path the cache file would live at.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public static function cached_path_for( \WP_Post $post ): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::CACHE_BUCKET . '/' . self::cache_key_for( $post ) . '.png';
	}

	/**
	 * Hash the data payload to detect when a regen is needed.
	 *
	 * @param array $data Data array.
	 * @return string
	 */
	public static function signature_for( array $data ): string {
		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Drop the cached card file + meta when post data changes.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate( int $post_id ): void {
		$path = (string) get_post_meta( $post_id, self::META_URL, true );
		if ( '' !== $path ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$file = self::cached_path_for( $post );
				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		delete_post_meta( $post_id, self::META_URL );
		delete_post_meta( $post_id, self::META_SIGNATURE );
	}
}
