<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A shared backend must serialize ALL sites using one provider account. */
interface MAD4B_SCP_Search_Shared_Budget_Backend extends MAD4B_SCP_Search_Store_Backend {
	public function account_authority_id();
}

/** One atomic account aggregate admits the complete hierarchy together. */
final class MAD4B_SCP_Search_Budgets {
	public static function boot() {
		add_filter( 'mad4b_scp_provider_account_budget_authoritative_reserve', array( __CLASS__, 'foundation_reserve' ), 20, 2 );
		add_filter( 'mad4b_scp_provider_account_budget_authoritative_commit', array( __CLASS__, 'foundation_commit' ), 20, 2 );
		add_filter( 'mad4b_scp_provider_account_budget_authoritative_release', array( __CLASS__, 'foundation_release' ), 20, 2 );
	}
	/** Existing account-authority callers share this coordinator, never a second allowance. */
	public static function foundation_reserve( $previous, array $request ) {
		if ( null !== $previous ) return $previous;
		$account = $request['account_key']; $state = self::status( $account );
		if ( ! is_array( $state ) || empty( $state['hard_global_enforcement'] ) || $request['billing_cycle_id'] !== $state['cycle_id'] ) return null;
		$used = $state['usage_receipt']['remaining'] - $state['remaining'];
		foreach ( $state['reservations'] as $row ) if ( in_array( $row['state'], array( 'reserved', 'entered', 'unknown' ), true ) ) $used += $row['units'];
		if ( $used + $request['units'] > $request['hard_allowance'] - $request['protected_reserve'] ) return MAD4B_SCP_Search_Contracts::error( 'budget_node_exhausted' );
		$id = MAD4B_SCP_Search_Contracts::digest( array( $account, $request['billing_cycle_id'], $request['idempotency_key'] ) );
		$r = self::reserve( $account, $id, array(), $request['units'], 0, time(), $request['reservation_ttl_seconds'] );
		if ( is_wp_error( $r ) ) return $r;
		return array( 'authoritative' => true, 'reservation_id' => $id, 'fencing_epoch' => (string) $r['epoch'], 'used_after' => $used + $request['units'], 'hard_allowance' => $request['hard_allowance'], 'backend_id' => self::backend( $account )->account_authority_id() );
	}
	public static function foundation_commit( $previous, array $receipt ) { return self::foundation_transition( $previous, $receipt, true ); }
	public static function foundation_release( $previous, array $receipt ) { return self::foundation_transition( $previous, $receipt, false ); }
	private static function foundation_transition( $previous, array $receipt, $commit ) {
		if ( null !== $previous ) return $previous;
		$account = $receipt['account_key']; $state = self::status( $account );
		if ( ! is_array( $state ) || empty( $state['hard_global_enforcement'] ) || ! isset( $state['reservations'][ $receipt['reservation_id'] ] ) ) return null;
		$r = $state['reservations'][ $receipt['reservation_id'] ];
		if ( (string) $r['epoch'] !== $receipt['fencing_epoch'] ) return MAD4B_SCP_Search_Contracts::error( 'reservation_fence' );
		if ( $commit && 'reserved' === $r['state'] ) { $r = self::transition( $account, $r['id'], $r['epoch'], 'entered', time() ); if ( is_wp_error( $r ) ) return $r; }
		$r = self::transition( $account, $r['id'], $r['epoch'], $commit ? 'spent' : 'released', time(), $commit ? $r['units'] : null, $commit ? $r['cost_micro'] : null );
		return is_wp_error( $r ) ? $r : array( 'authoritative' => true, 'state' => $r['state'], 'authorizing' => false );
	}
	private static function backend( $account ) {
		$backend = apply_filters( 'mad4b_scp_search_account_budget_backend', MAD4B_SCP_Search_Store::backend(), $account );
		return $backend instanceof MAD4B_SCP_Search_Store_Backend ? $backend : null;
	}
	private static function key( $account ) { return 'mad4b_asi_account_' . hash( 'sha256', $account ); }

