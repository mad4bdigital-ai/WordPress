<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-bound local enrollment. Account observation is not execution certification.
 * Credentials and account receipts share an authenticated, revision-bound CAS row.
 */
final class MAD4B_SCP_Search_Provider_Connections {
	const KIND = 'provider_connection';
	const CONTRACT = 'mad4b.search-provider-connection.v1';
	const HANDLE_PREFIX = 'asi.local.';
	const RECEIPT_TTL = 3600;

	public static function boot() {
		add_filter( 'mad4b_scp_search_serp_descriptor', array( __CLASS__, 'descriptor' ), 30, 2 );
		add_filter( 'mad4b_scp_search_secret_resolve', array( __CLASS__, 'resolve' ), 30, 3 );
		add_action( 'admin_post_mad4b_search_provider_connection', array( __CLASS__, 'post' ) );
	}
	private static function adapter( $id ) {
		if ( ! MAD4B_SCP_Search_Contracts::id( $id ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_id_invalid' );
		$adapters = MAD4B_SCP_Search_Providers::adapters();
		return isset( $adapters[ $id ] ) && $adapters[ $id ] instanceof MAD4B_SCP_Search_SERP_Enrollment ? $adapters[ $id ] : MAD4B_SCP_Search_Contracts::error( 'provider_enrollment_unavailable' );
	}
	private static function key() {
		$scope = MAD4B_SCP_Search_Store::scope();
		if ( is_wp_error( $scope ) ) return $scope;
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) return MAD4B_SCP_Search_Contracts::error( 'credential_encryption_unavailable' );
		$salt = wp_salt( 'auth' );
		if ( ! is_string( $salt ) || strlen( $salt ) < 16 ) return MAD4B_SCP_Search_Contracts::error( 'credential_encryption_unavailable' );
		return hash( 'sha256', self::CONTRACT . "\n" . $scope . "\n" . $salt, true );
	}
	private static function seal( $id, array $payload, $revision ) {
		$key = self::key(); if ( is_wp_error( $key ) ) return $key;
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $payload ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		try { $iv = random_bytes( 12 ); } catch ( Throwable $e ) { return MAD4B_SCP_Search_Contracts::error( 'credential_encryption_unavailable' ); }
		$tag = ''; $cipher = openssl_encrypt( wp_json_encode( $payload ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTRACT . ':' . $id . ':' . $revision, 16 );
		if ( false === $cipher || 16 !== strlen( $tag ) ) return MAD4B_SCP_Search_Contracts::error( 'credential_encryption_unavailable' );
		return array( 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $cipher ) );
	}
	private static function open( $id, $row ) {
		if ( null === $row ) return null;
		if ( is_wp_error( $row ) ) return $row;
		if ( ! is_array( $row ) || ! isset( $row['contract'], $row['provider_id'], $row['_revision'] ) || self::CONTRACT !== $row['contract'] || $id !== $row['provider_id'] || ! is_int( $row['_revision'] ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		// A deletion is a tombstone so stale forms cannot recreate the old revision.
		if ( isset( $row['removed'] ) && true === $row['removed'] && ! isset( $row['envelope'] ) ) return null;
		if ( ! isset( $row['envelope'] ) || ! is_array( $row['envelope'] ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		$key = self::key(); if ( is_wp_error( $key ) ) return $key;
		$parts = array();
		foreach ( array( 'iv', 'tag', 'ciphertext' ) as $field ) {
			$value = isset( $row['envelope'][ $field ] ) ? $row['envelope'][ $field ] : null;
			if ( ! is_string( $value ) || strlen( $value ) > 32768 || false === ( $parts[ $field ] = base64_decode( $value, true ) ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		}
		if ( 12 !== strlen( $parts['iv'] ) || 16 !== strlen( $parts['tag'] ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		$plain = openssl_decrypt( $parts['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $parts['iv'], $parts['tag'], self::CONTRACT . ':' . $id . ':' . $row['_revision'] );
		$p = false === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $p ) || ! isset( $p['credentials'], $p['handle'] ) || ! is_array( $p['credentials'] ) || ! is_string( $p['handle'] ) || ! preg_match( '/^asi\.local\.[a-f0-9]{64}$/D', $p['handle'] ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid', 'Saved credentials cannot be verified for this site. Replace or remove them.' );
		return $p;
	}
	private static function persist( $id, $old, array $payload, $event ) {
		$revision = null === $old ? 1 : $old['_revision'] + 1;
		$envelope = self::seal( $id, $payload, $revision ); if ( is_wp_error( $envelope ) ) return $envelope;
		return MAD4B_SCP_Search_Store::cas( self::KIND, $id, $old, array( 'contract' => self::CONTRACT, 'provider_id' => $id, 'envelope' => $envelope ), $event );
	}
	private static function account_receipt( $result ) {
		if ( is_wp_error( $result ) ) return $result;
		if ( ! is_array( $result ) || ! MAD4B_SCP_Search_Contracts::bounded( $result ) || ! isset( $result['account_ref'], $result['quota'] ) || ! array_key_exists( 'balance', $result ) || ! MAD4B_SCP_Search_Contracts::sha( $result['account_ref'] ) || ! is_array( $result['quota'] ) || array_diff( array_keys( $result ), array( 'account_ref', 'quota', 'balance' ) ) || array_diff( array_keys( $result['quota'] ), array( 'remaining', 'monthly_limit', 'monthly_used', 'reset_at', 'renewal_date' ) ) ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid' );
		foreach ( $result['quota'] as $field => $value ) {
			if ( 'renewal_date' === $field ) { if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) || gmdate( 'Y-m-d', strtotime( $value . ' UTC' ) ) !== $value ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid' ); }
			elseif ( ! is_int( $value ) || $value < 0 ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid' );
		}
		$b = $result['balance'];
		if ( null !== $b && ( ! is_array( $b ) || ! isset( $b['amount'], $b['currency'] ) || ( ! is_int( $b['amount'] ) && ! is_float( $b['amount'] ) ) || ! is_finite( (float) $b['amount'] ) || ! is_string( $b['currency'] ) || ! preg_match( '/^[A-Z]{3}$/D', $b['currency'] ) || array_diff( array_keys( $b ), array( 'amount', 'currency' ) ) ) ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid' );
		return $result;
	}

	/** Explicit local administrator action; no GET, cron, MCP or paid-search path. */
	public static function apply( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$id = isset( $input['provider_id'] ) ? $input['provider_id'] : '';
		$a = self::adapter( $id ); if ( is_wp_error( $a ) ) return $a;
		$action = isset( $input['operation'] ) ? $input['operation'] : '';
		if ( ! in_array( $action, array( 'save', 'test', 'remove' ), true ) || ! isset( $input['expected_revision'] ) || ! is_int( $input['expected_revision'] ) || $input['expected_revision'] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'connection_input_invalid' );
		$old = MAD4B_SCP_Search_Store::read( self::KIND, $id ); if ( is_wp_error( $old ) ) return $old;
		if ( null !== $old && ( ! is_array( $old ) || ! isset( $old['_revision'] ) || ! is_int( $old['_revision'] ) || $old['_revision'] < 1 ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_record_invalid' );
		if ( ( null === $old ? 0 : $old['_revision'] ) !== $input['expected_revision'] ) return MAD4B_SCP_Search_Contracts::error( 'connection_revision_conflict', 'Provider settings changed. Reload this page before trying again.' );
		if ( 'remove' === $action ) {
			$saved = MAD4B_SCP_Search_Store::cas( self::KIND, $id, $old, array( 'contract' => self::CONTRACT, 'provider_id' => $id, 'removed' => true ), 'PROVIDER_CONNECTION_REMOVED' );
		} else {
			$p = self::open( $id, $old );
			if ( 'save' === $action ) {
				$c = isset( $input['credentials'] ) ? $input['credentials'] : null;
				if ( ! is_array( $c ) || array_diff( array_keys( $c ), array_keys( $a->enrollment()['fields'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'credentials_invalid' );
				$blank = true; foreach ( $c as $value ) if ( '' !== $value ) $blank = false;
				if ( $blank && is_array( $p ) ) return self::status( $id );
				$c = $a->normalize_credentials( $c ); if ( is_wp_error( $c ) ) return $c;
				try { $handle = self::HANDLE_PREFIX . bin2hex( random_bytes( 32 ) ); } catch ( Throwable $e ) { return MAD4B_SCP_Search_Contracts::error( 'credential_encryption_unavailable' ); }
				// Rotation invalidates both the account receipt and descriptor certification.
				$p = array( 'credentials' => $c, 'handle' => $handle, 'account' => null, 'last_check' => null );
				$saved = self::persist( $id, $old, $p, 'PROVIDER_CREDENTIALS_SAVED' );
			} else {
				if ( is_wp_error( $p ) ) return $p;
				if ( ! is_array( $p ) ) return MAD4B_SCP_Search_Contracts::error( 'credentials_missing', 'Save the API credentials before testing the connection.' );
				$result = self::account_receipt( $a->observe_account( $p['credentials'] ) );
				$p['account'] = is_wp_error( $result ) ? null : array_merge( $result, array( 'observed_at' => time(), 'expires_at' => time() + self::RECEIPT_TTL ) );
				$p['last_check'] = array( 'state' => is_wp_error( $result ) ? 'FAILED' : 'VERIFIED', 'at' => time() );
				$saved = self::persist( $id, $old, $p, 'PROVIDER_ACCOUNT_CHECKED' );
				if ( ! is_wp_error( $saved ) && is_wp_error( $result ) ) return $result;
			}
		}
		return is_wp_error( $saved ) ? $saved : self::status( $id );
	}

	/** This projection never decrypts credentials into HTML or performs discovery HTTP. */
	public static function status( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$row = MAD4B_SCP_Search_Store::read( self::KIND, $id ); if ( is_wp_error( $row ) ) return $row;
		$p = self::open( $id, $row ); if ( is_wp_error( $p ) ) return $p;
		$account = is_array( $p ) && ! empty( $p['account'] ) ? $p['account'] : null;
		$out = array( 'provider_id' => $id, 'revision' => null === $row ? 0 : $row['_revision'], 'configured' => is_array( $p ), 'connection_state' => ! is_array( $p ) ? 'NOT_CONFIGURED' : ( null === $account ? ( ! empty( $p['last_check'] ) ? 'CHECK_FAILED' : 'NOT_CHECKED' ) : ( $account['expires_at'] > time() ? 'VERIFIED' : 'CHECK_EXPIRED' ) ), 'quota' => null === $account ? array() : $account['quota'], 'balance' => null === $account ? null : $account['balance'], 'observed_at' => null === $account ? null : $account['observed_at'], 'authorizing' => false );
		return $out;
	}

	/** Merge only observed identity/usage. Economics, rights and certification stay governed. */
	public static function descriptor( $d, $id ) {
		if ( ! is_array( $d ) ) return $d;
		$row = MAD4B_SCP_Search_Store::read( self::KIND, $id );
		if ( null === $row ) return $d; // Preserve externally managed adapters.
		$p = self::open( $id, $row );
		if ( ! is_array( $p ) ) { $d['secret_handle'] = ''; $d['account_id'] = ''; $d['usage'] = array(); return $d; }
		$d['secret_handle'] = $p['handle']; $d['account_id'] = ''; $d['usage'] = array();
		if ( ! empty( $p['account'] ) ) {
			$r = $p['account']; $d['provider_account_ref'] = $r['account_ref'];
			$d['account_id'] = MAD4B_SCP_Provider_Account_Budget_Authority::account_identity( array( 'provider_id' => $id, 'provider_account_ref' => $r['account_ref'] ) );
			$d['shared_account'] = true;
			if ( isset( $r['quota']['remaining'], $r['quota']['reset_at'] ) ) $d['usage'] = array( 'remaining' => $r['quota']['remaining'], 'reset_at' => $r['quota']['reset_at'], 'observed_at' => $r['observed_at'], 'expires_at' => $r['expires_at'], 'cycle_id' => 'native.' . $r['quota']['reset_at'] );
		}
		return $d;
	}
	public static function resolve( $value, $handle, $id ) {
		if ( ! is_string( $handle ) || 0 !== strpos( $handle, self::HANDLE_PREFIX ) ) return $value;
		$p = self::open( $id, MAD4B_SCP_Search_Store::read( self::KIND, $id ) );
		if ( ! is_array( $p ) || ! hash_equals( $p['handle'], $handle ) ) return null;
		$a = self::adapter( $id );
		return is_wp_error( $a ) ? null : $a->credential_value( $p['credentials'] );
	}

	public static function render( $profile_id = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<h2 id="search-providers">Search providers</h2><p>Save API credentials, then test the account connection. Saving or testing does not enable search capture. An approved provider, Search Profile and governed budget are required before observations can run.</p>';
		$editable = MAD4B_SCP_Search_Runtime::can_configure();
		if ( ! $editable ) echo '<p>Provider setup requires an enrolled Staging, Development or Local environment.</p>';
		foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $a ) {
			if ( ! $a instanceof MAD4B_SCP_Search_SERP_Enrollment ) continue;
			$m = $a->enrollment(); $s = self::status( $id ); $d = $a->descriptor();
			echo '<section class="postbox" style="padding:16px"><h3>' . esc_html( $m['label'] ) . '</h3><p>' . esc_html( $m['help'] ) . ' <a href="' . esc_url( $m['help_url'] ) . '" target="_blank" rel="noopener noreferrer">API credentials</a></p>';
			$revision = is_wp_error( $s ) ? MAD4B_SCP_Search_Store::read( self::KIND, $id ) : null;
			$revision = is_wp_error( $s ) ? ( is_array( $revision ) ? $revision['_revision'] : 0 ) : $s['revision'];
			if ( is_wp_error( $s ) ) echo '<p>' . esc_html( $s->get_error_message() ) . '</p>';
			else {
				echo '<dl><dt>Credentials</dt><dd>' . esc_html( $s['configured'] ? 'Saved securely; fields remain blank' : 'Not configured' ) . '</dd><dt>Connection</dt><dd>' . esc_html( str_replace( '_', ' ', $s['connection_state'] ) ) . '</dd>';
				echo '<dt>Observed search allowance</dt><dd>' . esc_html( isset( $s['quota']['remaining'] ) ? (string) $s['quota']['remaining'] . ( isset( $s['quota']['monthly_limit'] ) ? ' remaining; monthly plan: ' . $s['quota']['monthly_limit'] : ' remaining' ) : 'Not observed; monetary balance does not establish a search allowance' ) . '</dd>';
				if ( isset( $s['quota']['renewal_date'] ) ) echo '<dt>Provider renewal date</dt><dd>' . esc_html( $s['quota']['renewal_date'] ) . '</dd>';
				if ( null !== $s['balance'] ) echo '<dt>Observed account balance</dt><dd>' . esc_html( (string) $s['balance']['amount'] . ' ' . $s['balance']['currency'] ) . '</dd>';
				if ( null !== $s['observed_at'] ) echo '<dt>Account checked at</dt><dd>' . esc_html( gmdate( 'c', $s['observed_at'] ) ) . '</dd>';
				echo '</dl>';
			}
			$certified = ! empty( $d['certified'] ) && ! empty( $d['certification_expires_at'] ) && $d['certification_expires_at'] > time();
			echo '<p>Provider certification: ' . esc_html( $certified ? 'Current' : 'Required before capture; account verification is not certification' ) . '</p>';
			$b = ! empty( $d['account_id'] ) ? MAD4B_SCP_Search_Budgets::status( $d['account_id'] ) : null;
			echo '<p>Governed budget: ' . esc_html( is_array( $b ) ? $b['authority_scope'] . '; remaining: ' . $b['remaining'] : 'Not configured for this provider account' ) . '</p>';
			if ( $editable ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mad4b_search_provider_connection"><input type="hidden" name="provider_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( (string) $revision ) . '">';
				if ( MAD4B_SCP_Search_Contracts::id( $profile_id ) ) echo '<input type="hidden" name="profile_id" value="' . esc_attr( $profile_id ) . '">';
				wp_nonce_field( 'mad4b_search_provider_connection:' . $id );
				foreach ( $m['fields'] as $key => $field ) {
					$input_id = 'mad4b-provider-' . $id . '-' . $key;
					echo '<p><label for="' . esc_attr( $input_id ) . '">' . esc_html( $field['label'] ) . '</label><br><input class="regular-text" type="password" id="' . esc_attr( $input_id ) . '" name="credentials[' . esc_attr( $key ) . ']" value="" maxlength="' . esc_attr( (string) $field['max_length'] ) . '" autocomplete="new-password" spellcheck="false"></p>';
				}
				echo '<p>Leave every credential field blank to keep the saved connection. To replace credentials, complete every field.</p><button class="button button-primary" name="operation" value="save" type="submit">Save credentials</button> <button class="button" name="operation" value="test" type="submit">Test saved connection</button> <button class="button" name="operation" value="remove" type="submit">Remove credentials</button></form>';
			}
			echo '</section>';
		}
	}
	public static function post_input( array $input ) {
		$revision = isset( $input['expected_revision'] ) ? $input['expected_revision'] : null;
		if ( ! is_string( $revision ) || ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $revision ) ) return MAD4B_SCP_Search_Contracts::error( 'connection_input_invalid' );
		return self::apply( array( 'provider_id' => isset( $input['provider_id'] ) ? $input['provider_id'] : '', 'operation' => isset( $input['operation'] ) ? $input['operation'] : '', 'expected_revision' => (int) $revision, 'credentials' => isset( $input['credentials'] ) ? $input['credentials'] : array() ) );
	}
	public static function post() {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) wp_die( 'Provider configuration is not authorized.', '', array( 'response' => 403 ) );
		$id = isset( $_POST['provider_id'] ) && is_string( $_POST['provider_id'] ) ? wp_unslash( $_POST['provider_id'] ) : '';
		if ( ! MAD4B_SCP_Search_Contracts::id( $id ) ) wp_die( 'Invalid provider.', '', array( 'response' => 400 ) );
		$profile_id = isset( $_POST['profile_id'] ) ? wp_unslash( $_POST['profile_id'] ) : '';
		if ( ! is_string( $profile_id ) || ( '' !== $profile_id && ! MAD4B_SCP_Search_Contracts::id( $profile_id ) ) ) wp_die( 'Invalid profile.', '', array( 'response' => 400 ) );
		check_admin_referer( 'mad4b_search_provider_connection:' . $id );
		$result = self::post_input( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-search-intelligence&section=providers&profile_id=' . rawurlencode( $profile_id ) ) ); exit;
	}
}
