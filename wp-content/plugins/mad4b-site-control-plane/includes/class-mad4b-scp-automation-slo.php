<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable denial boundary around existing automatic maintenance handoffs. */
final class MAD4B_SCP_Automation_SLO {
	const CONTRACT = 'mad4b.automation-slo.v1';
	const SWITCH_CONTRACT = 'mad4b.automation-kill-switch.v1';
	const OPTION = 'mad4b_scp_g8_automation_slo_v1';
	const SWITCH_OPTION = 'mad4b_scp_g8_automation_kill_switch_v1';
	const WINDOW = 3600;
	const COOLDOWN = 600;
	const MAX_QUEUE = 4;
	const MAX_BUCKETS = 64;
	const TICKET_TTL = 1200;
	const MAX_RECENT_RECEIPTS = 64;

	public static function boot() {
		add_action( 'admin_post_mad4b_automation_kill_switch', array( __CLASS__, 'admin_switch' ) );
	}

	private static function initial() {
		$binding = MAD4B_SCP_G8_Record::binding();
		if ( is_wp_error( $binding ) ) return $binding;
		return array( 'contract' => self::CONTRACT, 'profile_digest' => MAD4B_SCP_G8_Record::profile(), 'restore_binding' => $binding, 'revision' => 0,
			'buckets' => array(), 'tickets' => array(), 'eligible_workload_count' => 0, 'outcomes' => array(), 'duration_ms_total' => 0,
			'cost_measured_count' => 0, 'cost_micros_total' => 0,
			'outcome_receipts' => array(), 'outcome_root_sha256' => str_repeat( '0', 64 ),
			'authorizing' => false );
	}

