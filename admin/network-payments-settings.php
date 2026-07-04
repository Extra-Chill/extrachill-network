<?php
/**
 * ExtraChill Network Commerce Credentials
 *
 * Network admin page for configuring all platform commerce/provider credentials.
 * This is the single WRITE surface for network-wide commerce secrets:
 *
 *   Stripe (artist marketplace + Connect payouts):
 *     - extrachill_stripe_secret_key
 *     - extrachill_stripe_publishable_key
 *     - extrachill_stripe_connect_client_id
 *     - extrachill_stripe_webhook_secret
 *
 *   Shipping (Shippo label generation):
 *     - extrachill_shippo_api_key
 *
 * The shop (extrachill-shop) owns the runtime READ contract for each option:
 *
 *     apply_filters( 'extrachill_stripe_<key>', get_site_option( 'extrachill_stripe_<key>' ) )
 *     get_site_option( 'extrachill_shippo_api_key' )
 *
 * This layer only writes the site_options under those exact option names, so the
 * shop read paths (and its `extrachill-dev` filter overrides) keep working
 * unchanged. Network-admin (super admin) only — these are platform-level secrets.
 *
 * @package ExtraChill\Multisite
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_payments_menu' );

/**
 * Add commerce credentials page to network admin menu
 */
function ec_add_network_payments_menu() {
	add_submenu_page(
		EXTRACHILL_MULTISITE_MENU_SLUG,
		'Payments Settings',
		'Payments',
		'manage_network_options',
		'extrachill-payments',
		'ec_render_network_payments_page'
	);
}

add_action( 'network_admin_edit_extrachill_payments', 'ec_handle_network_payments_save' );

/**
 * Commerce credential field specification.
 *
 * Single source of truth for the POST field name, the site_option name the shop
 * reads, and the field UI metadata. Option names MUST match the shop's read
 * contract exactly. Grouped by `section` for rendering.
 *
 * @return array<int, array<string,string>>
 */
