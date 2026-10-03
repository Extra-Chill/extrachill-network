<?php
/**
 * Seed the Gardner Studio social-operator journey on the real network boot.
 *
 * Ported from extrachill-studio tests/wp-codebox/chris-gardner-social-operator-setup.php
 * (extrachill-network#293) onto the full 11-site network rig. What the old
 * single-plugin recipe had to do for itself, this rig already does:
 *
 * - No `wp core multisite-convert` / manual network-activation workflow
 *   steps: this rig already boots a real 11-site multisite network with
 *   Extra Chill Network, API, Users, Analytics, Data Machine, Data Machine
 *   Socials, and Extra Chill Studio all real, network- or per-site-active
 *   components (see components.json).
 * - No `ec_get_blog_id()` single-site stub: the real function
 *   (extrachill-network) is loaded network-wide.
 * - The canonical Gardner identity now comes from the pinned persona
 *   (personas/gardner.v1.json) at the SAME forced user ID
 *   (201) the gardner-event-rsvp journey uses -- multisite users are
 *   global, so this is the same conceptual person, not a coincidence.
 *
 * Extra Chill Studio and Data Machine Socials are per-site plugins
 * (activation scope: studio.extrachill.com, per components.json).
 * WordPress.run-php executes against the primary site by default, where
 * per-site plugins never load -- switch_to_blog() swaps DB context only.
 * This seed bootstraps Studio's own plugin context explicitly, the same
 * constraint the gardner-event-rsvp journey documents for the events site.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID of the site.
 */
function ec_rig_social_operator_site_id( string $domain ): int {
	$sites = get_sites( array(
		'domain' => $domain,
		'number' => 1,
	) );
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey seed could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/**
 * Bootstrap the studio site's per-site plugin context and Data Machine's
 * task/step registries. Idempotent: safe to call again from deliver.php and
 * grade.php, each its own separate WordPress.run-php process.
 */
function ec_rig_social_operator_bootstrap(): void {
	if ( ! class_exists( '\\DataMachine\\Core\\Bootstrap\\ActivationServiceProvider' ) || ! class_exists( '\\DataMachineSocials\\Handlers\\Instagram\\InstagramAuth' ) ) {
		throw new RuntimeException( 'The real Data Machine and Data Machine Socials runtime is required (network-active components.json entries).' );
	}
	require_once WP_PLUGIN_DIR . '/extrachill-studio/extrachill-studio.php';
	if ( ! function_exists( 'ec_grant_brand_socials' ) ) {
		throw new RuntimeException( 'ec_grant_brand_socials() is unavailable -- Extra Chill Users is not loaded.' );
	}
	if ( ! defined( 'EXTRACHILL_STUDIO_VERSION' ) || ! function_exists( 'ExtraChillStudio\\enqueue_social_publish' ) ) {
		throw new RuntimeException( 'The mounted Extra Chill Studio runtime is not active.' );
	}

	\DataMachine\Core\Bootstrap\ActivationServiceProvider::ensure_all_tables();
	if ( function_exists( 'datamachine_register_core_actions' ) ) {
		datamachine_register_core_actions();
	}
	new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();
	if ( function_exists( 'datamachine_socials_bootstrap' ) ) {
		datamachine_socials_bootstrap();
	}
	\DataMachine\Engine\Tasks\TaskRegistry::reset();
}

$studio_blog_id = ec_rig_social_operator_site_id( 'studio.extrachill.com' );

switch_to_blog( $studio_blog_id );
ec_rig_social_operator_bootstrap();

const GARDNER_USER_ID  = 201; // Same canonical Gardner identity as gardner-event-rsvp; multisite users are global.
const ORDINARY_USER_ID = 220;

$users = array(
	GARDNER_USER_ID  => array(
		'login'   => 'gardner_persona_fixture',
		'display' => 'Chris Gardner (Test Persona)',
		'role'    => 'editor',
	),
	ORDINARY_USER_ID => array(
		'login'   => 'ordinary_team_operator_fixture',
		'display' => 'Ordinary Team User (Test Persona)',
		'role'    => 'author',
	),
);
foreach ( $users as $user_id => $spec ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		global $wpdb;
		$created = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $spec['login'],
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $spec['login'],
				'user_email'      => $spec['login'] . '@example.invalid',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $spec['display'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $created ) {
			throw new RuntimeException( esc_html( 'Unable to create operator user ' . $user_id . '.' ) );
		}
		clean_user_cache( $user_id );
	}
	if ( ! is_user_member_of_blog( $user_id, $studio_blog_id ) ) {
		add_user_to_blog( $studio_blog_id, $user_id, $spec['role'] );
	}
	$user = new WP_User( $user_id );
	$user->set_role( $spec['role'] );
}

