<?php
namespace DynamicFilterSEOBridge\IndexFlow;

/** Resolves output ownership; never globally disables either SEO provider. */
final class SeoOwnershipResolver {
    public function inspect(array $route, array $observed): array {
        $reasons = array();
        $managed = 'published' === ($route['status'] ?? '') && !empty($route['identity']);
        if (!$managed) return array('managed' => false, 'owner' => 'native', 'ready' => false, 'authorizing' => false, 'reasons' => array('unmanaged_route'));
        $owner = $route['seo_owner'] ?? '';
        // Reuse the common ETG ownership reducer introduced on this same branch;
        // add only managed-route scope, active-hook and hreflang requirements.
        $evidence = $observed;
        $evidence['jsf_seo_rule'] = true === ($observed['jsf_rule_matched'] ?? null) ? 'matched'
            : (false === ($observed['jsf_rule_matched'] ?? null) ? 'unmatched' : 'unknown');
        $evidence['rank_math_frontend'] = true === ($observed['rank_math_disabled'] ?? null) ? 'disabled'
            : (false === ($observed['rank_math_disabled'] ?? null) ? 'enabled' : 'unknown');
        $base = (new \DynamicFilterSEOBridge\SEO\OutputOwnershipResolver())->resolve(
            array('route_id' => $route['id'] ?? '', 'active' => true, 'in_scope' => true, 'index_eligible' => true), $evidence);
        $reasons = $base['blocking_reasons'];
        if ('rank_math' === $owner) {
            if (($observed['rank_math_available'] ?? false) !== true) $reasons[] = 'rank_math_unavailable';
            // A matched JSF rule may attach __return_true here after init-rule.
            if (($observed['rank_math_disabled'] ?? true) !== false) $reasons[] = 'rank_math_frontend_disabled';
            if (($observed['rank_math_hooks_active'] ?? false) !== true) $reasons[] = 'rank_math_output_hooks_missing';
            if (($observed['jsf_rule_matched'] ?? null) !== false) $reasons[] = 'jsf_seo_rule_conflict_or_unknown';
        } elseif ('jet_smart_filters' === $owner) {
            if (($observed['jsf_rule_matched'] ?? false) !== true || ($observed['jsf_hooks_active'] ?? false) !== true) $reasons[] = 'jsf_seo_output_unavailable';
            if (($observed['rank_math_disabled'] ?? false) !== true) $reasons[] = 'competing_seo_output';
        } else $reasons[] = 'unknown_seo_owner';
        foreach (array('canonical', 'robots', 'sitemap', 'hreflang') as $surface) {
            if (($observed[$surface . '_owner'] ?? '') !== $owner) $reasons[] = $surface . '_ownership_unproven';
        }
        return array('managed' => true, 'owner' => $owner, 'ready' => !$reasons, 'authorizing' => false, 'reasons' => $reasons);
    }
}
