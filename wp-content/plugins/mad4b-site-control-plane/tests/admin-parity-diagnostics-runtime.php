<?php
/** Read-only fastpath and diagnostic presentation regression fixture. */
define( 'ABSPATH', __DIR__ );
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $c ) { return 'manage_options' === $c && $GLOBALS['allowed']; }
function add_action() {}
function add_menu_page( ...$a ) { $GLOBALS['menu'][] = $a; }
function add_submenu_page( ...$a ) { $GLOBALS['native'][] = $a; }
$GLOBALS['allowed'] = true; $GLOBALS['native'] = array(); $GLOBALS['menu'] = array();
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-navigation-manifest.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-native-plugin-admin-menu.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-ui.php';
$expected = MAD4B_SCP_Admin_Navigation_Manifest::menu_rows();
if ( 20 !== count( $expected ) ) throw new RuntimeException( 'Manifest route count drift.' );
MAD4B_SCP_Native_Plugin_Admin_Menu::register_menu();
if ( count( $GLOBALS['native'] ) !== count( $expected ) ) throw new RuntimeException( 'Fast menu route drift.' );
foreach ( array_values( $expected ) as $i => $row ) {
    if ( $GLOBALS['native'][ $i ][2] !== $row['label'] || $GLOBALS['native'][ $i ][3] !== $row['capability'] )
        throw new RuntimeException( 'Fastpath labels or access drift.' );
}
$GLOBALS['submenu'] = array( 'mad4b-control-plane' => array() );
foreach ( array_reverse( $GLOBALS['native'] ) as $item )
    $GLOBALS['submenu']['mad4b-control-plane'][] = array( $item[2], $item[3], $item[4] );
$GLOBALS['submenu']['mad4b-control-plane'][] = array( 'External', 'manage_options', 'other-plugin' );
MAD4B_SCP_Admin_Navigation_Manifest::normalize_submenus();
if ( array_column( $GLOBALS['submenu']['mad4b-control-plane'], 2 ) !== array_merge( array_keys( $expected ), array( 'other-plugin' ) ) )
    throw new RuntimeException( 'Real registered menu order drift or external plugin removed.' );
$GLOBALS['allowed'] = false; $before = $GLOBALS['submenu'];
MAD4B_SCP_Admin_Navigation_Manifest::normalize_submenus();
if ( $before !== $GLOBALS['submenu'] ) throw new RuntimeException( 'Unauthorized menu mutation.' );
$GLOBALS['allowed'] = true;
$render = new ReflectionMethod( 'MAD4B_SCP_Admin_UI', 'render_overview' );
$render->setAccessible( true );
$s = array(
    'authority'=>array( 'status'=>'ready_read_only', 'blockers'=>array(), 'staging_write_authority'=>array('ready'=>true) ),
    'staging_candidate_binding'=>array( 'required'=>true,'match'=>false,'current_source_commit_sha'=>'new','stored_source_commit_sha'=>'old' ),
    'runtime_self_test'=>array('status'=>'deferred'),
    'audit_storage'=>array('ready'=>true,'event_count'=>0),
    'mcp_peer_governance'=>array('inventory_ready'=>false,'blockers'=>array()),
);
ob_start(); $render->invoke( null, $s ); $output = ob_get_clean();
if ( false === strpos( $output, 'not_evaluated' ) || false === strpos( $output, 'not certified for the currently installed package' )
    || false !== strpos( $output, 'mcp_foreign_transport_unreviewed' ) ) throw new RuntimeException( 'Deferral rendered as confirmed security error.' );
$s['mcp_peer_governance'] = array('inventory_ready'=>true,'foreign_transport_unreviewed'=>true,'foreign_route_count'=>1,'blockers'=>array('mcp_foreign_transport_unreviewed'),'foreign_transport_inventory'=>array('foreign_routes'=>array('/foreign-mcp')));
ob_start(); $render->invoke( null, $s ); $output = ob_get_clean();
if ( false === strpos( $output, 'mcp_foreign_transport_unreviewed' )
    || false === strpos( $output, '/foreign-mcp' )
    || false === strpos( $output, 'Foreign REST route count is not the number of connected MCP peers' )
    || false === strpos( $output, 'do not disable providers or waive the blocker automatically' ) )
    throw new RuntimeException( 'Verified foreign transport must include safe provider-review guidance.' );
echo "MAD4B_ADMIN_PARITY_DIAGNOSTICS_PASS\n";
