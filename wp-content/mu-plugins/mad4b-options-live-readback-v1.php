<?php
/**
 * Plugin Name: MAD4B Options Live Readback
 * Description: On-demand, read-only runtime status of Destination Activation and pricing options.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Options_Live_Readback_V1',false)){
final class MAD4B_Options_Live_Readback_V1 {
    public const CONTRACT='mad4b.options-live-readback.v1';
    private const PAGES=[
        'destination-activation','etg-dynamic-pricing-controls',
        'dynamic-pricing-controls','mad4b-pricing-lab',
        'calculate-booking-price','abk-selling-options',
        'mad4b-pricing-currencies','mad4b-pricing-workspace',
    ];
    private const PRICE_KEYS=[
        'pricing_engine_enabled','booking_enabled','booking_fulfillment_mode',
        'require_availability','allow_manual_overrides','stop_sale_global',
        'shared_price_source_direct_weight','shared_price_source_rate_weight',
        'shared_price_source_tie_break','shared_price_source_fallback',
        'shared_price_source_visibility','shared_price_card_date_mode',
        'default_currency','tax_percent','service_fee_percent',
        'minimum_booking_lead_hours','quote_ttl_minutes',
    ];
    private const FINANCIAL_FIELDS=[
        'tax_price_basis','discount_tax_order','payment_fee_taxable',
        'payment_fee_bearer','discount_scope','refund_allocation','refund_payment_fee',
    ];
    public static function permitted():bool {
        return current_user_can('manage_options');
    }
    public static function is_page():bool {
        if(!is_admin() || !self::permitted())return false;
        $slug=isset($_GET['page'])&&is_string($_GET['page'])
            ?sanitize_key(wp_unslash($_GET['page'])):'';
        return in_array($slug,self::PAGES,true);
    }
    public static function option_state($p,string $key):array {
        if(!is_object($p))return ['status'=>'page_unavailable','value'=>null];
        $fields=(array)($p->page['fields']??[]);
        if(!array_key_exists($key,$fields))
            return ['status'=>'definition_missing','value'=>null];
        $option=$p->storage_type==='separate'
            ? $p->get_separate_option_name($key):$p->slug;
        $sentinel='__mad4b_readback_unset__';
        $raw=get_option($option,$sentinel);
        $stored=$p->storage_type==='separate'
            ?$raw:(is_array($raw)&&array_key_exists($key,$raw)?$raw[$key]:$sentinel);
        if($stored===$sentinel || $stored===null || $stored==='')
            return ['status'=>'not_configured','value'=>null];
        if(is_bool($stored)||is_numeric($stored))
            return ['status'=>'explicit','value'=>$stored];
        if(is_string($stored))
            return ['status'=>'explicit','value'=>strlen($stored)<=40?$stored:'configured_string'];
        return ['status'=>'explicit','value'=>is_array($stored)?['items'=>count($stored)]:gettype($stored)];
    }
    private static function pricing($pages):array {
        $p=$pages['etg-dynamic-pricing-controls']??null;
        $fields=is_object($p)?(array)($p->page['fields']??[]):[];
        $control=[];
        foreach(self::PRICE_KEYS as $key)$control[$key]=self::option_state($p,$key);
        $provider=[];
        foreach(['pricing_engine_enabled','booking_enabled','stop_sale_global',
            'default_currency','quote_ttl_minutes'] as $key){
            if(!function_exists('shared_product_pricing_setting')){
                $provider[$key]=['status'=>'reader_unavailable'];
                continue;
            }
            $value=shared_product_pricing_setting($key);
            $provider[$key]=is_wp_error($value)
                ?['status'=>'unconfigured_or_invalid','reason'=>$value->get_error_code()]
                :['status'=>'verified','value'=>$value];
        }
        $rows=is_object($p)?$p->get('shared_mode_policy_rows',[]):[];
        $modeDetails=[];
        $errors=[];
        foreach((array)$rows as $row){
            if(!is_array($row)){$errors[]='malformed_row';continue;}
            $mode=(string)($row['mode']??'');
            if($mode===''||isset($modeDetails[$mode])){
                $errors[]='duplicate_or_missing_mode';continue;
            }
            $missing=array_values(array_diff(self::FINANCIAL_FIELDS,array_keys($row)));
            $modeDetails[$mode]=[
                'strategy'=>$row['strategy']??null,
                'enabled'=>($row['enabled']??null)==='1',
                'financial_fields_present'=>7-count($missing),
                'financial_fields_missing'=>$missing,
                'price_basis'=>$row['tax_price_basis']??'unconfigured',
                'refund_rule'=>$row['refund_allocation']??'unconfigured'
            ];
        }
        return [
            'schema_fields'=>count($fields),
            'expected_booking_field_present'=>isset($fields['booking_fulfillment_mode']),
            'controls'=>$control,'provider_effective'=>$provider,
            'mode_policy_rows'=>$modeDetails,
            'mode_policy_errors'=>$errors,
            'all_four_modes_present'=>count($modeDetails)===4 &&
                count(array_diff(['tour','activity','extra_trip','cruise_tour'],array_keys($modeDetails)))===0,
            'financial_policy_complete'=>count($modeDetails)===4
                && !array_filter($modeDetails,static fn($r)=>count($r['financial_fields_missing'])>0),
            'effective_booking_authority'=>'request_only_unless_independently_certified',
        ];
    }
    private static function destination($pages):array {
        $p=$pages['destination-activation']??null;
        $groups=function_exists('destinations_activation_groups')
            ?destinations_activation_groups():[];
        $saved=get_option('destination-activation',null);
        $result=[];
        $errors=0;
        foreach(['active_continents','active_countries','active_governorates'] as $key){
            $selected=array_values(array_unique(array_map('intval',(array)($saved[$key]??[]))));
            $available=(array)($groups[$key]??[]);
            $ids=array_map('intval',array_column($available,'id'));
            $unknown=array_values(array_diff($selected,$ids));
            $mismatches=[];
            $orphanParents=[];
            foreach($available as $item) {
                $id=(int)$item['id'];
                $should=in_array($id,$selected,true);
                $public=get_term_meta($id,'is_public',true);
                if(($should&&$public!=='1')||(!$should&&$public!=='0'))
                    $mismatches[]=$id;
                if($should){
                    foreach((array)($item['ancestors']??[]) as $ancestor)
                        if(get_term_meta((int)$ancestor,'is_public',true)==='0'){
                            $orphanParents[]=$id;break;
                        }
                }
            }
            $errors+=count($unknown)+count($mismatches)+count($orphanParents);
            $result[$key]=[
                'registered'=>is_object($p)&&isset($p->page['fields'][$key]),
                'selected'=>count($selected),
                'available_language_entries'=>count($available),
                'unknown_selected_count'=>count($unknown),
                'visibility_mismatch_count'=>count($mismatches),
                'disabled_parent_count'=>count($orphanParents),
            ];
        }
        return [
            'saved'=>is_array($saved),'groups'=>$result,
            'hierarchy_and_public_flags_consistent'=>$errors===0,
            'runtime_guard_loaded'=>function_exists('destinations_activation_allowed'),
        ];
    }
    private static function dimensions():array {
        $class='MAD4B_JetEngine_Pricing_Dimensions_V1';
        if(!class_exists($class,false))return ['status'=>'module_unavailable'];
        $state=$class::current();
        $language=$class::language_policy();
        $available=$class::available();
        $ids=array_column($available,'id');
        $active=$state['mode']==='all'?count($ids):
            count(array_intersect($ids,$state['enabled_ids']));
        $rangeCount=count(array_filter($available,
            static fn($x)=>($x['kind']??'')==='range'));
        return [
            'status'=>'ready','mode'=>$state['mode'],'saved'=>$state['saved'],
            'available'=>count($ids),'active'=>$active,
            'range_dimensions'=>$rangeCount,
            'default_language'=>$language['default_language'],
            'authoring_language'=>$language['authoring_language'],
            'effective_languages'=>$language['effective_languages'],
            'native_schema_derived'=>true,
            'catalog_may_differ_with_published_content'=>true,
            'dimension_ids'=>$ids,
            'live_checkout_policy_changed'=>false
        ];
    }
    private static function currency():array {
        $class='MAD4B_Pricing_Currency_Configuration_V1';
        if(!class_exists($class,false))return ['status'=>'module_unavailable'];
        $cfg=$class::current();
        return [
            'status'=>'ready','saved'=>$cfg['saved'],'mode'=>$cfg['mode'],
            'picker'=>$cfg['picker'],
            'store_base_currency'=>$cfg['wcml']['base_currency'],
            'wcml_enabled'=>$cfg['wcml']['enabled'],
            'display_currencies'=>$cfg['wcml']['currencies'],
            'supported_glossary_count'=>count($cfg['effective_currencies']),
            'new_draft_authoring_currencies'=>array_keys($class::authoring_choices()),
            'checkout_currencies_independent_of_authoring'=>true,
        ];
    }
    private static function selling($pages):array {
        $p=$pages['abk-selling-options']??null;
        $field=self::option_state($p,'allowed_price_units');
        $values=get_option('abk-selling-options',[]);
        return [
            'saved'=>$field['status']==='explicit',
            'registered'=>is_object($p)&&isset($p->page['fields']['allowed_price_units']),
            'allowed_units'=>is_array($values)&&is_array($values['allowed_price_units']??null)
                ?array_values(array_filter($values['allowed_price_units'],'is_string')):[],
        ];
    }
    public static function snapshot():array {
        $pages=function_exists('jet_engine')?
            (jet_engine()->options_pages->registered_pages??[]):[];
        if(!is_array($pages))$pages=[];
        return [
            'contract'=>self::CONTRACT,
            'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'observed_utc'=>gmdate('c'),
            'destinations'=>self::destination($pages),
            'pricing_configuration'=>self::pricing($pages),
            'pricing_dimensions'=>self::dimensions(),
            'currencies'=>self::currency(),
            'selling_options'=>self::selling($pages),
            'writes_performed'=>false,
            'payment_authority_changed'=>false,
            'prices_changed'=>false,
            'tenant_scope'=>'local_wordpress_read_only',
        ];
    }
    public static function restStatus():WP_REST_Response {
        $response=rest_ensure_response(self::snapshot());
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function restRoutes():void {
        register_rest_route('mad4b/v1','/options-live-status',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>self::permitted(),
            'callback'=>[__CLASS__,'restStatus'],
        ]);
    }
    public static function notice():void {
        if(!self::is_page())return;
        echo '<section class="notice notice-info" role="region" aria-label="Live options readback"'
            .' style="padding:12px 18px;margin:12px 16px 12px 0">';
        echo '<h2 style="margin:5px 0 8px">Live Options Readback</h2>'
            .'<p>Compare saved, inherited and effective Destination Activation / Pricing state without writing values.'
            .' This is not payment or booking readiness.</p>'
            .'<p><button type="button" class="button button-secondary" id="mad4b-options-live-check">'
            .'Inspect current WordPress options</button> '
            .'<span role="status" aria-live="polite" id="mad4b-options-live-state">Not checked in this session.</span></p>'
            .'<div id="mad4b-options-live-details" style="max-width:950px"></div>'
            .'</section>';
    }
    public static function assets():void {
        if(!self::is_page())return;
        $cfg=['url'=>rest_url('mad4b/v1/options-live-status'),
            'nonce'=>wp_create_nonce('wp_rest')];
        $js='(()=>{const b=document.getElementById("mad4b-options-live-check");'
            .'const s=document.getElementById("mad4b-options-live-state");'
            .'const d=document.getElementById("mad4b-options-live-details");'
            .'if(!b||!s||!d)return;const cfg='.wp_json_encode($cfg).';'
            .'b.addEventListener("click",async()=>{b.disabled=true;s.textContent="Reading WordPress…";'
            .'try{const res=await fetch(cfg.url,{credentials:"same-origin",cache:"no-store",'
            .'headers:{"X-WP-Nonce":cfg.nonce}});if(!res.ok)throw new Error("denied");'
            .'const x=await res.json();const total=x.pricing_configuration?.schema_fields||0;'
            .'const g=x.destinations?.groups||{};'
            .'const destinations=Object.entries(g).map(([key,v])=>key.replace("active_","")+": "'
            .'+v.selected+" / "+v.available_language_entries).join(" · ");'
            .'s.textContent="WordPress "+String(x.observed_utc||"")+" · Read only";d.replaceChildren();'
            .'const rows=['
            .'["Destination Activation",destinations+" · Hierarchy "+(x.destinations?.hierarchy_and_public_flags_consistent?"PASS":"DRIFT")],'
            .'["Pricing Controls",total+" fields · Booking "+String(x.pricing_configuration?.provider_effective?.booking_enabled?.status||"unverified")'
            .'+": "+String(x.pricing_configuration?.provider_effective?.booking_enabled?.value??"unconfigured")],'
            .'["Financial mode policies",x.pricing_configuration?.financial_policy_complete?"4 modes complete":"Missing policies / inspect source"],'
            .'["Pricing Dimensions",String(x.pricing_dimensions?.active||0)+" active / "+String(x.pricing_dimensions?.available||0)+" data-backed on this site"],'
            .'["Currencies","Store "+String(x.currencies?.store_base_currency||"?")+" · WCML display "'
            .'+(x.currencies?.display_currencies||[]).join(", ")+" · New draft authoring "'
            .'+(x.currencies?.new_draft_authoring_currencies||[]).join(", ")],'
            .'["Selling Units",(x.selling_options?.allowed_units||[]).join(", ")||"Unconfigured"]];'
            .'const table=document.createElement("table");table.className="widefat striped";'
            .'for(const [name,value] of rows){const tr=document.createElement("tr");'
            .'const th=document.createElement("th");th.scope="row";th.textContent=name;'
            .'const td=document.createElement("td");td.textContent=value;'
            .'tr.append(th,td);table.appendChild(tr);}d.appendChild(table);'
            .'}catch(e){s.textContent="Readback unavailable. Options status unverified.";}'
            .'finally{b.disabled=false;}});})();';
        wp_register_script('mad4b-options-live-readback',false,[],null,true);
        wp_enqueue_script('mad4b-options-live-readback');
        wp_add_inline_script('mad4b-options-live-readback',$js);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'restRoutes']);
        add_action('admin_notices',[__CLASS__,'notice'],4);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],25);
    }
}
MAD4B_Options_Live_Readback_V1::boot();
}
