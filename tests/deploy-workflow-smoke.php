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

dws_assert( str_contains( $yaml, 'cp -r "${GITHUB_WORKSPACE}/deploy/homeboy" "${HOME}/.config/homeboy"' ), 'checked-in config is materialized into $HOME/.config/homeboy (Homeboy ignores XDG_CONFIG_HOME)' );
$xdg_use = array_filter( $lines, static fn( $l ) => str_contains( $l, 'XDG_CONFIG_HOME' ) && ! str_starts_with( ltrim( $l ), '#' ) );
dws_assert( empty( $xdg_use ), 'workflow does not rely on XDG_CONFIG_HOME' );
dws_assert( str_contains( $yaml, '--confirm-dangerous' ), 'rollback path uses --confirm-dangerous with --ref' );
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
dws_assert( ( $p['components'] ?? null ) === array(), 'project ships with no component attachments' );
dws_assert( ( $s['id'] ?? null ) === 'hetzner' && array_key_exists( 'identity_file', $s ) && null === $s['identity_file'], 'server has null identity_file (key comes from ssh-key)' );
dws_assert( '' === ( $p['database']['name'] ?? 'x' ) && '' === ( $p['database']['user'] ?? 'x' ), 'no database credentials committed' );
dws_assert( false === ( $p['api']['enabled'] ?? true ), 'project api disabled' );

if ( $failures ) {
	echo count( $failures ) . " failure(s).\n";
	exit( 1 );
}
echo "All deploy workflow checks passed.\n";
