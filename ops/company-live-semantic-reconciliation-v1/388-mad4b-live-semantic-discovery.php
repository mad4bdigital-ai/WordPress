<?php
/**
 * Plugin Name: MAD4B Live Semantic Reconciliation Discovery
 * Description: Read-only live semantic inventory for company parity and archive filters.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/** Discovery must never mutate content, issue jobs, grant access, or reapply old Git state. */
function mad4b_live_semantic_discovery_v1(): array {
    global $wpdb;
    $out = [
        'contract' => 'mad4b.live-semantic-reconciliation.discovery.v1',
        'origin' => untrailingslashit(home_url()),
        'captured_utc' => gmdate('c'),
        'read_only' => true, 'changes_applied' => false, 'authority_granted' => false,
        'observations' => [], 'findings' => [],
        'browser_acceptance' => 'NOT_RUN_BY_DISCOVERY',
    ];
    $issue = static function(string $kind, string $level, string $key, array $context = []) use (&$out): void {
        $out['findings'][] = ['kind' => $kind, 'severity' => $level, 'key' => $key, 'context' => $context,
            'auto_apply' => false];
    };
    $postType = 'jet-smart-filters';
    $programs = []; $filterReadbacks = [];
    $keys = ['related_program_term_id'=>'programs', 'related_package_term_id'=>'package_levels'];
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_query_var'
         WHERE p.post_type=%s AND p.post_status='publish'
           AND (m.meta_value IN ('related_program_term_id','related_package_term_id')
                OR m.meta_value LIKE '_tax_query::%%')
         ORDER BY p.ID LIMIT 500", $postType
    ));
    $manager = class_exists('Jet_Engine\Query_Builder\Manager', false)
        ? \Jet_Engine\Query_Builder\Manager::instance() : null;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $var = (string)get_post_meta($id,'_query_var',true);
        $source = (string)get_post_meta($id,'_data_source',true);
        $tax = (string)get_post_meta($id,'_source_taxonomy',true);
        $queryId = (int)get_post_meta($id,'_query_builder_query',true);
        $expectedTax = $keys[$var] ?? (str_starts_with($var,'_tax_query::') ? substr($var,12) : '');
        $row = ['id'=>$id,'query_var'=>$var,'data_source'=>$source,
            'configured_taxonomy'=>$tax,'query_id'=>$queryId,
            'expected_taxonomy'=>$expectedTax,'taxonomy_exists'=>($expectedTax!=='' && taxonomy_exists($expectedTax))];
        $actualTax=[];
        if ($source==='query_builder') {
            $query = $queryId && $manager ? $manager->get_query_by_id($queryId) : null;
            $args = $query && method_exists($query,'get_query_args') ? (array)$query->get_query_args() : [];
            $actualTax = array_values(array_filter(array_map('strval',(array)($args['taxonomy']??[]))));
            $row['query_taxonomies'] = $actualTax;
            $row['query_exists'] = is_object($query);
            if (!$query) $issue('missing_query_builder',$queryId ? 'P1' : 'P2',"filter:$id",['query_id'=>$queryId,'data_source'=>$source]);
            elseif ($expectedTax && !in_array($expectedTax,$actualTax,true))
                $issue('wrong_query_taxonomy','P1',"filter:$id",['expected'=>$expectedTax,'observed'=>$actualTax,'query_id'=>$queryId]);
        }
        $directMetaFilter = isset($keys[$var]);
        if ($expectedTax && $tax && $tax!==$expectedTax) {
            $effectiveQueryCorrect = $source==='query_builder' && in_array($expectedTax,$actualTax,true);
            $unusedSourceTaxonomy = !in_array($source,['query_builder','taxonomies'],true);
            $fallbackOnly=$effectiveQueryCorrect || $unusedSourceTaxonomy;
            $level = $fallbackOnly ? 'ADVISORY' :
                ($directMetaFilter ? 'P1' : (taxonomy_exists($expectedTax) ? 'P2' : 'ADVISORY'));
            $issue($fallbackOnly?'stale_fallback_taxonomy_only':'filter_taxonomy_mismatch',
                $level,"filter:$id",['expected'=>$expectedTax,'observed'=>$tax,
                'effective_query_correct'=>$effectiveQueryCorrect,'data_source'=>$source,
                'legacy_alias_possible'=>!$directMetaFilter]);
        }
        if ($expectedTax && !taxonomy_exists($expectedTax)) {
            $level = $directMetaFilter || !taxonomy_exists($tax) ? 'P1' : 'ADVISORY';
            $issue('filter_taxonomy_not_registered',$level,"filter:$id",
                ['expected'=>$expectedTax,'configured'=>$tax,'legacy_alias_possible'=>!$directMetaFilter]);
        }
        if ($var==='related_program_term_id') $programs[]=$id;
        $filterReadbacks[]=$row;
    }
    $out['observations']['filters']=[
        'count'=>count($filterReadbacks), 'program_filter_ids'=>$programs,
        'definitions'=>$filterReadbacks,
    ];
    // Compare independent program and package Rates against their active Tour and term.
    $rates = $wpdb->get_results(
        "SELECT ID,post_status FROM {$wpdb->posts}
         WHERE post_type='tour-rates' AND post_status IN ('publish','draft') ORDER BY ID LIMIT 2500"
    );
    $stats = ['publish'=>0,'draft'=>0,'published_missing_package'=>0,
        'published_invalid_package'=>0,'published_draft_parent'=>0,
        'published_orphan_parent'=>0,'published_invalid_program'=>0,
        'published_programs'=>0,'published_stopped'=>0];
    $samples=['missing_package'=>[],'draft_parent'=>[],'invalid_program'=>[]];
    $rateParents=[]; $programCounts=[]; $publishedRateDetails=[];
    foreach($rates as $rate) {
        $id=(int)$rate->ID; $status=(string)$rate->post_status;
        $stats[$status]++;
        $parent=(int)get_post_meta($id,'related_tour_id',true);
        $pkg=(int)get_post_meta($id,'related_package_term_id',true);
        $program=(int)get_post_meta($id,'related_program_term_id',true);
        $parentStatus=$parent ? get_post_status($parent) : false;
        $stopped=(string)get_post_meta($id,'stop_sale',true)==='1';
        if ($status==='publish') {
            $publishedRateDetails[]=['id'=>$id,'parent_id'=>$parent,
                'package_term_id'=>$pkg,'program_term_id'=>$program,
                'stopped'=>$stopped];
            if ($stopped) $stats['published_stopped']++;
            if (!$pkg) {
                $stats['published_missing_package']++;
                if (count($samples['missing_package'])<15) $samples['missing_package'][]=$id;
            } elseif (!term_exists($pkg,'package_levels')) $stats['published_invalid_package']++;
            if (!$parent || get_post_type($parent)!=='tours-and-activities') $stats['published_orphan_parent']++;
            elseif ($parentStatus!=='publish') {
                $stats['published_draft_parent']++;
                if(count($samples['draft_parent'])<15)$samples['draft_parent'][]=$id;
            }
            if ($program) {
                if (!term_exists($program,'programs')) {
                    $stats['published_invalid_program']++;
                    if(count($samples['invalid_program'])<15)$samples['invalid_program'][]=$id;
                } else { $stats['published_programs']++; if(!$stopped) $programCounts[$parent][$program]=true; }
            }
            if ($parent) $rateParents[$parent] = ($rateParents[$parent]??0)+1;
        }
    }
    foreach(['published_missing_package','published_invalid_package','published_orphan_parent',
        'published_invalid_program'] as $metric) if($stats[$metric])
        $issue($metric, 'P1', 'tour-rates', ['count'=>$stats[$metric]]);
    if($stats['published_draft_parent'])
        $issue('published_rate_draft_parent','P2','tour-rates',
            ['count'=>$stats['published_draft_parent'],'not_auto_publish'=>true]);
    $out['observations']['rates']=['counts'=>$stats,'samples'=>$samples,
        'published_rate_details'=>$publishedRateDetails];
    $tours=$wpdb->get_results(
        "SELECT ID,post_status FROM {$wpdb->posts}
         WHERE post_type='tours-and-activities' AND post_status='publish' ORDER BY ID LIMIT 3000"
    );
    $tourCounts=['published'=>count($tours),'public'=>0,'cruise'=>0,
        'single_itinerary_cruise'=>0,'possible_multi_program_cruise'=>0,'public_without_rate_or_start_price'=>0];
    $publicUnpriced=[];$multi=[];$galleryHashes=[];$heroHashes=[];
    foreach($tours as $tour){
        $id=(int)$tour->ID;
        $mode=(string)get_post_meta($id,'tour_mode',true);
        $isPublic=(string)get_post_meta($id,'tour_public_status',true)==='1';
        if($isPublic) {
            $tourCounts['public']++;
            foreach(['gallery'=>&$galleryHashes,'image_vertical'=>&$heroHashes] as $field=>&$bucket) {
                $value=(string)get_post_meta($id,$field,true);
                if($value!=='') {$hash=hash('sha256',$value);$bucket[$hash]=($bucket[$hash]??0)+1;}
            }
            unset($bucket);
            if (empty($rateParents[$id]) && (float)get_post_meta($id,'start_price',true)<=0) {
                $tourCounts['public_without_rate_or_start_price']++;
                if(count($publicUnpriced)<12)$publicUnpriced[]=$id;
            }
        }
        if($mode!=='cruise_tour')continue;
        $tourCounts['cruise']++;
        if(count($programCounts[$id]??[])>1){
            $tourCounts['possible_multi_program_cruise']++;
            if(count($multi)<12)$multi[]=$id;
        } else $tourCounts['single_itinerary_cruise']++;
    }
    if($publicUnpriced)$issue('public_tour_without_published_rate_or_base_price','P2',
        'tours',['count'=>count($publicUnpriced),'examples'=>$publicUnpriced]);
    $out['observations']['tours']=['counts'=>$tourCounts,'possible_multi_program_ids'=>$multi];
    arsort($galleryHashes);arsort($heroHashes);
    $media=['public_tours'=>$tourCounts['public'],'distinct_gallery_values'=>count($galleryHashes),
        'largest_identical_gallery_group'=>(int)(reset($galleryHashes)?:0),
        'distinct_vertical_image_values'=>count($heroHashes),
        'largest_identical_vertical_group'=>(int)(reset($heroHashes)?:0)];
    $out['observations']['media_identity']=$media;
    if($media['public_tours']>=3 && $media['largest_identical_gallery_group']>=$media['public_tours']*.85)
        $issue('gallery_content_reused_across_tours','P2','gallery',
            ['public_tours'=>$media['public_tours'],'repeated_gallery_group'=>$media['largest_identical_gallery_group'],
            'requires_editorial_media_provenance'=>true]);
    // Translation IDs are site-local; cross-site TRID equality must never be assumed.
    $wpml=['available'=>false,'rate_lang_parent_mismatch'=>0,
        'standalone_non_english_rate'=>0,'rate_without_translation_row'=>0,
        'samples'=>[],'rate_groups'=>[]];
    $translationTable=$wpdb->prefix.'icl_translations';
    $tableExists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($translationTable)));
    if($tableExists===$translationTable) {
        $wpml['available']=true;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT element_id,trid,language_code,source_language_code FROM {$translationTable}
             WHERE element_type=%s",'post_tour-rates'),ARRAY_A);
        $groups=[];$byRate=[];
        foreach($rows as $r) {
            $byRate[(int)$r['element_id']]=$r;
            $groups[(int)$r['trid']][(string)$r['language_code']]=true;
        }
        $trows=$wpdb->get_results($wpdb->prepare(
            "SELECT element_id,trid,language_code FROM {$translationTable}
             WHERE element_type=%s",'post_tours-and-activities'),ARRAY_A);
        $byTour=[];foreach($trows as $r)$byTour[(int)$r['element_id']]=$r;
        foreach($rates as $rate) {
            $id=(int)$rate->ID;$r=$byRate[$id]??null;
            if(!$r){$wpml['rate_without_translation_row']++;continue;}
            $parent=(int)get_post_meta($id,'related_tour_id',true);
            $language=(string)$r['language_code'];
            $parentLang=(string)($byTour[$parent]['language_code']??'');
            if($parentLang!=='' && $language!==$parentLang) {
                $wpml['rate_lang_parent_mismatch']++;
                if(count($wpml['samples'])<15)$wpml['samples'][]=['rate_id'=>$id,'kind'=>'parent_language_mismatch'];
            }
            if($language!=='en' && $r['source_language_code']===null &&
                count($groups[(int)$r['trid']]??[])===1) {
                $wpml['standalone_non_english_rate']++;
                if(count($wpml['samples'])<15)$wpml['samples'][]=['rate_id'=>$id,'kind'=>'standalone_translation_candidate'];
            }
            $wpml['rate_groups'][]=['id'=>$id,'language'=>$language,'trid'=>(int)$r['trid'],
                'source_language'=>$r['source_language_code'],'parent'=>$parent,
                'parent_trid'=>(int)($byTour[$parent]['trid']??0)];
        }
    }
    if($wpml['rate_lang_parent_mismatch'])
        $issue('wpml_rate_parent_language_mismatch','P1','wpml',['count'=>$wpml['rate_lang_parent_mismatch']]);
    if($wpml['standalone_non_english_rate'])
        $issue('wpml_rate_standalone_translation','P2','wpml',
            ['count'=>$wpml['standalone_non_english_rate'],'requires_identity_proof'=>true]);
    $out['observations']['wpml']=$wpml;
    if(!$multi) $issue('multi_program_positive_test_unavailable','ADVISORY','programs',
        ['reason'=>'no_multiple_nonstopped_program_rate_relations','does_not_imply_broken_single_itinerary'=>true]);
    // The UI semantics and runtime modules are distinct: a guarded widget is not a corrected source query.
    $jsf=(array)get_option('jet-smart-filters-settings',[]);
    $rewrites=(array)($jsf['rewritable_post_types']??[]);
    $rules=(array)get_option('rewrite_rules',[]);
    $route=false;
    foreach($rules as $pattern=>$dest)if(str_starts_with($pattern,'tours-and-activities/jsf/')){$route=true;break;}
    $runtime=[
        'presentation_enabled'=>defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true,
        'commerce_enabled'=>defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true,
        'program_mode_function'=>function_exists('shared_product_program_mode_supported'),
        'rate_eligibility_function'=>function_exists('shared_product_eligible_tour_rate_ids'),
        'rate_facets_function'=>function_exists('shared_product_tour_facets'),
        'jet_smart_filters_signature'=>(string)($jsf['use_signature_verification']??'')==='true',
        'archive_rewritable'=>!empty($rewrites['tours-and-activities']),
        'archive_rewrite_rule_present'=>$route,
        'litespeed_combine_inline_external'=>(string)get_option('litespeed.conf.optm-js_comb_ext_inl',''),
        'visitor_lean_bootstrap'=>(string)get_option('mad4b_visitor_preference_lean_bootstrap_v1',''),
        'visitor_bootstrap_file_present'=>is_file(WPMU_PLUGIN_DIR.'/000-mad4b-visitor-state-fast-bootstrap.php'),
    ];
    if(!$runtime['jet_smart_filters_signature'])$issue('unsigned_filter_settings','P1','jet-smart-filters');
    if(!$runtime['archive_rewritable']||!$route)$issue('archive_deep_link_not_registered','P1','rewrite');
    if($runtime['presentation_enabled'] && (!$runtime['rate_eligibility_function'] || !$runtime['rate_facets_function']))
        $issue('presentation_missing_rate_facets','P1','runtime');
    if($runtime['litespeed_combine_inline_external']==='1')
        $issue('inline_js_combined_with_external','P2','litespeed',['potential_initialization_order_drift'=>true]);
    $out['observations']['runtime']=$runtime;
    $slots=[];
    if(class_exists('DynamicFilterSEOBridge\Presentation\ContentSlotRegistry',false)){
        try{
            $legacyCoreProfile = !$runtime['presentation_enabled'] && $runtime['commerce_enabled'];
            $reg=new \DynamicFilterSEOBridge\Presentation\ContentSlotRegistry($legacyCoreProfile);
            foreach(['style_section','tour_type_section'] as $key){
                $s=$reg->get($key);
                $slots[$key]=['enabled'=>!empty($s['enabled']),'type'=>$s['type']??null];
                if(empty($s['enabled']))$issue('presentation_slot_missing','P1','dfsb:'.$key);
            }
        }catch(Throwable $err){$issue('presentation_slot_readback_unavailable','UNKNOWN','dfsb',['reason'=>'registry_failed']);}
    }else $issue('presentation_registry_unavailable','UNKNOWN','dfsb');
    $out['observations']['dfsb_slots']=$slots;
    // Hash only checked-in runtime sources, never media, credentials or arbitrary paths.
    $paths=[
        '387-mad4b-archive-filter-reconciler.php',
        '388-mad4b-live-semantic-discovery.php',
        '389-mad4b-archive-async-asset-epoch.php',
        '406-mad4b-shared-performance-parity.php',
        '000-mad4b-visitor-state-fast-bootstrap.php',
        '001-mad4b-shared-presentation-provider.php',
        '179-wayfa-program-select-scope.php',
        'mad4b-destination-cards/company-destination-ajax-bridge.php',
        'shared-product-core/adapters/tour-rate-facets-from-query.php',
        'shared-product-core/adapters/tour-rate-eligible-query.php',
        'shared-product-core/adapters/tour-program-source-contract-v1.php',
        'shared-product-core/adapters/tour-rate-package-filter-options.php',
    ];
    $fingerprints=[];
    foreach($paths as $path){
        $absolute=WPMU_PLUGIN_DIR.'/'.$path;
        if(is_file($absolute) && is_readable($absolute))$fingerprints[$path]=hash_file('sha256',$absolute);
    }
    $out['observations']['code_fingerprints']=$fingerprints;
    $pluginPaths=[
        'dynamic-filter-seo-bridge/assets/js/ajax-filter-state.js',
        'dynamic-filter-seo-bridge/assets/js/ajax-taxonomy-reconcile.js',
        'dynamic-filter-seo-bridge/includes/Presentation/AjaxPresentationEndpoint.php',
        'jet-smart-filters/includes/rewrite.php',
    ];
    $pluginHashes=[];
    foreach($pluginPaths as $name){
        $file=WP_PLUGIN_DIR.'/'.$name;
        if(is_file($file) && is_readable($file))$pluginHashes[$name]=hash_file('sha256',$file);
    }
    $out['observations']['plugin_code_fingerprints']=$pluginHashes;
    $out['counts']=['findings'=>count($out['findings']),
        'p1'=>count(array_filter($out['findings'],static fn($x)=>$x['severity']==='P1')),
        'p2'=>count(array_filter($out['findings'],static fn($x)=>$x['severity']==='P2'))];
    return $out;
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')){
    WP_CLI::add_command('mad4b semantic-discovery',new class {
        public function scan($args,$assoc):void{
            WP_CLI::log(wp_json_encode(mad4b_live_semantic_discovery_v1(),
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    });
}
