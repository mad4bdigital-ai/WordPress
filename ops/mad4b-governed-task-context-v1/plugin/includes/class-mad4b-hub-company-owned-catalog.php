<?php
/**
 * Feature009 P1-E: source-backed, company-bound content ownership catalog.
 * A source post being published is NOT proof of its commercial ownership,
 * redistribution rights or a company-specific Hub release.
 */
defined('ABSPATH')||exit;
require_once __DIR__.'/class-mad4b-hub-company-rights-evidence.php';
final class MAD4B_Hub_Company_Owned_Catalog_V1 {
    public const CONTRACT='mad4b.hub.company-owned-content-catalog.v1';
    private const TYPES=['tours-and-activities','properties','units',
        'transportations','tour-rates','property-rates','transport-rates'];
    private static function error(string $id,int $http=409):WP_Error {
        return new WP_Error('mad4b_hub_catalog_'.$id,
            'Company catalog evidence: '.$id,['status'=>$http]);
    }
    public static function permission() {
        return class_exists('MAD4B_Content_Hub',false)
            && MAD4B_Content_Hub::permission()
            ? true:self::error('central_admin_required',403);
    }
    public static function item_id(string $uuid,string $type,int $post):string {
        return $type.'-'.substr(hash('sha256',$uuid.'|'.$type.'|'.$post),0,32);
    }
    public static function catalog(string $company,int $page=1) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
           ||$page<1||$page>200)return self::error('query_invalid',422);
        if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            ||!MAD4B_Company_Portfolio_Manager_V1::staging()
            ||!class_exists('MAD4B_SCP_Site_Profile',false))
            return self::error('central_source_required',403);
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
        if(is_wp_error($binding))return $binding;
        $uuid=$binding['company_site_uuid']??null;
        if(!wp_is_uuid((string)$uuid)||
          !preg_match('/^[a-f0-9]{64}$/D',(string)($binding['binding_sha256']??'')))
            return self::error('company_binding_unverified',409);
        $central=MAD4B_SCP_Site_Profile::status();
        if(($central['authority_ready']??false)!==true
            ||($central['binding_state']??null)!=='exact')
            return self::error('central_site_unverified',409);
        $site=(string)($central['site_uuid']??'');
        $is_local=$site===$uuid;
        global $wpdb;
        $types="'".implode("','",self::TYPES)."'";
        $totals=$wpdb->get_results(
            "SELECT post_type,COUNT(*) n FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type IN ($types)
             GROUP BY post_type",ARRAY_A);
        $counts=[];foreach(self::TYPES as $t)$counts[$t]=0;
        foreach((array)$totals as $t)$counts[$t['post_type']]=(int)$t['n'];
        $rows=[];
        if($is_local){
            $posts=$wpdb->get_results($wpdb->prepare(
                "SELECT ID,post_type,post_name,post_title,post_modified_gmt
                 FROM {$wpdb->posts} WHERE post_status='publish'
                 AND post_type IN ($types)
                 ORDER BY ID ASC LIMIT %d OFFSET %d",25,25*($page-1)),ARRAY_A);
            foreach((array)$posts as $post){
                $post_id=(int)$post['ID'];$kind=$post['post_type'];
                $rows[]=[
                    'global_id'=>self::item_id($site,$kind,$post_id),
                    'source_post_id'=>$post_id,'content_type'=>$kind,
                    'source_slug'=>(string)$post['post_name'],
                    'title'=>(string)$post['post_title'],
                    'source_version_hint_sha256'=>hash('sha256',
                        $site.'|'.$kind.'|'.$post_id.'|'.$post['post_modified_gmt']),
                    'source_evidence'=>'verified_local_wordpress_post',
                    'ownership_state'=>'NOT_CERTIFIED',
                    'rights_to_distribute'=>'NOT_VERIFIED',
                    'release_state'=>'CANDIDATE_ONLY',
                    'reference_repository_eligible'=>false,
                    'company_portable'=>false
                ];
            }
        }
        $publications=0;$verified=0;
        if(class_exists('MAD4B_Hub_Publication_Targets',false)
            &&MAD4B_Hub_Publication_Targets::ready()){
            $t=MAD4B_Hub_Publication_Targets::table();
            $found=$wpdb->get_results($wpdb->prepare(
                "SELECT source_verified,COUNT(*) n FROM $t
                 WHERE company_id=%s AND destination_site_uuid=%s
                 GROUP BY source_verified",$company,$uuid),ARRAY_A);
            foreach((array)$found as $record){
                $n=(int)$record['n'];$publications+=$n;
                if(in_array($record['source_verified'],
                    ['local_wordpress','verified_remote_connector'],true))
                    $verified+=$n;
            }
        }
        $referenceCount=0;
        if(class_exists('MAD4B_Hub_Canonical_Store',false)){
            $records=MAD4B_Hub_Canonical_Store::tables()['records'];
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$records))===$records)
                $referenceCount=(int)$wpdb->get_var(
                    "SELECT COUNT(*) FROM $records WHERE status='research_candidate'");
        }
        $evidence=MAD4B_Hub_Company_Rights_Evidence_V1::inspect($company,$uuid,$site);
        $count=$is_local?array_sum($counts):0;
        $result=[
            'contract'=>self::CONTRACT,
            'company_code'=>$company,'company_site_uuid'=>$uuid,
            'binding_sha256'=>$binding['binding_sha256'],
            'central_source_site_uuid'=>$site,'source_equals_company_site'=>$is_local,
            'content_authority'=>'Content_Hub_primary_company_scoped',
            'company_owned_verified_count'=>$evidence['verified_owned_records'],
            'company_verified_distribution_count'=>$evidence['certified_source_content_grants'],
            'company_hub_publication_records'=>$publications,
            'company_hub_verified_destination_links'=>$verified,
            'company_rights_evidence'=>$evidence,
            'public_local_source_count'=>$count,
            'source_type_counts'=>$is_local?$counts:[],
            'items'=>$rows,'page'=>$page,'page_size'=>25,
            'unverified_candidate_total'=>$count,
            'central_reference_research_candidate_count'=>$referenceCount,
            'reference_content_is_not_commercial_release'=>true,
            'hub_publication_schema_ready'=>class_exists('MAD4B_Hub_Publication_Targets',false)
                &&MAD4B_Hub_Publication_Targets::ready(),
            'external_company_source_requires_signed_native_connector'=>!$is_local,
            'rights_review_required'=>false,
            'rights_review_recommended'=>!$evidence['full_distribution_authorized'],
            'rights_documentation_optional'=>true,
            'rights_evidence_mode'=>'optional_advisory',
            'rights_evidence_blocks_provisioning'=>false,
            'rights_evidence_blocks_reconcile'=>false,
            'rights_evidence_blocks_schema_transfer'=>false,
            'rights_evidence_blocks_vendor_adapter'=>false,
            'company_profile_export_required'=>true,
            'full_company_distribution_authorized'=>$evidence['full_distribution_authorized'],
            'publication_performed'=>false,'remote_or_local_writes'=>0,
        ];
        // Keep this as a snapshot fingerprint, not a signature.
        $result['snapshot_sha256']=hash('sha256',
            wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return $result;
    }
    public static function routes():void {
        register_rest_route('mad4b-hub/v1','/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/owned-content-catalog',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function($request){
                $p=(int)($request->get_param('page')??1);
                $r=rest_ensure_response(self::catalog((string)$request['company_code'],$p));
                if($r instanceof WP_REST_Response)$r->header('Cache-Control','private,no-store');
                return $r;
            }
        ]);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],88);
    }
}
MAD4B_Hub_Company_Owned_Catalog_V1::boot();
