<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Task-first, read-only operator journey. No executor and no authority
 * inference: independent site, Skills, Browser and existing Operator facts.
 */
final class MAD4B_SCP_Guided_Operator_Experience {
	const CONTRACT = 'mad4b.guided-operator-experience.v1';

	private static function step( $id, $title, $summary, $state, $route, $query, $owner, $verify, $weight ) {
		return array( 'id' => $id, 'title' => $title, 'summary' => $summary,
			'state' => $state, 'route' => $route, 'query' => $query,
			'owner' => $owner, 'verify' => $verify, 'weight' => $weight,
			'execution_performed' => false, 'authority_granted' => false );
	}

	/** Pure model: missing observations always remain NOT_CHECKED. */
	public static function model( $operator, $profile, $skills, $browser ) {
		$operator = is_array( $operator ) ? $operator : array();
		$profile = is_array( $profile ) ? $profile : array();
		$skills = is_array( $skills ) ? $skills : array();
		$browser = is_array( $browser ) ? $browser : array();
		$reasons = isset( $operator['reasons'] ) && is_array( $operator['reasons'] )
			? array_values( array_filter( array_slice( $operator['reasons'], 0, 64 ), 'is_string' ) ) : array();
		$operational = isset( $operator['operational'] ) && is_array( $operator['operational'] ) ? $operator['operational'] : array();
		$provider = isset( $operational['provider_closure'] ) && is_array( $operational['provider_closure'] ) ? $operational['provider_closure'] : array();
		$write = isset( $operational['write_authority'] ) && is_array( $operational['write_authority'] ) ? $operational['write_authority'] : array();
		$site_ready = isset( $profile['authority_ready'] ) && true === $profile['authority_ready'];
		$has_site = isset( $profile['authority_ready'] );
		$skills_ready = true === ( isset( $skills['ready'] ) ? $skills['ready'] : null )
			&& true === ( isset( $skills['build_identity_current'] ) ? $skills['build_identity_current'] : null );
		$needs_skills = $site_ready && ! $skills_ready && ! empty( $profile['skills_enabled'] );
		$write_blocked = isset( $write['ready'] ) && false === $write['ready'];
		$browser_invalid = isset( $browser['preference_valid'] ) && false === $browser['preference_valid'];
		$provider_blocked = isset( $provider['action_required_count'] ) && (int) $provider['action_required_count'] > 0;
		$uncertain = count( array_intersect( $reasons, array( 'mutation_state_uncertain', 'recovery_required' ) ) ) > 0;
		$lanes = isset( $operational['lanes'] ) && is_array( $operational['lanes'] ) ? $operational['lanes'] : array();
		$host_blocked = count( array_intersect( $reasons, array( 'developer_lane_not_ready', 'developer_breakglass_lane_not_ready' ) ) ) > 0
			&& 'resolve_developer_host_execution_prerequisites' === ( $lanes['client_action'] ?? '' );
		// Write readiness is not evidence of an actual pending approval ticket.
		// The approval inbox remains an unverified independent human decision lane.
		$steps = array(
			self::step( 'site', __( 'Identify this WordPress site', 'mad4b-site-control-plane' ),
				__( 'Confirm the exact origin, environment and deployment identity.', 'mad4b-site-control-plane' ),
				$site_ready ? 'OBSERVED_READY' : ( $has_site ? 'NEEDS_ACTION' : 'NOT_CHECKED' ),
				'mad4b-control-plane-site-profile', array(), 'site_administrator', 'mad4b/site-profile-status', 10 ),
			self::step( 'connection', __( 'Connect and verify ChatGPT', 'mad4b-site-control-plane' ),
				__( 'Complete OAuth consent and verify an external read. A configured profile alone is not certification.', 'mad4b-site-control-plane' ),
				$site_ready ? 'NOT_CHECKED' : 'WAITING_FOR_SITE',
				'mad4b-control-plane-connection', array( 'tab' => 'readiness' ), 'site_administrator', 'mad4b/connection-status', 30 ),
			self::step( 'skills', __( 'Restore Managed Skills', 'mad4b-site-control-plane' ),
				__( 'Reconcile managed seeds and providers, then verify the certificate for this exact build.', 'mad4b-site-control-plane' ),
				isset( $profile['skills_enabled'] ) && false === $profile['skills_enabled'] && $site_ready ? 'NOT_APPLICABLE'
				: ( $needs_skills ? 'NEEDS_ACTION' : ( $skills_ready ? 'OBSERVED_READY' : 'NOT_CHECKED' ) ),
				'mad4b-control-plane-skills', array(), 'site_administrator', 'mad4b/skill-runtime-certification', 20 ),
			self::step( 'providers', __( 'Review affected provider capabilities', 'mad4b-site-control-plane' ),
				__( 'Inspect installed capabilities and certify only the specific missing provider evidence.', 'mad4b-site-control-plane' ),
				$provider_blocked ? 'NEEDS_ACTION' : ( isset( $provider['action_required_count'] ) && 'unavailable' !== ( $provider['state'] ?? 'unavailable' ) ? 'OBSERVED_NO_ACTION' : 'NOT_CHECKED' ),
				'mad4b-adapter-coverage', array( 'tab' => 'functional' ), 'provider_operator', 'mad4b/provider-closure-matrix', 35 ),
			self::step( 'browser', __( 'Configure browser acceptance', 'mad4b-site-control-plane' ),
				__( 'Choose a registered site adapter and external runner. A saved preference does not certify a test.', 'mad4b-site-control-plane' ),
				$browser_invalid ? 'NEEDS_ACTION' : 'NOT_CHECKED', 'mad4b-browser-acceptance', array(), 'browser_operator', 'mad4b/browser-acceptance-result', 50 ),
			self::step( 'approvals', __( 'Review pending change approvals', 'mad4b-site-control-plane' ),
				__( 'Inspect the exact affected scope and existing ticket before approving or rejecting.', 'mad4b-site-control-plane' ),
				'NOT_CHECKED',
				'mad4b-approval-decisions', array( 'view' => 'actionable' ), 'authorized_approver', 'mad4b/approval-ticket-status', 40 ),
		);
		if ( $write_blocked ) $steps[] = self::step( 'write', __( 'Review exact write readiness', 'mad4b-site-control-plane' ),
			__( 'The write runtime is not ready. Verify grants and candidate binding before seeking approval; no grants are created here.', 'mad4b-site-control-plane' ),
			'NEEDS_ACTION', 'mad4b-control-plane', array( 'tab' => 'overview' ), 'governed_operator', 'mad4b/staging-write-readiness', 15 );
		if ( $uncertain ) array_unshift( $steps, self::step( 'recovery', __( 'Resolve an uncertain previous change', 'mad4b-site-control-plane' ),
			__( 'Review the last receipt and independently read back the affected object before any retry.', 'mad4b-site-control-plane' ),
			'NEEDS_ACTION', 'mad4b-control-plane', array( 'tab' => 'mutations' ), 'governed_operator', 'mad4b/mutation-status', 0 ) );
		if ( $site_ready && isset( $profile['deployment_binding_configured'] )
			&& false === $profile['deployment_binding_configured'] ) {
			$steps[] = self::step( 'deployment', __( 'Review independent deployment identity', 'mad4b-site-control-plane' ),
				__( 'Site enrollment is valid, but independent same-origin clone protection is not configured. Host setup is separate from current Read and Write grants.', 'mad4b-site-control-plane' ),
				'EXTERNAL_ACTION', 'mad4b-control-plane-site-profile', array(), 'host_operator', 'mad4b/site-profile-status', 65 );
		}
		if ( $host_blocked ) $steps[] = self::step( 'host', __( 'Review isolated developer execution', 'mad4b-site-control-plane' ),
			__( 'Host sandbox prerequisites require an authorized external operator. Existing safe lanes remain usable.', 'mad4b-site-control-plane' ),
			'EXTERNAL_ACTION', 'mad4b-control-plane-connection', array( 'tab' => 'isolation' ), 'host_operator', 'mad4b/developer-host-capabilities', 60 );
		// Do not turn an unknown issue into a false success, nor make every
		// optional developer lane a precondition for core read-only operation.
		if ( empty( $operator['contract'] ) || 'mad4b.operator-control-center.v1' !== $operator['contract']
			|| ! empty( $operator['mutation_performed'] ) || ! empty( $operator['production_authorized'] ) ) {
			return array( 'contract' => self::CONTRACT, 'state' => 'EVIDENCE_UNTRUSTED',
				'steps' => array(), 'next_step' => null, 'authorizing' => false, 'mutation_performed' => false );
		}
		usort( $steps, static function ( $a, $b ) {
			$pa = 'NEEDS_ACTION' === $a['state'] ? 0 : ( 'EXTERNAL_ACTION' === $a['state'] ? 1 : 2 );
			$pb = 'NEEDS_ACTION' === $b['state'] ? 0 : ( 'EXTERNAL_ACTION' === $b['state'] ? 1 : 2 );
			return $pa === $pb ? $a['weight'] - $b['weight'] : $pa - $pb;
		} );
		$next = null;
		foreach ( $steps as $step ) {
			if ( 'NEEDS_ACTION' === $step['state'] ) { $next = $step['id']; break; }
		}
		if ( null === $next ) {
			foreach ( $steps as $step ) if ( 'NOT_CHECKED' === $step['state'] ) { $next = $step['id']; break; }
		}
		return array( 'contract' => self::CONTRACT, 'state' => null === $next ? 'OBSERVED_NO_PENDING_ACTION' : 'REVIEW_REQUIRED',
			'steps' => $steps, 'next_step' => $next, 'authorizing' => false, 'mutation_performed' => false,
			'release_certified' => false, 'browser_certified' => false );
	}

