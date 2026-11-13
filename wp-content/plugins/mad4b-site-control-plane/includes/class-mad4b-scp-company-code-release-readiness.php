<?php
/**
 * Read-only Company Code Release discovery and admission gaps.
 *
 * No target filesystem access, broker calls, signatures, grants, or mutation.
 * Presence of the source code or this ability is NOT permission to deploy.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Company_Code_Release_Readiness {
    const CONTRACT = 'mad4b.company-code-release.readiness.v1';
    const ABILITY  = 'mad4b/company-code-release-readiness';
    const DISPATCH = 'mad4b/company-code-release-dispatch';

    public static function boot(): void {
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_ability'), 34);
    }

    public static function register_ability(): void {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;

        wp_register_ability(self::ABILITY, array(
            'label' => 'Company Code Release Readiness',
            'description' => 'Read-only lifecycle admission guidance for a Git-pinned company release. Does not perform or authorize code deployment.',
            'category' => 'mad4b-read',
            'execute_callback' => array(__CLASS__, 'status'),
            'permission_callback' => array('MAD4B_SCP_Policy', 'can_read'),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object', 'additionalProperties' => true),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array('public' => false, 'type' => 'tool', 'surface' => 'read'),
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),
        ));
    }

    public static function status($input = array()): array {
        $invalid = !is_array($input) || count($input) !== 0;
        $registered = function_exists('wp_has_ability') &&
            wp_has_ability(self::DISPATCH);

        // Local WP metadata cannot independently verify original host authority,
        // source git SHA, target file digests, broker signatures or 313 receipts.
        // The external native operator must establish these with exact binding.
        return array(
            'contract' => self::CONTRACT,
            'operation_id' => 'company_code_release',
            'source_of_truth' => 'git_full_commit_sha',
            'dispatch_ability' => self::DISPATCH,
            'dispatch_ability_registered' => (bool) $registered,
            'host_exact_target_verified' => false,
            'broker_grant_verified' => false,
            'production_release_admitted' => false,
            'original_313_receipt_verified' => false,
            'end_to_end_complete' => false,
            'input_valid' => ! $invalid,
            'read_only' => true,
            'mutation_performed' => false,
            'authority_created' => false,
            'next_actions' => $invalid ? array('provide_empty_input_object') : array(
                $registered ? 'verify_native_host_release_binding' :
                    'register_original_broker_dispatch_ability',
                'verify_exact_git_plan_and_target_preimage',
                'obtain_independently_signed_exact_scope_broker_grant',
                'execute_native_rollback_capable_release',
                'verify_original_broker_313_receipt',
            ),
        );
    }
}

MAD4B_SCP_Company_Code_Release_Readiness::boot();
