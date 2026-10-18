<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/mad4b-cross-plugin-lifecycle/' );
class WP_Error {
    private $error;
    public function __construct( $error, $message = '' ) { $this->error = $error; }
    public function get_error_code() { return $this->error; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['env'] = 'staging';
$GLOBALS['provider_match'] = true;
function wp_get_environment_type() { return $GLOBALS['env']; }
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled() { return true; }
    public static function current_environment() { return $GLOBALS['env']; }
}
class MAD4B_SCP_Provider_Contracts {
    public static function get( $provider ) {
        return $GLOBALS['provider_match'] ? array(
            'plugin_file' => 'mock/mock.php', 'version' => '1.0.0',
            'archive_sha256' => str_repeat( 'd', 64 ) ) : array();
    }
}
class MAD4B_SCP_Cross_Plugin_Deployment {
    public static $native_calls = 0;
    public static $certified = false;
    public static function sources() {
        return array( 'mock' => array(
            'id' => 'mock', 'repository' => 'reviewed/plugins',
            'plugin_file' => 'mock/mock.php', 'provider_id' => 'mock',
            'review_state' => self::$certified ? 'source_reviewed_artifact_certified' : 'source_reviewed_artifact_pending',
            'package_certification' => self::$certified ? 'certified' : 'pending',
            'candidate_source_commit_sha' => str_repeat( 'a', 40 ),
            'candidate_artifact_version' => '1.0.0',
            'source_commit_sha' => self::$certified ? str_repeat( 'a', 40 ) : '',
            'artifact_sha256' => self::$certified ? str_repeat( 'd', 64 ) : '',
            'artifact_version' => self::$certified ? '1.0.0' : '',
            'production_promotion_attestation' => '',
        ) );
    }
    public static function plan( $input ) {
        self::$native_calls++;
        return array( 'eligible' => true, 'native_plan_sha256' => str_repeat( 'e', 64 ),
            'native_apply_input' => array( 'provider_id' => 'mock',
                'expected_plan_sha256' => str_repeat( 'e', 64 ) ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cross-plugin-lifecycle.php';
function ck( $yes, $why ) { if ( ! $yes ) { fwrite( STDERR, "FAIL: $why\n" ); exit(1); } }
$c = 'MAD4B_SCP_Cross_Plugin_Lifecycle';
ck( is_wp_error( $c::certification_plan( array( 'source_id' => '../bad' ) ) ), 'unreviewed injection' );
$pending = $c::certification_plan( array( 'source_id' => 'mock' ) );
ck( ! $pending['candidate_is_certified']
    && in_array( 'package_certification_pending', $pending['blockers'], true ), 'candidate must not certify' );
$staging = $c::staging_apply_plan( array( 'source_id' => 'mock' ) );
ck( ! $staging['eligible_for_separate_owner_authorization']
    && MAD4B_SCP_Cross_Plugin_Deployment::$native_calls === 0, 'no native plan on uncertified source' );
MAD4B_SCP_Cross_Plugin_Deployment::$certified = true;
ck( $c::certification_plan( array( 'source_id' => 'mock' ) )['candidate_is_certified'], 'exact certificate' );
$good = $c::staging_apply_plan( array( 'source_id' => 'mock' ) );
ck( $good['eligible_for_separate_owner_authorization']
    && $good['native_executor'] === 'mad4b/plugin-package-apply'
    && $good['production_allowed'] === false
    && ! $good['mutation_performed'], 'staging existing governed executor only' );
$GLOBALS['env'] = 'production';
ck( ! $c::staging_apply_plan( array( 'source_id' => 'mock' ) )['eligible_for_separate_owner_authorization'],
    'production cannot reuse Staging' );
$prod = $c::production_promotion_plan( array( 'source_id' => 'mock' ) );
ck( ! $prod['eligible'] && in_array( 'independent_production_executor_not_certified', $prod['blockers'], true )
    && ! $prod['inherited_staging_authority'], 'production independent denial' );
$GLOBALS['provider_match'] = false;
ck( ! $c::certification_plan( array( 'source_id' => 'mock' ) )['candidate_is_certified'],
    'certificate needs independent provider identity' );
echo "CROSS_PLUGIN_LIFECYCLE_GATES: PASS\n";
