<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Single presentation-only source for full and zero-touch admin navigation. */
final class MAD4B_SCP_Admin_Navigation_Manifest {
    const PARENT = 'mad4b-control-plane';

    public static function definitions() {
		return array(
			'mad4b-provider-connections' => array( __( 'Provider Connections', 'mad4b-site-control-plane' ), __( 'Inspect provider health, refresh saved grants or hand off owner OAuth consent. Remote mutation remains ticket-governed.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
			'mad4b-ai-knowledge-workspace' => array( __( 'AI Knowledge Workspace', 'mad4b-site-control-plane' ), __( 'Inspect knowledge sources and context eligibility; governed actions require a separate authorized route.', 'mad4b-site-control-plane' ), 'content', '', array() ),
			'mad4b-assistant-workspace' => array( __( 'Assistant Review', 'mad4b-site-control-plane' ), __( 'Read assistant bootstrap, blockers and queue without executing actions.', 'mad4b-site-control-plane' ), 'operate', '', array() ),
			'mad4b-growth-providers' => array( __( 'Growth Providers', 'mad4b-site-control-plane' ), __( 'Review provider, supplier, market and media rights policy before authorizing changes.', 'mad4b-site-control-plane' ), 'content', '', array() ),
			'mad4b-operational-remediation' => array( __( 'Operational Remediation', 'mad4b-site-control-plane' ), __( 'Guided recovery and post-update verification with exact next actions; tickets and mutations use their original governed executors.', 'mad4b-site-control-plane' ), 'operate', '', array() ),
			'mad4b-operator-control-center' => array( __( 'Action Center', 'mad4b-site-control-plane' ), __( 'Review blockers and choose the next safe step.', 'mad4b-site-control-plane' ), 'operate', '', array() ),
			'mad4b-control-plane-site-profile' => array( __( 'Site Profile', 'mad4b-site-control-plane' ), __( 'Enroll this site and review its environment and access.', 'mad4b-site-control-plane' ), 'connect', '', array( 'Environment', 'Site identity', 'Access settings' ) ),
			'mad4b-control-plane-connection' => array( __( 'Connection', 'mad4b-site-control-plane' ), __( 'Inspect OAuth, endpoints, isolation and certification.', 'mad4b-site-control-plane' ), 'connect', 'tab', array( 'readiness', 'oauth', 'endpoints', 'isolation', 'certification' ) ),
			'mad4b-control-plane-chatgpt' => array( __( 'ChatGPT Connection', 'mad4b-site-control-plane' ), __( 'Follow client connection and consent instructions.', 'mad4b-site-control-plane' ), 'connect', '', array() ),
			'mad4b-control-plane-oauth-canary' => array( __( 'OAuth Check', 'mad4b-site-control-plane' ), __( 'Review the local browser connection check.', 'mad4b-site-control-plane' ), 'connect', '', array() ),
			'mad4b-control-plane-context' => array( __( 'Brand & Context', 'mad4b-site-control-plane' ), __( 'Connect Google and manage brand sources, review and assets.', 'mad4b-site-control-plane' ), 'content', 'tab', array( 'overview', 'google-drive', 'sources', 'review', 'assets', 'quality', 'intelligence' ) ),
			'mad4b-search-intelligence' => array( __( 'Search Intelligence', 'mad4b-site-control-plane' ), __( 'Add API credentials, select a profile and review search budgets.', 'mad4b-site-control-plane' ), 'content', 'section', array( 'overview', 'providers', 'budgets', 'markets', 'languages', 'surfaces', 'targets', 'serp', 'competitors', 'archives', 'experiments', 'diagnostics' ) ),
			'mad4b-control-plane-content-pipeline' => array( __( 'Content Pipeline', 'mad4b-site-control-plane' ), __( 'Select validation stages and review their prerequisites.', 'mad4b-site-control-plane' ), 'content', '', array( 'Stage selection', 'Advanced policy' ) ),
			'mad4b-control-plane' => array( __( 'Governance & History', 'mad4b-site-control-plane' ), __( 'Inspect agents, effective access, recovery, consent, changes and audit evidence.', 'mad4b-site-control-plane' ), 'operate', 'tab', array( 'overview', 'tasks', 'controls', 'agents', 'approvals', 'mutations', 'consent', 'history', 'audit' ) ),
			'mad4b-approval-decisions' => array( __( 'Approval Decisions', 'mad4b-site-control-plane' ), __( 'Review exact proposed changes and decision history.', 'mad4b-site-control-plane' ), 'operate', 'view', array( 'actionable', 'history' ) ),
			'mad4b-adapter-coverage' => array( __( 'Provider Coverage', 'mad4b-site-control-plane' ), __( 'Separate native MCP discovery, adapter registration, certification and execution authority.', 'mad4b-site-control-plane' ), 'runtime', 'tab', array( 'overview', 'installed', 'priority', 'functional', 'mcp-routes', 'capabilities', 'native-peers', 'route-preview', 'requests' ) ),
			'mad4b-runtime-components' => array( __( 'Runtime Components', 'mad4b-site-control-plane' ), __( 'Review component identity, updates and maintenance.', 'mad4b-site-control-plane' ), 'runtime', 'tab', array( 'overview', 'core', 'plugins', 'mu-plugins', 'drop-ins', 'themes', 'astra', 'maintenance' ) ),
			'mad4b-browser-acceptance' => array( __( 'Browser Acceptance Setup', 'mad4b-site-control-plane' ), __( 'Configure browser-runner preference; execution and certification require external evidence.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
			'mad4b-control-plane-skills' => array( __( 'Managed Skills', 'mad4b-site-control-plane' ), __( 'Review available skills and their reconciliation status.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
			'mad4b-control-plane-performance' => array( __( 'Performance', 'mad4b-site-control-plane' ), __( 'Inspect database performance and maintenance evidence.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
		);
    }

    public static function menu_rows() {
        $rows = array( self::PARENT => array(
            'label' => __( 'MAD4B Control Plane', 'mad4b-site-control-plane' ),
            'capability' => 'manage_options',
        ) );
        foreach ( self::definitions() as $slug => $definition ) {
            if ( self::PARENT !== $slug ) $rows[ $slug ] = array(
                'label' => $definition[0], 'capability' => 'manage_options',
            );
        }
        return $rows;
    }

    /** Sort/label only registered full-runtime menus, never create missing callbacks. */
    public static function normalize_submenus() {
        global $submenu;
        if ( ! current_user_can( 'manage_options' ) || ! isset( $submenu[ self::PARENT ] )
            || ! is_array( $submenu[ self::PARENT ] ) ) return;
        $rows = self::menu_rows();
        $rank = array_flip( array_keys( $rows ) );
        $ordered = array();
        $unknown = array();
        foreach ( $submenu[ self::PARENT ] as $item ) {
            if ( ! is_array( $item ) || ! isset( $item[2] ) || ! is_string( $item[2] )
                || ! isset( $rank[ $item[2] ] ) ) { $unknown[] = $item; continue; }
            $item[0] = $rows[ $item[2] ]['label'];
            $ordered[ $rank[ $item[2] ] ][] = $item;
        }
        ksort( $ordered, SORT_NUMERIC );
        $sorted = array();
        foreach ( $ordered as $entries ) foreach ( $entries as $item ) $sorted[] = $item;
        $submenu[ self::PARENT ] = array_merge( $sorted, $unknown );
    }
}
