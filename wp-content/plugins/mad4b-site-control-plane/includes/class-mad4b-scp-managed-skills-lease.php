<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Managed_Skills_Lease {
	private static function compare_and_swap_option( $name, $expected, $replacement = null ) {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) return false;
		$where = array( 'option_name' => (string) $name, 'option_value' => maybe_serialize( $expected ) );
		if ( null === $replacement ) {
			$changed = $wpdb->delete( $wpdb->options, $where, array( '%s', '%s' ) );
		} else {
			$changed = $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $replacement ) ), $where, array( '%s' ), array( '%s', '%s' ) );
		}
		if ( 1 === (int) $changed ) { wp_cache_delete( (string) $name, 'options' ); return true; }
		return false;
	}
    /**
     * One narrowly named compensation helper for optional advisory preferences.
     * The global CAS primitive remains PRIVATE and may not be used as a generic
     * publicly callable WordPress Options mutation API.
     */
    public static function compensate_optional_governance_option( $owner, $expected, $replacement = null ) {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) return false;
        $lock = get_option( 'mad4b_scp_optional_governance_settings_lock_v1', array() );
        if ( ! is_array( $lock ) || ! isset( $lock['owner'], $lock['expires_at_epoch'] )
            || ! is_string( $owner ) || '' === $owner
            || ! hash_equals( (string) $lock['owner'], $owner )
            || time() > (int) $lock['expires_at_epoch'] ) return false;
        return self::compare_and_swap_option(
            'mad4b_scp_optional_governance_options_v1', $expected, $replacement );
    }
	public static function refresh( $option_name, $ttl, $owner ) {
		$current = get_option( $option_name, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! hash_equals( (string) $current['owner'], (string) $owner ) ) return new WP_Error( 'mad4b_remote_skill_lock_fenced', 'Managed Skill reconciliation lost its durable lock ownership.' );
		$now = time();
		if ( $now > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) return new WP_Error( 'mad4b_remote_skill_lock_expired', 'Managed Skill reconciliation lock expired before heartbeat.' );
		$next = $current; $next['expires_at_epoch'] = $now + (int) $ttl; $next['heartbeat_at'] = gmdate( 'c', $now );
		$current_serialized = maybe_serialize( $current ); $next_serialized = maybe_serialize( $next );
		if ( is_string( $current_serialized ) && is_string( $next_serialized ) && hash_equals( hash( 'sha256', $current_serialized ), hash( 'sha256', $next_serialized ) ) ) return true;
		if ( ! self::compare_and_swap_option( $option_name, $current, $next ) ) return new WP_Error( 'mad4b_remote_skill_lock_heartbeat_raced', 'Managed Skill reconciliation lock changed during heartbeat.' );
		return true;
	}
	public static function acquire( $option_name, $ttl ) {
		$owner = strtolower( wp_generate_uuid4() );
		$record = array( 'owner' => $owner, 'expires_at_epoch' => time() + (int) $ttl, 'acquired_at' => gmdate( 'c' ) );
		if ( add_option( $option_name, $record, '', false ) ) return $owner;
		$current = get_option( $option_name, array() );
		if ( is_array( $current ) && time() > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			if ( ! self::compare_and_swap_option( $option_name, $current, null ) ) return new WP_Error( 'mad4b_remote_skill_lock_reclaim_raced', 'Managed Skill reconciliation lock changed while reclaiming an expired lease.' );
			if ( add_option( $option_name, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_remote_skill_reconciliation_busy', 'Managed Skill reconciliation already has an active durable lease.' );
	}
	public static function release( $option_name, $owner ) {
		$current = get_option( $option_name, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $owner ) ) self::compare_and_swap_option( $option_name, $current, null );
	}
}
