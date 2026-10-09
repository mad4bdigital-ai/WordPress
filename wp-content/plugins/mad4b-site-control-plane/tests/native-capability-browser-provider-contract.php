<?php
/** Stateless signed WordPress semantic oracle and RSA-attested reducer contract. */
define( 'ABSPATH', __DIR__ . '/' );
function native_expect( $ok, $msg ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $msg\n" ); exit( 1 ); }
}
if ( ! function_exists( 'openssl_pkey_new' ) ) { fwrite(STDERR, "OpenSSL needed\n"); exit(2); }
$key_pair = openssl_pkey_new( array(
    'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA
) );
native_expect( $key_pair !== false, 'generate independent browser test signer' );
openssl_pkey_export( $key_pair, $private_pem );
$details = openssl_pkey_get_details( $key_pair );
define( 'MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM', $details['key'] );
$der = base64_decode( preg_replace(
    '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\\s+/', '', $details['key'] ), true );
$GLOBALS['native_test_key_id'] = 'rsa-spki-sha256:' . hash( 'sha256', $der );
$GLOBALS['native_build'] = array(
    'runtime_manifest_match' => true, 'stale' => false,
    'source_commit_sha' => str_repeat( 'a', 40 ),
    'build_fingerprint' => str_repeat( 'b', 64 )
);
$GLOBALS['native_path'] = '/public/';
$GLOBALS['native_prefix'] = '';
$GLOBALS['native_revision'] = str_repeat( 'c', 32 );
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status() { return $GLOBALS['native_build']; }
}
class MAD4B_SCP_Site_Capability_Discovery {
    public static function observe( $origin, $providers ) {
        native_expect( isset( $providers[ MAD4B_SCP_Native_Capability_Browser_Provider::ID ] ),
            'semantic fingerprint uses live registered providers, never empty registry' );
        return array( 'discovery_complete' => true, 'snapshot_sha256' => str_repeat( 'd', 64 ) );
    }
}
class MAD4B_SCP_Browser_Acceptance_Provider_Registry {
    public function all() {
        return array(
            MAD4B_SCP_Native_Capability_Browser_Provider::ID => array(
                'descriptor' => MAD4B_SCP_Native_Capability_Browser_Provider::descriptor()
            )
        );
    }
}
class MAD4B_SCP_Browser_Acceptance_Admin_UI {
    public static function public_selection() {
        return array( 'preference_valid' => true, 'configuration_revision' => $GLOBALS['native_revision'] );
    }
}
function home_url( $path = '/' ) { return 'https://demo.example' . $GLOBALS['native_prefix'] . $path; }
function wp_salt( $scheme = '' ) { return str_repeat( 'native-secret-', 5 ); }
function get_posts( $args ) {
    native_expect( $args['post_type'] === 'page' && $args['post_status'] === 'publish' &&
        $args['posts_per_page'] === MAD4B_SCP_Native_Capability_Browser_Provider::MAX_SCAN,
        'only publicly published pages qualify' );
    return array(
        (object) array( 'ID' => 3, 'post_type' => 'page', 'post_status' => 'publish',
            'post_password' => 'protected' ),
        (object) array( 'ID' => 4, 'post_type' => 'page', 'post_status' => 'draft',
            'post_password' => '' ),
        (object) array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish',
            'post_password' => '' ),
        (object) array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish',
            'post_password' => '' ),
        (object) array( 'ID' => 43, 'post_type' => 'page', 'post_status' => 'publish',
            'post_password' => '' )
    );
}
function get_permalink( $id ) { return 'https://demo.example' . $GLOBALS['native_prefix'] . $GLOBALS['native_path']; }
function wp_get_canonical_url( $id ) { return 'https://demo.example' . $GLOBALS['native_prefix'] . $GLOBALS['native_path']; }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-native-capability-browser-provider.php';
$c = 'MAD4B_SCP_Native_Capability_Browser_Provider';
$registered = $c::register( array() );
native_expect( isset( $registered[ $c::ID ] ) && $registered[ $c::ID ]['authorizing'] === false,
    'registered safely' );
native_expect( $c::capabilities()['independent_reducer'] === true, 'reducer declared' );
native_expect( $c::capabilities()['browser_attestation_key_id'] === $GLOBALS['native_test_key_id'],
    'native trusted public key fingerprint readback matches external signer' );
