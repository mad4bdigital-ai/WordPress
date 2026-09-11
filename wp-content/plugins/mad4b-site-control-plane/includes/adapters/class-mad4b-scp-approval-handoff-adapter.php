<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only handoff from MCP diagnostics to the independent human approval console.
 * This adapter never decides a ticket, exposes a nonce, or invokes the mutation.
 */
final class MAD4B_SCP_Approval_Handoff_Adapter extends MAD4B_SCP_Adapter_Base {
    const CONTRACT = 'mad4b.approval-decision-handoff.v1';

    public function id() { return 'approval-handoff'; }
    public function label() { return 'Human Approval Handoff'; }
    public function is_available() {
        return class_exists( 'MAD4B_SCP_Approval_Tickets' )
  && class_exists( 'MAD4B_SCP_Approval_Decision_Admin' );
    }
    public function ability_names() {
        return array(
  'read' => array( 'mad4b/approval-decision-handoff' ),
  'content' => array(),
  'admin' => array(),
        );
    }
    protected function mutation_requires_certification() { return false; }
    protected function provider_certification( $available ) {
        return array(
  'provider' => 'approval-handoff',
  'label' => 'Human Approval Handoff',
  'status' => $available ? 'read_only_compatible' : 'unavailable',
  'contract_mode' => 'read_only_human_decision_handoff',
  'runtime_contract_ok' => (bool) $available,
  'mutation_certified' => false,
        );
    }
    public static function can_manage( $input = null ) { return current_user_can( 'manage_options' ); }
    public function register_abilities() {
        $this->add_ability(
  'mad4b/approval-decision-handoff',
  'Inspect Human Approval Handoff',
  'handoff',
  array( __CLASS__, 'can_manage' ),
  $this->schema(
      array( 'ticket_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ) ),
      array( 'ticket_id' )
  )
        );
    }
    public function handoff( $input ) {
        $ticket_id = isset( $input['ticket_id'] ) ? strtolower( trim( (string) $input['ticket_id'] ) ) : '';
        if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_approval_handoff_ticket_invalid', 'A valid exact approval ticket id is required.' );
        $ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_id );
        if ( ! is_array( $ticket ) ) return new WP_Error( 'mad4b_approval_handoff_ticket_missing', 'Approval ticket does not exist.' );

        $candidate = MAD4B_SCP_Approval_Decision_Admin::current_candidate();
        $binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
        $payload = isset( $ticket['payload_sha256'] ) ? strtolower( trim( (string) $ticket['payload_sha256'] ) ) : '';
        $blockers = array();
        if ( 'pending' !== ( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) ) $blockers[] = 'ticket_not_pending';
        $expires = ! empty( $ticket['expires_at'] ) ? strtotime( (string) $ticket['expires_at'] . ' UTC' ) : false;
        if ( false === $expires || $expires < time() ) $blockers[] = 'ticket_expired';
        if ( 'mutation' !== ( isset( $ticket['ticket_class'] ) ? sanitize_key( (string) $ticket['ticket_class'] ) : '' ) ) $blockers[] = 'ticket_class_not_human_decidable';
        if ( 'mad4b-write' !== ( isset( $ticket['server_id'] ) ? sanitize_key( (string) $ticket['server_id'] ) : '' ) ) $blockers[] = 'ticket_server_not_human_decidable';
        if ( empty( $candidate['ready'] ) ) $blockers[] = 'current_candidate_not_ready';

        $binding_ok = is_array( $binding )
  && 'mad4b.approval-candidate-binding.v1' === ( isset( $binding['contract'] ) ? (string) $binding['contract'] : '' )
  && ! empty( $binding['ticket_id'] ) && hash_equals( $ticket_id, strtolower( (string) $binding['ticket_id'] ) )
  && ! empty( $binding['payload_sha256'] ) && '' !== $payload && hash_equals( $payload, strtolower( (string) $binding['payload_sha256'] ) )
  && ! empty( $binding['candidate_sha'] ) && ! empty( $candidate['source_commit_sha'] ) && hash_equals( (string) $candidate['source_commit_sha'], (string) $binding['candidate_sha'] )
  && ! empty( $binding['build_fingerprint'] ) && ! empty( $candidate['build_fingerprint'] ) && hash_equals( (string) $candidate['build_fingerprint'], (string) $binding['build_fingerprint'] );
        if ( ! $binding_ok ) $blockers[] = 'candidate_binding_missing_or_stale';

        $ability = isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '';
        $provider = isset( $ticket['provider'] ) ? sanitize_key( (string) $ticket['provider'] ) : '';
        $mounted_provider = '' !== $ability && class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability ) : null;
        if ( null === $mounted_provider ) $blockers[] = 'ticket_target_not_mounted';
        elseif ( '' === $provider || $provider !== sanitize_key( (string) $mounted_provider ) ) $blockers[] = 'ticket_provider_mismatch';
        if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) $blockers[] = 'write_authority_not_ready';
        $blockers = array_values( array_unique( $blockers ) );

        return array(
  'contract' => self::CONTRACT,
  'authorizing' => false,
  'read_only' => true,
  'mutation_exposed' => false,
  'decision_exposed' => false,
  'nonce_exposed' => false,
  'target_execution_exposed' => false,
  'human_action_required' => true,
  'decision_surface' => 'wp_admin_human_only',
  'admin_page_url' => admin_url( 'admin.php?page=' . MAD4B_SCP_Approval_Decision_Admin::PAGE_SLUG ),
  'ticket' => array(
      'ticket_id' => $ticket_id,
      'status' => isset( $ticket['status'] ) ? (string) $ticket['status'] : '',
      'ticket_class' => isset( $ticket['ticket_class'] ) ? (string) $ticket['ticket_class'] : '',
      'server_id' => isset( $ticket['server_id'] ) ? (string) $ticket['server_id'] : '',
      'ability' => $ability,
      'provider' => $provider,
      'payload_sha256' => $payload,
      'target_fingerprint' => isset( $ticket['target_fingerprint'] ) ? (string) $ticket['target_fingerprint'] : '',
      'expires_at' => isset( $ticket['expires_at'] ) ? (string) $ticket['expires_at'] : '',
  ),
  'candidate' => array(
      'ready' => ! empty( $candidate['ready'] ),
      'source_commit_sha' => isset( $candidate['source_commit_sha'] ) ? (string) $candidate['source_commit_sha'] : '',
      'build_fingerprint' => isset( $candidate['build_fingerprint'] ) ? (string) $candidate['build_fingerprint'] : '',
      'environment' => isset( $candidate['environment'] ) ? (string) $candidate['environment'] : '',
      'host' => isset( $candidate['host'] ) ? (string) $candidate['host'] : '',
  ),
  'candidate_binding_exact' => $binding_ok,
  'can_decide_in_admin' => empty( $blockers ),
  'blockers' => $blockers,
        );
    }
}

add_action( 'mad4b_scp_register_adapters', static function ( $registry ) {
    if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new MAD4B_SCP_Approval_Handoff_Adapter() );
} );
