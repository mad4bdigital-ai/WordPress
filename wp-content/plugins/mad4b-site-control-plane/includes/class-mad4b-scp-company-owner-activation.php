<?php
/** One-time native Staging owner setup for company roles and JetEngine pages. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Owner_Activation {
    const AUDIT='mad4b_company_owner_activation_audit_v1';
    const CONSENT='ACTIVATE COMPANY OPERATIONS ON STAGING';
    const PAGE='mad4b-company-owner-activation';
    public static function browserOwnerAuthorized():bool {
        if(wp_get_environment_type()!=='staging'||!defined('WP_ENVIRONMENT_TYPE')||WP_ENVIRONMENT_TYPE!=='staging'
            ||!current_user_can('manage_options')||!current_user_can('activate_plugins'))return false;
        $cookie=defined('LOGGED_IN_COOKIE')?(string)($_COOKIE[LOGGED_IN_COOKIE]??''):'';
        $uid=$cookie!==''?wp_validate_auth_cookie($cookie,'logged_in'):false;
        $session=function_exists('wp_get_session_token')?(string)wp_get_session_token():'';
        $nonce=(string)($_SERVER['HTTP_X_WP_NONCE']??'');
        return $uid && (int)$uid===get_current_user_id() && $session!=='' && $nonce!==''
            && (bool)wp_verify_nonce($nonce,'wp_rest');
    }
    private static function pageSchemas():array {
        $path=dirname(__DIR__).'/data/company-communication-page-schema.json';
        $original=json_decode((string)file_get_contents($path),true);
        if(!is_array($original)||count($original['fields']??[])!==24 ||
          ($original['general_settings']['slug']??'')!=='company-communication-operations')
            throw new RuntimeException('Bundled JetEngine page schema invalid.');
        $g=$original['general_settings'];
        $g['parent']='mad4b-company';$g['capability']='mad4b_manage_company_operations';
        $p1=array_merge($g,$original['labels'],['fields'=>$original['fields']]);
        $spec=[
            'hard_delete_policy'=>['disabled','guarded'],
            'price_edit_policy'=>['allowed','blocked'],
            'content_publication_policy'=>['direct','review'],
            'task_offer_ttl_hours'=>['24','48','72','168'],
            'max_staff_accounts'=>['20','60','120']];
        $fields=[];$i=802100;
        foreach($spec as$k=>$choices){
            $options=[];foreach($choices as$choice)$options[]=['key'=>$choice,'value'=>$choice];
            $fields[]=['name'=>$k,'title'=>ucwords(str_replace('_',' ',$k)),
                'type'=>'select','object_type'=>'field','id'=>++$i,
                'options_source'=>'manual','options'=>$options,'width'=>'100%'];
        }
        $p2=['slug'=>'company-operations-policies','name'=>'Company Operations Policies',
            'menu_name'=>'Operational Policies','parent'=>'mad4b-company',
            'icon'=>'dashicons-admin-generic','capability'=>'mad4b_manage_company_operations',
            'position'=>84,'storage_type'=>'default','option_prefix'=>true,
            'autoload_option'=>false,'hide_field_names'=>false,'fields'=>$fields];
        return [$p1,$p2];
    }
    private static function existing():array {
        if(!function_exists('jet_engine')||!isset(jet_engine()->options_pages))
            throw new RuntimeException('JetEngine Options unavailable.');
        $matches=[];
        foreach(jet_engine()->options_pages->get_items()as$p){
            if(in_array($p['slug']??'',['company-communication-operations','company-operations-policies'],true))
                $matches[$p['slug']]=$p;
        }
        return $matches;
    }
    public static function planData():array {
        $site=MAD4B_SCP_Site_Profile::status();
        $profile=MAD4B_SCP_Site_Profile::profile();
        $version=defined('MAD4B_SCP_VERSION')?MAD4B_SCP_VERSION:'unknown';
        $role=get_role('master_admin');
        $existing=self::existing();
        $problems=[];
        if(wp_get_environment_type()!=='staging' || !defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE!=='staging')$problems[]='environment_not_staging';
        if(($profile['environment']??'')!=='staging')$problems[]='site_profile_not_staging';
        if(!($site['authority_ready']??false))$problems[]='site_profile_not_ready';
        if(!$role)$problems[]='master_admin_missing';
        if($version!=='0.4.0-rc.109')$problems[]='unexpected_release_version';
        foreach($existing as$slug=>$page){
            if(($page['parent']??'')!=='mad4b-company' ||
              ($page['capability']??'')!=='mad4b_manage_company_operations')
                $problems[]='existing_page_contract_conflict_'.$slug;
        }
        $schema=self::pageSchemas();
        $roleCaps=$role?(array)$role->capabilities:[];
        ksort($roleCaps);
        $core=['release'=>$version,'environment'=>wp_get_environment_type(),
            'site_uuid'=>(string)($profile['site_uuid']??''),
            'site_profile_revision'=>(int)($profile['revision']??0),
            'master_role_hash'=>hash('sha256',wp_json_encode($roleCaps)),
            'existing_pages'=>array_map(static fn($x)=>(int)$x['id'],$existing),
            'schemas'=>array_map(static fn($x)=>[$x['slug'],count($x['fields'])],$schema),
            'role_migration'=>(string)get_option(MAD4B_SCP_Company_Master_Admin::MIGRATION,'unapplied')];
        $digest=hash('sha256',wp_json_encode($core));
        return ['contract'=>'mad4b.company.owner-activation.plan.v1','site'=>$core,
            'plan_sha256'=>$digest,'can_apply'=>!$problems,'blockers'=>$problems,
            'requires_browser_cookie_nonce'=>true,
            'requires_confirmation'=>self::CONSENT,
            'changes'=>['migrate_master_role','create_company_translation_manager_role',
                'create_missing_jetengine_options_pages','append_operator_audit'],
            'does_not_change'=>['site_profile','managed_write_grants','plugins','smtp_credentials',
                'email_delivery','production','existing_customer_orders']];
    }
    public static function plan($req){
        $r=rest_ensure_response(self::planData());
        $r->header('Cache-Control','private, no-store');return $r;
    }
    public static function ownerPermission(){
        if(!current_user_can('manage_options')||!current_user_can('activate_plugins'))
            return new WP_Error('company_owner_required','Real WordPress site administrator required.',['status'=>403]);
        return true;
    }
    public static function commit($req){
        if(!self::browserOwnerAuthorized())
            return new WP_Error('company_owner_browser_session_required','Verified browser cookie/session and WordPress REST nonce required.',['status'=>403]);
        if((string)$req->get_param('confirmation')!==self::CONSENT)
            return new WP_Error('company_activation_confirmation_invalid','Exact confirmation missing.',['status'=>422]);
        global $wpdb;
        $key='mad4b:company-owner-activation:'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$key))!==1)
            return new WP_Error('company_activation_busy','Concurrent company activation.', ['status'=>423]);
        try{
            $plan=self::planData();
            if(!$plan['can_apply'])return new WP_Error('company_activation_preflight_blocked','Source preflight failed.', ['status'=>409,'blockers'=>$plan['blockers']]);
            if(!hash_equals($plan['plan_sha256'],(string)$req->get_param('expected_plan_sha256')))
                return new WP_Error('company_activation_stale_plan','Plan changed. Reload and confirm.', ['status'=>409]);
            $rolesBefore=get_option($wpdb->prefix.'user_roles');
            $versionBefore=get_option(MAD4B_SCP_Company_Master_Admin::MIGRATION,null);
            $created=[];
            $phase='preparing_jetengine';
            try{
                $data=jet_engine()->options_pages->data;
                $existing=self::existing();
                foreach(self::pageSchemas() as$schema){
                    $slug=$schema['slug'];
                    if(isset($existing[$slug]))continue;
                    $phase='create_jetengine_page_'.$slug;
                    $data->set_request($schema);
                    $id=$data->create_item(false);
                    if(!$id)throw new RuntimeException('JetEngine refused page create '.$slug);
                    $created[]=(int)$id;
                    // JetEngine options-page get_items() populates a per-request
                    // query cache before create_item(). get_item_for_edit() then
                    // reads that stale cache and falsely reports the new record
                    // missing. The native uncached query is the proper readback.
                    $readRows=$data->db->query(
                        $data->table,['id'=>(int)$id],[$data,'filter_item_for_edit'],false
                    );
                    $read=$readRows[0]??false;
                    if(!is_array($read)||($read['general_settings']['slug']??'')!==$slug||
                       ($read['general_settings']['capability']??'')!=='mad4b_manage_company_operations'||
                       count($read['fields']??[])!==count($schema['fields']))
                        throw new RuntimeException('JetEngine native readback mismatch '.$slug);
                }
                $phase='migrate_company_roles';
                MAD4B_SCP_Company_Master_Admin::applyOwnerApprovedMigration();
                $role=get_role('master_admin');
                if(!$role || !$role->has_cap(MAD4B_SCP_Company_Master_Admin::CAP) ||
                    $role->has_cap('promote_users')||$role->has_cap('manage_options')||
                    $role->has_cap('activate_plugins')||!get_role('company_translation_manager'))
                    throw new RuntimeException('Role migration or forbidden role cap mismatch.');
                $phase='write_activation_audit';
                $audit=(array)get_option(self::AUDIT,[]);
                $audit[]=['at'=>current_time('mysql',true),'actor_id'=>get_current_user_id(),
                    'plan_sha256'=>$plan['plan_sha256'],'site_uuid'=>$plan['site']['site_uuid'],
                    'source_release'=>$plan['site']['release'],'created_option_page_ids'=>$created,
                    'result'=>'committed'];
                update_option(self::AUDIT,array_slice($audit,-20),false);
                return rest_ensure_response(['status'=>'COMMITTED','roles_verified'=>true,
                    'created_page_ids'=>$created,'plan_sha256'=>$plan['plan_sha256'],
                    'site_profile_unchanged'=>true,'smtp_unchanged'=>true]);
            }catch(Throwable $e){
                $incident=substr(hash('sha256',current_time('mysql',true).'|'.$plan['plan_sha256'].'|'.$phase),0,16);
                $rollbackProblems=[];
                try{
                    if($rolesBefore!==false){
                        update_option($wpdb->prefix.'user_roles',$rolesBefore);
                        wp_roles()->for_site(get_current_blog_id());
                    }
                    if($versionBefore===null)delete_option(MAD4B_SCP_Company_Master_Admin::MIGRATION);
                    else update_option(MAD4B_SCP_Company_Master_Admin::MIGRATION,$versionBefore);
                    foreach(array_reverse($created)as$id){
                        $data->set_request(['id'=>$id]);
                        $data->delete_item(false);
                        $left=$data->db->query($data->table,['id'=>(int)$id],null,false);
                        if($left)$rollbackProblems[]='jetengine_page_still_present';
                    }
                    if($rolesBefore!==false && get_option($wpdb->prefix.'user_roles')!==$rolesBefore)
                        $rollbackProblems[]='role_snapshot_mismatch';
                    if(get_option(MAD4B_SCP_Company_Master_Admin::MIGRATION,null)!==$versionBefore)
                        $rollbackProblems[]='migration_marker_mismatch';
                }catch(Throwable $rollbackException){
                    $rollbackProblems[]='rollback_exception';
                }
                $rollbackVerified=empty($rollbackProblems);
                // Keep a sanitized server-side diagnostic with no user input/credentials.
                error_log('[MAD4B Company Activation] incident='.$incident
                    .' phase='.$phase.' exception='.get_class($e)
                    .' rollback_verified='.($rollbackVerified?'yes':'no')
                    .' rollback_problems='.implode(',',$rollbackProblems));
                $code=$rollbackVerified?'company_activation_rolled_back':'company_activation_rollback_unverified';
                return new WP_Error($code,
                    $rollbackVerified?'Activation failed; all recorded changes were rolled back.':
                                      'Activation failed; rollback verification requires operator review.',
                    ['status'=>502,'incident'=>$incident,'phase'=>$phase,
                     'rollback_verified'=>$rollbackVerified]);
            }
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    public static function adminPage():void {
        if(!current_user_can('manage_options')||!current_user_can('activate_plugins'))wp_die('Administrator required');
        echo '<div class="wrap"><h1>Activate Company Operations — Staging</h1>';
        echo '<p>One-time, tenant-scoped WordPress role and JetEngine Options registration. No SMTP sending, central write approval or Production change.</p>';
        echo '<p><strong>Review the exact release preflight before activating.</strong></p>';
        echo '<pre id="mad4b-company-activation-plan">Loading secured plan…</pre>';
        echo '<button class="button button-primary" id="mad4b-company-activation-commit" type="button" disabled>Activate on this Staging site</button>';
        echo '<pre id="mad4b-company-activation-result"></pre></div>';
        $base=esc_url_raw(rest_url('mad4b/v1/company-activation'));
        $nonce=wp_create_nonce('wp_rest');
        $js='(async()=>{const base='.wp_json_encode($base).',nonce='.wp_json_encode($nonce).';';
        $js.='const planEl=document.getElementById("mad4b-company-activation-plan"),btn=document.getElementById("mad4b-company-activation-commit"),result=document.getElementById("mad4b-company-activation-result");';
        $js.='const h={"X-WP-Nonce":nonce,"Accept":"application/json"};';
        $js.='try{const r=await fetch(base+"/plan",{credentials:"same-origin",headers:h});const p=await r.json();planEl.textContent=JSON.stringify(p,null,2);if(!r.ok||!p.can_apply) return;btn.disabled=false;';
        $js.='btn.onclick=async()=>{btn.disabled=true;const phrase="'.self::CONSENT.'";if(prompt("Type the following confirmation exactly: "+phrase)!==phrase){result.textContent="Confirmation cancelled";btn.disabled=false;return;}';
        $js.='const c=await fetch(base+"/commit",{method:"POST",credentials:"same-origin",headers:{...h,"Content-Type":"application/json"},body:JSON.stringify({confirmation:phrase,expected_plan_sha256:p.plan_sha256})});result.textContent=JSON.stringify(await c.json(),null,2);if(!c.ok)btn.disabled=false;};}catch(e){result.textContent=String(e);}})();';
        echo '<script>'. $js .'</script>';
    }
    public static function register():void {
        add_action('admin_menu',static function(){add_management_page('Company Activation','Company Activation','manage_options',self::PAGE,[__CLASS__,'adminPage']);},30);
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/company-activation/plan',['methods'=>'GET',
                'permission_callback'=>[__CLASS__,'ownerPermission'],'callback'=>[__CLASS__,'plan']]);
            register_rest_route('mad4b/v1','/company-activation/commit',['methods'=>'POST',
                'permission_callback'=>[__CLASS__,'ownerPermission'],'callback'=>[__CLASS__,'commit']]);
        },30);
    }
}
MAD4B_SCP_Company_Owner_Activation::register();
