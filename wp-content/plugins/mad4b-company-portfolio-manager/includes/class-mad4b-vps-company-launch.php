<?php
/**
 * Plugin Name: MAD4B Company Launch Orchestrator — Local VPS
 * Description: Owner-governed workflow for tourism company intake and production preflight.
 * Version: 1.0.0
 * Source: authorized live VPS. GitHub is not an operational dependency.
 *
 * This module never executes shell commands or enables commercial access from HTTP.
 * Provider mutations require separately certified adapters and owner approval.
 */
defined('ABSPATH') || exit;
final class MAD4B_VPS_Company_Launch {
    const TYPE='mad4b_company_launch';
    const META='_mad4b_company_launch_v1';
    const PROOF=WPMU_PLUGIN_DIR.'/mad4b-company-launch-source-proof.json';
    const VERSION='mad4b.company-launch.vps.v1';
    const COLORS=array('primary'=>'#173A4C','secondary'=>'#235E6D','accent'=>'#BB9253','text'=>'#173A4C');
    private static $booted=false;
    public static function boot() {
        if(self::$booted)return;
        self::$booted=true;
        add_action('init',array(__CLASS__,'register'),8);
        add_action('rest_api_init',array(__CLASS__,'rest'),38);
        add_action('admin_menu',array(__CLASS__,'admin_menu'),40);
        add_shortcode('mad4b_start_tourism_company',array(__CLASS__,'form'));
    }
    public static function register() {
        register_post_type(self::TYPE,array(
            'labels'=>array('name'=>'Tourism Company Applications','singular_name'=>'Company Application'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>'tools.php',
            'show_in_rest'=>false,'exclude_from_search'=>true,
            'supports'=>array('title'),'capability_type'=>'post','map_meta_cap'=>true
        ));
    }
    private static function fail($id,$message,$status=422) {
        return new WP_Error($id,$message,array('status'=>$status));
    }
    private static function wrap($payload,$status=200) {
        if(is_wp_error($payload))return $payload;
        $r=new WP_REST_Response($payload,$status);
        $r->header('Cache-Control','no-store, private');
        return $r;
    }
    public static function owner_read() {
        return current_user_can('manage_options')?true:self::fail('mad4b_launch_admin_required','Owner administrator required.',403);
    }
    public static function owner_write() {
        // Use one owner-principal contract for both legacy intake and the
        // stage-specific workflow. No application-password or delegated agent
        // may impersonate an interactive owner approval.
        if(!class_exists('MAD4B_Tourism_Workflow_REST'))
            return self::fail('mad4b_launch_authority_unavailable',
                'Workflow owner authority unavailable.',503);
        return MAD4B_Tourism_Workflow_REST::write_permission();
    }
    public static function rest() {
        foreach(array(
            array('GET','/schema','schema','public_read'),
            array('POST','/applications','intake','public_intake'),
            array('GET','/applications','list_applications','owner_read'),
            array('GET','/applications/(?P<id>[1-9][0-9]{0,9})','status','owner_read'),
            array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/plan','plan','owner_read'),
            array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/approve','approve','owner_write'),
            array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/profile-intent','profile_intent','owner_write'),
            array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/readiness','readiness','owner_read'),
        ) as $route) {
            register_rest_route('mad4b/v1','/tourism-launch'.$route[1],array(
                'methods'=>$route[0],'callback'=>array(__CLASS__,$route[2]),
                'permission_callback'=>array(__CLASS__,$route[3])
            ));
        }
    }
    public static function public_read(){return true;}
    public static function public_intake(){return true;}
    public static function normalize($input) {
        if(!is_array($input))return self::fail('mad4b_launch_json_required','JSON body required.');
        $name=trim((string)($input['brand_name']??''));
        $email=trim((string)($input['email']??''));
        $phone=trim((string)($input['phone_e164']??''));
        if(function_exists('mb_strlen') ? (mb_strlen($name)<3||mb_strlen($name)>90) :
            (strlen($name)<3||strlen($name)>150))return self::fail('mad4b_launch_name_required','Company brand name required (3–90 characters).');
        if(preg_match('/[<>\\x00-\\x1f]/u',$name))return self::fail('mad4b_launch_brand_unsafe','Invalid brand characters.');
        if(!is_email($email)||strlen($email)>254)return self::fail('mad4b_launch_email_required','Valid company email required.');
        if(!preg_match('/^\\+[1-9][0-9]{7,14}$/D',$phone))return self::fail('mad4b_launch_phone_required','Valid E.164 company phone required.');
        $slug=trim((string)($input['company_code']??''));
        if($slug==='')$slug=sanitize_title($name);
        $slug=strtolower($slug);
        if(!preg_match('/^[a-z][a-z0-9-]{2,46}[a-z0-9]$/D',$slug)) {
            $slug='tour-'.substr(hash('sha256',$name.'|'.$email),0,12);
        }
        $colors=self::COLORS;
        $sources=array('name'=>'company_input','email'=>'company_input','phone'=>'company_input',
            'logo'=>'neutral_demo_placeholder','colors'=>'neutral_demo_palette','domain'=>'generated_mad4b_subdomain');
        if(isset($input['colors']) && is_array($input['colors'])) {
            foreach(array('primary','secondary','accent','text') as $k) {
                if(!array_key_exists($k,$input['colors']))continue;
                $val=strtoupper(trim((string)$input['colors'][$k]));
                if(!preg_match('/^#[A-F0-9]{6}$/D',$val))return self::fail('mad4b_launch_colors_invalid','Invalid hexadecimal color.');
                $colors[$k]=$val;
            }
            $sources['colors']='company_input';
        }
        $logo=trim((string)($input['logo_url']??''));
        if($logo!=='') {
            if(strlen($logo)>600 || !wp_http_validate_url($logo) ||
                wp_parse_url($logo,PHP_URL_SCHEME)!=='https' ||
                wp_parse_url($logo,PHP_URL_USER)!==null ||
                wp_parse_url($logo,PHP_URL_PASS)!==null)
                return self::fail('mad4b_launch_logo_invalid','Optional logo must be a safe HTTPS reference.');
            $sources['logo']='company_link_not_yet_media_verified';
        }
        $mode=(string)($input['domain_mode']??'subdomain');
        if(!in_array($mode,array('subdomain','custom_domain'),true))
            return self::fail('mad4b_launch_domain_mode_invalid','Choose custom_domain or subdomain.');
        $domain=$slug.'.mad4b.com';
        if($mode==='custom_domain') {
            $domain=strtolower(trim((string)($input['domain']??'')));
            if(!class_exists('MAD4B_SCP_Company_Domain_Cloudflare') ||
                !MAD4B_SCP_Company_Domain_Cloudflare::valid_host($domain) ||
                $domain==='mad4b.com'||substr($domain,-10)==='.mad4b.com') {
                return self::fail('mad4b_launch_domain_invalid','Valid external company-owned domain is required.');
            }
            $sources['domain']='requested_unverified_domain';
        }
        if(($input['consent']??false)!==true)return self::fail('mad4b_launch_consent','Data processing consent is required.');
        return array(
            'company_code'=>$slug,'brand_name'=>$name,'email'=>strtolower($email),
            'phone_e164'=>$phone,'logo_url'=>$logo,
            'colors'=>$colors,'domain_mode'=>$mode,'requested_domain'=>$domain,
            'default_bootstrap_domain'=>$slug.'.mad4b.com',
            'field_sources'=>$sources,'demo_visuals_customizable'=>true,
            'email_verified'=>false,'phone_verified'=>false,
            'logo_ownership_verified'=>false,'company_terms_approved'=>false,
            'mcp_suggestions_approved'=>false
        );
    }
    public static function schema() {
        return self::wrap(array('contract'=>self::VERSION,
            'required'=>array('brand_name','email','phone_e164','consent'),
            'optional'=>array('company_code','logo_url','colors','domain_mode','domain'),
            'defaults'=>array('domain_mode'=>'subdomain','colors'=>self::COLORS,'logo'=>'neutral_demo_placeholder'),
            'ai_proposals'=>'advisory_only_no_execution',
            'bootstrap_from'=>'verified_running_wordpress_container_source',
            'public_deployment_from_intake'=>false,
            'roles'=>array('applicant','owner','control_plane','coolify','site_agent','cloudflare')
        ));
    }
    public static function intake($request) {
        // Public personal-data collection MUST have a published Privacy Policy.
        // A review-only form page or a valid endpoint does not authorize intake.
        if (!get_privacy_policy_url())
            return self::fail('mad4b_launch_privacy_not_configured',
                'Company onboarding is not open until the privacy notice is configured.',503);
        $body=(array)$request->get_json_params();
        if(!empty($body['website']))return self::fail('mad4b_launch_spam','Invalid application.',429);
        $data=self::normalize($body);
        if(is_wp_error($data))return $data;
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,100);
        $throttle='mad4b_intake_'.hash_hmac('sha256',$ip,wp_salt('auth'));
        $hits=(int)get_transient($throttle);
        if($hits>=3)return self::fail('mad4b_launch_rate_limit','Too many requests. Try again later.',429);
        $existing=get_posts(array('post_type'=>self::TYPE,'posts_per_page'=>20,'post_status'=>'private','fields'=>'ids'));
        foreach($existing as $id) {
            $p=get_post_meta($id,self::META,true);
            $old=is_array($p)?($p['data']??null):null;
            if(is_array($old) && ($old['company_code']??'')===$data['company_code']) {
                if(($old['email']??'')===$data['email'])
                    return self::wrap(array('state'=>'intake_received','duplicate_existing'=>true,
                        'public_launch'=>false),202);
                return self::fail('mad4b_launch_company_code_conflict',
                    'This company code is already registered.',409);
            }
        }
        $id=wp_insert_post(array('post_type'=>self::TYPE,'post_status'=>'private',
            'post_title'=>'Company Request '.$data['company_code'],'post_content'=>'',
            'post_author'=>0,'comment_status'=>'closed'),true);
        if(is_wp_error($id)||!$id)return self::fail('mad4b_launch_save_failed','Cannot accept application.',503);
        $record=array('contract'=>self::VERSION,'state'=>'intake_received','data'=>$data,
            'version'=>1,'intake_at'=>gmdate('c'),'origin'=>'public_form_or_mcp_read_only',
            'coolify_resource_uuid'=>null,'reserved_target_site_uuid'=>wp_generate_uuid4(),'target_site_uuid'=>null,
            'native_profile_digest'=>null,'source_release'=>null,'public_live'=>false);
        if(!add_post_meta($id,self::META,$record,true)){
            wp_delete_post($id,true);
            return self::fail('mad4b_launch_persistence','Cannot persist application.',503);
        }
        set_transient($throttle,$hits+1,HOUR_IN_SECONDS);
        return self::wrap(array('application_ref'=>'TC-'.(int)$id,'state'=>'intake_received',
            'owner_approval_required'=>false,'deployment_started'=>false,'public_live'=>false),202);
    }
    private static function record($req) {
        $id=(int)$req->get_param('id');
        $post=get_post($id);
        if(!$post||$post->post_type!==self::TYPE||$post->post_status!=='private')
            return self::fail('mad4b_launch_unknown','Application not found.',404);
        $record=get_post_meta($id,self::META,true);
        if(!is_array($record)||($record['contract']??'')!==self::VERSION)
            return self::fail('mad4b_launch_corrupt','Application record invalid.',409);
        return array('id'=>$id,'record'=>$record);
    }
    public static function list_applications(){
        $ids=get_posts(array('post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>100,'fields'=>'ids'));
        $rows=array();
        foreach($ids as $id){
            $v=get_post_meta($id,self::META,true);
            if(is_array($v))$rows[]=array('id'=>(int)$id,'state'=>$v['state']??'unknown',
                'brand'=>$v['data']['brand_name']??'','company_code'=>$v['data']['company_code']??'');
        }
        return self::wrap(array('applications'=>$rows,'public_access'=>false));
    }
    public static function status($req){
        $x=self::record($req);
        if(is_wp_error($x))return $x;
        return self::wrap(array('application_id'=>$x['id'],'workflow'=>$x['record'],
            'public_live'=>false));
    }
    private static function proof(){
        if(!is_readable(self::PROOF)||is_link(self::PROOF))return array('available'=>false);
        $raw=file_get_contents(self::PROOF);
        $p=strlen($raw)<12000?json_decode($raw,true):null;
        if(!is_array($p)||($p['contract']??'')!=='mad4b.provisioning-source-proof.v1'||
            ($p['source_site']??'')!=='tours.dev.mad4b.com'||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($p['source_sha256']??'')) )
            return array('available'=>false);
        return array('available'=>true,'release_id'=>$p['release_id']??null,
            'sha256'=>$p['source_sha256'],'file_count'=>$p['file_count']??null,
            'approved_for_new_tenant'=>($p['approved_for_new_tenant']??false)===true,
            'code_only'=>($p['code_only']??false)===true,
            'staging_compatibility_review_complete'=>($p['staging_compatibility_review_complete']??false)===true);
    }
    public static function evaluate($x){
        $w=$x['record'];$data=$w['data'];$proof=self::proof();
        $hub=class_exists('MAD4B_Content_Hub');
        $domain=class_exists('MAD4B_SCP_Company_Domain_Cloudflare');
        $cf=$domain?MAD4B_SCP_Company_Domain_Cloudflare::status()->get_data():array();
        $tech=class_exists('MAD4B_Tourism_Workflow_REST') ?
            MAD4B_Tourism_Workflow_REST::technical_source_status() : array();
        $providers=class_exists('MAD4B_Tourism_Workflow_REST') ?
            MAD4B_Tourism_Workflow_REST::providers()->get_data() : array();
        $reg=get_option('mad4b_tourism_company_site_registry_v1',array());
        $exists=is_array($reg) && isset($reg[$data['company_code']??'']);
        $basic=in_array($w['state'],array('intake_received','owner_approved'),true) &&
            !empty($data['brand_name']) &&
            is_email((string)($data['email']??'')) &&
            preg_match('/^\+[1-9][0-9]{7,14}$/D',
                (string)($data['phone_e164']??''))===1;
        $checks=array(
            'application_received'=>$basic,
            'source_integrity_verified'=>!empty($tech['source_integrity_verified']),
            'code_only_no_uploads'=>!empty($tech['code_only_no_uploads']),
            'coolify_authenticated'=>!empty($providers['checks']['coolify_auth']),
            'company_not_already_enrolled'=>!$exists,
            'content_hub_available'=>$hub,
            'cloudflare_adapter_available'=>$domain,
            'cloudflare_token_authenticated'=>!empty($cf['cloudflare_token_configured']),
            'cloudflare_zone_configured'=>!empty($cf['mad4b_zone_id_configured'])
        );
        $seed=class_exists('MAD4B_Company_Seed_Admission_V1',false)
            ? MAD4B_Company_Seed_Admission_V1::status() : array('seed_complete'=>false,'state'=>'SEED_PROVIDER_UNAVAILABLE');
        $checks['live_demo_sealed_seed_verified']=($seed['seed_complete']??false)===true;
        $technical_ready=$checks['live_demo_sealed_seed_verified'] &&
            $checks['application_received'] &&
            $checks['source_integrity_verified'] &&
            $checks['code_only_no_uploads'] &&
            $checks['coolify_authenticated'] &&
            $checks['company_not_already_enrolled'];
        $blockers=array();
        foreach($checks as $k=>$ready)if(!$ready)$blockers[]=$k;
        return array('contract'=>self::VERSION,'application_id'=>$x['id'],
            'company_code'=>$data['company_code'],'current_state'=>$w['state'],
            'source'=>$proof,'seed'=>$seed,'checks'=>$checks,'blockers'=>$blockers,
            'next_action'=>!$checks['live_demo_sealed_seed_verified']?'prepare_original_sealed_demo_seed':($technical_ready?'restore_sealed_seed_private':'resolve_technical_preflight'),
            'private_provisioning_possible'=>$technical_ready,
            'source_certificate_required'=>false,
            'separate_owner_approval_required'=>false,
            'public_launch_authorized'=>false,
            'github_in_workflow'=>false
        );
    }
    public static function plan($req){
        $x=self::record($req);
        if(is_wp_error($x))return $x;
        $p=self::evaluate($x);
        $p['plan_sha256']=hash_hmac('sha256',
            wp_json_encode(array($x['id'],$x['record']['version'],$x['record']['state'],
                $x['record']['data']['company_code'],$p['source']['sha256']??'')),wp_salt('auth'));
        return self::wrap($p);
    }
    public static function approve($req) {
        $x=self::record($req);if(is_wp_error($x))return $x;
        if($x['record']['state']!=='intake_received')return self::fail(
            'mad4b_launch_not_reviewable','Only new applications may be approved.',409);
        $plan=self::evaluate($x);
        $digest=hash_hmac('sha256',wp_json_encode(array($x['id'],$x['record']['version'],
            $x['record']['state'],$x['record']['data']['company_code'],
            $plan['source']['sha256']??'')),wp_salt('auth'));
        $body=(array)$req->get_json_params();
        if(!is_string($body['plan_sha256']??null) ||
            !hash_equals($digest,$body['plan_sha256']) ||
            ($body['confirmation']??'')!=='APPROVE COMPANY '.$x['record']['data']['company_code'])
            return self::fail('mad4b_launch_approval_mismatch',
                'Exact owner approval and fresh plan required.',409);
        if(!class_exists('MAD4B_SCP_Audit') ||
            empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return self::fail('mad4b_launch_audit_unavailable',
                'Original MAD4B audit storage is required before owner approval.',503);
        $receipt=MAD4B_SCP_Audit::record('mad4b/tourism-company-owner-approval',array(
            'application_id'=>$x['id'],
            'company_code'=>$x['record']['data']['company_code'],
            'actor_id'=>get_current_user_id(),
            'plan_sha256'=>$digest,
            'public_live'=>false),'ok');
        if(is_wp_error($receipt))return $receipt;
        $next=$x['record'];$next['state']='owner_approved';$next['version']++;
        $next['reviewed_by']=get_current_user_id();$next['reviewed_at']=gmdate('c');
        if(!update_post_meta($x['id'],self::META,$next,$x['record']))
            return self::fail('mad4b_launch_race','Application changed concurrently.',409);
        return self::wrap(array('id'=>$x['id'],'state'=>'owner_approved',
            'resource_created'=>false,'public_live'=>false));
    }
    public static function profile_intent($req){
        $x=self::record($req);if(is_wp_error($x))return $x;
        $record=$x['record'];
        if(!in_array($record['state'],array('resource_reserved','profile_pending'),true)||
            empty($record['coolify_resource_uuid']))
            return self::fail('mad4b_launch_resource_not_reserved',
                'Cannot prepare native Site Profile before verified Coolify resource binding.',409);
        return self::fail('mad4b_launch_site_profile_native_pending',
            'Native Site Profile v2 must be enrolled on the target with its own UUID and audit; no central identity cloning.',424);
    }
    public static function readiness($req){
        $x=self::record($req);if(is_wp_error($x))return $x;
        $plan=self::evaluate($x);
        // Owner application is not a company Site binding.
        // Report required Skill admission without approving content writes.
        $code=(string)($x['record']['data']['company_code']??'');
        $preflight=class_exists('MAD4B_Company_ContentJob_Skill_Preflight_V1',false)
            ? MAD4B_Company_ContentJob_Skill_Preflight_V1::inspect($code,'start_company')
            : new WP_Error('mad4b_company_skill_unavailable','Company Skill admission unavailable');
        $content_status=is_wp_error($preflight)
            ? ['state'=>'HOLD_PENDING_SITE_IDENTITY_OR_SKILL',
               'reason'=>$preflight->get_error_code(),'can_execute'=>false]
            : ['state'=>$preflight['state'],
               'can_execute'=>$preflight['can_execute_company_reconcile']];
        return self::wrap(array('contentjob_skill_preflight'=>$content_status,
            'contentjob_preflight_required'=>true,
            'contentjob_preflight_is_not_resource_approval'=>true,
            'state'=>$plan['private_provisioning_possible']?
                'READY_FOR_PRIVATE_RESERVATION':'TECHNICAL_PREFLIGHT_BLOCKED',
            'missing_gates'=>$plan['blockers'],
            'second_owner_approval_required'=>false,'source_certificate_required'=>false,
            'public_live'=>false,
            'may_open_checkout'=>false,'source'=> $plan['source'],
            'no_content_imported_from_demo'=>true));
    }
    public static function admin_menu(){
        add_management_page('Tourism Company Launch','Tourism Company Launch',
            'manage_options','mad4b-tourism-company-launch',array(__CLASS__,'admin_page'));
    }
    public static function admin_page(){
        if(!current_user_can('manage_options'))return;
        $ids=get_posts(array('post_type'=>self::TYPE,'post_status'=>'private',
            'posts_per_page'=>100,'fields'=>'ids'));
        echo '<div class="wrap"><h1>MAD4B — Tourism Company Launch</h1>';
        echo '<p>Company name, email and phone are required. Provisioning does not require a second owner approval or source certificate; production payments remain separate.</p>';
        echo '<p><a href="'.esc_url(rest_url('mad4b/v1/tourism-launch/schema')).'">REST contract</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Brand</th><th>Stage</th><th>Launch</th></tr></thead><tbody>';
        foreach($ids as $id){
            $v=get_post_meta($id,self::META,true);
            if(!is_array($v))continue;
            echo '<tr><td>'.(int)$id.'</td><td>'.esc_html($v['data']['brand_name']??'').'</td>'.
                '<td>'.esc_html($v['state']??'').'</td><td><button class="button mad4b-workflow-status" type="button" data-request-id="'.(int)$id.'">Workflow readback</button> — Automatic technical preflight</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="mad4b-company-refresh">Refresh company applications</button></p>';
        echo '<p id="mad4b-company-review-status" role="status" aria-live="polite">Open an application to view its technical plan; no additional approval is required for private provisioning.</p>';
        $endpoint=rest_url('mad4b/v1/tourism-launch/applications');
        $nonce=wp_create_nonce('wp_rest');
        $js='(function(){const root='.wp_json_encode($endpoint).';const nonce='.wp_json_encode($nonce).';'.
            'const status=document.getElementById("mad4b-company-review-status");'.
            'document.querySelectorAll(".mad4b-workflow-status").forEach(b=>b.addEventListener("click",async()=>{'.
            'let r=await fetch(root+"/"+b.dataset.requestId+"/workflow",{credentials:"same-origin",headers:{"X-WP-Nonce":nonce}});'.
            'let p=await r.json();if(!r.ok){status.textContent=p.message||"Workflow unavailable";return;}'.
            'status.textContent="Company: "+p.company_code+" | Stage: "+p.state+" | Provider bound: "+Boolean(p.coolify_resource_uuid)+'.
            '" | Site Profile verified: "+Boolean(p.site_profile_registry_verified)+" | Content selected: "+Boolean(p.content_selection_saved)+'.
            '" | Public live: "+Boolean(p.public_live);'.
            '}));'.
            'let refresh=document.getElementById("mad4b-company-refresh");if(refresh)refresh.addEventListener("click",()=>location.reload());})();';
        echo '<script>'.$js.'</script></div>';
    }
    public static function form(){
        $endpoint=rest_url('mad4b/v1/tourism-launch/applications');
        $id='mad4b-tourism-start-'.wp_rand(100000,999999);
        $fields=array(
            array('Company / brand name','brand_name','text','required'),
            array('Company email','email','email','required'),
            array('Phone (international format, e.g. +201...)','phone_e164','tel','required'),
            array('Logo HTTPS URL (optional)','logo_url','url',''),
        );
        $h='<form id="'.esc_attr($id).'" class="mad4b-tourism-company-start"><h2>Start Your Tourism Company</h2>';
        foreach($fields as $f)$h.='<p><label>'.esc_html($f[0]).' <input type="'.esc_attr($f[2]).
            '" name="'.esc_attr($f[1]).'" '.esc_attr($f[3]).' maxlength="600"></label></p>';
        $h.='<p><label>Domain option <select name="domain_mode"><option value="subdomain">MAD4B subdomain</option><option value="custom_domain">My company domain</option></select></label></p>';
        $h.='<p><label>Custom domain (optional)<input name="domain"></label></p>';
        $privacy=get_privacy_policy_url();
        if($privacy) {
            $h.='<p><a href="'.esc_url($privacy).'" target="_blank" rel="noopener noreferrer">Privacy policy</a></p>';
        } else {
            $h.='<p class="mad4b-company-privacy-warning"><strong>Submission is not yet open: privacy policy must be published by the site owner.</strong></p>';
        }
        $h.='<p><label><input type="checkbox" name="consent" required> I agree to be contacted about this application.</label></p>';
        $h.='<input type="text" name="website" tabindex="-1" autocomplete="off" style="display:none" aria-hidden="true">';
        $h.='<button type="submit">Send request</button><p role="status" aria-live="polite"></p></form>';
        $js='(function(){let e=document.getElementById('.wp_json_encode($id).');if(!e)return;'.
            'e.addEventListener("submit",async function(ev){ev.preventDefault();const b=e.querySelector("button");b.disabled=true;'.
            'const x=new FormData(e);let v={};x.forEach((value,key)=>v[key]=value);'.
            'v.consent=x.has("consent");try{let r=await fetch('.wp_json_encode($endpoint).','.
            '{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(v),credentials:"omit"});'.
            'let q=await r.json();e.querySelector("[role=status]").textContent=r.ok?'.
            '"Request received: "+(q.application_ref||"submitted"):(q.message||"Please review your details");}'.
            'catch(er){e.querySelector("[role=status]").textContent="Connection unavailable";}finally{b.disabled=false;}});})();';
        return $h.'<script>'.$js.'</script>';
    }
}
MAD4B_VPS_Company_Launch::boot();
