<?php
function must( $ok, $why ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $why . "\n" ); exit( 2 ); }
}
$cls = 'MAD4B_SCP_Native_Source_Release_Review';
$cls::boot();
$cls::boot();
must( count( $GLOBALS['wp_review_hooks'] ) === 1, 'boot hooks duplicated' );
$cls::register_abilities();
$cls::register_abilities();
must( count( $GLOBALS['wp_review_abilities'] ) === 2, 'read Abilities not exactly two' );
foreach ( $GLOBALS['wp_review_abilities'] as $abi ) {
	must( $abi['meta']['annotations']['readonly'] === true, 'read ability missing readonly' );
	must( $abi['meta']['public'] === false && $abi['meta']['show_in_rest'] === false, 'public surface exposed' );
}
$status = $cls::status();
must( $status['state'] === 'READ_ONLY_DYNAMIC_DISCOVERY', 'wrong status state' );
must( $status['publisher_authorized'] === false, 'read-only status granted authority' );

$cases = array(
	array( 'op' => 'source.code.release', 'ability' => 'mad4b/native-source-release',
		'provider' => 'mad4b-source-release',
		'file' => 'wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js',
		'origin' => 'https://tours.dev.mad4b.com', 'uuid' => 'e5e64bca-ed62-48d2-b2b3-dff693cd702a' ),
	array( 'op' => 'partner.source.update', 'ability' => 'mad4b/partner-source-update',
		'provider' => 'partner-source-provider',
		'file' => 'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php',
		'origin' => 'https://example.partner.invalid', 'uuid' => '12345678-1234-4123-8123-123456789abc' ),
);
foreach ( $cases as $index => $case ) {
	$GLOBALS['review_test_origin'] = $case['origin'];
	$GLOBALS['review_test_uuid'] = $case['uuid'];
	$hash = hash_file( 'sha256', '/var/www/html/' . $case['file'] );
	must( is_string($hash) && strlen($hash) === 64, 'missing actual fixture file' );
	$plan = array(
		'operation_id' => $case['op'],
		'ability_name' => $case['ability'],
		'provider' => $case['provider'],
		'company' => $index === 0 ? 'tours-central' : 'partner-central',
		'environment' => $index === 0 ? 'staging' : 'production',
		'site_uuid' => $case['uuid'],
		'origin' => $case['origin'],
		'plan_sha256' => str_repeat( 'a', 64 ),
		'source_manifest_sha256' => str_repeat( 'b', 64 ),
		'operation_binding_sha256' => str_repeat( 'c', 64 ),
		'git_commit' => str_repeat( 'd', 40 ),
		'files' => array( array( 'target' => $case['file'],
			'expected_before_sha256' => $hash, 'source_sha256' => str_repeat('e',64) ) ),
	);
	must( is_array( $cls::request_input($plan) ), 'dynamic inputs rejected' );
	$held=$cls::preflight($plan);
	must( $held['state'] === 'HOLD__DYNAMIC_PROVIDER_OR_PREIMAGE_NOT_READY',
		'absent write provider treated as ready' );
	must( $held['approval_ticket_created'] === false && $held['authorization_created'] === false,
		'preflight created authority' );
	$GLOBALS['review_providers'][$case['ability']] = $case['provider'];
	$held=$cls::preflight($plan);
	must( $held['state'] === 'HOLD__DYNAMIC_PROVIDER_OR_PREIMAGE_NOT_READY',
		'unregistered write ability treated as mounted' );
	$GLOBALS['wp_review_abilities'][$case['ability']] = array('execute_callback'=>null);
	$ready=$cls::preflight($plan);
	must( $ready['state'] === 'EXACT_CAS_PREVIEW__SIGNED_HOST_ADMISSION_REQUIRED',
		'valid mocked dynamic route missing preview' );
	must( $ready['provider_mounted'] === true && $ready['preimage_match'] === true,
		'valid dynamic route not bound to source preimage' );
	must( strlen( $ready['expected_approval_target_fingerprint'] )===64,
		'fingerprint missing on registered route' );
	$GLOBALS['review_filter_mutates'] = true;
	$changed=$cls::preflight($plan);
	must( $changed['authorization_input_unchanged'] === false &&
		$changed['state'] !== 'EXACT_CAS_PREVIEW__SIGNED_HOST_ADMISSION_REQUIRED',
		'tampered approval input accepted' );
	$GLOBALS['review_filter_mutates'] = false;
	$bad=$plan;
	$bad['files'][0]['expected_before_sha256']=str_repeat('0',64);
	must( $cls::preflight($bad)['preimage_match'] === false, 'CAS drift not denied' );
	$bad=$plan;
	$bad['files'][0]['source_sha256']=$hash;
	must( $cls::preflight($bad)['change_required'] === false, 'no-op allowed' );
	$bad=$plan;
	$bad['source_manifest_sha256']='invalid';
	must( is_wp_error($cls::preflight($bad)), 'invalid manifest SHA accepted' );
	$bad=$plan;
	$bad['files'][0]['target']='wp-content/plugins/../../etc/passwd';
	must( is_wp_error($cls::preflight($bad)), 'path escape accepted' );
	$bad=$plan;
	$bad['extra_authority']=true;
	must( is_wp_error($cls::preflight($bad)), 'extra unsafe field accepted' );
	$bad=$plan;
	$bad['origin']='https://another.invalid';
	must( is_wp_error($cls::preflight($bad)), 'foreign origin accepted' );
	must( $cls::preflight($plan)['original313_receipt_signed'] === false,
		'preview falsely claims Original313 signed evidence' );
}
echo "PASS dynamic native-source Central PHP tests: 2 operators/sites, CAS, no-write, provider, manifest guards\n";
?>
