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
    const COMMAND_MAX_BYTES = 4096;

    public static function permitted($request = null): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            || !($request instanceof WP_REST_Request)) return false;
        // A WP-CLI user override is not an authenticated owner confirmation.
        if (defined('WP_CLI') && WP_CLI) return false;
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce !== '' && wp_verify_nonce($nonce, 'wp_rest')) {
            return get_current_user_id() > 0;
        }
        // Only the enrolled OAuth bearer with step-up, never a synthetic user.
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
        if (!class_exists('MAD4B_Company_Operation_Admission_V1', false)
            || !class_exists('MAD4B_Manager_Auto_Write_V1', false)
            || !in_array($operation, MAD4B_Central_Dual_Lane_Registry_V1::HOST_MAINTENANCE, true))
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
                array('host_id','host_principal','host_key_fingerprint','credential_ref','reason')))
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
            'ssh_session_active' => false,
            'host_authority_verified' => false,
            'ssh_secret_stored_in_wordpress' => false,
            'reason' => sanitize_text_field($reason)
        );
        if (!add_option(self::TRANSPORT_PREFIX . $id, $record, '', false))
            return self::error('transport_persistence_failed', 503);
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
        if (!is_array($record) || ($record['company'] ?? '') !== $company
            || ($record['central_principal_id'] ?? null) !== get_current_user_id())
            return self::error('record_not_found', 404);
        if (($record['state'] ?? '') === 'REVOKED')
            return rest_ensure_response(array('state'=>'REVOKED','object_id'=>$id));
        $scope = self::scope($company,
            $kind === 'grant' ? (string) ($record['operation'] ?? '') : 'maintenance.shell');
        if (is_wp_error($scope)) return $scope;
        foreach (array('site_uuid','environment','canonical_origin','assignment_revision') as $field)
            if (($scope[$field] ?? null) !== ($record[$field] ?? null))
                return self::error('scope_mismatch');
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
                array('grant_id','command','summary','correlation_id','target_scope')))
            return self::error('invalid_request_shape', 422);
        $id = (string) ($input['grant_id'] ?? '');
        $command = $input['command'] ?? null;
        $summary = (string) ($input['summary'] ?? '');
        $correlation = (string) ($input['correlation_id'] ?? '');
        $target_scope = (string) ($input['target_scope'] ?? 'wordpress');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)
            || !is_string($command) || trim($command) === ''
            || strlen($command) > self::COMMAND_MAX_BYTES
            || strpos($command, "\0") !== false
            || strlen($summary) < 10 || strlen($summary) > 240
            || !preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $correlation)
            || !in_array($target_scope, array('wordpress','host'), true))
            return self::error('command_fields_invalid', 422);
        $grant = get_option(self::PREFIX . $id, null);
        if (!is_array($grant) || ($grant['contract'] ?? '') !== self::CONTRACT
            || ($grant['company'] ?? '') !== $company
            || ($grant['central_principal_id'] ?? 0) !== get_current_user_id()
            || ($grant['state'] ?? '') !== 'CENTRAL_OPERATION_SCOPE_GRANTED'
            || (($grant['grant_mode'] ?? 'one_time') !== 'persistent'
                && (int) ($grant['expires_epoch'] ?? 0) <= time()))
            return self::error('grant_expired_or_mismatched');
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
                || ($transport['state'] ?? '') !== 'PENDING_INDEPENDENT_SSH_VERIFICATION'
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
            'command_sha256' => hash('sha256', $command),
            'command_byte_length' => strlen($command),
            'grant_mode' => $mode,
            'transport_id' => $mode === 'persistent' ? $transport_id : null,
            // Exact command bytes must come from the authenticated operator
            // session at dispatch. Never store arbitrary shell text in WP options.
            'command_bytes_persisted' => false,
            'created_epoch' => time(), 'expires_epoch' => $mode === 'persistent'
                ? time() + 300 : $grant['expires_epoch'],
            'status' => 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
            'host_ssh_grant_verified' => false, 'transport_registered' => false,
            'execution_performed' => false
        );
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
