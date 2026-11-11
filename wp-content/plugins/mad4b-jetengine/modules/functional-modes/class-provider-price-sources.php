<?php
/**
 * MAD4B Provider-aware Price Sources v1.
 * Company self-provider + supplier identities + rate ownership + scoped,
 * prioritized source policies. Draft authoring and source-backed preview only.
 * This is intentionally not hooked into checkout or published prices.
 */
defined('ABSPATH') || exit;

final class MAD4B_Provider_Price_Sources_V1 {
    public const ACTION='mad4b_provider_price_sources_v1';
    public const OPTION='mad4b_provider_price_sources_draft_v1';
    public const HISTORY='mad4b_provider_price_sources_history_v1';
    private const MAX_RULES=80;
    private const MAX_PROVIDERS=35;
    private const TAXONOMIES=[
        'good-for','tour-types','tour_categories','tour-styles',
        'destinations','season','package_levels','group-size-tiers'
    ];
    private const LEVELS=['company'=>10,'provider'=>20,'group'=>30,'tour'=>40,'rate'=>50];

    public static function permitted():bool {
        return current_user_can('commerce_manage_pricing')
            && current_user_can('manage_woocommerce');
    }
    public static function boot():void {
        if(!is_admin())return;
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],30);
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax']);
    }
    public static function assets():void {
        if(!self::permitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))return;
        $url=plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        $dir=__DIR__.'/assets/';
        wp_enqueue_style('mad4b-provider-price-sources',$url.'provider-price-sources.css',[],
            (string)filemtime($dir.'provider-price-sources.css'));
        wp_enqueue_script('mad4b-provider-price-sources',$url.'provider-price-sources.js',[],
            (string)filemtime($dir.'provider-price-sources.js'),true);
        wp_add_inline_script('mad4b-provider-price-sources',
            'window.MAD4BProviderPriceSourcesConfig='.wp_json_encode([
                'url'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,
                'nonce'=>wp_create_nonce(self::ACTION)
            ]).';','before');
    }
    public static function current():array {
        // JetEngine Option Page repeaters are the only canonical source
        // once the native schema is provisioned. Legacy Draft option is
        // archived, never merged or written by this runtime.
        if(class_exists('MAD4B_Provider_JetEngine_Bridge_V1',false)
            &&MAD4B_Provider_JetEngine_Bridge_V1::schema_is_native()){
            $config=MAD4B_Provider_JetEngine_Bridge_V1::current();
            if(is_wp_error($config)){
                return ['revision'=>0,'providers'=>[],'rules'=>[],
                    'validation_error'=>$config->get_error_code(),
                    'storage'=>'jetengine_native','commercial_active'=>false,
                    'saved_at'=>null];
            }
            $history=get_option(MAD4B_Provider_JetEngine_Bridge_V1::AUDIT,[]);
            $last=is_array($history)&&$history?end($history):[];
            return [
                'revision'=>(int)($last['revision']??0),
                'providers'=>$config['providers'],'rules'=>$config['rules'],
                'saved_at'=>$last['timestamp']??null,
                'storage'=>'jetengine_native','commercial_active'=>false
            ];
        }
        // Support read-only inspection prior to schema provisioning only.
        $raw=get_option(self::OPTION,[]);
        if(!is_array($raw))$raw=[];
        return [
            'revision'=>max(0,(int)($raw['revision']??0)),
            'providers'=>is_array($raw['providers']??null)?$raw['providers']:[],
            'rules'=>is_array($raw['rules']??null)?$raw['rules']:[],
            'storage'=>'legacy_inactive','saved_at'=>$raw['saved_at']??null,
            'commercial_active'=>false
        ];
    }
    public static function connected():array {
        if(!function_exists('BookingExperience\supplier_integrations'))return [];
        $sources=\BookingExperience\supplier_integrations();
        if(is_wp_error($sources)||!is_array($sources))return [];
        $out=[];
        foreach($sources as $p) {
            if(!is_array($p)||empty($p['id']))continue;
            $out[(string)$p['id']]=[
                'id'=>(string)$p['id'],
                'name'=>sanitize_text_field((string)($p['name']??$p['id'])),
                'status'=>!empty($p['active'])?'connector_registered':'connector_inactive',
                'quote_authorized'=>!empty($p['active'])&&is_callable($p['quote']??null)
            ];
        }
        return $out;
    }
    public static function providers(?array $config=null):array {
        $config=$config??self::current();
        $out=['self'=>[
            'id'=>'self','name'=>get_bloginfo('name').' — Company / Self',
            'kind'=>'self','status'=>'native_company',
            'linked_rate_ids'=>[],'quote_authorized'=>false
        ]];
        $connected=self::connected();
        foreach($connected as $key=>$provider)$out[$key]=$provider+[
            'kind'=>'connector','linked_rate_ids'=>[]
        ];
        foreach($config['providers'] as $p) {
            if(!is_array($p)||empty($p['id']))continue;
            $id=(string)$p['id'];
            if($id==='self')continue;
            $known=$connected[$id]??null;
            $out[$id]=[
                'id'=>$id,'name'=>$known['name']??(string)$p['name'],
                'kind'=>$known?'connector':'manual',
                'status'=>$known['status']??'manual_unverified',
                'linked_rate_ids'=>array_values((array)($p['linked_rate_ids']??[])),
                'quote_authorized'=>$known['quote_authorized']??false
            ];
        }
        return $out;
    }
    private static function ids($raw,int $max=100):array|WP_Error {
        if(!is_array($raw)||count($raw)>$max)return new WP_Error('source_ids_invalid');
        $ids=[];
        foreach($raw as $id) {
            if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)
                return new WP_Error('source_id_invalid');
            $ids[(int)$id]=(int)$id;
        }
        return array_values($ids);
    }
    public static function validate(array $raw):array|WP_Error {
        if(array_diff(array_keys($raw),['providers','rules']))
            return new WP_Error('source_payload_unknown_keys');
        $providers=$raw['providers']??[];
        $rules=$raw['rules']??[];
        if(!is_array($providers)||count($providers)>self::MAX_PROVIDERS
            ||!is_array($rules)||count($rules)>self::MAX_RULES)
            return new WP_Error('source_payload_size');
        $cleanProviders=[];$names=['self'=>true];$rateOwnership=[];
        foreach($providers as $p) {
            if(!is_array($p))return new WP_Error('source_provider_invalid');
            $id=(string)($p['id']??'');
            $name=sanitize_text_field((string)($p['name']??''));
            if(!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D',$id)
               ||$id==='self'||isset($names[$id])
               ||$name===''||mb_strlen($name)>100)
                return new WP_Error('source_provider_identity_invalid');
            $ids=self::ids($p['linked_rate_ids']??[],120);
            if(is_wp_error($ids))return $ids;
            foreach($ids as $rid){
                $parent=(int)get_post_meta($rid,'related_tour_id',true);
                if(get_post_type($rid)!=='tour-rates'||get_post_status($rid)!=='publish'
                    ||$parent<1)
                    return new WP_Error('source_provider_rate_invalid','Published Rate and parent required');
                if(get_post_type($parent)!=='tours-and-activities')
                    return new WP_Error('source_provider_rate_parent_invalid');
                if(isset($rateOwnership[$rid]))
                    return new WP_Error('source_rate_multiple_providers','A Rate has multiple owners');
                $rateOwnership[$rid]=$id;
            }
            $names[$id]=true;
            $cleanProviders[]=['id'=>$id,'name'=>$name,'linked_rate_ids'=>$ids];
        }
        $connected=self::connected();
        $allowed=array_fill_keys(array_keys($names),true);
        foreach($connected as $id=>$p)$allowed[$id]=true;
        $out=[];$keys=[];
        foreach($rules as $r) {
            if(!is_array($r))return new WP_Error('source_rule_invalid');
            $id=sanitize_key((string)($r['id']??''));
            $provider=(string)($r['provider_id']??'');
            $scope=(string)($r['scope']??'');
            $source=(string)($r['source']??'');
            $priority=filter_var($r['priority']??null,FILTER_VALIDATE_INT);
            $fallback=(string)($r['fallback']??'next_valid');
            if(!preg_match('/^[a-z0-9_-]{3,70}$/D',$id)||isset($keys[$id])
                ||!isset($allowed[$provider])||!isset(self::LEVELS[$scope])
                ||!in_array($source,['prefer_rate','prefer_direct','rate_only','direct_only'],true)
                ||$priority===false||$priority<0||$priority>1000
                ||!in_array($fallback,['next_valid','strict'],true))
                return new WP_Error('source_rule_parameters_invalid');
            if($scope==='company'&&$provider!=='self')
                return new WP_Error('source_company_scope_self_only');
            if($provider!=='self'&&in_array($source,['prefer_direct','direct_only'],true))
                return new WP_Error('source_external_direct_unverified',
                    'Direct Tour Price belongs to the company self-provider');
            $tourIds=self::ids($r['tour_ids']??[],80);
            $rateIds=self::ids($r['rate_ids']??[],100);
            $termIds=self::ids($r['term_ids']??[],40);
            foreach([$tourIds,$rateIds,$termIds] as $ids)
                if(is_wp_error($ids))return $ids;
            foreach($tourIds as $tid)
                if(get_post_type($tid)!=='tours-and-activities'
                    ||get_post_status($tid)!=='publish')
                    return new WP_Error('source_tour_invalid');
            foreach($rateIds as $rid)
                if(get_post_type($rid)!=='tour-rates'||get_post_status($rid)!=='publish')
                    return new WP_Error('source_rule_rate_invalid');
            $tax=(string)($r['taxonomy']??'');
            if($scope==='group'){
                if(!in_array($tax,self::TAXONOMIES,true)||!$termIds)
                    return new WP_Error('source_group_missing');
                foreach($termIds as $termId)
                    if(!term_exists($termId,$tax))
                        return new WP_Error('source_group_term_invalid');
            }
            if($scope==='tour'&&!$tourIds)return new WP_Error('source_tour_scope_missing');
            if($scope==='rate'&&!$rateIds)return new WP_Error('source_rate_scope_missing');
            // Direct source cannot be scoped to a Rate, and a Rate-level
            // rule must be owned by its declared provider.
            if($scope==='rate')foreach($rateIds as $rid){
                $owner=$rateOwnership[$rid]??'self';
                if($owner!==$provider)return new WP_Error('source_rate_provider_mismatch',
                    'Rate scope requires explicit provider ownership');
            }
            $keys[$id]=true;
            $out[]=[
                'id'=>$id,'provider_id'=>$provider,'scope'=>$scope,
                'priority'=>$priority,'source'=>$source,'fallback'=>$fallback,
                'taxonomy'=>$scope==='group'?$tax:'',
                'term_ids'=>$scope==='group'?$termIds:[],
                'tour_ids'=>$scope==='tour'?$tourIds:[],
                'rate_ids'=>$scope==='rate'?$rateIds:[],
                'preview_enabled'=>!empty($r['preview_enabled'])
            ];
        }
        return ['providers'=>$cleanProviders,'rules'=>$out];
    }
    private static function canonical(int $id,string $type):int {
        $lang=apply_filters('wpml_default_language','en');
        $out=apply_filters('wpml_object_id',$id,$type,true,$lang);
        return (int)($out?:$id);
    }
    private static function ruleMatches(array $rule,int $tour,int $rate):bool {
        if(empty($rule['preview_enabled']))return false;
        switch($rule['scope']) {
            case 'company':case 'provider':return true;
            case 'tour':
                $cid=self::canonical($tour,'tours-and-activities');
                foreach($rule['tour_ids'] as $id)
                    if(self::canonical((int)$id,'tours-and-activities')===$cid)
                        return true;
                return false;
            case 'rate':return in_array($rate,$rule['rate_ids'],true);
            case 'group':
                $tax=$rule['taxonomy'];
                $terms=wp_get_object_terms($tour,$tax,['fields'=>'ids']);
                if(is_wp_error($terms)||!is_array($terms))return false;
                $owned=array_map(static fn($v)=>self::canonical((int)$v,$tax),$terms);
                foreach($rule['term_ids'] as $term)
                    if(in_array(self::canonical((int)$term,$tax),$owned,true))return true;
                return false;
        }
        return false;
    }
    private static function ruleFor(array $rules,string $provider,int $tour,int $rate):array {
        $out=[];
        foreach($rules as $rule) {
            if($rule['provider_id']!==$provider||!self::ruleMatches($rule,$tour,$rate))
                continue;
            $out[]=$rule;
        }
        usort($out,static fn($a,$b)=>
            (self::LEVELS[$b['scope']]<=>self::LEVELS[$a['scope']])
            ?:($b['priority']<=>$a['priority'])
            ?:strcmp($a['id'],$b['id']));
        if($out)return $out[0];
        return [
            'id'=>'implicit_'. $provider,'provider_id'=>$provider,
            'scope'=>$provider==='self'?'company':'provider',
            'priority'=>0,'source'=>'prefer_rate','fallback'=>'next_valid',
            'preview_enabled'=>true
        ];
    }
    public static function preview(int $tour,int $rate=0,string $audience='general',string $occupancy='double',?array $config=null):array|WP_Error {
        if($tour<1||get_post_type($tour)!=='tours-and-activities'
            ||get_post_status($tour)!=='publish')
            return new WP_Error('source_preview_tour_invalid');
        if($rate&&(
            get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'
            ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour
        ))return new WP_Error('source_preview_rate_not_related');
        if(!in_array($audience,['general','egyptians'],true)
            ||!in_array($occupancy,['single','double','triple'],true))
            return new WP_Error('source_preview_context_invalid');
        $config=$config??self::current();
        if(!empty($config['validation_error']))
            return new WP_Error('provider_native_config_invalid',
                'Repair JetEngine source rules: '.$config['validation_error']);
        $providers=self::providers($config);
        $rateOwner=[];
        foreach($config['providers'] as $provider)
            foreach($provider['linked_rate_ids'] as $id)$rateOwner[(int)$id]=$provider['id'];
        $rows=[];$conflicts=[];
        $direct=get_post_meta($tour,'start_price',true);
        $cc=(string)get_post_meta($tour,'tour_base_currency',true);
        if(is_numeric($direct)&&(float)$direct>0
            &&get_post_meta($tour,'tour_price_unit',true)==='per_person'
            &&preg_match('/^[A-Z]{3}$/D',$cc)) {
            $rows[]=['source'=>'direct','provider_id'=>'self','rate_id'=>0,
                'amount'=>(float)$direct,'currency'=>$cc,'evidence'=>'tour_start_price'];
        }
        $ids=$rate?[$rate]:get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
            'fields'=>'ids','posts_per_page'=>80,'suppress_filters'=>true,
            'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour]]]);
        foreach($ids as $rid) {
            $rid=(int)$rid;
            if(!function_exists('pricing_audience_quote'))break;
            $quote=pricing_audience_quote($rid,$audience,$occupancy);
            if(($quote['status']??'')!=='available')continue;
            $owner=$rateOwner[$rid]??'self';
            $rows[]=['source'=>'rate','provider_id'=>$owner,'rate_id'=>$rid,
                'amount'=>(float)$quote['final'],'currency'=>$quote['source_currency'],
                'evidence'=>!empty($quote['discounted'])?'active_rate_sale':'rate_regular'];
        }
        $matches=[];
        foreach($rows as $row){
            $provider=$row['provider_id'];$ref=self::ruleFor($config['rules'],$provider,$tour,(int)$row['rate_id']);
            $choice=$ref['source'];
            $acceptable=$row['source']==='rate'
                ?!in_array($choice,['direct_only'],true)
                :!in_array($choice,['rate_only'],true);
            // Strict means the preferred source must actually be the
            // eligible kind; no silent fallback to the secondary source.
            if($ref['fallback']==='strict'
               &&(($choice==='prefer_rate'&&$row['source']!=='rate')
                   ||($choice==='prefer_direct'&&$row['source']!=='direct')))
                $acceptable=false;
            $row['rule_id']=$ref['id'];
            $row['scope']=$ref['scope'];
            $row['priority']=$ref['priority'];
            $row['fallback']=$ref['fallback'];
            $row['provider']=$providers[$provider]['name']??$provider;
            $row['eligible']=$acceptable;
            $row['source_order']=($choice==='prefer_rate'||$choice==='rate_only')
                ?($row['source']==='rate'?2:1):($row['source']==='direct'?2:1);
            $row['level']=self::LEVELS[$ref['scope']];
            $matches[]=$row;
        }
        usort($matches,static fn($a,$b)=>
            ($b['level']<=>$a['level'])?:($b['priority']<=>$a['priority'])
            ?:($b['source_order']<=>$a['source_order'])?:($a['rate_id']<=>$b['rate_id']));
        $eligible=array_values(array_filter($matches,static fn($r)=>$r['eligible']));
        $winner=$eligible[0]??null;
        if(count($eligible)>1 && $winner){
            $second=$eligible[1];
            if($winner['level']===$second['level']
                &&$winner['priority']===$second['priority']
                &&$winner['source_order']===$second['source_order']){
                $conflicts[]=$winner['provider_id']===$second['provider_id']
                    &&$winner['source']==='rate'&&$second['source']==='rate'
                    ?'multiple_eligible_rates_choose_rate_id'
                    :'provider_priority_tie';
            }
        }
        // Never report a commercial winner from a tied or ambiguous row.
        if($conflicts)$winner=null;
        $legacy=function_exists('shared_product_price_source_policy')
            ?shared_product_price_source_policy((string)get_post_meta($tour,'tour_mode',true))
            :null;
        return [
            'status'=>'preview_only','tour_id'=>$tour,'rate_id'=>$rate,
            'company_provider'=>'self','providers'=>array_values($providers),
            'rows'=>array_slice($matches,0,60),'winner'=>$winner,
            'conflicts'=>$conflicts,'legacy_source_policy'=>is_wp_error($legacy)?null:$legacy,
            'checkout_ready'=>false,'commercial_active'=>false,
            'note'=>'Policies saved as Draft. Commercial Source resolver and checkout are unchanged.'
        ];
    }
    public static function catalog():array {
        $current=self::current();
        $tax=[];
        foreach(self::TAXONOMIES as $id){
            $obj=get_taxonomy($id);
            if($obj&&is_object_in_taxonomy('tours-and-activities',$id))
                $tax[]=['id'=>$id,'label'=>$obj->labels->name];
        }
        $tours=get_posts(['post_type'=>'tours-and-activities',
            'post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>80,'orderby'=>'title','order'=>'ASC']);
        return [
            'revision'=>$current['revision'],'providers'=>$current['providers'],
            'rules'=>$current['rules'],'available_providers'=>array_values(self::providers($current)),
            'config_storage'=>$current['storage']??'unknown',
            'validation_error'=>$current['validation_error']??null,
            'tour_choices'=>array_map(static fn($id)=>[
                'id'=>(int)$id,'title'=>sanitize_text_field(get_the_title($id))],$tours),
            'taxonomies'=>$tax,'company_name'=>get_bloginfo('name'),
            'limits'=>['providers'=>self::MAX_PROVIDERS,'rules'=>self::MAX_RULES],
            'commercial_active'=>false,'scope'=>'draft_preview_only',
            'description'=>'Rates must be explicitly assigned to an external provider. Unassigned Rates inherit the company self-provider.'
        ];
    }
    public static function terms(string $taxonomy,string $search=''):array|WP_Error {
        if(!in_array($taxonomy,self::TAXONOMIES,true))return new WP_Error('source_taxonomy_invalid');
        $args=['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>100,'orderby'=>'name'];
        if($search!=='')$args['search']=sanitize_text_field($search);
        $terms=get_terms($args);
        if(is_wp_error($terms))return $terms;
        return array_map(static fn($t)=>['id'=>(int)$t->term_id,'label'=>$t->name],$terms);
    }
    public static function save(array $payload,int $expected):array|WP_Error {
        // The native JetEngine Save is sole writer of both Repeaters.
        if(class_exists('MAD4B_Provider_JetEngine_Bridge_V1',false)
           &&MAD4B_Provider_JetEngine_Bridge_V1::schema_is_native())
            return new WP_Error('source_use_jetengine_save',
                'Save changes through the native JetEngine Options Page');
        $validated=self::validate($payload);
        if(is_wp_error($validated))return $validated;
        $previous=self::current();
        if($previous['revision']!==$expected)
            return new WP_Error('source_revision_conflict','Reload provider rules before saving');
        $next=$validated+[
            'revision'=>$expected+1,'saved_at'=>gmdate('c'),
            'commercial_active'=>false,'actor'=>get_current_user_id()
        ];
        update_option(self::OPTION,$next,false);
        $read=self::current();
        if($read['revision']!==$expected+1)return new WP_Error('source_save_verification_failed');
        $history=get_option(self::HISTORY,[]);
        if(!is_array($history))$history=[];
        $history[]=['revision'=>$read['revision'],'actor'=>get_current_user_id(),
            'at'=>$next['saved_at'],'providers'=>count($next['providers']),
            'rules'=>count($next['rules']),'digest'=>hash('sha256',wp_json_encode($next)),
            'commercial_active'=>false];
        update_option(self::HISTORY,array_slice($history,-30),false);
        return ['status'=>'draft_saved','revision'=>$read['revision'],
            'rules'=>count($next['rules']),'providers'=>count($next['providers']),
            'commercial_active'=>false,'checkout_ready'=>false];
    }
    public static function ajax():void {
        if(!self::permitted())wp_send_json_error(['message'=>'Pricing permission required'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $op=sanitize_key((string)($_POST['operation']??'catalog'));
        if($op==='catalog')wp_send_json_success(self::catalog());
        if($op==='terms'){
            $rows=self::terms(sanitize_key((string)($_POST['taxonomy']??'')),
                substr(sanitize_text_field((string)($_POST['search']??'')),0,75));
            if(is_wp_error($rows))wp_send_json_error(['message'=>$rows->get_error_code()],400);
            wp_send_json_success($rows);
        }
        if($op==='preview'){
            $selected=null;
            if(isset($_POST['payload'])&&is_string($_POST['payload'])){
                $raw=wp_unslash($_POST['payload']);
                if(strlen($raw)>160000)wp_send_json_error(['message'=>'Payload too large'],413);
                $draft=json_decode($raw,true);
                if(!is_array($draft))
                    wp_send_json_error(['message'=>'Preview JSON invalid'],400);
                $selected=self::validate($draft);
                if(is_wp_error($selected))
                    wp_send_json_error(['message'=>$selected->get_error_code()],400);
            }
            $result=self::preview(absint($_POST['tour_id']??0),
                absint($_POST['rate_id']??0),
                sanitize_key((string)($_POST['audience']??'general')),
                sanitize_key((string)($_POST['occupancy']??'double')),$selected);
            if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_code()],400);
            wp_send_json_success($result);
        }
        if($op==='save'){
            if(class_exists('MAD4B_Provider_JetEngine_Bridge_V1',false)
                &&MAD4B_Provider_JetEngine_Bridge_V1::schema_is_native())
                wp_send_json_error(['message'=>'Use JetEngine Save changes; secondary provider save is disabled'],409);
            if((string)($_POST['confirm']??'')!=='save_provider_source_draft')
                wp_send_json_error(['message'=>'Explicit Draft save confirmation missing'],400);
            $json=wp_unslash((string)($_POST['payload']??''));
            if(strlen($json)>160000)wp_send_json_error(['message'=>'Payload too large'],413);
            $data=json_decode($json,true);
            if(!is_array($data))wp_send_json_error(['message'=>'Invalid JSON'],400);
            $expected=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
            if($expected===false||$expected<0)
                wp_send_json_error(['message'=>'Revision missing'],400);
            $result=self::save($data,$expected);
            if(is_wp_error($result))
                wp_send_json_error(['message'=>$result->get_error_code()],409);
            wp_send_json_success($result);
        }
        wp_send_json_error(['message'=>'Unsupported operation'],400);
    }
}
