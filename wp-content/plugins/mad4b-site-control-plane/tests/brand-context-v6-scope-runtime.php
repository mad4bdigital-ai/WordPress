<?php
/**
 * Isolated v6 cross-brand idempotency and replay denial regression.
 * Does not create ContentJobs, provider assets, grants, or live site state.
 */
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $code = 'stub_error' ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
final class MAD4B_SCP_Site_Profile {
    public static $site_uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    public static function site_uuid() { return self::$site_uuid; }
}
final class MAD4B_SCP_Content_Jobs {
    public static $job = array();
    public static function get_job( $input ) {
        return array( 'job' => self::$job );
    }
}
final class MAD4B_SCP_Artifacts {
    public static $artifact = array();
    public static function get_artifact( $input ) {
        return array( 'artifact' => self::$artifact );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-brand-context-builder.php';
$builder = 'MAD4B_SCP_Brand_Context_Builder';
$call = static function ( $method, $args ) use ( $builder ) {
    $reflection = new ReflectionMethod( $builder, $method );
    $reflection->setAccessible( true ); // Required for PHP 7.4; safe on PHP 8.x.
    return $reflection->invokeArgs( null, $args );
};
$must = static function ( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL v6 scope: ' . $label . PHP_EOL ); exit( 1 ); }
};
$site = MAD4B_SCP_Site_Profile::$site_uuid;
$ref = 'brand-primary';
$key = $call( 'draft_scoped_idempotency_key', array( $site, $ref, 'tone_of_voice', str_repeat( 'a', 64 ) ) );
$must( preg_match( '/^[a-f0-9]{64}$/', $key ), 'sha256 key' );
foreach ( array(
    array( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $ref, 'tone_of_voice', str_repeat( 'a', 64 ) ),
    array( $site, 'brand-other', 'tone_of_voice', str_repeat( 'a', 64 ) ),
    array( $site, $ref, 'editorial_guidelines', str_repeat( 'a', 64 ) ),
    array( $site, $ref, 'tone_of_voice', str_repeat( 'b', 64 ) ),
) as $changed ) {
    $must( ! hash_equals( $key, $call( 'draft_scoped_idempotency_key', $changed ) ), 'scoped identity must change' );
}
$job_id = 'job-1';
$artifact_id = 'artifact-1';
$record = array( 'artifact' => array(
    'job_id' => $job_id, 'artifact_id' => $artifact_id,
    'status' => 'active', 'artifact_type' => 'brand_context_draft',
) );
MAD4B_SCP_Content_Jobs::$job = array(
    'brand_id' => $ref, 'site_uuid' => $site, 'job_id' => $job_id,
    'content_type' => 'brand_context_generation',
);
MAD4B_SCP_Artifacts::$artifact = array(
    'artifact_id' => $artifact_id, 'job_id' => $job_id, 'status' => 'active',
);
$matches = static function ( $candidate, $brand ) use ( $call ) {
    return $call( 'draft_result_matches_trusted_scope', array( $candidate, $brand ) );
};
$must( $matches( $record, $ref ) === true, 'positive same-brand trusted readback' );
$must( $matches( $record, 'brand-other' ) === false, 'cross-brand replay denied' );
MAD4B_SCP_Content_Jobs::$job['site_uuid'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$must( $matches( $record, $ref ) === false, 'cross-site replay denied' );
MAD4B_SCP_Content_Jobs::$job['site_uuid'] = $site;
MAD4B_SCP_Artifacts::$artifact['job_id'] = 'other-job';
$must( $matches( $record, $ref ) === false, 'artifact mismatch denied' );
MAD4B_SCP_Artifacts::$artifact['job_id'] = $job_id;
$bad = $record;
$bad['artifact']['status'] = 'deleted';
$must( $matches( $bad, $ref ) === false, 'deleted draft denied' );
MAD4B_SCP_Content_Jobs::$job['content_type'] = 'unrelated_type';
$must( $matches( $record, $ref ) === false, 'unrelated ContentJob denied' );
echo "BRAND_V6_SCOPE_RUNTIME_PASS\n";