	private static function state_fields_valid( array $state ) {
		foreach ( array( 'revision', 'eligible_workload_count', 'duration_ms_total', 'cost_measured_count', 'cost_micros_total' ) as $field ) {
			if ( ! is_int( $state[ $field ] ?? null ) || $state[ $field ] < 0 ) return false;
		}
		if ( ! is_array( $state['outcomes'] ?? null ) || count( $state['outcomes'] ) > 4 ) return false;
		$completed = 0;
		foreach ( $state['outcomes'] as $outcome => $count ) {
			if ( ! in_array( $outcome, array( 'failed', 'handoff', 'verified_repair', 'cancelled' ), true )
				|| ! is_int( $count ) || $count < 0 ) return false;
			$completed += $count;
		}
		// Every admission has exactly one durable outcome or one live/uncertain
		// ticket. An unexplained gap is evidence loss, not a free retry budget.
		if ( $completed + count( $state['tickets'] ) !== $state['eligible_workload_count']
			|| $state['cost_measured_count'] > $completed
			|| $state['revision'] < $state['eligible_workload_count'] ) return false;
		foreach ( $state['buckets'] as $scope => $row ) {
			if ( ! is_string( $scope ) || strlen( $scope ) > 175 || ! is_array( $row ) ) return false;
			if ( 'site' !== $scope && 1 !== preg_match( '/^(provider:[a-z0-9_.-]{1,80}|capability:[a-z0-9_.-]{1,80}:[a-z0-9_.-]{1,80})$/D', $scope ) ) return false;
			foreach ( array( 'started_at', 'attempts', 'errors', 'cooldown_until' ) as $key )
				if ( ! is_int( $row[ $key ] ?? null ) || $row[ $key ] < 0 ) return false;
		}
		// Rolling evidence root survives bounded trimming; retained entries
		// prove their own contiguous chain and exact final root.
		if ( ! is_array( $state['outcome_receipts'] ?? null )
			|| count( $state['outcome_receipts'] ) > self::MAX_RECENT_RECEIPTS
			|| ! is_string( $state['outcome_root_sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state['outcome_root_sha256'] ) ) return false;
		$previous_root = null; $last_seq = 0;
		foreach ( $state['outcome_receipts'] as $entry ) {
			if ( ! is_array( $entry ) || ! is_int( $entry['sequence'] ?? null )
				|| $entry['sequence'] <= $last_seq
				|| ! is_string( $entry['previous_root_sha256'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $entry['previous_root_sha256'] )
				|| ! is_string( $entry['entry_sha256'] ?? null )
				|| ! is_string( $entry['root_sha256'] ?? null ) ) return false;
			if ( null !== $previous_root && ! hash_equals( $previous_root, $entry['previous_root_sha256'] ) )
				return false;
			$plain = $entry; unset( $plain['entry_sha256'], $plain['root_sha256'] );
			$calculated = MAD4B_SCP_G8_Record::digest( $plain );
			if ( ! hash_equals( $calculated, $entry['entry_sha256'] )
				|| ! hash_equals( hash( 'sha256', $entry['previous_root_sha256'] . $calculated ), $entry['root_sha256'] ) )
				return false;
			$previous_root = $entry['root_sha256']; $last_seq = $entry['sequence'];
		}
		if ( $state['outcome_receipts']
			&& ! hash_equals( $state['outcome_root_sha256'], $previous_root ) ) return false;
		if ( ! $state['outcome_receipts'] && $completed > 0 ) return false;
		if ( $last_seq > $completed ) return false;
		foreach ( $state['tickets'] as $token => $ticket ) {
			if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token )
				|| ! is_array( $ticket ) || ( $ticket['token'] ?? '' ) !== $token ) return false;
			foreach ( array( 'provider', 'capability' ) as $field )
				if ( ! is_string( $ticket[ $field ] ?? null ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,80}$/D', $ticket[ $field ] ) ) return false;
			foreach ( array( 'generation', 'runtime_binding' ) as $field )
				if ( ! is_string( $ticket[ $field ] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $ticket[ $field ] ) ) return false;
			if ( ! is_int( $ticket['started_at'] ?? null ) || ! is_int( $ticket['expires_at'] ?? null )
				|| $ticket['expires_at'] <= $ticket['started_at'] || ! is_int( $ticket['switch_revision'] ?? null )
				|| ! is_array( $ticket['restore_binding'] ?? null )
				|| $ticket['restore_binding'] !== ( $state['restore_binding'] ?? null )
				|| ! is_string( $ticket['profile_digest'] ?? null )
				|| $ticket['profile_digest'] !== ( $state['profile_digest'] ?? null ) ) return false;
			// Finishing a failed ticket increments each affected budget. Never
			// accept a ledger where active scopes have lost their budget rows.
			foreach ( array( 'site', 'provider:' . $ticket['provider'],
				'capability:' . $ticket['provider'] . ':' . $ticket['capability'] ) as $scope ) {
				if ( ! isset( $state['buckets'][ $scope ] ) ) return false;
			}
		}
		return true;
	}

	private static function state() {
		$raw = MAD4B_SCP_G8_Record::read( self::OPTION );
		if ( null === $raw ) return self::initial();
		$binding = MAD4B_SCP_G8_Record::binding();
		if ( ! MAD4B_SCP_G8_Record::valid( $raw, self::CONTRACT ) || ( $raw['profile_digest'] ?? '' ) !== MAD4B_SCP_G8_Record::profile()
			|| is_wp_error( $binding ) || serialize( $raw['restore_binding'] ?? null ) !== serialize( $binding )
			|| ! is_array( $raw['buckets'] ?? null ) || ! is_array( $raw['tickets'] ?? null ) || count( $raw['buckets'] ) > self::MAX_BUCKETS
			|| count( $raw['tickets'] ) > self::MAX_QUEUE || ! self::state_fields_valid( $raw ) ) {
			return new WP_Error( 'mad4b_automation_metrics_lost', 'Automation telemetry is invalid or belongs to another profile; automatic work requires reconciliation.' );
		}
		return $raw;
	}

	/** L0/L1 observations and existing governed manual paths do not use this automatic admission. */
	public static function admission( $provider, $capability, $level = 2 ) {
		if ( ! is_int( $level ) || $level < 0 || $level > 5 ) return array( 'allowed' => false, 'reason' => 'autonomy_level_invalid' );
		if ( $level < 2 ) return array( 'allowed' => true, 'reason' => 'observation_preserved', 'authorizing' => false );
		if ( $level > 4 ) return array( 'allowed' => false, 'reason' => 'manual_authority_required', 'authorizing' => false );
		if ( ! MAD4B_SCP_G8_Record::staging() ) return array( 'allowed' => false, 'reason' => 'enrolled_staging_required', 'authorizing' => false );
		foreach ( array( $provider, $capability ) as $id ) if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,80}$/D', $id ) ) return array( 'allowed' => false, 'reason' => 'automation_scope_invalid' );
		$switch = self::switch_status();
		if ( ! $switch['integrity_valid'] ) return array( 'allowed' => false, 'reason' => 'kill_switch_integrity_lost', 'authorizing' => false );
		foreach ( array( '*', $provider . ':*', $provider . ':' . $capability ) as $scope ) {
			if ( ! empty( $switch['scopes'][ $scope ] ) ) return array( 'allowed' => false, 'reason' => 'automation_kill_switch', 'scope' => $scope, 'authorizing' => false );
		}
		$state = self::state();
		if ( is_wp_error( $state ) ) return array( 'allowed' => false, 'reason' => $state->get_error_code(), 'authorizing' => false );
		$now = time();
		foreach ( $state['tickets'] as $ticket ) {
			if ( $ticket['started_at'] > $now + 30 || $ticket['expires_at'] > $now + self::TICKET_TTL + 30 )
				return array( 'allowed' => false, 'reason' => 'clock_skew_requires_reconciliation', 'authorizing' => false );
			if ( ! is_array( $ticket ) || ! is_int( $ticket['expires_at'] ?? null ) || $ticket['expires_at'] <= $now ) return array( 'allowed' => false, 'reason' => 'expired_automation_outcome_uncertain', 'authorizing' => false );
			if ( $ticket['provider'] === $provider && $ticket['capability'] === $capability ) return array( 'allowed' => false, 'reason' => 'capability_queue_busy', 'authorizing' => false );
		}
		if ( count( $state['tickets'] ) >= self::MAX_QUEUE ) return array( 'allowed' => false, 'reason' => 'site_backpressure', 'authorizing' => false );
		foreach ( array( 'site' => 24, 'provider:' . $provider => 6, 'capability:' . $provider . ':' . $capability => 3 ) as $scope => $limit ) {
			$row = $state['buckets'][ $scope ] ?? array( 'started_at' => time(), 'attempts' => 0, 'errors' => 0, 'cooldown_until' => 0 );
			if ( ! is_array( $row ) || ! is_int( $row['attempts'] ?? null ) || ! is_int( $row['errors'] ?? null ) || ! is_int( $row['cooldown_until'] ?? null ) || ! is_int( $row['started_at'] ?? null ) ) return array( 'allowed' => false, 'reason' => 'budget_metrics_invalid', 'authorizing' => false );
			if ( $row['started_at'] > $now + 30 || $row['cooldown_until'] > $now + self::WINDOW + self::COOLDOWN + 30 )
				return array( 'allowed' => false, 'reason' => 'clock_skew_requires_reconciliation', 'scope' => $scope, 'authorizing' => false );
			if ( $row['cooldown_until'] > $now ) return array( 'allowed' => false, 'reason' => 'error_budget_cooldown', 'scope' => $scope, 'authorizing' => false );
			if ( $row['started_at'] + self::WINDOW > time() && $row['attempts'] >= $limit ) return array( 'allowed' => false, 'reason' => 'retry_budget_exhausted', 'scope' => $scope, 'authorizing' => false );
		}
		return array( 'allowed' => true, 'reason' => 'within_budget', 'state_sha256' => MAD4B_SCP_G8_Record::digest( $state ), 'switch_revision' => $switch['revision'], 'authorizing' => false );
	}

	/** Called only at the existing internal automatic handoff; never creates execution permission. */
	public static function reserve( $provider, $capability, $generation ) {
		$decision = self::admission( $provider, $capability );
		if ( empty( $decision['allowed'] ) ) return new WP_Error( 'mad4b_automation_' . $decision['reason'], 'Automatic maintenance is paused.', $decision );
		if ( ! is_string( $generation ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $generation ) ) return new WP_Error( 'mad4b_automation_generation_invalid', 'Exact worker generation is required.' );
		$before = MAD4B_SCP_G8_Record::read( self::OPTION ); $state = self::state();
		if ( is_wp_error( $state ) ) return $state;
		// Bind the decision to the exact same record that is atomically replaced.
		if ( ( null !== $before && serialize( $state ) !== serialize( $before ) ) || ! hash_equals( $decision['state_sha256'], MAD4B_SCP_G8_Record::digest( $state ) ) ) return new WP_Error( 'mad4b_automation_metrics_raced', 'Automation metrics changed during admission.' );
		try { $token = bin2hex( random_bytes( 16 ) ); } catch ( Throwable $error ) { return new WP_Error( 'mad4b_automation_entropy_unavailable', 'Automatic work identity is unavailable.' ); }
		// Expired provider/capability windows are no longer retry authority.
		// Reclaim them without evicting a live ticket scope, an active cooldown,
		// or the site-wide safety bucket. Retain lifetime outcome counters.
		$active_scopes = array();
		foreach ( $state['tickets'] as $active_ticket ) {
			$active_scopes[ 'provider:' . $active_ticket['provider'] ] = true;
			$active_scopes[ 'capability:' . $active_ticket['provider'] . ':' . $active_ticket['capability'] ] = true;
		}
		$clock = time();
		foreach ( $state['buckets'] as $existing_scope => $existing_window ) {
			if ( 'site' === $existing_scope || isset( $active_scopes[ $existing_scope ] ) ) continue;
			if ( $existing_window['started_at'] + self::WINDOW <= $clock && $existing_window['cooldown_until'] <= $clock )
				unset( $state['buckets'][ $existing_scope ] );
		}
		foreach ( array( 'site', 'provider:' . $provider, 'capability:' . $provider . ':' . $capability ) as $scope ) {
			$row = $state['buckets'][ $scope ] ?? array( 'started_at' => 0, 'attempts' => 0, 'errors' => 0, 'cooldown_until' => 0 );
			if ( $row['started_at'] + self::WINDOW <= time() ) $row = array( 'started_at' => time(), 'attempts' => 0, 'errors' => 0, 'cooldown_until' => 0 );
			++$row['attempts']; $state['buckets'][ $scope ] = $row;
		}
		if ( count( $state['buckets'] ) > self::MAX_BUCKETS ) return new WP_Error( 'mad4b_automation_scope_capacity', 'Automation budget scope capacity is reached.' );
		$runtime_binding = MAD4B_SCP_G8_Record::runtime_binding();
		if ( is_wp_error( $runtime_binding ) ) return $runtime_binding;
		$pre_ready = null;
		if ( 'managed-skills' === $provider && 'reconcile' === $capability && class_exists( 'MAD4B_SCP_Skill_Runtime_Certification', false ) ) {
			$prior = MAD4B_SCP_Skill_Runtime_Certification::persisted_status();
			$pre_ready = is_array( $prior ) && true === ( $prior['ready'] ?? false ) && true === ( $prior['build_identity_current'] ?? false );
		}
		if ( 'runtime-convergence' === $provider && 'safe-phases' === $capability && class_exists( 'MAD4B_SCP_Runtime_Convergence', false ) ) {
			$prior = MAD4B_SCP_Runtime_Convergence::status();
			$pre_ready = is_array( $prior ) && empty( $prior['required_blockers'] );
		}
		$ticket = array( 'token' => $token, 'provider' => $provider, 'capability' => $capability, 'generation' => $generation, 'runtime_binding' => $runtime_binding, 'restore_binding' => $state['restore_binding'],
			'pre_ready' => $pre_ready, 'switch_revision' => $decision['switch_revision'],
			'profile_digest' => MAD4B_SCP_G8_Record::profile(), 'started_at' => time(), 'expires_at' => time() + self::TICKET_TTL );
		$state['tickets'][ $token ] = $ticket; ++$state['revision']; ++$state['eligible_workload_count']; $state['seal'] = MAD4B_SCP_G8_Record::seal( $state );
		$ok = MAD4B_SCP_G8_Record::replace( self::OPTION, $before, $state );
		if ( is_wp_error( $ok ) ) return $ok;
		// A switch changed while reserving: no action may run under the old decision.
		$after = self::switch_status();
		if ( ! $after['integrity_valid'] || $after['revision'] !== $decision['switch_revision'] || ! empty( $after['scopes']['*'] ) || ! empty( $after['scopes'][ $provider . ':*' ] ) || ! empty( $after['scopes'][ $provider . ':' . $capability ] ) ) {
			self::finish( $ticket, 'cancelled' ); return new WP_Error( 'mad4b_automation_switch_raced', 'Automatic maintenance was cancelled by its independent switch.' );
		}
		return $ticket;
	}

	/** Revalidate a live automatic ticket at each owned safe-phase mutation boundary. */
	public static function ticket_allowed( array $ticket ) {
		if ( ! MAD4B_SCP_G8_Record::staging() ) return new WP_Error( 'mad4b_automation_staging_lost', 'Automatic Staging eligibility changed.' );
		$state = self::state();
		if ( is_wp_error( $state ) ) return $state;
		$token = $ticket['token'] ?? '';
		if ( is_int( $ticket['started_at'] ?? null ) && is_int( $ticket['expires_at'] ?? null )
			&& ( $ticket['started_at'] > time() + 30 || $ticket['expires_at'] > time() + self::TICKET_TTL + 30 ) )
			return new WP_Error( 'mad4b_automation_clock_skew_requires_reconciliation', 'Ticket clock moved before its admission time.' );
		if ( ! is_string( $token ) || ! isset( $state['tickets'][ $token ] ) || serialize( $state['tickets'][ $token ] ) !== serialize( $ticket )
			|| ! is_int( $ticket['expires_at'] ?? null ) || $ticket['expires_at'] <= time() ) return new WP_Error( 'mad4b_automation_ticket_not_live', 'Automatic ticket expired, changed, or was consumed.' );
		$runtime = MAD4B_SCP_G8_Record::runtime_binding();
		if ( is_wp_error( $runtime ) || ! is_string( $ticket['runtime_binding'] ?? null ) || ! hash_equals( $ticket['runtime_binding'], $runtime ) )
			return new WP_Error( 'mad4b_automation_runtime_changed', 'Automatic work belongs to a different runtime.' );
		$switch = self::switch_status();
		if ( ! $switch['integrity_valid'] ) return new WP_Error( 'mad4b_automation_kill_switch_integrity_lost', 'Automatic switch lost integrity.' );
		if ( ! is_int( $ticket['switch_revision'] ?? null ) || $switch['revision'] !== $ticket['switch_revision'] )
			return new WP_Error( 'mad4b_automation_switch_raced', 'Kill switch revision changed since the automatic ticket was reserved.' );
		$provider = $ticket['provider'] ?? ''; $capability = $ticket['capability'] ?? '';
		if ( ! is_string( $provider ) || ! is_string( $capability ) || ! empty( $switch['scopes']['*'] )
			|| ! empty( $switch['scopes'][ $provider . ':*' ] ) || ! empty( $switch['scopes'][ $provider . ':' . $capability ] ) )
			return new WP_Error( 'mad4b_automation_kill_switch', 'Automatic maintenance was paused.' );
		return true;
	}

	/** Additional denial scopes for a phase inside an already admitted worker. */
	public static function additional_scope_allowed( array $ticket, $provider, $capability ) {
		foreach ( array( $provider, $capability ) as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,80}$/D', $id ) )
				return new WP_Error( 'mad4b_automation_scope_invalid', 'Exact automatic phase scope is required.' );
		}
		$live = self::ticket_allowed( $ticket );
		if ( true !== $live ) return $live;
		$switch = self::switch_status();
		if ( ! $switch['integrity_valid'] || $switch['revision'] !== ( $ticket['switch_revision'] ?? null ) )
			return new WP_Error( 'mad4b_automation_switch_raced', 'Automatic phase switch changed during verification.' );
		foreach ( array( '*', $provider . ':*', $provider . ':' . $capability ) as $scope ) {
			if ( ! empty( $switch['scopes'][ $scope ] ) )
				return new WP_Error( 'mad4b_automation_kill_switch', 'Automatic phase was paused by its independent scope.' );
		}
		// This is a further denial fence, never a second admission or grant.
		return self::ticket_allowed( $ticket );
	}

	/**
	 * Locally witnessed execution correlation (NOT a provider signature,
	 * independent acceptance or permission). Issued only by an already-fenced
	 * Cron worker for one exact changed slice and exact site/restore binding.
	 */
	public static function local_causal_receipt( array $ticket, array $checkpoint ) {
		$slice = $checkpoint['g8_current_slice_changed_safe_phases'] ?? null;
		if ( ! is_string( $ticket['token'] ?? null ) || ! is_string( $ticket['generation'] ?? null )
			|| ! is_string( $ticket['runtime_binding'] ?? null )
			|| ! is_array( $ticket['restore_binding'] ?? null )
			|| ! is_array( $checkpoint['target_identity'] ?? null )
			|| ! is_array( $slice ) || ! $slice || count( $slice ) > 24
			|| 'completed' !== ( $checkpoint['state'] ?? '' )
			|| 'post_update_cron' !== ( $checkpoint['last_execution_source'] ?? '' ) )
			return new WP_Error( 'mad4b_g8_causal_receipt_ineligible', 'Only an exact completed automatic slice can emit local evidence.' );
		foreach ( $slice as $phase ) {
			if ( ! is_string( $phase ) || 1 !== preg_match( '/^[a-z0-9_]{1,80}$/D', $phase ) )
				return new WP_Error( 'mad4b_g8_causal_phase_invalid', 'Invalid changed phase in local execution receipt.' );
		}
		$receipt = array(
			'contract' => 'mad4b.g8-local-causal-receipt.v1',
			'ticket_sha256' => hash( 'sha256', $ticket['token'] ),
			'generation' => $ticket['generation'],
			'runtime_binding' => $ticket['runtime_binding'],
			'restore_binding' => $ticket['restore_binding'],
			'profile_digest' => $ticket['profile_digest'] ?? '',
			'pre_ready' => $ticket['pre_ready'] ?? null,
			'changed_slice_sha256' => MAD4B_SCP_G8_Record::digest( $slice ),
			'target_sha256' => MAD4B_SCP_G8_Record::digest( $checkpoint['target_identity'] ),
			'issued_at' => time(),
			'authorizing' => false,
		);
		$receipt['seal'] = MAD4B_SCP_G8_Record::seal( $receipt );
		return $receipt;
	}

	private static function exact_local_causal_evidence( array $ticket, array $result ) {
		if ( ! function_exists( 'get_option' ) || ! is_array( $result['checkpoint'] ?? null ) )
			return false;
		$checkpoint = $result['checkpoint'];
		$stored = get_option( 'mad4b_scp_runtime_convergence_v1', array() );
		if ( ! is_array( $stored ) || ! MAD4B_SCP_G8_Record::inert( $stored )
			|| ! MAD4B_SCP_G8_Record::inert( $checkpoint )
			|| serialize( $stored ) !== serialize( $checkpoint )
			|| ! is_array( $checkpoint['g8_local_causal_receipt'] ?? null )
			|| ! is_array( $checkpoint['g8_current_slice_changed_safe_phases'] ?? null )
			|| ! $checkpoint['g8_current_slice_changed_safe_phases'] ) return false;
		$receipt = $checkpoint['g8_local_causal_receipt'];
		if ( ! MAD4B_SCP_G8_Record::valid( $receipt, 'mad4b.g8-local-causal-receipt.v1' ) )
			return false;
		$expected = self::local_causal_receipt( $ticket, $checkpoint );
		if ( is_wp_error( $expected ) ) return false;
		// Reconstruct the exact payload, keeping its original time. The live
		// HMAC and independently persisted checkpoint must agree.
		$expected['issued_at'] = $receipt['issued_at'] ?? null;
		$expected['seal'] = MAD4B_SCP_G8_Record::seal( $expected );
		return is_int( $expected['issued_at'] ) && $expected['issued_at'] <= time() + 30
			&& $expected['issued_at'] >= ( $ticket['started_at'] ?? PHP_INT_MAX ) - 30
			&& $expected === $receipt;
	}

	/** No success boolean is accepted; verified repair is recorded only by exact known readback. */
	public static function finish_existing( array $ticket, $result ) {
		$outcome = is_wp_error( $result ) ? 'failed' : 'handoff';
		$readback = 'managed-skills' === ( $ticket['provider'] ?? '' ) && class_exists( 'MAD4B_SCP_Skill_Runtime_Certification', false )
			? MAD4B_SCP_Skill_Runtime_Certification::current_status() : null;
		if ( false === ( $ticket['pre_ready'] ?? null ) && 'managed-skills' === ( $ticket['provider'] ?? '' ) && 'reconcile' === ( $ticket['capability'] ?? '' ) && ! is_wp_error( $result )
			&& is_array( $readback ) && 'mad4b.skill-runtime-certification.v2' === ( $readback['contract'] ?? '' ) && true === ( $readback['ready'] ?? false )
			&& is_array( $persisted = MAD4B_SCP_Skill_Runtime_Certification::persisted_status() ) && true === ( $persisted['build_identity_current'] ?? false ) ) $outcome = 'verified_repair';
		if ( false === ( $ticket['pre_ready'] ?? null ) && 'runtime-convergence' === ( $ticket['provider'] ?? '' ) && 'safe-phases' === ( $ticket['capability'] ?? '' )
			&& is_array( $result ) && 'mad4b.runtime-convergence-apply.v1' === ( $result['contract'] ?? '' )
			&& 'completed' === ( $result['state'] ?? '' )
			&& is_array( $result['changed_safe_phases'] ?? null ) && ! empty( $result['changed_safe_phases'] )
			&& is_array( $result['checkpoint'] ?? null )
			&& 'completed' === ( $result['checkpoint']['state'] ?? '' )
			&& 'post_update_cron' === ( $result['checkpoint']['last_execution_source'] ?? '' )
			&& ( $result['checkpoint']['changed_safe_phases'] ?? null ) === $result['changed_safe_phases']
			&& self::exact_local_causal_evidence( $ticket, $result )
			&& is_array( $result['readback'] ?? null )
			&& is_array( $result['readback']['required_blockers'] ?? null )
			&& empty( $result['readback']['required_blockers'] )
			&& class_exists( 'MAD4B_SCP_Runtime_Convergence', false ) ) {
			$actual = MAD4B_SCP_Runtime_Convergence::status();
			if ( is_array( $actual ) && array_key_exists( 'required_blockers', $actual )
				&& is_array( $actual['required_blockers'] ) && empty( $actual['required_blockers'] ) )
				$outcome = 'verified_repair';
		}
		// A verified repair cannot be recorded after its independent switch,
		// restore binding or exact worker ticket becomes stale. Post-hoc
		// counters are evidence, never permission to execute another action.
		if ( 'verified_repair' === $outcome && is_wp_error( self::ticket_allowed( $ticket ) ) )
			$outcome = 'handoff';
		return self::finish( $ticket, $outcome, $result );
	}

	private static function finish( array $ticket, $outcome, $result = null ) {
		$before = MAD4B_SCP_G8_Record::read( self::OPTION ); $state = self::state();
		if ( is_wp_error( $state ) ) return $state;
		$token = $ticket['token'] ?? '';
		if ( ! isset( $state['tickets'][ $token ] ) || serialize( $state['tickets'][ $token ] ) !== serialize( $ticket ) || $ticket['profile_digest'] !== MAD4B_SCP_G8_Record::profile() ) return new WP_Error( 'mad4b_automation_ticket_stale', 'Automatic outcome is stale, foreign or already consumed.' );
		$runtime_binding = MAD4B_SCP_G8_Record::runtime_binding();
		if ( is_wp_error( $runtime_binding ) || ! hash_equals( $ticket['runtime_binding'], $runtime_binding ) ) return new WP_Error( 'mad4b_automation_runtime_changed', 'Automatic outcome belongs to a different runtime; reconciliation is required.' );
		if ( $ticket['expires_at'] <= time() ) return new WP_Error( 'mad4b_automation_outcome_uncertain', 'Expired automatic work requires reconciliation before retry.' );
		unset( $state['tickets'][ $token ] );
		$state['outcomes'][ $outcome ] = ( $state['outcomes'][ $outcome ] ?? 0 ) + 1;
		$state['duration_ms_total'] += max( 0, time() - $ticket['started_at'] ) * 1000;
		if ( 'failed' === $outcome ) foreach ( array( 'site', 'provider:' . $ticket['provider'], 'capability:' . $ticket['provider'] . ':' . $ticket['capability'] ) as $scope ) {
			++$state['buckets'][ $scope ]['errors'];
			if ( $state['buckets'][ $scope ]['errors'] >= 2 ) $state['buckets'][ $scope ]['cooldown_until'] = time() + self::COOLDOWN;
		}
		// The receipt and counters commit in ONE CAS. This local HMAC-linked
		// history is auditable telemetry, never a native signed execution receipt.
		$last_root = $state['outcome_root_sha256'];
		$entry = array(
			'sequence' => array_sum( $state['outcomes'] ),
			'ticket_sha256' => hash( 'sha256', $ticket['token'] ),
			'provider' => $ticket['provider'],
			'capability' => $ticket['capability'],
			'generation' => $ticket['generation'],
			'restore_epoch' => $ticket['restore_binding']['epoch'],
			'outcome' => $outcome,
			'local_causal_receipt_sha256' => 'verified_repair' === $outcome && is_array( $result )
				? ( $result['checkpoint']['g8_local_causal_receipt']['seal'] ?? '' ) : '',
			'duration_ms' => max( 0, time() - $ticket['started_at'] ) * 1000,
			'settled_at' => time(),
			'previous_root_sha256' => $last_root,
			'authorizing' => false,
		);
		$entry['entry_sha256'] = MAD4B_SCP_G8_Record::digest( $entry );
		$entry['root_sha256'] = hash( 'sha256', $last_root . $entry['entry_sha256'] );
		$state['outcome_root_sha256'] = $entry['root_sha256'];
		$state['outcome_receipts'][] = $entry;
		if ( count( $state['outcome_receipts'] ) > self::MAX_RECENT_RECEIPTS )
			array_shift( $state['outcome_receipts'] );
		++$state['revision']; $state['seal'] = MAD4B_SCP_G8_Record::seal( $state );
		return MAD4B_SCP_G8_Record::replace( self::OPTION, $before, $state );
	}

	public static function switch_status() {
		$raw = MAD4B_SCP_G8_Record::read( self::SWITCH_OPTION );
		$binding = MAD4B_SCP_G8_Record::binding();
		$valid = ! is_wp_error( $binding ) && ( null === $raw || ( MAD4B_SCP_G8_Record::valid( $raw, self::SWITCH_CONTRACT ) && is_array( $raw['scopes'] ?? null )
			&& count( $raw['scopes'] ) <= 64 && is_int( $raw['revision'] ?? null ) && $raw['revision'] >= 0
			&& is_int( $raw['updated_at'] ?? null ) && $raw['updated_at'] > 0 && $raw['updated_at'] <= time() + 30
			&& serialize( $raw['restore_binding'] ?? null ) === serialize( $binding ) ) );
		if ( $valid && is_array( $raw ) ) foreach ( $raw['scopes'] as $scope => $enabled ) {
			if ( ! is_string( $scope ) || 1 !== preg_match( '/^(\\*|[a-z0-9_.-]{1,80}:(\\*|[a-z0-9_.-]{1,80}))$/D', $scope ) || ! is_bool( $enabled ) ) { $valid = false; break; }
		}
		return array( 'integrity_valid' => $valid, 'revision' => $valid && is_array( $raw ) ? $raw['revision'] : 0,
			'scopes' => $valid && is_array( $raw ) ? $raw['scopes'] : array( '*' => true ), 'authorizing' => false );
	}

	public static function change_switch( $scope, $enabled, $expected_revision ) {
		if ( ! current_user_can( 'manage_options' ) || ! MAD4B_SCP_G8_Record::staging() ) return new WP_Error( 'mad4b_automation_switch_admin_required', 'Enrolled Staging administrator is required.' );
		if ( ! is_bool( $enabled ) || ! is_int( $expected_revision ) || ! is_string( $scope ) || 1 !== preg_match( '/^(\*|[a-z0-9_.-]{1,80}:(\*|[a-z0-9_.-]{1,80}))$/D', $scope ) ) return new WP_Error( 'mad4b_automation_switch_input_invalid', 'Exact switch scope, state and revision are required.' );
		$before = MAD4B_SCP_G8_Record::read( self::SWITCH_OPTION ); $status = self::switch_status();
		if ( ! $status['integrity_valid'] || $status['revision'] !== $expected_revision ) return new WP_Error( 'mad4b_automation_switch_revision_conflict', 'Kill switch changed; reread its current revision.' );
		$next = array( 'contract' => self::SWITCH_CONTRACT, 'revision' => $expected_revision + 1, 'scopes' => $status['scopes'],
			'restore_binding' => MAD4B_SCP_G8_Record::binding(), 'updated_by' => get_current_user_id(), 'updated_at' => time(), 'authorizing' => false );
		$next['scopes'][ $scope ] = $enabled;
		if ( count( $next['scopes'] ) > 64 ) return new WP_Error( 'mad4b_automation_switch_capacity', 'Kill switch scope capacity is reached.' );
		$next['seal'] = MAD4B_SCP_G8_Record::seal( $next );
		return MAD4B_SCP_G8_Record::replace( self::SWITCH_OPTION, $before, $next );
	}

	public static function admin_switch() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST required.', '', array( 'response' => 405 ) );
		check_admin_referer( 'mad4b_automation_kill_switch' );
		$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? wp_unslash( $_POST['scope'] ) : '';
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) && ctype_digit( $_POST['revision'] ) ? (int) $_POST['revision'] : -1;
		$state = isset( $_POST['enabled'] ) && is_string( $_POST['enabled'] ) ? $_POST['enabled'] : '';
		$result = in_array( $state, array( '0', '1' ), true ) ? self::change_switch( $scope, '1' === $state, $revision ) : new WP_Error( 'mad4b_automation_switch_input_invalid', 'Invalid switch state.' );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ), '', array( 'response' => 409 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-operator-control-center' ) ); exit;
	}

	public static function status() {
		$state = self::state(); $valid = ! is_wp_error( $state );
		$denominator = $valid ? $state['eligible_workload_count'] : null;
		$outcomes = $valid ? $state['outcomes'] : array();
		$completed = array_sum( $outcomes );
		return array( 'contract' => self::CONTRACT, 'integrity_valid' => $valid, 'state' => $valid ? 'OBSERVED' : 'RECONCILIATION_REQUIRED',
			'exclusion_reason' => $valid ? '' : $state->get_error_code(), 'eligible_workload_count' => $denominator, 'denominator' => 'admitted_existing_automatic_handoffs',
			'outcomes' => $outcomes, 'pending_count' => $valid ? count( $state['tickets'] ) : null,
			'verified_repair_rate' => $denominator > 0 ? ( $outcomes['verified_repair'] ?? 0 ) / $denominator : null,
			'failed_workload_rate' => $denominator > 0 ? ( $outcomes['failed'] ?? 0 ) / $denominator : null,
			'handoff_workload_rate' => $denominator > 0 ? ( $outcomes['handoff'] ?? 0 ) / $denominator : null,
			'cancelled_workload_rate' => $denominator > 0 ? ( $outcomes['cancelled'] ?? 0 ) / $denominator : null,
			'outcome_denominator_includes_pending' => true,
			'mean_completion_ms' => $completed > 0 ? $state['duration_ms_total'] / $completed : null,
			'false_repair_rate' => null, 'rollback_failure_rate' => null, 'quarantine_rate' => null, 'intervention_rate' => null, 'cost_rate' => null,
			'outcome_chain_sha256' => $valid ? $state['outcome_root_sha256'] : null,
			'recent_outcome_receipt_count' => $valid ? count( $state['outcome_receipts'] ) : null,
			'recent_outcome_receipts' => $valid ? $state['outcome_receipts'] : array(),
			'local_chain_is_not_external_execution_certificate' => true,
			'unmeasured_metrics' => array( 'false_repair', 'rollback_failure', 'quarantine', 'intervention', 'cost' ),
			'kill_switch' => self::switch_status(), 'observation_preserved' => true, 'manual_authority_changed' => false, 'authorizing' => false );
	}

	public static function render_controls() {
		if ( ! current_user_can( 'manage_options' ) || ! MAD4B_SCP_G8_Record::staging() ) return;
		$status = self::status(); $switch = $status['kill_switch'];
		echo '<section class="card"><h2>' . esc_html__( 'Automatic maintenance controls', 'mad4b-site-control-plane' ) . '</h2><p>' . esc_html__( 'Pause automatic repair while observations and governed manual actions remain available.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Automation telemetry:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( $status['state'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Global automatic work:', 'mad4b-site-control-plane' ) . '</strong> '
			. esc_html( ! $switch['integrity_valid'] ? 'Blocked: switch integrity requires review' : ( ! empty( $switch['scopes']['*'] ) ? 'Paused' : 'Resume permitted by switch only; governed checks still apply' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Admitted workload:', 'mad4b-site-control-plane' ) . '</strong> '
			. esc_html( null === $status['eligible_workload_count'] ? 'Unknown' : (string) $status['eligible_workload_count'] ) . '</p>';
		$readiness = array(
			'Supply-chain' => class_exists( 'MAD4B_SCP_G8_Supply_Provenance', false ) ? MAD4B_SCP_G8_Supply_Provenance::runtime_status()['state'] : 'UNAVAILABLE',
			'Observation migration' => class_exists( 'MAD4B_SCP_G8_Schema_Migration', false ) ? MAD4B_SCP_G8_Schema_Migration::status()['state'] : 'UNAVAILABLE',
			'External acceptance' => class_exists( 'MAD4B_SCP_G8_Capability_Convergence', false ) ? MAD4B_SCP_G8_Capability_Convergence::live_acceptance()['state'] : 'UNAVAILABLE',
		);
		echo '<h3>' . esc_html__( 'G8 evidence (read-only)', 'mad4b-site-control-plane' ) . '</h3><ul>';
		foreach ( $readiness as $label => $value ) echo '<li><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $value ) . '</li>';
		echo '</ul><p class="description">' . esc_html__( 'Repository checks do not replace signed provider provenance, runtime/browser acceptance or owner authorization.', 'mad4b-site-control-plane' ) . '</p>';
		if ( $switch['integrity_valid'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mad4b_automation_kill_switch' );
			echo '<input type="hidden" name="action" value="mad4b_automation_kill_switch"><input type="hidden" name="revision" value="' . esc_attr( $switch['revision'] ) . '">';
			echo '<label>' . esc_html__( 'Scope (*, provider:*, or provider:capability)', 'mad4b-site-control-plane' ) . ' <input name="scope" value="*" required maxlength="161"></label> ';
			echo '<select name="enabled"><option value="1">' . esc_html__( 'Pause automatic work', 'mad4b-site-control-plane' ) . '</option><option value="0">' . esc_html__( 'Resume under existing policy', 'mad4b-site-control-plane' ) . '</option></select> ';
			submit_button( __( 'Save exact switch state', 'mad4b-site-control-plane' ), 'secondary', 'submit', false ); echo '</form>';
		}
		echo '</section>';
	}
}
