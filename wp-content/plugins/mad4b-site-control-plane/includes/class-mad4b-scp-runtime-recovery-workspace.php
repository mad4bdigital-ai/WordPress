<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local wp-admin recovery projection over Staging Certification's existing plan.
 * This view cannot dispatch abilities, write options, approve authority, or
 * treat external evidence as local success. Production always stays read-only.
 */
final class MAD4B_SCP_Runtime_Recovery_Workspace {
	const CONTRACT = 'mad4b.runtime-recovery-workspace.v1';
	const MAX_ACTIONS = 64;

	/** Pure projection: unknown, malformed or untrusted plans never become READY. */
	public static function model( $plan ) {
		$valid = is_array( $plan )
			&& isset( $plan['contract'] )
			&& 'mad4b.staging-convergence-plan.v1' === $plan['contract']
			&& ! empty( $plan['read_only'] )
			&& empty( $plan['mutation_performed'] )
			&& empty( $plan['production_mutation_performed'] );
		$rows = array();
		if ( $valid ) {
			$seen = array();
			foreach ( array_slice( isset( $plan['actions'] ) && is_array( $plan['actions'] ) ? $plan['actions'] : array(), 0, self::MAX_ACTIONS ) as $action ) {
				if ( ! is_array( $action ) || ! isset( $action['action_id'] ) || ! is_string( $action['action_id'] ) ) continue;
				$id = sanitize_key( $action['action_id'] );
				if ( '' === $id || isset( $seen[ $id ] ) ) continue;
				$seen[ $id ] = true;
				$kind = isset( $action['kind'] ) && is_string( $action['kind'] ) ? sanitize_key( $action['kind'] ) : 'unknown';
				$owner = isset( $action['executor'] ) && is_string( $action['executor'] ) ? sanitize_key( $action['executor'] ) : 'unknown';
				$dependencies = array();
				foreach ( array_slice( isset( $action['depends_on'] ) && is_array( $action['depends_on'] ) ? $action['depends_on'] : array(), 0, 16 ) as $dependency ) {
					if ( is_string( $dependency ) && '' !== sanitize_key( $dependency ) ) $dependencies[ sanitize_key( $dependency ) ] = true;
				}
				$rows[] = array(
					'id' => $id,
					'kind' => $kind,
					'owner' => $owner,
					'classification' => ! empty( $action['human_decision_required'] ) ? 'APPROVAL_REQUIRED' : (
						in_array( $kind, array( 'host_bootstrap_review', 'host_isolation_review', 'external_executor_job', 'external_oauth_reauthorization', 'external_evidence' ), true )
							? 'EXTERNAL_ACTION_REQUIRED' : 'RECONCILIATION_REQUIRED'
					),
					'depends_on' => array_keys( $dependencies ),
					'readback' => isset( $action['readback_ability'] ) && is_string( $action['readback_ability'] ) ? substr( $action['readback_ability'], 0, 128 ) : '',
					'link_slug' => self::local_page_for( $id, $kind ),
					'plan_only' => true,
					'execution_performed' => false,
				);
			}
		}
		$blocked = $valid && isset( $plan['blocking_gates'] ) && is_array( $plan['blocking_gates'] )
			? array_values( array_filter( array_slice( $plan['blocking_gates'], 0, 64 ), 'is_string' ) ) : array();
		return array(
			'contract' => self::CONTRACT,
			'state' => ! $valid ? 'UNAVAILABLE' : ( ! empty( $plan['current_ready'] ) && empty( $blocked ) ? 'OBSERVED_READY' : 'REVIEW_REQUIRED' ),
			'blocking_gates' => $blocked,
			'actions' => $rows,
			'action_count' => count( $rows ),
			'plan_sha256' => $valid && isset( $plan['plan_sha256'] ) && is_string( $plan['plan_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '',
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'automatic_executions_performed' => 0,
		);
	}

	/** Only previously registered local wp-admin routes; no caller-provided links. */
	private static function local_page_for( $id, $kind ) {
		if ( false !== strpos( $id, 'environment' ) || false !== strpos( $id, 'authority' ) || false !== strpos( $id, 'candidate_binding' ) )
			return 'mad4b-control-plane-site-profile';
		if ( false !== strpos( $id, 'skills' ) ) return 'mad4b-control-plane-skills';
		if ( false !== strpos( $id, 'provider' ) ) return 'mad4b-adapter-coverage';
		if ( false !== strpos( $id, 'browser' ) || 'external_executor_job' === $kind ) return 'mad4b-control-plane-connection';
		if ( false !== strpos( $id, 'host' ) ) return 'mad4b-control-plane-connection';
		if ( false !== strpos( $id, 'google' ) || false !== strpos( $id, 'brand' ) ) return 'mad4b-control-plane-context';
		return 'mad4b-runtime-components';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<section class="mad4b-scp-panel" aria-labelledby="mad4b-recovery-title">';
		echo '<h2 id="mad4b-recovery-title">' . esc_html__( 'WordPress recovery plan', 'mad4b-site-control-plane' ) . '</h2>';
		// Deep provider/Skills/authority inventory must never become an
		// incidental Action Center GET cost. Run only when the admin explicitly
		// opens this diagnostic, without triggering an apply action.
		$show = class_exists( 'MAD4B_SCP_Admin_Experience', false )
			&& 'show' === MAD4B_SCP_Admin_Experience::query_string( 'recovery', '', 8 );
		if ( ! $show ) {
			$url = MAD4B_SCP_Admin_Workspace::link( 'mad4b-operator-control-center', array( 'recovery' => 'show' ) );
			echo '<p>' . esc_html__( 'Inspect the current Site Profile, Skills, Write, Providers and Host prerequisites only when you need a detailed recovery plan.', 'mad4b-site-control-plane' ) . '</p>';
			if ( $url ) echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Inspect current recovery plan', 'mad4b-site-control-plane' ) . '</a>';
			echo '</section>';
			return;
		}
		// Fence the complete deep plan, including provider enumeration, between
		// two exact site/runtime identity captures. A racing update disables the
		// entire projection; a stale plan never offers next-step navigation.
		$start_identity = MAD4B_SCP_Recovery_Lifecycle::capture_identity();
		$plan = class_exists( 'MAD4B_SCP_Staging_Certification', false )
			? MAD4B_SCP_Staging_Certification::convergence_plan() : array();
		$end_identity = MAD4B_SCP_Recovery_Lifecycle::capture_identity();
		$lifecycle = MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $start_identity, $end_identity );
		$model = self::model( $plan );
		if ( empty( $lifecycle['identity_bound'] ) ) {
			$model['state'] = $lifecycle['state'];
			$model['actions'] = array();
			$model['action_count'] = 0;
		} else {
			$model['state'] = $lifecycle['state'];
			$mapped = array();
			foreach ( $model['actions'] as $row ) $mapped[ $row['id'] ] = $row;
			$ordered = array();
			foreach ( $lifecycle['ordered_actions'] as $action ) {
				if ( ! isset( $mapped[ $action['id'] ] ) ) continue;
				$row = $mapped[ $action['id'] ];
				$row['lifecycle_stage'] = $action['stage'];
				$ordered[] = $row;
			}
			$model['actions'] = $ordered;
			$model['action_count'] = count( $ordered );
		}
		echo '<p>' . esc_html__( 'These are live, site-scoped observations. Opening a workspace does not approve, execute or certify a repair.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'State:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( $model['state'] ) . '</code> · ';
		echo esc_html( sprintf( __( '%d planned actions', 'mad4b-site-control-plane' ), $model['action_count'] ) ) . '</p>';
		if ( ! empty( $lifecycle['identity_bound'] ) && isset( $plan['readiness_domains'] ) && is_array( $plan['readiness_domains'] ) ) {
			echo '<p><strong>' . esc_html__( 'Readiness by domain:', 'mad4b-site-control-plane' ) . '</strong></p>';
			echo '<ul class="ul-disc">';
			foreach ( $plan['readiness_domains'] as $domain => $domain_state ) {
				if ( ! is_array( $domain_state ) ) continue;
				$name = is_string( $domain ) ? sanitize_key( $domain ) : 'unknown';
				echo '<li><code>' . esc_html( $name ) . '</code>: ';
				echo esc_html( ! empty( $domain_state['ready'] ) ? 'OBSERVED_READY' : 'PENDING_OR_BLOCKED' );
				echo '</li>';
			}
			echo '</ul>';
		}
		if ( $model['blocking_gates'] ) {
			echo '<p><strong>' . esc_html__( 'Pending gates:', 'mad4b-site-control-plane' ) . '</strong> ';
			echo esc_html( implode( ', ', $model['blocking_gates'] ) ) . '</p>';
		}
		if ( empty( $lifecycle['identity_bound'] ) ) {
			echo '<p>' . esc_html__( 'Recovery plan refused: source, site, identity or dependency graph changed or could not be validated. No execution is available.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<p><code>' . esc_html( implode( ', ', $lifecycle['reasons'] ) ) . '</code></p></section>';
			return;
		}
		if ( 'UNAVAILABLE' === $model['state'] ) {
			echo '<p>' . esc_html__( 'The exact recovery plan is unavailable. No actions are authorized.', 'mad4b-site-control-plane' ) . '</p></section>';
			return;
		}
		if ( empty( $model['actions'] ) ) {
			echo '<p>' . esc_html__( 'No repair action was proposed by the current readback.', 'mad4b-site-control-plane' ) . '</p></section>';
			return;
		}
		echo '<div class="mad4b-workspace-table" role="region" tabindex="0"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'Action', 'Decision', 'Owner', 'Depends on', 'Verify after repair', 'Workspace' ) as $heading )
			echo '<th scope="col">' . esc_html( __( $heading, 'mad4b-site-control-plane' ) ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $model['actions'] as $row ) {
			echo '<tr><th scope="row"><code>' . esc_html( $row['id'] ) . '</code></th>';
			echo '<td>' . esc_html( $row['classification'] ) . ' <small>(' . esc_html( isset( $row['lifecycle_stage'] ) ? $row['lifecycle_stage'] : 'REVIEW_REQUIRED' ) . ')</small></td>';
			echo '<td><code>' . esc_html( $row['owner'] ) . '</code></td>';
			echo '<td>' . esc_html( $row['depends_on'] ? implode( ', ', $row['depends_on'] ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $row['readback'] ? $row['readback'] : 'not_specified' ) . '</code></td>';
			$url = MAD4B_SCP_Admin_Workspace::link( $row['link_slug'] );
			echo '<td>';
			if ( '' !== $url ) echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Inspect', 'mad4b-site-control-plane' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $model['plan_sha256'] ) echo '<p class="description">' . esc_html__( 'Plan fingerprint:', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( substr( $model['plan_sha256'], 0, 16 ) ) . '…</code></p>';
		echo '<p class="description">' . esc_html__( 'Developer execution, Host changes, provider activation and new Write grants remain separately governed. No automation or verification was performed by this screen.', 'mad4b-site-control-plane' ) . '</p></section>';
	}
}
