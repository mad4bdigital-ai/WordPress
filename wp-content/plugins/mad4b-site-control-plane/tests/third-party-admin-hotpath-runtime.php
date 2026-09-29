<?php

define( 'ABSPATH', '/srv/wordpress/' );

$_GET['page'] = 'sitepress-multilingual-cms/menu/support.php';
$_GET['tab'] = '';

function is_admin() { return true; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

// Any call here means Upgrade Continuity crossed the foreign-admin routing gate
// and started touching the WordPress admin lifecycle.
function remove_action() {
	fwrite( STDERR, "FAIL: foreign WPML admin request reached deep governance notice work\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-dependency-manager.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-upgrade-continuity.php';

// Dependency Manager would enter get_plugins/archive/config hashing if its page
// gate failed. Upgrade Continuity would call remove_action() and then deep
// schema/audit status. Both must be zero-touch for this exact third-party route.
MAD4B_SCP_Dependency_Manager::admin_notice();
MAD4B_SCP_Upgrade_Continuity::replace_ambiguous_governance_notice();

echo "mad4b.third-party-admin-hotpath.wpml.runtime.v1: PASS\n";
