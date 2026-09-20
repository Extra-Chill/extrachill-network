<?php
/**
 * Shape checks for the push-based network deploy workflow and its checked-in
 * Homeboy config. Line-based on purpose: no ext-yaml dependency.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

$root     = dirname( __DIR__ );
$workflow = $root . '/.github/workflows/deploy.yml';
$project  = $root . '/deploy/homeboy/projects/extrachill-site/extrachill-site.json';
$server   = $root . '/deploy/homeboy/servers/hetzner.json';
$failures = array();

function dws_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

dws_assert( is_file( $workflow ), 'deploy.yml exists' );
$yaml  = (string) file_get_contents( $workflow );
$lines = preg_split( '/\R/', $yaml ) ?: array();

dws_assert( (bool) preg_match( '/^\s+repository_dispatch:\s*$/m', $yaml ), 'repository_dispatch trigger present' );
dws_assert( (bool) preg_match( '/^\s+types:\s*\[component-released\]/m', $yaml ), 'repository_dispatch listens for component-released' );
dws_assert( (bool) preg_match( '/^\s+workflow_dispatch:\s*$/m', $yaml ), 'workflow_dispatch trigger present' );
dws_assert( (bool) preg_match( '/^\s+schedule:\s*$/m', $yaml ), 'schedule trigger present' );
dws_assert( (bool) preg_match( '/^concurrency:\s*\n\s+group:\s*deploy-extrachill-site\s*\n\s+cancel-in-progress:\s*false/m', $yaml ), 'deploys queue under one concurrency group' );

// permissions block contains only contents: read.
preg_match( '/^permissions:\s*\n((?:\s{2,}\S.*\n)+)/m', $yaml, $perm );
$perm_lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $perm[1] ?? '' ) ?: array() ) ) );
dws_assert( $perm_lines === array( 'contents: read' ), 'permissions is exactly contents: read' );

$uses_with_expr = array_filter( $lines, static fn( $l ) => preg_match( '/^\s*-?\s*uses:.*\$\{\{/', $l ) === 1 );
dws_assert( empty( $uses_with_expr ), 'no uses: line contains an expression' );

// Every homeboy-action step carries the SSH inputs.
$action_steps = preg_split( '/(?=^\s*-\s*name:)/m', $yaml ) ?: array();
$action_steps = array_filter( $action_steps, static fn( $s ) => preg_match( '/^\s*uses: Extra-Chill\/homeboy-action@v2\s*$/m', $s ) === 1 );
$missing_ssh  = array_filter( $action_steps, static fn( $s ) => ! str_contains( $s, 'ssh-key: ${{ secrets.EXTRACHILL_DEPLOY_SSH_KEY }}' ) || ! str_contains( $s, 'ssh-known-hosts: ${{ secrets.EXTRACHILL_DEPLOY_KNOWN_HOSTS }}' ) );
dws_assert( count( $action_steps ) >= 2, 'at least deploy and verify homeboy-action steps' );
dws_assert( empty( $missing_ssh ), 'every homeboy-action step passes ssh-key and ssh-known-hosts' );

dws_assert( str_contains( $yaml, 'HOMEBOY_CONFIG_ROOT: ${{ github.workspace }}/deploy/homeboy' ), 'HOMEBOY_CONFIG_ROOT points at the checked-in config (homeboy#14783)' );
// runner.* and github.workspace are job-scoped contexts; a workflow-level env
// referencing them makes GitHub reject the whole file (zero-job failed run).
preg_match( '/^env:\s*\n((?:\s{2,}\S.*\n)+)/m', $yaml, $top_env );
dws_assert( ! preg_match( '/\$\{\{\s*(runner\.|github\.workspace)/', $top_env[1] ?? '' ), 'workflow-level env does not use job-scoped contexts (runner.*, github.workspace)' );
// runner.* is step-scoped only: not valid in workflow- or job-level env.
preg_match( '/^    env:\s*\n((?:\s{6,}\S.*\n)+)/m', $yaml, $job_env );
dws_assert( ! preg_match( '/\$\{\{\s*runner\./', $job_env[1] ?? '' ), 'job-level env does not use the step-scoped runner context' );
$clone_steps = array_filter( $lines, static fn( $l ) => preg_match( '/^\s*repository:\s*\$\{\{/', $l ) === 1 );
dws_assert( empty( $clone_steps ), 'workflow never clones a component (checkout-less deploy, homeboy#14782)' );
dws_assert( str_contains( $yaml, 'deploy extrachill-site --outdated' ), 'scheduled --outdated poll is enabled' );
// homeboy#14813: an all-skipped poll reports "No outdated components found",
// which reads like success. A silent no-op loop is worse than a loud failure.
dws_assert( str_contains( $yaml, 'Reject a poll that resolved nothing' ), 'an all-skipped poll is rejected rather than reported as up to date' );
dws_assert( str_contains( $yaml, 'homeboy#14813' ), 'the guard cites the upstream issue it compensates for' );
// Unattended runs must be gated behind an explicit repository variable so the
// schedule cannot start mutating the server the moment it is merged.
dws_assert( str_contains( $yaml, 'AUTOMATION: ${{ vars.DEPLOY_AUTOMATION }}' ), 'unattended deploys are gated on the DEPLOY_AUTOMATION repository variable' );
dws_assert( str_contains( $yaml, 'if [ "${AUTOMATION:-}" != "enabled" ]' ), 'anything other than "enabled" keeps unattended runs in plan-only mode' );
dws_assert( (bool) preg_match( '/workflow_dispatch\)\s*\n\s+component="\$\{M_COMPONENT\}"; version="\$\{M_VERSION\}"; dry_run="\$\{M_DRY_RUN\}"/', $yaml ), 'manual dispatch is never gated by DEPLOY_AUTOMATION' );
dws_assert( (bool) preg_match( '/name:\s*deploy-evidence-\$\{\{ github\.run_id \}\}/', $yaml ), 'evidence artifact uploaded' );
dws_assert( str_contains( $yaml, 'GATE(extrachill-network#223)' ), 'rig gate placeholder present' );

// Checked-in Homeboy config.
dws_assert( is_file( $project ), 'project config exists' );
dws_assert( is_file( $server ), 'server config exists' );
$p = json_decode( (string) file_get_contents( $project ), true );
$s = json_decode( (string) file_get_contents( $server ), true );
dws_assert( is_array( $p ) && is_array( $s ), 'project and server configs parse as JSON' );
dws_assert( ( $p['server_id'] ?? null ) === 'hetzner', 'project targets server hetzner' );
dws_assert( ( $p['base_path'] ?? null ) === '/var/www/extrachill.com', 'project base_path is the site root' );
$attachments = $p['components'] ?? null;
dws_assert( is_array( $attachments ) && count( $attachments ) >= 30, 'project attaches the deployable component set' );
// Only components whose repositories cut GitHub Releases can be polled.
$not_releasing = array_filter( (array) $attachments, static fn( $c ) => in_array( $c['id'], array( 'chubes-gallery-lightbox', 'intelligence', 'wp-native-auth' ), true ) );
dws_assert( empty( $not_releasing ), 'components without usable GitHub Releases are not attached (homeboy#14813)' );
// local_path must be empty: the runner has no checkouts and Homeboy resolves
// each component from its GitHub Release (homeboy#14782). The key is still
// present as "" until homeboy#14795 ships serde(default).
$bad_attach = array_filter( (array) $attachments, static fn( $c ) => ! isset( $c['id'], $c['remote_path'] ) || '' !== ( $c['local_path'] ?? '' ) || ! preg_match( '#^wp-content/(plugins|themes|mu-plugins)/[a-z0-9-]+$#', $c['remote_path'] ) );
dws_assert( empty( $bad_attach ), 'every attachment has id + wp-content remote_path and an empty local_path' );
$registry_dir = $root . '/deploy/homeboy/components';
$missing_reg  = array_filter( (array) $attachments, static fn( $c ) => ! is_file( $registry_dir . '/' . $c['id'] . '.json' ) );
dws_assert( empty( $missing_reg ), 'every attachment has a standalone registry entry' );
$bad_reg = array();
foreach ( glob( $registry_dir . '/*.json' ) ?: array() as $f ) {
	$r = json_decode( (string) file_get_contents( $f ), true );
	if ( ! is_array( $r ) || ( $r['id'] ?? null ) !== basename( $f, '.json' ) || ! preg_match( '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) ( $r['remote_url'] ?? '' ) ) || isset( $r['local_path'] ) ) {
		$bad_reg[] = basename( $f );
	}
}
dws_assert( empty( $bad_reg ), 'every registry entry has a GitHub remote_url, matching id, and no local_path' );
dws_assert( ( $s['id'] ?? null ) === 'hetzner' && array_key_exists( 'identity_file', $s ) && null === $s['identity_file'], 'server has null identity_file (key comes from ssh-key)' );
// CI must connect as the restricted deploy account, never as an operator
// account with broader reach than wp-content/{plugins,themes,mu-plugins}.
dws_assert( ( $s['user'] ?? null ) === 'deploy', 'server targets the restricted deploy user' );
dws_assert( '' === ( $p['database']['name'] ?? 'x' ) && '' === ( $p['database']['user'] ?? 'x' ), 'no database credentials committed' );
dws_assert( false === ( $p['api']['enabled'] ?? true ), 'project api disabled' );

if ( $failures ) {
	echo count( $failures ) . " failure(s).\n";
	exit( 1 );
}
echo "All deploy workflow checks passed.\n";
