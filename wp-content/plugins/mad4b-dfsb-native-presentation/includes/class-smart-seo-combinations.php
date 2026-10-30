<?php
namespace MAD4B\DFSB\NativePresentation;
if (!defined('ABSPATH')) { exit; }

/**
 * Sparse, demand-driven combinations. No Cartesian SEO sitemap enumeration.
 * Reads actual MU registry and published posts, never bookings or prices.
 */
final class SmartSeoCombinations {
    const CONTRACT='mad4b.smart-seo.combinations.v1';
    const PREFIX='mad4b_seo_combo_v1_';
    const MAX_FACETS=12;
    const MAX_BATCH=40;

    public static function source(string $provider,string $query) {
        $key=sanitize_key($provider).'/'.sanitize_key($query);
        if (!function_exists('shared_presentation_registry_v2')) return new \WP_Error(
            'source_registry_missing','Shared Product Core registry unavailable',array('status'=>409));
        foreach ((array)shared_presentation_registry_v2() as $slot=>$r) {
            if (!is_array($r) || ($r['provider']??'')!==$key) continue;
            $type=(string)($r['entity_type']??'');
            $policy=DynamicQualification::companyPolicy($type);
            if (!empty($policy['owner']) && empty($policy['standalone_allowed']))
                return new \WP_Error('company_standalone_disallowed',
                    'The company does not allow a standalone archive for this service',array('status'=>409));
            if (!post_type_exists($type)||!get_post_type_archive_link($type))
                return new \WP_Error('archive_unavailable','No public archive',array('status'=>409));
            return array('key'=>$key,'provider'=>$provider,'query_id'=>$query,'slot'=>$slot,
                'post_type'=>$type,'listing_id'=>(int)($r['listing_id']??0),
                'query_builder_id'=>(int)($r['query_id']??0),
                'facet_filter_ids'=>(array)($r['facet_filter_ids']??array()),
                'archive_url'=>get_post_type_archive_link($type),
                'company_policy'=>$policy);
        }
        return new \WP_Error('source_not_registered','Provider/query is not in the shared presentation registry',array('status'=>404));
    }

    public static function facets(array $source): array {
        $out=array();
        foreach($source['facet_filter_ids'] as $tax=>$ids) {
            foreach((array)$ids as $id) {
                $post=get_post((int)$id);
                if (!$post || $post->post_status!=='publish' || $post->post_type!=='jet-smart-filters')continue;
                $instance=jet_smart_filters()->filter_types->get_filter_instance((int)$id);
                $args=is_object($instance)?(array)$instance->args:array();
                $actual=(string)($args['query_var']??'');
                $object=$actual?get_taxonomy($actual):null;
                if (($args['query_type']??'')!=='tax_query' || !$object)continue;
                $items=array();
                foreach((array)($args['options']??array())as $value) {
                    if (is_array($value) && isset($value['value'])) $value=get_term((int)$value['value'],$actual);
                    if($value instanceof \WP_Term) {
                        $items[(int)$value->term_id]=array('term_id'=>(int)$value->term_id,
                          'slug'=>$value->slug,'label'=>$value->name,
                          'total_assigned'=>(int)$value->count);
                    }
                }
                $out[] =array('id'=>(int)$id,'label'=>$post->post_title,
                    'taxonomy'=>$actual,
                    'dimension'=>$actual==='guide-languages'?'tour_guide_spoken_language':$actual,
                    'distinct_from_site_language'=>$actual==='guide-languages',
                    'public_taxonomy'=>(bool)$object->public,
                    'options'=>array_values($items),'options_count'=>count($items),
                    'language_specific'=>preg_match('/- (AR|FR|DE|IT|ES)$/i',$post->post_title)===1);
            }
        }
        return $out;
    }

    /**
     * Separate commercial guide spoken language from WPML page locale.
     * Uses the actual source registry; never assumes a particular filter ID.
     */
    public static function withGuideLanguage(array $source,array $selections,$requested) {
        if ($requested===null || $requested==='') return $selections;
        if (!is_scalar($requested) || is_bool($requested))
            return new \WP_Error('guide_language_invalid',
                'Guide language must be a term slug or numeric ID',array('status'=>400));
        $facet=null;
        foreach(self::facets($source) as $candidate) {
            if (($candidate['taxonomy']??'')==='guide-languages') {
                $facet=$candidate;break;
            }
        }
        if (!$facet) return new \WP_Error('guide_language_unavailable',
            'No guide-language filter registered for this provider/query',array('status'=>422));
        foreach($selections as $choice) {
            if(!is_array($choice) || !isset($choice['filter_id'])) continue;
            if((int)$choice['filter_id']===(int)$facet['id'])
                return new \WP_Error('guide_language_duplicate',
                    'Choose guide language either in selections or guide_language',array('status'=>422));
        }
        $text=(string)$requested;
        $matches=array_values(array_filter($facet['options'],static function($item)use($text) {
            return (ctype_digit($text) && (int)$text===(int)$item['term_id'])
                || $text===(string)$item['slug'];
        }));
        if(count($matches)!==1)return new \WP_Error('guide_language_not_selectable',
            'Guide language is not an allowed current filter option',array('status'=>422));
        $selections[]=array('filter_id'=>(int)$facet['id'],
            'term_id'=>(int)$matches[0]['term_id']);
        return $selections;
    }