	public static function configure( $account, array $policy, array $receipt, $now ) {
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $policy ) || ! MAD4B_SCP_Search_Contracts::id( $account ) || empty( $policy['nodes'] ) || count( $policy['nodes'] ) > 128 || ! isset( $policy['reserve_fraction'], $policy['burst_multiplier'] ) || $policy['reserve_fraction'] < 0 || $policy['reserve_fraction'] >= 1 || $policy['burst_multiplier'] < 1 || $policy['burst_multiplier'] > 10 ) return MAD4B_SCP_Search_Contracts::error( 'budget_policy_invalid' );
		if ( ! isset( $receipt['remaining'], $receipt['observed_at'], $receipt['reset_at'], $receipt['cycle_id'], $receipt['generation'] ) || ! is_int( $receipt['remaining'] ) || $receipt['remaining'] < 0 || $receipt['observed_at'] > $now || $receipt['reset_at'] <= $now || ! MAD4B_SCP_Search_Contracts::sha( $receipt['generation'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_usage_unknown' );
		$ids = array(); $has_root = false;
		foreach ( $policy['nodes'] as $node ) {
			if ( empty( $node['id'] ) || ! MAD4B_SCP_Search_Contracts::id( $node['id'] ) || isset( $ids[ $node['id'] ] ) || ! isset( $node['selectors'] ) || ! is_array( $node['selectors'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_node_invalid' );
			foreach ( array( 'monthly_units', 'daily_units', 'money_micro', 'concurrency', 'per_minute' ) as $field ) if ( ! isset( $node[ $field ] ) || ! is_int( $node[ $field ] ) || $node[ $field ] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'budget_limit_unknown' );
			$ids[ $node['id'] ] = true;
			if ( array() === $node['selectors'] ) $has_root = true;
		}
		if ( ! $has_root ) return MAD4B_SCP_Search_Contracts::error( 'budget_root_required' );
		$backend = self::backend( $account ); if ( ! $backend ) return MAD4B_SCP_Search_Contracts::error( 'budget_authority_unavailable' );
		$key = self::key( $account ); $old = $backend->read( $key ); if ( is_wp_error( $old ) ) return $old;
		if ( is_array( $old ) ) {
			foreach ( $old['reservations'] as $reservation ) if ( in_array( $reservation['state'], array( 'reserved', 'entered', 'unknown' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_reconciliation_required' );
			if ( $old['cycle_id'] === $receipt['cycle_id'] ) return MAD4B_SCP_Search_Contracts::error( 'budget_cycle_already_initialized' );
		}
		$scope = $backend instanceof MAD4B_SCP_Search_Shared_Budget_Backend && MAD4B_SCP_Search_Contracts::id( $backend->account_authority_id() ) ? 'shared_account' : 'local_allocation';
		$state = array( 'contract' => 'mad4b.provider-account-budget-authority.v1', 'account_id' => $account, 'authority_scope' => $scope, 'hard_global_enforcement' => 'shared_account' === $scope, 'policy' => $policy, 'policy_fingerprint' => MAD4B_SCP_Search_Contracts::digest( $policy ), 'cycle_id' => $receipt['cycle_id'], 'reset_at' => $receipt['reset_at'], 'remaining' => $receipt['remaining'], 'usage_receipt' => $receipt, 'epoch' => null === $old ? 1 : $old['epoch'] + 1, 'revision' => 1, 'spent' => array(), 'reservations' => array(), 'authorizing' => false );
		return $backend->compare_exchange( $key, $old, $state ) ? $state : MAD4B_SCP_Search_Contracts::error( 'budget_race' );
	}
	public static function status( $account ) {
		$b = self::backend( $account ); return $b ? $b->read( self::key( $account ) ) : MAD4B_SCP_Search_Contracts::error( 'budget_authority_unavailable' );
	}
	/** Revalidate an existing hold without counting the same request a second time. */
	public static function verify_held( $account, $id, array $dimensions, $units, $cost_micro, $now ) {
		$state = self::status( $account );
		if ( ! is_array( $state ) || $now >= $state['reset_at'] || ! empty( $state['overrun_requires_reconciliation'] ) || ! isset( $state['reservations'][ $id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'reservation_fence' );
		$r = $state['reservations'][ $id ];
		if ( 'reserved' !== $r['state'] || $r['epoch'] !== $state['epoch'] || $r['cycle_id'] !== $state['cycle_id'] || $now >= $r['expires_at'] || $r['units'] !== $units || $r['cost_micro'] !== $cost_micro || ! isset( $r['dimensions_sha256'] ) || $r['dimensions_sha256'] !== MAD4B_SCP_Search_Contracts::digest( $dimensions ) ) return MAD4B_SCP_Search_Contracts::error( 'reservation_binding_mismatch' );
		return array( 'admissible' => true, 'existing_reservation' => true, 'authorizing' => false );
	}
	private static function applies( array $selectors, array $dimensions ) {
		foreach ( $selectors as $key => $value ) if ( ! isset( $dimensions[ $key ] ) || $dimensions[ $key ] !== $value ) return false;
		return true;
	}
	public static function reserve( $account, $reservation_id, array $dimensions, $units, $cost_micro, $now, $ttl = 120, $commit = true ) {
		if ( ! MAD4B_SCP_Search_Contracts::sha( $reservation_id ) || ! is_int( $units ) || $units < 1 || ! is_int( $cost_micro ) || $cost_micro < 0 ) return MAD4B_SCP_Search_Contracts::error( 'budget_estimate_invalid' );
		$b = self::backend( $account ); if ( ! $b ) return MAD4B_SCP_Search_Contracts::error( 'budget_authority_unavailable' );
		$key = self::key( $account ); $old = $b->read( $key );
		if ( ! is_array( $old ) || $now >= $old['reset_at'] || $old['remaining'] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'budget_unknown_or_reset' );
		if ( isset( $old['reservations'][ $reservation_id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'reservation_replay_requires_state_review' );
		if ( count( $old['reservations'] ) >= 5000 || ! empty( $old['overrun_requires_reconciliation'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_reconciliation_required' );
		$next = $old;
		foreach ( $next['reservations'] as $id => $r ) if ( 'reserved' === $r['state'] && $r['expires_at'] <= $now ) unset( $next['reservations'][ $id ] );
		$held = 0; foreach ( $next['reservations'] as $r ) if ( in_array( $r['state'], array( 'reserved', 'entered', 'unknown' ), true ) ) $held += $r['units'];
		$cap = max( 0, $old['remaining'] - (int) ceil( $old['usage_receipt']['remaining'] * $old['policy']['reserve_fraction'] ) );
		if ( $held + $units > $cap ) return MAD4B_SCP_Search_Contracts::error( 'budget_reserve_protected' );
		$matched = array(); $day = gmdate( 'Y-m-d', $now ); $minute = (int) floor( $now / 60 );
		foreach ( $old['policy']['nodes'] as $node ) {
			if ( ! self::applies( $node['selectors'], $dimensions ) ) continue;
			if ( isset( $node['max_depth'] ) && ( ! is_int( $node['max_depth'] ) || ! isset( $dimensions['depth'] ) || $dimensions['depth'] > $node['max_depth'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_depth_exceeded' );
			$id = $node['id']; $matched[] = $id;
			$spent = isset( $next['spent'][ $id ] ) ? $next['spent'][ $id ] : array();
			$monthly = isset( $spent['monthly'] ) ? $spent['monthly'] : 0; $daily = isset( $spent['days'][ $day ] ) ? $spent['days'][ $day ] : 0; $money = isset( $spent['money'] ) ? $spent['money'] : 0; $running = 0; $rate = isset( $spent['minutes'][ $minute ] ) ? $spent['minutes'][ $minute ] : 0;
			foreach ( $next['reservations'] as $r ) if ( in_array( $id, $r['nodes'], true ) && in_array( $r['state'], array( 'reserved', 'entered', 'unknown' ), true ) ) { $monthly += $r['units']; $money += $r['cost_micro']; ++$running; if ( $r['day'] === $day ) $daily += $r['units']; if ( $r['minute'] === $minute ) ++$rate; }
			if ( array() === $node['selectors'] ) {
				$pacing = MAD4B_SCP_Search_Decisions::pacing( max( 0, $cap - $held ) + $daily, $old['reset_at'], $now, $old['policy']['burst_multiplier'] );
				if ( is_wp_error( $pacing ) || $daily + $units > max( $units, $pacing['burst_limit'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_pacing_exhausted' );
			}
			if ( $monthly + $units > $node['monthly_units'] || $daily + $units > $node['daily_units'] || $money + $cost_micro > $node['money_micro'] || $running + 1 > $node['concurrency'] || $rate + 1 > $node['per_minute'] ) return MAD4B_SCP_Search_Contracts::error( 'budget_node_exhausted' );
		}
		if ( ! $matched ) return MAD4B_SCP_Search_Contracts::error( 'budget_no_envelope' );
		$next['reservations'][ $reservation_id ] = array( 'id' => $reservation_id, 'state' => 'reserved', 'units' => $units, 'cost_micro' => $cost_micro, 'nodes' => $matched, 'dimensions_sha256' => MAD4B_SCP_Search_Contracts::digest( $dimensions ), 'expires_at' => $now + max( 10, min( 900, $ttl ) ), 'day' => $day, 'minute' => $minute, 'epoch' => $old['epoch'], 'cycle_id' => $old['cycle_id'] );
		++$next['revision'];
		if ( ! $commit ) return array( 'admissible' => true, 'policy_fingerprint' => $old['policy_fingerprint'], 'cycle_id' => $old['cycle_id'], 'authority_scope' => $old['authority_scope'], 'remaining' => $old['remaining'] - $held, 'authorizing' => false );
		return $b->compare_exchange( $key, $old, $next ) ? $next['reservations'][ $reservation_id ] : MAD4B_SCP_Search_Contracts::error( 'budget_race' );
	}
	public static function transition( $account, $id, $epoch, $state, $now, $actual_units = null, $actual_cost = null ) {
		$b = self::backend( $account ); if ( ! $b ) return MAD4B_SCP_Search_Contracts::error( 'budget_authority_unavailable' );
		$key = self::key( $account ); $old = $b->read( $key );
		if ( ! is_array( $old ) || ! isset( $old['reservations'][ $id ] ) || $old['epoch'] !== $epoch ) return MAD4B_SCP_Search_Contracts::error( 'reservation_fence' );
		$r = $old['reservations'][ $id ]; $next = $old;
		if ( $r['state'] === $state && ( 'spent' !== $state || ( isset( $r['actual_units'], $r['actual_cost_micro'] ) && $r['actual_units'] === $actual_units && $r['actual_cost_micro'] === $actual_cost ) ) ) return $r;
		$allowed = array( 'reserved' => array( 'entered', 'released' ), 'entered' => array( 'unknown', 'spent' ), 'unknown' => array( 'spent', 'released' ) );
		if ( ! isset( $allowed[ $r['state'] ] ) || ! in_array( $state, $allowed[ $r['state'] ], true ) || ( 'entered' === $state && ( $now >= $r['expires_at'] || $now >= $old['reset_at'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'reservation_transition_denied' );
		if ( 'spent' === $state ) {
			if ( ! is_int( $actual_units ) || $actual_units < 0 || ! is_int( $actual_cost ) || $actual_cost < 0 ) return MAD4B_SCP_Search_Contracts::error( 'usage_receipt_invalid' );
			if ( $actual_units > $r['units'] || $actual_cost > $r['cost_micro'] ) { $next['overrun_requires_reconciliation'] = true; $state = 'unknown'; }
			else {
				$next['remaining'] = max( 0, $old['remaining'] - $actual_units );
				foreach ( $r['nodes'] as $node ) {
					$spent = isset( $next['spent'][ $node ] ) ? $next['spent'][ $node ] : array( 'monthly' => 0, 'money' => 0, 'days' => array(), 'minutes' => array() );
					$spent['monthly'] += $actual_units; $spent['money'] += $actual_cost;
					$spent['days'][ $r['day'] ] = ( isset( $spent['days'][ $r['day'] ] ) ? $spent['days'][ $r['day'] ] : 0 ) + $actual_units;
					$spent['minutes'][ $r['minute'] ] = ( isset( $spent['minutes'][ $r['minute'] ] ) ? $spent['minutes'][ $r['minute'] ] : 0 ) + 1;
					foreach ( $spent['minutes'] as $minute => $value ) if ( $minute < floor( $now / 60 ) - 60 ) unset( $spent['minutes'][ $minute ] );
					$next['spent'][ $node ] = $spent;
				}
			}
		}
		$next['reservations'][ $id ]['state'] = $state; ++$next['revision'];
		if ( 'spent' === $state ) { $next['reservations'][ $id ]['actual_units'] = $actual_units; $next['reservations'][ $id ]['actual_cost_micro'] = $actual_cost; }
		return $b->compare_exchange( $key, $old, $next ) ? $next['reservations'][ $id ] : MAD4B_SCP_Search_Contracts::error( 'budget_race' );
	}
}
