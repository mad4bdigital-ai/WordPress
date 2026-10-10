<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Signed external events wake an exact workflow, confer no site authority and send no messages. */
final class MAD4B_SCP_CSO_Triggers {
    const CONTRACT = 'mad4b.cso01.trigger.v1';
    const PURPOSE = 'mad4b.cso01.trigger';
    const EVENT = 'mad4b.cso01.signed-event.v1';
    public static function plan( $source_ability, array $sealed_workflow ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) ) return MAD4B_SCP_CSO_Changes::error( 'triggers_disabled' );
        $workflow = MAD4B_SCP_CSO_Scope::unseal( $sealed_workflow, MAD4B_SCP_CSO_Workflows::PURPOSE ); if ( is_wp_error( $workflow ) ) return $workflow;
        if ( ( $workflow['contract'] ?? '' ) !== MAD4B_SCP_CSO_Workflows::CONTRACT || ( $workflow['expires_at'] ?? 0 ) <= MAD4B_SCP_CSO_Changes::now() ) return MAD4B_SCP_CSO_Changes::error( 'trigger_workflow_expired' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $workflow['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $source = self::source( $source_ability ); if ( is_wp_error( $source ) ) return $source;
        $p = array( 'contract' => self::CONTRACT, 'operation_id' => wp_generate_uuid4(), 'source_ability' => $source_ability, 'source' => $source,
            'workflow' => $sealed_workflow, 'workflow_sha256' => $workflow['plan_sha256'], 'scope' => $workflow['scope'], 'expires_at' => $workflow['expires_at'], 'authorizing' => false, 'mutation_performed' => false );
        $p['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $p ); if ( is_wp_error( $p['plan_sha256'] ) ) return $p['plan_sha256'];
        $seal = MAD4B_SCP_CSO_Scope::seal( $p, self::PURPOSE ); if ( is_wp_error( $seal ) ) return $seal;
        return array( 'state' => 'PLANNED', 'sealed_trigger' => $seal, 'operation_id' => $p['operation_id'], 'signer_id' => $source['signer_id'], 'key_id' => $source['key_id'],
            'audience' => $workflow['scope']['site_uuid'], 'workflow_sha256' => $workflow['plan_sha256'], 'scope_sha256' => $workflow['scope']['binding_sha256'],
            'authorizing' => false, 'mutation_performed' => false, 'external_listener_installed' => false );
    }
    public static function accept( array $sealed_trigger, array $event, array $governance = array() ) {
        $p = MAD4B_SCP_CSO_Scope::unseal( $sealed_trigger, self::PURPOSE ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['expires_at'] ?? 0 ) <= MAD4B_SCP_CSO_Changes::now() ) return MAD4B_SCP_CSO_Changes::error( 'trigger_expired' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $source = self::source( $p['source_ability'] ); if ( is_wp_error( $source ) ) return $source;
        if ( ! MAD4B_SCP_CSO_Changes::same( $source, $p['source'] ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_signer_drift' );
        $fields = array( 'contract', 'issuer', 'key_id', 'audience', 'scope_sha256', 'workflow_sha256', 'event_id', 'nonce', 'sequence', 'issued_at', 'expires_at', 'signature' );
        if ( array_diff( array_keys( $event ), $fields ) || array_diff( $fields, array_keys( $event ) ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_event_shape' );
        $now = MAD4B_SCP_CSO_Changes::now();
        if ( $event['contract'] !== self::EVENT || $event['issuer'] !== $source['signer_id'] || $event['key_id'] !== $source['key_id'] || $event['audience'] !== $p['scope']['site_uuid']
            || $event['scope_sha256'] !== $p['scope']['binding_sha256'] || $event['workflow_sha256'] !== $p['workflow_sha256'] || ! is_string( $event['event_id'] ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $event['event_id'] )
            || ! is_string( $event['nonce'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $event['nonce'] ) || ! is_int( $event['sequence'] ) || $event['sequence'] < 1 || $event['sequence'] > 500
            || ! is_int( $event['issued_at'] ) || ! is_int( $event['expires_at'] ) || $event['issued_at'] > $now || $event['issued_at'] < $now - $source['max_age_seconds']
            || $event['expires_at'] <= $now || $event['expires_at'] > $p['expires_at'] || $event['expires_at'] - $event['issued_at'] > $source['max_age_seconds'] || ! is_string( $event['signature'] ) || strlen( $event['signature'] ) > 2048 ) return MAD4B_SCP_CSO_Changes::error( 'trigger_identity_or_time' );
        $unsigned = $event; unset( $unsigned['signature'] ); $sha = MAD4B_SCP_CSO_Scope::digest( $unsigned ); $sig = base64_decode( $event['signature'], true );
        if ( ! is_string( $sha ) || ! is_string( $sig ) || base64_encode( $sig ) !== $event['signature'] || ! function_exists( 'openssl_verify' ) || 1 !== openssl_verify( $sha, $sig, $source['public_key_pem'], OPENSSL_ALGO_SHA256 ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_signature_denied' );
        if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) || ! class_exists( 'MAD4B_SCP_Abuse_Budget' ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_durable_budget_required' );
        $budget = MAD4B_SCP_Abuse_Budget::admit( 'execute', array( 'ability_name' => $p['source_ability'], 'operation_id' => $p['operation_id'] ) ); if ( is_wp_error( $budget ) ) return $budget;
        $j = MAD4B_SCP_CSO_Bulk::start( $p ); if ( is_wp_error( $j ) ) return $j;
        if ( ! in_array( $j['state'], array( 'PLANNED', 'RUNNING' ), true ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_reconcile_required' );
        $last_sequence = 0; foreach ( $j['events'] as $record ) if ( isset( $record['safe_metadata']['checkpoint'] ) ) $last_sequence = $record['safe_metadata']['checkpoint'];
        if ( $event['sequence'] !== $last_sequence + 1 ) return MAD4B_SCP_CSO_Changes::error( 'trigger_order_or_duplicate' );
        $scope_key = hash_hmac( 'sha256', serialize( array( 'cso_event', $p['scope']['site_uuid'], $source['signer_id'], $source['key_id'] ) ), wp_salt( 'auth' ) );
        $claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, 'event:' . $event['nonce'], $sha, 3600 ); if ( is_wp_error( $claim ) ) return $claim;
        if ( ! empty( $claim['replayed'] ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_nonce_replay_denied' );
        $workflow = MAD4B_SCP_CSO_Scope::unseal( $p['workflow'], MAD4B_SCP_CSO_Workflows::PURPOSE ); if ( is_wp_error( $workflow ) ) return $workflow;
        $inbox = MAD4B_SCP_Durable_Execution::accept_inbox( $source['provider_id'], $event['event_id'], $workflow['operation_id'], $sha, $source['signer_id'] );
        if ( is_wp_error( $inbox ) || ! empty( $inbox['duplicate'] ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_inbox_reconcile_required' );
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, $j['state'], 'RUNNING', array( 'checkpoint' => $event['sequence'] ) ); if ( is_wp_error( $saved ) ) return $saved;
        try { $result = MAD4B_SCP_CSO_Workflows::run( $p['workflow'], $governance ); } catch ( Throwable $e ) { $result = MAD4B_SCP_CSO_Changes::error( 'trigger_workflow_unknown' ); }
        $state = is_wp_error( $result ) ? 'UNCERTAIN' : ( $result['state'] ?? 'UNCERTAIN' );
        if ( 'UNCERTAIN' === $state ) { MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'UNCERTAIN', array( 'reason_code' => 'trigger_workflow_reconcile_required' ) ); return array( 'state' => 'UNCERTAIN', 'accepted' => true, 'authorizing' => false, 'blind_retry_allowed' => false, 'external_message_sent' => false ); }
        $receipt = array( 'state' => $state, 'accepted' => true, 'workflow_operation_id' => $workflow['operation_id'], 'sequence' => $event['sequence'], 'authorizing' => false, 'signature_is_authority' => false, 'blind_retry_allowed' => false, 'external_message_sent' => false );
        $closed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $receipt ); return is_wp_error( $closed ) ? $closed : $receipt;
    }
    /** Only current registered mounted provider metadata supplies a public verification key. */
    private static function source( $name ) {
        $d = MAD4B_SCP_CSO_Registry::describe( $name ); if ( is_wp_error( $d ) ) return $d;
        if ( true !== $d['read_only'] || 'read' !== $d['lane'] || empty( $d['provider']['certification_generation_sha256'] ) || ( $d['certification_status'] ?? '' ) !== 'existing_governed_mount' || ( $d['provider']['source'] ?? '' ) !== 'existing_capability_descriptor' ) return MAD4B_SCP_CSO_Changes::error( 'trigger_source_uncertified' );
        $meta = wp_get_ability( $name )->get_meta(); $s = $meta['mad4b_cso']['event_source'] ?? null;
        if ( ! is_array( $s ) || ! is_string( $s['signer_id'] ?? null ) || ! preg_match( '/^[a-z0-9._:-]{1,128}$/D', $s['signer_id'] ) || ! is_string( $s['key_id'] ?? null ) || ! preg_match( '/^[a-z0-9._-]{1,64}$/D', $s['key_id'] )
            || ! is_int( $s['max_age_seconds'] ?? null ) || $s['max_age_seconds'] < 10 || $s['max_age_seconds'] > 300 || ! is_string( $s['public_key_pem'] ?? null ) || strlen( $s['public_key_pem'] ) > 8192 || ! function_exists( 'openssl_pkey_get_public' ) ) return MAD4B_SCP_CSO_Changes::error( 'trigger_signer_contract_required' );
        $key = openssl_pkey_get_public( $s['public_key_pem'] ); $detail = false !== $key ? openssl_pkey_get_details( $key ) : false;
        if ( ! is_array( $detail ) || OPENSSL_KEYTYPE_RSA !== $detail['type'] || $detail['bits'] < 2048 ) return MAD4B_SCP_CSO_Changes::error( 'trigger_key_invalid' );
        return array( 'provider_id' => $d['provider']['provider_id'], 'signer_id' => $s['signer_id'], 'key_id' => $s['key_id'], 'public_key_pem' => $s['public_key_pem'], 'max_age_seconds' => $s['max_age_seconds'], 'schema_sha256' => $d['schema_sha256'], 'adapter_sha256' => $d['adapter_sha256'], 'capability_binding' => $d['capability_binding'] );
    }
}