	public static function render( $snapshot ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		// A broken optional status provider must never crash Action Center or
		// turn a failed read into a success/authority claim.
		$model = array( 'state' => 'EVIDENCE_UNTRUSTED', 'steps' => array(), 'next_step' => null );
		try {
			$site = class_exists( 'MAD4B_SCP_Site_Profile', false ) ? MAD4B_SCP_Site_Profile::status() : array();
			$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification', false )
				? MAD4B_SCP_Skill_Runtime_Certification::persisted_status() : array();
			$browser = class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', false )
				? MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection() : array();
			$model = self::model( $snapshot, $site, $skills, $browser );
		} catch ( Throwable $error ) {
			$model = array( 'state' => 'EVIDENCE_UNTRUSTED', 'steps' => array(), 'next_step' => null );
		}
		echo '<section class="mad4b-scp-panel mad4b-guided-journey" aria-labelledby="mad4b-guided-title">';
		echo '<h2 id="mad4b-guided-title">' . esc_html__( 'Your next safe steps', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'This checklist adapts to the current site and existing observations. It does not grant access, perform repairs, or claim release acceptance.', 'mad4b-site-control-plane' ) . '</p>';
		if ( 'EVIDENCE_UNTRUSTED' === $model['state'] ) {
			echo '<p>' . esc_html__( 'Current operator evidence is incomplete or untrusted. Reopen Runtime Components before taking action.', 'mad4b-site-control-plane' ) . '</p></section>';
			return;
		}
		echo '<ol class="mad4b-guided-steps">';
		foreach ( $model['steps'] as $step ) {
			$url = MAD4B_SCP_Admin_Workspace::link( $step['route'], $step['query'] );
			if ( '' === $url ) continue;
			$is_next = $step['id'] === $model['next_step'];
			echo '<li class="mad4b-guided-step' . ( $is_next ? ' is-next' : '' ) . '"' . ( $is_next ? ' aria-current="step"' : '' ) . '>';
			$state_labels = array(
				'OBSERVED_READY' => __( 'Ready', 'mad4b-site-control-plane' ),
				'NEEDS_ACTION' => __( 'Needs attention', 'mad4b-site-control-plane' ),
				'NOT_CHECKED' => __( 'Not checked', 'mad4b-site-control-plane' ),
				'NOT_APPLICABLE' => __( 'Not applicable', 'mad4b-site-control-plane' ),
				'WAITING_FOR_SITE' => __( 'Complete site identification first', 'mad4b-site-control-plane' ),
				'OBSERVED_NO_ACTION' => __( 'No action currently reported', 'mad4b-site-control-plane' ),
				'EXTERNAL_ACTION' => __( 'External action required', 'mad4b-site-control-plane' ),
			);
			echo '<div><strong>' . esc_html( $step['title'] ) . '</strong> <span class="mad4b-guided-state">' . esc_html( isset( $state_labels[ $step['state'] ] ) ? $state_labels[ $step['state'] ] : __( 'Not checked', 'mad4b-site-control-plane' ) ) . '</span></div>';
			echo '<p>' . esc_html( $step['summary'] ) . '</p>';
			echo '<p class="description"><strong>' . esc_html__( 'Responsible:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( str_replace( '_', ' ', $step['owner'] ) ) . '</p>';
			echo '<a class="button ' . ( $is_next ? 'button-primary' : 'button-secondary' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $is_next ? __( 'Continue with this step', 'mad4b-site-control-plane' ) : __( 'Open this step', 'mad4b-site-control-plane' ) ) . '</a>';
			echo '</li>';
		}
		echo '</ol></section>';
	}
}
