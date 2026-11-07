<?php
/**
 * Plugin Name: MAD4B Central Scoped SSH Grant and Command Intake
 * Description: Revocable one-time and persistent scoped grants and registered SSH connection references; SSH host authorization remains independent.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_SSH_Command_Intake_V1 {
    const CONTRACT = 'mad4b.central-ssh-command-intake.v1';
    const PREFIX = 'mad4b_central_ssh_grant_v1_';
    const CLAIM_PREFIX = 'mad4b_central_ssh_claim_v1_';
    const REQUEST_PREFIX = 'mad4b_central_ssh_request_v1_';
    const TRANSPORT_PREFIX = 'mad4b_central_ssh_transport_v1_';
    const AUDIT = 'mad4b_central_ssh_intake_audit_v1';
    const TTL_MAX = 900;
    // PERMANENT NATIVE 313 LIFECYCLE: original persistent WP-CLI jobs need
    // enough time for the 5-minute dispatch cadence, a 320-second native
    // worker, independent readback, and bounded retries. This is only the
    // *initial* expiry of NEW 305 requests; it does not renew expired claims,
    // widen one-time grant TTL, or weaken 312/313 signatures/authority checks.
    // Backwards-compatible minimum; no fixed 1200-second admission policy.
    const PERSISTENT_WPCLI_313_JOB_WINDOW = 1200;
    const PERSISTENT_WPCLI_313_JOB_MAX_WINDOW = 3600;
    const COMMAND_MAX_BYTES = 4096;

    /**
     * PERMANENT DYNAMIC 305->313 LIFECYCLE POLICY
     *
     * Compute each NEW persistent WordPress job's time budget from current,
     * independently recorded 306 queue and recent broker-claim latency.
     * There is intentionally no fixed company/host/operation target here.
     *
     * The 60s service interval and 480s service budget are conservative
     * availability defaults, NOT authorizing metadata. The observed backlog
     * and delay are dynamic. This affects new original 305 requests only;
     * 312 signature, ownership, one-time grants, job IDs and 313 independent
     * proof remain unchanged. Old/expired receipts cannot be extended.
     */
    public static function wpcli_lifecycle_window( string $target_host = '' ): array {
        $tick = 60;
        $service_budget = 480;
        $newest_limit = 180;
        $now = time();
        $queued = 0;
        $claimed = 0;
        $recent_waits = array();
        if ( class_exists( 'MAD4B_Central_VPS_Broker_V1', false ) ) {
            global $wpdb;
            // One bounded read from original broker records, no new registry.
            $names = $wpdb->get_col( $wpdb->prepare(
                'SELECT option_name FROM ' . $wpdb->options
                    . ' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d',
                $wpdb->esc_like( MAD4B_Central_VPS_Broker_V1::JOB ) . '%',
                $newest_limit
            ) );
            foreach ( (array) $names as $option_name ) {
                $job = get_option( (string) $option_name, false );
                if ( ! is_array( $job ) || ( $target_host !== ''
                    && (string) ( $job['target_host_id'] ?? '' ) !== $target_host ) ) continue;
                $state = (string) ( $job['state'] ?? '' );
                $expires = (int) ( $job['expires_epoch'] ?? 0 );
                $created = (int) ( $job['created_epoch'] ?? 0 );
                if ( $expires > $now && $state === 'QUEUED_FOR_VPS_BROKER_CLAIM' ) ++$queued;
                if ( $expires > $now && $state === 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY' ) ++$claimed;
                $claim_key = (string) ( $job['job_id'] ?? '' );
                if ( $created <= 0 || $claim_key === '' || strlen( $claim_key ) !== 32 ) continue;
                $claim = get_option( MAD4B_Central_VPS_Broker_V1::CLAIM . $claim_key, false );
                $claim_epoch = is_array( $claim ) ? (int) ( $claim['claimed_epoch'] ?? 0 ) : 0;
                if ( $claim_epoch > $created && $claim_epoch <= $now
                    && $created > $now - DAY_IN_SECONDS ) {
                    $recent_waits[] = min( 900, $claim_epoch - $created );
                }
            }
        }
        sort( $recent_waits, SORT_NUMERIC );
        // P75 observed claim delay, capped to prevent a slow/stale job
        // inflating another company's authorization indefinitely.
        $p75 = count( $recent_waits )
            ? $recent_waits[(int) floor( (count( $recent_waits ) - 1) * 0.75 )] : 0;
        $queue_budget = min( 1500, $queued * $tick + $claimed * $service_budget );
        $suggested = $service_budget + 2 * $tick + max( $tick, $p75 )
            + $queue_budget + 120; // independent readback and bounded retry
        $seconds = max( self::PERSISTENT_WPCLI_313_JOB_WINDOW,
            min( self::PERSISTENT_WPCLI_313_JOB_MAX_WINDOW, $suggested ) );
        return array(
            'contract' => 'mad4b.original-305-native-313-adaptive-window.v1',
            'seconds' => $seconds,
            'queued' => $queued,
            'claimed' => $claimed,
            'claim_wait_p75_seconds' => $p75,
            'observations' => count( $recent_waits ),
            'queue_delay_budget_seconds' => $queue_budget,
            'window_bounded' => true,
            'authorization_changed' => false,
            'existing_jobs_changed' => false
        );
    }

    public static function permitted($request = null): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            || !($request instanceof WP_REST_Request)) return false;
        // A WP-CLI user override is not an authenticated owner confirmation.
        if (defined('WP_CLI') && WP_CLI) return false;
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce !== '') {
            // A supplied invalid REST nonce must fail closed. Only the
            // absence of a nonce permits the original separately verified
            // Unified Administrator identity route.
            return (bool) wp_verify_nonce($nonce, 'wp_rest')
                && get_current_user_id() > 0;
        }
        // No REST-session proof: original Unified Administrator is an
        // alternative identity, not a fallback after failed verification.
        return class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal', false)
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified();
    }

    private static function error(string $code, int $status = 403) {
        return new WP_Error('mad4b_ssh_intake_' . $code,
            'Central SSH intake rejected: ' . $code,
            array('status' => $status, 'contract' => self::CONTRACT));
    }

    private static function audit(string $action, string $company, string $id, string $state): void {
        $rows = get_option(self::AUDIT, array());
        if (!is_array($rows)) $rows = array();
        $rows[] = array(
            'time_utc' => gmdate('c'), 'actor_user_id' => get_current_user_id(),
            'action' => $action, 'company' => $company, 'object_id' => $id,
            'state' => $state
        );
        update_option(self::AUDIT, array_slice($rows, -200), false);
    }

    private static function scope(string $company, string $operation) {
        // The Central VPS is a workload, never a customer company target.
        // The original enrolled WP owner admits only this local host scope;
        // the independent VPS operator must still prove its own authority.
        if ($company === 'tours-central') {
            if (!class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
                || !MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                    $operation,'host','maintenance.shell.text.v1')
                || !class_exists('MAD4B_Central_Host_Scope_V1', false) ||
                !MAD4B_Central_Host_Scope_V1::current_owner())
                return self::error('central_host_owner_or_operation_required');
            $host=MAD4B_Central_Host_Scope_V1::inspect();
            if (($host['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED')
                return self::error('central_host_site_identity_required');
            return array('site_uuid'=>$host['site_uuid'],
                'canonical_origin'=>$host['canonical_origin'],
                'environment'=>$host['environment'],
                'assignment_revision'=>0);
        }
        if (!class_exists('MAD4B_Company_Operation_Admission_V1', false)
            || !class_exists('MAD4B_Manager_Auto_Write_V1', false)
            || !class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            || !MAD4B_Original_Native_Operation_Providers_V1::original_305_operation_registered(
                $operation))
            return self::error('operation_not_registered', 422);
        $admitted = MAD4B_Company_Operation_Admission_V1::assess($company, 'operations.write');
        if (is_wp_error($admitted) || empty($admitted['policy_delegation_admitted']))
            return self::error('active_company_operations_delegation_required');
        $target = MAD4B_Manager_Auto_Write_V1::company_target($company);
        if (($target['state'] ?? '') !== 'REGISTERED'
            || ($target['site_uuid'] ?? '') !== ($admitted['company_site_uuid'] ?? null))
            return self::error('exact_company_site_binding_required');
        $manager = MAD4B_Company_Operation_Admission_V1::manager_id();
        if ($manager === '') return self::error('manager_not_registered');
        $rights = MAD4B_Manager_Auto_Write_V1::entitlement(
            $manager, $company, 'operations.write', get_current_user_id());
        if (empty($rights['assignment_authorized'])) return self::error('assignment_inactive');
        return array('site_uuid' => $target['site_uuid'],
            'canonical_origin' => $target['canonical_origin'],
            'environment' => $target['environment'],
            'assignment_revision' => (int) ($rights['assignment_revision'] ?? 0));
    }


    /**
     * Persist SSH connection configuration, not a live SSH session or secret.
     * The host operator must independently attest the host key and secret
     * reference through its authenticated transport before execution.
     */
    public static function register_transport(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input) || array_diff(array_keys($input),
                array('host_id','host_principal','host_key_fingerprint','credential_ref','reason','capabilities')))
            return self::error('invalid_transport_shape', 422);
        $host = (string) ($input['host_id'] ?? '');
        $principal = (string) ($input['host_principal'] ?? '');
        $fingerprint = (string) ($input['host_key_fingerprint'] ?? '');
        $reference = (string) ($input['credential_ref'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,79}$/D', $host)
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $principal)
            || !preg_match('/^SHA256:[A-Za-z0-9+\\/]{43}=?$/D', $fingerprint)
            || !preg_match('/^vault:[a-z0-9][a-z0-9_\\/-]{5,150}$/D', $reference)
            || strlen($reason) < 10 || strlen($reason) > 240)
            return self::error('invalid_ssh_transport_fields', 422);
        // Full WP-CLI must be explicitly enrolled by the original authenticated
        // company owner; a shell probe, WP-CLI user override or SSH handshake
        // alone cannot create a capability.
        $requested_capabilities = $input['capabilities'] ?? array();
        if (!is_array($requested_capabilities) || array_values($requested_capabilities) !== $requested_capabilities)
            return self::error('invalid_transport_capabilities', 422);
        $full_wpcli = $requested_capabilities === array('wpcli.full');
        if ($requested_capabilities !== array() && !$full_wpcli)
            return self::error('unknown_transport_capability', 422);
        if ($full_wpcli && (!class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal', false)
            || !MAD4B_Company_Manager_Unified_Administrator_Principal::verified()))
            return self::error('verified_owner_enrollment_required');
        if ($company==='tours-central' &&
            ($host!=='srv2030908' || $principal!=='root'
             || $reference!=='vault:registry/local-srv2030908'
             || $requested_capabilities!==array()))
            return self::error('central_host_exact_local_transport_required', 422);
        $scope = self::scope($company, 'maintenance.shell');
        if (is_wp_error($scope)) return $scope;
        $id = bin2hex(random_bytes(16));
        $record = array(
            'contract' => self::CONTRACT, 'transport_id' => $id,
            'mode' => 'persistent_ssh_configuration',
            'company' => $company,
            'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'],
            'assignment_revision' => $scope['assignment_revision'],
            'central_principal_id' => get_current_user_id(),
            'host_id' => $host, 'host_principal' => $principal,
            'host_key_fingerprint' => $fingerprint,
            'credential_ref' => $reference,
            'created_epoch' => time(),
            'state' => 'PENDING_INDEPENDENT_SSH_VERIFICATION',
            // Persist intent in the ORIGINAL transport. Activation still
            // requires signed native 312/313 admission and exact host binding.
            'capabilities' => $requested_capabilities,
            'full_wpcli_pending_native_enrollment' => $full_wpcli,
            'full_wpcli_active' => false,
            'ssh_session_active' => false,
            'host_authority_verified' => false,
            'ssh_secret_stored_in_wordpress' => false,
            'reason' => sanitize_text_field($reason)
        );
        // Owner approves FULL WP-CLI at SSH-transport enrollment once.
        // Persist the original reusable grant, not a parallel grant ledger.
        // This is central enrollment ONLY: independent 312/313 and the
        // registered Native Executor must still prove execution authority.
        $grant_id = $full_wpcli ? bin2hex(random_bytes(16)) : null;
        if ($full_wpcli) $record['full_wpcli_grant_id'] = $grant_id;
        if (!add_option(self::TRANSPORT_PREFIX . $id, $record, '', false))
            return self::error('transport_persistence_failed', 503);
        if ($full_wpcli) {
            $grant = array(
                'contract' => self::CONTRACT, 'grant_id' => $grant_id,
                'company' => $company, 'operation' => 'company.remote.wpcli.full',
                'site_uuid' => $scope['site_uuid'],
                'canonical_origin' => $scope['canonical_origin'],
                'environment' => $scope['environment'],
                'assignment_revision' => $scope['assignment_revision'],
                'central_principal_id' => get_current_user_id(),
                'target_host_id' => $host, 'target_ssh_principal' => $principal,
                'issued_epoch' => time(), 'expires_epoch' => null,
                'grant_mode' => 'persistent', 'transport_id' => $id,
                'requires_fresh_host_auth_for_each_command' => true,
                'state' => 'CENTRAL_OPERATION_SCOPE_GRANTED',
                'host_ssh_authority_verified' => false,
                'execution_authorized' => false, 'executed' => false,
                'reason' => sanitize_text_field($reason),
            );
            if (!add_option(self::PREFIX . $grant_id, $grant, '', false)) {
                delete_option(self::TRANSPORT_PREFIX . $id);
                return self::error('persistent_wpcli_grant_persistence_failed', 503);
            }
            // Do not set full_wpcli_active until the verified native host path
            // is certified; a credential probe cannot upgrade privileges.
            self::audit('full_wpcli_owner_enrolled', $company, $grant_id, 'CENTRAL_OPERATION_SCOPE_GRANTED');
        }
        self::audit('transport_registered', $company, $id, $record['state']);
        return new WP_REST_Response($record, 201);
    }

    /**
     * Revoking either a permanent grant or its connection registration
     * prevents new command claims. Host transport verifies the revocation
     * state again before dispatch, not solely when the command was accepted.
     */
    public static function revoke(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $kind = (string) ($request->get_param('kind') ?: '');
        $id = (string) ($request['object_id'] ?? '');
        if (!in_array($kind, array('grant','transport'), true)
            || !preg_match('/^[a-f0-9]{32}$/D', $id))
            return self::error('invalid_revoke_scope', 422);
        $prefix = $kind === 'grant' ? self::PREFIX : self::TRANSPORT_PREFIX;
        $record = get_option($prefix . $id, null);
        $native_owner_revoke=is_array($record)
            && ($record['principal_type']??'')==='vps_native'
            && $company==='tours-central'
            && class_exists('MAD4B_Central_Host_Scope_V1',false)
            && MAD4B_Central_Host_Scope_V1::current_owner();
        if (!is_array($record) || ($record['company'] ?? '') !== $company
            || (!$native_owner_revoke
                && ($record['central_principal_id'] ?? null) !== get_current_user_id()))
            return self::error('record_not_found', 404);
        if (($record['state'] ?? '') === 'REVOKED')
            return rest_ensure_response(array('state'=>'REVOKED','object_id'=>$id));
        // Revocation always remains available to the issuing enrolled owner,
        // even after the delegated company authority was suspended or removed.
        // A revoked assignment must not trap a persistent host grant as active.
        $record['state'] = 'REVOKED';
        $record['revoked_epoch'] = time();
        $record['revoked_by'] = get_current_user_id();
        if (!update_option($prefix . $id, $record, false))
            return self::error('revoke_persistence_failed', 503);
        self::audit($kind . '_revoked', $company, $id, 'REVOKED');
        return rest_ensure_response(array('state'=>'REVOKED','object_id'=>$id));
    }

    public static function issue(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input)
            || array_diff(array_keys($input),
                array('operation','host_id','host_principal','ttl_seconds','reason','grant_mode','transport_id')))
            return self::error('invalid_grant_shape', 422);
        $operation = (string) ($input['operation'] ?? '');
        // A reusable Full WP-CLI grant can ONLY originate at one owner-verified
        // connection enrollment, never via the ordinary generic grant endpoint.
        $profile=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            ? MAD4B_Original_Native_Operation_Providers_V1::profile($operation) : null;
        if (is_array($profile) && $profile['authority_family']==='original_305'
            && $profile['target_scope']==='wordpress')
            return self::error('use_owner_verified_wpcli_transport_enrollment', 403);

        $host = (string) ($input['host_id'] ?? '');
        $host_principal = (string) ($input['host_principal'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        $mode = (string) ($input['grant_mode'] ?? 'one_time');
        $transport_id = (string) ($input['transport_id'] ?? '');
        $ttl = (int) ($input['ttl_seconds'] ?? 300);
        if (!in_array($mode, array('one_time','persistent'), true))
            return self::error('grant_mode_invalid', 422);
        if ($mode === 'persistent') {
            if (isset($input['ttl_seconds']) && $input['ttl_seconds'] !== null)
                return self::error('persistent_grant_does_not_expire_by_ttl', 422);
            if (!preg_match('/^[a-f0-9]{32}$/D', $transport_id))
                return self::error('persistent_transport_reference_required', 422);
            $transport = get_option(self::TRANSPORT_PREFIX . $transport_id, null);
            if (!is_array($transport)
                || ($transport['state'] ?? '') !== 'PENDING_INDEPENDENT_SSH_VERIFICATION')
                return self::error('persistent_transport_missing_or_revoked');
        } else {
            $transport = null;
            if ($transport_id !== '') return self::error('one_time_transport_reference_unsupported', 422);
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}$/D', $host)
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $host_principal)
            || ($mode === 'one_time' && ($ttl < 30 || $ttl > self::TTL_MAX))
            || strlen($reason) < 10 || strlen($reason) > 240)
            return self::error('grant_fields_invalid', 422);
        if ($company==='tours-central' &&
            (!MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                 $operation,'host','maintenance.shell.text.v1') || $mode!=='persistent'
             || $host!=='srv2030908' || $host_principal!=='root'
             || $transport_id===''))
            return self::error('central_host_persistent_local_grant_required', 422);
        // The native-principal standing grant can only be created through
        // a valid Ed25519 VPS Client attestation. The owner-facing 305 API
        // may revoke it but may not mint a second grant on its transport.
        if ($mode==='persistent' && ($transport['principal_type']??'')==='vps_native')
            return self::error('native_grant_requires_signed_client',403);
        $scope = self::scope($company, $operation);
        if (is_wp_error($scope)) return $scope;
        if ($mode === 'persistent' && (
            ($transport['company'] ?? '') !== $company
            || ($transport['host_id'] ?? '') !== $host
            || ($transport['host_principal'] ?? '') !== $host_principal
            || ($transport['central_principal_id'] ?? null) !== get_current_user_id()
            || ($transport['site_uuid'] ?? null) !== $scope['site_uuid']
            || ($transport['canonical_origin'] ?? null) !== $scope['canonical_origin']
            || ($transport['environment'] ?? null) !== $scope['environment']
            || ($transport['assignment_revision'] ?? null) !== $scope['assignment_revision']))
            return self::error('persistent_transport_scope_mismatch');
        // This is a central policy scope; host-level privilege is NOT minted.
        // The SSH transport must independently verify host principal/grants.
        $grant_id = bin2hex(random_bytes(16));
        $record = array(
            'contract' => self::CONTRACT, 'grant_id' => $grant_id,
            'company' => $company, 'operation' => $operation,
            'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'],
            'assignment_revision' => $scope['assignment_revision'],
            'central_principal_id' => get_current_user_id(),
            'target_host_id' => $host, 'target_ssh_principal' => $host_principal,
            'issued_epoch' => time(), 'expires_epoch' => $mode === 'persistent' ? null : time() + $ttl,
            'grant_mode' => $mode, 'transport_id' => $mode === 'persistent' ? $transport_id : null,
            'requires_fresh_host_auth_for_each_command' => true,
            'state' => 'CENTRAL_OPERATION_SCOPE_GRANTED',
            'host_ssh_authority_verified' => false,
            'execution_authorized' => false, 'executed' => false,
            'reason' => sanitize_text_field($reason)
        );
        if (!add_option(self::PREFIX . $grant_id, $record, '', false))
            return self::error('grant_persistence_failed', 503);
        self::audit('grant_issued', $company, $grant_id, $record['state']);
        return new WP_REST_Response($record, 201);
    }

    public static function accept_command(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input) || array_diff(array_keys($input),
                array('grant_id','command','argv','verification','summary','correlation_id','target_scope')))
            return self::error('invalid_request_shape', 422);
        $id = (string) ($input['grant_id'] ?? '');
        $command = $input['command'] ?? null;
        $argv = $input['argv'] ?? null;
        $summary = (string) ($input['summary'] ?? '');
        $correlation = (string) ($input['correlation_id'] ?? '');
        $target_scope = (string) ($input['target_scope'] ?? 'wordpress');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)
            || strlen($summary) < 10 || strlen($summary) > 240
            || !preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $correlation)
            || !in_array($target_scope, array('wordpress','host'), true))
            return self::error('command_fields_invalid', 422);
        $grant = get_option(self::PREFIX . $id, null);
        if (is_array($grant) && ($grant['principal_type']??'')==='vps_native')
            return self::error('native_command_requires_signed_client',403);
        if (!is_array($grant) || ($grant['contract'] ?? '') !== self::CONTRACT
            || ($grant['company'] ?? '') !== $company
            || ($grant['central_principal_id'] ?? 0) !== get_current_user_id()
            || ($grant['state'] ?? '') !== 'CENTRAL_OPERATION_SCOPE_GRANTED'
            || (($grant['grant_mode'] ?? 'one_time') !== 'persistent'
                && (int) ($grant['expires_epoch'] ?? 0) <= time()))
            return self::error('grant_expired_or_mismatched');
        // WP-CLI uses the same canonical digest as the signed native argv
        // envelope; maintenance.shell preserves its legacy text digest.
        $profile=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            ? MAD4B_Original_Native_Operation_Providers_V1::profile(
                (string)($grant['operation']??'')) : null;
        if (!is_array($profile) || $profile['authority_family']!=='original_305')
            return self::error('source_registered_operation_provider_required',422);
        $wpcli=$profile['target_scope']==='wordpress';
        if ($wpcli) {
            if ($command !== null || $target_scope !== 'wordpress'
                || !is_array($argv) || array_values($argv) !== $argv
                || count($argv) < 1 || count($argv) > 256)
                return self::error('wpcli_argv_shape_invalid', 422);
            foreach ($argv as $part) {
                if (!is_string($part) || strlen($part) > 8192
                    || preg_match('/[\x00\x0a\x0d]/', $part)
                    || preg_match('/^--(?:path|url|ssh|http|user)(?:=|$)/i', $part))
                    return self::error('wpcli_argv_target_override_or_invalid', 422);
            }
            $canonical = wp_json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonical) || strlen($canonical) > self::COMMAND_MAX_BYTES)
                return self::error('wpcli_argv_bytes_invalid', 422);
            $command_digest = hash('sha256', $canonical);
            $command_byte_length = strlen($canonical);
            $command_format = 'wpcli.argv.json.v1';
            if (array_key_exists('verification',$input)
                && (!class_exists('MAD4B_Original_WPCLI_Sealed_Command_V2',false)
                    || !MAD4B_Original_WPCLI_Sealed_Command_V2::witness_valid($input['verification'])))
                return self::error('original_signed_wpcli_verification_invalid',422);
        } else {
            if ($profile['target_scope']!=='host' || $target_scope!=='host')
                return self::error('exact_host_operation_scope_required',422);
            if ($argv !== null || array_key_exists('verification',$input)
                || !is_string($command) || trim($command) === ''
                || strlen($command) > self::COMMAND_MAX_BYTES
                || strpos($command, "\0") !== false)
                return self::error('command_text_invalid', 422);
            $command_digest = hash('sha256', $command);
            $command_byte_length = strlen($command);
            $command_format = 'maintenance.shell.text.v1';
        }
        if ($company==='tours-central' &&
            ($target_scope!=='host' ||
             ($grant['target_host_id']??'')!=='srv2030908' ||
             ($grant['target_ssh_principal']??'')!=='root'))
            return self::error('central_host_command_target_scope_invalid', 422);
        $scope = self::scope($company, (string) $grant['operation']);
        if (is_wp_error($scope)) return $scope;
        foreach (array('site_uuid','environment','canonical_origin','assignment_revision') as $field) {
            if (($scope[$field] ?? null) !== ($grant[$field] ?? null))
                return self::error('delegation_or_site_changed');
        }
        $mode = (string) ($grant['grant_mode'] ?? 'one_time');
        $transport_id = (string) ($grant['transport_id'] ?? '');
        if ($mode === 'persistent') {
            $transport = get_option(self::TRANSPORT_PREFIX . $transport_id, null);
            if (!is_array($transport)
                || !class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
                || !MAD4B_Original_Verified_WPCLI_Transport_V1::permitted($transport,$grant)
                || ($transport['company'] ?? null) !== $company
                || ($transport['site_uuid'] ?? null) !== $scope['site_uuid']
                || ($transport['canonical_origin'] ?? null) !== $scope['canonical_origin']
                || ($transport['environment'] ?? null) !== $scope['environment']
                || ($transport['assignment_revision'] ?? null) !== $scope['assignment_revision']
                || ($transport['host_id'] ?? null) !== $grant['target_host_id']
                || ($transport['host_principal'] ?? null) !== $grant['target_ssh_principal'])
                return self::error('persistent_transport_revoked_or_changed');
        } elseif ($mode !== 'one_time') return self::error('grant_mode_unknown');
        $request_id = bin2hex(random_bytes(16));
        // Persistent grants are reusable, but each correlation is single use.
        // For one-time grants preserve the original one-time consumption.
        $claim_key = self::CLAIM_PREFIX . $id;
        if ($mode === 'persistent')
            $claim_key .= '_' . hash('sha256', $correlation);
        if (!add_option($claim_key, $request_id, '', false))
            return self::error($mode === 'persistent'
                ? 'correlation_already_claimed' : 'one_time_grant_already_claimed', 409);
        $receipt = array(
            'contract' => self::CONTRACT, 'request_id' => $request_id,
            'grant_id' => $id, 'company' => $company,
            'operation' => $grant['operation'], 'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'], 'central_principal_id' => get_current_user_id(),
            'target_host_id' => $grant['target_host_id'],
            'target_ssh_principal' => $grant['target_ssh_principal'],
            'target_scope' => $target_scope, 'summary' => sanitize_text_field($summary),
            'correlation_id' => $correlation,
            'command_sha256' => $command_digest,
            'command_format' => $command_format,
            'command_byte_length' => $command_byte_length,
            'grant_mode' => $mode,
            'transport_id' => $mode === 'persistent' ? $transport_id : null,
            // Exact command bytes must come from the authenticated operator
            // session at dispatch. Never store arbitrary shell text in WP options.
            'command_bytes_persisted' => false,
            'created_epoch' => time(), 'expires_epoch' => $mode === 'persistent'
                ? time() + ($wpcli
                    ? self::wpcli_lifecycle_window( (string) $grant['target_host_id'] )['seconds']
                    : 300)
                : $grant['expires_epoch'],
            'status' => 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
            'host_ssh_grant_verified' => false, 'transport_registered' => false,
            'execution_performed' => false
        );
        if ($wpcli) {
            if (!class_exists('MAD4B_Original_WPCLI_Sealed_Command_V2',false))
                return self::error('original_sealed_wpcli_module_unavailable',503);
            $sealed=MAD4B_Original_WPCLI_Sealed_Command_V2::seal(
                $receipt,$argv,$input['verification']??null);
            if (is_wp_error($sealed)) return $sealed;
            if (!is_string($sealed)||!preg_match('/^[a-f0-9]{64}$/D',$sealed))
                return self::error('original_wpcli_argv_seal_unknown',503);
            $receipt['sealed_argv_sha256']=$sealed;
            $receipt['sealed_argv_transport']='host_bound_x25519_original_305';
        }
        if (!add_option(self::REQUEST_PREFIX . $request_id, $receipt, '', false)) {
            self::audit('request_persistence_failed', $company, $request_id, 'FAILED');
            return self::error('request_persistence_failed', 503);
        }
        self::audit('command_intake', $company, $request_id, $receipt['status']);
        return new WP_REST_Response($receipt, 202);
    }

    public static function inspect(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $id = (string) $request['request_id'];
        if (!preg_match('/^[a-f0-9]{32}$/D', $id))
            return self::error('invalid_request_id', 422);
        $row = get_option(self::REQUEST_PREFIX . $id, null);
        if (!is_array($row) || ($row['company'] ?? '') !== (string) $request['company']
            || ($row['central_principal_id'] ?? 0) !== get_current_user_id())
            return self::error('request_not_found', 404);
        return rest_ensure_response($row);
    }

    // Original 305 intake owns the rebind; it does not invent an actor or grant.
    public static function rebindOriginalSealedCommandOnce(string $id,array $input,array $proof) {
        return MAD4B_Original_Sealed_Command_Rebind_V1::execute($id,$input,$proof);
    }

    public static function routes(): void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central()) return;
        $base = '/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})';
        register_rest_route('mad4b/v1', $base . '/ssh-transports', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'register_transport')
        ));
        register_rest_route('mad4b/v1', $base . '/(?P<kind>grant|transport)/(?P<object_id>[a-f0-9]{32})/revoke', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'revoke')
        ));
        register_rest_route('mad4b/v1', $base . '/grants', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'issue')
        ));
        register_rest_route('mad4b/v1', $base . '/commands', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'accept_command')
        ));
        register_rest_route('mad4b/v1', $base . '/commands/(?P<request_id>[a-f0-9]{32})', array(
            'methods' => 'GET', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'inspect')
        ));
    }
    public static function boot(): void {
        add_action('rest_api_init', array(__CLASS__, 'routes'), 90);
    }
}
MAD4B_Central_SSH_Command_Intake_V1::boot();
// The verified 377-entry MU manifest loads 305-mad4b-original-sealed-rebind.php at its
// ordered position. Do not require a forward module while the router is
// iterating; otherwise public HTML needlessly depends on central commands.
// Outside the modular router, preserve the historical fail-closed requirement.
if (!defined('MAD4B_MU_MODULAR_LOADER_BOOTED')) {
    require_once MAD4B_MU_File_Resolver_V1::path('305-mad4b-original-sealed-rebind.php');
}
