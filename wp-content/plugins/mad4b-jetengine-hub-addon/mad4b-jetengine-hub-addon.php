<?php
/**
 * Plugin Name: MAD4B JetEngine Hub Add-on
 * Description: Governed native JetEngine REST API Listings source-term mapping for MAD4B Content Hub.
 * Version: 0.1.0
 * Requires Plugins: jet-engine, mad4b-site-control-plane
 * Author: NagyE
 * Author URI: https://nagy.essam.website/
 *
 * This plugin does not create a Listing, endpoint, query, shortcode, taxonomy,
 * company credentials, cached copy of a Hub object, or any public catalog page.
 */
defined('ABSPATH') || exit;

final class MAD4B_JetEngine_Hub_Addon {
    const CONTRACT = 'mad4b.jetengine-hub-native-addon.v1';
    const CENTRAL = 'tours.dev.mad4b.com';
    const SOURCE = 'tourism-almallahgroup';
    const BINDINGS = 'mad4b_scp_hub_jetengine_bindings_v1';
    const SOURCE_CPT = 'mad4b_hub_nile';
    const SOURCE_HASH = '_mad4b_hub_source_sha256';
    const URL_PREFIX = '/wp-json/mad4b-central/v1/hub-distribution/';

    private static function deny($reason) {
        return new WP_Error('mad4b_jetengine_hub_' . $reason, $reason, array('status'=>409,'contract'=>self::CONTRACT));
    }

    public static function dependencies_ready() {
        return class_exists('MAD4B_SCP_Hub_JetEngine_Consumer_Governance') &&
            class_exists('Jet_Engine\\Modules\\Rest_API_Listings\\Module') &&
            function_exists('jet_engine') &&
            is_array(get_option('jet_engine_modules',array())) &&
            in_array('rest-api-listings',get_option('jet_engine_modules',array()),true);
    }

    public static function status() {
        $bindings=get_option(self::BINDINGS,array());
        return array(
            'contract'=>self::CONTRACT,
            'native_jetengine_provider'=>'rest-api-listings',
            'control_plane_dependency_ready'=>self::dependencies_ready(),
            'configuration_state'=>empty($bindings)?'unpaired':'configured_requires_server_authority',
            'activation_default'=>'deny',
            'dry_run_taxonomy_mapping'=>true,
            'native_listing_created'=>false,
            'native_query_replaced'=>false,
            'native_taxonomy_created'=>false,
            'homepage_or_elementor_mutation'=>false,
            'commercial_price_authority'=>false,
        );
    }

