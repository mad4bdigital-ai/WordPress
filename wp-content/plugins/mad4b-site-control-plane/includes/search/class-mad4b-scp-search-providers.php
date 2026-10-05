<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface MAD4B_SCP_Search_SERP_Adapter {
	public function descriptor();
	public function prepare( array $request, array $market );
	public function execute( array $prepared );
	public function normalize( array $payload, array $request );
	public function reconcile( array $job );
}

/** Selection admits only registered adapters, exact generations and known economics. */
final class MAD4B_SCP_Search_Providers {
	public static function adapters() {
		$adapters = apply_filters( 'mad4b_scp_search_serp_adapters', array( new MAD4B_SCP_Search_SerpApi_Adapter(), new MAD4B_SCP_Search_DataForSEO_Adapter() ) );
		$out = array();
		foreach ( (array) $adapters as $adapter ) {
			if ( ! $adapter instanceof MAD4B_SCP_Search_SERP_Adapter ) continue;
			$d = $adapter->descriptor(); $id = isset( $d['provider_id'] ) ? $d['provider_id'] : '';
			if ( MAD4B_SCP_Search_Contracts::id( $id ) && ! isset( $out[ $id ] ) ) $out[ $id ] = $adapter;
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	public static function descriptor_guard( array $d, $now ) {
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $d ) || ! isset( $d['provider_id'], $d['account_id'], $d['capabilities']['engines'], $d['capabilities']['devices'], $d['capabilities']['features'], $d['capabilities']['max_depth'] ) || ! MAD4B_SCP_Search_Contracts::id( $d['provider_id'] ) || ! MAD4B_SCP_Search_Contracts::id( $d['account_id'] ) || ! is_array( $d['capabilities']['engines'] ) || ! is_array( $d['capabilities']['devices'] ) || ! is_array( $d['capabilities']['features'] ) || ! is_int( $d['capabilities']['max_depth'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_descriptor_invalid' );
		if ( ! isset( $d['contract'] ) || 'mad4b.serp-provider-descriptor.v1' !== $d['contract'] || empty( $d['active'] ) || empty( $d['certified'] ) || ! MAD4B_SCP_Search_Contracts::sha( isset( $d['certification_generation'] ) ? $d['certification_generation'] : '' ) || ! isset( $d['certification_expires_at'] ) || $d['certification_expires_at'] <= $now ) return MAD4B_SCP_Search_Contracts::error( 'provider_uncertified' );
		if ( ! isset( $d['usage']['remaining'], $d['usage']['reset_at'], $d['usage']['observed_at'], $d['usage']['expires_at'], $d['economics']['request_units'], $d['economics']['max_cost_micro'], $d['account_id'], $d['evidence_rights'] ) || ! is_int( $d['usage']['remaining'] ) || $d['usage']['remaining'] < 0 || $d['usage']['reset_at'] <= $now || $d['usage']['expires_at'] <= $now || $d['usage']['observed_at'] > $now || ! is_int( $d['economics']['request_units'] ) || $d['economics']['request_units'] < 1 || ! is_int( $d['economics']['max_cost_micro'] ) || $d['economics']['max_cost_micro'] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'provider_economics_unknown' );
		foreach ( array( 'normalized_allowed', 'raw_allowed', 'max_retention_seconds', 'redistribution', 'storage_regions' ) as $field ) if ( ! array_key_exists( $field, $d['evidence_rights'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_rights_unknown' );
		if ( true !== $d['evidence_rights']['normalized_allowed'] || ! is_int( $d['evidence_rights']['max_retention_seconds'] ) || $d['evidence_rights']['max_retention_seconds'] <= 0 ) return MAD4B_SCP_Search_Contracts::error( 'provider_rights_denied' );
		if ( ! empty( $d['shared_account'] ) ) {
			if ( empty( $d['provider_account_ref'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_account_identity_required' );
			$identity = MAD4B_SCP_Provider_Account_Budget_Authority::account_identity( array( 'provider_id' => $d['provider_id'], 'provider_account_ref' => $d['provider_account_ref'] ) );
			if ( is_wp_error( $identity ) || $identity !== $d['account_id'] ) return MAD4B_SCP_Search_Contracts::error( 'provider_account_identity_mismatch' );
		}
		return true;
	}

	public static function select( array $request, array $policy, $now, array $held = array() ) {
		$candidates = array(); $excluded = array();
		foreach ( self::adapters() as $id => $adapter ) {
			$d = $adapter->descriptor(); $guard = self::descriptor_guard( $d, $now );
			if ( is_wp_error( $guard ) ) { $excluded[] = array( 'provider_id' => $id, 'reason' => $guard->get_error_code() ); continue; }
			if ( ! in_array( $id, $policy['allowed'], true ) || in_array( $id, $policy['disabled'], true ) || ! empty( $policy['freeze_spend'] ) || ! in_array( $request['engine'], $d['capabilities']['engines'], true ) || ! in_array( $request['device'], $d['capabilities']['devices'], true ) || $request['depth'] > $d['capabilities']['max_depth'] || array_diff( $request['requested_features'], $d['capabilities']['features'] ) ) { $excluded[] = array( 'provider_id' => $id, 'reason' => 'policy_or_capability_mismatch' ); continue; }
			if ( ! class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ) { $excluded[] = array( 'provider_id' => $id, 'reason' => 'breaker_unavailable' ); continue; }
			$breaker = MAD4B_SCP_Provider_Circuit_Breaker::status( $id, MAD4B_SCP_Site_Profile::site_uuid(), $d['certification_generation'] );
			if ( is_wp_error( $breaker ) || 'closed' !== $breaker['state'] ) { $excluded[] = array( 'provider_id' => $id, 'reason' => 'breaker_probe_required' ); continue; }
			$budget = MAD4B_SCP_Search_Budgets::status( $d['account_id'] );
			if ( ! is_array( $budget ) || ( ! empty( $d['shared_account'] ) && empty( $budget['hard_global_enforcement'] ) && empty( $d['local_allocation_certified'] ) ) || $budget['reset_at'] <= $now || $d['usage']['remaining'] < $d['economics']['request_units'] ) { $excluded[] = array( 'provider_id' => $id, 'reason' => 'budget_authority_required' ); continue; }
			$pressure = 1 / max( 1, $d['usage']['remaining'] );
			$dimensions = $request; $dimensions['provider_id'] = $id;
			$admission = isset( $held[ $id ] ) ? MAD4B_SCP_Search_Budgets::verify_held( $d['account_id'], $held[ $id ], $dimensions, $d['economics']['request_units'], $d['economics']['max_cost_micro'], $now ) : MAD4B_SCP_Search_Budgets::reserve( $d['account_id'], MAD4B_SCP_Search_Contracts::digest( array( 'preview', $request, $id ) ), $dimensions, $d['economics']['request_units'], $d['economics']['max_cost_micro'], $now, 120, false );
			if ( is_wp_error( $admission ) ) { $excluded[] = array( 'provider_id' => $id, 'reason' => $admission->get_error_code() ); continue; }
			$score = $d['economics']['max_cost_micro'] + 1000000 * $pressure + ( isset( $d['health']['latency_ms'] ) ? $d['health']['latency_ms'] : 0 );
			$candidates[] = array( 'provider_id' => $id, 'generation' => $d['certification_generation'], 'score' => $score, 'quota_pressure' => $pressure, 'max_cost_micro' => $d['economics']['max_cost_micro'], 'authority_scope' => $budget['authority_scope'], 'reason' => 'capability_fit_then_economics_health_and_quota' );
		}
		usort( $candidates, static function ( $a, $b ) { $c = $a['score'] <=> $b['score']; return $c ? $c : strcmp( $a['provider_id'], $b['provider_id'] ); } );
		return array( 'selected' => $candidates ? $candidates[0] : null, 'alternatives' => $candidates, 'excluded' => $excluded, 'authorizing' => false );
	}

	public static function public_descriptor( array $d ) {
		return MAD4B_SCP_Search_Evidence::untrusted( array_intersect_key( $d, array_flip( array( 'contract', 'provider_id', 'family', 'active', 'certified', 'capabilities', 'usage', 'economics', 'health', 'certification_generation', 'evidence_rights' ) ) ) );
	}
	public static function probe( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_write() ) return MAD4B_SCP_Search_Contracts::error( 'execution_unauthorized' );
		$adapters = self::adapters(); $id = isset( $input['provider_id'] ) ? $input['provider_id'] : '';
		if ( ! isset( $adapters[ $id ] ) || ! method_exists( $adapters[ $id ], 'health_probe' ) ) return MAD4B_SCP_Search_Contracts::error( 'health_probe_unavailable' );
		$d = $adapters[ $id ]->descriptor(); $guard = self::descriptor_guard( $d, time() ); if ( is_wp_error( $guard ) ) return $guard;
		$attempt = MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt( $id, MAD4B_SCP_Site_Profile::site_uuid(), $d['certification_generation'], true ); if ( is_wp_error( $attempt ) ) return $attempt;
		$result = $adapters[ $id ]->health_probe();
		MAD4B_SCP_Provider_Circuit_Breaker::record_result( $attempt, ! is_wp_error( $result ), is_wp_error( $result ) ? 'transport_error' : '' );
		return is_wp_error( $result ) ? $result : array( 'provider_id' => $id, 'healthy' => true, 'paid_search_performed' => false, 'authorizing' => false );
	}
}
