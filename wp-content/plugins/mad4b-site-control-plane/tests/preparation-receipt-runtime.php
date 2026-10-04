<?php
// Exercise real classification, versioned receipt signatures and fixed mutation dispatch.
if ( ! defined( 'MAD4B_SCP_TEST_RUNTIME' ) ) define( 'MAD4B_SCP_TEST_RUNTIME', true );
require __DIR__ . '/gateway-regression-runtime.php';
$a = new GatewayFixture( 'fixture/receipt-write', 'content', false );
$GLOBALS['abilities'] = array( $a->get_name() => $a );
$GLOBALS['mounted']['mad4b-content'][$a->get_name()] = true;
$GLOBALS['mounted']['mad4b-write'][$a->get_name()] = true;
$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $a->get_name() );
$token = MAD4B_SCP_Preparation_Receipt::issue( $row );
$second_token = MAD4B_SCP_Preparation_Receipt::issue( $row );
$verify = static function ( $value = null ) use ( &$token, $a ) { return MAD4B_SCP_Preparation_Receipt::verify( null === $value ? $token : $value, $a->get_name() ); };
$decode_signature = static function ( $value ) {
	$parts = explode( '.', (string) $value, 2 );
	if ( 2 !== count( $parts ) ) return array();
	$segment = $parts[1]; $padding = strlen( $segment ) % 4; if ( $padding ) $segment .= str_repeat( '=', 4 - $padding );
	$json = base64_decode( strtr( $segment, '-_', '+/' ), true );
	$data = is_string( $json ) ? json_decode( $json, true ) : null;
	return is_array( $data ) ? $data : array();
};
$check_signed = $decode_signature( $token );
check_gateway( is_string( $token ) && ! empty( $check_signed['kid'] ) && 'preparation-receipt-rs256-v1' === $check_signed['profile_id'], 'Preparation receipt did not use the certified versioned crypto profile' );
check_gateway( true === $verify(), 'Valid receipt rejected' );
check_gateway( is_string( $second_token ) && $second_token !== $token && true === $verify( $second_token ), 'Consecutive preparation did not issue a unique valid receipt identity' );
check_gateway( $row['descriptor_sha256'] === MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $a->get_name() )['descriptor_sha256'], 'Classifier and canonical descriptor diverged' );
check_gateway( is_wp_error( $verify( $token . '0' ) ), 'Forged signature accepted' );
check_gateway( is_wp_error( $verify( array() ) ), 'Non-string receipt accepted' );
check_gateway( is_wp_error( $verify( str_repeat( 'a', 4097 ) ) ), 'Unbounded receipt accepted' );
check_gateway( is_wp_error( MAD4B_SCP_Preparation_Receipt::verify( $token, 'fixture/other' ) ), 'Cross-target receipt accepted' );

$GLOBALS['receipt_user'] = 2;
check_gateway( is_wp_error( $verify() ), 'Cross-user receipt accepted' );
$GLOBALS['receipt_user'] = 1; $GLOBALS['receipt_caps'] = array( 'read' => false );
check_gateway( is_wp_error( $verify() ), 'Changed effective capabilities accepted' );
unset( $GLOBALS['receipt_caps'] ); $GLOBALS['blog'] = 2;
check_gateway( is_wp_error( $verify() ), 'Cross-blog receipt accepted' );
$GLOBALS['blog'] = 1;
$a->lane = 'write';
check_gateway( is_wp_error( $verify() ), 'Original lane drift accepted' );
$a->lane = 'content'; $a->boundary = false;
check_gateway( is_wp_error( $verify() ), 'Lost execution boundary accepted' );
$a->boundary = true;

// Rotation keeps the previous key verifiable only during overlap; revocation is immediate.
$old_signature = $decode_signature( $token );
$profile_id = (string) $old_signature['profile_id'];
$old_kid = (string) $old_signature['kid'];
$rotated = MAD4B_SCP_Crypto_Profile::rotate( $profile_id );
check_gateway( is_array( $rotated ) && $old_kid !== $rotated['current_kid'] && true === $verify(), 'Preparation receipt did not survive the certified rotation overlap' );
$revoked = MAD4B_SCP_Crypto_Profile::revoke( $profile_id, $old_kid );
check_gateway( is_array( $revoked ) && is_wp_error( $verify() ), 'Revoked preparation signing key remained valid' );
$token = MAD4B_SCP_Preparation_Receipt::issue( $row );
check_gateway( is_string( $token ) && true === $verify(), 'Fresh preparation receipt did not use the rotated current key' );

