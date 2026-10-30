<?php
/**
 * Plugin Name: MAD4B Company & Brand Readiness — Content Hub
 * Description: Company-scoped Content Hub selection by the central master_admin. Source candidates are not yet distributable.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Brand_Readiness_Hub {
    const NS='mad4b/v1';
    const ROOT='/company-brand-readiness';
    const STORE='mad4b_company_brand_hub_preferences_v1';
    const CONTRACT='mad4b.company-brand-hub-preferences.v1';
    const LANG=array('en','ar','fr','de','it','es');
    const CURRENCY=array('USD','EUR','GBP','EGP','AED','SAR');
    public static function init(){
        add_action('rest_api_init',array(__CLASS__,'routes'),70);
        add_action('admin_menu',array(__CLASS__,'menu'),90);
        add_action('wp_abilities_api_init',array(__CLASS__,'register_mcp_abilities'),50);
    }
    private static function context(){
        return wp_get_environment_type()==='staging' &&
            defined('DB_NAME') && DB_NAME==='etg_commerce_staging' &&
            wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com';
    }
    private static function error($code,$msg,$status=409){
        return new WP_Error($code,$msg,array('status'=>$status));
    }
    public static function permission(){
        if(!self::context()) return self::error('mad4b_brand_wrong_site','Control Plane host mismatch.',403);
        if((current_user_can('mad4b_manage_company_operations') &&
            current_user_can('mad4b_propose_company_content')) ||
           (current_user_can('manage_options')&&current_user_can('activate_plugins')))
            return true;
        return self::error('mad4b_brand_company_operator_required',
            'Master admin company operations + content proposal capability required.',403);
    }
    private static function response($value){
        $r=rest_ensure_response($value);$r->header('Cache-Control','private, no-store');return $r;
    }
    private static function registry(){
        $r=get_option('mad4b_tourism_company_site_registry_v1',array());
        return is_array($r)?$r:array();
    }
    private static function company($request){
        $code=sanitize_key((string)$request->get_param('company'));
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
            return self::error('mad4b_brand_company_code_invalid','Invalid company code.',422);
        $r=self::registry();$x=$r[$code]??null;
        if(!is_array($x)||($x['company_code']??'')!==$code ||
           empty($x['host_binding_verified'])||
           !wp_is_uuid((string)($x['company_site_uuid']??''))||
           !wp_is_uuid((string)($x['native_site_profile_uuid']??'')) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($x['native_profile_digest']??'')))
            return self::error('mad4b_brand_tenant_not_bound','Company requires a verified central Site Profile.',409);
        return array('code'=>$code,'record'=>$x);
    }
    private static function catalog(){
        if(!class_exists('MAD4B_Tourism_Workflow_REST'))
            return self::error('mad4b_brand_hub_unavailable','Central hosted catalog unavailable.',503);
        $r=MAD4B_Tourism_Workflow_REST::hub_catalog();
        if(is_wp_error($r))return $r;
        $x=$r instanceof WP_REST_Response?$r->get_data():null;
        if(!is_array($x)||($x['contract']??'')!=='mad4b.content-hub.hosted-trial-discovery.v1'||
          !is_array($x['items']??null)||count($x['items'])<1||count($x['items'])>300 ||
          !preg_match('/^[a-f0-9]{64}$/D',(string)($x['catalog_sha256']??'')))
            return self::error('mad4b_brand_catalog_invalid','Trusted candidate inventory invalid.',503);
        return $x;
    }
    public static function routes(){
        register_rest_route(self::NS,self::ROOT.'/companies',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'companies'),
            'permission_callback'=>array(__CLASS__,'permission')));
        register_rest_route(self::NS,self::ROOT.'/companies/(?P<company>[a-z][a-z0-9-]{2,47})/catalog',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'list_catalog'),
            'permission_callback'=>array(__CLASS__,'permission')));
        register_rest_route(self::NS,self::ROOT.'/companies/(?P<company>[a-z][a-z0-9-]{2,47})/selection',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'read_selection'),
                  'permission_callback'=>array(__CLASS__,'permission')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_selection'),
                  'permission_callback'=>array(__CLASS__,'permission'))));
        register_rest_route(self::NS,self::ROOT.'/companies/(?P<company>[a-z][a-z0-9-]{2,47})/distribution/plan',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'distribution_plan'),
            'permission_callback'=>array(__CLASS__,'permission')));
    }
    public static function companies(){
        $out=array();
        foreach(self::registry() as $code=>$r){
            if(!is_array($r) || empty($r['host_binding_verified']) ||
               !wp_is_uuid((string)($r['company_site_uuid']??'')))continue;
            $out[]=array('company_code'=>$code,'site_uuid'=>$r['company_site_uuid'],
              'origin'=>$r['canonical_origin']??null,
              'native_site_profile_uuid'=>$r['native_site_profile_uuid']??null);
        }
        return self::response(array('contract'=>self::CONTRACT,'companies'=>$out,
            'role'=>'master_admin_or_central_system_admin',
            'registry_source'=>'MAD4B native company Site Profiles'));
    }
    private static function stored($code){
        $s=get_option(self::STORE,array());
        return is_array($s) && is_array($s[$code]??null)?$s[$code]:null;
    }
    public static function list_catalog($req){
        $tenant=self::company($req);if(is_wp_error($tenant))return $tenant;
        $c=self::catalog();if(is_wp_error($c))return $c;
        $items=array();
        foreach($c['items'] as $item){
            $labels=array();
            foreach((array)($item['languages']??array()) as $code=>$data){
                if(!in_array($code,self::LANG,true))continue;
                $labels[$code]=array('title'=>(string)($data['title']??''),
                    'source_preview_url'=>$data['source_preview_url']??null);
            }
            $items[]=array('hub_ref'=>$item['hub_ref'],'languages'=>$labels,
              'hub_ingestion_state'=>'CANDIDATE_NOT_CANONICAL','tenant_publish_allowed'=>false);
        }
        return self::response(array(
            'contract'=>self::CONTRACT,'company_code'=>$tenant['code'],
            'catalog_sha256'=>$c['catalog_sha256'],
            'inventory_status'=>'SOURCE_CANDIDATES_PENDING_CANONICAL_CONTENT_HUB_INGESTION',
            'upstream_source_not_tenant_distribution_origin'=>true,
            'distribution_authorized'=>false,
            'items'=>$items,'languages'=>self::LANG,'currencies'=>self::CURRENCY));
    }
    public static function read_selection($req){
        $tenant=self::company($req);if(is_wp_error($tenant))return $tenant;
        $c=self::catalog();if(is_wp_error($c))return $c;
        $selected=self::stored($tenant['code']);
        return self::response(array('contract'=>self::CONTRACT,
            'company_code'=>$tenant['code'],
            'site_uuid'=>$tenant['record']['company_site_uuid'],
            'selection'=>$selected,
            'revision'=>(int)($selected['revision']??0),
            'catalog_sha256'=>$c['catalog_sha256'],
            'catalog_changed'=>is_array($selected)&&($selected['catalog_sha256']??'')!==$c['catalog_sha256'],
            'canonical_hub_distribution_state'=>'WAITING_FOR_INGESTION_AND_APPROVED_HUB_ASSETS'));
    }
    public static function save_selection($req){
        $tenant=self::company($req);if(is_wp_error($tenant))return $tenant;
        $c=self::catalog();if(is_wp_error($c))return $c;
        $body=$req->get_json_params();
        if(!is_array($body))return self::error('mad4b_brand_payload_invalid','JSON object required.',422);
        $t=$body['tours']??null;$l=$body['languages']??null;$money=$body['currencies']??null;
        if(!is_array($t)||!is_array($l)||!is_array($money) ||
           count($t)<1||count($t)>min(12,count($c['items']))||
           count($l)<1||count($l)>6||count($money)<1||count($money)>6 ||
           count(array_unique($t))!==count($t)||
           count(array_unique($l))!==count($l)||
           count(array_unique($money))!==count($money))
            return self::error('mad4b_brand_selection_invalid','Select unique tours, languages and currencies.',422);
        $allowed=array_column($c['items'],'hub_ref');
        foreach($t as $ref)if(!is_string($ref)||!in_array($ref,$allowed,true))
            return self::error('mad4b_brand_tour_not_found','Tour reference outside current Hub candidates.',422);
        foreach($l as $lang)if(!is_string($lang)||!in_array($lang,self::LANG,true))
            return self::error('mad4b_brand_language_not_found','Unsupported language.',422);
        foreach($money as $currency)if(!is_string($currency)||!in_array($currency,self::CURRENCY,true))
            return self::error('mad4b_brand_currency_not_found','Unsupported display currency.',422);
        if(($body['catalog_sha256']??'')!==$c['catalog_sha256'])
            return self::error('mad4b_brand_catalog_changed','Refresh Content Hub candidates.',409);
        $expected=$body['expected_revision']??null;
        if(!is_int($expected)||$expected<0)return self::error('mad4b_brand_revision_missing','Integer expected_revision required.',422);
        global $wpdb;
        $lock='mad4b:brand-hub:'.$tenant['code'];
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::error('mad4b_brand_selection_busy','Another content choice is in progress.',423);
        try {
            $all=get_option(self::STORE,array());
            if(!is_array($all))return self::error('mad4b_brand_store_invalid','Preferences storage invalid.',503);
            $prior=$all[$tenant['code']]??null;
            if((int)($prior['revision']??0)!==$expected)
                return self::error('mad4b_brand_stale_selection','Refresh company preferences first.',409);
            $new=array(
                'contract'=>self::CONTRACT,'company_code'=>$tenant['code'],
                'company_site_uuid'=>$tenant['record']['company_site_uuid'],
                'native_site_profile_uuid'=>$tenant['record']['native_site_profile_uuid'],
                'catalog_sha256'=>$c['catalog_sha256'],
                'tours'=>array_values($t),'languages'=>array_values($l),
                'currencies'=>array_values($money),
                'revision'=>$expected+1,'actor_user_id'=>get_current_user_id(),
                'updated_at'=>gmdate('c'),
                'ingestion_pending'=>true,'distribution_queued'=>false,
                'tenant_posts_created'=>false,'media_copied'=>false,
                'price_overrides_applied'=>false,'checkout_enabled'=>false);
            $all[$tenant['code']]=$new;
            if(!update_option(self::STORE,$all,false))
                return self::error('mad4b_brand_store_write_failed','Cannot save brand selections.',503);
            $read=self::stored($tenant['code']);
            if(!is_array($read)||$read['revision']!==$new['revision'])
                return self::error('mad4b_brand_readback_failed','Preferences readback failed.',503);
            return self::response(array('state'=>'SAVED_PENDING_CANONICAL_HUB_INGESTION',
                'selection'=>$new,'tenant_mutated'=>false));
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function distribution_plan($req){
        $tenant=self::company($req);if(is_wp_error($tenant))return $tenant;
        $current=self::stored($tenant['code']);
        $blockers=array();
        if(!is_array($current))$blockers[]='company_preferences_not_selected';
        $blockers[]='source_candidates_not_ingested_into_canonical_content_hub';
        $blockers[]='no_approved_hub_content_versions_or_media_license';
        return self::response(array('contract'=>self::CONTRACT,
            'company_code'=>$tenant['code'],'site_uuid'=>$tenant['record']['company_site_uuid'],
            'selected_count'=>count($current['tours']??array()),
            'blockers'=>$blockers,
            'distribution_eligible'=>false,'commercial_checkout_enabled'=>false,
            'no_direct_woo_vacation_tenant_import'=>true,
            'target'=>'canonical_content_hub_database_to_company_tenant'));
    }
    public static function register_mcp_abilities(){
        if(!self::context()||!function_exists('wp_register_ability'))return;
        $defs=array(
            'mad4b/company-brand-hub-companies'=>array(
                'label'=>'List enrolled tourism companies',
                'description'=>'Find host-bound company Site Profiles for Company & Brand Readiness.',
                'category'=>'mad4b-read','handler'=>'ability_companies',
                'schema'=>array('type'=>'object','additionalProperties'=>false),
                'read_only'=>true),
            'mad4b/company-brand-hub-catalog'=>array(
                'label'=>'Preview company Content Hub candidates',
                'description'=>'Read-only upstream content references awaiting canonical Content Hub ingestion.',
                'category'=>'mad4b-read','handler'=>'ability_catalog',
                'schema'=>array('type'=>'object','properties'=>array(
                    'company'=>array('type'=>'string','minLength'=>3,'maxLength'=>48)),
                    'required'=>array('company'),'additionalProperties'=>false),
                'read_only'=>true),
            'mad4b/company-brand-hub-selection'=>array(
                'label'=>'Read company-selected Content Hub preferences',
                'description'=>'Read selected tour references, languages, currencies and optimistic revision.',
                'category'=>'mad4b-read','handler'=>'ability_selection',
                'schema'=>array('type'=>'object','properties'=>array(
                    'company'=>array('type'=>'string','minLength'=>3,'maxLength'=>48)),
                    'required'=>array('company'),'additionalProperties'=>false),
                'read_only'=>true),
            'mad4b/company-brand-hub-save'=>array(
                'label'=>'Save company Content Hub preferences',
                'description'=>'Master-admin choice of candidate tour references, languages and currencies; never publishes content, uploads media or activates checkout.',
                'category'=>'mad4b-write','handler'=>'ability_save',
                'schema'=>array('type'=>'object',
                    'properties'=>array(
                        'company'=>array('type'=>'string','minLength'=>3,'maxLength'=>48),
                        'tours'=>array('type'=>'array','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>12),
                        'languages'=>array('type'=>'array','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>6),
                        'currencies'=>array('type'=>'array','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>6),
                        'catalog_sha256'=>array('type'=>'string','minLength'=>64,'maxLength'=>64),
                        'expected_revision'=>array('type'=>'integer','minimum'=>0)),
                    'required'=>array('company','tours','languages','currencies','catalog_sha256','expected_revision'),
                    'additionalProperties'=>false),
                'read_only'=>false)
        );
        foreach($defs as $name=>$config){
            if(function_exists('wp_has_ability') && wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>$config['label'],'description'=>$config['description'],
                'category'=>$config['category'],
                'permission_callback'=>array(__CLASS__,'permission'),
                'execute_callback'=>array(__CLASS__,$config['handler']),
                'input_schema'=>$config['schema'],
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool',
                        'surface'=>$config['read_only']?'read':'write'),
                    'annotations'=>array('readonly'=>$config['read_only'],
                        'destructive'=>false,'idempotent'=>true))
            ));
        }
    }
    private static function ability_dispatch($method,$input,$path){
        $permit=self::permission();
        if(is_wp_error($permit))return $permit;
        $req=new WP_REST_Request($method,'/mad4b/v1/company-brand-readiness/'.$path);
        foreach($input as $key=>$value)$req->set_param($key,$value);
        if($method==='POST'){
            $req->set_header('Content-Type','application/json');
            $req->set_body(wp_json_encode($input));
        }
        $callback=$path==='companies'?'companies':
            (str_ends_with($path,'/catalog')?'list_catalog':
             (str_ends_with($path,'/selection')?
                ($method==='POST'?'save_selection':'read_selection'):
                'distribution_plan'));
        $result=call_user_func(array(__CLASS__,$callback),$req);
        return $result instanceof WP_REST_Response?$result->get_data():$result;
    }
    public static function ability_companies($input=array()){
        return self::ability_dispatch('GET',array(),'companies');
    }
    public static function ability_catalog($input=array()){
        return self::ability_dispatch('GET',(array)$input,
            'companies/'.sanitize_key((string)($input['company']??'')).'/catalog');
    }
    public static function ability_selection($input=array()){
        return self::ability_dispatch('GET',(array)$input,
            'companies/'.sanitize_key((string)($input['company']??'')).'/selection');
    }
    public static function ability_save($input=array()){
        return self::ability_dispatch('POST',(array)$input,
            'companies/'.sanitize_key((string)($input['company']??'')).'/selection');
    }
    public static function menu(){
        if(!self::context())return;
        add_menu_page('Company & Brand Readiness','Company & Brand Readiness',
          'mad4b_manage_company_operations','mad4b-company-brand-readiness',
          array(__CLASS__,'screen'),'dashicons-store',37);
    }
    public static function screen(){
        if(is_wp_error(self::permission()))wp_die('Company operations permission required.',403);
        $users=self::registry();
        $code=sanitize_key((string)($_GET['company']??''));
        if(!isset($users[$code]))$code=(string)array_key_first($users);
        $req=new WP_REST_Request('GET','/');$req->set_param('company',$code);
        $catalog=self::list_catalog($req);
        $catalog=is_wp_error($catalog)?null:$catalog->get_data();
        $existing=self::stored($code);
        $nonce=wp_create_nonce('wp_rest');
        echo '<div class="wrap"><h1>Company &amp; Brand Readiness — Content Hub</h1>';
        echo '<p>Choose content preferences here. Woo Vacation supplies upstream candidates; only canonical, approved Content Hub assets can later be distributed to companies.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-company-brand-readiness"><label>Company <select name="company">';
        foreach($users as $key=>$v)if(!empty($v['host_binding_verified'])){
            echo '<option value="'.esc_attr($key).'" '.selected($key,$code,false).'>'.esc_html($key).'</option>';
        }
        echo '</select></label> <button type="submit" class="button">Open</button></form>';
        if(!$catalog){echo '<p>Content Hub inventory is unavailable.</p></div>';return;}
        echo '<p>Current state: <strong>source candidates pending Hub ingestion</strong>. Nothing is published automatically.</p>';
        echo '<form id="mad4b-brand-hub-form"><h2>Tour candidates — choose up to 12</h2><div style="border:1px solid #ddd;background:#fff;max-height:330px;overflow:auto;padding:12px">';
        foreach($catalog['items'] as $item){
            $label=$item['languages']['en']??reset($item['languages']);
            echo '<p><label><input type="checkbox" name="tour" value="'.esc_attr($item['hub_ref']).'" '.checked(in_array($item['hub_ref'],$existing['tours']??array(),true),true,false).'> '.esc_html($label['title']??$item['hub_ref']).'</label></p>';
        }
        echo '</div><h2>Languages</h2>';
        foreach(self::LANG as $lang)echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="lang" value="'.esc_attr($lang).'" '.checked(in_array($lang,$existing['languages']??array('en'),true),true,false).'>'.esc_html(strtoupper($lang)).'</label>';
        echo '<h2>Display currencies</h2>';
        foreach(self::CURRENCY as $money)echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="currency" value="'.esc_attr($money).'" '.checked(in_array($money,$existing['currencies']??array('USD'),true),true,false).'>'.esc_html($money).'</label>';
        echo '<p><button class="button button-primary" type="submit">Save company preferences</button></p></form><pre id="mad4b-brand-hub-result" style="max-width:900px;white-space:pre-wrap"></pre>';
        $base=rest_url(self::NS.self::ROOT.'/companies/'.$code.'/selection');
        $script='(()=>{const form=document.getElementById("mad4b-brand-hub-form"),out=document.getElementById("mad4b-brand-hub-result");'.
          'let revision='.wp_json_encode((int)($existing['revision']??0)).';'.
          'form.addEventListener("submit",async e=>{e.preventDefault();'.
          'const pick=n=>[...form.querySelectorAll("input[name="+n+"]:checked")].map(x=>x.value);'.
          'const data={tours:pick("tour"),languages:pick("lang"),currencies:pick("currency"),'.
          'catalog_sha256:'.wp_json_encode($catalog['catalog_sha256']).',expected_revision:revision};'.
          'const r=await fetch('.wp_json_encode($base).',{method:"POST",credentials:"same-origin",'.
          'headers:{"Content-Type":"application/json","X-WP-Nonce":'.wp_json_encode($nonce).' },body:JSON.stringify(data)});'.
          'const v=await r.json(); if(r.ok){revision=v.selection.revision;out.textContent="Saved "+v.selection.tours.length+" tour references; publication pending Content Hub ingestion.";}else out.textContent=v.message||"Save failed";});})();';
        echo '<script>'.$script.'</script></div>';
    }
}
MAD4B_Company_Brand_Readiness_Hub::init();
