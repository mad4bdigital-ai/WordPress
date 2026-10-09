<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Presentation over the existing operator reducer. No diagnostic or mutation executor. */
final class MAD4B_SCP_Operator_Workspace {
	const CONTRACT = 'mad4b.operator-workspace.v1';

	/** Unknown values cannot become readiness, completed repair or an automation percentage. */
	public static function model( array $snapshot ) {
		$signals = isset( $snapshot['signals'] ) && is_array( $snapshot['signals'] ) ? $snapshot['signals'] : array();
		$checks = array();
		foreach ( array(
			'write_authority_ready' => __( 'Write authority', 'mad4b-site-control-plane' ),
			'candidate_binding_match' => __( 'Current build binding', 'mad4b-site-control-plane' ),
			'database_topology_ready' => __( 'Database write safety', 'mad4b-site-control-plane' ),
			'governed_write_lane_ready' => __( 'Governed write lane', 'mad4b-site-control-plane' ),
		) as $key => $label ) {
			$value = isset( $signals[ $key ] ) && is_bool( $signals[ $key ] ) ? $signals[ $key ] : null;
			$checks[] = array( 'id' => $key, 'label' => $label, 'value' => $value );
		}
		$actions = array();
		$reasons = isset( $snapshot['reasons'] ) && is_array( $snapshot['reasons'] ) ? $snapshot['reasons'] : array();
		foreach ( array_slice( $reasons, 0, 32 ) as $reason ) {
			if ( ! is_string( $reason ) ) continue;
			$action = self::action( $reason );
			if ( ! isset( $actions[ $action['id'] ] ) ) $actions[ $action['id'] ] = $action;
		}
		return array( 'contract' => self::CONTRACT, 'checks' => $checks, 'actions' => array_values( $actions ), 'automation_percentage' => null, 'auto_repaired_count' => null, 'authorizing' => false, 'mutation_performed' => false );
	}

