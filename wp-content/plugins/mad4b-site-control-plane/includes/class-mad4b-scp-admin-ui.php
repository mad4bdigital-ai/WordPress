<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only administrator console for MAD4B governance/runtime evidence.
 *
 * This first product slice intentionally exposes no mutation forms. All data is
 * bounded and sourced from the same services/tables used by runtime governance.
 */
final class MAD4B_SCP_Admin_UI {
	const PAGE_SLUG = 'mad4b-control-plane';
	const AGENT_LIMIT = 100;
	const EVIDENCE_LIMIT = 50;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), MAD4B_SCP_Admin_Route_Registry::PARENT_MENU_PRIORITY );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'MAD4B Control Plane', 'mad4b-site-control-plane' ),
			__( 'MAD4B Control Plane', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-shield-alt',
			80
		);
	}

	public static function snapshot( $agent_public_id = '', $section = 'overview' ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mad4b_admin_ui_capability_denied', 'Administrator capability is required to inspect control-plane governance.' );
		}
        // Goal discovery must remain usable when governance tables are
        // unavailable; the task map is strictly read-only and static.
        $section = sanitize_key( (string) $section );
        if ( in_array( $section, array( 'tasks', 'controls' ), true ) ) return array(
            'section' => 'tasks', 'read_only' => true,
            'release_certified' => false, 'mutation_performed' => false );
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) {
			return new WP_Error( 'mad4b_admin_ui_schema_unavailable', 'Governance schema is unavailable.' );
		}
		if ( ! in_array( $section, array( 'overview', 'agents', 'approvals', 'mutations', 'consent', 'history', 'audit' ), true ) ) $section = 'overview';
		$t = MAD4B_SCP_Schema::tables();
		$snapshot = array(
			'section' => $section,
			'authority' => array(),
			'agents' => array( 'agents' => array() ),
			'effective_access' => null,
			'approvals' => array(),
			'mutations' => array(),
			'audit_storage' => array(),
			'audit_tail' => array(),
			'recovery_preview' => null,
			'consent_profile' => array(),
			'change_history' => array(),
			'runtime_self_test' => array( 'status' => 'deferred' ),
			'mcp_peer_governance' => array( 'inventory_ready' => false, 'blockers' => array( 'deferred_to_connection_or_diagnostics_workspace' ) ),
		);

		if ( 'overview' === $section ) {
			$snapshot['authority'] = MAD4B_SCP_Authorization::authority_status();
			$snapshot['audit_storage'] = MAD4B_SCP_Audit::storage_status();
			// Deep adapter/provider self-tests and peer scans are intentionally not
			// executed during ordinary dashboard render. Their dedicated workspaces
			// own those diagnostics so the Control Plane overview remains bounded.
			return $snapshot;
		}

		if ( 'agents' === $section ) {
			$agents = MAD4B_SCP_Governance_Abilities::agent_list( array( 'status' => 'all', 'limit' => self::AGENT_LIMIT ) );
			if ( is_wp_error( $agents ) ) return $agents;
			$snapshot['agents'] = $agents;
			$agent_public_id = trim( (string) $agent_public_id );
			if ( '' !== $agent_public_id ) {
				$snapshot['effective_access'] = MAD4B_SCP_Governance_Abilities::agent_effective_access(
					array(
						'agent_public_id' => $agent_public_id,
						'token_scopes' => array(),
						'server_id' => '',
					)
				);
			}
			return $snapshot;
		}

		if ( 'approvals' === $section ) {
			$rows = $wpdb->get_results(
				"SELECT a.ticket_id,a.ticket_class,a.server_id,a.ability_name,a.provider,a.target_fingerprint,a.status,a.reason,a.approved_by,a.approved_at,a.expires_at,a.used_at,a.created_at,g.public_id AS agent_public_id,g.slug AS agent_slug,g.label AS agent_label
				 FROM {$t['approvals']} a
				 LEFT JOIN {$t['agents']} g ON g.id = a.agent_id
				 ORDER BY a.id DESC LIMIT " . self::EVIDENCE_LIMIT,
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$snapshot['approvals'] = is_array( $rows ) ? $rows : array();
			return $snapshot;
		}

		if ( 'mutations' === $section ) {
			$rows = $wpdb->get_results(
				"SELECT m.mutation_id,m.parent_mutation_id,m.request_id,m.server_id,m.ability_name,m.provider,m.provider_version,m.target_type,m.target_id,m.approval_ticket_id,m.impact,m.status,m.reversible,m.before_sha256,m.after_sha256,m.undo_expires_at,m.verification_code,m.error_code,m.created_at,m.updated_at,g.public_id AS agent_public_id,g.slug AS agent_slug,g.label AS agent_label
				 FROM {$t['mutations']} m
				 LEFT JOIN {$t['agents']} g ON g.id = m.agent_id
				 ORDER BY m.id DESC LIMIT " . self::EVIDENCE_LIMIT,
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$snapshot['mutations'] = is_array( $rows ) ? $rows : array();
			return $snapshot;
		}

		if ( 'consent' === $section ) {
			$snapshot['consent_profile'] = class_exists( 'MAD4B_SCP_G2_Governance_Experience' )
				? MAD4B_SCP_G2_Governance_Experience::consent_profile_status( array() )
				: array();
			return $snapshot;
		}

		if ( 'history' === $section ) {
			$snapshot['change_history'] = class_exists( 'MAD4B_SCP_G2_Governance_Experience' )
				? MAD4B_SCP_G2_Governance_Experience::change_history_search( array( 'limit' => self::EVIDENCE_LIMIT ) )
				: array();
			return $snapshot;
		}

		$snapshot['audit_storage'] = MAD4B_SCP_Audit::storage_status();
		$snapshot['audit_tail'] = MAD4B_SCP_Audit::tail( self::EVIDENCE_LIMIT );
		return $snapshot;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect MAD4B Control Plane governance.', 'mad4b-site-control-plane' ) );

		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'controls' => __( 'Controls Coverage', 'mad4b-site-control-plane' ),
            'tasks' => __( 'Tasks & Workflows', 'mad4b-site-control-plane' ),
			'agents' => __( 'Agents & Access', 'mad4b-site-control-plane' ),
			'approvals' => __( 'Approvals', 'mad4b-site-control-plane' ),
			'mutations' => __( 'Mutations & Recovery', 'mad4b-site-control-plane' ),
			'consent' => __( 'Consent & Clients', 'mad4b-site-control-plane' ),
			'history' => __( 'Change History', 'mad4b-site-control-plane' ),
			'audit' => __( 'Audit', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'tab' ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
		$agent_public_id = isset( $_GET['agent'] ) ? sanitize_text_field( MAD4B_SCP_Admin_Experience::query_string( 'agent' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection.
		if ( '' !== $agent_public_id && ! preg_match( '/^[A-Za-z0-9-]{36,64}$/', $agent_public_id ) ) $agent_public_id = '';

		$mutation_id = isset( $_GET['mutation'] ) ? sanitize_text_field( MAD4B_SCP_Admin_Experience::query_string( 'mutation' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection.
		if ( '' !== $mutation_id && ! preg_match( '/^[A-Za-z0-9-]{36,64}$/', $mutation_id ) ) $mutation_id = '';
		$snapshot = self::snapshot( $agent_public_id, $tab );
		if ( ! is_wp_error( $snapshot ) && 'mutations' === $tab && '' !== $mutation_id && class_exists( 'MAD4B_SCP_G2_Governance_Experience' ) ) {
			$snapshot['recovery_preview'] = MAD4B_SCP_G2_Governance_Experience::recovery_preview( array( 'mutation_id' => $mutation_id, 'reason' => '' ) );
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAD4B Control Plane', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only governance and runtime evidence. This screen does not grant authority, approve tickets, execute mutations, or perform undo.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>';
			return;
		}

		MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );

		if ( 'overview' === $tab ) self::render_overview( $snapshot );
        if ( 'tasks' === $tab ) self::render_tasks();
		if ( 'controls' === $tab ) self::render_controls();
		if ( 'agents' === $tab ) self::render_agents( $snapshot, $agent_public_id );
		if ( 'approvals' === $tab ) self::render_approvals( $snapshot['approvals'] );
		if ( 'mutations' === $tab ) { self::render_mutations( $snapshot['mutations'] ); self::render_recovery_preview( $snapshot['recovery_preview'] ); }
		if ( 'consent' === $tab ) self::render_consent( $snapshot['consent_profile'] );
		if ( 'history' === $tab ) self::render_history( $snapshot['change_history'] );
		if ( 'audit' === $tab ) self::render_audit( $snapshot );
		echo '</div>';
	}

    private static function render_tasks() {
        if ( ! class_exists( 'MAD4B_SCP_Operator_Journeys', false ) ) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__( 'The task index is not installed on this plugin version.', 'mad4b-site-control-plane' )
                . '</p></div>';
            return;
        }
        $map = MAD4B_SCP_Operator_Journeys::discover();
        if ( is_wp_error( $map ) || ! is_array( $map ) ) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Unable to inspect task routes. Retry after checking site permissions.', 'mad4b-site-control-plane' )
                . '</p></div>';
            return;
        }
        echo '<h2>' . esc_html__( 'What do you want to do?', 'mad4b-site-control-plane' ) . '</h2>';
        echo '<p>' . esc_html__( 'Pick a goal. Follow the steps in order, checking the exact authority and result after each operation. These cards never approve or execute anything.', 'mad4b-site-control-plane' ) . '</p>';
        foreach ( $map['journeys'] as $journey ) {
            if ( ! is_array( $journey ) ) continue;
            $missing = is_array( $journey['missing_abilities'] ?? null ) ? $journey['missing_abilities'] : array();
            echo '<details style="max-width:1100px;margin:8px 0;padding:12px 16px;border:1px solid #c3c4c7;border-radius:4px">';
            echo '<summary style="cursor:pointer"><strong>'
                . esc_html( (string) ( $journey['label'] ?? '' ) ) . '</strong> — '
                . esc_html( $missing ? __( 'Integration needed', 'mad4b-site-control-plane' )
                    : __( 'Tools listed — not yet executed', 'mad4b-site-control-plane' ) )
                . '</summary><ol>';
            foreach ( $journey['steps'] as $step ) {
                echo '<li>' . esc_html( ucfirst( str_replace( '_', ' ', (string) ( $step['label'] ?? '' ) ) ) )
                    . ' <code dir="ltr">' . esc_html( (string) ( $step['ability_name'] ?? '' ) )
                    . '</code> — ' . esc_html( ! empty( $step['mounted'] )
                        ? __( 'Registered', 'mad4b-site-control-plane' )
                        : __( 'Not registered', 'mad4b-site-control-plane' ) ) . '</li>';
            }
            echo '</ol><p class="description">' . esc_html( (string) ( $journey['user_guidance'] ?? '' ) )
                . '</p></details>';
        }
        echo '<p class="description">'
            . esc_html__( 'For unresolved Staging gates, open the Operational Remediation submenu. For actual writes, use the separately governed MCP ability.', 'mad4b-site-control-plane' )
            . '</p>';
    }

	/**
	 * Universal control directory of registration metadata, not an
	 * arbitrary admin callback runner or proof of per-widget parity.
	 */
	private static function render_controls() {
		if ( ! class_exists( 'MAD4B_SCP_Admin_Surface_Coverage' ) ) {
			echo '<p>' . esc_html__( 'Control census adapter is not installed.', 'mad4b-site-control-plane' ) . '</p>';
			return;
		}
		$kind = isset( $_GET['control_kind'] ) ? sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'control_kind' ) ) : 'abilities';
		$allowed = array( 'abilities', 'pages', 'admin_post', 'ajax', 'settings' );
		if ( ! in_array( $kind, $allowed, true ) ) $kind = 'abilities';
		$offset = isset( $_GET['control_offset'] ) ? absint( MAD4B_SCP_Admin_Experience::query_string( 'control_offset', '0', 7 ) ) : 0;
		$offset = min( 100000, $offset );
		$limit = 50;
		$state = MAD4B_SCP_Admin_Surface_Coverage::control_census( array(
			'kind' => $kind, 'offset' => $offset, 'limit' => $limit ) );
		echo '<h2>' . esc_html__( 'Registered Controls Directory', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Read-only registration inventory. A listed Ability or hook does not prove that a matching WordPress form/button exists, that MCP access is enabled, or that a write was tested. Independently certify UI control bindings and browser interactions.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<div class="mad4b-controls-kinds" role="navigation" aria-label="' . esc_attr__( 'Control registry kinds', 'mad4b-site-control-plane' ) . '">';
		foreach ( $allowed as $type ) {
			$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'controls',
				'control_kind' => $type, 'control_offset' => 0 ), admin_url( 'admin.php' ) );
			echo '<a class="button ' . ( $kind === $type ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '" style="margin:0 6px 6px 0">' . esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . '</a>';
		}
		echo '</div>';
		if ( is_wp_error( $state ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $state->get_error_message() ) . '</p></div>';
			return;
		}
		if ( empty( $state['registry_ready'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Control registry not loaded in this request. Do not treat missing observations as zero controls.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		echo '<p><strong>' . esc_html__( 'Registered in this category:', 'mad4b-site-control-plane' ) . '</strong> ' .
			esc_html( (string) $state['total_count'] ) . ' · ' .
			esc_html__( 'Rows in this page:', 'mad4b-site-control-plane' ) . ' ' .
			esc_html( (string) $state['returned_count'] ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Registered Control', 'mad4b-site-control-plane' ) . '</th><th>' .
			esc_html__( 'Category', 'mad4b-site-control-plane' ) . '</th><th>' .
			esc_html__( 'WordPress UI coverage', 'mad4b-site-control-plane' ) . '</th><th>' .
			esc_html__( 'Evidence', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
		foreach ( $state['items'] as $item ) {
			$entry = '<code>' . esc_html( $item['name'] ) . '</code>';
			if ( 'pages' === $kind && ! empty( $item['admin_url'] ) ) $entry = '<a href="' . esc_url( $item['admin_url'] ) . '">' . $entry . '</a>';
			echo '<tr><td>' . $entry . '</td><td>' . esc_html( $item['registered_kind'] ) . '</td><td>' .
				esc_html( $item['wp_admin_ui_binding'] ) . '</td><td>' .
				esc_html__( 'Registered only; per-control interaction unverified', 'mad4b-site-control-plane' ) . '</td></tr>';
		}
		if ( ! $state['items'] ) echo '<tr><td colspan="4">' . esc_html__( 'No controls visible in this request.', 'mad4b-site-control-plane' ) . '</td></tr>';
		echo '</tbody></table>';
		$base = array( 'page' => self::PAGE_SLUG, 'tab' => 'controls', 'control_kind' => $kind );
		if ( $offset ) echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $base, array( 'control_offset' => max( 0, $offset - $limit ) ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Previous controls', 'mad4b-site-control-plane' ) . '</a> ';
		if ( null !== $state['next_offset'] ) echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $base, array( 'control_offset' => $state['next_offset'] ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next controls', 'mad4b-site-control-plane' ) . '</a>';
		echo '<p class="description">' . esc_html__( 'This directory is intentionally not a claim of 100% UI parity. JS-injected controls, control handlers, front-end builders, provider dependencies and effect readback require disposable WordPress and real browser acceptance.', 'mad4b-site-control-plane' ) . '</p>';
	}

	private static function render_overview( array $snapshot ) {
		$authority = $snapshot['authority'];
		$self_test = $snapshot['runtime_self_test'];
		$audit = $snapshot['audit_storage'];
		echo '<h2>' . esc_html__( 'Runtime authority', 'mad4b-site-control-plane' ) . '</h2>';
		self::key_value_table(
			array(
				'Authority status' => isset( $authority['status'] ) ? $authority['status'] : 'unknown',
				'Schema version' => isset( $authority['schema_version'] ) ? $authority['schema_version'] : '',
				'Mutation globally enabled' => ! empty( $authority['mutation_global_enabled'] ),
				'Mutation effective for request' => ! empty( $authority['mutation_effective_for_request'] ),
				'Enabled agents' => isset( $authority['enabled_agents'] ) ? $authority['enabled_agents'] : 0,
				'Exact grants' => isset( $authority['exact_grants'] ) ? $authority['exact_grants'] : 0,
				'Runtime self-test' => isset( $self_test['status'] ) ? $self_test['status'] : 'unknown',
				'Audit ready' => ! empty( $audit['ready'] ),
				'Audit events' => isset( $audit['event_count'] ) ? $audit['event_count'] : 0,
			)
		);
		self::render_blockers( isset( $authority['blockers'] ) ? $authority['blockers'] : array() );

		echo '<h2>' . esc_html__( 'MCP peer governance', 'mad4b-site-control-plane' ) . '</h2>';
		$peer = $snapshot['mcp_peer_governance'];
		self::key_value_table(
			array(
				'Inventory ready' => ! empty( $peer['inventory_ready'] ),
				'Inventory reason' => isset( $peer['reason'] ) ? $peer['reason'] : '',
				'Write side-channel detected' => ! empty( $peer['write_side_channel_detected'] ),
				'Peer count' => isset( $peer['peers'] ) && is_array( $peer['peers'] ) ? count( $peer['peers'] ) : 0,
			)
		);
		self::render_blockers( isset( $peer['blockers'] ) ? $peer['blockers'] : array() );
	}

	private static function render_agents( array $snapshot, $selected ) {
		$agents = isset( $snapshot['agents']['agents'] ) && is_array( $snapshot['agents']['agents'] ) ? $snapshot['agents']['agents'] : array();
		echo '<h2>' . esc_html__( 'Agents / NHI', 'mad4b-site-control-plane' ) . '</h2>';
		if ( ! $agents ) {
			echo '<p>' . esc_html__( 'No agents are configured.', 'mad4b-site-control-plane' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Agent</th><th>Status</th><th>Environment</th><th>Subjects</th><th>Grants</th><th>Budgets</th><th>Access</th></tr></thead><tbody>';
		foreach ( $agents as $agent ) {
			$public_id = isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '';
			$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'agents', 'agent' => $public_id ), admin_url( 'admin.php' ) );
			echo '<tr><td><strong>' . esc_html( isset( $agent['label'] ) ? $agent['label'] : '' ) . '</strong><br><code>' . esc_html( isset( $agent['slug'] ) ? $agent['slug'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $agent['status'] ) ? $agent['status'] : '' ) . '</td><td>' . esc_html( isset( $agent['environment'] ) ? $agent['environment'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $agent['subjects'] ) ? (string) $agent['subjects'] : '0' ) . '</td><td>' . esc_html( isset( $agent['grants'] ) ? (string) $agent['grants'] : '0' ) . '</td><td>' . esc_html( isset( $agent['budgets'] ) ? (string) $agent['budgets'] : '0' ) . '</td>';
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Preview', 'mad4b-site-control-plane' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		if ( '' !== $selected ) {
			echo '<h2>' . esc_html__( 'Effective access preview', 'mad4b-site-control-plane' ) . '</h2>';
			$effective = $snapshot['effective_access'];
			if ( is_wp_error( $effective ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $effective->get_error_message() ) . '</p></div>';
				return;
			}
			$items = isset( $effective['effective'] ) && is_array( $effective['effective'] ) ? $effective['effective'] : array();
			echo '<p>' . esc_html( sprintf( 'Allowed: %d · Conditional: %d · Denied: %d', isset( $effective['allowed_count'] ) ? $effective['allowed_count'] : 0, isset( $effective['conditional_count'] ) ? $effective['conditional_count'] : 0, isset( $effective['denied_count'] ) ? $effective['denied_count'] : 0 ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>Server</th><th>Ability</th><th>Provider</th><th>Grant</th><th>Impact</th><th>Approval</th><th>Constraint</th><th>Decision</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				echo '<tr><td>' . esc_html( isset( $item['server_id'] ) ? $item['server_id'] : '' ) . '</td><td><code>' . esc_html( isset( $item['ability'] ) ? $item['ability'] : '' ) . '</code></td><td>' . esc_html( isset( $item['provider'] ) ? $item['provider'] : '' ) . '</td><td>' . esc_html( isset( $item['grant'] ) ? $item['grant'] : '' ) . '</td><td>' . esc_html( isset( $item['impact'] ) ? $item['impact'] : '' ) . '</td><td>' . esc_html( ! empty( $item['approval_required'] ) ? 'required' : 'not required' ) . '</td><td>' . esc_html( isset( $item['constraint_state'] ) ? $item['constraint_state'] : '' ) . '</td><td><strong>' . esc_html( isset( $item['decision'] ) ? $item['decision'] : '' ) . '</strong></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private static function render_approvals( array $items ) {
		echo '<h2>' . esc_html__( 'Approval tickets', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Read-only view. Approval/revocation actions are intentionally not exposed in this first console slice.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No approval tickets found.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Ticket</th><th>Agent</th><th>Class</th><th>Ability</th><th>Target</th><th>Status</th><th>Expires</th><th>Used</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td><code>' . esc_html( self::short_id( isset( $item['ticket_id'] ) ? $item['ticket_id'] : '' ) ) . '</code></td><td>' . esc_html( isset( $item['agent_label'] ) && $item['agent_label'] ? $item['agent_label'] : ( isset( $item['agent_slug'] ) ? $item['agent_slug'] : '' ) ) . '</td><td>' . esc_html( isset( $item['ticket_class'] ) ? $item['ticket_class'] : '' ) . '</td><td><code>' . esc_html( isset( $item['ability_name'] ) ? $item['ability_name'] : '' ) . '</code></td><td>' . esc_html( isset( $item['target_fingerprint'] ) ? $item['target_fingerprint'] : '' ) . '</td><td><strong>' . esc_html( isset( $item['status'] ) ? $item['status'] : '' ) . '</strong></td><td>' . esc_html( isset( $item['expires_at'] ) ? $item['expires_at'] : '' ) . '</td><td>' . esc_html( isset( $item['used_at'] ) && $item['used_at'] ? $item['used_at'] : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_mutations( array $items ) {
		echo '<h2>' . esc_html__( 'Mutation / undo evidence', 'mad4b-site-control-plane' ) . '</h2>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No mutation evidence found.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Mutation</th><th>Agent</th><th>Ability</th><th>Target</th><th>Status</th><th>Reversible</th><th>Verification</th><th>Undo expires</th><th>Parent</th><th>Recovery</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$mutation_id = isset( $item['mutation_id'] ) ? (string) $item['mutation_id'] : '';
			$recovery_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'mutations', 'mutation' => $mutation_id ), admin_url( 'admin.php' ) );
			echo '<tr><td><code>' . esc_html( self::short_id( $mutation_id ) ) . '</code></td><td>' . esc_html( isset( $item['agent_label'] ) && $item['agent_label'] ? $item['agent_label'] : ( isset( $item['agent_slug'] ) ? $item['agent_slug'] : '' ) ) . '</td><td><code>' . esc_html( isset( $item['ability_name'] ) ? $item['ability_name'] : '' ) . '</code></td><td>' . esc_html( ( isset( $item['target_type'] ) ? $item['target_type'] : '' ) . ':' . ( isset( $item['target_id'] ) ? $item['target_id'] : '' ) ) . '</td><td><strong>' . esc_html( isset( $item['status'] ) ? $item['status'] : '' ) . '</strong></td><td>' . esc_html( ! empty( $item['reversible'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $item['verification_code'] ) ? $item['verification_code'] : '' ) . '</td><td>' . esc_html( isset( $item['undo_expires_at'] ) && $item['undo_expires_at'] ? $item['undo_expires_at'] : '—' ) . '</td><td><code>' . esc_html( self::short_id( isset( $item['parent_mutation_id'] ) ? $item['parent_mutation_id'] : '' ) ) . '</code></td><td><a href="' . esc_url( $recovery_url ) . '">' . esc_html__( 'Preview', 'mad4b-site-control-plane' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_recovery_preview( $preview ) {
		if ( null === $preview ) return;
		echo '<h2>' . esc_html__( 'Recovery preview', 'mad4b-site-control-plane' ) . '</h2>';
		if ( is_wp_error( $preview ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>'; return; }
		self::key_value_table( array(
			'Mutation' => isset( $preview['mutation_id'] ) ? $preview['mutation_id'] : '',
			'Ability' => isset( $preview['ability'] ) ? $preview['ability'] : '',
			'Target' => isset( $preview['target'] ) ? ( $preview['target']['type'] . ':' . $preview['target']['id'] ) : '',
			'Readback' => isset( $preview['readback_state'] ) ? $preview['readback_state'] : '',
			'Expires' => isset( $preview['undo_expires_at'] ) ? $preview['undo_expires_at'] : '',
			'Eligible by repository evidence' => ! empty( $preview['eligible_by_repository_evidence'] ),
			'Execution available here' => ! empty( $preview['execution_available_here'] ),
		) );
		self::render_blockers( isset( $preview['blockers'] ) ? $preview['blockers'] : array() );
		echo '<p class="description">' . esc_html__( 'Preview only. Undo still requires the existing exact mutation authority, approval and execution checks.', 'mad4b-site-control-plane' ) . '</p>';
	}

	private static function render_consent( array $profile ) {
		echo '<h2>' . esc_html__( 'Consent profiles and client compatibility', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Read-only projection. New scopes require external consent and no preset grants generic full access.', 'mad4b-site-control-plane' ) . '</p>';
		$presets = isset( $profile['presets'] ) && is_array( $profile['presets'] ) ? $profile['presets'] : array();
		echo '<table class="widefat striped"><thead><tr><th>Preset</th><th>Scopes</th><th>Default</th><th>Re-consent</th><th>Mutation authority</th></tr></thead><tbody>';
		foreach ( $presets as $preset ) echo '<tr><td>' . esc_html( $preset['label'] ) . '</td><td><code>' . esc_html( implode( ', ', $preset['scopes'] ) ) . '</code></td><td>' . esc_html( ! empty( $preset['default'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( ! empty( $preset['requires_external_reconsent'] ) ? 'required' : 'not required' ) . '</td><td>' . esc_html( ! empty( $preset['mutation_authority'] ) ? 'yes' : 'no' ) . '</td></tr>';
		echo '</tbody></table>';
		self::key_value_table( array(
			'Generation' => isset( $profile['generation_sha256'] ) ? self::short_hash( $profile['generation_sha256'] ) : '',
			'PKCE' => isset( $profile['protocol']['pkce_methods'] ) ? $profile['protocol']['pkce_methods'] : array(),
			'Dynamic registration exposed' => ! empty( $profile['protocol']['dynamic_client_registration_exposed'] ),
			'New scopes require external consent' => ! empty( $profile['new_scopes_require_external_consent'] ),
			'Generic full access supported' => ! empty( $profile['generic_full_access_supported'] ),
		) );
	}

	private static function render_history( array $history ) {
		echo '<h2>' . esc_html__( 'Governed change history', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Bounded, redacted evidence. History is not rollback authority and raw rollback payloads are not shown.', 'mad4b-site-control-plane' ) . '</p>';
		$items = isset( $history['items'] ) && is_array( $history['items'] ) ? $history['items'] : array();
		if ( ! $items ) { echo '<p>' . esc_html__( 'No governed mutation history found.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Agent</th><th>Operation</th><th>Target</th><th>Status</th><th>Diff evidence</th><th>Approval</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$op = $item['operation']; $diff = $item['diff']; $evidence = $item['evidence'];
			echo '<tr><td>' . esc_html( $item['created_at'] ) . '</td><td>' . esc_html( $item['agent']['label'] ? $item['agent']['label'] : $item['agent']['slug'] ) . '</td><td><code>' . esc_html( $op['ability'] ) . '</code></td><td>' . esc_html( $op['target_type'] . ':' . $op['target_id'] ) . '</td><td>' . esc_html( $op['status'] ) . '</td><td><code>' . esc_html( self::short_hash( $diff['before_sha256'] ) . ' → ' . self::short_hash( $diff['after_sha256'] ) ) . '</code></td><td><code>' . esc_html( self::short_id( $evidence['approval_ticket_id'] ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		self::key_value_table( array( 'Export digest' => isset( $history['export_sha256'] ) ? self::short_hash( $history['export_sha256'] ) : '', 'Audit chain ready' => ! empty( $history['audit_chain_ready'] ), 'Legal hold supported' => ! empty( $history['legal_hold_supported'] ) ) );
	}

	private static function render_audit( array $snapshot ) {
		echo '<h2>' . esc_html__( 'Append-only audit integrity', 'mad4b-site-control-plane' ) . '</h2>';
		$audit = $snapshot['audit_storage'];
		self::key_value_table(
			array(
				'Ready' => ! empty( $audit['ready'] ),
				'Transactional storage' => ! empty( $audit['transactional'] ),
				'Legacy chain valid' => ! empty( $audit['legacy_chain_valid'] ),
				'Legacy anchor matches' => ! empty( $audit['legacy_anchor_match'] ),
				'Head consistent' => ! empty( $audit['head_consistent'] ),
				'Event count' => isset( $audit['event_count'] ) ? $audit['event_count'] : 0,
				'Head sequence' => isset( $audit['head_sequence'] ) ? $audit['head_sequence'] : 0,
			)
		);
		$items = isset( $snapshot['audit_tail'] ) && is_array( $snapshot['audit_tail'] ) ? $snapshot['audit_tail'] : array();
		if ( ! $items ) { echo '<p>' . esc_html__( 'No append-only audit events found.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Seq</th><th>Time</th><th>Ability</th><th>Status</th><th>Request</th><th>Entry hash</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td>' . esc_html( isset( $item['sequence'] ) ? (string) $item['sequence'] : '' ) . '</td><td>' . esc_html( isset( $item['time'] ) ? $item['time'] : '' ) . '</td><td><code>' . esc_html( isset( $item['ability'] ) ? $item['ability'] : '' ) . '</code></td><td>' . esc_html( isset( $item['status'] ) ? $item['status'] : '' ) . '</td><td><code>' . esc_html( self::short_id( isset( $item['request_id'] ) ? $item['request_id'] : '' ) ) . '</code></td><td><code>' . esc_html( self::short_hash( isset( $item['entry_hash'] ) ? $item['entry_hash'] : '' ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_blockers( $blockers ) {
		$blockers = is_array( $blockers ) ? array_values( array_unique( array_filter( $blockers ) ) ) : array();
		if ( ! $blockers ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No blockers reported.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Blockers:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( implode( ', ', $blockers ) ) . '</p></div>';
	}

	private static function key_value_table( array $values ) {
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) ) $value = $value ? 'yes' : 'no';
			elseif ( is_array( $value ) ) $value = implode( ', ', array_map( 'strval', $value ) );
			echo '<tr><th style="width:260px">' . esc_html( (string) $key ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function short_id( $value ) {
		$value = (string) $value;
		return strlen( $value ) > 16 ? substr( $value, 0, 8 ) . '…' . substr( $value, -4 ) : $value;
	}

	private static function short_hash( $value ) {
		$value = (string) $value;
		return strlen( $value ) > 20 ? substr( $value, 0, 16 ) . '…' : $value;
	}
}

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Admin_UI::PAGE_SLUG, 'manage_options' );
