<?php
if(!defined('ABSPATH'))exit;
/**
 * AI-only content QA grant reconciliation. The original enrolled owner's
 * persisted, audited ai_only delegation is the approval source. No model,
 * unauthenticated HTTP request, or arbitrary caller can select an Agent,
 * environment, or expand the allowlist. Local content-quality receipts only.
 */
final class MAD4B_SCP_AI_Native_Grants_Opt_In {
    const CONTRACT='mad4b.ai-native-five-policy-reconciliation.v2';
    const OPTION='mad4b_scp_ai_native_owner_exact_grants_v1';
    const LOCK='mad4b_scp_ai_native_owner_exact_grants_lock_v1';
    private static function abilities(){return array(
        'mad4b/ai-content-job-review','mad4b/ai-product-catalog-review',
        'mad4b/ai-seo-metadata-review','mad4b/ai-localization-review',
        'mad4b/ai-design-template-review');}
    private static function record(){
        $r=get_option(self::OPTION,array());
        if(!is_array($r) || !in_array(($r['contract']??''),
            array(self::CONTRACT,'mad4b.ai-native-five-owner-grants.v1'),true))return array();
        return $r;
    }
    private static function original_policy(){
        if(wp_get_environment_type()!=='local' || PHP_SAPI!=='cli' && !is_admin() && !wp_doing_cron()) {
            // HTTP request origin never supplies authority; persisted source remains decisive.
            if(wp_get_environment_type()!=='local')return new WP_Error('ai_native_local_only','Local required.');
        }
        if(!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::configured()
          || !MAD4B_SCP_Site_Profile::origin_enrolled()
          || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
          || MAD4B_SCP_Site_Profile::current_environment()!=='local')
            return new WP_Error('ai_native_site_scope_invalid','Original Local Site Profile required.');
        $policy=MAD4B_SCP_Context_Authority::review_policy();
        if(($policy['mode']??'')!=='ai_only')return new WP_Error('ai_native_policy_not_ai_only','No AI-only delegation.');
        $owner=(int)($policy['updated_by_wp_user_id']??0);
        if($owner<1 || !MAD4B_SCP_Site_Profile::user_is_enrolled($owner)
            || !user_can($owner,'manage_options'))
            return new WP_Error('ai_native_owner_attestation_missing','Original enrolled owner delegation missing.');
        $native=MAD4B_SCP_Context_Authority::ai_review_policy_status();
        if(empty($native['ready']) || empty($native['exact_grant_ready']))
            return new WP_Error('ai_native_context_grant_not_ready','Original Context Agent review grant missing.');
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)$policy['ai_agent_public_id']);
        if(!is_array($agent) || ($agent['status']??'')!=='enabled'
            || ($agent['slug']??'')!==MAD4B_SCP_Site_Profile::agent_slug())
            return new WP_Error('ai_native_agent_binding_invalid','Original Agent not Site Profile-owned.');
        if(empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('ai_native_audit_missing','Original append-only Audit required.');
        return array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
            'agent'=>$agent,'owner_user_id'=>$owner,'policy_updated_at'=>$policy['updated_at']??'');
    }
    private static function managed_valid($r){
        $managed=$r['managed']??array();
        if(!is_array($managed))return false;
        foreach($managed as $ability=>$id)
            if(!in_array($ability,self::abilities(),true) || !is_numeric($id) || (int)$id<1)return false;
        return true;
    }
    private static function rollback($agent,$created){
        foreach(array_reverse($created,true) as $ability=>$id)
            MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id($agent['public_id'],(int)$id,'mad4b-write');
    }
    public static function reconcile(){
        if(!function_exists('wp_get_environment_type') || wp_get_environment_type()!=='local')
            return new WP_Error('ai_native_local_only','Local required.');
        $policy=self::original_policy();
        if(is_wp_error($policy))return $policy;
        $r=self::record();$agent=$policy['agent'];
        if($r && (($r['site_uuid']??'')!==$policy['site_uuid'] ||
             ($r['agent_public_id']??'')!==$agent['public_id'] || !self::managed_valid($r)))
            return new WP_Error('ai_native_record_drift','Reconcile prior managed grants before granting.');
        if(!add_option(self::LOCK,array('created_at'=>time(),'site_uuid'=>$policy['site_uuid']),'',false))
            return new WP_Error('ai_native_reconcile_busy','An existing reconciliation lock must complete.');
        try {
            $existing=array();
            foreach(self::abilities() as $ability){
                if(!function_exists('wp_has_ability') || !wp_has_ability($ability)
                    || !MAD4B_SCP_AI_Native_Owner_Review::local_ability_allowed($ability))
                    return new WP_Error('ai_native_ability_unregistered','Original native owner QA ability required.');
                $g=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write',$ability,'core');
                if(is_wp_error($g) && $g->get_error_code()!=='mad4b_nhi_grant_missing')return $g;
                if(is_array($g) && ($g['environment']??'')!=='local')
                    return new WP_Error('ai_native_broad_grant_requires_review','Cannot manage an all-environment grant.');
                $existing[$ability]=$g;
            }
            $missing=array_filter($existing,'is_wp_error');
            if(!$missing)return self::status();
            $meta=array('contract'=>self::CONTRACT,'site_uuid'=>$policy['site_uuid'],
                'agent_public_id'=>$agent['public_id'],'owner_user_id'=>$policy['owner_user_id'],
                'delegation_recorded_at'=>$policy['policy_updated_at'],
                'grant_abilities'=>array_keys($missing),'environment'=>'local',
                'publication_authorized'=>false,'developer'=>false,'breakglass'=>false);
            $intent=MAD4B_SCP_Audit::record('mad4b/ai-only-native-qa-reconcile-intent',$meta,'ok');
            if(is_wp_error($intent))return $intent;
            $created=array();
            foreach($missing as $ability=>$ignored){
                $grant=MAD4B_SCP_Agent_Registry::grant_ability($agent['public_id'],'mad4b-write',
                    $ability,'core',array('purpose'=>'content_qa_only','delegation'=>'existing_ai_only'),
                    'allow','local');
                if(is_wp_error($grant)){self::rollback($agent,$created);return $grant;}
                $read=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write',$ability,'core');
                if(!is_array($read) || ($read['effect']??'')!=='allow' || ($read['environment']??'')!=='local'){
                    self::rollback($agent,$created);
                    return new WP_Error('ai_native_readback_failed','Original exact grant not verified.');
                }
                $created[$ability]=(int)$read['id'];
            }
            $state=array('contract'=>self::CONTRACT,'mode'=>'delegated_ai_only',
                'agent_public_id'=>$agent['public_id'],'site_uuid'=>$policy['site_uuid'],
                'managed'=>array_merge($r['managed']??array(),$created),
                'policy_updated_at'=>$policy['policy_updated_at'],'updated_at'=>time());
            if(!update_option(self::OPTION,$state,false) && self::record()!==$state){
                self::rollback($agent,$created);return new WP_Error('ai_native_state_write_failed','State persistence failed.');
            }
            $receipt=MAD4B_SCP_Audit::record('mad4b/ai-only-native-qa-reconciled',
                $meta+array('grant_ids'=>array_values($created)),'ok');
            if(is_wp_error($receipt)){
                self::rollback($agent,$created);update_option(self::OPTION,$r,false);
                return new WP_Error('ai_native_audit_write_failed','Audit failed; new grants revoked.');
            }
            return self::status();
        } finally {delete_option(self::LOCK);}
    }
    public static function prepare_content_job_qa() {
        $s=self::status();
        if(wp_get_environment_type()!=='local' || PHP_SAPI!=='cli'
            || !$s['scope_valid'] || $s['grants_local_allowed']!==5)
            return new WP_Error('ai_native_fixture_scope_denied','Original AI-only Local delegation required.');
        if(!defined('MAD4B_LOCAL_CLIENT_VAULT_PATH') || !defined('MAD4B_AI_LOCAL_REVIEW_LOOPBACK_URL'))
            return new WP_Error('ai_native_fixture_transport_missing','Host-owned original Agent transport unavailable.');
        $path=dirname((string)MAD4B_LOCAL_CLIENT_VAULT_PATH).'/ai-only-review.json';
        if(!is_file($path) || is_link($path) || (fileperms($path)&0077)!==0)
            return new WP_Error('ai_native_fixture_vault_unavailable','Original service identity vault invalid.');
        $auth=json_decode((string)file_get_contents($path),true);
        if(!is_array($auth) || empty($auth['login']) || empty($auth['password'])
            || (int)($auth['expires_at']??0)<=time()
            || ($auth['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid())
            return new WP_Error('ai_native_fixture_auth_expired','Original Agent credential invalid.');
        $origin=(string)MAD4B_AI_LOCAL_REVIEW_LOOPBACK_URL;
        if(!preg_match('#^http://[a-z0-9][a-z0-9-]*(?::[0-9]{2,5})?$#D',$origin))
            return new WP_Error('ai_native_fixture_origin_denied','Only configured internal service permitted.');
        $url=wp_parse_url(home_url());
        $hostname=(string)($url['host']??'');
        if($hostname==='')return new WP_Error('ai_native_fixture_site_missing','Canonical origin unavailable.');
        $host=$hostname.(isset($url['port'])?':'.(int)$url['port']:'');
        $res=wp_remote_post($origin.'/wp-json/mad4b-local-ai/v1/domain-review',array(
            'timeout'=>40,'redirection'=>0,'limit_response_size'=>8192,
            'headers'=>array('Authorization'=>'Basic '.base64_encode($auth['login'].':'.$auth['password']),
                'Host'=>$host,'Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('domain'=>'content_job','action'=>'prepare_local_qa_fixture'))));
        // Never return or log the credential. Only the response status and
        // controlled original ContentJob contract leave this helper.
        if(is_wp_error($res))return $res;
        $status=(int)wp_remote_retrieve_response_code($res);
        $decoded=json_decode(wp_remote_retrieve_body($res),true);
        if($status!==200 || !is_array($decoded))
            return new WP_Error('ai_native_fixture_http_failed','Original ContentJob did not acknowledge the preparation.',
                array('http_status'=>$status,'original_error'=>is_array($decoded)?($decoded['code']??'unknown'):'invalid_response',
                    'failure_phase'=>is_array($decoded)?($decoded['data']['failure_phase']??null):null));
        if(($decoded['state']??'')!=='WAITING_REVIEW' || empty($decoded['job_id']))
            return new WP_Error('ai_native_fixture_response_invalid','Original ContentJob did not enter WAITING_REVIEW.');
        return array('job_id'=>$decoded['job_id'],'state'=>'WAITING_REVIEW',
            'original_owner_http_verified'=>true,'publication_authorized'=>false);
    }
    public static function status(){
        $p=self::original_policy();$r=self::record();$states=array();$count=0;
        foreach(self::abilities() as $ability){
            $grant=!is_wp_error($p)?MAD4B_SCP_Agent_Registry::exact_grant(
                (int)$p['agent']['id'],'mad4b-write',$ability,'core'):null;
            $ok=is_array($grant)&&($grant['effect']??'')==='allow'&&($grant['environment']??'')==='local';
            if($ok)$count++;
            $states[$ability]=array('allowed'=>$ok,'status'=>is_wp_error($grant)?$grant->get_error_code():($ok?'local_allow':'not_local_allow'));
        }
        return array('contract'=>self::CONTRACT,'mode'=>is_wp_error($p)?'blocked':'delegated_ai_only',
            'scope_valid'=>!is_wp_error($p),'scope_error'=>is_wp_error($p)?$p->get_error_code():null,
            'grants_local_allowed'=>$count,'grants_required'=>count(self::abilities()),
            'managed_grant_count'=>count($r['managed']??array()),'grants'=>$states,
            'human_approval_per_review'=>false,'owner_policy_is_approval_source'=>true,
            'publication_authorized'=>false,'privileged_grants_issued'=>false);
    }
    public static function boot(){
        // Original WordPress Abilities are registered first. Reconcile at init
        // only in Local; never from Stage/Production or from model outputs.
        add_action('init',array(__CLASS__,'maybe_reconcile'),95);
    }
    /** If the enrolled owner withdraws AI-only delegation, immediately remove
     * ONLY the five local entitlements minted by this controller. Do not
     * remove independent grants or trust model instructions as revocation.
     */
    public static function revoke_withdrawn_policy() {
        if(wp_get_environment_type()!=='local')return false;
        $prior=self::record();
        if(($prior['mode']??'')!=='delegated_ai_only' || empty($prior['managed']))return false;
        $current=MAD4B_SCP_Context_Authority::review_policy();
        if(($current['mode']??'')==='ai_only'
            &&($current['ai_agent_public_id']??'')===($prior['agent_public_id']??''))return false;
        if(!MAD4B_SCP_Site_Profile::configured() || !MAD4B_SCP_Site_Profile::origin_enrolled()
            || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            || ($prior['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid()
            || !self::managed_valid($prior) || empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('ai_native_revoke_scope_invalid','Original site/Audit identity required.');
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)$prior['agent_public_id']);
        if(!is_array($agent))return new WP_Error('ai_native_revoke_agent_missing','Original Agent unavailable.');
        $all=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$agent['id'],'mad4b-write');
        foreach($prior['managed'] as $ability=>$id) {
            $found=false;
            foreach($all as $row)if((int)$row['id']===(int)$id
                &&($row['ability_name']??'')===$ability &&($row['provider']??'')==='core'
                &&($row['environment']??'')==='local' &&($row['effect']??'')==='allow')$found=true;
            if(!$found)return new WP_Error('ai_native_revoke_grant_drift','Managed grant identity drift.');
        }
        if(!add_option(self::LOCK,array('created_at'=>time()),'',false))
            return new WP_Error('ai_native_revoke_lock_busy','Reconciliation busy.');
        try {
            $intent=MAD4B_SCP_Audit::record('mad4b/ai-only-native-qa-policy-withdrawn',
                array('previous_agent'=>$prior['agent_public_id'],
                    'managed_grant_count'=>count($prior['managed']),
                    'review_scope'=>'content_quality_only'),'ok');
            if(is_wp_error($intent))return $intent;
            foreach($prior['managed'] as $ability=>$id) {
                $deleted=MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(
                    (string)$prior['agent_public_id'],(int)$id,'mad4b-write');
                if(is_wp_error($deleted))return $deleted;
                unset($prior['managed'][$ability]);
                update_option(self::OPTION,$prior,false); // persist partial progress
            }
            $prior['mode']='off';$prior['updated_at']=time();
            update_option(self::OPTION,$prior,false);
            MAD4B_SCP_Audit::record('mad4b/ai-only-native-qa-grants-revoked',
                array('reason'=>'original_policy_changed',
                    'review_scope'=>'content_quality_only'),'ok');
            return true;
        } finally {delete_option(self::LOCK);}
    }
    public static function maybe_reconcile(){
        if(wp_get_environment_type()!=='local')return;
        $s=self::status();
        if(!$s['scope_valid']) {self::revoke_withdrawn_policy();return;}
        if($s['grants_local_allowed']===5)return;
        self::reconcile();
    }
}
MAD4B_SCP_AI_Native_Grants_Opt_In::boot();
