<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Reviewed inert adapter for guarded reversible failure CI only. No abilities registered. */
final class MAD4B_CI_Failure_Evidence_Adapter extends MAD4B_SCP_Adapter_Base {
	const OPTION = 'mad4b_ci_failure_evidence_state';
	public function id() { return 'ci-failure-evidence'; }
	public function label() { return 'CI Failure Evidence'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array(), 'content' => array( 'ci/failure-evidence-write' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'ci/failure-evidence-write' => 'mad4b.rollback.ci-failure-evidence.v1' ); }
	public function register_abilities() {}
	protected function mutation_requires_certification() { return false; }
	protected function detect_plugin_version() { return '1.0.0-ci'; }
	public function mutate_then_fail( $input ) {
		update_option( self::OPTION, array( 'value' => 'after-side-effect' ), false );
		return new WP_Error( 'mad4b_ci_provider_failed_after_write', 'CI provider intentionally fails after changing state.' );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		$state = get_option( self::OPTION, array( 'value' => 'missing' ) );
		return array( 'target_type' => 'ci-option', 'target_id' => self::OPTION, 'target' => array( 'option' => self::OPTION ), 'state' => is_array( $state ) ? $state : array( 'value' => (string) $state ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		$state = get_option( self::OPTION, array( 'value' => 'missing' ) );
		return is_array( $state ) ? $state : array( 'value' => (string) $state );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		update_option( self::OPTION, $state, false );
		return true;
	}
}