// Canonical team identity and grants come from Extra Chill Users; Studio
// only adds the scenario's own tool-use capability.
$gardner = new WP_User( GARDNER_USER_ID );
if ( get_role( 'extra_chill_team' ) ) {
	$gardner->add_role( 'extra_chill_team' );
}
$gardner->add_cap( 'datamachine_use_tools' );
$ordinary = new WP_User( ORDINARY_USER_ID );
if ( get_role( 'extra_chill_team' ) ) {
	$ordinary->add_role( 'extra_chill_team' );
}
$ordinary->add_cap( 'datamachine_use_tools' );
ec_grant_brand_socials( GARDNER_USER_ID );
ec_revoke_brand_socials( ORDINARY_USER_ID );

// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_brand_socials/access_studio are extrachill-users/extrachill-studio capabilities, unknown to this repo's own ruleset.
if ( ! user_can( GARDNER_USER_ID, 'manage_brand_socials' ) || ! user_can( GARDNER_USER_ID, 'access_studio' )
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_brand_socials/access_studio are extrachill-users/extrachill-studio capabilities, unknown to this repo's own ruleset.
	|| user_can( ORDINARY_USER_ID, 'manage_brand_socials' ) || ! user_can( ORDINARY_USER_ID, 'access_studio' ) ) {
	throw new RuntimeException( 'Canonical team/grant capability fixture is invalid.' );
}

$execution_owner_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
$agents                  = new \DataMachine\Core\Database\Agents\Agents();
$owned_agents            = $agents->get_all_by_owner_id( $execution_owner_user_id );
if ( ! empty( $owned_agents ) ) {
	$agent_id   = (int) $owned_agents[0]['agent_id'];
	$agent_slug = (string) $owned_agents[0]['agent_slug'];
} else {
	$agent_slug = 'studio-social-operator-owner';
	$agent_id   = $agents->create_if_missing( $agent_slug, 'Studio Social Operator Owner', $execution_owner_user_id );
}
if ( $agent_id <= 0 ) {
	throw new RuntimeException( 'Unable to create the stable Data Machine execution owner.' );
}
update_user_meta( $execution_owner_user_id, \DataMachine\Core\Agents\AgentBundler::ACTIVE_AGENT_META_KEY, $agent_slug );

$instagram = new \DataMachineSocials\Handlers\Instagram\InstagramAuth();
$instagram->save_config( array(
	'app_id'     => 'fixture-instagram-app',
	'app_secret' => 'fixture-instagram-secret',
) );
$instagram->save_account(
	array(
		'access_token'     => 'fixture-instagram-access-token',
		'token_expires_at' => time() + MONTH_IN_SECONDS,
		'user_id'          => '17841400000000000',
		'username'         => 'extrachill',
	)
);
$bluesky = new \DataMachineSocials\Handlers\Bluesky\BlueskyAuth();
$bluesky->save_config( array(
	'username'     => 'extrachill.com',
	'app_password' => 'fixture-bluesky-app-password',
) );

require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Create one deterministic public JPEG fixture attachment on the CURRENT
 * blog and return its NetworkMediaItem-shaped reference
 * (`source_id` = "<blog_id>:<attachment_id>", matching how Studio's own
 * `social_asset_refs_from_studio_images()` resolves media against whichever
 * site's own library the caller currently has switched into).
 *
 * @param string $slug Fixture filename slug.
 * @param int[]  $rgb  RGB color.
 * @return array{id:int,source_id:string,url:string}
 */
function ec_rig_social_operator_create_media( string $slug, array $rgb ): array {
	$uploads    = wp_upload_dir();
	$image_path = trailingslashit( $uploads['path'] ) . 'gardner-social-operator-' . $slug . '.jpg';
	$image      = imagecreatetruecolor( 1200, 800 );
	$color      = imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
	imagefill( $image, 0, 0, $color );
	imagejpeg( $image, $image_path, 88 );
	imagedestroy( $image );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Gardner operator ' . $slug,
			'post_status'    => 'inherit',
		),
		$image_path
	);
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		throw new RuntimeException( 'Unable to create public JPEG fixture.' );
	}
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $image_path ) );
	return array(
		'id'        => (int) $attachment_id,
		'source_id' => get_current_blog_id() . ':' . $attachment_id,
		'url'       => (string) wp_get_attachment_url( $attachment_id ),
	);
}

/*
 * ---------------------------------------------------------------------------
 * The canonical article lives on the REAL main site (extrachill.com), not on
 * studio.extrachill.com -- Studio's own social_source_attribution() (Studio
 * inc/social-drafts.php) validates a draft's declared source_post_id/
 * source_url by switching to ec_get_blog_id('main') and comparing the
 * ACTUAL post's permalink there. Creating the article on studio's own
 * database (as an earlier version of this seed did) makes that lookup miss
 * on the real network and fail the whole delivery with
 * social_publish_attribution_invalid -- a real cross-site identity
 * requirement the old single-site sandbox could not surface, because a
 * single-site multisite-convert made "studio" and "main" the same blog.
 * ---------------------------------------------------------------------------
 */
