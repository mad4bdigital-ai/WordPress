<?php
define( 'ABSPATH', __DIR__ );
$GLOBALS['test_role_admin'] = false;
$GLOBALS['test_role_update'] = false;
$GLOBALS['test_inventory'] = array();
$GLOBALS['test_active'] = array();
$GLOBALS['test_inventory_reads'] = 0;
$GLOBALS['test_mutations'] = 0;

function current_user_can( $capability ) {
	if ( 'manage_options' === $capability ) return $GLOBALS['test_role_admin'];
	if ( 'update_plugins' === $capability ) return $GLOBALS['test_role_update'];
	throw new RuntimeException( 'unexpected capability requested' );
}
function get_plugins() {
	++$GLOBALS['test_inventory_reads'];
	return $GLOBALS['test_inventory'];
}
function is_plugin_active( $file ) { return in_array( $file, $GLOBALS['test_active'], true ); }
function is_plugin_active_for_network( $file ) { return false; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-file-workspace.php';

function check_workspace( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
$denied = MAD4B_SCP_Admin_File_Workspace::status();
check_workspace( 'administrator_required' === $denied['inspection'], 'No-admin request must remain opaque' );
check_workspace( 0 === $GLOBALS['test_inventory_reads'], 'No-admin request accessed inventory' );
check_workspace( false === $denied['manager_detected'], 'No-admin request disclosed providers' );

$GLOBALS['test_role_admin'] = true;
$GLOBALS['test_role_update'] = true;
$GLOBALS['test_inventory'] = array(
	'generic-content/plugin.php' => array( 'Name' => 'Generic Content Model' ),
	'admin-files/plugin.php' => array( 'Name' => 'WP File Manager' ),
);
$inactive = MAD4B_SCP_Admin_File_Workspace::status();
check_workspace( true === $inactive['manager_detected'], 'Installed administrator file manager not discovered' );
check_workspace( false === $inactive['manager_active'], 'Inactive file manager reported active' );

$GLOBALS['test_active'] = array( 'admin-files/plugin.php' );
$active = MAD4B_SCP_Admin_File_Workspace::status();
check_workspace( true === $active['manager_active'], 'Active file manager not detected' );
check_workspace( true === $active['wp_admin_update_capability'], 'Admin update capability missing' );
check_workspace( false === $active['file_manager_remote_api_enabled'], 'Provider remote API enabled' );
check_workspace( false === $active['el_finder_remote_transport_enabled'], 'elFinder remote transport enabled' );
check_workspace( false === $active['arbitrary_path_or_url_allowed'], 'Arbitrary path or URL allowed' );
check_workspace( false === $active['direct_core_plugin_file_edit_allowed'], 'Ungoverned source editing enabled' );
check_workspace( false === $active['production_write_authorized'], 'Production authority fabricated' );
check_workspace( false === $active['mutation_performed'] && false === $active['authorizing'], 'Read-only contract violated' );
check_workspace( 'mad4b/control-plane-upload-plan' === $active['governed_update_plan_ability'], 'Governed plan handoff missing' );
check_workspace( 'mad4b/control-plane-upload-apply' === $active['governed_update_apply_ability'], 'Governed apply handoff missing' );
check_workspace( 0 === $GLOBALS['test_mutations'], 'Unexpected mutation' );
echo "ADMIN_FILE_WORKSPACE: PASS (denial, installed/inactive, active, zero foreign API, no mutation)\n";
