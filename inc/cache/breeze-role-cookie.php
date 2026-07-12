<?php
/**
 * Breeze role-cookie hardening.
 *
 * Extra Chill's full-page cache is provided by the vendored Breeze plugin.
 * Breeze's cache-SERVE gate (advanced-cache.php -> execute-cache.php) only
 * bypasses the anonymous cache for a logged-in browser when BOTH cookies are
 * present:
 *
 *   1. `wordpress_logged_in_*` (set by WordPress core), and
 *   2. `breeze_folder_name`   (the Breeze role cookie, set by Breeze).
 *
 * If `breeze_folder_name` is missing, an authenticated request is served the
 * ANONYMOUS cached page, and because the cache layer `exit`s from
 * advanced-cache.php *before* WordPress `init` ever runs, Breeze's own
 * init-time self-heal (`breeze_auth_cookie_set_init()` on `init` @ 5) never
 * fires to repair the cookie. The user is deadlocked: every page load re-serves
 * the anon cache and exits, until the `wordpress_logged_in_` cookie itself is
 * cleared. This presents as "I log in and it doesn't stick" (see
 * extrachill-users#161).
 *
 * WHY the role cookie desyncs: Breeze emits `breeze_folder_name` with
 * `setcookie()`, which SILENTLY FAILS when headers are already sent. Production
 * logs show recurring `Cannot modify header information - headers already sent`
 * and `Undefined array key "HTTP_HOST"` warnings during bootstrap, i.e. output
 * is sometimes emitted before WordPress finishes setting cookies. When a login
 * response (or any request that would set the role cookie) has headers already
 * sent, `breeze_folder_name` is dropped while `wordpress_logged_in_` succeeds,
 * dropping the user straight into the trapped state.
 *
 * THIS FILE hardens the *prevention* side of that mechanism from the layer that
 * owns platform cache/network integration (extrachill-network). It does NOT
 * touch the vendored Breeze plugin and does NOT own advanced-cache.php — the
 * cache-serve gate itself remains Breeze's. It works WITH Breeze by re-emitting
 * the exact same role cookie Breeze expects, earlier and more reliably than
 * Breeze does, so a logged-in browser reliably carries a resolvable
 * `breeze_folder_name` and the existing Breeze bypass fires.
 *
 * Two safety nets, both no-ops unless Breeze is active:
 *
 *   A. `set_auth_cookie` @ 20 (after Breeze's own @ 15): a backstop that
 *      re-emits the role cookie on the same login/auth response Breeze does,
 *      so a single dropped emission has a second chance on the same request.
 *
 *   B. `plugins_loaded` (fires ~150 lines / one bootstrap phase BEFORE Breeze's
 *      `init` @ 5): re-mints the role cookie for any logged-in request that is
 *      missing it, as early as possible, well before template output starts —
 *      closing most of the headers-already-sent window that Breeze's late
 *      `init` self-heal leaves open.
 *
 * NOTE ON SCOPE (extrachill-users#161 / extrachill-network#80): this is the
 * minimal Breeze-COMPATIBLE unblock. It cannot un-trap a browser that is
 * *already* being served from cache (that serve exits before any PHP runs) — it
 * prevents the desync that traps the browser in the first place. The complete,
 * cache-agnostic fix ("a logged-in request must NEVER be served an anonymous
 * cached page, regardless of the role cookie") requires owning the cache-serve
 * gate and is tracked as extrachill-network#80 (owned Redis full-page cache
 * to replace Breeze). #161 stays open until #80 lands.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Breeze's role-cookie machinery is present and usable.
 *
 * Every entry point below is a hard no-op unless this returns true, so the
 * plugin remains safe on installs without Breeze.
 *
 * @return bool
 */
function extrachill_breeze_role_cookie_available() {
	return defined( 'BREEZE_WP_COOKIE' )
		&& defined( 'BREEZE_WP_COOKIE_SALT' )
		&& function_exists( 'breeze_which_role_folder' );
}

/**
 * Build the Breeze role-cookie hash for a set of roles.
 *
 * Mirrors Breeze's own scheme exactly (see
 * breeze/inc/functions.php::breeze_auth_cookie_set()): each role is hashed as
 * sha1( BREEZE_WP_COOKIE_SALT . $role ) and the hashes are joined with the
 * literal '|&&&|' separator Breeze's `breeze_which_role_folder()` splits on.
 * Kept as a thin, clearly-cited replica because Breeze exposes no callable
 * helper that returns just the hash string.
 *
 * @param string[] $roles User role slugs.
 * @return string The `breeze_folder_name` cookie value, or '' if no roles.
 */
function extrachill_breeze_role_cookie_value( array $roles ) {
	$roles = array_filter( array_map( 'strval', $roles ) );
	if ( empty( $roles ) ) {
		return '';
	}

	$hashes = array();
	foreach ( $roles as $role ) {
		$hashes[] = sha1( BREEZE_WP_COOKIE_SALT . $role );
	}

	return implode( '|&&&|', $hashes );
}

/**
 * Emit the Breeze role cookie for a user, headers-already-sent aware.
 *
 * Uses the same cookie name, path, domain, and secure/httponly flags Breeze
 * uses so the emitted cookie is indistinguishable from Breeze's own. When
 * headers have already been sent the emission cannot happen (this is the exact
 * failure that traps users); rather than fail silently like Breeze, log it so
 * the desync is observable.
 *
 * @param int      $user_id  User whose roles the cookie encodes.
 * @param string[] $roles    Role slugs for the user.
 * @param int|null $expire   Unix timestamp for cookie expiry. Defaults to the
 *                           same window Breeze uses on init self-heal.
 * @return bool True if the cookie was emitted, false otherwise.
 */
