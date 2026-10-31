<?php
/** Site-bound test Brand Context administration. Never an external source transfer. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Staging_Brand_Trial {
    const MENU='mad4b-staging-brand-trial';
    public static function canReview(){
        return wp_get_environment_type()==='staging'
            && current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function plan($request){
        $plan=MAD4B_SCP_Context_Authority::staging_trial_plan();
        if(is_wp_error($plan))return $plan;
        $response=rest_ensure_response($plan);
        $response->header('Cache-Control','private, no-store');return $response;
    }
    public static function create($request){
        $result=MAD4B_SCP_Context_Authority::staging_trial_create((array)$request->get_json_params());
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store');return $response;
    }
    public static function rename($request){
        $result=MAD4B_SCP_Context_Authority::staging_trial_rename((array)$request->get_json_params());
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store');return $response;
    }
    public static function restore($request){
        $result=MAD4B_SCP_Context_Authority::staging_trial_restore((array)$request->get_json_params());
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store');return $response;
    }
    public static function page():void {
        if(!self::canReview())wp_die('WordPress Administrator on Staging required.');
        $base=rest_url('mad4b/v1/brand-staging-trial');
        $nonce=wp_create_nonce('wp_rest');
        echo '<div class="wrap"><h1>Brand Context — Staging Test Enrollment</h1>';
        echo '<p>Creates one new TEST-ONLY Brand identity for this exact Staging Site Profile. Original external-owned Brand Context remains in protected rollback evidence. No data, assets, mail, WooCommerce or Production authority is transferred.</p>';
        $suggested=sanitize_text_field((string)get_option('blogname','Travel Demo'));
        if (!preg_match('/TEST|تجريبي|اختبار/iu',$suggested)) $suggested.=' TEST Demo';
        echo '<p><label for="mad4b-test-brand-name">Test Brand name</label> ';
        echo '<input id="mad4b-test-brand-name" class="regular-text" maxlength="85" value="'.esc_attr($suggested).'" /></p>';
        echo '<pre id="mad4b-staging-trial-plan">Loading secure plan…</pre>';
        echo '<button class="button button-primary" type="button" id="mad4b-staging-trial-create" disabled>Create TEST Brand on Staging</button> ';
        echo '<button class="button" type="button" id="mad4b-staging-trial-restore" disabled>Restore original legacy Brand</button>';
        echo ' <button class="button" type="button" id="mad4b-staging-trial-rename" disabled>Rename current TEST Brand</button>';
        echo '<pre id="mad4b-staging-trial-result"></pre></div>';
        $js='(async()=>{const base='.wp_json_encode($base).',nonce='.wp_json_encode($nonce).';';
        $js.='const planBox=document.getElementById("mad4b-staging-trial-plan"),result=document.getElementById("mad4b-staging-trial-result"),';
        $js.='create=document.getElementById("mad4b-staging-trial-create"),restore=document.getElementById("mad4b-staging-trial-restore"),rename=document.getElementById("mad4b-staging-trial-rename"),name=document.getElementById("mad4b-test-brand-name");';
        $js.='const headers={"X-WP-Nonce":nonce,"Content-Type":"application/json"};let plan;';
        $js.='async function load(){let r=await fetch(base+"/plan",{credentials:"same-origin",headers});plan=await r.json();planBox.textContent=JSON.stringify(plan,null,2);';
        $js.='create.disabled=!r.ok||!plan.can_create;restore.disabled=true;rename.disabled=true;';
        $js.='if(plan.blockers?.includes("site_brand_already_enrolled")){let r2=await fetch(base+"/status",{credentials:"same-origin",headers});let s=await r2.json();restore.disabled=!s.test_active;rename.disabled=!s.test_active || s.email_send_allowed;window.__trial_status=s;}}';
        $js.='async function perform(path,data,phrase){if(prompt("Type this exact phrase to confirm: "+phrase)!==phrase)return;create.disabled=true;restore.disabled=true;rename.disabled=true;';
        $js.='try{const r=await fetch(base+path,{method:"POST",credentials:"same-origin",headers,body:JSON.stringify({...data,confirmation:phrase})});';
        $js.='result.textContent=JSON.stringify(await r.json(),null,2);}catch(e){result.textContent=String(e);}finally{await load();}}';
        $js.='create.onclick=()=>perform("/create",{expected_plan_sha256:plan.plan_sha256,brand_name:name.value},"CREATE NEW STAGING TEST BRAND");';
        $js.='restore.onclick=()=>perform("/restore",{expected_trial_id:window.__trial_status?.trial_id},"RESTORE ORIGINAL STAGING BRAND RECORD");';
        $js.='rename.onclick=()=>perform("/rename",{expected_trial_id:window.__trial_status?.trial_id,expected_revision:window.__trial_status?.test_revision,expected_profile_sha256:window.__trial_status?.profile_sha256,brand_name:name.value},"RENAME STAGING TEST BRAND");';
        $js.='try{await load()}catch(e){result.textContent=String(e)}})();';
        echo '<script>'. $js .'</script>';
    }
    public static function status($request){
        $current=MAD4B_SCP_Context_Authority::profile();
        $archive=get_option(MAD4B_SCP_Context_Authority::STAGING_TRIAL_ARCHIVE,false);
        $active=is_array($archive) && is_array($current) &&
            ($current['status']??'')==='test_only' &&
            ($current['brand_id']??'')===($archive['trial_id']??'');
        $r=rest_ensure_response(array('contract'=>'mad4b.staging-brand-trial-status.v1',
            'test_active'=>$active,'trial_id'=>$active?$current['brand_id']:null,
            'test_brand_name'=>$active?$current['brand_name']:null,
            'test_revision'=>$active?(int)($current['revision']??0):null,
            'profile_sha256'=>$active?hash('sha256',wp_json_encode($current)):null,
            'legacy_archive_exists'=>is_array($archive),
            'email_send_allowed'=>class_exists('MAD4B_SCP_Company_Communication')?
                MAD4B_SCP_Company_Communication::canSend():false));
        $r->header('Cache-Control','private, no-store');return $r;
    }
    public static function boot():void {
        if(wp_get_environment_type()!=='staging')return;
        MAD4B_SCP_Admin_Route_Registry::register(self::MENU,'manage_options');
        MAD4B_SCP_Admin_Route_Registry::schedule_submenu(static function(){
            add_submenu_page('mad4b-control-plane','Staging Test Brand','Staging Test Brand',
                'manage_options',self::MENU,[__CLASS__,'page']);
        },70);
        add_action('rest_api_init',static function(){
            foreach(array('plan'=>'GET','status'=>'GET','create'=>'POST','restore'=>'POST','rename'=>'POST') as$action=>$method){
                register_rest_route('mad4b/v1','/brand-staging-trial/'.$action,[
                    'methods'=>$method,'callback'=>[__CLASS__,$action],
                    'permission_callback'=>[__CLASS__,'canReview']]);
            }
        },35);
    }
}
MAD4B_SCP_Staging_Brand_Trial::boot();
