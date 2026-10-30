<?php
defined('ABSPATH') || exit;

/**
 * Source rights never follow merely from a successful scrape or connection.
 * This policy is deterministic and reviewer-neutral; the normal QA workflow
 * may use the same author as reviewer. No release or publication occurs here.
 */
final class MAD4B_Hub_Usage_Rights {
    const CONTRACT = 'mad4b.hub.usage-rights.v1';

    public static function assess($source, $draft, $purpose) {
        if (!is_array($source) || !is_array($draft)) {
            return self::blocked('evidence_missing');
        }
        if (!in_array($purpose, array('research', 'reference', 'distribution'), true)) {
            return self::blocked('purpose_unknown');
        }
        $origin = (string) ($source['origin'] ?? 'unknown');
        $company = sanitize_key((string) ($source['company_id'] ?? ''));
        $is_original = ($draft['kind'] ?? '') === 'original_article'
            && ($draft['lineage_verified'] ?? false) === true;
        $is_clear = ($draft['quality_state'] ?? '') === 'passed'
            && empty($draft['hard_blockers']);
        $has_foreign_media = !empty($draft['external_media']);
        $rights_holder = (string) ($draft['rights_holder'] ?? '');

        if ($purpose === 'research') {
            return self::allow('research_only');
        }
        if ($origin === 'external_reference') {
            // A scraped source can be cited but not copied or republished.
            return $purpose === 'reference'
                ? self::allow('attributed_reference_only')
                : self::blocked('external_copy_rights_unverified');
        }
        if ($origin === 'signed_company_reference') {
            $verified = $company !== ''
                && class_exists('MAD4B_Central_Hub_Remote_Control_V1')
                && MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible($company);
            if (!$verified) return self::blocked('signed_source_not_verified');
            return $purpose === 'reference'
                ? self::allow('signed_reference_only')
                : self::blocked('source_connection_does_not_grant_publication');
        }
        if ($origin !== 'owned_original' && $origin !== 'licensed_content') {
            return self::blocked('rights_source_unknown');
        }
        if ($purpose === 'reference') return self::allow('internal_owned_reference');
        if (!$is_original || !$is_clear) return self::blocked('original_draft_or_qa_missing');
        if ($has_foreign_media) return self::blocked('embedded_media_license_missing');

        if ($origin === 'owned_original') {
            if ($company === '' || $rights_holder !== $company) {
                return self::blocked('rights_holder_mismatch');
            }
            return self::allow('owned_original_distribution');
        }
        // A license must come from the managed rights registry, not a
        // caller-controlled "licensed":true flag.
        $license = sanitize_text_field((string) ($source['license_id'] ?? ''));
        $licenses = get_option('mad4b_hub_usage_licenses_v1', array());
        $row = is_array($licenses) ? ($licenses[$license] ?? null) : null;
        if (!is_array($row) || ($row['company_id'] ?? '') !== $company
            || ($row['status'] ?? '') !== 'active'
            || empty($row['commercial_redistribution_allowed'])) {
            return self::blocked('managed_license_not_verified');
        }
        return self::allow('licensed_original_distribution');
    }

    private static function allow($reason) {
        return array('contract' => self::CONTRACT, 'allowed' => true,
            'reason' => $reason, 'independent_reviewer_required' => false,
            'publication_performed' => false);
    }

    private static function blocked($reason) {
        return array('contract' => self::CONTRACT, 'allowed' => false,
            'reason' => $reason, 'publication_performed' => false);
    }
}