// Server-signed old/future evidence must fail temporal checks.
$parts = explode( '.', $token, 2 );
$payload_segment = $parts[0]; $padding = strlen( $payload_segment ) % 4; if ( $padding ) $payload_segment .= str_repeat( '=', 4 - $padding );
$payload = json_decode( base64_decode( strtr( $payload_segment, '-_', '+/' ), true ), true );
$sign = static function ( $p ) {
	$body = rtrim( strtr( base64_encode( json_encode( $p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), '+/', '-_' ), '=' );
	$sig = MAD4B_SCP_Crypto_Profile::sign_digest_for_purpose( 'preparation_receipt', hash( 'sha256', $body ) );
	if ( is_wp_error( $sig ) ) return '';
	$sig_json = json_encode( $sig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return $body . '.' . rtrim( strtr( base64_encode( $sig_json ), '+/', '-_' ), '=' );
};
foreach ( array( -1000, 1000 ) as $offset ) {
	$dated = $payload; $dated['issued_at'] += $offset; $dated['expires_at'] += $offset;
	check_gateway( is_wp_error( $verify( $sign( $dated ) ) ), 'Expired/future receipt accepted' );
}

// Isolate the direct expires_at guard from the overlapping issued_at age policy.
// At the exact TTL boundary assert_timestamp() is still valid because age == max_age,
// while expires_at == now must be rejected by the receipt's own expiry condition.
$boundary_now = 2000000000;
$boundary_ttl = MAD4B_SCP_Time_Policy::bounded_ttl( 'preparation_receipt', MAD4B_SCP_Preparation_Receipt::TTL );
check_gateway( ! is_wp_error( $boundary_ttl ) && 0 < $boundary_ttl, 'Preparation receipt boundary TTL unavailable' );
$clock = MAD4B_SCP_Time_Policy::set_test_clock( $boundary_now, 1000 );
check_gateway( ! is_wp_error( $clock ), 'Preparation receipt deterministic boundary clock unavailable' );
$expired_boundary = $payload;
$expired_boundary['issued_at'] = $boundary_now - (int) $boundary_ttl;
$expired_boundary['expires_at'] = $boundary_now;
check_gateway( is_wp_error( $verify( $sign( $expired_boundary ) ) ), 'Receipt expiring exactly at now was accepted' );
MAD4B_SCP_Time_Policy::reset_test_clock();
$malformed = $payload; $malformed['descriptor_sha256'] = array();
check_gateway( is_wp_error( $verify( $sign( $malformed ) ) ), 'Malformed signed payload accepted' );
$nonce_missing = $payload; unset( $nonce_missing['nonce'] );
check_gateway( is_wp_error( $verify( $sign( $nonce_missing ) ) ), 'Signed preparation receipt without nonce accepted' );

// Legacy HMAC-shaped receipts are never accepted after crypto-profile migration.
$legacy_body = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
$legacy_token = $legacy_body . '.' . hash_hmac( 'sha256', $legacy_body, hash_hmac( 'sha256', MAD4B_SCP_Preparation_Receipt::CONTRACT, wp_salt( 'auth' ), true ) );
check_gateway( is_wp_error( $verify( $legacy_token ) ), 'Legacy local-HMAC preparation receipt was accepted after migration' );

$input = array( 'ability_name' => $a->get_name(), 'expected_input_schema_sha256' => $row['input_schema_sha256'], 'expected_execution_lane' => 'content', 'expected_classification_sha256' => $row['classification_sha256'], 'expected_authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), 'preparation_receipt' => $token, 'input' => array() );
check_gateway( ! is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Valid receipt failed fixed dispatcher' );
$a->permission = false;
check_gateway( is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Receipt substituted for live permission callback' );
$a->permission = true;
$bad = $input; $bad['preparation_receipt'] .= '0';
check_gateway( is_wp_error( $dispatcher->write_execute( $bad ) ) && 1 === $a->calls, 'Forged receipt reached target callback' );
foreach ( array( 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' ) as $key ) {
	$bad = $input; unset( $bad[$key] );
	check_gateway( is_wp_error( $dispatcher->write_execute( $bad ) ) && 1 === $a->calls, 'Partial pins downgraded prepared contract' );
	$bad = $input; $bad[$key] = null;
	check_gateway( is_wp_error( $dispatcher->write_execute( $bad ) ) && 1 === $a->calls, 'Null pin downgraded prepared contract' );
}
echo "PASS preparation receipts: versioned crypto profile, rotation/revocation, temporal, user/site/contract drift, dispatch and live permission isolation\n";
