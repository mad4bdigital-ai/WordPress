<?php
// Exercise real classification, evidence verification and fixed mutation dispatch.
require __DIR__ . '/gateway-regression-runtime.php';
$a = new GatewayFixture( 'fixture/receipt-write', 'content', false );
$GLOBALS['abilities'] = array( $a->get_name() => $a );
$GLOBALS['mounted']['mad4b-content'][$a->get_name()] = true;
$GLOBALS['mounted']['mad4b-write'][$a->get_name()] = true;
$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $a->get_name() );
$token = MAD4B_SCP_Preparation_Receipt::issue( $row );
$verify = static function ( $value = null ) use ( $token, $a ) { return MAD4B_SCP_Preparation_Receipt::verify( null === $value ? $token : $value, $a->get_name() ); };
check_gateway( true === $verify(), 'Valid receipt rejected' );
check_gateway( $row['descriptor_sha256'] === MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $a->get_name() )['descriptor_sha256'], 'Classifier and canonical descriptor diverged' );
check_gateway( is_wp_error( $verify( $token . '0' ) ), 'Forged signature accepted' );
check_gateway( is_wp_error( $verify( array() ) ), 'Non-string receipt accepted' );
check_gateway( is_wp_error( $verify( str_repeat( 'a', 4097 ) ) ), 'Unbounded receipt accepted' );
check_gateway( is_wp_error( MAD4B_SCP_Preparation_Receipt::verify( $token, 'fixture/other' ) ), 'Cross-target receipt accepted' );
$GLOBALS['receipt_user'] = 2;
check_gateway( is_wp_error( $verify() ), 'Cross-user receipt accepted' );
$GLOBALS['receipt_user'] = 1; $GLOBALS['receipt_caps'] = array( 'read' => false );
check_gateway( is_wp_error( $verify() ), 'Changed effective capabilities accepted' );
unset( $GLOBALS['receipt_caps'] ); $GLOBALS['signing_salt'] = 'rotated';
check_gateway( is_wp_error( $verify() ), 'Rotated signing key accepted' );
unset( $GLOBALS['signing_salt'] ); $GLOBALS['blog'] = 2;
check_gateway( is_wp_error( $verify() ), 'Cross-blog receipt accepted' );
$GLOBALS['blog'] = 1;
$a->lane = 'write';
check_gateway( is_wp_error( $verify() ), 'Original lane drift accepted' );
$a->lane = 'content'; $a->boundary = false;
check_gateway( is_wp_error( $verify() ), 'Lost execution boundary accepted' );
$a->boundary = true;
// Server-signed old/future evidence must fail temporal checks too.
$parts = explode( '.', $token );
$payload = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
$sign = static function ( $p ) {
 $body = rtrim( strtr( base64_encode( json_encode( $p ) ), '+/', '-_' ), '=' );
 return $body . '.' . hash_hmac( 'sha256', $body, hash_hmac( 'sha256', MAD4B_SCP_Preparation_Receipt::CONTRACT, wp_salt( 'auth' ), true ) );
};
foreach ( array( -1000, 1000 ) as $offset ) {
 $dated = $payload; $dated['issued_at'] += $offset; $dated['expires_at'] += $offset;
 check_gateway( is_wp_error( $verify( $sign( $dated ) ) ), 'Expired/future receipt accepted' );
}
$malformed = $payload; $malformed['descriptor_sha256'] = array();
check_gateway( is_wp_error( $verify( $sign( $malformed ) ) ), 'Malformed signed payload accepted' );
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
echo "PASS preparation receipts: temporal, signature, user/site/contract drift, dispatch and live permission isolation\n";
