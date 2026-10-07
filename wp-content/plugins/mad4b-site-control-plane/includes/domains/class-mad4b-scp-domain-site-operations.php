<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local operational plans do not turn backup, cache or host inventory into mutation authority. */
final class MAD4B_SCP_Domain_Site_Operations {
	public static function validate( $profile, array $desired, array $facts ) {
		switch ( $profile ) {
			case 'operations_backup': return self::backup( $desired, $facts );
			case 'operations_cache': return self::cache( $desired, $facts );
			case 'operations_redirect': return self::redirects( $desired, $facts );
			case 'operations_security': return self::security( $desired, $facts );
		}
		return MAD4B_SCP_Domain_Contracts::error( 'operations_profile' );
	}

	private static function backup( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'action','package_sha256','relative_path','restore_epoch' ), array( 'action','package_sha256','relative_path','restore_epoch' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! in_array( $desired['action'], array( 'inspect','trigger','restore_readiness' ), true ) || ! MAD4B_SCP_Domain_Contracts::sha( $desired['package_sha256'] ) || $desired['package_sha256'] !== ( $facts['package_sha256'] ?? '' ) || ! is_int( $desired['restore_epoch'] ) || $desired['restore_epoch'] < 0 || $desired['restore_epoch'] !== ( $facts['restore_epoch'] ?? null ) || true !== ( $facts['protected_receipts_outside_backup'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'backup_package_or_epoch' );
		$path = self::path( $desired['relative_path'], false );
		if ( is_wp_error( $path ) || ! in_array( $path, $facts['allowed_artifact_paths'] ?? array(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'backup_artifact_path' );
		$result = MAD4B_SCP_Domain_Contracts::result( 'operations_backup', $desired, array( 'exact_package_root','current_restore_epoch','protected_receipts','no_installer_credentials' ), 'inspect' === $desired['action'] ? 'read' : 'high_risk_explicit_gate' );
		$result['execution_supported'] = false;
		$result['restore_never_replays_authority'] = true;
		return $result;
	}

	private static function cache( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'targets','whole_site' ), array( 'targets','whole_site' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( false !== $desired['whole_site'] || true !== ( $facts['frontend_readback_contract'] ?? null ) || ! is_array( $desired['targets'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['targets'] ) || ! $desired['targets'] || count( $desired['targets'] ) > 32 ) return MAD4B_SCP_Domain_Contracts::error( 'cache_blast_radius' );
		$seen = array();
		foreach ( $desired['targets'] as $target ) {
			$path = self::path( $target, true );
			if ( is_wp_error( $path ) || '/' === $path || isset( $seen[ $path ] ) || ! in_array( $path, $facts['allowed_cache_targets'] ?? array(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'cache_target' );
			$seen[ $path ] = true;
		}
		return MAD4B_SCP_Domain_Contracts::result( 'operations_cache', $desired, array( 'narrow_target_grant','no_global_purge','frontend_readback' ), 'governed_cache_effect' );
	}

	private static function redirects( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'rules' ), array( 'rules' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( true !== ( $facts['redirect_inventory_complete'] ?? null ) || true !== ( $facts['language_scope_authorized'] ?? null ) || ! is_array( $desired['rules'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['rules'] ) || ! $desired['rules'] || count( $desired['rules'] ) > 32 || ! is_array( $facts['existing_redirects'] ?? null ) || count( $facts['existing_redirects'] ) > 256 ) return MAD4B_SCP_Domain_Contracts::error( 'redirect_scope_or_inventory' );
		$edges = array(); $changed = array();
		foreach ( $facts['existing_redirects'] as $source => $target ) {
			if ( is_wp_error( self::path( $source, true ) ) || is_wp_error( self::path( $target, true ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'redirect_existing_path' );
			$edges[ $source ] = $target;
		}
		foreach ( $desired['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) return MAD4B_SCP_Domain_Contracts::error( 'redirect_rule' );
			$check = MAD4B_SCP_Domain_Contracts::keys( $rule, array( 'source','target','status' ), array( 'source','target','status' ) );
			if ( is_wp_error( $check ) ) return $check;
			$source = self::path( $rule['source'], true ); $target = self::path( $rule['target'], true );
			if ( is_wp_error( $source ) || is_wp_error( $target ) || $source === $target || isset( $changed[ $source ] ) || ! is_int( $rule['status'] ) || ! in_array( $rule['status'], array( 301,302,307,308 ), true ) || ! in_array( $source, $facts['allowed_redirect_sources'] ?? array(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'redirect_path_status_or_grant' );
			$changed[ $source ] = true; $edges[ $source ] = $target;
		}
		foreach ( array_keys( $changed ) as $source ) {
			$seen = array(); $cursor = $source;
			while ( isset( $edges[ $cursor ] ) ) {
				if ( isset( $seen[ $cursor ] ) || count( $seen ) >= 32 ) return MAD4B_SCP_Domain_Contracts::error( 'redirect_loop_or_chain' );
				$seen[ $cursor ] = true; $cursor = $edges[ $cursor ];
			}
		}
		return MAD4B_SCP_Domain_Contracts::result( 'operations_redirect', $desired, array( 'same_origin_relative_paths','exact_status','complete_loop_check','language_scope','cache_readback_required' ), 'governed_redirect_effect' );
	}

	private static function security( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'action','subject_sha256','masked' ), array( 'action','subject_sha256','masked' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! in_array( $desired['action'], array( 'diagnostics','ban_review','restore_review' ), true ) || ! MAD4B_SCP_Domain_Contracts::sha( $desired['subject_sha256'] ) || true !== $desired['masked'] || true !== ( $facts['object_access'] ?? null ) || true !== ( $facts['log_redaction_verified'] ?? null ) || true !== ( $facts['local_provider_scope'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'security_scope_or_privacy' );
		if ( 'diagnostics' !== $desired['action'] && ( ! MAD4B_SCP_Domain_Contracts::sha( $facts['current_actor_sha256'] ?? null ) || $desired['subject_sha256'] === $facts['current_actor_sha256'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'security_self_lockout' );
		$result = MAD4B_SCP_Domain_Contracts::result( 'operations_security', $desired, array( 'masked_diagnostics','no_self_lockout','local_capability_only','reviewed_handoff' ), 'diagnostics' === $desired['action'] ? 'private_read' : 'high_risk_explicit_gate' );
		$result['execution_supported'] = false;
		return $result;
	}

	/** Exact relative paths only: no authorities, query, fragment, encoded aliases or traversal. */
	public static function path( $value, $absolute ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1024 || preg_match( '/[^\x21-\x7E]/', $value ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '%' ) || false !== strpos( $value, ':' ) || false !== strpos( $value, '?' ) || false !== strpos( $value, '#' ) || false !== strpos( $value, '//' ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $value ) || ( $absolute ? '/' !== $value[0] : '/' === $value[0] ) ) return MAD4B_SCP_Domain_Contracts::error( 'path_alias' );
		return $value;
	}
}
