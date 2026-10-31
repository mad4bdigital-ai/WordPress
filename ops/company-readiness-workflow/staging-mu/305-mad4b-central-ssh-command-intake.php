<?php
/**
 * Plugin Name: MAD4B Central Scoped SSH Grant and Command Intake
 * Description: Authenticated central grants and one-time host-command intake; execution requires independently authorized SSH transport.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_SSH_Command_Intake_V1 {
    const CONTRACT = 'mad4b.central-ssh-command-intake.v1';
    const PREFIX = 'mad4b_central_ssh_grant_v1_';
    const CLAIM_PREFIX = 'mad4b_central_ssh_claim_v1_';
    const REQUEST_PREFIX = 'mad4b_central_ssh_request_v1_';
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

    public static function issue(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input)
            || array_diff(array_keys($input),
                array('operation','host_id','host_principal','ttl_seconds','reason')))
            return self::error('invalid_grant_shape', 422);
        $operation = (string) ($input['operation'] ?? '');
        $host = (string) ($input['host_id'] ?? '');
        $host_principal = (string) ($input['host_principal'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        $ttl = (int) ($input['ttl_seconds'] ?? 300);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}$/D', $host)
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $host_principal)
            || $ttl < 30 || $ttl > self::TTL_MAX
            || strlen($reason) < 10 || strlen($reason) > 240)
            return self::error('grant_fields_invalid', 422);
        $scope = self::scope($company, $operation);
        if (is_wp_error($scope)) return $scope;
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
            'issued_epoch' => time(), 'expires_epoch' => time() + $ttl,
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
            || (int) ($grant['expires_epoch'] ?? 0) <= time())
            return self::error('grant_expired_or_mismatched');
        $scope = self::scope($company, (string) $grant['operation']);
        if (is_wp_error($scope)) return $scope;
        foreach (array('site_uuid','environment','canonical_origin','assignment_revision') as $field) {
            if (($scope[$field] ?? null) !== ($grant[$field] ?? null))
                return self::error('delegation_or_site_changed');
        }
        $request_id = bin2hex(random_bytes(16));
        // Unique WordPress option insertion claims a one-time grant atomically.
        if (!add_option(self::CLAIM_PREFIX . $id, $request_id, '', false))
            return self::error('one_time_grant_already_claimed', 409);
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
            // Exact command bytes must come from the authenticated operator
            // session at dispatch. Never store arbitrary shell text in WP options.
            'command_bytes_persisted' => false,
            'created_epoch' => time(), 'expires_epoch' => $grant['expires_epoch'],
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