function extrachill_breeze_emit_role_cookie( $user_id, array $roles, $expire = null ) {
	if ( ! extrachill_breeze_role_cookie_available() ) {
		return false;
	}

	$value = extrachill_breeze_role_cookie_value( $roles );
	if ( '' === $value ) {
		return false;
	}

	if ( headers_sent( $file, $line ) ) {
		// Headers already sent -> setcookie() would silently no-op. This is the
		// root trigger of extrachill-users#161; surface it (only when debugging)
		// instead of hiding it like Breeze does.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated observability for the #161 desync.
				sprintf(
					'[extrachill-network] Could not emit breeze_folder_name for user %d: headers already sent by %s:%d',
					(int) $user_id,
					is_string( $file ) ? $file : 'unknown',
					(int) $line
				)
			);
		}
		return false;
	}

	if ( null === $expire ) {
		$expiration = time() + (int) apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, (int) $user_id, true );
		$expire     = $expiration + ( 12 * HOUR_IN_SECONDS );
	}

	$secure                  = is_ssl();
	$secure_logged_in_cookie = $secure && 'https' === wp_parse_url( get_option( 'home' ), PHP_URL_SCHEME );
	/** This filter is documented in wp-includes/pluggable.php */
	$secure_logged_in_cookie = apply_filters( 'secure_logged_in_cookie', $secure_logged_in_cookie, (int) $user_id, $secure );

	setcookie( BREEZE_WP_COOKIE, $value, (int) $expire, COOKIEPATH, COOKIE_DOMAIN, $secure_logged_in_cookie, true );
	// Reflect into the current request so a same-request re-check sees it.
	$_COOKIE[ BREEZE_WP_COOKIE ] = $value;

	return true;
}

/**
 * Safety-net (A): backstop Breeze's own `set_auth_cookie` emission.
 *
 * Breeze emits the role cookie on `set_auth_cookie` @ 15. We run @ 20 so that
 * if Breeze's emission is about to be (or was) dropped, we re-emit on the same
 * response with the identical value. Harmless when Breeze already succeeded —
 * it just re-sends the same Set-Cookie header.
 *
 * Signature matches core's do_action( 'set_auth_cookie', ... ) in
 * wp-includes/pluggable.php.
 *
 * @param string $auth_cookie Unused.
 * @param int    $expire      Cookie expiry timestamp Breeze uses.
 * @param int    $expiration  Unused.
 * @param int    $user_id     User the cookie is for.
 * @return void
 */
function extrachill_breeze_backstop_on_auth_cookie( $auth_cookie, $expire, $expiration, $user_id ) {
	if ( ! extrachill_breeze_role_cookie_available() ) {
		return;
	}

	/** This filter is documented in wp-includes/pluggable.php */
	if ( ! apply_filters( 'send_auth_cookies', true ) ) {
		return;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return;
	}

	extrachill_breeze_emit_role_cookie( (int) $user_id, (array) $user->roles, (int) $expire );
}
add_action( 'set_auth_cookie', 'extrachill_breeze_backstop_on_auth_cookie', 20, 4 );

/**
 * Safety-net (B): early self-heal for logged-in requests missing the role cookie.
 *
 * This is the important one. Breeze's equivalent self-heal runs on `init` @ 5;
 * we run on `plugins_loaded`, one bootstrap phase earlier, so the cookie is
 * re-minted as early as possible — before themes, widgets, and most output can
 * send headers. That shrinks the headers-already-sent window that lets the
 * desync (and thus the extrachill-users#161 deadlock) form in the first place.
 *
 * No-op unless: Breeze is active, the request is a logged-in browser request
 * (has the `wordpress_logged_in_` cookie), and the role cookie is absent. On a
 * cache HIT this code never runs (advanced-cache.php exits first) — by design;
 * this heals the *real* requests that would otherwise re-emit the trap.
 *
 * @return void
 */
function extrachill_breeze_selfheal_role_cookie() {
	if ( ! extrachill_breeze_role_cookie_available() ) {
		return;
	}

	// Role cookie already present -> Breeze's serve gate can resolve it; nothing to do.
	if ( isset( $_COOKIE[ BREEZE_WP_COOKIE ] ) && '' !== $_COOKIE[ BREEZE_WP_COOKIE ] ) {
		return;
	}

	// Only act on browser requests that actually carry a logged-in cookie.
	// Avoids spinning up the user stack on anonymous or non-cookie (CLI/cron/REST-token) traffic.
	$has_logged_in_cookie = false;
	foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
		if ( 0 === strpos( (string) $cookie_name, 'wordpress_logged_in_' ) ) {
			$has_logged_in_cookie = true;
			break;
		}
	}
	if ( ! $has_logged_in_cookie ) {
		return;
	}

	/** This filter is documented in wp-includes/pluggable.php */
	if ( ! apply_filters( 'send_auth_cookies', true ) ) {
		return;
	}

	// Validate the logged-in cookie the normal WordPress way. If it doesn't
	// resolve to a real user, do nothing (stale/forged cookie).
	$user_id = function_exists( 'wp_validate_auth_cookie' ) ? wp_validate_auth_cookie( '', 'logged_in' ) : 0;
	if ( ! $user_id ) {
		return;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return;
	}

	extrachill_breeze_emit_role_cookie( (int) $user_id, (array) $user->roles );
}
add_action( 'plugins_loaded', 'extrachill_breeze_selfheal_role_cookie', 1 );