$main_blog_id = ec_rig_social_operator_site_id( 'extrachill.com' );
switch_to_blog( $main_blog_id );
$article_media = ec_rig_social_operator_create_media( 'crowd', array( 25, 38, 66 ) );
$article_id    = wp_insert_post(
	array(
		'post_title'   => 'Gardner Operator Canonical Article',
		'post_excerpt' => 'A canonical Extra Chill article for stateful social operations.',
		'post_content' => '<p>A canonical Extra Chill article for stateful social operations.</p>',
		'post_status'  => 'publish',
		'post_author'  => GARDNER_USER_ID,
	),
	true
);
if ( is_wp_error( $article_id ) ) {
	restore_current_blog();
	throw new RuntimeException( esc_html( 'Unable to create canonical article: ' . $article_id->get_error_message() ) );
}
set_post_thumbnail( $article_id, $article_media['id'] );
$article_permalink = (string) get_permalink( $article_id );
restore_current_blog();

/*
 * The Studio draft, and the media Gardner actually attaches to the social
 * post, live on studio.extrachill.com -- its own composer's own media
 * library, independent of the article's own featured image.
 */
$draft_media_original = ec_rig_social_operator_create_media( 'stage-original', array( 90, 90, 90 ) );
$draft_media_approved = ec_rig_social_operator_create_media( 'stage', array( 113, 45, 189 ) );

$draft_id = wp_insert_post(
	array(
		'post_title'  => 'Gardner Instagram and Bluesky Review',
		'post_status' => 'pending',
		'post_author' => GARDNER_USER_ID,
	),
	true
);
if ( is_wp_error( $draft_id ) ) {
	throw new RuntimeException( esc_html( 'Unable to create pending Studio social draft: ' . $draft_id->get_error_message() ) );
}

$original_caption = 'Initial caption Gardner changes before approval.';
$approved_caption = 'Edited and approved: local music belongs to the people who build the scene.';
update_post_meta( $draft_id, '_studio_social_platforms', array( 'instagram', 'bluesky' ) );
update_post_meta( $draft_id, '_studio_social_caption', $original_caption );
update_post_meta( $draft_id, '_studio_social_media_kind', 'image' );
update_post_meta( $draft_id, '_studio_social_images', array( $draft_media_original ) );
update_post_meta( $draft_id, '_studio_social_source_post_id', (int) $article_id );
update_post_meta( $draft_id, '_studio_social_source_url', $article_permalink );

// Gardner changes both caption and media before approval; these values become frozen input.
update_post_meta( $draft_id, '_studio_social_caption', $approved_caption );
update_post_meta( $draft_id, '_studio_social_images', array( $draft_media_approved ) );
$media = array( $draft_media_original, $draft_media_approved );

// The Studio app's own front page, matching production's real single-page
// operator surface. Idempotent: reuse an existing front page if one is
// already the [extrachill/studio] block, matching the site's own real
// configuration rather than clobbering it.
$existing_front_id = (int) get_option( 'page_on_front' );
$existing_front    = $existing_front_id > 0 ? get_post( $existing_front_id ) : null;
if ( 'page' === get_option( 'show_on_front' ) && $existing_front instanceof WP_Post && has_block( 'extrachill/studio', $existing_front ) ) {
	$page_id = $existing_front_id;
} else {
	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Studio Social Operator',
			'post_content' => '<!-- wp:extrachill/studio /-->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( esc_html( 'Unable to create Studio operator page: ' . $page_id->get_error_message() ) );
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $page_id );
}

$state = array(
	'schema'                      => 'extrachill-network/journey-fixture/gardner-social-operator/v1',
	'scenario'                    => 'studio-social-operations',
	'canonical_identity_contract' => array(
		'id'      => 'extra-chill-users/chris-gardner',
		'version' => '1.0.0',
	),
	'studio_blog_id'              => $studio_blog_id,
	'gardner_user_id'             => GARDNER_USER_ID,
	'ordinary_user_id'            => ORDINARY_USER_ID,
	'execution_owner_user_id'     => $execution_owner_user_id,
	'execution_owner_agent_id'    => $agent_id,
	'article_id'                  => (int) $article_id,
	'draft_id'                    => (int) $draft_id,
	'page_id'                     => (int) $page_id,
	'media'                       => $media,
	'original_caption'            => $original_caption,
	'approved_caption'            => $approved_caption,
);
update_option( 'ec_rig_journey_fixture_gardner_social_operator', $state, false );
update_option( 'ec_studio_operator_provider_ledger', array(), false );
update_option(
	'ec_studio_operator_provider_state',
	array(
		'bluesky_failures_remaining' => 1,
		'comments_mode'              => 'page',
	),
	false
);
update_option(
	'ec_studio_operator_transition_ledger',
	array(
		array(
			'state'        => 'pending',
			'caption_hash' => hash( 'sha256', $original_caption ),
			'media'        => $media[0]['source_id'],
		),
		array(
			'state'        => 'approved-edit',
			'caption_hash' => hash( 'sha256', $approved_caption ),
			'media'        => $media[1]['source_id'],
		),
	),
	false
);

restore_current_blog();

$fixture_evidence = array(
	'schema'          => $state['schema'],
	'article_id'      => $article_id,
	'draft_id'        => $draft_id,
	'page_id'         => $page_id,
	'media_refs'      => array_column( $media, 'source_id' ),
	'external_writes' => false,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $fixture_evidence ) ) );
