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
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
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

	public static function snapshot( $agent_public_id = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mad4b_admin_ui_capability_denied', 'Administrator capability is required to inspect control-plane governance.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) {
			return new WP_Error( 'mad4b_admin_ui_schema_unavailable', 'Governance schema is unavailable.' );
		}

		$t = MAD4B_SCP_Schema::tables();
		$agents = MAD4B_SCP_Governance_Abilities::agent_list( array( 'status' => 'all', 'limit' => self::AGENT_LIMIT ) );
		if ( is_wp_error( $agents ) ) return $agents;

		$effective = null;
		$agent_public_id = trim( (string) $agent_public_id );
		if ( '' !== $agent_public_id ) {
			$effective = MAD4B_SCP_Governance_Abilities::agent_effective_access(
				array(
					'agent_public_id' => $agent_public_id,
					'token_scopes' => array(),
					'server_id' => '',
				)
			);
		}

		$approvals = $wpdb->get_results(
			"SELECT a.ticket_id,a.ticket_class,a.server_id,a.ability_name,a.provider,a.target_fingerprint,a.status,a.reason,a.approved_by,a.approved_at,a.expires_at,a.used_at,a.created_at,g.public_id AS agent_public_id,g.slug AS agent_slug,g.label AS agent_label
			 FROM {$t['approvals']} a
			 LEFT JOIN {$t['agents']} g ON g.id = a.agent_id
			 ORDER BY a.id DESC LIMIT " . self::EVIDENCE_LIMIT,
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $approvals ) ) $approvals = array();

		$mutations = $wpdb->get_results(
			"SELECT m.mutation_id,m.parent_mutation_id,m.request_id,m.server_id,m.ability_name,m.provider,m.provider_version,m.target_type,m.target_id,m.approval_ticket_id,m.impact,m.status,m.reversible,m.before_sha256,m.after_sha256,m.undo_expires_at,m.verification_code,m.error_code,m.created_at,m.updated_at,g.public_id AS agent_public_id,g.slug AS agent_slug,g.label AS agent_label
			 FROM {$t['mutations']} m
			 LEFT JOIN {$t['agents']} g ON g.id = m.agent_id
			 ORDER BY m.id DESC LIMIT " . self::EVIDENCE_LIMIT,
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $mutations ) ) $mutations = array();

		$self_test = class_exists( 'MAD4B_SCP_Adapter_Registry' )
			? MAD4B_SCP_Adapter_Registry::instance()->runtime_self_test()
			: array( 'status' => 'degraded', 'missing' => array( 'adapter_registry' ) );
		$peer = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' )
			? MAD4B_SCP_MCP_Peer_Governance::status()
			: array( 'inventory_ready' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );

		return array(
			'authority' => MAD4B_SCP_Authorization::authority_status(),
			'agents' => $agents,
			'effective_access' => $effective,
			'approvals' => $approvals,
			'mutations' => $mutations,
			'audit_storage' => MAD4B_SCP_Audit::storage_status(),
			'audit_tail' => MAD4B_SCP_Audit::tail( self::EVIDENCE_LIMIT ),
			'runtime_self_test' => $self_test,
			'mcp_peer_governance' => $peer,
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect MAD4B Control Plane governance.', 'mad4b-site-control-plane' ) );

		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'agents' => __( 'Agents & Access', 'mad4b-site-control-plane' ),
			'approvals' => __( 'Approvals', 'mad4b-site-control-plane' ),
			'mutations' => __( 'Mutations', 'mad4b-site-control-plane' ),
			'audit' => __( 'Audit', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
		$agent_public_id = isset( $_GET['agent'] ) ? sanitize_text_field( wp_unslash( $_GET['agent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection.
		if ( '' !== $agent_public_id && ! preg_match( '/^[A-Za-z0-9-]{36,64}$/', $agent_public_id ) ) $agent_public_id = '';

		$snapshot = self::snapshot( $agent_public_id );
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAD4B Control Plane', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only governance and runtime evidence. This screen does not grant authority, approve tickets, execute mutations, or perform undo.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) );
			echo '<a class="nav-tab ' . ( $tab === $slug ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'overview' === $tab ) self::render_overview( $snapshot );
		if ( 'agents' === $tab ) self::render_agents( $snapshot, $agent_public_id );
		if ( 'approvals' === $tab ) self::render_approvals( $snapshot['approvals'] );
		if ( 'mutations' === $tab ) self::render_mutations( $snapshot['mutations'] );
		if ( 'audit' === $tab ) self::render_audit( $snapshot );
		echo '</div>';
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
		echo '<table class="widefat striped"><thead><tr><th>Mutation</th><th>Agent</th><th>Ability</th><th>Target</th><th>Status</th><th>Reversible</th><th>Verification</th><th>Undo expires</th><th>Parent</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td><code>' . esc_html( self::short_id( isset( $item['mutation_id'] ) ? $item['mutation_id'] : '' ) ) . '</code></td><td>' . esc_html( isset( $item['agent_label'] ) && $item['agent_label'] ? $item['agent_label'] : ( isset( $item['agent_slug'] ) ? $item['agent_slug'] : '' ) ) . '</td><td><code>' . esc_html( isset( $item['ability_name'] ) ? $item['ability_name'] : '' ) . '</code></td><td>' . esc_html( ( isset( $item['target_type'] ) ? $item['target_type'] : '' ) . ':' . ( isset( $item['target_id'] ) ? $item['target_id'] : '' ) ) . '</td><td><strong>' . esc_html( isset( $item['status'] ) ? $item['status'] : '' ) . '</strong></td><td>' . esc_html( ! empty( $item['reversible'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $item['verification_code'] ) ? $item['verification_code'] : '' ) . '</td><td>' . esc_html( isset( $item['undo_expires_at'] ) && $item['undo_expires_at'] ? $item['undo_expires_at'] : '—' ) . '</td><td><code>' . esc_html( self::short_id( isset( $item['parent_mutation_id'] ) ? $item['parent_mutation_id'] : '' ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_audit( array $snapshot ) {
		echo '<h2>' . esc_html__( 'Append-only audit integrity', 'mad4b-site-control-plane' ) . '</h2>';
		$audit = $snapshot['audit_storage'];
		self::key_value_table(
			array(
				'Ready' => ! empty( $audit['ready'] ),
				'Transactional storage' => ! empty( $audit['transactional'] ),
				'Legacy chain valid' => ! empty( $audit['legacy_chain_valid'] ),
				'Legacy anchor matches' => ! empty( $audit['legacy_anchor_matches'] ),
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