    public static function guideLanguages(array $source): array {
        foreach(self::facets($source) as $f) {
            if(($f['taxonomy']??'')==='guide-languages')return array(
                'dimension'=>'tour_guide_spoken_language',
                'filter_id'=>(int)$f['id'],'taxonomy'=>$f['taxonomy'],
                'terms'=>$f['options'],
                'separate_from_wpml_page_language'=>true,
                'availability_semantics'=>'Tour taxonomy association, not date-specific guide availability');
        }
        return array('dimension'=>'tour_guide_spoken_language',
            'filter_id'=>null,'terms'=>array(),'available'=>false);
    }

    public static function inspect(array $source,array $selections,string $lang='en') {
        if (!$selections || count($selections)>self::MAX_FACETS)
            return new \WP_Error('facet_count_invalid','Select between 1 and 12 facets',array('status'=>400));
        if (!in_array($lang,SmartSeoPolicyRegistry::LANGUAGES,true))
            return new \WP_Error('language_invalid','Unsupported language',array('status'=>400));
        $facets=self::facets($source);
        $map=array_column($facets,null,'id');
        $used=array();$resolved=array();$taxQuery=array('relation'=>'AND');
        foreach($selections as $value) {
            if (!is_array($value) || !isset($value['filter_id'],$value['term_id'])
                || !is_numeric($value['filter_id']) || !is_numeric($value['term_id']))
                return new \WP_Error('facet_input_invalid','Each selection needs filter_id and term_id',array('status'=>400));
            $fid=(int)$value['filter_id'];$termId=(int)$value['term_id'];
            $facet=$map[$fid]??null;
            if (!$facet || !$termId)
                return new \WP_Error('facet_not_registered','Filter is not part of this provider',array('status'=>422));
            if (isset($used[$facet['taxonomy']]))
                return new \WP_Error('duplicate_taxonomy','One selection per taxonomy per combination',array('status'=>422));
            $term=get_term($termId,$facet['taxonomy']);
            if (!$term || is_wp_error($term))
                return new \WP_Error('term_not_registered','Term not registered in this filter taxonomy',array('status'=>422));
            if (!in_array($termId,array_column($facet['options'],'term_id'),true))
                return new \WP_Error('term_not_selectable','Term is not a current filter option',array('status'=>422));
            $used[$facet['taxonomy']]=true;
            $resolved[]=array('filter_id'=>$fid,'term_id'=>$termId,'taxonomy'=>$facet['taxonomy'],
                'slug'=>$term->slug,'label'=>$term->name,
                'taxonomy_public'=>$facet['public_taxonomy']);
            $taxQuery[]=array('taxonomy'=>$facet['taxonomy'],'field'=>'term_id',
                'terms'=>array($termId),'operator'=>'IN','include_children'=>true);
        }
        usort($resolved,static fn($a,$b)=>strcmp($a['taxonomy'],$b['taxonomy']));
        $untranslated=array();
        if ($lang!=='en') {
            $taxQuery=array('relation'=>'AND');
            foreach ($resolved as $item) {
                $translated=apply_filters('wpml_object_id',(int)$item['term_id'],
                    $item['taxonomy'],false,$lang);
                if (!$translated) $untranslated[]=$item['term_id'];
                $taxQuery[]=array('taxonomy'=>$item['taxonomy'],'field'=>'term_id',
                    'terms'=>array($translated?(int)$translated:0),
                    'operator'=>'IN','include_children'=>true);
            }
        }
        // Evaluate matching posts in the correct WPML language, then restore
        // the original language context for the REST request.
        $current=(string)apply_filters('wpml_current_language','en');
        if($lang!==$current && has_action('wpml_switch_language'))
            do_action('wpml_switch_language',$lang);
        try {
            $q=new \WP_Query(array('post_type'=>$source['post_type'],'post_status'=>'publish',
                'posts_per_page'=>3,'fields'=>'ids','tax_query'=>$taxQuery,
                'ignore_sticky_posts'=>true,'no_found_rows'=>false));
        } finally {
            if($lang!==$current && has_action('wpml_switch_language'))
                do_action('wpml_switch_language',$current);
        }
        $count=(int)$q->found_posts;
        $policy=SmartSeoPolicyRegistry::effective($source['provider'],$source['query_id']);
        $required=(int)$policy['min_result_count'];
        $restricted=array_values(array_column(array_filter($resolved,
            static fn($x)=>!$x['taxonomy_public']),'taxonomy'));
        $guideLanguage=null;
        foreach($resolved as $item) {
            if($item['taxonomy']==='guide-languages') {
                $guideLanguage=array('term_id'=>(int)$item['term_id'],
                    'slug'=>$item['slug'],'label'=>$item['label'],
                    'filter_id'=>(int)$item['filter_id'],
                    'evidence'=>'published_tour_term_intersection',
                    'date_specific_availability_verified'=>false);
                break;
            }
        }
        $url=self::url($source,$resolved,$lang);
        $blocked=array();
        if($count<$required)$blocked[]='not_enough_published_matching_products';
        if($restricted)$blocked[]='nonpublic_taxonomy_requires_company_policy_review';
        if($untranslated)$blocked[]='wpml_term_translation_missing';
        if($lang!=='en') {
            $translatedArchive=apply_filters('wpml_permalink',$source['archive_url'],$lang,true);
            $englishArchive=apply_filters('wpml_permalink',$source['archive_url'],'en',true);
            // Do not publish a localized canonical while the site has no
            // distinct localized archive route. Search/preview still works.
            if(untrailingslashit((string)$translatedArchive)
                ===untrailingslashit((string)$englishArchive))
                $blocked[]='localized_archive_url_not_distinct';
        }
        // Locale review is a separate REST gate, not a permanent selection error.
        if(!$policy['enabled'])$blocked[]='source_policy_disabled';
        foreach($resolved as $x) {
            if(in_array($x['filter_id'],$policy['excluded_filter_ids'],true))
                $blocked[]='excluded_filter_by_company_policy';
            if($policy['allowed_filter_ids']
                && !in_array($x['filter_id'],$policy['allowed_filter_ids'],true))
                $blocked[]='filter_not_yet_allowlisted_by_company_policy';
        }
        $key=$source['key'];
        $id=hash('sha256',wp_json_encode(array($key,$lang,
            array_map(static fn($x)=>array($x['filter_id'],$x['term_id']),$resolved))));
        return array(
            'contract'=>self::CONTRACT,'id'=>$id,'source'=>$key,
            'language'=>$lang,'site_language'=>$lang,
            'guide_language'=>$guideLanguage,
            'post_type'=>$source['post_type'],'selections'=>$resolved,
            'matched_published_count'=>$count,'sample_post_ids'=>array_map('intval',$q->posts),
            'min_result_count'=>$required,'url'=>$url,
            'qualified_for_editorial_review'=>!$blocked,'index_ready'=>false,
            'blockers'=>array_values(array_unique($blocked)),
            'localization_review_required'=>$lang!=='en',
            'untranslated_term_ids'=>$untranslated,
            'result_evidence'=>'wordpress_wp_query_taxonomy_intersection',
            'public_url_evidence'=>'not_yet_verified',
        );
    }