native_expect( $c::capabilities()['browser_attestation_public_key_valid'] === true &&
    $c::capabilities()['browser_attestation_ready'] === false &&
    $c::capabilities()['release_ready'] === false &&
    $c::capabilities()['replay_prevention_verified'] === false,
    'public trust anchor never masquerades as live browser or anti-replay proof' );
$plan = $c::plan( array( 'profile_id' => $c::PROFILE, 'suite' => 'browser_runtime' ) );
native_expect( $plan['state'] === 'ready' && $plan['case_count'] === 1,
    'native read oracle generates signed plan' );
native_expect( $plan['cases'][0]['expected']['path'] === '/public/' &&
    $plan['cases'][0]['case_id'] === 'page-42', 'password pages and nonpublished post records excluded' );
native_expect( strlen( $plan['plan_signature'] ) === 64, 'HMAC plan signature produced' );
$another = $c::plan( array( 'profile_id' => $c::PROFILE, 'suite' => 'browser_runtime' ) );
native_expect( $another['plan_digest'] !== $plan['plan_digest'] &&
    $another['challenge']['nonce'] !== $plan['challenge']['nonce'],
    'two issued plans even within one second must have unique nonces and HMAC digests' );
$evidence = array(
    'contract' => $c::EVIDENCE, 'plan_digest' => $plan['plan_digest'],
    'plan_signature' => $plan['plan_signature'], 'origin' => $plan['origin'],
    'build_identity' => $plan['build_identity'],
    'observer' => array(
        'contract' => 'mad4b.capability-browser-observer.v1',
        'javascript_runtime' => true, 'runner_javascript_runtime' => true,
        'page_javascript_enabled' => false, 'browser_engine' => 'test:Chromium',
        'execution_mode' => 'managed_browser_agent',
        'plan_issued_at' => $plan['challenge']['issued_at'],
    ),
    'cases' => array(
        array(
            'case_id' => $plan['cases'][0]['case_id'],
            'capability_id' => 'seo.canonical', 'probe_type' => 'public.canonical_path',
            'challenge_nonce' => $plan['challenge']['nonce'],
            'http_status' => 200, 'observed' => array( 'path' => '/public/' ),
            'matches_expected' => true, 'certification_issued' => false, 'authorizing' => false,
        )
    )
);
$method = new ReflectionMethod( $c, 'canonical_json' );
$method->setAccessible( true );
native_expect( '{"items":[]}' === $method->invoke( null, array( 'items' => array() ) ),
    'empty PHP arrays must serialize as JSON lists for cross-language RSA signatures' );
function native_sign( $evidence, $method, $private_pem ) {
    $material = $method->invoke( null, $evidence );
    openssl_sign( $material, $signed_bytes, $private_pem, OPENSSL_ALGO_SHA256 );
    $evidence['attestation'] = array(
        'algorithm' => 'rsa-sha256', 'key_id' => $GLOBALS['native_test_key_id'],
        'signature' => base64_encode( $signed_bytes )
    );
    return $evidence;
}
$proof = native_sign( $evidence, $method, $private_pem );
$request = array(
    'profile_id' => $c::PROFILE, 'suite' => 'browser_runtime',
    'plan_digest' => $plan['plan_digest'], 'plan_signature' => $plan['plan_signature'],
    'evidence' => $proof
);
$passed = $c::result( $request );
native_expect( $passed['verdict'] === 'PASS' &&
    $passed['verification']['browser_runtime_parity_verified'] === true,
    'valid independent browser signature permits PASS' );
native_expect( $passed['authorizing'] === false && $passed['receipt_authorizing'] === false,
    'PASS cannot grant authority' );
native_expect( $passed['release_ready'] === false &&
    $passed['globally_unique_consumption_proven'] === false &&
    $passed['consumption_authority'] === 'separate_governed_authority_required',
    'a repeatable read-only WordPress PASS cannot claim global one-time release authorization' );
native_expect( $c::result( $request )['verdict'] === 'PASS' &&
    $c::result( $request )['release_ready'] === false,
    'replayed same signed observation remains observable but cannot issue a release certificate' );
native_expect( strlen( $passed['receipt_signature'] ) === 64, 'server receipt signed' );
$bad_js = $request;
$bad_js['evidence']['observer']['page_javascript_enabled'] = true;
$bad_js['evidence'] = native_sign( array_diff_key( $bad_js['evidence'],
    array( 'attestation' => true ) ), $method, $private_pem );