    /** Re-check a source record's ownership and raw payload integrity. */
    private static function source_category_rows($company) {
        if ($company !== self::SOURCE || !post_type_exists(self::SOURCE_CPT)) {
            return self::deny('company_or_hub_unavailable');
        }
        $ids=get_posts(array(
            'post_type'=>self::SOURCE_CPT,'post_status'=>'publish',
            'numberposts'=>80,'fields'=>'ids','suppress_filters'=>true,
            'meta_query'=>array(
                array('key'=>'_mad4b_hub_source_company','value'=>$company),
                array('key'=>'_mad4b_hub_source_kind','value'=>'nile_category'),
            ),
        ));
        if (count($ids)!==18) return self::deny('incomplete_category_set');
        $rows=array();
        foreach($ids as $id) {
            $id=(int)$id;
            if ((string)get_post_meta($id,'_mad4b_hub_commerce_approved',true)!=='no' ||
                (string)get_post_meta($id,'_mad4b_hub_bookable',true)!=='no' ||
                (string)get_post_meta($id,'_mad4b_hub_reuse_policy_state',true)!=='existing_canonical_term_linked') {
                return self::deny('source_governance_invalid');
            }
            $payload=(string)get_post_meta($id,'_mad4b_hub_source_payload_json',true);
            $expected=(string)get_post_meta($id,self::SOURCE_HASH,true);
            if ($payload==='' || strlen($payload)>16000 ||
                !hash_equals($expected,hash('sha256',$payload))) {
                // Source hashing contract is the canonical JSON encoding of the decoded source.
                $decoded=json_decode($payload,true);
                $digest=is_array($decoded)?hash('sha256',wp_json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)):'';
                if ($digest==='' || !hash_equals($expected,$digest))
                    return self::deny('source_hash_invalid');
            }
            $data=json_decode($payload,true);
            if (!is_array($data) || ($data['source_company']??'')!==$company ||
                ($data['taxonomy']??'')!=='tour-types_jet' ||
                !in_array($data['source_language_code']??'',array('ar','en'),true) ||
                !is_int($data['source_term_id']??null) ||
                !is_int($data['parent_source_term_id']??null) ||
                $data['source_term_id']<=0 ||
                (string)get_post_meta($id,'_mad4b_hub_target_taxonomy',true)!=='tour-types') {
                return self::deny('source_item_invalid');
            }
            $target=(int)get_post_meta($id,'_mad4b_hub_target_term_id',true);
            if ($target<1) return self::deny('target_term_missing');
            $key=$data['source_language_code'].':'.$data['source_term_id'];
            if (isset($rows[$key])) return self::deny('duplicated_source');
            $rows[$key]=array(
                'source_company'=>$company,
                'source_term_id'=>$data['source_term_id'],
                'source_parent_id'=>$data['parent_source_term_id'],
                'source_slug'=>$data['slug'],
                'language'=>$data['source_language_code'],
                'source_wpml_group'=>(int)$data['source_translation_group_id'],
                'canonical_term_id'=>$target,
                'source_hub_id'=>$id,
            );
        }
        return $rows;
    }

    /** No WP term ID can be assumed to be portable: match on both source and target identity. */
    public static function mapping($company=self::SOURCE) {
        $rows=self::source_category_rows($company);
        if(is_wp_error($rows))return $rows;
        $result=array();$canonicalLookup=array();$allSource=array();
        foreach($rows as $key=>$row) {
            $term=get_term($row['canonical_term_id'],'tour-types');
            if (!$term || is_wp_error($term) ||
                $term->taxonomy!=='tour-types' ||
                (string)$term->slug!==(string)$row['source_slug']) {
                return self::deny('target_taxonomy_slug_mismatch');
            }
            global $wpdb;
            if (!defined('ICL_SITEPRESS_VERSION'))return self::deny('wpml_unavailable');
            $wpml=$wpdb->get_row($wpdb->prepare(
                "SELECT language_code,trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_type=%s AND element_id=%d LIMIT 1",
                'tax_tour-types',(int)$term->term_id),ARRAY_A);
            if (!$wpml || $wpml['language_code']!==$row['language'] ||
                (int)$wpml['trid']<1) return self::deny('target_wpml_mismatch');
            $row['target_wpml_group']=(int)$wpml['trid'];
            $row['canonical_parent_id']=(int)$term->parent;
            $result[$key]=$row;
            $canonicalKey=$row['language'].':'.$row['canonical_term_id'];
            if (isset($canonicalLookup[$canonicalKey]))return self::deny('duplicated_canonical');
            $canonicalLookup[$canonicalKey]=$row['source_term_id'];
            $allSource[$row['source_term_id'].':'.$row['language']]=$row;
        }
        $langs=array_count_values(array_column($result,'language'));
        if (($langs['en']??0)!==9 || ($langs['ar']??0)!==9)return self::deny('wpml_language_set_incomplete');
        // Source parent and canonical parent must represent the same hierarchy.
        foreach($result as $row) {
            $sourceParent=(int)$row['source_parent_id'];
            $targetParent=(int)$row['canonical_parent_id'];
            if (!$sourceParent && $targetParent) return self::deny('root_parent_mismatch');
            if ($sourceParent) {
                $key=$row['language'].':'.$sourceParent;
                if (!isset($result[$key]) ||
                    (int)$result[$key]['canonical_term_id']!==$targetParent)
                    return self::deny('canonical_parent_mismatch');
            }
        }
        return $result;
    }

    public static function resolve($canonicalId,$language,$company=self::SOURCE) {
        if(!in_array($language,array('en','ar'),true) ||
           !is_numeric($canonicalId) || (int)$canonicalId<=0 ||
           (string)(int)$canonicalId!==(string)$canonicalId) {
            return self::deny('invalid_canonical_filter');
        }
        $map=self::mapping($company);
        if(is_wp_error($map))return $map;
        $matches=array_values(array_filter($map,static function($row)use($canonicalId,$language){
            return $row['language']===$language && $row['canonical_term_id']===(int)$canonicalId;
        }));
        if(count($matches)!==1)return self::deny('unmapped_canonical_filter');
        return $matches[0];
    }

    /**
     * Only a server-approved and already-configured endpoint is eligible.
     * Plugin opts-in to ZERO endpoints by default; no silent edits to JetEngine.
     */
    private static function bound_plan($request) {
        if(!self::dependencies_ready() || !is_object($request) ||
           !method_exists($request,'get_endpoint'))return false;
        $endpoint=$request->get_endpoint();
        if(!is_array($endpoint))return false;
        $id=(int)($endpoint['id']??0);
        if(!$id)return false;
        $bindings=get_option(self::BINDINGS,array());
        if(!is_array($bindings) || !isset($bindings[$id]) ||
           !is_array($bindings[$id]))return false;
        $plan=$bindings[$id];
        if (($plan['source_company']??'')!==self::SOURCE ||
            ($plan['endpoint_url']??'')!==($endpoint['url']??null))return false;
        $check=MAD4B_SCP_Hub_JetEngine_Consumer_Governance::validate_plan($plan);
        if(is_wp_error($check))return false;
        return $plan;
    }

    /**
     * Translate a native WPML taxonomy term used by JetSmartFilters into source
     * term identity before JetEngine forms the REST query. This does not create
     * source or native taxonomy terms, and cannot activate a request on its own.
     */
    public static function jet_query_args($args,$request) {
        $plan=self::bound_plan($request);
        if(!$plan)return $args;
        if(!is_array($args))return array('mad4b_rejected'=>'invalid_query_shape');
        if(isset($args['category']) || isset($args['source_term_id'])) {
            return array('mad4b_rejected'=>'raw_source_id_disallowed');
        }
        $language=$args['language']??null;
        if(!in_array($language,array('en','ar'),true)) {
            return array('mad4b_rejected'=>'missing_wpml_language');
        }
        $out=array('language'=>$language);
        if(isset($args['canonical_term_id'])) {
            $m=self::resolve($args['canonical_term_id'],$language,$plan['source_company']);
            if(is_wp_error($m))return array('mad4b_rejected'=>'unresolved_taxonomy');
            $out['category']=$m['source_term_id'];
        }
        return $out;
    }

    /**
     * Before JetEngine renders its source object, normalize its canonical
     * taxonomy ID. Consumer cannot inject local term IDs through a remote body.
     */
    public static function jet_response($body,$request,$args,$response) {
        $plan=self::bound_plan($request);
        if(!$plan)return $body;
        if(!is_object($body) || !isset($body->items) || !is_array($body->items))
            return (object)array('items'=>array());
        $map=self::mapping($plan['source_company']);
        if(is_wp_error($map))return (object)array('items'=>array());
        $language=isset($args['language'])?(string)$args['language']:'';
        $rewritten=array();
        foreach($body->items as $item){
            if(!is_object($item) || !isset($item->source_company,$item->source_id,$item->language) ||
               $item->source_company!==$plan['source_company'] ||
               $item->language!==$language) return (object)array('items'=>array());
            // A mapping mismatch must fail closed rather than show another site's taxonomy.
            if(isset($item->source_term_ids) && is_array($item->source_term_ids)){
                $local=array();
                foreach($item->source_term_ids as $sourceTerm) {
                    if(!is_int($sourceTerm))return (object)array('items'=>array());
                    $k=$language.':'.$sourceTerm;
                    if(!isset($map[$k]))return (object)array('items'=>array());
                    $local[]=(int)$map[$k]['canonical_term_id'];
                }
                $item->canonical_term_ids=array_values(array_unique($local));
            }
            unset($item->price,$item->sale_price,$item->checkout_url,$item->customer_email);
            $rewritten[]=$item;
        }
        $body->items=$rewritten;
        return $body;
    }

    public static function permission() {return current_user_can('manage_options');}
    public static function rest_status() {return self::status();}
    public static function rest_resolve($request) {
        $id=$request->get_param('canonical_term_id');
        $lang=$request->get_param('language');
        $result=self::resolve($id,$lang);
        if(is_wp_error($result))return $result;
        return array(
            'contract'=>self::CONTRACT,'source_company'=>$result['source_company'],
            'canonical_term_id'=>$result['canonical_term_id'],
            'source_term_id'=>$result['source_term_id'],
            'language'=>$result['language'],
            'source_taxonomy'=>'tour-types_jet','canonical_taxonomy'=>'tour-types',
            'target_wpml_group'=>$result['target_wpml_group'],
            'source_wpml_group'=>$result['source_wpml_group'],
            'read_only'=>true,'runtime_approved'=>false,
        );
    }
    public static function rest_routes(){
        register_rest_route('mad4b/v1','/jetengine-hub-addon/status',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_status'),
            'permission_callback'=>array(__CLASS__,'permission')));
        register_rest_route('mad4b/v1','/jetengine-hub-addon/resolve',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'rest_resolve'),
            'permission_callback'=>array(__CLASS__,'permission')));
    }
    public static function boot(){
        // Do not depend on undocumented vendor PHP class overrides.
        add_action('rest_api_init',array(__CLASS__,'rest_routes'),30);
        add_filter('jet-engine/rest-api-listings/request/query-args',
            array(__CLASS__,'jet_query_args'),25,2);
        add_filter('jet-engine/rest-api-listings/response/body',
            array(__CLASS__,'jet_response'),25,4);
    }
}
add_action('plugins_loaded',array('MAD4B_JetEngine_Hub_Addon','boot'),30);
