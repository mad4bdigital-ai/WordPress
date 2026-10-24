<?php
/** Hermetic contract: reviewed optional direct tools never gain mount/authority. */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
function sanitize_key( $value ) { return strtolower( (string) $value ); }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['env'] ?? 'staging'; }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static function effective() { return empty( $GLOBALS['write_denied'] ); }
}
class MAD4B_SCP_AI_Approval {
    public static function catalog_eligible() { return empty( $GLOBALS['ai_denied'] ); }
}
class MAD4B_SCP_Servers {
    public static function ability_is_mounted( $server, $name ) {
        return $server === 'mad4b-write' && empty( $GLOBALS['mount_denied'] );
    }
    public static function chatgpt_reviewed_direct_step_up_tools() {
        return MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools();
    }
}
class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function projected_ability_names() { return array( 'demo/optional-read' ); }
}
class MAD4B_SCP_Direct_Profile {
    public static function catalog_tools() { return array( 'mad4b/site-profile-view' ); }
}
class Fixture_Ability {
    private $readonly;
    public function __construct( $readonly ) { $this->readonly = $readonly; }
    public function get_meta() { return array( 'annotations' => array( 'readonly' => $this->readonly ) ); }
}
function wp_has_ability( $name ) { return in_array( $name, array( 'mad4b/approval-plan', 'mad4b/approval-ai-decide' ), true ); }
function wp_get_ability( $name ) {
    return new Fixture_Ability( ! empty( $GLOBALS['readonly'] ) );
}
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-chatgpt-approval-bootstrap.php';
function check( $ok, $name ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 2 ); }
    echo 'PASS ' . $name . PHP_EOL;
}
$both = MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools();
check( $both === array( 'mad4b/approval-plan', 'mad4b/approval-ai-decide' ), 'only mounted nonreadonly reviewed approvals' );
$requested = array( 'mad4b/write-execute', 'mad4b/approval-plan', 'mad4b/approval-ai-decide',
    'demo/optional-read', 'mad4b/site-profile-view', 'demo/foreign-ability' );
$optional = MAD4B_SCP_ChatGPT_Approval_Bootstrap::materialization_optional_tools( $requested );
check( $optional === array( 'demo/optional-read', 'mad4b/approval-plan',
    'mad4b/approval-ai-decide', 'mad4b/site-profile-view' ), 'optional classification excludes required transport and foreign ability' );
$GLOBALS['env'] = 'production';
check( MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools() === array(), 'Production approval bootstrap hidden' );
unset( $GLOBALS['env'] );
$GLOBALS['write_denied'] = true;
check( MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools() === array(), 'unprovisioned Write authority hidden' );
unset( $GLOBALS['write_denied'] );
$GLOBALS['mount_denied'] = true;
check( MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools() === array(), 'unmounted approvals hidden' );
unset( $GLOBALS['mount_denied'] );
$GLOBALS['readonly'] = true;
check( MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools() === array(), 'readonly-tagged approval excluded' );
unset( $GLOBALS['readonly'] );
$GLOBALS['ai_denied'] = true;
check( MAD4B_SCP_ChatGPT_Approval_Bootstrap::catalog_tools() === array( 'mad4b/approval-plan' ), 'AI approval ineligible hidden' );
echo 'PASS optional tool classification is non-authorizing' . PHP_EOL;
