<?php
/**
 * Plugin Name: MAD4B WPML Design Reconciliation
 * Description: Source-of-truth visual and provider-configuration parity across linked WPML objects. Does not translate locale text or prices.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
/** Shared guard for 301, 305 and 349. Defaults ON, never controls cross-company operations. */
function mad4b_wpml_auto_sync_allowed_v1($source_id=0,$target_id=0) {
    if(get_option('mad4b_wpml_design_auto_sync_enabled_v1','yes')!=='yes')return false;
    foreach([(int)$source_id,(int)$target_id] as $post_id) {
        if($post_id>0&&get_post_meta($post_id,'_mad4b_wpml_auto_sync_excluded',true)==='yes')return false;
    }
    return true;
}
final class MAD4B_WPML_Design_Reconciliation_V1 {
    const EVENT = 'mad4b_wpml_design_reconciliation_v1';
    const QUEUE = 'mad4b_wpml_design_sync_queue_v1';
    const AUDIT = 'mad4b_wpml_design_sync_audit_v1';
    /** All registered post types are eligible unless manually excluded. */
    public static function eligible_types(): array {
        $types=get_post_types([], 'names');
        $excluded=get_option('mad4b_wpml_design_excluded_post_types_v1',[]);
        if(!is_array($excluded))$excluded=[];
        $excluded=array_fill_keys(array_filter(array_map('strval',$excluded)),true);
        return array_values(array_filter((array)$types,static function($type)use($excluded){
            return is_string($type) && !isset($excluded[$type]);
        }));
    }
    private static function eligible_type(string $type): bool {
        return in_array($type,self::eligible_types(),true);
    }
    private static $busy = false;
    private static function runtime_site() {
        return wp_get_environment_type()==='staging'
            && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
            && defined('ICL_SITEPRESS_VERSION');
    }