native_expect( $c::result( $bad_js )['verdict'] === 'BLOCKED',
    'signed passive observation cannot falsely claim page JavaScript was enabled' );
$changed = $request;
$changed['evidence']['cases'][0]['observed']['path'] = '/other/';
native_expect( $c::result( $changed )['verdict'] === 'BLOCKED',
    'unsigned tampering is rejected before comparing observations' );
$changed['evidence'] = native_sign( array_diff_key( $changed['evidence'], array( 'attestation' => true ) ), $method, $private_pem );
native_expect( $c::result( $changed )['verdict'] === 'FAIL',
    'genuinely observed different canonical classified as FAIL' );
$bad_key_id = $request;
$bad_key_id['evidence']['attestation']['key_id'] = 'rsa-spki-sha256:' . str_repeat( 'e', 64 );
native_expect( $c::result( $bad_key_id )['verdict'] === 'BLOCKED',
    'RSA signature from correct key with forged identity denied' );
$changed = $request; $changed['evidence']['cases'][0]['challenge_nonce'] = str_repeat( '0', 32 );
native_expect( $c::result( $changed )['verdict'] === 'BLOCKED',
    'challenge nonce tampering rejects stateless plan reconstruction' );
$malformed = $request;
$malformed['evidence']['cases'][0]['http_status'] = '200';
$malformed['evidence'] = native_sign( array_diff_key( $malformed['evidence'],
    array( 'attestation' => true ) ), $method, $private_pem );
native_expect( $c::result( $malformed )['verdict'] === 'BLOCKED',
    'signed string HTTP status is evidence infrastructure failure, not product defect' );
$malformed = $request;
$malformed['evidence']['observer']['release_ready'] = true;
$malformed['evidence'] = native_sign( array_diff_key( $malformed['evidence'],
    array( 'attestation' => true ) ), $method, $private_pem );
native_expect( $c::result( $malformed )['verdict'] === 'BLOCKED',
    'signed observer cannot invent release-ready claim' );
$malformed = $request;
$malformed['evidence']['business_state_mutated'] = true;
$malformed['evidence'] = native_sign( array_diff_key( $malformed['evidence'],
    array( 'attestation' => true ) ), $method, $private_pem );
native_expect( $c::result( $malformed )['verdict'] === 'BLOCKED',
    'signed extra evidence fields rejected' );
$changed = $request; $changed['plan_signature'] = str_repeat( '0', 64 );
native_expect( $c::result( $changed )['verdict'] === 'BLOCKED', 'forged plan signature denied' );
$changed = $request; $changed['evidence']['observer']['plan_issued_at'] -= 2000;
native_expect( $c::result( $changed )['verdict'] === 'BLOCKED', 'expired stateless epoch rejected' );
$GLOBALS['native_revision'] = str_repeat( 'f', 32 );
native_expect( $c::result( $request )['verdict'] === 'BLOCKED', 'operator generation drift denied' );
$GLOBALS['native_revision'] = str_repeat( 'c', 32 );
$GLOBALS['native_path'] = '/changed/';
native_expect( $c::result( $request )['verdict'] === 'BLOCKED', 'oracle moved since plan denied' );
$GLOBALS['native_path'] = '/public/';
$GLOBALS['native_build']['runtime_manifest_match'] = false;
native_expect( $c::plan( array( 'profile_id' => $c::PROFILE, 'suite' => 'browser_runtime' ) )['state'] === 'blocked',
    'unverified package cannot create browser oracle' );
$GLOBALS['native_build']['runtime_manifest_match'] = true;
native_expect( $c::plan( array( 'profile_id' => 'wrong-profile', 'suite' => 'browser_runtime' ) )['state'] === 'blocked',
    'unknown profile denied' );

$GLOBALS['native_prefix'] = '/travel';
$nested = $c::plan( array( 'profile_id' => $c::PROFILE, 'suite' => 'browser_runtime' ) );
native_expect( $nested['state'] === 'ready' &&
  $nested['origin'] === 'https://demo.example/travel/' &&
  $nested['cases'][0]['page_path'] === '/travel/public/',
  'nested WordPress installation keeps canonical observation inside site scope' );
$GLOBALS['native_prefix'] = '';
echo "MAD4B_NATIVE_CAPABILITY_BROWSER_PROVIDER: PASS\n";
