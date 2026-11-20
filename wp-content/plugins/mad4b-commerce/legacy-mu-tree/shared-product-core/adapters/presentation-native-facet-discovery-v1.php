<?php
/**
 * MU Presentation — discover JetSmartFilters' native Elementor consumers.
 *
 * Only filters actually mounted in published Elementor layouts for the
 * registered JetEngine archive query are admitted. Saved JetSmartFilters
 * taxonomy metadata, not PHP source-code taxonomy names, owns each facet.
 * Does not mutate native widgets, JetEngine queries, terms or sellable rates.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)
    && (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED)) return;

function shared_presentation_native_facet_discovery_v1(array $provider): array {
    static $building = false;
    // Bootstrap/early init cannot reliably enumerate native taxonomies.
    // Never persist an empty discovery from an incomplete registry.
    if (!did_action('init') || doing_action('init')) return [];
    // Fail closed while scanning native metadata; prevent recursive registry
    // calls from third-party filters examining Elementor and JSF objects.
    if ($building) return [];
    $slug = (string)($provider['provider'] ?? '');
    if (!preg_match('~^jet-engine/([a-z0-9_-]{2,80})$~D', $slug, $m)
        || ($provider['entity_type']??'') !== 'tours-and-activities'
        || (int)($provider['query_id']??0) <= 0) return [];
    $key = 'shared_presentation_jsf_native_tour_facets_v1';
    $cached = get_transient($key);
    if (is_array($cached) && (int)($cached['discovery_version']??0)===3
        && ($cached['provider']??null)===$slug
        && ($cached['query_id']??null)===(int)$provider['query_id']
        && (!empty($cached['facets']) || empty($cached['source_template_ids'])))
        return $cached;

    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm
              ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
         WHERE p.post_type = 'elementor_library' AND p.post_status = 'publish'
           AND pm.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 251",
        '%'.$wpdb->esc_like($m[1]).'%'
    ));
    if (!is_array($rows) || count($rows)>250) return [];

    $building = true;
    // Only native filters reachable from an actual published Elementor
    // archive using this Query Builder may become Tours archive facets.
    // A Home Hero/search widget can share the query but is not an archive
    // filter. Follow saved Elementor template references and exact WPML
    // translations of those referenced templates.
    $archiveRows = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
           JOIN {$wpdb->postmeta} kind ON kind.post_id=p.ID
             AND kind.meta_key='_elementor_template_type'
             AND kind.meta_value='archive'
           JOIN {$wpdb->postmeta} payload ON payload.post_id=p.ID
             AND payload.meta_key='_elementor_data'
         WHERE p.post_type='elementor_library' AND p.post_status='publish'
           AND payload.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 151",
        '%'.$wpdb->esc_like($m[1]).'%'
    ));
    if(!is_array($archiveRows) || count($archiveRows)>150){
        $building=false;return [];
    }
    $allowedTemplates=[];
    $archiveSourceIds=[];
    foreach($archiveRows as $archiveId){
        $archiveId=(int)$archiveId;
        $nodes=json_decode((string)get_post_meta($archiveId,'_elementor_data',true),true);
        if(!is_array($nodes))continue;
        $nativeQueryMounted=false;
        $linked=[];
        $walkArchive=static function($items) use (&$walkArchive,&$nativeQueryMounted,&$linked,$provider){
            foreach((array)$items as $node){
                if(!is_array($node))continue;
                $settings=(array)($node['settings']??[]);
                $widget=(string)($node['widgetType']??'');
                if($widget==='jet-listing-grid'
                   && (int)($settings['custom_query_id']??0)===(int)$provider['query_id']
                   && ($settings['custom_query']??'')==='yes')$nativeQueryMounted=true;
                if(in_array($widget,['template','jet-dropbar'],true)
                   && ctype_digit((string)($settings['template_id']??'')))
                   $linked[(int)$settings['template_id']]=true;
                $walkArchive($node['elements']??[]);
            }
        };
        $walkArchive($nodes);
        if(!$nativeQueryMounted)continue;
        $archiveSourceIds[$archiveId]=true;
        $allowedTemplates[$archiveId]=true;
        foreach(array_keys($linked) as $linkedId){
            if(get_post_type($linkedId)!=='elementor_library'
              ||get_post_status($linkedId)!=='publish')continue;
            $allowedTemplates[$linkedId]=true;
            $siblings=$wpdb->get_col($wpdb->prepare(
                "SELECT b.element_id FROM {$wpdb->prefix}icl_translations a
                 JOIN {$wpdb->prefix}icl_translations b
                   ON b.trid=a.trid AND b.element_type=a.element_type
                 WHERE a.element_type=%s AND a.element_id=%d",
                'post_elementor_library',$linkedId
            ));
            foreach((array)$siblings as $translatedId)
                $allowedTemplates[(int)$translatedId]=true;
        }
    }
    $facetIds = [];
    $sourceTemplates = [];
    $collect = static function($nodes) use (&$collect, &$facetIds, &$sourceTemplates, $m) {
        foreach ((array)$nodes as $node) {
            if (!is_array($node)) continue;
            $widget = (string)($node['widgetType']??'');
            $settings = $node['settings']??[];
            if (str_starts_with($widget,'jet-smart-filters-') && is_array($settings)
                && ($settings['content_provider']??'') === 'jet-engine'
                && ($settings['query_id']??'') === $m[1]) {
                foreach ((array)($settings['selected_filter']??$settings['filter_id']??[]) as $raw) {
                    if (is_scalar($raw) && ctype_digit((string)$raw)
                        && (int)$raw > 0) $facetIds[(int)$raw] = true;
                }
            }
            if (isset($node['elements']) && is_array($node['elements']))
                $collect($node['elements']);
        }
    };
    foreach ($rows as $templateId) {
        if(!isset($allowedTemplates[(int)$templateId]))continue;
        $nodes = json_decode((string)get_post_meta((int)$templateId,'_elementor_data',true),true);
        if (!is_array($nodes)) continue;
        $before = count($facetIds);
        $collect($nodes);
        if (count($facetIds) > $before) $sourceTemplates[] = (int)$templateId;
    }

    $facets = [];
    foreach (array_keys($facetIds) as $filterId) {
        if (get_post_type($filterId) !== 'jet-smart-filters'
            || get_post_status($filterId)!=='publish'
            || !in_array(get_post_meta($filterId,'_filter_type',true),['checkboxes','select','radio'],true)
            || get_post_meta($filterId,'_data_source',true) !== 'taxonomies') continue;
        $taxonomy=(string)get_post_meta($filterId,'_source_taxonomy',true);
        if (!$taxonomy || !taxonomy_exists($taxonomy)) continue;
        $tax = get_taxonomy($taxonomy);
        if (!$tax || !in_array('tours-and-activities',(array)$tax->object_type,true)) continue;
        $facets[$taxonomy][]=$filterId;
    }
    ksort($facets);
    foreach ($facets as &$ids) {
        sort($ids,SORT_NUMERIC);
        $ids=array_values(array_unique($ids));
    }
    unset($ids);
    $data=[
        'discovery_version'=>3,
        'archive_template_ids'=>array_keys($archiveSourceIds),
        'provider'=>$slug,
        'query_id'=>(int)$provider['query_id'],
        'facets'=>$facets,
        'taxonomies'=>array_keys($facets),
        'source_template_ids'=>array_values(array_unique($sourceTemplates)),
    ];
    // A short cache is only an optimization. Native layout/filter edits
    // invalidate it immediately below; no indefinite hard-coded allowlist.
    // A scan finding native widgets but zero valid taxonomies may be
    // observing incomplete plugin registration. Do not poison the cache.
    if (!$facets && $facetIds) { $building=false; return $data; }
    set_transient($key,$data,$facets ? 10*MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
    $building = false;
    return $data;
}

add_filter('shared_presentation_registry_v2',static function(array $providers):array {
    if (!isset($providers['tours.archive']) || !is_array($providers['tours.archive'])) return $providers;
    $discovered=shared_presentation_native_facet_discovery_v1($providers['tours.archive']);
    $providers['tours.archive']['taxonomies']=$discovered['taxonomies']??[];
    $providers['tours.archive']['facet_filter_ids']=$discovered['facets']??[];
    $providers['tours.archive']['facet_discovery']='published_native_jet_smart_filters_widgets';
    return $providers;
},30);

function shared_presentation_invalidate_native_facet_discovery_v1($postId): void {
    if (in_array(get_post_type($postId),['elementor_library','jet-smart-filters'],true))
        delete_transient('shared_presentation_jsf_native_tour_facets_v1');
}
add_action('save_post', 'shared_presentation_invalidate_native_facet_discovery_v1',30,1);
add_action('deleted_post', 'shared_presentation_invalidate_native_facet_discovery_v1',30,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
    add_action($hook,static function($metaId,$postId,$key) {
        if (in_array($key,['_elementor_data','_source_taxonomy','_data_source','_filter_type','_query_var'],true))
            shared_presentation_invalidate_native_facet_discovery_v1((int)$postId);
    },30,3);
}
