<?php
/**
 * MAD4B Unified Operation Authority — Staging compatibility bridge.
 *
 * Host-installed MU file that provides source-backed read-only integration
 * while the full Site Control Plane package retains its existing provenance.
 * The official plugin's classes supersede this bridge on later upgrades.
 *
 * No REST route for caller-supplied principals or grants. No SSH execution.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'MAD4B_SCP_Central_Company_Rollout', false ) ||
        ! class_exists( 'MAD4B_SCP_Central_Release_Registry', false ) ||
        ! class_exists( 'MAD4B_SCP_Policy', false ) ) {
        return;
    }
    $root = __DIR__ . '/mad4b-unified-operation-authority';
    if ( ! class_exists( 'MAD4B_SCP_Unified_Operation_Authority', false ) ) {
        $file = $root . '/class-mad4b-scp-unified-operation-authority.php';
        if ( ! is_file( $file ) || is_link( $file ) ) return;
        require_once $file;
    }
    if ( ! class_exists( 'MAD4B_SCP_Central_Operator_Handoff', false ) ) {
        $file = $root . '/class-mad4b-scp-central-operator-handoff.php';
        if ( ! is_file( $file ) || is_link( $file ) ) return;
        require_once $file;
    }
    // This only registers a read-only planning ability. No mutating hooks,
    // identity provider override or standing-grant changes are installed.
    MAD4B_SCP_Central_Operator_Handoff::boot();
}, 90 );
