<?php
/** Offline invariant: only the native transport is required; optional tools
 * cannot displace it or acquire permission by being materialized. */
define( 'ABSPATH', __DIR__ . '/' );
final class MAD4B_SCP_Servers {
    public static function chatgpt_direct_read_transport_tools() {
        return array( 'mad4b/site-info', 'mad4b/read-execute' );
    }
    public static function chatgpt_dispatch_transport_tools() {
        return array( 'mad4b/write-execute', 'mad4b/developer-execute' );
    }
    public static function chatgpt_base_tools() {
        return array_merge( self::chatgpt_direct_read_transport_tools(),
            self::chatgpt_dispatch_transport_tools(),
            array( 'mad4b/reviewed-auxiliary-read', 'mad4b/chatgpt-tool-projection-apply' ) );
    }
    public static function chatgpt_reviewed_direct_step_up_tools() {
        return array( 'mad4b/chatgpt-tool-projection-apply' );
    }
}
final class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function projected_ability_names() {
        return array( 'mad4b-ci/verified-dynamic-read' );
    }
}
final class MAD4B_SCP_Direct_Profile {
    public static function catalog_tools() {
        return array( 'mad4b/direct-profile-optional' );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-chatgpt-approval-bootstrap.php';
$optional = 'MAD4B_SCP_ChatGPT_Approval_Bootstrap';
$required = array_merge( MAD4B_SCP_Servers::chatgpt_direct_read_transport_tools(),
    MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools() );
$candidates = array_merge( MAD4B_SCP_Servers::chatgpt_base_tools(),
    MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names(),
    MAD4B_SCP_Direct_Profile::catalog_tools(),
    array( 'external/never-reviewed' ) );
$classified = $optional::materialization_optional_tools( $candidates );
foreach ( $required as $name ) {
    if ( in_array( $name, $classified, true ) )
        throw new RuntimeException( 'REQUIRED_TRANSPORT_BECAME_OPTIONAL:' . $name );
}
foreach ( array( 'mad4b/reviewed-auxiliary-read', 'mad4b/chatgpt-tool-projection-apply',
    'mad4b-ci/verified-dynamic-read', 'mad4b/direct-profile-optional' ) as $name ) {
    if ( ! in_array( $name, $classified, true ) )
        throw new RuntimeException( 'REVIEWED_OPTIONAL_MISCLASSIFIED:' . $name );
}
if ( in_array( 'external/never-reviewed', $classified, true ) )
    throw new RuntimeException( 'FOREIGN_UNREVIEWED_TOOL_BECAME_OPTIONAL' );
if ( $optional::materialization_optional_tools( $required ) !== array() )
    throw new RuntimeException( 'REQUIRED_ONLY_PREVIEW_NOT_FAIL_CLOSED' );
echo "CHATGPT_MATERIALIZATION_OPTIONAL_POLICY_PASS: stable transport required; auxiliary reviewed tools optional; foreign denied\n";
