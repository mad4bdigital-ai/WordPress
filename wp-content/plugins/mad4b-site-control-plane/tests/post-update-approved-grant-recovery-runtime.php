<?php
define( 'ABSPATH', __DIR__ );
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ); }
class WP_Error { private $code; public function __construct( $code ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class MAD4B_SCP_Environment_Convergence_Policy {
    public static $environment = 'production';
    public static function current() { return array( 'auto_convergence_eligible' => self::$environment === 'staging', 'environment' => self::$environment ); }
}
class MAD4B_SCP_Post_Update_Continuation {
    public static $baseline = array();
    public static function approved_grant_baseline() { return self::$baseline; }
}
class MAD4B_SCP_Staging_Write_Grant_Reconciliation {
    public static $calls = 0;
    public static function can_execute() { return new WP_Error( 'oauth_required' ); }
    public static function reconcile( $input ) { self::$calls++; throw new RuntimeException( 'No live grant mutation allowed in hermetic test' ); }
}
class MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan {
    public static function plan() { throw new RuntimeException( 'No plan without OAuth' ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-post-update-approved-grant-recovery.php';
$r = MAD4B_SCP_Post_Update_Approved_Grant_Recovery::attempt();
if ( 'BLOCKED' !== $r['state'] || 'trusted_staging_profile_required' !== $r['reason'] ) throw new RuntimeException( 'Production must be blocked' );
MAD4B_SCP_Environment_Convergence_Policy::$environment = 'staging';
$r = MAD4B_SCP_Post_Update_Approved_Grant_Recovery::attempt();
if ( 'DEFER' !== $r['state'] || 'sealed_previous_baseline_or_permit_required' !== $r['reason'] ) throw new RuntimeException( 'Missing baseline must defer' );
MAD4B_SCP_Post_Update_Continuation::$baseline = array( 'seal_verified' => true );
$r = MAD4B_SCP_Post_Update_Approved_Grant_Recovery::attempt();
if ( 'DEFER' !== $r['state'] || 'verified_oauth_step_up_required' !== $r['reason'] || MAD4B_SCP_Staging_Write_Grant_Reconciliation::$calls !== 0 ) throw new RuntimeException( 'Cron without OAuth must never mutate' );
echo "POST_UPDATE_APPROVED_GRANT_RECOVERY_RUNTIME: PASS (production, baseline, cron)\n";
