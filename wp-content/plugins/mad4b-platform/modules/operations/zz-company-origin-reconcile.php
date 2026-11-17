<?php
/**
 * Plugin Name: Company Origin Reconcile (Portable)
 * Description: Dynamic WPML-aware tour origin completeness audit and native repair, applicable to every enrolled site.
 * Version: 1.0.0
 * MAD4B Provider Role: tenant-portable
 */
if (!defined('ABSPATH')) { exit; }

if (!class_exists('Company_Portable_Origin_Reconcile')) {
final class Company_Portable_Origin_Reconcile {
    private const KEYS = ['tour_origin_location_term','tour_origin_address','tour_origin_address_place_id','tour_origin_address_lat','tour_origin_address_lng'];

    public static function languages(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'icl_languages';
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) return [];
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array)$wpdb->get_col("SELECT code FROM `{$table}` WHERE active=1 ORDER BY code")))));
    }
    private static function origin_for(int $post_id, string $lang, string $taxonomy): array {
        if (!function_exists('tk_tour_origin_sync_extract_id')) return ['reason'=>'ORIGIN_SYNC_PROVIDER_MISSING'];
        $selected = tk_tour_origin_sync_extract_id(get_post_meta($post_id,'tour_origin_location_term',true));
        $sources=[];
        if ($selected) {
            $ref=tk_tour_origin_sync_map_term_to_reference_lang($selected,$taxonomy);
            if (get_term($ref,$taxonomy) instanceof WP_Term) $sources[$ref]=true;
        } else {
            $post_type=tk_tour_origin_sync_post_type();
            $trid=apply_filters('wpml_element_trid',null,$post_id,'post_'.$post_type);
            $translations=$trid?apply_filters('wpml_get_element_translations',[], $trid,'post_'.$post_type):[];
            foreach ((array)$translations as $candidate) {
                $peer=(int)($candidate->element_id??0);
                if (!$peer || $peer===$post_id || get_post_status($peer)!=='publish') continue;
                $peer_term=tk_tour_origin_sync_extract_id(get_post_meta($peer,'tour_origin_location_term',true));
                if (!$peer_term) continue;
                $ref=tk_tour_origin_sync_map_term_to_reference_lang($peer_term,$taxonomy);
                if (get_term($ref,$taxonomy) instanceof WP_Term) $sources[$ref]=true;
            }
        }
        if (count($sources)!==1) return ['reason'=>count($sources)?'CONFLICTING_ORIGIN_SOURCES':'MISSING_VERIFIED_ORIGIN_TERM'];
        $ref=(int)array_key_first($sources);
        if (sanitize_key((string)get_term_meta($ref,'destination_type',true))==='continent') return ['reason'=>'ORIGIN_IS_CONTINENT'];
        $term=apply_filters('wpml_object_id',$ref,$taxonomy,false,$lang);
        $term=$term?(int)$term:0;
        $translated=$term?get_term($term,$taxonomy):null;
        if (!$translated instanceof WP_Term || is_wp_error($translated)) return ['reason'=>'MISSING_ORIGIN_TERM_TRANSLATION'];
        $meta=tk_tour_origin_sync_read_location_term_meta($ref,$taxonomy);
        [$lat,$lng]=tk_tour_origin_sync_parse_lat_lng($meta['location']);
        // A label fallback is not a geocoded location; require original term meta.
        $address=trim((string)get_term_meta($ref,'google_address',true));
        $place=trim((string)get_term_meta($ref,'google_place_id',true));
        if (!$address || !$place || !is_numeric($lat) || !is_numeric($lng) || abs((float)$lat)>90 || abs((float)$lng)>180)
            return ['reason'=>'INCOMPLETE_VERIFIED_ORIGIN_GEO'];
        $expected=['tour_origin_location_term'=>(string)$term,
            'tour_origin_address'=>sanitize_text_field($address),
            'tour_origin_address_place_id'=>sanitize_text_field($place),
            'tour_origin_address_lat'=>sanitize_text_field($lat),
            'tour_origin_address_lng'=>sanitize_text_field($lng)];
        $actual=[]; foreach(self::KEYS as $key) $actual[$key]=(string)get_post_meta($post_id,$key,true);
        if ($selected && $selected!==$term) return ['reason'=>'LOCAL_ORIGIN_TERM_CONFLICT'];
        if ($actual===$expected) {
            // Metadata alone does not make a destination selectable. Require
            // the native taxonomy relationship in the post's WPML language.
            $attached=wp_get_object_terms($post_id,$taxonomy,['fields'=>'ids']);
            if(is_wp_error($attached))return ['reason'=>'ORIGIN_NATIVE_TERMS_UNREADABLE'];
            if(!in_array($term,array_map('intval',$attached),true))
                return ['reason'=>'ELIGIBLE','expected'=>$expected,
                    'reference_term'=>$ref,'relation_only'=>true];
            return ['reason'=>'ALREADY_RECONCILED'];
        }
        // Existing intentional coordinates/addresses must not be replaced silently.
        foreach (self::KEYS as $key) {
            if ($actual[$key]!=='' && $actual[$key]!==$expected[$key])
                return ['reason'=>'SOURCE_VALUE_DRIFT_REQUIRES_CONTENT_REVIEW','field'=>$key];
        }
        return ['reason'=>'ELIGIBLE','expected'=>$expected,'reference_term'=>$ref];
    }
    public static function scan(): array {
        $type=function_exists('tk_tour_origin_sync_post_type')?tk_tour_origin_sync_post_type():'tours-and-activities';
        $taxonomy=function_exists('tk_tour_origin_sync_taxonomy')?tk_tour_origin_sync_taxonomy():'destinations';
        $reference=function_exists('tk_tour_origin_sync_reference_lang')?tk_tour_origin_sync_reference_lang():'en';
        $langs=self::languages();
        $report=['contract'=>'company.portable-origin-reconcile.v1','site_origin'=>home_url(),
            'post_type'=>$type,'taxonomy'=>$taxonomy,'reference_lang'=>$reference,
            'target_languages'=>array_values(array_diff($langs,[$reference])),
            'eligible'=>[],'unresolved'=>[],'already_reconciled'=>0,'checked'=>0];
        if (!post_type_exists($type) || !taxonomy_exists($taxonomy) || !$langs || !has_filter('wpml_object_id')) {
            $report['unresolved'][]=['reason'=>'REQUIRED_POST_TYPE_TAXONOMY_OR_WPML_NOT_READY'];return $report;
        }
        $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
        $beforeLanguage=(string)apply_filters('wpml_current_language',null);
        try {
        foreach($ids as $raw) {
            $id=(int)$raw;
            $lang=(string)apply_filters('wpml_element_language_code',null,['element_id'=>$id,'element_type'=>'post_'.$type]);
            if (!$lang || !in_array($lang,$report['target_languages'],true)) continue;
            $report['checked']++;
            do_action('wpml_switch_language',$lang);
            $row=self::origin_for($id,$lang,$taxonomy);
            $reason=$row['reason'];
            if ($reason==='ALREADY_RECONCILED') {$report['already_reconciled']++;continue;}
            $item=['post_id'=>$id,'language'=>$lang,'reason'=>$reason];
            if ($reason==='ELIGIBLE') {
                $item['expected']=$row['expected'];$item['reference_term']=$row['reference_term'];
                if(!empty($row['relation_only']))$item['relation_only']=true;
                $report['eligible'][]=$item;
            } else {
                if(isset($row['field']))$item['field']=$row['field'];
                $report['unresolved'][]=$item;
            }
        }
        } finally {
            if($beforeLanguage!=='')do_action('wpml_switch_language',$beforeLanguage);
        }
        $report['eligible_count']=count($report['eligible']);
        $report['unresolved_count']=count($report['unresolved']);
        return $report;
    }
    private static function snapshot(int $id): array {
        $snapshot=[];foreach(self::KEYS as $key) $snapshot[$key]=get_post_meta($id,$key,false);
        return $snapshot;
    }
    private static function restore(int $id,array $data): void {
        foreach(self::KEYS as $key) {
            delete_post_meta($id,$key);
            foreach($data[$key] as $value) add_post_meta($id,$key,$value);
        }
    }
    public static function apply(array $plan): array {
        if (!defined('WP_CLI') || !WP_CLI || !get_current_user_id()) throw new RuntimeException('AUTHENTICATED_WORDPRESS_ACTOR_REQUIRED');
        $rows=$plan['eligible'];
        foreach($rows as $row) if (!current_user_can('edit_post',(int)$row['post_id'])) throw new RuntimeException('ACTOR_CANNOT_EDIT_POST');
        global $wpdb;
        $lock='company-origin-reconcile:'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)throw new RuntimeException('RECONCILE_BUSY');
        $snapshots=[];$changed=[];$rowLanguages=[];$termSnapshots=[];$termRelationsAdded=[];
        $originalLanguage=(string)apply_filters('wpml_current_language',null);
        $allowedLanguages=self::languages();
        try {
            // Recalculate after obtaining lock; source data must not drift between plan and commit.
            $fresh=self::scan();
            if ($fresh['eligible']!==$rows)throw new RuntimeException('RECONCILE_PLAN_CHANGED');
            foreach($rows as $row) {
                $id=(int)$row['post_id'];
                $language=(string)($row['language']??'');
                if(!in_array($language,$allowedLanguages,true))throw new RuntimeException('UNKNOWN_POST_TRANSLATION_LANGUAGE_'.$id);
                $rowLanguages[$id]=$language;
                $snapshots[$id]=self::snapshot($id);
            }
            foreach($rows as $row){
                $id=(int)$row['post_id'];
                // WPML translates taxonomy term reads to the active language.
                // The native origin selection guard must see the same language
                // as the post, or it rejects a valid translated term ID.
                do_action('wpml_switch_language',$rowLanguages[$id]);
                if(!current_user_can('edit_post',$id))throw new RuntimeException('ACTOR_CANNOT_EDIT_POST_'.$id);
                $post=get_post($id);
                // The selected origin term must already be part of this tour's
                // translated destinations. If the verified source term is not
                // attached, repair the WPML-scoped relationship first rather
                // than bypassing the native taxonomy field options guard.
                $expectedTerm=(int)($row['expected']['tour_origin_location_term']??0);
                $referenceTerm=(int)($row['reference_term']??0);
                $lang=$rowLanguages[$id];
                $mapped=(int)apply_filters('wpml_object_id',$referenceTerm,'destinations',false,$lang);
                $term=get_term($expectedTerm,'destinations');
                $termLang=$term instanceof WP_Term
                    ?(string)apply_filters('wpml_element_language_code',null,
                        ['element_id'=>$term->term_taxonomy_id,'element_type'=>'tax_destinations']) : '';
                if(!$expectedTerm || $mapped!==$expectedTerm || !$term instanceof WP_Term || $termLang!==$lang)
                    throw new RuntimeException('ORIGIN_TERM_NATIVE_WPML_IDENTITY_INVALID_'.$id);
                $assigned=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
                if(is_wp_error($assigned))throw new RuntimeException('ORIGIN_NATIVE_TERMS_UNREADABLE_'.$id);
                $termSnapshots[$id]=array_map('intval',$assigned);
                if(!in_array($expectedTerm,$termSnapshots[$id],true)){
                    $cap=get_taxonomy('destinations')->cap->assign_terms??'assign_categories';
                    if(!current_user_can($cap))throw new RuntimeException('ORIGIN_TAXONOMY_ASSIGNMENT_NOT_AUTHORIZED_'.$id);
                    $added=wp_add_object_terms($id,[$expectedTerm],'destinations');
                    if(is_wp_error($added))throw new RuntimeException('ORIGIN_NATIVE_TERM_ASSIGNMENT_REJECTED_'.$id);
                    $termRelationsAdded[$id]=true;
                    $confirmed=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
                    if(is_wp_error($confirmed)||!in_array($expectedTerm,array_map('intval',$confirmed),true))
                        throw new RuntimeException('ORIGIN_NATIVE_TERM_RELATION_READBACK_FAILED_'.$id);
                }
                tk_tour_origin_sync_save($id,$post,true);
                $changed[]=$id;
                foreach($row['expected'] as $key=>$value) {
                    $actual=(string)get_post_meta($id,$key,true);
                    if($actual!==(string)$value)throw new RuntimeException('ORIGIN_READBACK_FAILED_FOR_POST_'.$id.'_FIELD_'.$key.'_ACTUAL_LENGTH_'.strlen($actual).'_EXPECTED_LENGTH_'.strlen((string)$value));
                }
            }
        } catch(Throwable $e) {
            foreach($snapshots as $id=>$before) {
                if(isset($rowLanguages[$id]))do_action('wpml_switch_language',$rowLanguages[$id]);
                self::restore($id,$before);
            }
            foreach($termRelationsAdded as $id=>$added) {
                if(isset($rowLanguages[$id]))do_action('wpml_switch_language',$rowLanguages[$id]);
                if(isset($termSnapshots[$id]))wp_set_object_terms($id,$termSnapshots[$id],'destinations',false);
            }
            throw $e;
        } finally {
            if($originalLanguage!=='')do_action('wpml_switch_language',$originalLanguage);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
        $after=self::scan();
        return ['state'=>'NATIVE_ORIGIN_RECONCILE_APPLIED','updated'=>count($changed),'origin_term_relations_added'=>count($termRelationsAdded),
            'remaining_eligible'=>$after['eligible_count'],'unresolved'=>$after['unresolved_count'],
            'site_origin'=>home_url(),'target_languages'=>$after['target_languages']];
    }
}}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    final class Company_Portable_Origin_Reconcile_Command {
        /**
         * Reconcile translated tour origins from published, verified destination terms.
         *
         * ## OPTIONS
         * [--apply] : Commit safe repairs using the explicitly authenticated --user WordPress actor.
         * [--format=<format>] : json (default) or table.
         *
         * ## EXAMPLES
         *     wp company-origin reconcile --format=json
         *     wp company-origin reconcile --apply --user=<enrolled-site-editor> --format=json
         */
        public function reconcile($args,$assoc_args): void {
            $plan=Company_Portable_Origin_Reconcile::scan();
            if(!empty($assoc_args['apply'])){
                if(!WP_CLI::get_config('user'))WP_CLI::error('EXPLICIT_WP_USER_REQUIRED');
                try{$result=Company_Portable_Origin_Reconcile::apply($plan);}
                catch(Throwable $e){WP_CLI::error($e->getMessage());return;}
            }else{$result=$plan;$result['state']='NATIVE_RECONCILE_PLAN_ONLY';$result['data_writes']=0;}
            $format=$assoc_args['format']??'json';
            if($format!=='json')WP_CLI::error('ONLY_JSON_SUPPORTED');
            WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
    WP_CLI::add_command('company-origin', 'Company_Portable_Origin_Reconcile_Command');
}

// Content migration runtimes emit this generic hook only after post, term and
// WPML relations are committed; a missing actor is reported, never impersonated.
add_action('mad4b_company_content_reconciled', static function($context=[]): void {
    if (!is_array($context) || !empty($context['read_only'])) return;
    if (!defined('WP_CLI') || !WP_CLI) return;
    if (!get_current_user_id()) {
        do_action('mad4b_company_origin_reconcile_result', ['state'=>'AUTHENTICATED_WORDPRESS_ACTOR_REQUIRED']);
        return;
    }
    try {
        $result=Company_Portable_Origin_Reconcile::apply(Company_Portable_Origin_Reconcile::scan());
    } catch (Throwable $error) {
        $result=['state'=>'ORIGIN_RECONCILE_FAILED','error_code'=>$error->getMessage()];
    }
    do_action('mad4b_company_origin_reconcile_result',$result);
}, 20, 1);
