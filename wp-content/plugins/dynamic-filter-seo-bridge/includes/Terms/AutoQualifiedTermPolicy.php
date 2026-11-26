<?php
namespace DynamicFilterSEOBridge\Terms;

use WP_Term;

/**
 * Read-only native-facet/term discovery. Qualification is NOT publication
 * authority: exact combinations, provider observations, language, content
 * parity, Control Plane and Rank Math gates continue to apply.
 */
final class AutoQualifiedTermPolicy {
    private const MIN_CONTENT = 120;
    private const SAMPLE_LIMIT = 240;
    private const MAX_AUTO_TAXONOMIES = 20;

    public static function qualify(WP_Term $term): array {
        $description = trim(wp_strip_all_tags((string)$term->description));
        $content = mb_strlen($description) >= self::MIN_CONTENT;
        $metaKeys = array();
        foreach ((array) get_term_meta((int)$term->term_id) as $key => $values) {
            $key = sanitize_key((string)$key);
            // Infrastructure, workflow and synthetic keys are not SEO evidence.
            if (!$key || $key[0] === '_' || preg_match('/^(icl_|wpml_|_?edit_|order|count|sync|tracking|usage|views)/', $key)) continue;
            if (!preg_match('/(description|excerpt|exerpt|intro|short|seo_title|rank_math|image|media|gallery|faq|benefit|selling|feature|destination_type|location_level|characteristic)/i', $key)) continue;
            foreach ((array)$values as $value) {
                if (!is_scalar($value)) continue;
                $value = trim((string)$value);
                if ($value !== '' && $value !== '0' && $value !== 'false' && $value !== '[]') {
                    $metaKeys[] = $key;
                    // Substantive editorial metadata can itself supply the content.
                    if (preg_match('/(description|excerpt|exerpt|intro|short|faq|benefit|selling|characteristic)/i', $key)
                        && mb_strlen(wp_strip_all_tags($value)) >= self::MIN_CONTENT) $content = true;
                    break;
                }
            }
        }
        $metaKeys = array_values(array_unique($metaKeys));
        return array(
            'qualified' => $content && (bool)$metaKeys,
            'reason' => !$content ? 'editorial_content_insufficient' : (!$metaKeys ? 'editorial_metadata_missing' : 'candidate'),
            'term_id' => (int)$term->term_id,
            'taxonomy' => (string)$term->taxonomy,
            'metadata_keys' => $metaKeys,
            'content_characters' => mb_strlen($description),
            'authorizing' => false,
        );
    }

    public static function expand(array $profiles): array {
        if (!function_exists('shared_presentation_provider_v2')) return $profiles;
        $provider = shared_presentation_provider_v2('tours.archive');
        if (!is_array($provider) || ($provider['provider'] ?? '') !== 'jet-engine/tours_query_archive'
            || ($provider['entity_type'] ?? '') !== 'tours-and-activities') return $profiles;
        $nativeTaxonomies = array_slice((array)($provider['taxonomies'] ?? array()), 0, self::MAX_AUTO_TAXONOMIES);
        foreach ($profiles as $profileId => $profile) {
            if (!is_array($profile) || !in_array('tours-and-activities',(array)($profile['post_types']??array()),true)
                || count((array)($profile['routes']??array())) !== 1) continue;
            $route = reset($profile['routes']);
            if (($route['provider']??'') !== 'jet-engine'
                || (string)($route['provider_query_id']??$route['query_id']??'') !== 'tours_query_archive') continue;
            foreach ($nativeTaxonomies as $taxonomy) {
                $taxonomy = sanitize_key((string)$taxonomy);
                if (!$taxonomy || isset($profile['taxonomy_rules'][$taxonomy])) continue;
                $object = get_taxonomy($taxonomy);
                if (!$object || empty($object->public) || !in_array('tours-and-activities',(array)$object->object_type,true)) continue;
                $ids = get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false,'fields'=>'ids','number'=>self::SAMPLE_LIMIT));
                if (is_wp_error($ids) || !is_array($ids)) continue;
                $eligible = false;
                foreach ($ids as $id) {
                    $term = get_term((int)$id,$taxonomy);
                    if ($term instanceof WP_Term && self::qualify($term)['qualified']) {
                        $eligible = true;
                        break;
                    }
                }
                if (!$eligible) continue;
                $profile['taxonomy_rules'][$taxonomy] = array(
                    'role'=>$taxonomy,'priority'=>100,'gallery_priority'=>100,
                    'index_single'=>true,'auto_qualified'=>true,
                    'min_results'=>max(3,(int)($profile['min_results_by_depth']['1']??3)),
                    'required_meta_key'=>'','required_meta_values'=>array(),
                    'meta_constraint_scope'=>'single','field_map'=>array(),
                );
                $profile['allowed_taxonomy_sets'][] = $taxonomy;
            }
            $profile['allowed_taxonomy_sets'] = array_values(array_unique($profile['allowed_taxonomy_sets']));
            $profiles[$profileId] = $profile;
        }
        return $profiles;
    }
}