    public static function url(array $source,array $resolved,string $lang): string {
        $base=$source['archive_url'];
        if($lang!=='en')$base=apply_filters('wpml_permalink',$base,$lang,true);
        $sections=array();
        foreach($resolved as $x) {
            $id=(int)$x['term_id'];
            if ($lang!=='en') {
                $translated=apply_filters('wpml_object_id',$id,$x['taxonomy'],false,$lang);
                if($translated)$id=(int)$translated;
            }
            $sections[]=$x['taxonomy'].':'.$id;
        }
        $filter=implode(';',$sections);
        // Format is the native JetSmartFilters ?jsf=...&tax=... syntax.
        return add_query_arg(array('jsf'=>$source['provider'].':'.$source['query_id'],
            'tax'=>$filter),$base);
    }

    public static function get(string $id) {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))
            return new \WP_Error('combination_id_invalid','Invalid combination ID',array('status'=>400));
        $record=get_option(self::PREFIX.$id,null);
        return is_array($record)?$record:new \WP_Error(
            'combination_not_found','Combination not found',array('status'=>404));
    }
    public static function revision(array $record): string {
        return hash('sha256',wp_json_encode($record));
    }
    public static function put(string $id,$expected,array $next) {
        global $wpdb;
        $key=self::PREFIX.$id;
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
        if($expected===null && !is_string($raw)) {
            if(!add_option($key,$next,'','no'))return new \WP_Error('record_conflict','Already created',array('status'=>409));
        } elseif(is_string($raw)) {
            $old=maybe_unserialize($raw);
            if(!is_array($old) || !is_string($expected) || !hash_equals(self::revision($old),$expected))
                return new \WP_Error('record_revision_conflict','Reload before updating',array('status'=>409));
            if(!SmartSeoNativeStore::compareAndSwapOption($key,$raw,maybe_serialize($next)))
                return new \WP_Error('record_write_conflict','Concurrent update rejected',array('status'=>409));
        } else return new \WP_Error('record_not_found','Record does not exist',array('status'=>404));
        return array('id'=>$id,'revision'=>self::revision($next),'record'=>$next);
    }
    public static function list(int $limit=50): array {
        global $wpdb;
        $limit=max(1,min(100,$limit));
        $prefix=$wpdb->esc_like(self::PREFIX).'%';
        $names=$wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",$prefix,$limit));
        $out=array();
        foreach($names as $name) {
            $record=get_option($name,array());
            if(is_array($record))$out[]=array('revision'=>self::revision($record),'record'=>$record);
        }
        return $out;
    }
}
