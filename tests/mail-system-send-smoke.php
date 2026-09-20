<?php
/**
 * Standalone tests for the principal-less system-send declaration and the
 * interim queued fallback in ec_send_email() (#235 / data-machine#3534).
 */

namespace DataMachine\Abilities {

	class PermissionHelper {
		public static int $acting_user_id = 0;
		public static bool $agent_context = false;

		public static function acting_user_id(): int {
			return self::$acting_user_id;
		}

		public static function in_agent_context(): bool {
			return self::$agent_context;
		}
	}
}

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['mail_direct_result']  = null;
$GLOBALS['mail_direct_args']    = null;
$GLOBALS['mail_queued_result']  = null;
$GLOBALS['mail_queued_args']    = null;
$GLOBALS['mail_log_file']       = tempnam( sys_get_temp_dir(), 'mail-smoke-log-' );
ini_set( 'error_log', $GLOBALS['mail_log_file'] );

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_action() {}

function add_filter() {}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}

function get_current_blog_id() {
	return 1;
}

function get_site_transient( $key ) {
	unset( $key );
	return false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	unset( $key, $value, $ttl );
	return true;
}

function get_option( $name ) {
	unset( $name );
	// Easy WP SMTP configured with a real mailer so mail_site_id resolves locally.
	return array(
		'mail' => array( 'mailer' => 'smtp' ),
		'smtp' => array( 'host' => 'smtp.test' ),
	);
}

class FakeSendEmailAbility {
	public function execute( array $args ) {
		$GLOBALS['mail_direct_args'] = $args;
		return $GLOBALS['mail_direct_result'];
	}
}

class FakeSendEmailQueuedAbility {
	public function execute( array $args ) {
		$GLOBALS['mail_queued_args'] = $args;
		return $GLOBALS['mail_queued_result'];
	}
}

function wp_get_ability( $slug ) {
	if ( 'datamachine/send-email' === $slug ) {
		return new FakeSendEmailAbility();
	}
	if ( 'datamachine/send-email-queued' === $slug ) {
		return new FakeSendEmailQueuedAbility();
	}
	return null;
}

require_once dirname( __DIR__ ) . '/inc/core/mail.php';

function mail_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function mail_reset() {
	$GLOBALS['mail_direct_result']  = null;
	$GLOBALS['mail_direct_args']    = null;
	$GLOBALS['mail_queued_result']  = null;
	$GLOBALS['mail_queued_args']    = null;
	DataMachine\Abilities\PermissionHelper::$acting_user_id = 0;
	DataMachine\Abilities\PermissionHelper::$agent_context  = false;
}

// --- (a) Principal-less call declares a system send. ------------------------

mail_reset();
$GLOBALS['mail_direct_result'] = array( 'success' => true, 'message' => 'sent' );

$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert(
	isset( $GLOBALS['mail_direct_args']['system'] ) && true === $GLOBALS['mail_direct_args']['system'],
	'a principal-less call sets system => true on the direct ability input'
);
mail_assert(
	true === $result['success'] && null === $GLOBALS['mail_queued_args'],
	'a successful principal-less direct send is returned untouched with no queued fallback'
);

// --- (b) email_auth_ref_required triggers the queued fallback. ---------------

mail_reset();
$GLOBALS['mail_direct_result'] = new WP_Error( 'email_auth_ref_required', 'An authorized mailbox ref is required to send email.' );
$GLOBALS['mail_queued_result'] = array( 'success' => true, 'action_id' => 7, 'scheduled_for' => 1234567890 );

$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert(
	is_array( $result ) && true === $result['success'] && 'queued_fallback' === ( $result['delivery'] ?? '' ),
	'an email_auth_ref_required refusal falls back to the queued path and returns delivery => queued_fallback'
);
mail_assert(
	is_array( $GLOBALS['mail_queued_args'] ) && ! array_key_exists( 'system', $GLOBALS['mail_queued_args'] ),
	'the queued retry receives the same args minus the system flag'
);
mail_assert(
	(int) $GLOBALS['mail_queued_args']['mail_site_id'] === 1 && 'extrachill/branded' === $GLOBALS['mail_queued_args']['template'],
	'the queued retry keeps the resolved mail defaults'
);

$log_contents = (string) file_get_contents( $GLOBALS['mail_log_file'] );
mail_assert(
	str_contains( $log_contents, 'email_auth_ref_required' ) && str_contains( $log_contents, 'ec_send_email_queued' ),
	'the fallback logs one line naming the refused code and the queued retry'
);

// --- (b2) A refused envelope (array, not WP_Error) also falls back. ----------

mail_reset();
$GLOBALS['mail_direct_result'] = array( 'success' => false, 'code' => 'email_mailbox_forbidden', 'error' => 'Mailbox forbidden.' );
$GLOBALS['mail_queued_result'] = array( 'success' => true, 'action_id' => 8 );

$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert(
	is_array( $result ) && true === $result['success'] && 'queued_fallback' === ( $result['delivery'] ?? '' ),
	'an envelope refusal with code email_mailbox_forbidden also triggers the queued fallback'
);

// --- (b3) Other failure codes do not trigger the fallback. -------------------

mail_reset();
$GLOBALS['mail_direct_result'] = new WP_Error( 'invalid_email_recipient', 'No valid recipient' );

$result = ec_send_email( array( 'to' => '', 'subject' => 'Hi' ) );

mail_assert(
	is_wp_error( $result ) && 'invalid_email_recipient' === $result->get_error_code() && null === $GLOBALS['mail_queued_args'],
	'a refusal with an unrelated code is returned untouched with no queued fallback'
);

// --- (c) A call with an acting user is passed through unchanged. -------------

mail_reset();
DataMachine\Abilities\PermissionHelper::$acting_user_id = 5;
$GLOBALS['mail_direct_result'] = array( 'success' => true, 'message' => 'sent' );

$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert(
	! array_key_exists( 'system', $GLOBALS['mail_direct_args'] ) && null === $GLOBALS['mail_queued_args'],
	'a call with an acting user passes through with no system flag and no fallback'
);
mail_assert(
	true === $result['success'],
	'the acting-user result is returned unchanged'
);

// --- (d) An explicit auth_ref opts out of the system declaration. ------------

mail_reset();
$GLOBALS['mail_direct_result'] = new WP_Error( 'email_auth_ref_required', 'An authorized mailbox ref is required to send email.' );

$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi', 'auth_ref' => 'email_imap:default' ) );

mail_assert(
	! array_key_exists( 'system', $GLOBALS['mail_direct_args'] ) && null === $GLOBALS['mail_queued_args'],
	'a caller-supplied auth_ref gets no system flag and no queued fallback'
);
mail_assert(
	is_wp_error( $result ) && 'email_auth_ref_required' === $result->get_error_code(),
	'the auth_ref refusal is returned untouched'
);

// --- (e) An acting agent context is not treated as principal-less. -----------

mail_reset();
DataMachine\Abilities\PermissionHelper::$agent_context = true;
$GLOBALS['mail_direct_result'] = array( 'success' => true, 'message' => 'sent' );

ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert(
	! array_key_exists( 'system', $GLOBALS['mail_direct_args'] ) && null === $GLOBALS['mail_queued_args'],
	'an agent-context call gets no system flag and no fallback'
);

unlink( $GLOBALS['mail_log_file'] );

echo "All mail system send tests passed.\n";
}
