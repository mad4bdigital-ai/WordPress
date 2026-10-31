<?php
defined('ABSPATH') || exit;

/**
 * Company responsibility for third-party content and media.
 * This is an internal distribution policy, not a license grant or a finding
 * that reuse is lawful. Security, QA, source permissions and release gates
 * remain separate. No reviewer signature is required by default.
 */
final class MAD4B_Hub_Usage_Rights {
    const CONTRACT = 'mad4b.hub.usage-rights.v2';
    const POLICIES = 'mad4b_hub_company_usage_policies_v2';

    public static function company_policy($company) {
        $rules = get_option(self::POLICIES, array());
        $stored = is_array($rules) ? ($rules[$company] ?? array()) : array();
        $stored = is_array($stored) ? $stored : array();
        return array(
            'responsibility_mode' => 'company',
            'allow_external_content' => !array_key_exists('allow_external_content', $stored)
                || $stored['allow_external_content'] === true,
            'allow_external_media' => !array_key_exists('allow_external_media', $stored)
                || $stored['allow_external_media'] === true,
            'require_verified_license' => !empty($stored['require_verified_license']),
        );
    }

    public static function assess($source, $draft, $purpose, $override = array()) {
        if (!is_array($source) || !is_array($draft)) {
            return self::blocked('evidence_missing');
        }
        if (!in_array($purpose, array('research', 'reference', 'display', 'publish', 'distribution'), true)) {
            return self::blocked('purpose_unknown');
        }
        $company = sanitize_key((string)($source['consumer_company_id']
            ?? $source['company_id'] ?? $draft['responsible_company_id'] ?? ''));
        $origin = sanitize_key((string)($source['origin'] ?? 'unknown'));
        $external = !in_array($origin, array('owned_original', 'licensed_content'), true);
        $external_media = !empty($draft['external_media']);
        $policy = self::company_policy($company);
        if (is_array($override)) {
            // Callers may make policy stricter but cannot override stored denial.
            foreach (array('allow_external_content','allow_external_media','require_verified_license') as $field) {
                if (array_key_exists($field,$override)) {
                    $value = $override[$field] === true;
                    $policy[$field] = $field === 'require_verified_license'
                        ? ($policy[$field] || $value) : ($policy[$field] && $value);
                }
            }
        }
        $metadata = array(
            'responsible_company_id'=>$company,
            'source_origin'=>$origin,
            'external_content'=>$external,
            'external_media'=>$external_media,
            'rights_handling'=>'company_responsibility',
            'license_state'=>'not_verified',
            'legal_reuse_permission'=>'not_determined_by_system',
        );
        $license = sanitize_text_field((string)($source['license_id'] ?? ''));
        $licenses = get_option('mad4b_hub_usage_licenses_v1', array());
        $registered = is_array($licenses) && $license !== ''
            ? ($licenses[$license] ?? null) : null;
        if (is_array($registered) && ($registered['company_id'] ?? '') === $company
            && ($registered['status'] ?? '') === 'active'
            && !empty($registered['commercial_redistribution_allowed'])) {
            $metadata['license_state'] = 'registered_verified';
        }

        if ($purpose === 'research' || $purpose === 'reference') {
            return self::allow('source_reference_available',$metadata);
        }
        // Public display, publication and company distribution share the
        // same normal QA/policy route. Unverified license metadata is not a
        // global veto for any of these three actions.
        $metadata['requested_purpose'] = $purpose;
        if ($company === '') return self::blocked('responsible_company_missing',$metadata);
        if (($draft['quality_state'] ?? '') !== 'passed' || !empty($draft['hard_blockers'])) {
            return self::blocked('content_qa_not_passed',$metadata);
        }
        if ($external && !$policy['allow_external_content']) {
            return self::blocked('company_policy_external_content_denied',$metadata);
        }
        if ($external_media && !$policy['allow_external_media']) {
            return self::blocked('company_policy_external_media_denied',$metadata);
        }
        if ($policy['require_verified_license'] && $metadata['license_state'] !== 'registered_verified') {
            return self::blocked('company_policy_verified_license_required',$metadata);
        }
        // The company's acceptance is the system's policy selection, not a
        // substitute for copyright permissions or a representation of a license.
        return self::allow('distribution_under_company_responsibility',$metadata);
    }

    private static function allow($reason,$metadata) {
        return array('contract'=>self::CONTRACT,'allowed'=>true,'reason'=>$reason,
            'rights_metadata'=>$metadata,'independent_reviewer_required'=>false,
            'publication_performed'=>false);
    }
    private static function blocked($reason,$metadata=array()) {
        return array('contract'=>self::CONTRACT,'allowed'=>false,'reason'=>$reason,
            'rights_metadata'=>$metadata,'publication_performed'=>false);
    }
}
