<?php
/**
 * Plugin Name: MAD4B GTM Consent Governance
 * Description: Tenant-local public GTM inventory, immutable published-version fingerprints, and fail-closed partial-consent eligibility.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_GTM_Consent_Governance',false)) {
final class MAD4B_GTM_Consent_Governance {
    const INVENTORY='mad4b_gtm_public_inventory_v1';
    const APPROVAL='mad4b_gtm_workspace_consent_approval_v1';
    const CRON='mad4b_gtm_public_inventory_refresh_v1';
    public static function container():string {
        if(!class_exists('MAD4B_Consent_Gateway',false))return '';
        $raw=MAD4B_Consent_Gateway::id();
        return is_string($raw)&&preg_match('/^GTM-[A-Z0-9]{6,20}$/D',$raw)?$raw:'';
    }
    private static function resource(string $text):?array {
        if(strlen($text)>3000000)return null;
        $s=strpos($text,'"resource":');
        if($s===false)return null;
        $s=strpos($text,'{',$s);
        if($s===false)return null;
        $depth=0;$quoted=false;$escaped=false;$length=strlen($text);
        for($i=$s;$i<$length;$i++) {
            $c=$text[$i];
            if($escaped){$escaped=false;continue;}
            if($quoted&&$c==='\\'){$escaped=true;continue;}
            if($c==='"'){$quoted=!$quoted;continue;}
            if($quoted)continue;
            if($c==='{')$depth++;
            if($c==='}'&&--$depth===0){
               $decoded=json_decode(substr($text,$s,$i-$s+1),true,50);
               return is_array($decoded)?$decoded:null;
            }
        }
        return null;
    }
    public static function analyze(string $text,string $container):array {
        $resource=self::resource($text);
        if(!$resource||!is_array($resource['tags']??null))return ['ok'=>false,'reason'=>'published_resource_unreadable'];
        $tags=$resource['tags'];
        if(count($tags)>1000)return ['ok'=>false,'reason'=>'unreasonable_tag_count'];
        $types=[];$active=[];$paused=[];$potential_pii=[];$tags_with_consent_signal=[];
        $external_rules=[];
        foreach($tags as $t) {
            if(!is_array($t))continue;
            $fn=(string)($t['function']??'');
            $id=(string)($t['tag_id']??'');
            if($fn==='__paused'){$paused[]=$id;continue;}
            $types[$fn]=($types[$fn]??0)+1;
            $active[]=$id;
            // __evl is a GTM-internal event listener, not a remotely
            // configured third-party tracking tag. Its emitted events can
            // trigger real tags, which must still pass consent checks.
            if($fn!=='__evl'){
                $consent=(array)($t['consent']??[]);
                $needs=(string)($consent[0]??'')==='list'
                    ?array_values(array_map('strval',array_slice($consent,1))):[];
                $external_rules[$id]=['function'=>$fn,'consent_types'=>$needs];
            }
            if(!empty($t['vtp_isAutoCollectPiiEnabledFlag']) || !empty($t['vtp_autoEmailEnabled'])
               || !empty($t['vtp_autoPhoneEnabled']))
                $potential_pii[]=['id'=>$id,'type'=>$fn,'auto_pii_flag'=>!empty($t['vtp_isAutoCollectPiiEnabledFlag'])];
            // This is NOT proof of Advanced > Additional Consent Checks.
            foreach(array_keys($t) as $key)
                if(str_contains(strtolower((string)$key),'consent')) {
                   $tags_with_consent_signal[$id]=true;break;
                }
        }
        $ids=array_values(array_unique(array_merge($active,$paused)));
        sort($ids,SORT_STRING);
        ksort($types);
        ksort($external_rules,SORT_STRING);
        return [
          'ok'=>true,'scope'=>'published_js_only_not_workspace_export',
          'container_id'=>$container,'published_resource_version'=>(string)($resource['version']??''),
          'js_sha256'=>hash('sha256',$text),
          'published_total_tags'=>count($tags),'published_active_tags'=>count($active),
          'published_paused_tags'=>count($paused),
          'active_types'=>$types,'tag_ids'=>$ids,
          'external_tag_consent_rules'=>$external_rules,
          'potential_pii_auto_collect'=>$potential_pii,
          'tags_with_public_consent_signal'=>count($tags_with_consent_signal),
          'additional_consent_checks_verified'=>false,
          'checked_at_utc'=>gmdate('c'),
        ];
    }
    public static function refresh():array {
        $container=self::container();
        if($container==='')return ['ok'=>false,'reason'=>'gtm_container_not_unique'];
        $uri='https://www.googletagmanager.com/gtm.js?id='.rawurlencode($container);
        $response=wp_remote_get($uri,[
            'timeout'=>14,'redirection'=>0,'sslverify'=>true,
            'limit_response_size'=>2900000,'headers'=>['Accept'=>'application/javascript']
        ]);
        if(is_wp_error($response))return ['ok'=>false,'reason'=>'public_gtm_request_failed'];
        if((int)wp_remote_retrieve_response_code($response)!==200)
            return ['ok'=>false,'reason'=>'public_gtm_http_non_200'];
        $result=self::analyze((string)wp_remote_retrieve_body($response),$container);
        if(!($result['ok']??false))return $result;
        $previous=(array)get_option(self::INVENTORY,[]);
        $result['changed_since_last_scan']=!empty($previous['js_sha256'])
           && !hash_equals((string)$previous['js_sha256'],$result['js_sha256']);
        update_option(self::INVENTORY,$result,false);
        return $result;
    }
    public static function status():array {
        $inventory=(array)get_option(self::INVENTORY,[]);
        $approval=(array)get_option(self::APPROVAL,[]);
        $why=[];
        $container=self::container();
        if($container==='')$why[]='container_unavailable_or_multiple';
        if(($inventory['container_id']??'')!==$container||empty($inventory['ok']))
            $why[]='published_container_not_inventoried';
        if(empty($inventory['checked_at_utc'])||time()-strtotime((string)$inventory['checked_at_utc'])>36*HOUR_IN_SECONDS)
            $why[]='published_inventory_stale';
        $approvalSource=(string)($approval['source']??'');
        $providerAudit=$approvalSource==='authenticated_google_tag_manager_api_live_version';
        $legacyExport=$approvalSource==='authenticated_gtm_workspace_export_v2';
        if(!$providerAudit&&!$legacyExport)
            $why[]='authenticated_provider_consent_evidence_missing';
        if($providerAudit){
            if(($approval['provider_live_version']??'')!==($inventory['published_resource_version']??''))
                $why[]='published_provider_version_not_matched';
            $approved=(array)($approval['approved_external_consent_rules']??[]);
            $public=(array)($inventory['external_tag_consent_rules']??[]);
            ksort($approved,SORT_STRING);ksort($public,SORT_STRING);
            if(!$approved||!$public||$approved!==$public)
                $why[]='published_external_tag_consent_mismatch';
            if(count($public)!==(int)($inventory['tags_with_public_consent_signal']??-1))
                $why[]='unprotected_published_external_tags';
            $receipt=(string)($approval['broker_audit_sha256']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$receipt))
                $why[]='authenticated_provider_audit_receipt_missing';
            $siteUuid=class_exists('MAD4B_SCP_Site_Profile',false)
                ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
            if($siteUuid===''||($approval['site_uuid']??'')!==$siteUuid)
                $why[]='authenticated_provider_audit_wrong_company';
        }
        if(($approval['container_id']??'')!==$container||!$container)
            $why[]='workspace_container_not_matched';
        if(empty($approval['all_active_tags_consent_reviewed']) ||
           empty($approval['google_analytics_requires_analytics_storage']) ||
           empty($approval['marketing_tags_require_ad_storage']) ||
           empty($approval['custom_tags_require_additional_consent']))
            $why[]='tag_consent_checks_not_verified';
        if(empty($approval['published_js_sha256']) || empty($inventory['js_sha256']) ||
            !hash_equals((string)$inventory['js_sha256'],(string)$approval['published_js_sha256']))
            $why[]='published_script_fingerprint_not_approved';
        if(empty($approval['verified_at_utc']) ||
           time()-strtotime((string)$approval['verified_at_utc'])>30*DAY_IN_SECONDS)
            $why[]='workspace_approval_stale';
        if(!empty($inventory['potential_pii_auto_collect']) && empty($approval['pii_auto_collection_reviewed']))
            $why[]='first_party_pii_collection_unreviewed';
        return [
          'container_id'=>$container,'eligible_for_partial_shared_gtm'=>count($why)===0,
          'blockers'=>array_values(array_unique($why)),
          'published'=>array_intersect_key($inventory,array_flip([
             'published_resource_version','js_sha256','published_total_tags',
             'published_active_tags','published_paused_tags','potential_pii_auto_collect',
             'tags_with_public_consent_signal','checked_at_utc','changed_since_last_scan',
          ])),
          'workspace_checks_proven'=>count($why)===0,
        ];
    }
    public static function eligible():bool {
        return self::status()['eligible_for_partial_shared_gtm'];
    }
    public static function adminInfo():void {
        if(!current_user_can('manage_options'))return;
        $s=self::status();
        echo '<h2>Google Tag Manager consent audit</h2>';
        echo '<p><strong>Container:</strong> '.esc_html($s['container_id']).
            ' · <strong>Partial-load eligibility:</strong> '.
            esc_html($s['eligible_for_partial_shared_gtm']?'verified':'blocked by default').'</p>';
        if($s['blockers'])echo '<p><strong>Unresolved:</strong> '.esc_html(implode(', ',$s['blockers'])).'</p>';
        echo '<p>Public script inventory is informative; it cannot prove each Tag’s Additional Consent Checks. A matching authenticated live Google API audit and published-script proof are required before shared container partial loading is enabled.</p>';
        foreach($s['published'] as $key=>$value) {
            $val=is_scalar($value)?(string)$value:wp_json_encode($value);
            echo '<p>'.esc_html($key).': '.esc_html((string)$val).'</p>';
        }
    }
    public static function boot():void {
        add_action(self::CRON,[self::class,'refresh']);
        if(!wp_next_scheduled(self::CRON))
           wp_schedule_event(time()+2*HOUR_IN_SECONDS,'daily',self::CRON);
    }
}
MAD4B_GTM_Consent_Governance::boot();
}
