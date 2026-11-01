<?php
/**
 * Plugin Name: MAD4B Central Dual-Lane Execution Registry
 * Description: Read-only registration and eligibility projection for independent semantic Executor and VPS SSH administration lanes.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_Dual_Lane_Registry_V1 {
    const CONTRACT = 'mad4b.central-dual-lane-registry.v1';
    const SEMANTIC = array(
        'identity.inspect', 'environment.inspect', 'capability.inspect',
        'provider.inspect', 'content.read', 'content.write',
        'strategy.write', 'operations.write', 'commerce.write'
    );
    const HOST_MAINTENANCE = array(
        'maintenance.one_time_apply', 'maintenance.raw_sql', 'maintenance.shell'
    );
    const TECHNICAL_TIERS = array('wordpress', 'container', 'vps_ssh');

    public static function central(): bool {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && defined('DB_NAME') && DB_NAME === 'etg_commerce_staging'
            && untrailingslashit(home_url('/')) === 'https://tours.dev.mad4b.com';
    }

    public static function allowed(): bool {
        if (!self::central() || !is_user_logged_in()
            || get_current_user_id() < 1
            || !current_user_can('manage_options')) return false;
        if (!class_exists('MAD4B_SCP_Site_Profile', false)) return false;
        return MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }

    public static function policy(): array {
        return array(
            'contract' => self::CONTRACT,
            'central_origin' => 'https://tours.dev.mad4b.com',
            'native_lanes' => array(
                'registered_executor' => array(
                    'purpose' => 'bounded_semantic_operation',
                    'registered_operation_required' => true,
                    'exact_site_identity_required' => true,
                    'host_shell_from_queue_allowed' => false
                ),
                'vps_ssh' => array(
                    'purpose' => 'general_host_administration',
                    'per_command_ability_required' => false,
                    'independent_ssh_host_authority_required' => true,
                    'command_digest_and_journal_required' => true
                )
            ),
            'technical_tiers' => self::TECHNICAL_TIERS,
            'company_staging_required' => false,

            'environment_capability_model' => 'production_inherits_staging_with_exact_operation_authority',
            'production_environment_blanket_blocked' => false,
            'production_read_and_diagnostic_capabilities_available' => true,
            'production_write_capabilities_available_when_authorized' => true,
            'production_host_mutations_require' => array(
                'enrolled_host_identity','exact_company_environment_scope',
                'independent_ssh_principal','operation_backup_and_rollback',
                'independent_execution_readback'
            ),

            'one_time_maintenance_supported_as_policy_intent' => true,
            'failure_switch_requires_independent_transport_authority' => true,
            'security_denial_switch_allowed' => false,
            'source_registry_only' => false,
            'vps_broker_heartbeat_verified' => (static function () {
                $hb = get_option('mad4b_central_broker_heartbeat_v1', array());
                return is_array($hb) && !empty($hb['verified'])
                    && (int)($hb['at'] ?? 0) > time()-600;
            })(),
            'vps_broker_host_id' => (string)get_option('mad4b_central_broker_host_id_v1',''),
            'vps_broker_mode' => 'existing_dispatch_v1_systemd_claim_bridge',
            'live_ssh_adapter_registered' => false,
            'mutating_ssh_executor_activated' => false,
            'live_executor_registered_by_this_component' => false,
            'execution_performed' => false
        );
    }

    public static function classify(string $company, string $operation,
        string $mode = 'auto', string $failed_tier = '', ?string $failure = null) {
        if (!self::allowed()) {
            return new WP_Error('mad4b_dual_lane_owner_required',
                'Enrolled central administrator required', array('status' => 403));
        }
        if (!in_array($mode, array('auto', 'registered_executor', 'vps_ssh'), true)
            || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $company)) {
            return new WP_Error('mad4b_dual_lane_invalid_scope',
                'Invalid operation routing scope', array('status' => 422));
        }
        $semantic = in_array($operation, self::SEMANTIC, true);
        $host = in_array($operation, self::HOST_MAINTENANCE, true);
        if (!$semantic && !$host) {
            return new WP_Error('mad4b_dual_lane_unknown_operation',
                'Unknown operation', array('status' => 422));
        }
        if (($semantic && $mode === 'vps_ssh')
            || ($host && $mode === 'registered_executor')) {
            return new WP_Error('mad4b_dual_lane_kind_mismatch',
                'A semantic Executor job cannot be relabeled as general SSH',
                array('status' => 403));
        }
        if (!class_exists('MAD4B_Company_Recovery_Ladder_V1', false)) {
            return new WP_Error('mad4b_dual_lane_recovery_unavailable',
                'Central recovery planner not installed', array('status' => 503));
        }
        $assessment = MAD4B_Company_Recovery_Ladder_V1::plan(
            $company, $operation, $failed_tier, $failure);
        if (is_wp_error($assessment)) return $assessment;
        $denied = ($assessment['state'] ?? '') === 'STOP_AUTHORIZATION_OR_SECURITY_DENIAL';
        $lane = $host ? 'vps_ssh' : 'registered_executor';
        return array(
            'contract' => self::CONTRACT,
            'company_code' => $company,
            'operation' => $operation,
            'lane' => $lane,
            'technical_tier' => $assessment['next_stage'] ?? null,
            'environment' => $assessment['environment'] ?? null,
            'canonical_origin' => $assessment['canonical_origin'] ?? null,
            'site_uuid' => $assessment['site_uuid'] ?? null,
            'state' => $denied ? 'STOP_ORIGINAL_SECURITY_DENIAL'
                : (($assessment['state'] ?? '') === 'NO_FURTHER_TRANSPORT_TIER'
                    ? 'NO_FURTHER_TIER' : 'ADMITTED_PENDING_INDEPENDENT_TRANSPORT'),
            'original_denial_preserved' => $denied,
            'manager_delegation_evaluated' => true,
            'independent_ssh_authority_required' => $host,
            'signed_executor_ticket_required' => $semantic,
            'no_secondary_company_approval_if_already_delegated' => true,
            'live_transport_registered' => false,
            'ready_to_dispatch' => false,
            'operation_performed' => false,
            'requirements' => $assessment['requirements'] ?? array()
        );
    }

    public static function register_routes(): void {
        if (!self::central()) return;
        register_rest_route('mad4b/v1', '/central-execution-lanes', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'allowed'),
            'callback' => static function () { return rest_ensure_response(self::policy()); }
        ));
        register_rest_route('mad4b/v1',
            '/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})',
            array(
                'methods' => 'GET',
                'permission_callback' => array(__CLASS__, 'allowed'),
                'callback' => static function (WP_REST_Request $request) {
                    $value = self::classify((string) $request['company'],
                        (string) $request->get_param('operation'),
                        (string) ($request->get_param('mode') ?: 'auto'),
                        (string) ($request->get_param('failed_tier') ?: ''),
                        $request->get_param('failure') ?: null);
                    return is_wp_error($value) ? $value : rest_ensure_response($value);
                }
            )
        );
    }
    public static function boot(): void {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 80);
    }
}
MAD4B_Central_Dual_Lane_Registry_V1::boot();
