<?php
/**
 * Plugin Name: MAD4B Authority Reviews Classifier
 * Description: Read-only, source-backed classification of governed Write Authority review entries.
 * Version: 0.1.0
 * Author: MAD4B
 * Text Domain: mad4b-authority-reviews-classifier
 */
defined('ABSPATH') || exit;

/**
 * Adds review-only labels without changing the Control Plane, its manifest,
 * identity bindings, approval tickets, capability policies, or grant storage.
 */
final class MAD4B_Authority_Reviews_Classifier {
    const CONTRACT = 'mad4b.authority-reviews-classifier.v1';
    const SOURCE = 'MAD4B_SCP_Staging_Write_Grant_Reconciliation';

    public static function boot() {
        if (!function_exists('add_filter')) return;
        add_filter('option_mad4b_authority_reconciliation_reviews_v1',
            array(__CLASS__, 'classify'), 25, 1);
    }

    public static function classify($entries) {
        if (!is_array($entries)
            || !class_exists('MAD4B_SCP_Site_Profile')
            || !class_exists(self::SOURCE)
            || 'staging' !== MAD4B_SCP_Site_Profile::current_environment()
            || !MAD4B_SCP_Site_Profile::configured()
            || !MAD4B_SCP_Site_Profile::origin_enrolled()
            || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()) return $entries;

        $site = (string) MAD4B_SCP_Site_Profile::site_uuid();
        $allowlist = MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers();
        if (!is_array($allowlist)) return $entries;

        foreach ($entries as $key => $entry) {
            if (!is_array($entry)
                || 'mad4b.authority-review-intake.v1' !== ($entry['contract'] ?? '')
                || $site !== ($entry['site_uuid'] ?? '')
                || 'exact_grant' !== ($entry['kind'] ?? '')
                || !empty($entry['resolved'])
                || !empty($entry['authorization_granted'])
                || !empty($entry['automatic_grant_issued'])) continue;

            $subject = (string) ($entry['subject'] ?? '');
            $split = strrpos($subject, '@');
            if (false === $split || $split < 1 || $split === strlen($subject)-1) continue;
            $ability = substr($subject, 0, $split);
            $provider = substr($subject, $split + 1);
            if (!preg_match('/^[a-z0-9_\/.-]{2,191}$/D', $ability)
                || !preg_match('/^[a-z0-9_-]{1,80}$/D', $provider)) continue;

            $reviewed = isset($allowlist[$ability])
                && hash_equals((string) $allowlist[$ability], $provider);
            $entries[$key]['source_reviewed'] = $reviewed;
            $entries[$key]['classifier_contract'] = self::CONTRACT;
            $entries[$key]['mutation_performed'] = false;
            if (!$reviewed) {
                $entries[$key]['state'] = 'SOURCE_POLICY_REVIEW_REQUIRED';
                $entries[$key]['reason'] = 'capability_provider_outside_reviewed_source_policy';
            } elseif ('SOURCE_POLICY_REVIEW_REQUIRED' === ($entry['state'] ?? '')) {
                // Policy may have been expanded by a separate, reviewed source
                // update. That does not itself approve the requested grant.
                $entries[$key]['state'] = 'REVIEW_REQUIRED';
                $entries[$key]['reason'] = 'native_grant_approval_not_yet_verified';
            }
        }
        return $entries;
    }
}
add_action('plugins_loaded', array('MAD4B_Authority_Reviews_Classifier', 'boot'), 20);
