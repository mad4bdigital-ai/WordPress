<?php
/**
 * Plugin Name: MAD4B Service Activation Live Readback
 * Description: Local, read-only effective-state comparison for JetEngine Service Activation.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if(!class_exists('MAD4B_Service_Activation_Live_Readback_V1',false)){
final class MAD4B_Service_Activation_Live_Readback_V1 {
    private const FAMILIES=[
        'tours'=>['name'=>'Tours & Activities','cpt'=>'tours-and-activities','surface'=>'tour'],
        'properties'=>['name'=>'Properties & Accommodation','cpt'=>'properties','surface'=>'property'],
        'transportations'=>['name'=>'Transportation','cpt'=>'transportations','surface'=>'transportation'],
        'visas'=>['name'=>'Visas','cpt'=>'travel-visa','surface'=>'visa']
    ];
    private static function editor():bool {
        return current_user_can('manage_options');
    }
    public static function onPage():bool {
        return is_admin() && self::editor()
            && isset($_GET['page']) && is_string($_GET['page'])
            && sanitize_key(wp_unslash($_GET['page']))==='service-activation';
    }
    private static function familyMode(string $family):string {
        if(function_exists('service_channel_mode'))return service_channel_mode($family);
        return 'unavailable';
    }
    private static function modeLabel(string $mode):string {
        return match($mode){
          'standalone_and_tour'=>'Standalone + Tour',
          'tour_only'=>'Tour context only',
          'disabled'=>'Disabled',
          default=>'Unavailable / Not verified'
        };
    }
    private static function familyConfigured(string $family,array $option):array {
        $direct=$family.'_channel_mode';
        if(array_key_exists($direct,$option) && $option[$direct]!=='')
            return ['source'=>'saved','value'=>$option[$direct]];
        if(isset($option['service_channel_modes']) &&
            is_array($option['service_channel_modes']) &&
            array_key_exists($family,$option['service_channel_modes']))
            return ['source'=>'legacy override','value'=>$option['service_channel_modes'][$family]];
        return ['source'=>'legacy active_services fallback','value'=>
            in_array($family,(array)($option['active_services']??[]),true)
            ?'standalone_and_tour':'disabled'];
    }
    /** Site-local readback only. No credential lookups, no remote writes. */
    public static function snapshot():array {
        $opt=get_option('service-activation',[]);
        if(!is_array($opt))$opt=[];
        $runtime=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED;
        $guard=class_exists('MAD4B_Service_Public_Entry_Guard_V1',false)
            || class_exists('Shared_Service_Public_Access_v1',false);
        $unified=class_exists('MAD4B_Unified_Booking_Fulfillment_V2',false);
        $stripe=get_option('woocommerce_stripe_settings',[]);
        $stripeEnabled=is_array($stripe)&&($stripe['enabled']??'no')==='yes';
        $early=false;
        if(class_exists('MAD4B_Early_Payment_Benefits_V1',false)
            && is_callable(['MAD4B_Early_Payment_Benefits_V1','policy'])){
            $p=MAD4B_Early_Payment_Benefits_V1::policy();
            $early=is_array($p)&&!empty($p['active']);
        }
        $globalBooking='unverified';
        if(function_exists('shared_product_pricing_setting')){
            $v=shared_product_pricing_setting('booking_enabled');
            $globalBooking=is_wp_error($v)?'unverified':($v===true?'enabled':'disabled');
        }
        $schema=[];
        if(function_exists('jet_engine')){
            $pages=jet_engine()->options_pages->registered_pages??[];
            $page=is_array($pages)?($pages['service-activation']??null):null;
            $fields=is_object($page)?($page->page['fields']??[]):[];
            $expected=[
                'shared_tour_presentation_mode',
                'tours_booking_fulfillment_mode',
                'properties_booking_fulfillment_mode',
                'transportations_booking_fulfillment_mode',
                'visas_booking_fulfillment_mode'
            ];
            $schema=[
                'declared_count'=>is_array($fields)?count($fields):0,
                'explicit_saved_count'=>count($opt),
                'unfilled_field_keys'=>is_array($fields)
                    ?array_values(array_diff(array_keys($fields),array_keys($opt))):[],
                'runtime_schema_gaps'=>is_array($fields)
                    ?array_values(array_diff($expected,array_keys($fields))):$expected,
            ];
        }
        $families=[];
        foreach(self::FAMILIES as $key=>$config){
            $cpt=$config['cpt'];
            $postType=post_type_exists($cpt);
            $counts=$postType?wp_count_posts($cpt):null;
            $published=$counts?((int)($counts->publish??0)):0;
            $mode=self::familyMode($key);
            $allowedStandalone=function_exists('service_channel_allowed')
                &&service_channel_allowed($key,'standalone');
            $allowedTour=function_exists('service_channel_allowed')
                &&service_channel_allowed($key,'tour_component');
            $configSource=self::familyConfigured($key,$opt);
            $fulfillmentConfigured=$opt[$key.'_booking_fulfillment_mode']??'inherit';
            if(!is_string($fulfillmentConfigured)||$fulfillmentConfigured==='')$fulfillmentConfigured='inherit';
            // An executor's mere existence is not a certified checkout authority.
            $fulfillmentState=$unified
                ?($key==='tours'
                    ?($globalBooking==='disabled'?'Inquiry only · booking globally off'
                        :($globalBooking==='unverified'?'Inquiry only · booking authority unverified'
                            :'Engine loaded · verify tour/rate readiness'))
                    :'Inquiry only · non-tour executor not certified')
                :'Not evaluated · unified engine missing';
            $families[$key]=[
                'name'=>$config['name'],'cpt'=>$cpt,
                'post_type_registered'=>$postType,'published'=>$published,
                'configured_mode'=>$configSource['value'],
                'mode_source'=>$configSource['source'],
                'effective_mode'=>$mode,'effective_label'=>self::modeLabel($mode),
                'standalone_allowed'=>$allowedStandalone,
                'tour_context_allowed'=>$allowedTour,
                'discovery_candidate'=>($opt['discovery_enabled']??'disabled')==='enabled'
                    &&in_array($config['surface'],(array)($opt['discovery_surfaces']??[]),true)
                    &&function_exists('service_channel_allowed')
                    &&service_channel_allowed($key,'destination_discovery'),
                'fulfillment_setting'=>$fulfillmentConfigured,
                'fulfillment_status'=>$fulfillmentState,
                'public_guard_loaded'=>$guard,
                'status'=>$guard?'policy_loaded':'public_guard_missing',
            ];
        }
        return [
            'contract'=>'mad4b.service-activation-live-readback.v1',
            'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'observed_utc'=>gmdate('c'),
            'site_option_present'=>get_option('service-activation',null)!==null,
            'global_booking_status'=>$globalBooking,
            'unified_fulfillment_loaded'=>$unified,
            'public_guard_loaded'=>$guard,
            'central_platform_runtime_loaded'=>$runtime,
            'discovery_configured'=>($opt['discovery_enabled']??'disabled')==='enabled',
            'search_runtime'=>function_exists('registry_search_public_types')
                ?'central registry':(class_exists('MAD4B_Service_Public_Entry_Guard_V1',false)
                    ?'site-neutral fallback':'not verified'),
            'stripe_enabled'=>$stripeEnabled,
            'early_payment_benefits_enabled'=>$early,
            'schema'=>$schema,'families'=>$families,
            'plan_verified_from_central'=>false,
            'status_note'=>'Local WordPress runtime readback. Central Company Plan and remote rollout are separate.',
        ];
    }
    /** A deliberate guest HTTPS request, never a cached status guessed from saved options. */
    public static function verifyPublicHttp():array {
        $base=home_url('/');
        $host=wp_parse_url($base,PHP_URL_HOST);
        if(wp_parse_url($base,PHP_URL_SCHEME)!=='https' || !is_string($host)||$host===''){
            return ['status'=>'unavailable','reason'=>'site_https_required','checks'=>[]];
        }
        $queries=[
            'internal_tour_rates'=>'wp-json/wp/v2/tour-rates?per_page=1&_fields=id',
            'transportation_rest'=>'wp-json/wp/v2/transportations?per_page=1&_fields=id',
            'properties_rest'=>'wp-json/wp/v2/properties?per_page=1&_fields=id',
            'tour_rest'=>'wp-json/wp/v2/tours-and-activities?per_page=1&_fields=id',
        ];
        $snap=self::snapshot();
        $check=[];
        foreach($queries as $kind=>$path){
            $url=home_url('/'.$path);
            $parsedHost=wp_parse_url($url,PHP_URL_HOST);
            if($parsedHost!==$host){
                $check[$kind]=['result'=>'unknown','reason'=>'host_mismatch'];
                continue;
            }
            $resp=wp_safe_remote_get($url,[
                'timeout'=>6,'redirection'=>0,'sslverify'=>true,
                'headers'=>['Accept'=>'application/json','Cache-Control'=>'no-cache'],
                'cookies'=>[],'limit_response_size'=>2048,
            ]);
            if(is_wp_error($resp)){
                $check[$kind]=['result'=>'unknown','reason'=>'http_probe_failed'];continue;
            }
            $actual=(int)wp_remote_retrieve_response_code($resp);
            $expected=match($kind){
                'internal_tour_rates'=>404,
                'transportation_rest'=>$snap['families']['transportations']['standalone_allowed']?200:404,
                'properties_rest'=>$snap['families']['properties']['standalone_allowed']?200:404,
                'tour_rest'=>$snap['families']['tours']['standalone_allowed']?200:404,
            };
            $check[$kind]=[
                'expected_http'=>$expected,'observed_http'=>$actual,
                'result'=>$actual===$expected?'pass':'drift',
            ];
        }
        $status='pass';
        foreach($check as $item) {
            if($item['result']==='drift'){$status='drift';break;}
            if($item['result']==='unknown')$status='unknown';
        }
        return [
            'status'=>$status,'checks'=>$check,'observed_utc'=>gmdate('c'),
            'site_host'=>$host,'anonymous_https'=>true,'credentials_sent'=>false,
        ];
    }
    public static function ajaxProbe():void {
        if(!self::editor())wp_send_json_error(['code'=>'forbidden'],403);
        check_ajax_referer('mad4b_live_service_probe','nonce');
        wp_send_json_success(self::verifyPublicHttp());
    }
    private static function flag($state,string $positive,string $negative):string {
        return '<strong class="'.($state?'mad4b-sa-ok':'mad4b-sa-warn').'">'
            .esc_html($state?$positive:$negative).'</strong>';
    }
    public static function notice():void {
        if(!self::onPage())return;
        $s=self::snapshot();
        $id='mad4b-service-live-readback';
        echo '<section id="'.esc_attr($id).'" class="notice notice-info mad4b-sa-panel" role="region" aria-label="Live service activation readback">';
        echo '<h2>Live Service Activation · '.esc_html($s['site_host']).'</h2>';
        echo '<p>Saved setup is not live readiness. Readback at <strong>'.esc_html($s['observed_utc']).'</strong>. ';
        echo '<a href="'.esc_url(admin_url('admin.php?page=service-activation')).'">Refresh WordPress state</a>.</p>';
        echo '<p>Public entry guard: '.self::flag($s['public_guard_loaded'],'Loaded','Missing')
            .' · Unified Fulfillment: '.self::flag($s['unified_fulfillment_loaded'],'Loaded','Missing')
            .' · Booking: <strong>'.esc_html($s['global_booking_status']).'</strong>'
            .' · Stripe: '.self::flag(!$s['stripe_enabled'],'Off','On (review authority)')
            .' · Early payment benefits: '.self::flag(!$s['early_payment_benefits_enabled'],'Off','On')
            .'</p>';
        echo '<div class="mad4b-sa-scroll"><table class="widefat striped"><thead><tr>'
            .'<th>Service</th><th>Configured → Effective</th><th>Published</th>'
            .'<th>Public availability</th><th>Booking / Execution</th></tr></thead><tbody>';
        foreach($s['families'] as $family=>$r){
            $mode=$r['effective_label'];
            $source=$r['mode_source'];
            echo '<tr><th scope="row">'.esc_html($r['name']).'</th><td><strong>'
                .esc_html($mode).'</strong><small> Source: '.esc_html($source).'</small></td>'
                .'<td>'.esc_html((string)$r['published']).' published'.(!$r['post_type_registered']?' · CPT not registered':'').'</td>'
                .'<td>Standalone: '.self::flag($r['standalone_allowed'],'Allowed','Not available')
                .'<small>Tour context: '.($r['tour_context_allowed']?'Allowed by policy':'Disabled')
                .' · Discovery: '.($r['discovery_candidate']?'Configured':'Not selected').'</small></td>'
                .'<td>'.esc_html($r['fulfillment_status']).'<small>Requested: '
                .esc_html($r['fulfillment_setting']).'</small></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="mad4b-sa-note">Schema: '.esc_html((string)($s['schema']['declared_count']??0))
            .' fields declared, '.esc_html((string)($s['schema']['explicit_saved_count']??0))
            .' values explicitly saved. Other fields may inherit defaults. '
            .'Central Company Service Catalog is a separate plan, not proof of deployment.</p>';
        $schemaGaps=(array)($s['schema']['runtime_schema_gaps']??[]);
        if($schemaGaps){
            echo '<p class="mad4b-sa-warn"><strong>Definition gap:</strong> '
                .esc_html(implode(', ',$schemaGaps))
                .'. These controls are not registered on this site. No inactive control is presented as an active feature.</p>';
        }
        echo '<p><button type="button" class="button button-secondary" id="mad4b-sa-public-probe">Verify public HTTP as guest</button>'
            .' <span id="mad4b-sa-probe-result" role="status" aria-live="polite">Not checked in this admin session.</span></p>';
        echo '<ul id="mad4b-sa-probe-details" aria-label="Public route verification"></ul>';
        echo '</section>';
    }
    public static function assets():void {
        if(!self::onPage())return;
        $css='.mad4b-sa-panel{padding:14px 18px 12px!important;margin:14px 16px 18px 0!important;}'
            .'.mad4b-sa-panel h2{font-size:20px;margin:4px 0 9px;}'
            .'.mad4b-sa-panel small{display:block;margin-top:5px;color:#59646b;}'
            .'.mad4b-sa-panel .mad4b-sa-scroll{overflow-x:auto;}'
            .'.mad4b-sa-panel table{min-width:650px;table-layout:auto;}'
            .'.mad4b-sa-ok{color:#146c36}.mad4b-sa-warn{color:#a03825}'
            .'.mad4b-sa-panel td,.mad4b-sa-panel th{padding:10px;vertical-align:top;}'
            .'.mad4b-sa-panel .mad4b-sa-note{font-size:12px;color:#56626a;}';
        wp_register_style('mad4b-service-live-readback',false,[],null);
        wp_enqueue_style('mad4b-service-live-readback');
        wp_add_inline_style('mad4b-service-live-readback',$css);
        $payload=[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('mad4b_live_service_probe'),
        ];
        $script='(()=>{const b=document.getElementById("mad4b-sa-public-probe");'
        .'const r=document.getElementById("mad4b-sa-probe-result");'
        .'const detail=document.getElementById("mad4b-sa-probe-details");if(!b||!r||!detail)return;'
        .'const cfg='.wp_json_encode($payload).';b.addEventListener("click",async()=>{'
        .'b.disabled=true;r.textContent="Checking public site without admin cookies…";'
        .'try{const body=new URLSearchParams({action:"mad4b_service_live_probe",nonce:cfg.nonce});'
        .'const response=await fetch(cfg.ajaxUrl,{method:"POST",credentials:"same-origin",body});'
        .'const data=await response.json();if(!response.ok||!data.success)throw new Error("Readback failed");'
        .'const v=data.data;const entries=Object.entries(v.checks||{});'
        .'const counts=entries.reduce((a,[k,x])=>(a[x.result]=(a[x.result]||0)+1,a),{});'
        .'r.textContent="HTTP "+String(v.status).toUpperCase()+" · "+entries.length+" routes checked · "'
        .'+String(counts.pass||0)+" pass · "+String(counts.drift||0)+" drift · "'
        .'+String(counts.unknown||0)+" unknown · "+String(v.observed_utc||"");'
        .'detail.replaceChildren();for(const [key,x] of entries){const li=document.createElement("li");'
        .'li.textContent=key.replaceAll("_"," ")+": expected "+String(x.expected_http||"unknown")'
        .'+", observed "+String(x.observed_http||"unknown")+" — "+String(x.result||"unknown");'
        .'detail.appendChild(li);}'
        .'}catch(e){r.textContent="Unable to verify public HTTP. Readiness remains unverified.";}'
        .'finally{b.disabled=false;}});})();';
        wp_register_script('mad4b-service-live-readback',false,[],null,true);
        wp_enqueue_script('mad4b-service-live-readback');
        wp_add_inline_script('mad4b-service-live-readback',$script);
    }
    public static function restStatus():WP_REST_Response {
        $response=rest_ensure_response(self::snapshot());
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function restRoutes():void {
        register_rest_route('mad4b/v1','/service-activation/live-status',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>[__CLASS__,'restStatus'],
        ]);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'restRoutes']);
        add_action('admin_notices',[__CLASS__,'notice'],3);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],20);
        add_action('wp_ajax_mad4b_service_live_probe',[__CLASS__,'ajaxProbe']);
    }
}
MAD4B_Service_Activation_Live_Readback_V1::boot();
}