function ec_network_commerce_fields() {
	return array(
		// Stripe Connect (artist marketplace + payouts).
		array(
			'section'     => 'stripe',
			'key'         => 'secret_key',
			'option'      => 'extrachill_stripe_secret_key',
			'label'       => __( 'Secret Key', 'extrachill-multisite' ),
			'placeholder' => 'sk_live_...',
			'description' => __( 'Your Stripe secret API key. Keep this confidential.', 'extrachill-multisite' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'publishable_key',
			'option'      => 'extrachill_stripe_publishable_key',
			'label'       => __( 'Publishable Key', 'extrachill-multisite' ),
			'placeholder' => 'pk_live_...',
			'description' => __( 'Stripe publishable API key, used for frontend integration.', 'extrachill-multisite' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'connect_client_id',
			'option'      => 'extrachill_stripe_connect_client_id',
			'label'       => __( 'Connect Client ID', 'extrachill-multisite' ),
			'placeholder' => 'ca_...',
			'description' => __( 'Stripe Connect platform client ID. Enables artist Connect Express onboarding and payouts.', 'extrachill-multisite' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'webhook_secret',
			'option'      => 'extrachill_stripe_webhook_secret',
			'label'       => __( 'Webhook Secret', 'extrachill-multisite' ),
			'placeholder' => 'whsec_...',
			'description' => __( 'Webhook signing secret used to verify Stripe webhook events.', 'extrachill-multisite' ),
		),
		// Shipping (Shippo label generation).
		array(
			'section'     => 'shipping',
			'key'         => 'shippo_api_key',
			'option'      => 'extrachill_shippo_api_key',
			'label'       => __( 'Shippo API Key', 'extrachill-multisite' ),
			'placeholder' => 'shippo_live_...',
			'description' => __( 'Shippo API token used to generate USPS shipping labels. Keep this confidential.', 'extrachill-multisite' ),
		),
	);
}

/**
 * Handle commerce credentials form submission.
 *
 * No-wipe-on-blank: a key is only written when a NEW non-empty value is
 * submitted. Saving the form with a blank field preserves any already-set key,
 * so an accidental empty save cannot erase configured credentials.
 */
function ec_handle_network_payments_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-multisite' ) );
	}

	check_admin_referer( 'ec_payments_settings', 'ec_payments_nonce' );

	foreach ( ec_network_commerce_fields() as $field ) {
		$post_key = 'ec_commerce_' . $field['key'];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		$submitted = isset( $_POST[ $post_key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) )
			: '';

		// Only update when a new non-empty value was submitted.
		if ( '' !== $submitted ) {
			// TODO: encrypt these secrets at rest once a shared EC secret-encryption
			// helper exists. No reusable EC-owned crypto helper is available today
			// (only third-party, plugin-local schemes such as Easy WP SMTP's own
			// Crypto class). Do not invent a bespoke scheme here; revisit when a
			// shared helper lands.
			update_site_option( $field['option'], $submitted );
		}
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-payments',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render network commerce credentials page.
 *
 * Stored credential values are NEVER echoed back into the form. Each field shows
 * only a "Set / Not set" status and renders an empty masked input so the value
 * can be replaced. This prevents secret leakage into the page HTML.
 */
function ec_render_network_payments_page() {
	$fields   = ec_network_commerce_fields();
	$statuses = array();

	foreach ( $fields as $field ) {
		$statuses[ $field['key'] ] = ! empty( get_site_option( $field['option'], '' ) );
	}

	$is_configured   = ! empty( $statuses['secret_key'] ) && ! empty( $statuses['publishable_key'] );
	$connect_enabled = ! empty( $statuses['connect_client_id'] );
	$shipping_ready  = ! empty( $statuses['shippo_api_key'] );

	$stripe_fields   = array_filter( $fields, static fn( $f ) => 'stripe' === $f['section'] );
	$shipping_fields = array_filter( $fields, static fn( $f ) => 'shipping' === $f['section'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Commerce Settings', 'extrachill-multisite' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success flag. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Commerce settings updated successfully.', 'extrachill-multisite' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_payments">
			<?php wp_nonce_field( 'ec_payments_settings', 'ec_payments_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th colspan="2">
							<h2><?php esc_html_e( 'Stripe Connect', 'extrachill-multisite' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Stripe credentials for the artist marketplace payment processing and Connect payouts.', 'extrachill-multisite' ); ?>
								<?php if ( $is_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Processing configured', 'extrachill-multisite' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-multisite' ); ?></span>
								<?php endif; ?>
								<?php if ( $connect_enabled ) : ?>
									&nbsp;<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Connect payouts enabled', 'extrachill-multisite' ); ?></span>
								<?php else : ?>
									&nbsp;<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Connect client ID not set (artist payouts unavailable)', 'extrachill-multisite' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<?php foreach ( $stripe_fields as $field ) : ?>
						<?php ec_render_commerce_field_row( $field, ! empty( $statuses[ $field['key'] ] ) ); ?>
					<?php endforeach; ?>

					<tr>
						<th colspan="2" style="padding-top: 30px;">
							<h2><?php esc_html_e( 'Shipping (Shippo)', 'extrachill-multisite' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Shippo API token for USPS shipping label generation (artist fulfillment).', 'extrachill-multisite' ); ?>
								<?php if ( $shipping_ready ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-multisite' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-multisite' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<?php foreach ( $shipping_fields as $field ) : ?>
						<?php ec_render_commerce_field_row( $field, ! empty( $statuses[ $field['key'] ] ) ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Stored values are never shown. Leave a field blank to keep its current value; enter a new value to replace it.', 'extrachill-multisite' ); ?>
			</p>

			<?php submit_button( __( 'Save Commerce Settings', 'extrachill-multisite' ) ); ?>
		</form>

		<div class="card" style="margin-top: 20px; max-width: 800px;">
			<h3><?php esc_html_e( 'Setup Instructions', 'extrachill-multisite' ); ?></h3>
			<p><strong><?php esc_html_e( 'Stripe', 'extrachill-multisite' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create a Stripe account at stripe.com and enable Stripe Connect in your dashboard.', 'extrachill-multisite' ); ?></li>
				<li><?php esc_html_e( 'Developers > API keys: copy your Secret Key and Publishable Key.', 'extrachill-multisite' ); ?></li>
				<li><?php esc_html_e( 'Developers > Connect: copy your platform Connect Client ID (the "ca_..." identifier).', 'extrachill-multisite' ); ?></li>
				<li><?php esc_html_e( 'Create a webhook endpoint pointing to your site and copy the Webhook Signing Secret.', 'extrachill-multisite' ); ?></li>
			</ol>
			<p>
				<strong><?php esc_html_e( 'Stripe Webhook URL:', 'extrachill-multisite' ); ?></strong>
				<code><?php echo esc_url( rest_url( 'extrachill/v1/shop/stripe-webhook' ) ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Shippo', 'extrachill-multisite' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create a Shippo account at goshippo.com.', 'extrachill-multisite' ); ?></li>
				<li><?php esc_html_e( 'Settings > API: copy your Live API Token.', 'extrachill-multisite' ); ?></li>
			</ol>
		</div>
	</div>

	<style>
		.card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px;
			margin: 20px 0;
		}
		.card h3 {
			margin-top: 0;
		}
	</style>
	<?php
}

/**
 * Render a single masked commerce credential field row.
 *
 * Stored values are never output; the input is always empty so the operator can
 * replace the value, and a Set / Not set indicator reflects the current state.
 *
 * @param array<string,string> $field  Field spec from ec_network_commerce_fields().
 * @param bool                 $is_set Whether a value is currently stored.
 */
function ec_render_commerce_field_row( $field, $is_set ) {
	$input_id = 'ec_commerce_' . $field['key'];
	$post_key = 'ec_commerce_' . $field['key'];
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
		</th>
		<td>
			<input type="password"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $post_key ); ?>"
					value=""
					autocomplete="new-password"
					class="regular-text"
					placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" />
			<?php if ( $is_set ) : ?>
				<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Set', 'extrachill-multisite' ); ?></span>
			<?php else : ?>
				<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not set', 'extrachill-multisite' ); ?></span>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( $field['description'] ); ?>
			</p>
		</td>
	</tr>
	<?php
}
