<?php
/**
 * Plugin Name: MAD4B Media Review Policy Hierarchy
 * Description: Central inherited and company-local media review policy with host WP-CLI sync and fleet telemetry.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Media_Review_Hierarchy_V1 {
    const CONTRACT='mad4b.media-review-policy-hierarchy.v1';
    const CENTRAL_OPTION='mad4b_media_review_central_default_v1';
    const INHERITED_OPTION='mad4b_media_review_inherited_v1';
    const LOCAL_OPTION='mad4b_media_review_local_override_v1';
    const TELEMETRY_OPTION='mad4b_media_review_fleet_telemetry_v1';
    const REGISTRY='mad4b_tourism_company_site_registry_v1';
    const MODES=['inherit','human_only','human_and_ai','ai_only'];

    public static function init():void {
        add_action('admin_menu',[__CLASS__,'menu'],110);
        add_action('admin_post_mad4b_media_review_settings',[__CLASS__,'admin_save']);
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue_company_assets']);
        add_action('rest_api_init',[__CLASS__,'routes'],80);
    }
    private static function fail($code,$message='Media review policy operation denied.') {
        return new WP_Error($code,$message,['status'=>409]);
    }
    private static function central():bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function site():array {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return [];
        $s=MAD4B_SCP_Site_Profile::status();
        if(!is_array($s)||empty($s['origin_match'])
            ||empty($s['environment_match'])||empty($s['deployment_binding_match'])
            ||!wp_is_uuid((string)($s['site_uuid']??'')))return [];
        return $s;
    }
    private static function registry():array {
        $r=get_option(self::REGISTRY,[]);
        return is_array($r)?$r:[];
    }
    private static function bound_company($code) {
        $s=self::site();
        if(!self::central()||!$s)return self::fail('mad4b_media_central_host_invalid');
        $r=self::registry();
        $row=$r[$code]??null;
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)$code)
           ||!is_array($row)||($row['company_code']??'')!==$code
           ||empty($row['host_binding_verified'])
           ||!wp_is_uuid((string)($row['company_site_uuid']??''))
           ||!hash_equals((string)$row['company_site_uuid'],
                  (string)($row['native_site_profile_uuid']??'')))
            return self::fail('mad4b_media_company_registry_unverified');
        if(class_exists('MAD4B_Company_Portfolio_Manager_V1',false)){
            $unique=MAD4B_Company_Portfolio_Manager_V1::unique_company_site_identity(
                $code,$row['company_site_uuid'],$row['native_site_profile_uuid'],$r);
            if(is_wp_error($unique))return $unique;
        }
        return $row;
    }
    private static function normalize_mode($mode,bool $allow_inherit=true):string {
        $mode=sanitize_key((string)$mode);
        return in_array($mode,self::MODES,true)
            &&($allow_inherit||$mode!=='inherit')?$mode:'';
    }
    private static function fresh_central():array {
        return ['contract'=>self::CONTRACT,'site_uuid'=>(string)(self::site()['site_uuid']??''),
            'revision'=>0,'default_mode'=>'human_only','company_defaults'=>[],
            'updated_at'=>null,'updated_by'=>''];
    }
    public static function central_policy():array {
        if(!self::central())return [];
        $d=get_option(self::CENTRAL_OPTION,null);
        $fresh=self::fresh_central();
        if(!is_array($d))return $fresh;
        if(($d['contract']??'')!==self::CONTRACT
           ||!hash_equals($fresh['site_uuid'],(string)($d['site_uuid']??''))
           ||!is_int($d['revision']??null)
           ||!self::normalize_mode($d['default_mode']??'',false)
           ||!is_array($d['company_defaults']??null))return $fresh;
        $out=$fresh;
        foreach(['revision','default_mode','updated_at','updated_by'] as $k)$out[$k]=$d[$k]??$fresh[$k];
        foreach($d['company_defaults'] as $code=>$mode){
            if(!is_wp_error(self::bound_company($code))
               &&self::normalize_mode($mode,true))$out['company_defaults'][$code]=$mode;
        }
        ksort($out['company_defaults']);
        return $out;
    }
    private static function inherited():array {
        $s=self::site();
        if(!$s)return [];
        if(self::central()){
            $d=self::central_policy();
            return ['central_revision'=>$d['revision'],
                'central_site_uuid'=>$d['site_uuid'],
                'site_uuid'=>$s['site_uuid'],
                'company_code'=>'central',
                'default_mode'=>$d['default_mode'],
                'company_default_mode'=>'inherit',
                'received_at'=>$d['updated_at'],'source'=>'central_live'];
        }
        $d=get_option(self::INHERITED_OPTION,[]);
        if(!is_array($d)||($d['contract']??'')!==self::CONTRACT
           ||!hash_equals((string)$s['site_uuid'],(string)($d['site_uuid']??''))
           ||!wp_is_uuid((string)($d['central_site_uuid']??''))
           ||!self::normalize_mode($d['default_mode']??'',false)
           ||!self::normalize_mode($d['company_default_mode']??'',true)
           ||!is_int($d['central_revision']??null)
           ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)($d['company_code']??'')))
            return [];
        return $d;
    }
    public static function local_override():array {
        // Central has its own policy store and must never inherit a
        // company-local administrative or OAuth write surface.
        if(self::central())
            return ['mode'=>'inherit','revision'=>0,
                'source'=>'central_policy_is_not_company_local'];
        $s=self::site();
        if(!$s)return [];
        $d=get_option(self::LOCAL_OPTION,[]);
        if(!is_array($d)||($d['contract']??'')!==self::CONTRACT
           ||!hash_equals((string)$s['site_uuid'],(string)($d['site_uuid']??''))
           ||!self::normalize_mode($d['mode']??'',true))
            return ['mode'=>'inherit','revision'=>0,'source'=>'default_inherit'];
        return $d;
    }
    public static function effective():array {
        $site=self::site();
        if(!$site)return ['contract'=>self::CONTRACT,'mode'=>'human_only',
            'policy_ready'=>false,'blockers'=>['site_binding_invalid']];
        $i=self::inherited();
        $l=self::local_override();
        $default=(string)($i['default_mode']??'human_only');
        $company_default=(string)($i['company_default_mode']??'inherit');
        $local=(string)($l['mode']??'inherit');
        $ready=!empty($i) &&self::normalize_mode($default,false)!=='';
        $mode='human_only';$source='unconfigured_safe_fallback';
        if($ready){
            if($local!=='inherit'){$mode=$local;$source='company_local_override';}
            elseif($company_default!=='inherit'){$mode=$company_default;$source='central_company_default';}
            else{$mode=$default;$source='central_inherited_default';}
        }
        $fingerprint=hash('sha256',wp_json_encode([
            $site['site_uuid'],$i['central_site_uuid']??'',
            $i['central_revision']??0,$mode,$source,$l['revision']??0]));
        return ['contract'=>self::CONTRACT,'site_uuid'=>$site['site_uuid'],
            'mode'=>$mode,'source'=>$source,
            'policy_ready'=>$ready,
            'blockers'=>$ready?[]:['central_policy_not_synced'],
            'default_mode'=>$default,'central_company_default'=>$company_default,
            'company_local_override'=>$local,
            'central_revision'=>(int)($i['central_revision']??0),
            'company_revision'=>(int)($l['revision']??0),
            'received_at'=>$i['received_at']??null,
            'policy_fingerprint'=>$fingerprint,
            'visual_review_evidence_required'=>true,
            'photographic_provenance_certified'=>false,
            'usage_rights_certified'=>false,
            'auto_apply_enabled'=>false,
            'host_wpcli_execution_separate'=>true];
    }
    public static function applied_media_snapshot():array {
        $site=self::site();
        if(!$site)return ['ready'=>false,'applied_tour_families'=>null];
        global $wpdb;
        $rows=$wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 301",
             $wpdb->esc_like('mad4b_media_apply_backup_v1_').'%'));
        if(!is_array($rows)||$wpdb->last_error!=='')
            return ['ready'=>false,'applied_tour_families'=>null];
        if(count($rows)>300)return ['ready'=>false,
            'applied_tour_families'=>null,'reason'=>'receipt_cap_exceeded'];
        $active=[];$rollback=0;$latest_seen=[];
        foreach($rows as $raw){
            $r=maybe_unserialize($raw);
            if(!is_array($r)||($r['contract']??'')!=='mad4b.tour-media-rollback.v1'
               ||($r['site_uuid']??'')!==$site['site_uuid'])continue;
            $post=absint($r['source_post_id']??0);
            if(!$post||get_post_type($post)!=='tours-and-activities')continue;
            // Receipts arrive newest first. The newest receipt for each
            // canonical tour determines its CURRENT write state.
            if(isset($latest_seen[$post]))continue;
            $latest_seen[$post]=true;
            if(($r['status']??'')==='rolled_back')$rollback++;
            elseif(($r['status']??'')==='applied')$active[$post]=true;
        }
        $ids=array_map('intval',array_keys($active));
        sort($ids,SORT_NUMERIC);
        return ['ready'=>true,'applied_tour_families'=>count($active),
            'applied_tour_ids'=>$ids,
            'rollback_receipts'=>$rollback,
            'audited_media_apply_records_scanned'=>count($rows),
            'current_frontend_status_not_implied'=>true];
    }
    public static function media_queue_snapshot():array {
        $out=['contract'=>self::CONTRACT,
            'media_jobs_waiting_review'=>null,'media_jobs_count'=>null,
            'queue_readback_ready'=>false,
            'content_job_transitions_performed'=>false];
        if(!class_exists('MAD4B_SCP_Content_Jobs',false))return $out;
        $r=MAD4B_SCP_Content_Jobs::list_jobs(['limit'=>100]);
        if(is_wp_error($r)||!is_array($r)||!is_array($r['items']??null))return $out;
        $count=0;$waiting=0;
        foreach($r['items'] as $job){
            $subject=(string)($job['subject']??'');
            if(!preg_match('/^\\[MAD4B_MEDIA_[a-f0-9]{24}\\]/',$subject))continue;
            ++$count;
            if(($job['state']??'')==='WAITING_REVIEW')++$waiting;
        }
        $out['media_jobs_count']=$count;
        $out['media_jobs_waiting_review']=$waiting;
        $out['queue_readback_ready']=true;
        $out['bounded_to_first_100_jobs']=true;
        return $out;
    }
    private static function save_option($option,$value):bool {
        $prior=get_option($option,null);
        if($prior===$value)return true;
        $ok=$prior===null ? add_option($option,$value,'','no')
            :update_option($option,$value,false);
        return (bool)$ok &&get_option($option,null)===$value;
    }
    private static function audit($action,$details):bool {
        if(!class_exists('MAD4B_SCP_Audit',false))return false;
        $r=MAD4B_SCP_Audit::record($action,$details);
        return !is_wp_error($r);
    }
    private static function mutate_central($mode,$company_code='',$company_mode='') {
        if(!self::central()||!self::site())
            return self::fail('mad4b_media_central_scope_required');
        $d=self::central_policy();
        if($company_code!==''){
            $bound=self::bound_company($company_code);
            if(is_wp_error($bound))return $bound;
            $new=self::normalize_mode($company_mode,true);
            if($new==='')return self::fail('mad4b_media_company_mode_invalid');
            $d['company_defaults'][$company_code]=$new;
        }else{
            $new=self::normalize_mode($mode,false);
            if($new==='')return self::fail('mad4b_media_central_mode_invalid');
            $d['default_mode']=$new;
        }
        ++$d['revision'];
        $d['updated_at']=gmdate('c');
        $d['updated_by']=get_current_user_id()?'central_admin':'authorized_host_wpcli';
        if(!self::audit('mad4b/media-central-review-policy-change',[
            'site_uuid'=>$d['site_uuid'],'revision'=>$d['revision'],
            'default_mode'=>$d['default_mode'],
            'company_code'=>$company_code,
            'company_default_mode'=>$company_code!==''?$d['company_defaults'][$company_code]:null
        ]))return self::fail('mad4b_media_policy_audit_unavailable');
        if(!self::save_option(self::CENTRAL_OPTION,$d))
            return self::fail('mad4b_media_policy_central_persist_failed');
        return self::central_policy();
    }
    private static function mutate_local($mode) {
        if(self::central())return self::fail(
            'mad4b_media_company_preference_not_allowed_on_central');
        $s=self::site();
        if(!$s)return self::fail('mad4b_media_local_profile_invalid');
        $mode=self::normalize_mode($mode,true);
        if($mode==='')return self::fail('mad4b_media_local_mode_invalid');
        $old=self::local_override();
        if(($old['mode']??'inherit')===$mode)return self::effective();
        $d=['contract'=>self::CONTRACT,'site_uuid'=>$s['site_uuid'],
            'mode'=>$mode,'revision'=>(int)($old['revision']??0)+1,
            'updated_at'=>gmdate('c'),
            'updated_by'=>get_current_user_id()?'company_admin':'authorized_host_wpcli'];
        if(!self::audit('mad4b/media-company-review-policy-change',[
            'site_uuid'=>$s['site_uuid'],'previous_mode'=>$old['mode']??'inherit',
            'mode'=>$mode,'revision'=>$d['revision']]))
            return self::fail('mad4b_media_local_policy_audit_unavailable');
        if(!self::save_option(self::LOCAL_OPTION,$d))
            return self::fail('mad4b_media_local_policy_persist_failed');
        return self::effective();
    }
    /**
     * Company-scoped remote OAuth MCP mutation, never central policy.
     * A fresh exact plan, Site Profile and verified OAuth permission are
     * checked before AND inside the per-company lock. No host identity or
     * WordPress user is impersonated.
     */
    public static function remote_company_apply($mode,$site_uuid,$expected_revision,
        $expected_fingerprint,$expected_plan_sha256) {
        if(!class_exists('MAD4B_Company_Media_MCP_V1',false))
            return self::fail('mad4b_media_company_mcp_unavailable');
        $permission=MAD4B_Company_Media_MCP_V1::write_permission([]);
        if(is_wp_error($permission))return $permission;
        if(self::central())
            return self::fail('mad4b_media_central_mcp_local_mutation_denied');
        $site=self::site();
        if(!$site||!is_string($site_uuid)
           ||!hash_equals($site['site_uuid'],$site_uuid))
            return self::fail('mad4b_media_remote_site_profile_mismatch');
        if(!is_int($expected_revision)||$expected_revision<0)
            return self::fail('mad4b_media_remote_revision_invalid');
        if(!class_exists('MAD4B_SCP_Distributed_Lock',false))
            return self::fail('mad4b_media_remote_lock_unavailable');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'company-media-policy:'.$site['site_uuid']);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try {
            // Exact fingerprint also binds the inherited central revision:
            // an update to central policy invalidates any pending MCP plan.
            $before=self::effective();
            $plan=MAD4B_Company_Media_MCP_V1::planned_state(['mode'=>$mode]);
            if(is_wp_error($plan))return $plan;
            if((int)$before['company_revision']!==$expected_revision
                ||!hash_equals((string)$before['policy_fingerprint'],
                    (string)$expected_fingerprint)
                ||!hash_equals((string)$plan['plan_sha256'],
                    (string)$expected_plan_sha256))
                return self::fail('mad4b_media_remote_plan_stale');
            $result=self::mutate_local($mode);
            if(is_wp_error($result))return $result;
            $changed=$before['company_local_override']!==$mode;
            if($result['company_local_override']!==$mode ||
                $result['site_uuid']!==$site['site_uuid'] ||
                (int)$result['company_revision']!==
                   (int)$before['company_revision']+($changed?1:0))
                return self::fail('mad4b_media_remote_postcondition_failed');
            return [
                'contract'=>MAD4B_Company_Media_MCP_V1::CONTRACT,
                'status'=>$changed?'applied_verified':'already_matching',
                'site_uuid'=>$site['site_uuid'],
                'effective_mode'=>$result['mode'],
                'policy_source'=>$result['source'],
                'company_override'=>$result['company_local_override'],
                'company_revision'=>$result['company_revision'],
                'central_revision'=>$result['central_revision'],
                'policy_fingerprint'=>$result['policy_fingerprint'],
                'only_company_option_changed'=>$changed,
                'central_default_changed'=>false,
                'content_job_changed'=>false,
                'tour_media_changed'=>false,
                'authority_transport'=>'verified_enrolled_user_oauth_mcp',
                'readback_verified'=>true,
            ];
        }finally{
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
    public static function fleet_export() {
        if(!self::central()||!self::site())
            return self::fail('mad4b_media_fleet_central_only');
        $d=self::central_policy();
        $companies=[];
        foreach(self::registry() as $code=>$row){
            $b=self::bound_company($code);
            if(is_wp_error($b))continue;
            $companies[$code]=[
                'site_uuid'=>$b['company_site_uuid'],
                'canonical_origin'=>$b['canonical_origin']??'',
                'central_company_default'=>$d['company_defaults'][$code]??'inherit'];
        }
        return ['contract'=>self::CONTRACT,'central_site_uuid'=>$d['site_uuid'],
            'central_revision'=>$d['revision'],'central_default_mode'=>$d['default_mode'],
            'companies'=>$companies,'source'=>'central_wpcli_bound_registry'];
    }
    private static function sync(array $m) {
        $site=self::site();
        if(!$site||self::central())return self::fail('mad4b_media_target_not_company');
        $keys=['contract','site_uuid','central_site_uuid','central_revision',
            'company_code','default_mode','company_default_mode','source'];
        $given=array_keys($m);sort($given);sort($keys);
        if($given!==$keys||($m['contract']??'')!==self::CONTRACT
            ||($m['source']??'')!=='bound_host_wpcli_fleet_sync'
            ||!hash_equals((string)$site['site_uuid'],(string)($m['site_uuid']??''))
            ||!wp_is_uuid((string)($m['central_site_uuid']??''))
            ||!is_int($m['central_revision']??null)
            ||$m['central_revision']<1
            ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)($m['company_code']??''))
            ||!self::normalize_mode($m['default_mode']??'',false)
            ||!self::normalize_mode($m['company_default_mode']??'',true))
            return self::fail('mad4b_media_policy_sync_invalid');
        $prior=self::inherited();
        if(($prior['central_site_uuid']??$m['central_site_uuid'])!==$m['central_site_uuid']
            ||($prior['company_code']??$m['company_code'])!==$m['company_code']
            ||(int)($prior['central_revision']??0)>$m['central_revision'])
            return self::fail('mad4b_media_policy_source_or_revision_drift');
        $new=$m;
        $new['received_at']=gmdate('c');
        if(!empty($prior)
           &&$prior['central_revision']===$m['central_revision']
           &&$prior['default_mode']===$m['default_mode']
           &&$prior['company_default_mode']===$m['company_default_mode']){
            return self::effective();
        }
        if(!self::audit('mad4b/media-inherited-policy-synced',[
            'site_uuid'=>$site['site_uuid'],'central_site_uuid'=>$m['central_site_uuid'],
            'central_revision'=>$m['central_revision'],
            'company_code'=>$m['company_code'],
            'review_mode_default'=>$m['default_mode'],
            'company_default'=>$m['company_default_mode'],
            'principal'=>'root_host_wpcli_no_wordpress_user_impersonation']))
            return self::fail('mad4b_media_policy_sync_audit_unavailable');
        if(!self::save_option(self::INHERITED_OPTION,$new))
            return self::fail('mad4b_media_policy_sync_persist_failed');
        return self::effective();
    }
    private static function write_telemetry(array $m) {
        if(!self::central())return self::fail('mad4b_media_telemetry_central_only');
        $code=sanitize_key((string)($m['company_code']??''));
        if($code==='central'){
            $central_site=self::site();
            if(!$central_site)return self::fail('mad4b_media_central_telemetry_site_invalid');
            $r=['company_site_uuid'=>$central_site['site_uuid']];
        }else{
            $r=self::bound_company($code);
            if(is_wp_error($r))return $r;
        }
        if(($m['contract']??'')!==self::CONTRACT
           ||($m['source']??'')!=='host_wpcli_verified_readback'
           ||!hash_equals($r['company_site_uuid'],(string)($m['site_uuid']??''))
           ||!is_int($m['canonical_tours']??null)||$m['canonical_tours']<0
           ||$m['canonical_tours']>10000
           ||!is_bool($m['inventory_complete']??null)
           ||!is_int($m['missing_source_images']??null)
           ||!is_int($m['waiting_visual_review']??null)
           ||!self::normalize_mode($m['effective_mode']??'',false))
            return self::fail('mad4b_media_telemetry_invalid');
        $stored=get_option(self::TELEMETRY_OPTION,[]);
        if(!is_array($stored))return self::fail('mad4b_media_telemetry_store_invalid');
        $stored[$code]=[
            'site_uuid'=>$r['company_site_uuid'],
            'canonical_tours'=>$m['canonical_tours'],
            'inventory_complete'=>$m['inventory_complete'],
            'missing_source_images'=>$m['missing_source_images'],
            'waiting_visual_review'=>$m['waiting_visual_review'],
            'applied_tour_families'=>is_int($m['applied_tour_families']??null)
                ?max(0,$m['applied_tour_families']):null,
            'content_jobs_waiting_review'=>is_int($m['content_jobs_waiting_review']??null)
                ?max(0,$m['content_jobs_waiting_review']):null,
            'effective_mode'=>$m['effective_mode'],
            'effective_source'=>sanitize_key((string)($m['effective_source']??'')),
            'central_revision'=>(int)($m['central_revision']??0),
            'company_local_override'=>sanitize_key((string)($m['company_local_override']??'inherit')),
            'updated_at'=>gmdate('c'),'telemetry_source'=>'root_host_wpcli'];
        if(!self::save_option(self::TELEMETRY_OPTION,$stored))
            return self::fail('mad4b_media_telemetry_persist_failed');
        return $stored[$code];
    }
    private static function cli_authorized():bool {
        return defined('WP_CLI')&&WP_CLI&&PHP_SAPI==='cli'&&!get_current_user_id();
    }
    public static function cli($args,$assoc):void {
        if(!self::cli_authorized()||count($args)!==1)
            WP_CLI::error('Exact host WP-CLI authority without WP user impersonation required');
        $op=$args[0];
        if(in_array($op,['status','export'],true)){
            if($assoc)WP_CLI::error('Unexpected CLI arguments');
            $result=$op==='status'
                ?array_merge(self::effective(),[
                    'media_content_jobs'=>self::media_queue_snapshot(),
                    'media_apply_progress'=>self::applied_media_snapshot()])
                :self::fleet_export();
        }else{
            if($assoc)WP_CLI::error('No arbitrary CLI flags');
            $raw=stream_get_contents(STDIN,8193);
            if(!is_string($raw)||strlen($raw)>8192)
                WP_CLI::error('Bounded JSON policy input required');
            $m=json_decode($raw,true);
            if(!is_array($m))WP_CLI::error('Invalid JSON policy manifest');
            if($op==='central-set'){
                $result=self::mutate_central($m['default_mode']??'',
                    (string)($m['company_code']??''),
                    (string)($m['company_default_mode']??''));
            }elseif($op==='company-set'){
                $result=self::mutate_local($m['mode']??'');
            }elseif($op==='sync'){
                $result=self::sync($m);
            }elseif($op==='telemetry'){
                $result=self::write_telemetry($m);
            }else WP_CLI::error('Unsupported media-policy operation');
        }
        if(is_wp_error($result))WP_CLI::error($result->get_error_code().':'.$result->get_error_message());
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function enqueue_company_assets($hook=null):void {
        if($hook!=='tools_page_mad4b-company-media-review'
           ||self::central()||!current_user_can('manage_options'))
            return;
        $relative='mu-plugins/assets/mad4b-company-media-control.css';
        $path=WP_CONTENT_DIR.'/'. $relative;
        if(!is_file($path))return;
        wp_enqueue_style('mad4b-company-media-control',
            content_url($relative),[],(string)filemtime($path));
        $js_relative='mu-plugins/assets/mad4b-company-media-control.js';
        $js_path=WP_CONTENT_DIR.'/'.$js_relative;
        if(is_file($js_path))
            wp_enqueue_script('mad4b-company-media-control',
                content_url($js_relative),[],(string)filemtime($js_path),true);
    }
    public static function menu():void {
        if(self::central()){
            add_submenu_page('mad4b-company-brand-readiness','Media Review Fleet',
                'Media Review Fleet','manage_options','mad4b-media-review-fleet',
                [__CLASS__,'central_screen']);
        }
        if(!self::central())
            add_management_page('Company Media Control','Company Media Control',
                'manage_options','mad4b-company-media-review',
                [__CLASS__,'local_screen']);
    }
    public static function central_screen():void {
        if(!current_user_can('manage_options')||!self::central())
            wp_die('Central administrator required');
        $c=self::central_policy();
        $states=get_option(self::TELEMETRY_OPTION,[]);
        echo '<div class="wrap"><h1>MAD4B Central / Media Review Fleet</h1>';
        echo '<p>The central administrator owns inherited strategy defaults and fleet monitoring. Each company has its own Site Profile, OAuth identity, company preference and execution authority. A company preference cannot modify central permissions or credentials.</p>';
        echo '<h2>Central inherited default</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_media_policy_central_default');
        echo '<input type="hidden" name="action" value="mad4b_media_review_settings"><input type="hidden" name="operation" value="central">';
        echo '<label for="central-media-mode">Default review mode </label><select id="central-media-mode" name="default_mode">';
        foreach(['ai_only'=>'AI only','human_and_ai'=>'Human + AI','human_only'=>'Human only'] as $value=>$label)
            echo '<option value="'.esc_attr($value).'" '.selected($c['default_mode'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select> <button class="button button-primary">Save central default</button></form>';
        echo '<p>Revision '.esc_html((string)$c['revision']).' · Auto-apply remains off: a visual evidence manifest and governed host apply are separate.</p>';
        echo '<h2>Companies and execution telemetry</h2>';
        $central_telemetry=is_array($states['central']??null)?$states['central']:[];
        echo '<p><strong>Tours.dev (Central)</strong> · Media mode: '.
            esc_html((string)($central_telemetry['effective_mode']??$c['default_mode'])).
            ' · Tour families: '.esc_html((string)($central_telemetry['canonical_tours']??'Pending host readback')).
            ' · Applied tours: '.esc_html((string)($central_telemetry['applied_tour_families']??'—')).
            ' · Waiting visual review: '.esc_html((string)($central_telemetry['waiting_visual_review']??'—')).
            ' · Missing source images: '.esc_html((string)($central_telemetry['missing_source_images']??'—')).
            ' · Last: '.esc_html((string)($central_telemetry['updated_at']??'—')).'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Inherited company mode</th><th>Effective remote mode</th><th>Source</th><th>Tour families</th><th>Applied</th><th>Visual queue</th><th>Media Jobs waiting</th><th>Missing images</th><th>Last readback</th></tr></thead><tbody>';
        foreach(self::registry() as $code=>$row){
            $b=self::bound_company($code);if(is_wp_error($b))continue;
            $v=is_array($states[$code]??null)?$states[$code]:[];
            echo '<tr><td><strong>'.esc_html($code).'</strong><p>'.esc_html($row['canonical_origin']??'').'</p></td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_media_policy_company_'.$code);
            echo '<input type="hidden" name="action" value="mad4b_media_review_settings"><input type="hidden" name="operation" value="company-default"><input type="hidden" name="company_code" value="'.esc_attr($code).'"><select name="company_default_mode">';
            foreach(['inherit'=>'Inherit central','ai_only'=>'AI only','human_and_ai'=>'Human + AI','human_only'=>'Human only'] as $value=>$label)
                echo '<option value="'.esc_attr($value).'" '.selected($c['company_defaults'][$code]??'inherit',$value,false).'>'.esc_html($label).'</option>';
            echo '</select> <button class="button">Save</button></form></td>';
            foreach([
                $v['effective_mode']??'No host readback',
                $v['effective_source']??'—',
                (string)($v['canonical_tours']??'—'),
                (string)($v['applied_tour_families']??'—'),
                (string)($v['waiting_visual_review']??'—'),
                (string)($v['content_jobs_waiting_review']??'—'),
                (string)($v['missing_source_images']??'—'),
                (string)($v['updated_at']??'—')
            ] as $field)echo '<td>'.esc_html($field).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table><p>Company preferences determine review strategy only. Central and company authentication policies are separate. Readback is supplied by the bound Host WP-CLI collector, not by unauthenticated REST calls.</p></div>';
    }
    public static function local_screen():void {
        if(!current_user_can('manage_options')||!self::site()||self::central())
            wp_die('Enrolled company administrator required');
        $e=self::effective();
        $site=self::site();
        $inherited=self::inherited();
        // Exact persistent value; do not confuse the inherited effective
        // strategy with the company's selected/saved radio option.
        $stored_option=get_option(self::LOCAL_OPTION,null);
        $stored_exists=$stored_option!==null;
        $stored_valid=is_array($stored_option)
            &&($stored_option['contract']??'')===self::CONTRACT
            &&hash_equals((string)$site['site_uuid'],
                (string)($stored_option['site_uuid']??''))
            &&self::normalize_mode($stored_option['mode']??'',true)!==''
            &&is_int($stored_option['revision']??null)
            &&$stored_option['revision']>=0;
        $stored_consistent=!$stored_exists||($stored_valid
            &&($stored_option['mode']??'')===$e['company_local_override']
            &&(int)$stored_option['revision']===(int)$e['company_revision']);
        $ready=!empty($e['policy_ready'])&&$stored_consistent
            &&(!$stored_exists||$stored_valid);
        $stored_when=$stored_valid
            ?(string)($stored_option['updated_at']??''):'';
        $stored_when_utc=$stored_when!==''&&strtotime($stored_when)!==false
            ?gmdate('Y-m-d H:i',strtotime($stored_when)).' UTC':'Not recorded';
        $stored_actor_raw=$stored_valid
            ?(string)($stored_option['updated_by']??'unknown'):'Not set';
        $actors=['authorized_host_wpcli'=>'Authorized host WP-CLI',
            'company_admin'=>'Company WordPress administrator',
            'unknown'=>'Actor not recorded','Not set'=>'No company save recorded'];
        $stored_actor=$actors[$stored_actor_raw]??'Recorded operator: '.$stored_actor_raw;
        $jobs=self::media_queue_snapshot();
        $applied=self::applied_media_snapshot();
        $profile=class_exists('MAD4B_SCP_Site_Profile',false)
            ?MAD4B_SCP_Site_Profile::status():[];
        $oauth=class_exists('MAD4B_SCP_OAuth_Resource_Bridge',false)
            ?MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status():[];
        $profile_bound=!empty($profile['configured'])
            &&!empty($profile['origin_match'])
            &&!empty($profile['environment_match'])
            &&!empty($profile['deployment_binding_match']);
        $oauth_feature=class_exists('MAD4B_SCP_Site_Profile',false)
            &&MAD4B_SCP_Site_Profile::oauth_enabled();
        // Resource readiness is NOT proof of an OAuth client session.
        $oauth_resource_ready=$oauth_feature&&!empty($oauth['effective']);
        $mcp_read=$oauth_resource_ready;
        $mcp_write=$mcp_read
            &&class_exists('MAD4B_SCP_Site_Profile',false)
            &&MAD4B_SCP_Site_Profile::governed_write_ready()
            &&class_exists('MAD4B_SCP_Policy',false)
            &&!empty(MAD4B_SCP_Policy::mutation_gate_status()['effective']);
        $labels=['ai_only'=>'AI review','human_and_ai'=>'Human + AI',
            'human_only'=>'Manual review','inherit'=>'Inherit'];
        $mode=$e['company_local_override']??'inherit';
        $inherited_mode=($e['central_company_default']??'inherit')!=='inherit'
            ?$e['central_company_default']:$e['default_mode'];
        $effective=$e['mode']??'human_only';
        $source=$e['source']??'unknown';
        $source_labels=['central_inherited_default'=>'Central standard',
            'central_company_default'=>'Central company default',
            'company_local_override'=>'Company preference',
            'unconfigured_safe_fallback'=>'Safe fallback'];
        $mode_label=$labels[$effective]??$effective;
        $source_label=$source_labels[$source]??$source;
        $name=(string)($inherited['company_code']??'company');
        $jobs_value=!empty($jobs['queue_readback_ready'])
            ?number_format_i18n((int)$jobs['media_jobs_waiting_review']):'—';
        $applied_value=!empty($applied['ready'])
            ?number_format_i18n((int)$applied['applied_tour_families']):'—';
        $mcp_label=$mcp_write?'Eligible for OAuth-governed write'
            :($mcp_read?'OAuth server ready; client not verified here':'Not ready');
        $save_notice='';
        $notice=isset($_GET['media_notice'])&&is_string($_GET['media_notice'])
            ?sanitize_key(wp_unslash($_GET['media_notice'])):'';
        $notice_sig=isset($_GET['media_notice_token'])
            &&is_string($_GET['media_notice_token'])
            ?sanitize_text_field(wp_unslash($_GET['media_notice_token'])):'';
        if(in_array($notice,['changed','unchanged'],true)&&$notice_sig!==''
            &&wp_verify_nonce($notice_sig,
                'mad4b_media_policy_notice_'.$e['site_uuid'].'_'.
                $e['company_revision'].'_'.$e['company_local_override'].'_'.$notice))
            $save_notice=$notice;
        $settings_url=admin_url('admin.php?page=mad4b-control-plane-site-profile');
        $connection_url=admin_url('admin.php?page=mad4b-control-plane-connection');

        echo '<div class="wrap mad4b-company-control"><header class="mc-hero"><div>'.
            '<span class="mc-eyebrow">MAD4B / COMPANY CONTROL</span>'.
            '<h1>Company Media Control</h1>'.
            '<p>Manage this company’s media review preference and its own ChatGPT connection. Central policy, company authority and publishing permissions are separate.</p>'.
            '</div><span class="mc-tag">'.esc_html(ucwords(str_replace('-',' ',$name))).
            ' · '.esc_html(ucfirst((string)($profile['environment']??'production'))).
            '</span></header>';
        if($save_notice==='changed')
            echo '<div class="mc-saved" role="status"><strong>Saved and verified.</strong> The company preference was updated in WordPress and read back successfully. Central policy and OAuth settings are unchanged.</div>';
        elseif($save_notice==='unchanged')
            echo '<div class="mc-saved" role="status"><strong>No changes needed.</strong> This value already matches the company database setting.</div>';
        if(!$ready)echo '<div class="notice notice-error inline" role="alert"><p><strong>Policy readback failed.</strong> A stored option or central policy does not match this site. Editing is blocked until the source is reconciled.</p></div>';
        echo '<div class="mc-summary" aria-label="Current media review overview">';
        foreach([
            [$mode_label,'Effective review strategy', $source_label],
            [$labels[$inherited_mode]??$inherited_mode,'Inherited default','Read-only here'],
            [$applied_value,'Tours with Apply receipts','Does not certify frontend or image origin'],
            [$jobs_value,'Media Jobs awaiting review',!empty($jobs['bounded_to_first_100_jobs'])?'From first 100 jobs only':'Job ledger readback']
        ] as $metric){
            echo '<div class="mc-metric"><span>'.esc_html($metric[1]).'</span>'.
                '<strong>'.esc_html((string)$metric[0]).'</strong>'.
                '<small>'.esc_html($metric[2]).'</small></div>';
        }
        echo '</div>';
        echo '<section class="mc-section" aria-labelledby="company-review-heading">'.
            '<div class="mc-section-header"><div><h2 id="company-review-heading">Review strategy for this company</h2>'.
            '<p class="mc-muted">Your preference changes only this site’s review workflow; it never modifies the central administrator’s policy or OAuth permissions.</p></div>'.
            '<span class="mc-pill">Company revision '.esc_html((string)($e['company_revision']??0)).'</span></div>';
        echo '<div class="mc-current-state" aria-label="Live saved configuration">'.
            '<div><span class="mc-state-label">Saved company selection (WordPress DB)</span>'.
            '<strong>'.esc_html($stored_valid?($labels[$mode]??$mode):($stored_exists?'Invalid stored value':'Not yet saved — default inheritance')).'</strong>'.
            '<small>'.esc_html($stored_valid?'Stored as '.$mode.' · revision '.(int)$stored_option['revision']:'No valid company override record').'</small></div>'.
            '<div><span class="mc-state-label">Effective workflow now</span>'.
            '<strong>'.esc_html($mode_label).'</strong>'.
            '<small>'.esc_html($source_label.' · central revision '.(int)$e['central_revision']).'</small></div>'.
            '<div><span class="mc-state-label">Last saved</span>'.
            '<strong>'.esc_html($stored_when_utc).'</strong>'.
            '<small>'.esc_html($stored_actor).'</small></div></div>';
        echo '<form id="mad4b-media-company-form" data-persisted-mode="'.esc_attr($mode).'"'.
            ' data-inherited-mode="'.esc_attr($inherited_mode).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_media_policy_local');
        echo '<input type="hidden" name="action" value="mad4b_media_review_settings">'.
            '<input type="hidden" name="operation" value="local">'.
            '<input type="hidden" name="expected_site_uuid" value="'.esc_attr($e['site_uuid']).'">'.
            '<input type="hidden" name="expected_company_revision" value="'.esc_attr((string)$e['company_revision']).'">'.
            '<input type="hidden" name="expected_policy_fingerprint" value="'.esc_attr($e['policy_fingerprint']).'">'.
            '<input type="hidden" name="expected_saved_mode" value="'.esc_attr($mode).'">'.
            '<fieldset class="mc-policy-grid"'.($ready?'':' disabled').
            '><legend class="screen-reader-text">Company media review preference</legend>';
        $choices=[
            'inherit'=>['Use inherited default',
                'Follow the central standard for this company. Currently: '.($labels[$inherited_mode]??$inherited_mode).'.'],
            'ai_only'=>['AI review',
                'Use the AI review workflow with visual/source evidence. No automatic mass publication.'],
            'human_and_ai'=>['Human + AI',
                'AI prepares assessments and a company reviewer participates.'],
            'human_only'=>['Manual review',
                'A company reviewer decides after inspecting image evidence.']
        ];
        foreach($choices as $value=>$copy){
            $is_saved=$stored_valid&&$mode===$value;
            $is_effective=$effective===$value;
            $is_inherit=$mode==='inherit'&&$value==='inherit';
            echo '<label class="mc-option'.($is_saved?' is-persisted':'').
                ($is_effective?' is-effective':'').'" data-policy-mode="'.esc_attr($value).'">'.
                '<input type="radio" name="mode" value="'.
                esc_attr($value).'" '.checked($mode,$value,false).' required>'.
                '<span class="mc-choice-body"><b>'.esc_html($copy[0]).'</b>'.
                '<small>'.esc_html($copy[1]).'</small>'.
                '<span class="mc-option-markers">';
            if($is_saved)echo '<span class="mc-marker mc-marker-saved">Saved in database</span>';
            elseif(!$stored_exists&&$is_inherit)echo '<span class="mc-marker">Default (not saved)</span>';
            if($is_effective)echo '<span class="mc-marker mc-marker-effective">Effective now</span>';
            echo '</span></span></label>';
        }
        echo '</fieldset><div class="mc-controls">'.
            '<button id="mad4b-media-save" type="submit" class="button button-primary"'.
                ($ready?'':' disabled').'>Save company preference</button>'.
            '<span id="mad4b-media-unsaved" class="mc-footnote" role="status" aria-live="polite">'.
                esc_html($stored_valid?'Database selection loaded. No unsaved changes.':'Verify the selected preference before saving.').
            '</span></div></form>';
        echo '<div class="mc-guardrails"><div><strong>Independent policy boundaries</strong>'.
            '<p class="mc-muted">Central controls inherited defaults and central operator authority. The company manages only its own review preference. OAuth login, WordPress capabilities, actual media approval and Apply/rollback authorization are checked separately; changing this preference grants none of them.</p>'.
            '</div></div></section>';
        echo '<details class="mc-advanced"><summary>'.
            '<span>Connection and authority details</span>'.
            '<small>Company OAuth: '.esc_html($oauth_feature?'Enabled':'Disabled').
            ' · Expand for settings and diagnostics</small></summary><div class="mc-grid">';
        echo '<section class="mc-section" aria-labelledby="company-auth-heading"><h2 id="company-auth-heading">Company authentication</h2>'.
            '<p class="mc-muted">This site has its own Site Profile and OAuth subject binding, distinct from Tours.dev Central.</p>'.
            '<div class="mc-kv"><span>WordPress admin permission</span><b>Verified for this page; not an OAuth grant</b></div>'.
            '<div class="mc-kv"><span>Bound site UUID</span><b><code>'.
                esc_html($site['site_uuid']).'</code></b></div>'.
            '<div class="mc-kv"><span>Site Profile binding</span><b>'.esc_html($profile_bound?'Origin/environment/deployment verified':'Incomplete binding').'</b></div>'.
            '<div class="mc-kv"><span>Site Profile OAuth feature</span><b>'.esc_html($oauth_feature?'Enabled':'Disabled').'</b></div>'.
            '<div class="mc-kv"><span>OAuth resource readiness</span><b>'.esc_html($oauth_resource_ready?'Ready (not proof of live client session)':'Not ready').'</b></div>'.
            '<div class="mc-kv"><span>MCP company operations</span><b>'.esc_html($mcp_label).'</b></div>'.
            '<div class="mc-kv"><span>Site write feature</span><b>'.
                esc_html(class_exists('MAD4B_SCP_Site_Profile',false)
                    &&MAD4B_SCP_Site_Profile::write_enabled()?'Enabled':'Not enabled').'</b></div>';
        if(!$mcp_read)echo '<p class="mc-pill mc-pill-warning" role="status">Company OAuth is not ready. WordPress and host access are independent of MCP authentication; no verified ChatGPT client session is claimed.</p>';
        echo '<div class="mc-actions"><a class="mc-link" href="'.esc_url($settings_url).'">Open Site Profile</a>'.
            '<a class="mc-link" href="'.esc_url($connection_url).'">Connection &amp; OAuth diagnostics</a></div></section>';
        echo '<section class="mc-section" aria-labelledby="central-boundary-heading"><h2 id="central-boundary-heading">Who controls what?</h2>'.
            '<p class="mc-muted">Two separate operator contexts. Inheritance does not transfer login credentials or permissions.</p>'.
            '<div class="mc-kv"><span>Central admin</span><b>System defaults and fleet monitoring</b></div>'.
            '<div class="mc-kv"><span>Company admin</span><b>Local review strategy and company jobs</b></div>'.
            '<div class="mc-kv"><span>Company ChatGPT Client</span><b>Its own OAuth subject, if enabled</b></div>'.
            '<div class="mc-kv"><span>Host WP-CLI</span><b>Separate authorized host executor</b></div>'.
            '<ol class="mc-steps"><li>Configure this company’s OAuth in Site Profile.</li>'.
            '<li>Connect ChatGPT to this company’s MCP endpoint.</li>'.
            '<li>Read current state, plan a local change, then execute under the enrolled company identity.</li></ol>'.
            '<p class="mc-footnote">Photo provenance and license rights are not asserted by this setting.</p>'.
            '</section></div></details></div>';
    }
    public static function admin_save():void {
        if(!current_user_can('manage_options'))wp_die('Access denied',403);
        $op=sanitize_key((string)($_POST['operation']??''));
        if($op==='central'||$op==='company-default'){
            if(!self::central())wp_die('Central scope required',403);
            $code=$op==='company-default'?sanitize_key((string)($_POST['company_code']??'')):'';
            check_admin_referer($op==='central'?'mad4b_media_policy_central_default':'mad4b_media_policy_company_'.$code);
            $r=self::mutate_central(sanitize_key((string)($_POST['default_mode']??'')),
                $code,sanitize_key((string)($_POST['company_default_mode']??'')));
            $target=admin_url('admin.php?page=mad4b-media-review-fleet');
        }elseif($op==='local'){
            if(self::central()||!self::site())
                wp_die('Company Site Profile required',403);
            check_admin_referer('mad4b_media_policy_local');
            // Prevent an old tab from overwriting a newer company or central
            // policy. A changed central revision also makes the plan stale.
            $before=self::effective();
            $stored=get_option(self::LOCAL_OPTION,null);
            if(!$before['policy_ready']
               ||($stored!==null&&(!is_array($stored)
                   ||($stored['contract']??'')!==self::CONTRACT
                   ||($stored['site_uuid']??'')!==$before['site_uuid']
                   ||self::normalize_mode($stored['mode']??'',true)===''
                   ||!is_int($stored['revision']??null))))
                wp_die('Stored company policy is invalid or the central policy is not synchronized. Reconcile the source before changing settings.','Media policy readback error',['response'=>409]);
            $posted_revision=(string)wp_unslash($_POST['expected_company_revision']??'');
            $posted_site=(string)wp_unslash($_POST['expected_site_uuid']??'');
            $posted_fp=(string)wp_unslash($_POST['expected_policy_fingerprint']??'');
            $posted_mode=sanitize_key((string)wp_unslash($_POST['expected_saved_mode']??''));
            $selected=sanitize_key((string)wp_unslash($_POST['mode']??''));
            if($posted_revision===''||!ctype_digit($posted_revision)
               ||!preg_match('/^[a-f0-9]{64}$/D',$posted_fp)
               ||!is_string($posted_site)
               ||!hash_equals((string)$before['site_uuid'],$posted_site)
               ||(int)$posted_revision!==(int)$before['company_revision']
               ||!hash_equals((string)$before['policy_fingerprint'],$posted_fp)
               ||$posted_mode!==$before['company_local_override'])
                wp_die('This company or central setting changed since you opened the page. Reload the page to review the latest saved preference before saving.','Outdated policy form',['response'=>409]);
            $changed=$selected!==$before['company_local_override'];
            $r=self::mutate_local($selected);
            $target=admin_url('tools.php?page=mad4b-company-media-review');
            if(!is_wp_error($r)){
                $after=get_option(self::LOCAL_OPTION,null);
                if(($r['site_uuid']??'')!==$before['site_uuid']
                   ||($r['company_local_override']??'')!==$selected
                   ||(int)($r['company_revision']??-1)!==
                      (int)$before['company_revision']+($changed?1:0)
                   ||($changed&&(!is_array($after)
                       ||($after['mode']??'')!==$selected
                       ||(int)($after['revision']??-1)!==(int)$r['company_revision'])))
                    $r=self::fail('mad4b_media_company_save_readback_mismatch');
            }
            if(!is_wp_error($r)){
                $notice=$changed?'changed':'unchanged';
                $signature=wp_create_nonce(
                    'mad4b_media_policy_notice_'.$r['site_uuid'].'_'.
                    $r['company_revision'].'_'.$r['company_local_override'].'_'.$notice);
                $target=add_query_arg(['media_notice'=>$notice,
                    'media_notice_token'=>$signature],$target);
            }
        }else wp_die('Invalid settings operation',400);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),'Media policy error',['response'=>409]);
        wp_safe_redirect($target,303);exit;
    }
    public static function routes():void {
        register_rest_route('mad4b-company-management/v1','/media-review/fleet',[
            'methods'=>'GET',
            'permission_callback'=>static function(){
                return self::central()&&current_user_can('manage_options');
            },
            'callback'=>static function(){
                $r=self::fleet_export();
                if(is_wp_error($r))return $r;
                $r['telemetry']=get_option(self::TELEMETRY_OPTION,[]);
                $r['read_only']=true;
                return rest_ensure_response($r);
            }]);
    }
}
MAD4B_Media_Review_Hierarchy_V1::init();
if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-policy',[MAD4B_Media_Review_Hierarchy_V1::class,'cli']);