    public static function enabled() {
        return self::runtime_site() && mad4b_wpml_auto_sync_allowed_v1();
    }
    /**
     * Policy is the company's runtime/configured WPML language union, never
     * a static EN/AR or six-language constant. Missing language means PENDING.
     */
    public static function required_languages_readback(): array {
        $settings=(array)get_option('icl_sitepress_settings',[]);
        $configured=(array)($settings['active_languages']??[]);
        $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
        $live=is_array($active)?array_keys($active):[];
        $default=sanitize_key((string)apply_filters('wpml_default_language',null));
        $normalize=static function($values) {
            $out=[];
            foreach((array)$values as $v){
                $v=strtolower((string)$v);
                if(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$v))$out[$v]=true;
            }
            return array_keys($out);
        };
        $configured=$normalize($configured);
        $live=$normalize($live);
        $langs=$normalize(array_merge($configured,$live,[$default]));
        usort($langs,static fn($a,$b)=>$a===$default?-1:
            ($b===$default?1:strcmp($a,$b)));
        $a=$configured;$b=$live;sort($a);sort($b);
        return ['required'=>$langs,'default'=>$default,
            'configured'=>$configured,'runtime'=>$live,
            'consistent'=>$a===$b&&$default!==''&&count($langs)<=32,
            'truncated'=>count($langs)>32,
            'source'=>'WPML_CONFIG_AND_RUNTIME_UNION',
            'mutation_performed'=>false];
    }
    /**
     * Side-effect-free verification of an authored Elementor ref for a given
     * locale. This is not a browser/query-execution or translation proof.
     */
    private static function linked_reference_gaps(int $source,int $target,string $lang): array {
        $origin=self::tree($source);
        $translated=self::tree($target);
        $src=[];$dst=[];
        $walk=static function($nodes,&$index)use(&$walk){
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                $id=(string)($node['id']??'');
                if($id!=='')$index[$id]=(array)($node['settings']??[]);
                if(!empty($node['elements']))$walk($node['elements'],$index);
            }
        };
        $walk($origin,$src);$walk($translated,$dst);
        $issues=[];$inspected=0;$incomplete=false;
        foreach($src as $widget=>$settings){
            foreach($settings as $field=>$value){
                $type=self::reference_key((string)$field);
                if(!$type)continue;
                foreach((is_array($value)?array_slice($value,0,25):[$value]) as $position=>$ref){
                    if(!is_scalar($ref)||!ctype_digit((string)$ref)||(int)$ref<=0)continue;
                    if(++$inspected>180){$incomplete=true;break 3;}
                    $expected=(int)apply_filters('wpml_object_id',(int)$ref,$type,false,$lang);
                    $post=$expected?get_post($expected):null;
                    $valid=$post&&$post->post_type===$type&&$post->post_status==='publish'
                        &&self::language($expected)===$lang;
                    $actual=$dst[$widget][$field]??null;
                    $local=is_array($actual)?($actual[$position]??null):$actual;
                    $resolved=is_scalar($local)&&ctype_digit((string)$local)?(int)$local:0;
                    if(($dst[$widget]['mad4b_wpml_sync_mode']??'')==='detach')continue;
                    if(!$valid||$resolved!==$expected){
                        $issues[]=['widget_id'=>$widget,'field'=>(string)$field,
                            'source_reference'=>(int)$ref,
                            'expected_locale_reference'=>$valid?$expected:0,
                            'actual_locale_reference'=>$resolved,
                            'code'=>!$valid?'MISSING_LINKED_REFERENCE':
                                'WRONG_LANGUAGE_OR_STALE_REFERENCE'];
                    }
                }
            }
        }
        return ['issues'=>array_slice($issues,0,60),
            'unresolved_count'=>count($issues),
            'inspected_refs'=>min($inspected,180),
            'truncated'=>$incomplete,'no_runtime_claim'=>true];
    }
    /** Native WPML term identity and same-text review; no translation writes. */
    /**
     * Locale-driven weekday taxonomy term names through existing WPML links.
     * ICU CLDR supplies labels, not a hardcoded MAD4B translation dictionary.
     * Preserve manually translated names; source must have seven EN weekdays.
     */
    /** Read exact stored taxonomy term, without WPML's current-locale remapping. */
    private static function exact_taxonomy_term(int $term_id,string $taxonomy):?array {
        if($term_id<1||!taxonomy_exists($taxonomy))return null;
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id,t.name,t.slug,tt.term_taxonomy_id
               FROM {$wpdb->terms} t
               JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
              WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",
            $term_id,$taxonomy),ARRAY_A);
        return is_array($row)?$row:null;
    }
    public static function localize_weekday_terms(string $taxonomy,bool $apply=false):array|WP_Error {
        $policy=self::required_languages_readback();
        if(!$policy['consistent']||$policy['default']!=='en'||
           !taxonomy_exists($taxonomy)||!class_exists('IntlDateFormatter'))
            return new WP_Error('mad4b_weekday_terms_policy_or_icu_unavailable');
        if($apply && (!self::enabled()||!MAD4B_SCP_Policy::can_mutate()||
            !current_user_can('manage_options')))
            return new WP_Error('mad4b_weekday_terms_mutation_authority_denied');
        $langs=apply_filters('wpml_active_languages',null,'skip_missing=0');
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,
                          'number'=>40,'lang'=>$policy['default']]);
        if(is_wp_error($terms)||!is_array($terms)||count($terms)!==7)
            return new WP_Error('mad4b_weekday_terms_source_incomplete');
        $dates=[];$base=new DateTimeImmutable('2026-11-09T12:00:00+00:00');
        for($i=0;$i<7;$i++){
            $date=$base->modify("+{$i} days");
            $formatter=new IntlDateFormatter('en_US',IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,'UTC',IntlDateFormatter::GREGORIAN,'EEEE');
            $name=$formatter->format($date);
            $dates[mb_strtolower((string)$name,'UTF-8')]=$date;
        }
        $source=[];
        foreach($terms as $term){
            if(!$term instanceof WP_Term ||
               !isset($dates[mb_strtolower((string)$term->name,'UTF-8')]))
                return new WP_Error('mad4b_weekday_terms_unexpected_source_label');
            $actual=(string)apply_filters('wpml_element_language_code',null,[
                'element_id'=>$term->term_id,'element_type'=>'tax_'.$taxonomy]);
            if($actual!=='en')return new WP_Error('mad4b_weekday_terms_source_identity_invalid');
            $source[]=$term;
        }
        $rows=[];$issues=[];$written=[];
        foreach($policy['required'] as $lang){
            if($lang==='en')continue;
            $locale=is_array($langs[$lang]??null)
                ?(string)($langs[$lang]['default_locale']??$lang):$lang;
            if(!preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8})*$/D',$locale))
                return new WP_Error('mad4b_weekday_terms_locale_not_supported');
            $formatter=new IntlDateFormatter($locale,IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,'UTC',IntlDateFormatter::GREGORIAN,'EEEE');
            foreach($source as $term){
                $id=absint(apply_filters('wpml_object_id',
                    $term->term_id,$taxonomy,false,$lang));
                $translated=$id?self::exact_taxonomy_term($id,$taxonomy):null;
                $identity=$id?(string)apply_filters('wpml_element_language_code',null,[
                    'element_id'=>$id,'element_type'=>'tax_'.$taxonomy]):'';
                if(!$translated||$identity!==$lang){
                    $issues[]=['source_id'=>$term->term_id,'lang'=>$lang,
                        'code'=>'MISSING_OR_INVALID_TERM_TRANSLATION'];
                    continue;
                }
                $name=$formatter->format($dates[mb_strtolower($term->name,'UTF-8')]);
                if(!is_string($name)||trim($name)===''){
                    $issues[]=['source_id'=>$term->term_id,'lang'=>$lang,
                        'code'=>'ICU_LOCALE_LABEL_UNAVAILABLE'];continue;
                }
                $current=(string)$translated['name'];
                $state=$current===$name?'ALREADY_LOCALIZED':
                    ($current===(string)$term->name?'SOURCE_TEXT_NEEDS_LOCALIZATION':
                     'LOCAL_EDITOR_OVERRIDE_PRESERVED');
                $rows[]=['source_id'=>$term->term_id,'target_id'=>$id,'lang'=>$lang,
                    'expected_name'=>$name,'current_name'=>$current,'state'=>$state];
            }
        }
        if($issues)return new WP_Error('mad4b_weekday_terms_missing_dependency',
            'Native term translation identity or ICU label unavailable',
            ['issues'=>$issues,'mutation_performed'=>false]);
        if($apply){
            global $wpdb;
            // WPML legitimately reuses slugs across locales. wp_update_term()
            // can reject a name-only edit as duplicate_term_slug. Make only an
            // exact, authorized term-name compare-and-swap; preserve term ID,
            // taxonomy, slug, relationships and locale identity unchanged.
            $rollback=static function(array $changes)use($wpdb,$taxonomy):void {
                foreach(array_reverse($changes) as $done){
                    $wpdb->update($wpdb->terms,
                        ['name'=>$done['prior']],
                        ['term_id'=>$done['id'],'name'=>$done['name']],
                        ['%s'],['%d','%s']);
                    clean_term_cache($done['id'],$taxonomy);
                }
            };
            foreach($rows as $row){
                if($row['state']!=='SOURCE_TEXT_NEEDS_LOCALIZATION')continue;
                $live=self::exact_taxonomy_term((int)$row['target_id'],$taxonomy);
                $source_term=self::exact_taxonomy_term((int)$row['source_id'],$taxonomy);
                $identity=(string)apply_filters('wpml_element_language_code',null,[
                    'element_id'=>(int)$row['target_id'],
                    'element_type'=>'tax_'.$taxonomy]);
                if(!$live||!$source_term||
                   (string)$live['name']!==$row['current_name']||
                   (string)$live['name']!==(string)$source_term['name']||
                   $identity!==$row['lang']){
                    $rollback($written);
                    return new WP_Error('mad4b_weekday_terms_stale_rollback');
                }
                $changed=$wpdb->update($wpdb->terms,
                    ['name'=>$row['expected_name']],
                    ['term_id'=>$row['target_id'],'name'=>$row['current_name']],
                    ['%s'],['%d','%s']);
                if($changed!==1){
                    $rollback($written);
                    return new WP_Error('mad4b_weekday_terms_compare_and_swap_rollback');
                }
                $written[]=['id'=>$row['target_id'],'prior'=>$row['current_name'],
                    'name'=>$row['expected_name'],'lang'=>$row['lang']];
                clean_term_cache($row['target_id'],$taxonomy);
                $after=self::exact_taxonomy_term((int)$row['target_id'],$taxonomy);
                if(!$after||$after['name']!==$row['expected_name']||
                   $after['slug']!==$live['slug']||
                   (int)$after['term_taxonomy_id']!==(int)$live['term_taxonomy_id']){
                    $rollback($written);
                    return new WP_Error('mad4b_weekday_terms_exact_readback_rollback');
                }
            }
        }
        $out=['contract'=>'mad4b.wpml.weekday-terms-native.v1',
            'taxonomy'=>$taxonomy,'required_languages'=>$policy['required'],
            'candidate_count'=>count(array_filter($rows,static fn($r)=>
                $r['state']==='SOURCE_TEXT_NEEDS_LOCALIZATION')),
            'updated_count'=>count($written),'apply'=>$apply,
            'manual_overrides_preserved'=>count(array_filter($rows,
                static fn($r)=>$r['state']==='LOCAL_EDITOR_OVERRIDE_PRESERVED')),
            'rows'=>$rows,'readback_verified'=>true,
            'production_mutation_performed'=>false];
        $out['receipt_sha256']=hash('sha256',wp_json_encode($out,JSON_UNESCAPED_UNICODE));
        return $out;
    }

    private static function term_language_readback(string $taxonomy,array $langs,string $default): array {
        $out=['taxonomy'=>$taxonomy,'source_terms_checked'=>0,
            'sample_truncated'=>false,'by_language'=>[],'mutation_performed'=>false];
        if(!taxonomy_exists($taxonomy)){
            $out['status']='TAXONOMY_NOT_REGISTERED';return $out;
        }
        $sitepress=$GLOBALS['sitepress']??null;
        $translatable=is_object($sitepress)&&method_exists($sitepress,'is_translated_taxonomy')
            ?(bool)$sitepress->is_translated_taxonomy($taxonomy):false;
        if(!$translatable){$out['status']='TAXONOMY_NOT_TRANSLATABLE';return $out;}
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,
            'number'=>26,'lang'=>$default]);
        if(is_wp_error($terms)||!is_array($terms)){
            $out['status']='TERM_SOURCE_LOOKUP_UNVERIFIED';return $out;
        }
        if(count($terms)>25){$out['sample_truncated']=true;$terms=array_slice($terms,0,25);}
        $base=[];
        foreach($terms as $term){
            if(!$term instanceof WP_Term)continue;
            $own=(string)apply_filters('wpml_element_language_code',null,[
                'element_id'=>(int)$term->term_id,'element_type'=>'tax_'.$taxonomy]);
            if($own===$default)$base[]=$term;
        }
        $out['source_terms_checked']=count($base);
        foreach($langs as $lang){
            $row=['linked_term_count'=>0,'missing_term_count'=>0,
                'same_text_review_count'=>0,'examples'=>[]];
            foreach($base as $term){
                $tid=(int)apply_filters('wpml_object_id',$term->term_id,$taxonomy,false,$lang);
                $mapped=$tid?self::exact_taxonomy_term($tid,$taxonomy):null;
                $actual=$tid?(string)apply_filters('wpml_element_language_code',null,[
                    'element_id'=>$tid,'element_type'=>'tax_'.$taxonomy]):'';
                if(!$mapped||$actual!==$lang){
                    $row['missing_term_count']++;
                    if(count($row['examples'])<6)$row['examples'][]=[
                        'source_term_id'=>$term->term_id,'state'=>'MISSING_TERM'];
                }else{
                    $row['linked_term_count']++;
                    if($lang!==$default&&trim((string)$mapped['name'])===trim((string)$term->name)){
                        // Same label can be correct for proper nouns. Review,
                        // never silently mark translated or auto-overwrite.
                        $row['same_text_review_count']++;
                        if(count($row['examples'])<6)$row['examples'][]=[
                            'source_term_id'=>$term->term_id,
                            'target_term_id'=>$tid,'state'=>'SAME_TEXT_NEEDS_REVIEW'];
                    }
                }
            }
            $out['by_language'][$lang]=$row;
        }
        $out['status']=$out['sample_truncated']?'SAMPLED_ONLY':'NATIVE_TERM_READBACK';
        return $out;
    }
    /**
     * Reuse the original WPML owner and its family verification to inspect
     * nested Elementor/JetEngine/JSF template identities. Bounded, read-only
     * and without any automatic language creation.
     */
    public static function dependency_family_manifest(int $source,int $max_families=12):array {
        $limit=max(1,min(24,$max_families));
        $policy=self::required_languages_readback();
        $queue=[['id'=>$source,'depth'=>0]];
        $seen=[];$families=[];$edges=[];$truncated=false;
        $maxDepth=5;
        while($queue){
            $item=array_shift($queue);
            $id=absint($item['id']);
            if(!$id||isset($seen[$id]))continue;
            if(count($seen)>=$limit){$truncated=true;break;}
            $seen[$id]=true;
            $post=get_post($id);
            if(!$post){$edges[]=['source_id'=>$id,'status'=>'MISSING_SOURCE_POST'];continue;}
            $readback=self::family_coverage_readback($id);
            $localeStates=[];
            foreach((array)$readback['by_language'] as $lang=>$locale){
                $localeStates[$lang]=[
                    'state'=>$locale['state'],
                    'post_id'=>$locale['post_id'],
                    'config_gaps'=>$locale['missing_native_configuration'],
                    'unresolved_ref_count'=>$locale['unresolved_reference_count'],
                    'policy_exception'=>$locale['policy_exception']
                ];
            }
            $families[$id]=[
                'post_type'=>$post->post_type,'source_language'=>self::language($id),
                'required_languages'=>$readback['required_languages'],
                'unmet_language_count'=>$readback['summary']['unmet_language_count'],
                'missing_languages'=>$readback['summary']['missing_languages'],
                'draft_languages'=>$readback['summary']['draft_languages'],
                'native_reference_and_configuration_gaps'=>
                    $readback['summary']['configuration_or_reference_gap_count'],
                'same_text_term_review_count'=>
                    $readback['summary']['same_text_terms_needing_review']??0,
                'missing_term_translations'=>
                    $readback['summary']['missing_locale_term_translations']??0,
                'structural_readback_pass'=>$readback['structural_readback_pass'],
                'locale_states'=>$localeStates
            ];
            $nodes=self::tree($id);
            $walk=static function($tree)use(&$walk,&$queue,&$edges,$id,$item,$maxDepth,&$truncated){
                foreach((array)$tree as $node){
                    if(!is_array($node))continue;
                    $settings=(array)($node['settings']??[]);
                    foreach($settings as $key=>$value){
                        $type=self::reference_key((string)$key);
                        if(!$type)continue;
                        foreach((is_array($value)?array_slice($value,0,24):[$value]) as $v){
                            if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<=0)continue;
                            if(count($edges)>=250){$truncated=true;return;}
                            $ref=(int)$v;$post=get_post($ref);
                            $valid=$post&&$post->post_type===$type;
                            $edges[]=[
                                'from_post_id'=>$id,'from_widget_id'=>(string)($node['id']??''),
                                'field'=>(string)$key,'to_post_id'=>$ref,
                                'expected_post_type'=>$type,
                                'state'=>$valid?'REFERENCED':'INVALID_REFERENCE'];
                            if($valid){
                                $policy_default=(string)apply_filters('wpml_default_language',null);
                $canonical=(int)apply_filters('wpml_object_id',
                    $ref,$type,false,$policy_default);
                                $root=$canonical&&get_post_type($canonical)===$type &&
                                    self::language($canonical)===$policy_default?$canonical:$ref;
                                if($item['depth']<$maxDepth)
                                    $queue[]=['id'=>$root,'depth'=>$item['depth']+1];
                                else $truncated=true;
                            }
                        }
                    }
                    if(!empty($node['elements']))$walk($node['elements']);
                    if($truncated)return;
                }
            };
            $walk($nodes);
        }
        ksort($families,SORT_NUMERIC);
        $unmet=0;$review=0;$missing=[];
        foreach($families as $id=>$family){
            $unmet+=$family['unmet_language_count'];
            $review+=$family['same_text_term_review_count'];
            foreach($family['missing_languages'] as $lang)
                if(count($missing)<200)$missing[]=['source_id'=>$id,
                    'post_type'=>$family['post_type'],'language'=>$lang];
        }
        $complete=(bool)$families&&$unmet===0&&!$truncated&&$policy['consistent'];
        $out=[
            'contract'=>'mad4b.wpml.dependency-family-manifest.v1',
            'root_source_id'=>$source,'required_languages'=>$policy['required'],
            'family_count'=>count($families),'families'=>$families,
            'dependency_edges'=>$edges,
            'missing_translation_job_candidates'=>$missing,
            'summary'=>[
                'unmet_locale_instances'=>$unmet,
                'same_text_term_translation_reviews'=>$review,
                'scope_truncated'=>$truncated,
                'languages_discovered_from_wpml'=>true,
                'all_families_structurally_ready'=>$complete
            ],
            'overall_reconciliation_pass_allowed'=>false,
            'render_interaction_verified'=>false,
            'automatic_translation_creation_performed'=>false,
            'mutation_performed'=>false
        ];
        $out['receipt_sha256']=hash('sha256',
            wp_json_encode($out,JSON_UNESCAPED_SLASHES));
        return $out;
    }
    /**
     * Missing languages are part of the receipt, even though the write
     * reconciler can only update translations that already exist.
     */
    public static function family_coverage_readback(int $source,array $pairs=[]):array {
        $policy=self::required_languages_readback();
        $post=get_post($source);
        $type=$post?(string)$post->post_type:'';
        $trid=$type?(int)apply_filters('wpml_element_trid',null,$source,'post_'.$type):0;
        $items=[];$unmet=0;$missing=[];$draft=[];$dependencyGaps=0;
        $source_cfg=(array)get_post_meta($source,'_listing_data',true);
        $source_listing_type=(string)get_post_meta($source,'_listing_type',true);
        $source_tree=(string)get_post_meta($source,'_elementor_data',true);
        $policy_override=get_option('mad4b_wpml_design_auto_sync_enabled_v1','yes')!=='yes'
            ||get_post_meta($source,'_mad4b_wpml_auto_sync_excluded',true)==='yes';
        foreach($policy['required'] as $lang){
            $id=(int)($pairs[$lang]??0);
            if(!$id)$id=(int)apply_filters('wpml_object_id',$source,$type,false,$lang);
            if($lang===$policy['default'])$id=$source;
            $p=$id?get_post($id):null;
            $identity=$p&&$p->post_type===$type&&self::language($id)===$lang
                &&$trid>0&&(int)apply_filters('wpml_element_trid',null,$id,'post_'.$type)===$trid;
            $status=!$id?'MISSING_TRANSLATION':
                (!$identity?'INVALID_WPML_IDENTITY':
                ($p->post_status==='publish'?'PUBLISHED':
                ($p->post_status==='draft'?'DRAFT_PENDING_REVIEW':'UNPUBLISHED')));
            if($status==='MISSING_TRANSLATION')$missing[]=$lang;
            if($status==='DRAFT_PENDING_REVIEW')$draft[]=$lang;
            $exception=$policy_override||($identity&&
                get_post_meta($id,'_mad4b_wpml_auto_sync_excluded',true)==='yes');
            $configuration=[];
            $ref=['issues'=>[],'unresolved_count'=>0,'truncated'=>false];
            if($identity&&$p->post_status==='publish'){
                if($source_cfg && (array)get_post_meta($id,'_listing_data',true)!==$source_cfg)
                    $configuration[]='_listing_data_MISSING_OR_DIVERGENT';
                if($source_listing_type&&
                    (string)get_post_meta($id,'_listing_type',true)!==$source_listing_type)
                    $configuration[]='_listing_type_MISSING_OR_DIVERGENT';
                if($source_tree!==''&&(string)get_post_meta($id,'_elementor_data',true)==='')
                    $configuration[]='_elementor_data_MISSING';
                if($source_tree!=='')$ref=self::linked_reference_gaps($source,$id,$lang);
            }
            $unmetHere=$status!=='PUBLISHED'||$exception||$configuration||
                $ref['unresolved_count']||$ref['truncated'];
            if($unmetHere)$unmet++;
            $dependencyGaps+=$ref['unresolved_count']+count($configuration);
            $items[$lang]=[
                'language'=>$lang,'post_id'=>$identity?$id:0,
                'state'=>$status,'policy_exception'=>(bool)$exception,
                'missing_native_configuration'=>$configuration,
                'reference_issues'=>$ref['issues'],
                'unresolved_reference_count'=>$ref['unresolved_count'],
                'reference_scan_truncated'=>$ref['truncated'],
                'reference_scan_count'=>$ref['inspected_refs']??0,
                'frontend_verified'=>false,'content_translation_verified'=>false];
        }
        $term_readback=['status'=>'NOT_APPLICABLE','mutation_performed'=>false];
        if(($source_cfg['source']??'')==='terms'){
            $term_readback=self::term_language_readback(
                (string)($source_cfg['tax']??''),$policy['required'],$policy['default']);
            foreach((array)($term_readback['by_language']??[]) as $term_lang){
                if(!empty($term_lang['missing_term_count']))$dependencyGaps+=(int)$term_lang['missing_term_count'];
            }
        }
        $termMissing=0;$termSameText=0;
        foreach((array)($term_readback['by_language']??[]) as $term_lang){
            $termMissing+=(int)($term_lang['missing_term_count']??0);
            $termSameText+=(int)($term_lang['same_text_review_count']??0);
        }
        $complete=(bool)$items&&!$unmet&&$policy['consistent']&&!$policy['truncated']
            &&!$termMissing&&!($term_readback['sample_truncated']??false);
        return ['contract'=>'mad4b.wpml.family-coverage.v1',
            'source_id'=>$source,'post_type'=>$type,
            'required_languages'=>$policy['required'],'language_policy'=>$policy,
            'by_language'=>$items,
            'summary'=>[
                'required'=>count($items),'missing_languages'=>$missing,
                'draft_languages'=>$draft,'unmet_language_count'=>$unmet,
                'configuration_or_reference_gap_count'=>$dependencyGaps,
                'missing_locale_term_translations'=>$termMissing,
                'same_text_terms_needing_review'=>$termSameText],
            'term_translation_coverage'=>$term_readback,
            'structural_readback_pass'=>$complete,
            'business_pass_allowed'=>false,
            'render_style_interaction_readback'=>'REQUIRED',
            'target_translation_creation_performed'=>false,
            'mutation_performed'=>false];
    }

    private static function language($id) {
        $data=apply_filters('wpml_post_language_details',null,(int)$id);
        return is_array($data) ? (string)($data['language_code']??'') : '';
    }
    private static function translations($id,$type) {
        $trid=apply_filters('wpml_element_trid',null,(int)$id,'post_'.$type);
        if(!$trid)return [];
        $refs=apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
        $out=[];
        foreach((array)$refs as $lang=>$o)if(is_object($o)&&!empty($o->element_id))$out[$lang]=(int)$o->element_id;
        // WPML's translations collection can omit drafts. Resolve through the
        // native WPML object-ID filter, then enforce the same source TRID,
        // language, type, and an explicitly unpublished draft status.
        // Never equate a draft with an approved/published translation.
        $active=(array)apply_filters('wpml_active_languages',null);
        foreach(array_keys($active) as $lang) {
            if(!is_string($lang)||isset($out[$lang])||$lang===self::language($id))continue;
            $candidate=(int)apply_filters('wpml_object_id',(int)$id,$type,false,$lang);
            if(!$candidate||$candidate===(int)$id||get_post_type($candidate)!==$type
                ||get_post_status($candidate)!=='draft'||self::language($candidate)!==$lang)continue;
            $trid2=(int)apply_filters('wpml_element_trid',null,$candidate,'post_'.$type);
            if($trid2!==(int)$trid)continue;
            $out[$lang]=$candidate;
        }
        return $out;
    }
    private static function canonical($id) {
        $post=get_post((int)$id);
        if(!$post||!self::eligible_type((string)$post->post_type))return false;
        return self::language($id)===(string)apply_filters('wpml_default_language',null);
    }
    private static function tree($id) {
        $raw=get_post_meta((int)$id,'_elementor_data',true);
        if(!is_string($raw)||$raw==='')return [];
        $parsed=json_decode($raw,true);
        return is_array($parsed)?$parsed:[];
    }
    private static function style_key($key,$rtl) {
        if($key==='__globals__'||$key==='custom_css')return true;
        if(preg_match('/(?:text|title|editor|label|content|description|html|shortcode|link|url|image|gallery|video|media|placeholder|query|condition|dynamic|source|currency|price|rate|nationality)/i',$key))return false;
        if($rtl&&preg_match('/(?:margin|padding|align|justify|direction|float|order|typography|font|transform)/i',$key))return false;
        return (bool)preg_match('/^(?:_?(?:margin|padding|width|height|align|flex|grid|gap|order|position|overflow|z_index|border|shadow|background|typography|font|color|opacity|css_classes|size|display|hide_on|sticky|animation|transform|custom_css|justify|direction|content_width|object_fit|space_between|column|row|section|container|jet_sticky|vertical_gap|horizontal_gap|columns|carousel|prev_|next_))/', $key);
    }
    private static function translated_object($source_id,$post_type,$lang) {
        $id=(int)apply_filters('wpml_object_id',(int)$source_id,$post_type,false,$lang);
        return $id && get_post_type($id)===$post_type ? $id : 0;
    }
    private static function reference_key($key) {
        $key=strtolower((string)$key);
        if(in_array($key,['template_id','popup_id','header_template','footer_template'],true))return 'elementor_library';
        if(in_array($key,['listing_id','lisitng_id','listing_item_id'],true))return 'jet-engine';
        if(in_array($key,['filter_id','filterid'],true))return 'jet-smart-filters';
        return '';
    }
    private static function content_key($key) {
        return (bool)preg_match('/(?:text|title|editor|label|content|description|html|placeholder|link|url|media|image|gallery|alt|caption|currency|price|rate|nationality|__dynamic__|conditions|shortcode)/i',(string)$key);
    }
    private static function sync_globals($source,$target) {
        foreach((array)$source as $k=>$v) {
            if(preg_match('/(?:color|typography|background|border|shadow|font)/i',(string)$k))$target[$k]=$v;
        }
        return $target;
    }
    private static function node($source,$target,$lang,&$issues,&$changed) {
        $source_s=(array)($source['settings']??[]);
        $target_s=(array)($target['settings']??[]);
        foreach($source_s as $k=>$v) {
            $type=self::reference_key($k);
            // JetSmartFilters stores filter_id as an array of WPML post IDs.
            // Resolve each member in its original order, not (int)$array=1.
            if($type&&is_array($v)) {
                if(!$v||count($v)>24||array_keys($v)!==range(0,count($v)-1)){
                    $issues[]=['code'=>'invalid_reference_array','field'=>$k,'lang'=>$lang,
                        'widget'=>$source['id']??''];continue;
                }
                $mapped=[];$all_valid=true;
                foreach($v as $position=>$source_id){
                    if(!is_scalar($source_id)||!ctype_digit((string)$source_id)
                        ||(int)$source_id<=0||get_post_type((int)$source_id)!==$type){
                        $issues[]=['code'=>'invalid_source_reference','field'=>$k,
                            'source_id'=>(int)$source_id,'lang'=>$lang,'widget'=>$source['id']??''];
                        $all_valid=false;continue;
                    }
                    $translated=self::translated_object((int)$source_id,$type,$lang);
                    if(!$translated){
                        $issues[]=['code'=>'missing_linked_reference','field'=>$k,
                            'source_id'=>(int)$source_id,'lang'=>$lang,'widget'=>$source['id']??''];
                        $all_valid=false;continue;
                    }
                    $mapped[]=is_int($source_id)?$translated:(string)$translated;
                }
                if($all_valid&&$mapped!==(array)($target_s[$k]??[])){
                    $target_s[$k]=$mapped;$changed++;
                }
                continue;
            }
            if($type&&is_numeric($v)&&(int)$v>0) {
                $translated=self::translated_object((int)$v,$type,$lang);
                if(!$translated) {
                    $shared=false;
                    if($type==='elementor_library'
                       &&class_exists('MAD4B_WPML_Translation_Need_V1')){
                        $need=MAD4B_WPML_Translation_Need_V1::source((int)$v);
                        $shared=!is_wp_error($need)
                           &&($need['classification']??'')==='shared_dynamic_provider';
                    }
                    if(!$shared || (string)($target_s[$k]??'')!==(string)$v)
                        $issues[]=['code'=>'missing_linked_reference','field'=>$k,'source_id'=>(int)$v,'lang'=>$lang,'widget'=>$source['id']??''];
                }elseif((string)($target_s[$k]??'')!==(string)$translated) {
                    $target_s[$k]=is_int($v)?$translated:(string)$translated;
                    $changed++;
                }
                continue;
            }
            if($k==='__globals__'&&is_array($v)) {
                $before=(array)($target_s[$k]??[]);
                $after=self::sync_globals($v,$before);
                if($after!==$before){$target_s[$k]=$after;$changed++;}
                continue;
            }
            if(!self::style_key((string)$k,$lang==='ar'))continue;
            if(!array_key_exists($k,$target_s)||$target_s[$k]!==$v){$target_s[$k]=$v;$changed++;}
        }
        $target['settings']=$target_s;
        $srcchildren=(array)($source['elements']??[]);
        $dstchildren=(array)($target['elements']??[]);
        if($srcchildren){
            $byid=[];foreach($dstchildren as $n)if(is_array($n)&&isset($n['id']))$byid[(string)$n['id']]=$n;
            $merged=[];$used=[];
            foreach($srcchildren as $sn){
                $sid=(string)($sn['id']??'');
                if(!$sid||!isset($byid[$sid])){
                    $issues[]=['code'=>'missing_widget_translation','lang'=>$lang,'widget'=>$sid,'type'=>$sn['widgetType']??$sn['elType']??''];
                    continue;
                }
                $used[$sid]=true;$tn=$byid[$sid];
                if(($sn['widgetType']??$sn['elType']??'')!==($tn['widgetType']??$tn['elType']??'')){
                    $issues[]=['code'=>'widget_type_conflict','lang'=>$lang,'widget'=>$sid];$merged[]=$tn;continue;
                }
                $merged[]=self::node($sn,$tn,$lang,$issues,$changed);
            }
            foreach($dstchildren as $dn)if(!isset($used[(string)($dn['id']??'')]))$merged[]=$dn;
            if($merged!==$dstchildren){$target['elements']=$merged;$changed++;}
        }
        return $target;
    }
    private static function elements($src,$dst,$lang,&$issues,&$changed) {
        $byid=[];foreach((array)$dst as $n)if(is_array($n)&&isset($n['id']))$byid[(string)$n['id']]=$n;
        $out=[];$used=[];
        foreach((array)$src as $n){
            $id=(string)($n['id']??'');
            if(!$id||!isset($byid[$id])){
                $issues[]=['code'=>'missing_root_widget_translation','lang'=>$lang,'widget'=>$id];
                continue;
            }
            $used[$id]=true;
            $out[]=self::node($n,$byid[$id],$lang,$issues,$changed);
        }
        foreach((array)$dst as $n)if(!isset($used[(string)($n['id']??'')]))$out[]=$n;
        if($out!==$dst)$changed++;
        return $out;
    }
    private static function provider_keys($type) {
        if($type==='jet-engine')return ['_listing_data'];
        if($type!=='jet-smart-filters')return [];
        return [
            '_filter_type','_data_source','_is_hierarchical','_ih_source_map','_ih_terms_orderby',
            '_ih_terms_order',
            '_query_builder_value_prop','_query_builder_label_prop','_source_taxonomy',
            '_terms_orderby','_terms_order','_terms_relational_operator','_source_callback',
            '_source_min','_source_max','_source_step','_range_inputs_enabled',
            '_source_post_type','_glossary_id','_source_custom_field','_source_get_from_field_data',
            '_custom_field_source_plugin','_is_custom_checkbox','_use_exclude_include',
            '_data_exclude_include','_query_var','_is_custom_query_var','_custom_query_var',
            '_query_compare','_is_default_filter_value','_default_filter_value',
            '_show_empty_terms','_only_child','_group_by_parent','_group_collapsible',
            '_terms_orderby_meta_value','_date_source','_date_type','_date_format',
            '_date_period_type','_date_available_range','_date_period_start_end_enabled',
            '_values_decimal_num','_values_decimal_sep','_values_thousand_sep',
        ];
    }
    private static function provider_plan($source,$target,$type) {
        $plan=[];
        foreach(self::provider_keys($type) as $k) {
            if(!metadata_exists('post',$source,$k))continue;
            $value=get_post_meta($source,$k,true);
            if(!metadata_exists('post',$target,$k)||get_post_meta($target,$k,true)!==$value)$plan[$k]=$value;
        }
        return $plan;
    }
    private static function backup($src,$dst,$kind,$old) {
        $hash=substr(hash('sha256',wp_json_encode($old)),0,14);
        $key='mad4b_wpml_design_backup_'.$dst.'_'.$kind.'_'.$hash;
        if(get_option($key,false)===false){
            // Fourth argument is autoload. Keep snapshot data readable with
            // get_option(), but never load rollback payloads for every visitor.
            $saved=add_option($key,['src'=>$src,'dst'=>$dst,'at'=>gmdate('c'),'value'=>$old],'',false);
            if(!$saved)return false;
        }
        return $key;
    }
    /**
     * Native original-owner target CAS. The source and target must still
     * belong to the same WPML family; never applies to other translations.
     * No new rights, ContentJob, or mutating principal are granted here.
     */
    /**
     * A single proof-backed WPML Elementor reference repair. Unlike generic
     * layout sync, this never rewrites unrelated target widgets or text.
     */
    public static function reconcile_exact_dependency(int $source,int $target,
        string $widget_id,string $field,bool $apply=false):array|WP_Error {
        $type=self::reference_key($field);
        $source_post=get_post($source);$target_post=get_post($target);
        if(!$type||!$source_post||!$target_post||
           $source_post->post_type!==$target_post->post_type||
           $source_post->post_status!=='publish'||
           $target_post->post_status!=='publish'||
           !self::canonical($source)||!mad4b_wpml_auto_sync_allowed_v1($source,$target))
            return new WP_Error('mad4b_wpml_selective_source_or_target_invalid');
        $locale=self::language($target);
        if(!$locale||$locale===self::language($source)||
           (int)apply_filters('wpml_object_id',$source,$source_post->post_type,
               false,$locale)!==$target)
            return new WP_Error('mad4b_wpml_selective_wpml_identity_mismatch');
        if($apply && (!self::enabled()||!MAD4B_SCP_Policy::can_mutate()||
            !current_user_can('edit_post',$target)))
            return new WP_Error('mad4b_wpml_selective_apply_permission_denied');
        $sourceTree=self::tree($source);$targetTree=self::tree($target);
        $find=static function($tree)use($widget_id){
            $match=[];
            $walk=static function($nodes)use(&$walk,&$match,$widget_id){
                foreach((array)$nodes as $node){
                    if(!is_array($node))continue;
                    if(($node['id']??'')===$widget_id)$match[]=$node;
                    if(!empty($node['elements']))$walk($node['elements']);
                }
            };
            $walk($tree);
            return count($match)===1?$match[0]:null;
        };
        $original=$find($sourceTree);$local=$find($targetTree);
        if(!$original||!$local||
           ($original['widgetType']??'')!==($local['widgetType']??''))
            return new WP_Error('mad4b_wpml_selective_widget_not_unique');
        $ref=$original['settings'][$field]??null;
        $previous=$local['settings'][$field]??null;
        if(!is_scalar($ref)||!ctype_digit((string)$ref)||!$ref||
           !is_scalar($previous)||!ctype_digit((string)$previous))
            return new WP_Error('mad4b_wpml_selective_non_scalar_reference');
        $expected=(int)apply_filters('wpml_object_id',(int)$ref,$type,false,$locale);
        $expectedPost=$expected?get_post($expected):null;
        if(!$expectedPost||$expectedPost->post_type!==$type||
           $expectedPost->post_status!=='publish'||
           self::language($expected)!==$locale)
            return new WP_Error('mad4b_wpml_selective_dependency_unpublished');
        if((int)$previous!==$expected&&(int)$previous!==(int)$ref)
            return new WP_Error('mad4b_wpml_selective_manual_reference_preserved');
        $missingType=$source_post->post_type==='jet-engine'
            &&get_post_meta($source,'_listing_type',true)!==''
            &&!metadata_exists('post',$target,'_listing_type');
        $wouldChange=(int)$previous!==$expected;
        $fixed=0;$changedTree=$targetTree;$hiddenRemoved=false;
        $walk=static function(&$nodes)use(&$walk,&$fixed,&$hiddenRemoved,
            $widget_id,$field,$previous,$expected,$wouldChange){
            foreach($nodes as &$node){
                if(!is_array($node))continue;
                if(($node['id']??'')===$widget_id){
                    $current=$node['settings'][$field]??null;
                    if(!is_scalar($current)||(string)$current!==(string)$previous)
                        throw new RuntimeException('TARGET_WIDGET_CHANGED');
                    if($wouldChange){
                        $node['settings'][$field]=is_int($current)?$expected:(string)$expected;
                        $fixed++;
                    }
                    // Technical Elementor Template widgets can be unhidden
                    // after their single native template ref is resolved.
                    if(($node['widgetType']??'')==='template'&&$wouldChange){
                        $classes=(string)($node['settings']['_css_classes']??'');
                        if(str_contains($classes,'mad4b-wpml-needs-translation')){
                            $new=trim(str_replace('mad4b-wpml-needs-translation','',$classes));
                            if($new==='')unset($node['settings']['_css_classes']);
                            else $node['settings']['_css_classes']=$new;
                            $hiddenRemoved=true;
                        }
                    }
                }
                if(!empty($node['elements']))$walk($node['elements']);
            }
        };
        try{$walk($changedTree);}catch(Throwable $e){
            return new WP_Error('mad4b_wpml_selective_widget_changed');
        }
        if($fixed!==($wouldChange?1:0))
            return new WP_Error('mad4b_wpml_selective_widget_edit_count_invalid');
        $plan=['contract'=>'mad4b.wpml.selective-reference-inheritance.v1',
            'source_id'=>$source,'target_id'=>$target,'language'=>$locale,
            'widget_id'=>$widget_id,'field'=>$field,'old_reference'=>(int)$previous,
            'new_reference'=>$expected,'would_change'=>$wouldChange,
            'missing_listing_type'=>$missingType,
            'hidden_pending_class_removal'=>$hiddenRemoved,'applied'=>false,
            'other_widgets_or_editorial_text_mutated'=>false];
        if(!$apply||(!$wouldChange&&!$missingType))return $plan;
        $before=(string)get_post_meta($target,'_elementor_data',true);
        $oldHash=hash('sha256',$before);
        $backup='mad4b_wpml_selective_backup_'.$target.'_'.substr($oldHash,0,20);
        if(get_option($backup,null)===null&&!add_option($backup,
            ['origin'=>home_url(),'source_id'=>$source,'target_id'=>$target,
             'tree'=>$before,'missing_listing_type'=>$missingType],'',false))
            return new WP_Error('mad4b_wpml_selective_backup_failed');
        $new=wp_json_encode($changedTree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($new)||json_decode($new,true)!==$changedTree)
            return new WP_Error('mad4b_wpml_selective_json_invalid');
        if($wouldChange&&!update_post_meta($target,'_elementor_data',wp_slash($new)))
            return new WP_Error('mad4b_wpml_selective_elementor_write_failed');
        $typeAdded=false;
        if($missingType)$typeAdded=add_post_meta($target,'_listing_type',
            get_post_meta($source,'_listing_type',true),true);
        $check=self::tree($target);
        if($check!==$changedTree||($missingType&&!$typeAdded)){
            if($wouldChange)update_post_meta($target,'_elementor_data',wp_slash($before));
            if($typeAdded)delete_post_meta($target,'_listing_type');
            return new WP_Error('mad4b_wpml_selective_readback_rollback');
        }
        clean_post_cache($target);
        do_action('litespeed_purge_post',$target);
        $plan['applied']=true;
        $plan['backup_option']=$backup;
        $plan['readback_verified']=true;
        return $plan;
    }

    public static function reconcile_exact($source,$target,$apply=false,
        $expected_source_sha256='',$expected_target_sha256='') {
        $source=(int)$source;$target=(int)$target;
        if(!$source||!$target||$source===$target||
            !self::enabled()||!self::canonical($source)||
            get_post_type($source)!==get_post_type($target)||
            !self::eligible_type((string)get_post_type($source))||
            !mad4b_wpml_auto_sync_allowed_v1($source,$target))
            return new WP_Error('mad4b_wpml_exact_target_scope_denied');
        $src=(string)get_post_meta($source,'_elementor_data',true);
        $dst=(string)get_post_meta($target,'_elementor_data',true);
        if($src===''||$dst===''||
            !preg_match('/^[a-f0-9]{64}$/D',(string)$expected_source_sha256)||
            !preg_match('/^[a-f0-9]{64}$/D',(string)$expected_target_sha256)||
            !hash_equals(hash('sha256',$src),$expected_source_sha256)||
            !hash_equals(hash('sha256',$dst),$expected_target_sha256))
            return new WP_Error('mad4b_wpml_exact_target_stale_or_missing');
        if($apply && (!current_user_can('edit_post',$source)||
                       !current_user_can('edit_post',$target)))
            return new WP_Error('mad4b_wpml_exact_target_editor_denied');
        return self::reconcile($source,(bool)$apply,$target);
    }
    public static function reconcile($source,$apply=false,$only_target=0) {
        $source=(int)$source;$post=get_post($source);
        if(!self::enabled()||!self::canonical($source)||!self::eligible_type((string)$post->post_type))return new WP_Error('mad4b_wpml_design_invalid_source');
        if(!mad4b_wpml_auto_sync_allowed_v1($source))return new WP_Error('mad4b_wpml_design_source_excluded');
        $type=$post->post_type;$src=self::tree($source);
        $pairs=self::translations($source,$type);
        $family_coverage=self::family_coverage_readback($source,$pairs);
        $only_target=(int)$only_target;
        if($only_target && !in_array($only_target,array_values($pairs),true))
            return new WP_Error('mad4b_wpml_exact_target_not_in_native_family');
        $results=[];$affected=0;$unresolved=0;
        foreach($pairs as $lang=>$target) {
            if($lang===self::language($source)||!get_post($target)||
               ($only_target && (int)$target!==$only_target))continue;
            if(!mad4b_wpml_auto_sync_allowed_v1($source,$target)) {
                $results[]=['lang'=>$lang,'target'=>$target,'skipped'=>'post_excluded','would_update'=>false,'updated'=>false,'issues'=>[]];
                continue;
            }
            $issues=[];$changes=0;$meta=self::provider_plan($source,$target,$type);
            $dst=self::tree($target);$out=$dst;
            if($src&&$dst) {
                if(class_exists('MAD4B_WPML_Structural_Sync_V2') && get_option('mad4b_wpml_structural_sync_v2_enabled','no')==='yes')
                    $out=MAD4B_WPML_Structural_Sync_V2::plan($src,$dst,(string)$lang,$source,(int)$target,$issues,$changes);
                else $out=self::elements($src,$dst,$lang,$issues,$changes);
            }
            elseif($src&&!$dst)$issues[]=['code'=>'empty_translated_elementor_tree','lang'=>$lang];
            $meta_count=count($meta);$changed=($out!==$dst)||$meta_count>0;
            $row=['lang'=>$lang,'target'=>$target,'would_update'=>$changed,'updated'=>false,'elementor_changes'=>$changes,'provider_meta_keys'=>array_keys($meta),'issues'=>$issues];
            $unresolved+=count(array_filter($issues,static fn($issue)=>!in_array($issue['code']??'',['widget_created_from_master'],true)));
            if($apply&&$changed){
                if(self::$busy)return new WP_Error('mad4b_wpml_design_recursion');
                $old=[];if($out!==$dst)$old['_elementor_data']=get_post_meta($target,'_elementor_data',true);
                foreach($meta as $k=>$v)$old[$k]=['exists'=>metadata_exists('post',$target,$k),'value'=>get_post_meta($target,$k,true)];
                $back=self::backup($source,$target,'reconcile',$old);
                if(!$back)return new WP_Error('mad4b_wpml_design_backup_failed');
                self::$busy=true;
                try {
                    if($out!==$dst)update_post_meta($target,'_elementor_data',wp_slash(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
                    foreach($meta as $k=>$v)update_post_meta($target,$k,$v);
                } finally {self::$busy=false;}
                if($out!==$dst && self::tree($target)!==$out)return new WP_Error('mad4b_wpml_design_elementor_readback_failed');
                foreach($meta as $k=>$v)if(get_post_meta($target,$k,true)!==$v)return new WP_Error('mad4b_wpml_design_provider_readback_failed');
                if($out!==$dst&&class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
                    try{ $css=new \Elementor\Core\Files\CSS\Post($target);$css->delete();}catch(\Throwable $e){$row['css_warning']='regeneration_required';}
                }
                clean_post_cache($target);
                $row['updated']=true;$row['rollback_option']=$back;
                $affected++;
            }
            $results[]=$row;
        }
        return ['contract'=>'mad4b.wpml.design-reconciliation.v1',
            'source'=>$source,'type'=>$type,'apply'=>(bool)$apply,
            'updated_translations'=>$affected,'unresolved_refs_or_widgets'=>$unresolved,
            'translations'=>$results,
            'family_coverage'=>$family_coverage,
            'required_languages'=>$family_coverage['required_languages'],
            'unmet_language_count'=>$family_coverage['summary']['unmet_language_count'],
            'full_reconciliation_pass'=>false,
            'missing_locales_create_content_job_candidates_only'=>true,
            'localized_text_overwritten'=>false,'rates_overwritten'=>false];
    }
    public static function enqueue($id) {
        if(self::$busy||!self::enabled()||!self::canonical($id)
            ||!mad4b_wpml_auto_sync_allowed_v1($id))return;
        $queue=get_option(self::QUEUE,[]);
        if(!is_array($queue))$queue=[];
        $queue[(int)$id]=time();
        if(count($queue)>60)$queue=array_slice($queue,-60,null,true);
        update_option(self::QUEUE,$queue,false);
        if(!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+25,self::EVENT);
    }
    /** Reconcile any newly registered type after a canonical source is edited. */
    public static function on_save($id,$post,$update) {
        if(!$post instanceof \WP_Post || wp_is_post_revision($id) || wp_is_post_autosave($id))return;
        if(!self::eligible_type((string)$post->post_type))return;
        if(!metadata_exists('post',$id,'_elementor_data')
            && !metadata_exists('post',$id,'_listing_data')
            && !metadata_exists('post',$id,'_filter_type'))return;
        self::enqueue((int)$id);
    }
    public static function on_meta($mid,$id,$key,$value) {
        if($key==='_elementor_data'||$key==='_elementor_page_settings'||$key==='_listing_data'
           ||str_starts_with((string)$key,'_filter_')||str_starts_with((string)$key,'_source_')
           ||in_array($key,['_query_var','_is_custom_query_var','_custom_query_var'],true))self::enqueue($id);
    }
    public static function run_queue() {
        if(!self::enabled())return;
        $queue=get_option(self::QUEUE,[]);
        if(!is_array($queue)||!$queue)return;
        $subset=array_slice($queue,0,8,true);$audit=get_option(self::AUDIT,[]);
        if(!is_array($audit))$audit=[];
        foreach($subset as $id=>$time) {
            $result=self::reconcile((int)$id,true);
            $audit[]=['at'=>gmdate('c'),'source'=>(int)$id,'result'=>is_wp_error($result)?$result->get_error_code():['updated'=>$result['updated_translations'],'issues'=>$result['unresolved_refs_or_widgets']]];
            unset($queue[$id]);
        }
        update_option(self::QUEUE,$queue,false);
        update_option(self::AUDIT,array_slice($audit,-50),false);
        if($queue&&!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+25,self::EVENT);
    }
    public static function cli($args,$assoc) {
        $mode=$args[0]??'status';
        if($mode==='status'){ \WP_CLI::line(wp_json_encode(['enabled'=>self::enabled(),'queue'=>get_option(self::QUEUE,[]),'audit'=>array_slice((array)get_option(self::AUDIT,[]),-6)]));return; }
        if($mode==='run'){self::run_queue();\WP_CLI::success('Queued reconciliation pass complete');return;}
        if(!in_array($mode,['preview','sync'],true)||empty($args[1]))\WP_CLI::error('Usage: wp mad4b-design-sync status|run|preview <source_id>|sync <source_id>');
        $result=self::reconcile((int)$args[1],$mode==='sync');
        if(is_wp_error($result))\WP_CLI::error($result->get_error_code());
        \WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    /** Native WordPress options form: only applies within this WPML site. */
    public static function admin_settings() {
        register_setting('mad4b_wpml_design_settings','mad4b_wpml_design_auto_sync_enabled_v1',[
            'type'=>'string','default'=>'yes',
            'sanitize_callback'=>static fn($v)=>($v==='yes'?'yes':'no'),
        ]);
        register_setting('mad4b_wpml_design_settings','mad4b_wpml_design_excluded_post_types_v1',[
            'type'=>'array','default'=>[],
            'sanitize_callback'=>static function($submitted) {
                $requested=is_array($submitted)?$submitted:[];
                $registered=array_fill_keys(array_values(get_post_types([], 'names')),true);
                return array_values(array_unique(array_filter(array_map('strval',$requested),
                    static fn($type)=>isset($registered[$type]))));
            },
        ]);
    }
    public static function admin_menu() {
        add_options_page('WPML Design Sync','WPML Design Sync','manage_options','mad4b-wpml-design-sync',[__CLASS__,'settings_page']);
    }
    public static function settings_page() {
        if(!current_user_can('manage_options'))return;
        $yes=mad4b_wpml_auto_sync_allowed_v1();
        echo '<div class="wrap"><h1>WPML Design Sync</h1>';
        echo '<p>Synchronize source designs across linked translations on this WordPress site. No cross-company content distribution.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mad4b_wpml_design_settings');
        echo '<input type="hidden" name="mad4b_wpml_design_auto_sync_enabled_v1" value="no">';
        echo '<label style="display:block;margin:18px 0;font-size:17px"><input type="checkbox" name="mad4b_wpml_design_auto_sync_enabled_v1" value="yes" '.checked($yes,true,false).'> <strong>Enable automatic synchronization (Master)</strong></label>';
        echo '<p>Default: enabled. Turning it off stops automatic design writes by engines 301, 305 and 349. Existing translations remain unchanged.</p>';
        echo '<p>Every post, template, Listing and Filter can be excluded individually from the edit screen. Localized text and pricing are never overwritten.</p>';
        echo '<h2>Manually excluded post types</h2><p>New registered post types are included automatically. Checked types are excluded from design synchronization.</p>';
        $excluded=(array)get_option('mad4b_wpml_design_excluded_post_types_v1',[]);
        echo '<input type="hidden" name="mad4b_wpml_design_excluded_post_types_v1" value="">';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;max-width:1000px">';
        foreach(get_post_types([], 'objects') as $type=>$object) {
            $label=isset($object->labels->name)?(string)$object->labels->name:$type;
            echo '<label><input type="checkbox" name="mad4b_wpml_design_excluded_post_types_v1[]" value="'.esc_attr($type).'" '.checked(in_array($type,$excluded,true),true,false).'> '.esc_html($label).' <code>'.esc_html($type).'</code></label>';
        }
        echo '</div>';
        submit_button('Save synchronization preferences');
        echo '</form></div>';
    }
    public static function metaboxes() {
        foreach(get_post_types(['show_ui'=>true],'names') as $type) {
            if(in_array($type,['attachment','revision'],true))continue;
            add_meta_box('mad4b_wpml_auto_sync','WPML Design Sync',[__CLASS__,'metabox'],$type,'side','default');
        }
    }
    public static function metabox($post) {
        wp_nonce_field('mad4b_wpml_auto_sync_'.$post->ID,'mad4b_wpml_sync_nonce');
        $excluded=get_post_meta($post->ID,'_mad4b_wpml_auto_sync_excluded',true)==='yes';
        echo '<label><input type="checkbox" name="mad4b_wpml_auto_sync_excluded" value="yes" '.checked($excluded,true,false).'> <strong>Exclude this item from automatic design sync</strong></label>';
        echo '<p class="description">Overrides the Master Switch. Applies to this post whether it is a source or a translation; content stays editable.</p>';
    }
    public static function save_exclusion($id) {
        if(wp_is_post_revision($id)||wp_is_post_autosave($id))return;
        if(!isset($_POST['mad4b_wpml_sync_nonce']))return;
        $nonce=sanitize_text_field(wp_unslash($_POST['mad4b_wpml_sync_nonce']));
        if(!wp_verify_nonce($nonce,'mad4b_wpml_auto_sync_'.$id)||!current_user_can('edit_post',$id))return;
        $excluded=isset($_POST['mad4b_wpml_auto_sync_excluded'])&&$_POST['mad4b_wpml_auto_sync_excluded']==='yes'?'yes':'no';
        update_post_meta($id,'_mad4b_wpml_auto_sync_excluded',$excluded);
    }
    public static function boot() {
        if(!self::runtime_site())return;
        add_action('admin_init',[__CLASS__,'admin_settings']);
        add_action('admin_menu',[__CLASS__,'admin_menu']);
        add_action('add_meta_boxes',[__CLASS__,'metaboxes']);
        add_action('save_post',[__CLASS__,'save_exclusion'],95);
        add_action('added_post_meta',[__CLASS__,'on_meta'],95,4);
        add_action('updated_post_meta',[__CLASS__,'on_meta'],95,4);
        add_action('save_post',[__CLASS__,'on_save'],95,3);
        add_action('elementor/editor/after_save',static function($id){self::enqueue((int)$id);},95,2);
        add_action('save_post_jet-smart-filters',static function($id){self::enqueue((int)$id);},100);
        add_action('save_post_jet-engine',static function($id){self::enqueue((int)$id);},100);
        add_action('init',static function(){
            if(self::enabled()&&get_option(self::QUEUE,[])&&!wp_next_scheduled(self::EVENT))
                wp_schedule_single_event(time()+20,self::EVENT);
        },85);
        add_action(self::EVENT,[__CLASS__,'run_queue']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-design-sync',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_WPML_Design_Reconciliation_V1','boot'],75);
require_once __DIR__.'/349-mad4b-wpml-visible-ui-alerts.php';
