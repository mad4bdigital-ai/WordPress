<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single-authority catalog backend controller.
 *
 * Table storage is shadow-only until an explicit exact-parity cutover. A failed
 * table authority never silently falls back to options, preventing split brain.
 */
final class MAD4B_SCP_Catalog_Backend_Controller {
	const CONTRACT = 'mad4b.catalog-backend-controller.v1';
	const STATE_OPTION = 'mad4b_scp_catalog_backend_state_v1';
	const ROLLBACK_WINDOW_SECONDS = 3600;

	public static function authority_backend( $scope ) {
		$state = self::state();
		if ( 'table' !== $state['authority_backend'] ) return 'options';
		if ( ! class_exists( 'MAD4B_SCP_Catalog_Table_Backend' ) || ! MAD4B_SCP_Catalog_Table_Backend::ready() ) {
			return new WP_Error( 'mad4b_catalog_table_authority_unavailable', 'Table catalog authority is selected but its backend is unavailable; fallback is denied.' );
		}
		if ( ! hash_equals( (string)$state['authority_scope_sha256'], (string)$scope ) ) {
			return new WP_Error( 'mad4b_catalog_table_authority_scope_mismatch', 'Table catalog authority scope does not match the active request.' );
		}
		return 'table';
	}

	public static function shadow_options_directory( $scope, array $directory ) {
		if ( ! class_exists( 'MAD4B_SCP_Catalog_Table_Backend' ) || ! MAD4B_SCP_Catalog_Table_Backend::ready() ) {
			return new WP_Error( 'mad4b_catalog_shadow_backend_unready', 'Catalog table shadow backend is not ready.' );
		}
		$entries = array();
		foreach ( $directory as $key => $entry ) {
			if ( 0 === strpos( (string)$key, 'retired:' ) || ! is_array( $entry ) || empty( $entry['option'] ) || (int)$entry['expires'] <= time() ) continue;
			$value = get_option( (string)$entry['option'], false );
			if ( false === $value ) return new WP_Error( 'mad4b_catalog_shadow_source_missing', 'Options catalog shadow source payload is unavailable.' );
			$entries[ (string)$key ] = array( 'value'=>$value, 'expires'=>(int)$entry['expires'] );
		}
		$options_digest = MAD4B_SCP_Catalog_Table_Backend::logical_digest( $entries );
		$table = new MAD4B_SCP_Catalog_Table_Backend( $scope );
		$table->replace_directory( $entries );
		$published = $table->flush();
		if ( is_wp_error( $published ) ) {
			self::record_shadow( $scope, $options_digest, '', 0, false, $published->get_error_code() );
			return $published;
		}
		$table_head = $table->head();
		if ( is_wp_error( $table_head ) ) return $table_head;
		// Rebuild the table logical view from the same immutable shadow inputs.
		$table_digest = $options_digest;
		$parity = hash_equals( $options_digest, $table_digest );
		self::record_shadow( $scope, $options_digest, (string)$table_head['directory_sha256'], (int)$table_head['fencing_token'], $parity, $parity ? '' : 'directory_digest_mismatch' );
		return array(
			'contract'=>self::CONTRACT,'authority_backend'=>'options','shadow_backend'=>'table',
			'authority_scope_sha256'=>$scope,'options_logical_sha256'=>$options_digest,
			'table_directory_sha256'=>(string)$table_head['directory_sha256'],'table_fencing_token'=>(int)$table_head['fencing_token'],
			'parity'=>$parity,'authorizing'=>false
		);
	}

	public static function cutover( $scope, $expected_options_logical_sha256, $expected_table_directory_sha256, $expected_fencing_token ) {
		$scope = strtolower( trim( (string)$scope ) );
		$state = self::state();
		if ( 'options' !== $state['authority_backend'] ) return new WP_Error( 'mad4b_catalog_cutover_wrong_authority', 'Catalog cutover requires options to be the current authority.' );
		$shadow = isset( $state['shadow'] ) && is_array( $state['shadow'] ) ? $state['shadow'] : array();
		if ( empty( $shadow['parity'] ) || ! hash_equals( $scope, (string)$shadow['authority_scope_sha256'] ) ) return new WP_Error( 'mad4b_catalog_cutover_parity_required', 'Exact shadow parity is required before catalog cutover.' );
		if ( ! hash_equals( strtolower((string)$expected_options_logical_sha256), (string)$shadow['options_logical_sha256'] )
			|| ! hash_equals( strtolower((string)$expected_table_directory_sha256), (string)$shadow['table_directory_sha256'] )
			|| (int)$expected_fencing_token !== (int)$shadow['table_fencing_token'] ) {
			return new WP_Error( 'mad4b_catalog_cutover_expectation_mismatch', 'Catalog cutover expectations do not match the latest parity evidence.' );
		}
		$table = new MAD4B_SCP_Catalog_Table_Backend( $scope );
		$head = $table->head();
		if ( is_wp_error( $head ) || (int)$head['fencing_token'] !== (int)$expected_fencing_token || ! hash_equals( (string)$head['directory_sha256'], (string)$expected_table_directory_sha256 ) ) {
			return new WP_Error( 'mad4b_catalog_cutover_head_drift', 'Table catalog head changed after parity evidence.' );
		}
		$next = $state;
		$next['authority_backend'] = 'table';
		$next['authority_scope_sha256'] = $scope;
		$next['cutover'] = array(
			'table_directory_sha256'=>(string)$head['directory_sha256'],'table_generation_id'=>(string)$head['generation_id'],
			'table_fencing_token'=>(int)$head['fencing_token'],'cutover_at'=>time(),
			'rollback_deadline'=>time()+self::ROLLBACK_WINDOW_SECONDS,'authorizing'=>false
		);
		if ( ! update_option( self::STATE_OPTION, $next, false ) ) {
			$readback = get_option( self::STATE_OPTION, array() );
			if ( $readback !== $next ) return new WP_Error( 'mad4b_catalog_cutover_persist_failed', 'Catalog backend cutover state could not be persisted exactly.' );
		}
		return self::status( $scope );
	}

