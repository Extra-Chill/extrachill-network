<?php
/**
 * Emoji Deprecation Silence + Canonical Sizing Restoration
 *
 * WordPress core retains the deprecated `print_emoji_styles()` function hooked
 * to `wp_print_styles` / `admin_print_styles` for backwards-compatibility (see
 * wp-includes/default-filters.php). Since WP 6.4 the supported path is
 * `wp_enqueue_emoji_styles()` (hooked to `wp_enqueue_scripts`), which is meant
 * to unhook the deprecated action on normal page loads.
 *
 * On request paths that print styles without firing `wp_enqueue_scripts`
 * (feeds, certain admin-ajax / REST contexts, embeds), the unhook never runs
 * and core calls the deprecated `print_emoji_styles()`, emitting:
 *
 *   "Function print_emoji_styles is deprecated since version 6.4.0!
 *    Use wp_enqueue_emoji_styles instead."
 *
 * With WP_DEBUG logging on, this fired ~640-840 times/day per site and was the
 * single loudest line in debug.log network-wide, burying actionable warnings.
 *
 * Fix part 1 — silence the notice: explicitly remove the deprecated
 * `print_emoji_styles` action on both the frontend (`wp_print_styles`) and
 * admin (`admin_print_styles`) hooks.
 *
 * Fix part 2 — restore sizing (the regression): removing `print_emoji_styles`
 * has a side effect core did NOT intend for this site. `wp_enqueue_emoji_styles()`
 * (wp-includes/formatting.php) is gated by a back-compat guard: it early-returns
 * and emits NO sizing CSS whenever `print_emoji_styles` is no longer hooked,
 * assuming a plugin that unhooked it is taking over emoji styling itself. Because
 * this plugin removes `print_emoji_styles` on `init` (which fires before
 * `wp_enqueue_scripts`), the guard trips on every page load and core never emits
 * the canonical `img.emoji { height: 1em !important; ... }` rule. The emoji-
 * replacement JS still runs (text -> `<img class="emoji">`), but those images are
 * now unsized and render at their natural (giant) dimensions site-wide.
 *
 * So this plugin emits core's canonical emoji sizing CSS itself, via the
 * supported `wp_add_inline_style` path attached to the theme's always-loaded
 * main stylesheet handle (`extrachill-style`). This guarantees the rule loads on
 * every front-end page on every site in the network. wp-admin has no theme
 * stylesheet handle, so the same CSS is printed on `admin_head` there. This is
 * our own small style block — it is NOT the deprecated `print_emoji_styles`, so
 * it reintroduces no deprecation notice. Network-active, so this applies to all
 * sites.
 *
 * @package ExtraChill_Multisite
 * @since 1.20.0
 */

/**
 * Canonical emoji sizing CSS — mirrors wp-includes/formatting.php `wp_enqueue_emoji_styles()`.
 *
 * @return string Raw CSS (no <style> wrapper).
 */
function extrachill_multisite_emoji_sizing_css() {
	return <<<CSS
	img.wp-smiley, img.emoji {
		display: inline !important;
		border: none !important;
		box-shadow: none !important;
		height: 1em !important;
		width: 1em !important;
		margin: 0 0.07em !important;
		vertical-align: -0.1em !important;
		background: none !important;
		padding: 0 !important;
	}
CSS;
}

/**
 * Remove the deprecated `print_emoji_styles` action so core stops emitting the
 * WP 6.4 deprecation notice on request paths that skip `wp_enqueue_scripts`.
 */
function extrachill_multisite_silence_emoji_deprecation() {
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}
add_action( 'init', 'extrachill_multisite_silence_emoji_deprecation' );

/**
 * Restore canonical emoji sizing CSS on the front end.
 *
 * Attached to the Extra Chill theme's main stylesheet handle (`extrachill-style`),
 * which is unconditionally enqueued on every front-end page load on every site in
 * the network. Running at priority 25 ensures the handle is already enqueued by
 * the theme (priority 20). If core ever resumes emitting the rule itself the only
 * effect is a harmless duplicate.
 */
function extrachill_multisite_restore_emoji_sizing_frontend() {
	if ( wp_style_is( 'extrachill-style', 'enqueued' ) ) {
		wp_add_inline_style( 'extrachill-style', extrachill_multisite_emoji_sizing_css() );
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_multisite_restore_emoji_sizing_frontend', 25 );

/**
 * Restore canonical emoji sizing CSS in wp-admin.
 *
 * Admin screens have no theme stylesheet handle to attach to, and the deprecation
 * silence above removed `print_emoji_styles` from `admin_print_styles` too — so
 * admin also lost the sizing rule via core's back-compat early return. Print the
 * same small style block directly. This is our own CSS, not the deprecated
 * function, so no deprecation notice is emitted.
 */
function extrachill_multisite_restore_emoji_sizing_admin() {
	echo '<style>' . extrachill_multisite_emoji_sizing_css() . '</style>';
}
add_action( 'admin_head', 'extrachill_multisite_restore_emoji_sizing_admin', 99 );
