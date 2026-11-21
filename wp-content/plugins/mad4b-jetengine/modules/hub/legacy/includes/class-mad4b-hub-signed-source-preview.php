<?php
/**
 * MAD4B Site Control Plane — source-signed selective content preview.
 * Administrator only. Never an authenticated distribution grant or public
 * JetEngine feed; a signed content snapshot is NOT a signed market license.
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Signed_Source_Preview {
    const CONTRACT = 'mad4b.hub.signed-source-market-preview.v1';
    const SOURCE_COMPANY = 'tourism-almallahgroup';
    const SOURCE_SCOPE = 'nile_cruise_catalog.read';
    const MAX_SCAN_PAGES = 10;
    const PER_PAGE = 20;

    private static function fail($reason, $status=409) {
        return new WP_Error('mad4b_hub_source_preview_'.$reason, $reason,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function enabled() {
        return class_exists('MAD4B_Hub_Discovery_Planner') &&
            class_exists('MAD4B_Hub_Market_Policy') &&
            class_exists('MAD4B_JetEngine_Hub_Addon') &&
            class_exists('MAD4B_Central_Nile_Reference_V1');
    }
    public static function state() {
        $companyGrant = get_option('mad4b_company_market_grants_v1',null);
        $markets=get_option(MAD4B_Hub_Market_Policy::REGISTRY_OPTION,array());
        return array(
            'contract'=>self::CONTRACT,
            'source_company'=>self::SOURCE_COMPANY,
            'transport'=>'existing_signed_P256_tourism_nile_reference',
            'signed_source_read_available'=>self::enabled(),
            'source_market_grants_verified'=>false,
            'source_market_grant_registry_present'=>is_array($companyGrant) && !empty($companyGrant),
            'destination_active_market_count'=>is_array($markets)?count($markets):0,
            'public_jetengine_feed_enabled'=>false,
            'preview_requires_administrator'=>true,
            'source_content_publication_authorized'=>false,
            'commercial_pricing_authorized'=>false,
            'read_only'=>true
        );
    }
    private static function safe_item($row, $verified, $company) {
        if(!is_array($row) ||
            ($row['source_company']??null)!==$company ||
            !isset($row['source_tour_id'],$row['title'],$row['summary'],$row['translation_source'])) {
            return self::fail('untrusted_item');
        }
        $id=$row['source_tour_id'];
        if(!is_int($id)||$id<=0 ||
           !is_string($row['title']) || strlen($row['title'])>1500 ||
           !is_string($row['summary']) || strlen($row['summary'])>25000 ||
           !is_array($row['translation_source']) ||
           !in_array($row['translation_source']['language_code']??null,array('en','ar'),true) ||
           !is_array($row['source_taxonomy_term_ids']??null) ||
           count($row['source_taxonomy_term_ids'])>30) {
            return self::fail('invalid_item_shape');
        }
        if(($row['publish_allowed']??true)!==false) {
            // Current source has NOT approved publishing. Do not accidentally
            // turn source status into implied content-sharing authority.
            return self::fail('unexpected_source_publish_authority');
        }
        $language=$row['translation_source']['language_code'];
        $localTerms=array();
        foreach($row['source_taxonomy_term_ids'] as $term){
            if(!is_int($term)||$term<1)return self::fail('invalid_source_term');
            $map=$verified[$language.':'.$term]??null;
            if(!$map)return self::fail('source_term_unmapped');
            $localTerms[]=(int)$map['canonical_term_id'];
        }
        return array(
            'source_company'=>$company,
            'source_id'=>$id,
            'language'=>$language,
            'title'=>sanitize_text_field($row['title']),
            'summary'=>sanitize_textarea_field($row['summary']),
            'source_term_ids'=>array_values(array_unique($row['source_taxonomy_term_ids'])),
            'canonical_term_ids'=>array_values(array_unique($localTerms)),
            'reference_only'=>true,'bookable'=>false,
            'source_publish_allowed'=>false
        );
    }

    public static function preview($manifest) {
        if(!self::enabled())return self::fail('dependencies_missing',503);
        if(!is_array($manifest) ||
           ($manifest['source_company']??null)!==self::SOURCE_COMPANY)
            return self::fail('company_not_allowed',403);
        $plan=MAD4B_Hub_Discovery_Planner::validate($manifest);
        if(is_wp_error($plan))return $plan;
        if(empty($plan['market_scoped']) ||
           empty($plan['query_plan']['market_slices']) ||
           !empty($plan['query_plan']['policy_blocks_read']))
            return self::fail('market_not_authorized_for_preview',403);
        $selection=$plan['selected'];
        $chosen=$selection['source_record_ids']??array();
        $langs=$selection['languages']??array();
        $canonical=$selection['canonical_category_ids']??array();
        if(!$chosen||count($chosen)>50||!$langs||
           $manifest['provider']!=='jet-engine' ||
           !in_array('en',$langs,true) && !in_array('ar',$langs,true))
            return self::fail('exact_source_ids_and_languages_required');
        $marketScopes=$plan['market_plan']['scopes'];
        // Cross-market union is intentionally unsupported in this first
        // read-only preview: it might leak an item into a denied market.
        if(count($marketScopes)!==1)return self::fail('exactly_one_market_required');
        $market=$marketScopes[0];
        if(array_diff($chosen,$market['dimensions']['source_record_ids']) ||
           array_diff($langs,$market['dimensions']['languages']))
            return self::fail('market_selection_mismatch');
        $termMap=MAD4B_JetEngine_Hub_Addon::mapping(self::SOURCE_COMPANY);
        if(is_wp_error($termMap))return $termMap;
        $signedItems=array();$total=null;$pageCount=0;
        for($page=1;$page<=self::MAX_SCAN_PAGES;$page++){
            $signed=MAD4B_Central_Nile_Reference_V1::fetch(
                self::SOURCE_SCOPE,$page,self::PER_PAGE);
            if(is_wp_error($signed))return $signed;
            if(($signed['owner_company']??null)!==self::SOURCE_COMPANY ||
               ($signed['reference_only']??false)!==true ||
               ($signed['may_publish']??true)!==false ||
               ($signed['may_checkout']??true)!==false ||
               !is_int($signed['total']??null) ||
               !is_array($signed['items']??null) ||
               count($signed['items'])>self::PER_PAGE)
                return self::fail('source_read_scope_invalid');
            if($total===null)$total=$signed['total'];
            if($total!==$signed['total']||$total>self::PER_PAGE*self::MAX_SCAN_PAGES)
                return self::fail('source_read_total_drift');
            foreach($signed['items'] as $item){
                $id=$item['source_tour_id']??null;
                if(!is_int($id)||$id<1||isset($signedItems[$id]))
                    return self::fail('source_id_duplicate_or_invalid');
                $signedItems[$id]=$item;
            }
            $pageCount=$page;
            if(count($signedItems)===$total)break;
            if(count($signed['items'])<self::PER_PAGE)return self::fail('incomplete_source_page');
        }
        if(count($signedItems)!==$total)return self::fail('source_incomplete');
        $matches=array();$selectedSet=array_fill_keys($chosen,true);
        foreach($chosen as $id){
            if(!isset($signedItems[$id]))return self::fail('requested_source_record_missing');
            $safe=self::safe_item($signedItems[$id],$termMap,self::SOURCE_COMPANY);
            if(is_wp_error($safe))return $safe;
            if(!in_array($safe['language'],$langs,true))
                return self::fail('selected_record_wrong_language');
            if($canonical && !array_intersect($safe['canonical_term_ids'],$canonical))
                return self::fail('selected_record_outside_canonical_taxonomy');
            $matches[]=$safe;
        }
        return array('contract'=>self::CONTRACT,
            'source_company'=>self::SOURCE_COMPANY,
            'market_id'=>$market['market_id'],
            'source_signed_transport_verified'=>true,
            'source_signed_market_license_verified'=>false,
            'items'=>$matches,
            'selected_items'=>count($matches),
            'source_catalog_count'=>$total,
            'source_pages_verified'=>$pageCount,
            'query_plan_digest'=>hash('sha256',wp_json_encode($plan['query_plan'])),
            'preview_only'=>true,'public_rendering_allowed'=>false,
            'wordPress_items_created'=>0,'jetengine_bindings_updated'=>0,
            'historical_rates_enabled'=>false,'checkout_enabled'=>false);
    }
    public static function permission() {
        return current_user_can('manage_options');
    }
    public static function rest_status() {
        return self::state();
    }
    public static function rest_preview($request) {
        $params=$request->get_json_params();
        if(!is_array($params)||!isset($params['selection_manifest']))
            return self::fail('manifest_required',400);
        $result=self::preview($params['selection_manifest']);
        if(is_wp_error($result))return $result;
        $response=new WP_REST_Response($result,200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function public_deny() {
        // The signed SOURCE explicitly states may_publish=false.
        return self::fail('source_market_public_grant_missing',403);
    }
    public static function boot() {
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/hub-market-source/status',array(
                'methods'=>'GET','callback'=>array(__CLASS__,'rest_status'),
                'permission_callback'=>array(__CLASS__,'permission')));
            register_rest_route('mad4b/v1','/hub-market-source/preview',array(
                'methods'=>'POST','callback'=>array(__CLASS__,'rest_preview'),
                'permission_callback'=>array(__CLASS__,'permission')));
            // Reserve the correct JetEngine-native contract without exposing
            // data or accepting WordPress admin sessions as distribution auth.
            register_rest_route('mad4b-central/v1',
                '/hub-distribution/(?P<company>[a-z0-9-]+)/(?P<collection>catalog|categories)',
                array('methods'=>'GET','callback'=>array(__CLASS__,'public_deny'),
                    'permission_callback'=>'__return_true'));
        },36);
    }
}