	private static function action( $reason ) {
		$kind = 'RECONCILIATION_REQUIRED';
		$id = 'runtime';
		$title = __( 'Review runtime evidence', 'mad4b-site-control-plane' );
		$message = __( 'A runtime check needs review. Open the relevant workspace to inspect its current evidence.', 'mad4b-site-control-plane' );
		$slug = 'mad4b-runtime-components';
		$query = array();
		switch ( $reason ) {
			case 'mutation_state_uncertain':
			case 'recovery_required':
				$id = 'recovery';
				$title = __( 'Reconcile the previous change', 'mad4b-site-control-plane' );
				$message = __( 'The outcome of an earlier change is uncertain. Review its receipt and current object state before retrying.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane'; $query = array( 'tab' => 'mutations' );
				break;
			case 'write_authority_not_current':
			case 'governed_write_lane_not_ready':
				$id = 'authority'; $kind = 'APPROVAL_REQUIRED';
				$title = __( 'Review approved access', 'mad4b-site-control-plane' );
				$message = __( 'Current write access is not ready. Review the existing site permissions and exact proposal before approving any change.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane-site-profile';
				break;
			case 'runtime_authority_candidate_not_reconciled':
			case 'runtime_identity_mismatch':
				$id = 'binding';
				$title = __( 'Review the installed build', 'mad4b-site-control-plane' );
				$message = __( 'The current build or its authority binding needs reconciliation. Review package identity and the existing candidate.', 'mad4b-site-control-plane' );
				$query = array( 'tab' => 'maintenance' );
				break;
			case 'database_topology_not_write_safe':
				$id = 'database';
				$title = __( 'Review database readiness', 'mad4b-site-control-plane' );
				$message = __( 'Database safety or attribution requires review before writes can proceed.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane-performance';
				break;
			case 'developer_lane_not_ready':
			case 'developer_breakglass_lane_not_ready':
				$id = 'host'; $kind = 'EXTERNAL_ACTION_REQUIRED';
				$title = __( 'Review developer prerequisites', 'mad4b-site-control-plane' );
				$message = __( 'Check the isolated execution prerequisites. A missing host sandbox must be configured by the host administrator; existing access gates remain in force.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane-connection'; $query = array( 'tab' => 'isolation' );
				break;
			case 'provider_closure_actions_pending':
			case 'optional_capability_state_unbound':
			case 'optional_capabilities_not_fail_closed':
				$id = 'providers';
				$title = __( 'Review affected provider capabilities', 'mad4b-site-control-plane' );
				$message = __( 'Inspect the individual capability gaps and required evidence in Provider Coverage.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-adapter-coverage'; $query = array( 'tab' => 'functional' );
				break;
			case 'external_machine_ingress_unbound':
			case 'external_machine_ingress_not_reachable':
				$id = 'external_connection'; $kind = 'EXTERNAL_ACTION_REQUIRED';
				$title = __( 'Verify the external client connection', 'mad4b-site-control-plane' );
				$message = __( 'Complete a valid external client connection for this build, then inspect the endpoint evidence.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane-chatgpt';
				break;
			case 'repository_evidence_unbound':
			case 'repository_evidence_failed':
			case 'runtime_identity_unbound':
			case 'live_evidence_unbound':
			case 'live_evidence_not_ready':
				$id = 'acceptance'; $kind = 'EXTERNAL_ACTION_REQUIRED';
				$title = __( 'Complete acceptance evidence', 'mad4b-site-control-plane' );
				$message = __( 'Bind the current build to repository checks and external acceptance results. Missing evidence stays unverified.', 'mad4b-site-control-plane' );
				$slug = 'mad4b-control-plane-connection'; $query = array( 'tab' => 'certification' );
				break;
		}
		return array( 'id' => $id, 'state' => $kind, 'title' => $title, 'message' => $message, 'page' => $slug, 'query' => $query );
	}

	public static function state_label( $state ) {
		$labels = array(
			'AUTO_REPAIRED' => __( 'Repair verified', 'mad4b-site-control-plane' ),
			'APPROVAL_REQUIRED' => __( 'Approval required', 'mad4b-site-control-plane' ),
			'EXTERNAL_ACTION_REQUIRED' => __( 'External action required', 'mad4b-site-control-plane' ),
			'RECONCILIATION_REQUIRED' => __( 'Review and reconcile', 'mad4b-site-control-plane' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : __( 'Not checked', 'mad4b-site-control-plane' );
	}

	public static function render( array $snapshot, array $adaptive = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$model = self::model( $snapshot );
		MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'MAD4B Action Center', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'See what needs attention, who can resolve it and where to take the next step.', 'mad4b-site-control-plane' ) . '</p>';
		$cards = array();
		foreach ( $model['checks'] as $check ) $cards[] = array( 'label' => $check['label'], 'value' => MAD4B_SCP_Admin_Experience::observed_state( $check['value'] ), 'help' => null === $check['value'] ? __( 'No current observation is available.', 'mad4b-site-control-plane' ) : __( 'Current local runtime observation.', 'mad4b-site-control-plane' ), 'state' => null === $check['value'] ? 'pending' : ( $check['value'] ? 'complete' : 'attention' ) );
		MAD4B_SCP_Admin_Experience::cards( $cards );
		if ( class_exists( 'MAD4B_SCP_Guided_Operator_Experience', false ) ) MAD4B_SCP_Guided_Operator_Experience::render( $snapshot );
		echo '<h2>' . esc_html__( 'Next actions', 'mad4b-site-control-plane' ) . '</h2>';
		if ( ! $model['actions'] ) echo '<div class="mad4b-scp-panel"><p>' . esc_html__( 'No pending actions were reported by this snapshot. External acceptance and repair coverage still require their own evidence.', 'mad4b-site-control-plane' ) . '</p></div>';
		echo '<div class="mad4b-action-list">';
		foreach ( $model['actions'] as $action ) {
			echo '<article class="mad4b-action-card"><span class="mad4b-action-state is-' . esc_attr( strtolower( $action['state'] ) ) . '">' . esc_html( self::state_label( $action['state'] ) ) . '</span><h3>' . esc_html( $action['title'] ) . '</h3><p>' . esc_html( $action['message'] ) . '</p>';
			$url = MAD4B_SCP_Admin_Workspace::link( $action['page'], $action['query'] );
			if ( '' !== $url ) echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Open workspace: %s', 'mad4b-site-control-plane' ), $action['title'] ) ) . '</a>';
			echo '</article>';
		}
		echo '</div>';
		$g7 = isset( $snapshot['g7_action_center'] ) && is_array( $snapshot['g7_action_center'] ) ? $snapshot['g7_action_center'] : array();
		if ( isset( $g7['contract'] ) && 'mad4b.feature007-g7-action-center.v1' === $g7['contract'] ) {
			echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'G7 governed action policy', 'mad4b-site-control-plane' ) . '</summary>';
			echo '<p>' . esc_html__( 'Read-only operator projection. No repair, approval, Undo or host action is performed here.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Observed state:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( (string) ( $g7['state'] ?? 'RECONCILIATION_REQUIRED' ) ) . '</code></p>';
			foreach ( array_slice( isset( $g7['action_items'] ) && is_array( $g7['action_items'] ) ? $g7['action_items'] : array(), 0, 16 ) as $item ) {
				if ( ! is_array( $item ) ) continue;
				echo '<p><code>' . esc_html( (string) ( $item['reason'] ?? 'unknown' ) ) . '</code> — ' . esc_html( (string) ( $item['state'] ?? 'RECONCILIATION_REQUIRED' ) ) . '</p>';
			}
			echo '<p>' . esc_html__( 'Verified automation rate: unavailable until the eligible workload and readback evidence are measured.', 'mad4b-site-control-plane' ) . '</p></details>';
		}
		if ( class_exists( 'MAD4B_SCP_Runtime_Recovery_Workspace', false ) ) MAD4B_SCP_Runtime_Recovery_Workspace::render();
		echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'Advanced setup reference', 'mad4b-site-control-plane' ) . '</summary>';
		self::setup_path();
		echo '</details>';
		self::external_notices();
		if ( class_exists( 'MAD4B_SCP_Automation_SLO', false ) ) MAD4B_SCP_Automation_SLO::render_controls();
		self::capabilities( $adaptive );
		if ( class_exists( 'MAD4B_SCP_Competitive_Evidence' ) ) MAD4B_SCP_Competitive_Evidence::render_operator_view();
		self::autonomy_reference();
		echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'Technical evidence', 'mad4b-site-control-plane' ) . '</summary><p>' . esc_html__( 'This view reports evidence and grants no new access.', 'mad4b-site-control-plane' ) . '</p><ul>';
		foreach ( array_slice( isset( $snapshot['reasons'] ) && is_array( $snapshot['reasons'] ) ? $snapshot['reasons'] : array(), 0, 32 ) as $reason ) if ( is_string( $reason ) ) echo '<li><code><bdi>' . esc_html( $reason ) . '</bdi></code></li>';
		echo '</ul><p>' . esc_html__( 'Verified automatic repair coverage: Not measured.', 'mad4b-site-control-plane' ) . '</p></details></div>';
	}

	private static function external_notices() {
		echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'Language hierarchy and license notices', 'mad4b-site-control-plane' ) . '</summary><h3>' . esc_html__( 'Taxonomy language synchronization', 'mad4b-site-control-plane' ) . '</h3><p>' . esc_html__( 'Use the language provider\'s taxonomy hierarchy screen to inspect the affected terms and update each listed hierarchy. Review the source language and parent relationships first.', 'mad4b-site-control-plane' ) . '</p><h3>' . esc_html__( 'License and domain mismatch', 'mad4b-site-control-plane' ) . '</h3><p>' . esc_html__( 'Review the current domain and the vendor\'s staging license policy in the provider settings. License deactivation or reactivation stays an explicit owner action.', 'mad4b-site-control-plane' ) . '</p><p><a class="button button-secondary" href="' . esc_url( MAD4B_SCP_Admin_Workspace::link( 'mad4b-runtime-components', array( 'tab' => 'plugins' ) ) ) . '">' . esc_html__( 'Review installed providers', 'mad4b-site-control-plane' ) . '</a></p></details>';
	}

	private static function setup_path() {
		echo '<h2>' . esc_html__( 'Setup path', 'mad4b-site-control-plane' ) . '</h2><p>' . esc_html__( 'Open each workspace to inspect its current prerequisites. These steps describe setup; they do not certify completion.', 'mad4b-site-control-plane' ) . '</p>';
		MAD4B_SCP_Admin_Experience::stages( array(
			array( 'label' => __( 'Enroll the site', 'mad4b-site-control-plane' ), 'detail' => __( 'Review the environment and site permissions.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Workspace::link( 'mad4b-control-plane-site-profile' ) ),
			array( 'label' => __( 'Connect a client', 'mad4b-site-control-plane' ), 'detail' => __( 'Complete consent and verify a safe read.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Workspace::link( 'mad4b-control-plane-chatgpt' ) ),
			array( 'label' => __( 'Configure context and providers', 'mad4b-site-control-plane' ), 'detail' => __( 'Add sources, API credentials and a paused search profile.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Workspace::link( 'mad4b-search-intelligence', array( 'section' => 'providers' ), 'search-providers' ) ),
			array( 'label' => __( 'Review the first proposal', 'mad4b-site-control-plane' ), 'detail' => __( 'Inspect the exact change and required approval.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Workspace::link( 'mad4b-approval-decisions' ) ),
		) );
	}

	private static function capabilities( array $adaptive ) {
		echo '<h2>' . esc_html__( 'Provider observations', 'mad4b-site-control-plane' ) . '</h2>';
		$providers = isset( $adaptive['providers'] ) && is_array( $adaptive['providers'] ) ? $adaptive['providers'] : array();
		if ( ! $providers ) { echo '<p>' . esc_html__( 'No current provider observations. Open Provider Coverage to inspect available capabilities.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-workspace-table" tabindex="0" role="region" aria-label="' . esc_attr__( 'Provider observations', 'mad4b-site-control-plane' ) . '"><table class="widefat striped"><caption>' . esc_html__( 'Bounded stored observations; permission and execution certification are checked separately.', 'mad4b-site-control-plane' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Provider', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Capability', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Observation', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $providers as $provider => $observation ) {
			if ( ! is_array( $observation ) ) continue;
			foreach ( isset( $observation['capabilities'] ) && is_array( $observation['capabilities'] ) ? $observation['capabilities'] : array() as $capability => $row ) {
				if ( ++$shown > 64 ) break 2;
				$state = is_array( $row ) && isset( $row['state'] ) && is_string( $row['state'] ) ? $row['state'] : 'NOT_CHECKED';
				echo '<tr><td><bdi>' . esc_html( (string) $provider ) . '</bdi></td><td><bdi>' . esc_html( (string) $capability ) . '</bdi></td><td>' . esc_html( str_replace( '_', ' ', $state ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table></div><p><a href="' . esc_url( MAD4B_SCP_Admin_Workspace::link( 'mad4b-adapter-coverage' ) ) . '">' . esc_html__( 'Review all provider capabilities', 'mad4b-site-control-plane' ) . '</a></p>';
		if ( $shown > 64 || ! empty( $adaptive['page']['has_more'] ) ) echo '<p class="description">' . esc_html__( 'Additional observations are available in Provider Coverage.', 'mad4b-site-control-plane' ) . '</p>';
	}

	private static function autonomy_reference() {
		echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'Automation levels and review boundaries', 'mad4b-site-control-plane' ) . '</summary><p>' . esc_html__( 'Policy reference only. A level describes a boundary; it does not enable an operation or prove that a repair ran.', 'mad4b-site-control-plane' ) . '</p><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Level', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Operation', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Required boundary', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
		$rows = array(
			array( 'L0', __( 'Observe registered metadata', 'mad4b-site-control-plane' ), __( 'Existing read permissions.', 'mad4b-site-control-plane' ) ),
			array( 'L1', __( 'Refresh status projections', 'mad4b-site-control-plane' ), __( 'No permission change or new certification.', 'mad4b-site-control-plane' ) ),
			array( 'L2', __( 'Repair owned configuration', 'mad4b-site-control-plane' ), __( 'Proven ownership; preserve human edits.', 'mad4b-site-control-plane' ) ),
			array( 'L3', __( 'Verified read or reversible staging trial', 'mad4b-site-control-plane' ), __( 'Existing authorization, approved recipe, exact readback and verified restoration.', 'mad4b-site-control-plane' ) ),
			array( 'L4', __( 'Refresh eligible existing staging access', 'mad4b-site-control-plane' ), __( 'Exact existing inventory; no new grants or tools.', 'mad4b-site-control-plane' ) ),
			array( 'L5', __( 'High-risk or new authority', 'mad4b-site-control-plane' ), __( 'Owner review required. No automatic promotion.', 'mad4b-site-control-plane' ) ),
		);
		foreach ( $rows as $row ) echo '<tr><th scope="row"><bdi>' . esc_html( $row[0] ) . '</bdi></th><td>' . esc_html( $row[1] ) . '</td><td>' . esc_html( $row[2] ) . '</td></tr>';
		echo '</tbody></table></details>';
	}
}
