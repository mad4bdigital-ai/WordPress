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
        $active=[];$rollback=0;
        foreach($rows as $raw){
            $r=maybe_unserialize($raw);
            if(!is_array($r)||($r['contract']??'')!=='mad4b.tour-media-rollback.v1'
               ||($r['site_uuid']??'')!==$site['site_uuid'])continue;
            $post=absint($r['source_post_id']??0);
            if(!$post||get_post_type($post)!=='tours-and-activities')continue;
            if(($r['status']??'')==='rolled_back')$rollback++;
            elseif(($r['status']??'')==='applied')$active[$post]=true;
        }
        return ['ready'=>true,'applied_tour_families'=>count($active),
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
    public static function menu():void {
        if(self::central()){
            add_submenu_page('mad4b-company-brand-readiness','Media Review Fleet',
                'Media Review Fleet','manage_options','mad4b-media-review-fleet',
                [__CLASS__,'central_screen']);
        }
        add_management_page('Media Review Policy','Media Review Policy',
            'manage_options','mad4b-company-media-review',
            [__CLASS__,'local_screen']);
    }
    public static function central_screen():void {
        if(!current_user_can('manage_options')||!self::central())
            wp_die('Central administrator required');
        $c=self::central_policy();
        $states=get_option(self::TELEMETRY_OPTION,[]);
        echo '<div class="wrap"><h1>MAD4B Central / Media Review Fleet</h1>';
        echo '<p>Central defaults → company default → local company override. This controls photo review only. A real visual evidence manifest and a separately authorized host execution are required to update tour media.</p>';
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
        echo '</tbody></table><p>Company local overrides have higher precedence. Readback is supplied only by the bound host WP-CLI collector, not by unauthenticated REST calls.</p></div>';
    }
    public static function local_screen():void {
        if(!current_user_can('manage_options')||!self::site())wp_die('Company administrator required');
        $e=self::effective();
        echo '<div class="wrap"><h1>MAD4B Media Review / Company Override</h1>';
        echo '<p>Central inherited default: <strong>'.esc_html($e['default_mode']).'</strong> · Central company default: <strong>'.esc_html($e['central_company_default']).'</strong> · Effective: <strong>'.esc_html($e['mode']).'</strong> · Source: '.esc_html($e['source']).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_media_policy_local');
        echo '<input type="hidden" name="action" value="mad4b_media_review_settings"><input type="hidden" name="operation" value="local"><label>Company policy <select name="mode">';
        foreach(['inherit'=>'Inherit central','ai_only'=>'AI only','human_and_ai'=>'Human + AI','human_only'=>'Human only'] as $value=>$label)
            echo '<option value="'.esc_attr($value).'" '.selected($e['company_local_override'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <button class="button button-primary">Save company override</button></form>';
        echo '<p>Visual review and source match remain mandatory. This setting never grants WordPress publishing permissions, certifies photo authenticity, or enables automatic mass replacement.</p></div>';
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
            check_admin_referer('mad4b_media_policy_local');
            $r=self::mutate_local(sanitize_key((string)($_POST['mode']??'')));
            $target=admin_url('tools.php?page=mad4b-company-media-review');
        }else wp_die('Invalid settings operation',400);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),'Media policy error',['response'=>409]);
        wp_safe_redirect(add_query_arg('saved','1',$target),303);exit;
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