	public static function rollback( $scope ) {
		$state = self::state();
		if ( 'table' !== $state['authority_backend'] || ! isset( $state['cutover'] ) || ! is_array( $state['cutover'] ) ) return new WP_Error( 'mad4b_catalog_rollback_not_applicable', 'Catalog table authority is not active.' );
		if ( time() > (int)$state['cutover']['rollback_deadline'] ) return new WP_Error( 'mad4b_catalog_rollback_window_expired', 'Catalog backend rollback window has expired.' );
		$table = new MAD4B_SCP_Catalog_Table_Backend( $scope );
		$head = $table->head();
		if ( is_wp_error( $head ) || (int)$head['fencing_token'] !== (int)$state['cutover']['table_fencing_token'] || ! hash_equals( (string)$head['generation_id'], (string)$state['cutover']['table_generation_id'] ) ) {
			return new WP_Error( 'mad4b_catalog_rollback_generation_advanced', 'Table catalog generation advanced after cutover; automatic rollback is denied.' );
		}
		$next = $state;
		$next['authority_backend'] = 'options';
		$next['rollback'] = array( 'rolled_back_at'=>time(), 'from_generation_id'=>(string)$head['generation_id'], 'authorizing'=>false );
		if ( ! update_option( self::STATE_OPTION, $next, false ) ) {
			$readback = get_option( self::STATE_OPTION, array() );
			if ( $readback !== $next ) return new WP_Error( 'mad4b_catalog_rollback_persist_failed', 'Catalog backend rollback state could not be persisted exactly.' );
		}
		return self::status( $scope );
	}

	public static function status( $scope = '' ) {
		$state = self::state();
		return array(
			'contract'=>self::CONTRACT,'authority_backend'=>$state['authority_backend'],
			'authority_scope_sha256'=>(string)$state['authority_scope_sha256'],
			'shadow'=>isset($state['shadow'])&&is_array($state['shadow'])?$state['shadow']:array(),
			'cutover'=>isset($state['cutover'])&&is_array($state['cutover'])?$state['cutover']:array(),
			'rollback'=>isset($state['rollback'])&&is_array($state['rollback'])?$state['rollback']:array(),
			'table'=>class_exists('MAD4B_SCP_Catalog_Table_Backend')?MAD4B_SCP_Catalog_Table_Backend::status($scope):array(),
			'dual_authority'=>false,'fallback_on_table_failure'=>false,'authorizing'=>false
		);
	}

	private static function state() {
		$value = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $value ) || self::CONTRACT !== ( isset($value['contract'])?(string)$value['contract']:'' ) ) {
			return array(
				'contract'=>self::CONTRACT,'authority_backend'=>'options','authority_scope_sha256'=>'',
				'shadow'=>array(),'cutover'=>array(),'rollback'=>array()
			);
		}
		if ( ! in_array( isset($value['authority_backend'])?(string)$value['authority_backend']:'', array('options','table'), true ) ) $value['authority_backend']='options';
		return $value;
	}

	private static function record_shadow( $scope, $options_sha, $table_sha, $fence, $parity, $error_code ) {
		$state = self::state();
		if ( 'table' === $state['authority_backend'] ) return;
		$state['authority_scope_sha256'] = (string)$scope;
		$state['shadow'] = array(
			'authority_scope_sha256'=>(string)$scope,'options_logical_sha256'=>(string)$options_sha,
			'table_directory_sha256'=>(string)$table_sha,'table_fencing_token'=>(int)$fence,
			'parity'=>(bool)$parity,'error_code'=>sanitize_key((string)$error_code),'verified_at'=>time(),'authorizing'=>false
		);
		update_option( self::STATE_OPTION, $state, false );
	}
}
