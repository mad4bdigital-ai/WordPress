<?php
/**
 * Fail-closed, READ-ONLY field-source preview for company provisioning.
 * Not registered as a WordPress hook or an authorization to apply mutations.
 * Uses only the versioned key/classification policy; never reads or exports
 * customer settings, media attachments, source option values or secrets.
 */
final class MAD4B_WoVacation_Company_Clone_Gate {
    public const CONTRACT = 'mad4b.wo-vacation-demo.company-clone-source-preview.v1';
    public const POLICY_PAGES_SHA256 = '872ea2c4c02a2919c15367057669ae53aeba7cd3ba602b34442f38530958d49a';
    public const SOURCE_CENSUS_SHA256 = 'b6c79f53443d599c6d9b57c94fbb4a7d1e656d445f3e91877150376450938a0e';
    public const SOURCE_SITE = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    public const SOURCE_HOST = 'tours.dev.mad4b.com';
    private static function blocked($reason) {
        return [
            'contract' => self::CONTRACT, 'status' => 'blocked',
            'reason' => $reason, 'decisions' => [],
            'can_apply' => false, 'records_copied' => 0, 'mutation_performed' => false
        ];
    }
    private static function uuid($value) {
        return is_string($value) &&
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value) === 1;
    }
    public static function plan(array $policy, array $context, array $selected) {
        if (($policy['contract'] ?? '') !== 'mad4b.company-overlay.field-source-governance.v1' ||
            ($policy['jetengine_options_contract']['policy'] ?? '') !== 'default_deny_unclassified' ||
            !is_array($policy['jetengine_options_contract']['pages'] ?? null)) {
            return self::blocked('untrusted_policy_contract');
        }
        $pages = $policy['jetengine_options_contract']['pages'];
        $pagesDigest = hash('sha256', json_encode($pages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!hash_equals(self::POLICY_PAGES_SHA256, $pagesDigest)) {
            return self::blocked('field_classification_policy_drift');
        }
        $sourceDigest = $policy['jetengine_options_contract']['source_sha256'] ?? '';
        if (!is_string($sourceDigest) || preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1 ||
            !hash_equals(self::SOURCE_CENSUS_SHA256, $sourceDigest) ||
            ($context['source_snapshot_sha256'] ?? '') !== $sourceDigest) {
            return self::blocked('source_snapshot_mismatch');
        }
        if (($context['mode'] ?? '') !== 'dry_run' ||
            ($context['source_site_uuid'] ?? '') !== self::SOURCE_SITE ||
            ($context['source_host'] ?? '') !== self::SOURCE_HOST ||
            ($context['source_environment'] ?? '') !== 'staging' ||
            ($context['target_environment'] ?? '') !== 'staging' ||
            !self::uuid($context['target_site_uuid'] ?? null) ||
            $context['target_site_uuid'] === self::SOURCE_SITE) {
            return self::blocked('cross_site_or_environment_binding_invalid');
        }
        if (!$selected || count($selected) > 150) {
            return self::blocked('invalid_field_count');
        }
        $map = [
            'SHARED_DEFINITION' => 'definition_key_only',
            'TENANT_FIELD' => 'new_company_value_required',
            'TENANT_MEDIA' => 'new_company_licensed_media_required',
            'RECONNECT_PROVIDER' => 'new_provider_connection_required'
        ];
        $seen = [];
        $decisions = [];
        foreach ($selected as $item) {
            // Deliberately forbid the caller from passing any source option values.
            if (!is_array($item) || array_keys($item) !== ['page', 'field'] ||
                !is_string($item['page']) || !is_string($item['field'])) {
                return self::blocked('source_value_or_unrecognized_argument_denied');
            }
            $key = $item['page'].':'.$item['field'];
            if (isset($seen[$key])) return self::blocked('duplicate_field');
            $seen[$key] = true;
            $pagePolicy = $policy['jetengine_options_contract']['pages'][$item['page']] ?? null;
            if (!is_array($pagePolicy) || !is_array($pagePolicy['field_policy'] ?? null) ||
                (int)($pagePolicy['field_count'] ?? -1) !== count($pagePolicy['field_policy'])) {
                return self::blocked('unknown_or_incomplete_options_page');
            }
            $classification = $pagePolicy['field_policy'][$item['field']] ?? 'BLOCK_REVIEW';
            if (!array_key_exists($classification, $map)) {
                return self::blocked('unknown_or_unapproved_field');
            }
            $decisions[] = [
                'page' => $item['page'], 'field' => $item['field'],
                'classification' => $classification, 'decision' => $map[$classification],
                'source_values_copied' => false, 'requires_new_company_review' => $classification !== 'SHARED_DEFINITION'
            ];
        }
        return [
            'contract' => self::CONTRACT, 'status' => 'requires_company_provisioning',
            'source_snapshot_sha256' => $sourceDigest, 'target_site_uuid' => $context['target_site_uuid'],
            'decisions' => $decisions, 'can_apply' => false,
            'records_copied' => 0, 'mutation_performed' => false,
            'ready_to_publish' => false
        ];
    }
}
