<?php
defined('ABSPATH') || exit;

/**
 * Read-only, fail-closed contract preflight. No grants, tokens, fixture
 * creation, profile change, provider mutation, or permission elevation.
 */
final class MAD4B_SCP_ChatbotX_Write_Preflight {
    const CONTRACT = 'mad4b.chatbotx-write-preflight.v1';
    const ACTIONS = array(
        'mad4b/chatbotx-support-create-ticket' => 'support_ticket',
        'mad4b/chatbotx-support-add-note' => 'support_internal_note',
        'mad4b/chatbotx-cpt-create-draft' => 'unpublished_cpt_draft',
    );

    public static function assess($input) {
        $input = is_array($input) ? $input : array();
        $ability = isset($input['ability']) ? (string)$input['ability'] : '';
        if (!isset(self::ACTIONS[$ability])) {
            return new WP_Error('cmb_write_preflight_invalid_ability', 'Only an explicitly registered ChatbotX write ability can be assessed.');
        }
        $profile = class_exists('MAD4B_SCP_Site_Profile') ? MAD4B_SCP_Site_Profile::status() : array();
        $environment = isset($profile['environment']) ? sanitize_key((string)$profile['environment']) : 'unknown';
        $gate = class_exists('MAD4B_SCP_Policy') ? MAD4B_SCP_Policy::mutation_gate_status() : array();
        $profile_write = class_exists('MAD4B_SCP_Site_Profile') && MAD4B_SCP_Site_Profile::write_enabled();
        $profile_bound = class_exists('MAD4B_SCP_Site_Profile') && MAD4B_SCP_Site_Profile::origin_enrolled();
        $provider_key = 'chatbotx-provider';
        $artifact = class_exists('MAD4B_SCP_Provider_Contracts')
            && is_array(MAD4B_SCP_Provider_Contracts::get($provider_key));
        $catalog = class_exists('MAD4B_SCP_Provider_Compatibility_Certification')
            && MAD4B_SCP_Provider_Compatibility_Certification::supports_provider($provider_key);
        $admin = current_user_can('manage_options');

        $blockers = array();
        if (!$admin) $blockers[] = 'actor_admin_permission_required';
        if (!$profile_bound) $blockers[] = 'exact_origin_enrollment_required';
        if (!in_array($environment, array('staging','production'), true)) {
            $blockers[] = 'enroll_exact_staging_or_production_origin_before_write_enable';
        }
        if (!$profile_write) $blockers[] = 'site_profile_write_feature_disabled';
        if (empty($gate['effective'])) $blockers[] = 'central_mutation_authority_not_effective';
        if (!$artifact || !$catalog) $blockers[] = 'provider_write_certification_missing';
        if (!class_exists('MAD4B_SCP_Audit') || empty(MAD4B_SCP_Audit::storage_status()['ready'])) {
            $blockers[] = 'audit_storage_not_ready';
        }

        $context = array('complete' => false, 'requirements' => array());
        if ($ability === 'mad4b/chatbotx-support-create-ticket') {
            $customer = absint($input['customer_id'] ?? 0);
            $mailbox = absint($input['mailbox_id'] ?? 0);
            $customer_cls = 'FluentSupport\\App\\Models\\Customer';
            $mailbox_cls = 'FluentSupport\\App\\Models\\MailBox';
            $helper = 'FluentSupport\\App\\Services\\Helper';
            $agent = class_exists($helper) && (bool)$helper::getAgentByUserId();
            $customer_ok = $customer > 0 && class_exists($customer_cls) && (bool)$customer_cls::find($customer);
            $mailbox_ok = $mailbox > 0 && class_exists($mailbox_cls) && (bool)$mailbox_cls::find($mailbox);
            if (!$agent) $blockers[] = 'fluent_support_agent_not_configured';
            if (!$customer_ok) $blockers[] = 'existing_support_customer_binding_required';
            if (!$mailbox_ok) $blockers[] = 'existing_support_mailbox_binding_required';
            $context = array('complete' => $agent && $customer_ok && $mailbox_ok,
                'requirements' => array('validated_existing_customer', 'validated_mailbox', 'native_support_agent', 'bounded_title_and_content_at_execution'));
        } elseif ($ability === 'mad4b/chatbotx-support-add-note') {
            $ticket = absint($input['ticket_id'] ?? 0);
            $cls = 'FluentSupport\\App\\Models\\Ticket';
            $found = $ticket > 0 && class_exists($cls) && (bool)$cls::find($ticket);
            if (!$found) $blockers[] = 'existing_ticket_binding_required';
            $context = array('complete' => $found, 'requirements' => array(
                'native_agent_access_checked_at_execution','bounded_internal_note_at_execution'));
        } else {
            $type = sanitize_key((string)($input['post_type'] ?? ''));
            $allowed = in_array($type, array('tours-and-activities','properties','transportations'), true);
            $post_obj = $allowed ? get_post_type_object($type) : null;
            $can_create = $post_obj && isset($post_obj->cap->create_posts) && current_user_can($post_obj->cap->create_posts);
            if (!$can_create) $blockers[] = 'exact_cpt_create_permission_required';
            $context = array('complete' => (bool)$can_create, 'requirements' => array(
                'supported_cpt','per_post_type_create_capability','bounded_title_at_execution'));
        }

        return array(
            'contract' => self::CONTRACT,
            'ability' => $ability,
            'operation' => self::ACTIONS[$ability],
            'environment' => $environment,
            'read_only' => true,
            'site_write_enabled' => (bool)$profile_write,
            'central_write_effective' => !empty($gate['effective']),
            'provider_certification_present' => (bool)($artifact && $catalog),
            'context' => $context,
            'blockers' => array_values(array_unique($blockers)),
            'potentially_eligible_for_next_authorization_step' => empty($blockers),
            'approved_for_execution' => false,
            'execution_requires' => array(
                'verified_mcp_actor_and_NHI','exact_ability_grant',
                'runtime_provider_certification','fresh_policy_evaluation',
                'risk_appropriate_approval','idempotency_key','append_only_audit'
            ),
            'next_step' => !in_array($environment, array('staging','production'), true)
                ? 'Establish a real exact-origin governed Staging enrollment; never relabel Local or bypass Site Profile rules.'
                : 'Complete site-profile authority and exact per-ability provider/NHI certification, then retry through the governed MCP executor.',
            'no_mutation_performed' => true,
        );
    }
}
