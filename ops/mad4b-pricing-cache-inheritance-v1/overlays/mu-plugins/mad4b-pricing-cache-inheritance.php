<?php
/**
 * Plugin Name: MAD4B Pricing-Aware Cache Inheritance
 * Description: Scope-aware policy blueprint caching and immutable/dynamic classification, never quote HTML or amounts.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Pricing_Cache_Inheritance_V1', false)) {
final class MAD4B_Pricing_Cache_Inheritance_V1 {
    public const VERSION='1.1.0';
    public const OPTION='mad4b_pricing_cache_inheritance_v1';
    private const GROUP='mad4b-pricing-blueprints-v1';
    private static array $counters=['hit'=>0,'miss'=>0,'bypass'=>0,'stored'=>0,'fail'=>0];
    private static array $last=[];

    /* Restrict the cache to descriptors. Amounts, customer and availability
     * decisions must always be obtained from the native quote provider. */
    private const CLASSIFICATION=[
        'site_brand_tokens'=>['class'=>'static','kind'=>'metadata'],
        'published_taxonomy_labels'=>['class'=>'static','kind'=>'metadata'],
        'elementor_layout_blueprint'=>['class'=>'conditional','kind'=>'layout'],
        'mode_policy_definition'=>['class'=>'conditional','kind'=>'policy_blueprint'],
        'pricing_source_definition'=>['class'=>'conditional','kind'=>'policy_blueprint'],
        'financial_rule_definition'=>['class'=>'conditional','kind'=>'policy_blueprint'],
        'component_pricing_defaults'=>['class'=>'static','kind'=>'metadata'],
        'public_service_taxonomy'=>['class'=>'static','kind'=>'metadata'],
        'rate_schedule_definition'=>['class'=>'conditional','kind'=>'policy_blueprint'],
        'tour_public_description'=>['class'=>'conditional','kind'=>'metadata'],
        'tour_rate_eligibility'=>['class'=>'dynamic','kind'=>'rate_eligibility'],
        'rate_price_amount'=>['class'=>'dynamic','kind'=>'amount'],
        'customer_quote'=>['class'=>'dynamic','kind'=>'quote'],
        'checkout_total'=>['class'=>'dynamic','kind'=>'checkout'],
        'payment_fee_amount'=>['class'=>'dynamic','kind'=>'amount'],
        'live_availability'=>['class'=>'dynamic','kind'=>'availability'],
        'extras_selection'=>['class'=>'dynamic','kind'=>'booking'],
        'market_price_adjustment'=>['class'=>'dynamic','kind'=>'amount'],
        'currency_conversion_amount'=>['class'=>'dynamic','kind'=>'amount'],
    ];
    private const TTL_DEFAULTS=['static_ttl'=>1800,'policy_ttl'=>1800,'conditional_ttl'=>900,'mode'=>'auto'];

    private static function safe_override($raw): array {
        if (!is_array($raw)) return [];
        $out=[];
        foreach (self::TTL_DEFAULTS as $key=>$default) {
            if (!array_key_exists($key,$raw) || $raw[$key]==='inherit' || $raw[$key]==='') continue;
            if ($key==='mode') {
                if (in_array($raw[$key],['auto','disabled','private_only'],true))$out[$key]=$raw[$key];
                continue;
            }
            $v=$raw[$key];
            if ((is_int($v) || (is_string($v)&&ctype_digit($v))) && (int)$v>=0 && (int)$v<=2400)
                $out[$key]=(int)$v;
        }
        return $out;
    }
    private static function read_overrides(): array {
        $raw=get_option(self::OPTION,[]);
        return is_array($raw)?$raw:[];
    }
    private static function context(array $input): array {
        $ctx=[];
        foreach (['tour_id','rate_id','program_term_id','season_id','property_id','product_id'] as $key)
            $ctx[$key]=max(0,absint($input[$key]??0));
        $ctx['tour_mode']=sanitize_key((string)($input['tour_mode']??''));
        $ctx['language']=sanitize_key((string)($input['language']??apply_filters('wpml_current_language','en')));
        $ctx['device']=in_array(($input['device']??''),['mobile','desktop','tablet'],true)?$input['device']:(wp_is_mobile()?'mobile':'desktop');
        foreach (['currency','market_profile','passport','nationality','occupancy','travel_date','audience','party_size','payment_method'] as $key)
            if (isset($input[$key]) && is_scalar($input[$key]))$ctx[$key]=substr((string)$input[$key],0,95);
        return $ctx;
    }
    private static function mode_if_known(array $ctx): bool {
        if (!$ctx['tour_mode'])return false;
        $rows=get_option('etg-dynamic-pricing-controls_shared_mode_policy_rows',[]);
        if (!is_array($rows))return false;
        foreach ($rows as $r)if(is_array($r)&&sanitize_key((string)($r['mode']??''))===$ctx['tour_mode'])
            return in_array($r['enabled']??null,[true,1,'1','yes','on','true'],true);
        return false;
    }
    private static function policy(array $ctx): array {
        $policy=self::TTL_DEFAULTS;
        $trace=['built_in'];
        $config=self::read_overrides();
        if (isset($config['company'])) {
            $policy=array_replace($policy,self::safe_override($config['company']));$trace[]='company';
        }
        $mode=$ctx['tour_mode'];
        if ($mode!=='' && isset($config['modes'][$mode])) {
            $policy=array_replace($policy,self::safe_override($config['modes'][$mode]));$trace[]='mode:'.$mode;
        }
        foreach (['program_term_id'=>'programs','season_id'=>'season'] as $idField=>$taxonomy) {
            $term=(int)$ctx[$idField];
            if (!$term || !isset($config['terms'][$taxonomy][$term]))continue;
            $actual=get_term($term,$taxonomy);
            if ($actual instanceof WP_Term && !is_wp_error($actual)) {
                $policy=array_replace($policy,self::safe_override($config['terms'][$taxonomy][$term]));
                $trace[]='taxonomy:'.$taxonomy.':'.$term;
            }
        }
        $tour=(int)$ctx['tour_id'];
        if ($tour && get_post_type($tour)==='tours-and-activities' && isset($config['tours'][$tour])) {
            $policy=array_replace($policy,self::safe_override($config['tours'][$tour]));$trace[]='tour:'.$tour;
        }
        $rate=(int)$ctx['rate_id'];
        if ($rate && get_post_type($rate)==='tour-rates'
            && (!$tour || (int)get_post_meta($rate,'related_tour_id',true)===$tour)
            && isset($config['rates'][$rate])) {
            $policy=array_replace($policy,self::safe_override($config['rates'][$rate]));$trace[]='rate:'.$rate;
        }
        if (class_exists('MAD4B_Company_Policy_Registry_V2',false)) {
            $native=['tour_id'=>$tour,'rate_id'=>$rate,'program_term_id'=>$ctx['program_term_id'],
                'season_id'=>$ctx['season_id'],'language'=>$ctx['language'],'device'=>$ctx['device']];
            $existing=MAD4B_Company_Policy_Registry_V2::resolve('pricing.cache.inheritance',$native,$policy);
            if (is_array($existing) && is_array($existing['values']??null)) {
                $policy=array_replace($policy,self::safe_override($existing['values']));
                if (!empty($existing['receipt']['rule_id']))$trace[]='registry:'.$existing['receipt']['rule_id'];
            }
        }
        return [$policy,$trace];
    }

    public static function decision(string $surface, array $input=[]): array {
        $ctx=self::context($input);
        $profile=self::CLASSIFICATION[$surface]??['class'=>'dynamic','kind'=>'unknown'];
        [$settings,$trace]=self::policy($ctx);
        $class=$profile['class'];
        // The normalized-mode-policy map is market-neutral; the active market,
        // selected date, audience and currency are NEVER consumed by this cache.
        // Conditional metadata may only be shared when its context is proven.
        $complete=($surface==='mode_policy_definition')
            ||($surface==='pricing_source_definition'
                && ($ctx['tour_mode']==='' || self::mode_if_known($ctx)))
            ||($surface==='financial_rule_definition' && $ctx['tour_id']>0 && self::mode_if_known($ctx))
            ||($surface==='tour_public_description' && $ctx['tour_id']>0)
            ||($surface==='elementor_layout_blueprint' && $ctx['tour_id']>0);
        $safe=($class==='static'||($class==='conditional'&&$complete))
            && $settings['mode']==='auto';
        // Rate schedules are never blindly shared: their season/availability
        // eligibility is certified only by the original pricing engine.
        if ($surface==='rate_schedule_definition')$safe=false;
        $ttl=$safe?min(2400,max(0,(int)$settings[
            $class==='static'?'static_ttl':($profile['kind']==='policy_blueprint'?'policy_ttl':'conditional_ttl')
        ])):0;
        if ($ttl===0)$safe=false;
        $snapshot=[
            'contract'=>'mad4b.pricing-cache.decision.v1',
            'resource'=>$surface,'classification'=>$class,
            'resource_kind'=>$profile['kind'],'cacheable'=>$safe,
            'cache_kind'=>$safe?'tenant_scoped_policy_or_metadata_only':'request_only_no_shared_html',
            'persistent_cache_activated'=>$safe && in_array($surface,
                ['mode_policy_definition','pricing_source_definition'],true),
            'scope_explanation'=>$safe && !in_array($surface,
                ['mode_policy_definition','pricing_source_definition'],true)
                ?'eligible_policy_only_not_yet_connected_to_renderer'
                :($safe?'redis_normalized_pricing_policy_definitions':'native_recalculate_each_request'),
            'ttl_seconds'=>$ttl,
            'inheritance'=>$trace,
            'currency_calculation'=>'native_per_request',
            'quote_calculation'=>'native_per_request',
            'context_dimensions'=>$safe
                ?['tenant','environment','policy_revision','language','device','tour_mode','tour','rate','season','program']
                :[],
        ];
        self::$last[$surface]=$snapshot;
        return $snapshot;
    }

    private static function source_fingerprint(): ?string {
        // Saved policy definitions are part of the key: a changed admin option
        // cannot reuse an old result, including updates during the same request.
        $names=[
            'etg-dynamic-pricing-controls_shared_mode_policy_rows',
            'etg-dynamic-pricing-controls_shared_mode_policies_json',
            'etg-dynamic-pricing-controls_shared_price_source_mode_rows',
            'etg-dynamic-pricing-controls_shared_price_source_direct_weight',
            'etg-dynamic-pricing-controls_shared_price_source_rate_weight',
            'etg-dynamic-pricing-controls_shared_price_source_tie_break',
            'etg-dynamic-pricing-controls_shared_price_source_fallback',
            'etg-dynamic-pricing-controls_shared_price_source_visibility',
            'shared_product_pricing_policies',
            'mad4b_render_query_data_generation',
            'mad4b_company_policy_registry_v2',
            self::OPTION,
        ];
        $payload=['contract'=>self::VERSION,'site'=>get_option('home'),'blog'=>get_current_blog_id(),
            'environment'=>wp_get_environment_type(),
            'locale'=>(string)apply_filters('wpml_current_language',determine_locale()),
            'date'=>gmdate('Y-m-d')];
        foreach($names as $n)$payload[$n]=get_option($n,null);
        $encoded=wp_json_encode($payload);
        if (!is_string($encoded)||strlen($encoded)>150000)return null;
        return hash('sha256',$encoded);
    }
    public static function memoize_mode_policies(callable $build) {
        $allowed=wp_using_ext_object_cache() && !is_admin() && !wp_doing_ajax()
            && ($_SERVER['REQUEST_METHOD']??'GET')==='GET'
            && (!(defined('REST_REQUEST') && REST_REQUEST))
            && (!(defined('WP_CLI')&&WP_CLI) || (defined('MAD4B_PRICING_CACHE_QA')&&MAD4B_PRICING_CACHE_QA))
            && !has_filter('shared_product_mode_policy_defaults_v1')
            && !has_filter('shared_product_pricing_strategy_types')
            && !has_filter('shared_product_unit_glossary')
            && !has_filter('pre_option_etg-dynamic-pricing-controls_shared_mode_policy_rows')
            && !has_filter('option_etg-dynamic-pricing-controls_shared_mode_policy_rows');
        if (!$allowed) {
            ++self::$counters['bypass'];return $build();
        }
        $plan=self::decision('mode_policy_definition');
        if (!$plan['cacheable']) {
            ++self::$counters['bypass'];return $build();
        }
        $fingerprint=self::source_fingerprint();
        if (!$fingerprint) {++self::$counters['fail'];return $build();}
        $key='policy-mode-'.substr($fingerprint,0,48);
        $stored=wp_cache_get($key,self::GROUP);
        if (is_array($stored) && ($stored['contract']??'')===self::VERSION
            && ($stored['digest']??'')===$fingerprint && is_array($stored['value']??null)) {
            ++self::$counters['hit'];return $stored['value'];
        }
        ++self::$counters['miss'];
        $value=$build();
        if (is_wp_error($value)||!is_array($value)||count($value)>40)return $value;
        $serialized=wp_json_encode($value);
        if (!is_string($serialized)||strlen($serialized)>65536)return $value;
        foreach ($value as $mode=>$rule) {
            if(!is_string($mode)||!preg_match('/^[a-z][a-z0-9_]{1,50}$/D',$mode)
                || !is_array($rule) || empty($rule['strategy']))return $value;
        }
        if (wp_cache_set($key,['contract'=>self::VERSION,'digest'=>$fingerprint,'value'=>$value],self::GROUP,$plan['ttl_seconds']))
            ++self::$counters['stored'];
        return $value;
    }
    public static function memoize_source_policy(string $mode, callable $build) {
        $mode=sanitize_key($mode);
        $cache_allowed=wp_using_ext_object_cache() && !is_admin() && !wp_doing_ajax()
            && ($_SERVER['REQUEST_METHOD']??'GET')==='GET'
            && (!(defined('REST_REQUEST')&&REST_REQUEST))
            && (!(defined('WP_CLI')&&WP_CLI) || (defined('MAD4B_PRICING_CACHE_QA')&&MAD4B_PRICING_CACHE_QA))
            && !has_filter('pre_option_etg-dynamic-pricing-controls_shared_price_source_mode_rows')
            && !has_filter('option_etg-dynamic-pricing-controls_shared_price_source_mode_rows');
        if(!$cache_allowed){++self::$counters['bypass'];return $build();}
        $plan=self::decision('pricing_source_definition',['tour_mode'=>$mode]);
        if(!$plan['cacheable']){++self::$counters['bypass'];return $build();}
        $fingerprint=self::source_fingerprint();
        if(!$fingerprint){++self::$counters['fail'];return $build();}
        $digest=hash('sha256',$fingerprint.'|source|'.$mode);
        $key='policy-source-'.substr($digest,0,48);
        $stored=wp_cache_get($key,self::GROUP);
        if(is_array($stored)&&($stored['contract']??'')===self::VERSION
            && ($stored['digest']??'')===$digest && is_array($stored['value']??null)) {
            ++self::$counters['hit'];return $stored['value'];
        }
        ++self::$counters['miss'];
        $value=$build();
        if(is_wp_error($value)||!is_array($value))return $value;
        $expected=['direct_weight','rate_weight','tie_break','fallback','visibility'];
        if(array_diff(array_keys($value),$expected)||array_diff($expected,array_keys($value)))return $value;
        if(!in_array($value['tie_break'],['direct','rate'],true)
            ||!in_array($value['fallback'],['next_valid','strict'],true)
            ||!in_array($value['visibility'],['winner','both','none'],true))return $value;
        foreach(['direct_weight','rate_weight'] as $name)
            if(!is_numeric($value[$name])||(float)$value[$name]<0||(float)$value[$name]>1000)
                return $value;
        if(wp_cache_set($key,['contract'=>self::VERSION,'digest'=>$digest,'value'=>$value],
            self::GROUP,$plan['ttl_seconds']))++self::$counters['stored'];
        return $value;
    }
    public static function metrics(): array {
        return ['contract'=>'mad4b.pricing-cache.metrics.v1','counters'=>self::$counters,
            'recent_decisions'=>self::$last,'stores_quote_or_rate_amount'=>false];
    }
    public static function registry_provider(array $providers): array {
        $providers['pricing-cache-inheritance']=[
            'label'=>'Pricing Policy / Cache Inheritance (no cached quotes)',
            'policy_id'=>'pricing.cache.inheritance',
            'fields'=>[
                'mode'=>['type'=>'enum','options'=>['auto'=>'Auto: definitions only','disabled'=>'Disable shared blueprint cache','private_only'=>'Force request-only']],
                'static_ttl'=>['type'=>'int','min'=>0,'max'=>2400],
                'policy_ttl'=>['type'=>'int','min'=>0,'max'=>2400],
                'conditional_ttl'=>['type'=>'int','min'=>0,'max'=>2400],
            ],
            'allowed_scopes'=>['company','program','season','tour','rate'],
        ];
        return $providers;
    }
    public static function rest(): void {
        register_rest_route('mad4b/v1','/pricing-cache-inheritance',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'),
            'callback'=>static function($request) {
                $tour=absint($request->get_param('tour_id'));
                $rate=absint($request->get_param('rate_id'));
                $mode=sanitize_key((string)$request->get_param('tour_mode'));
                return rest_ensure_response(self::overview(['tour_id'=>$tour,'rate_id'=>$rate,'tour_mode'=>$mode]));
            },
        ]);
    }
    public static function overview(array $ctx=[]): array {
        $out=[];
        foreach (array_keys(self::CLASSIFICATION) as $name)$out[$name]=self::decision($name,$ctx);
        return ['contract'=>'mad4b.pricing-cache.inheritance.v1',
            'home'=>get_option('home'),'environment'=>wp_get_environment_type(),
            'selected_context'=>array_intersect_key(self::context($ctx),array_flip(['tour_id','rate_id','tour_mode','season_id','program_term_id','language'])),
            'resources'=>$out,'metrics'=>self::metrics(),
            'pricing_policy_admin'=>admin_url('admin.php?page=etg-dynamic-pricing-controls'),
            'cache_lifecycle_admin'=>admin_url('admin.php?page=mad4b-cache-lifecycle'),
            'cache_policy_admin'=>admin_url('admin.php?page=mad4b-pricing-cache-inheritance')];
    }
    public static function menu(): void {
        add_submenu_page('tools.php','Pricing Cache Inheritance','Pricing Cache Inheritance',
            'manage_options','mad4b-pricing-cache-inheritance',[self::class,'render']);
    }
    private static function allowed_mode(string $mode): bool {
        if(!preg_match('/^[a-z][a-z0-9_]{1,50}$/D',$mode))return false;
        $rows=get_option('etg-dynamic-pricing-controls_shared_mode_policy_rows',[]);
        if(!is_array($rows))return false;
        foreach($rows as $row)
            if(is_array($row)&&(string)($row['mode']??'')===$mode)return true;
        return false;
    }
    public static function validated_admin_change($raw) {
        if(!is_array($raw))return new WP_Error('cache_policy_post_invalid');
        if(!is_scalar($raw['scope']??null)||!is_scalar($raw['scope_id']??''))return new WP_Error('cache_policy_scope_invalid');
        $scope=sanitize_key((string)$raw['scope']);
        $id=trim((string)($raw['scope_id']??''));
        $overrides=[];
        foreach (['static_ttl','policy_ttl','conditional_ttl'] as $key) {
            if(!is_scalar($raw[$key]??''))return new WP_Error('cache_policy_ttl_type_invalid');
            $value=trim((string)($raw[$key]??''));
            if($value==='')continue;
            if(!ctype_digit($value)||(int)$value>2400)return new WP_Error('cache_policy_invalid_ttl');
            $overrides[$key]=(int)$value;
        }
        if(!is_scalar($raw['mode']??''))return new WP_Error('cache_policy_mode_type_invalid');
        $mode=(string)($raw['mode']??'');
        if($mode!=='' && $mode!=='inherit') {
            if(!in_array($mode,['auto','disabled','private_only'],true))
                return new WP_Error('cache_policy_invalid_mode');
            $overrides['mode']=$mode;
        }
        if($scope==='company')return ['path'=>['company'],'values'=>$overrides];
        if($scope==='mode') {
            if(!self::allowed_mode(sanitize_key($id))||sanitize_key($id)!==$id)
                return new WP_Error('cache_policy_unknown_tour_mode');
            return ['path'=>['modes',$id],'values'=>$overrides];
        }
        if(in_array($scope,['program','season'],true)) {
            $tax=$scope==='program'?'programs':'season';
            if(!ctype_digit($id)||!($term=get_term((int)$id,$tax))||is_wp_error($term))
                return new WP_Error('cache_policy_unknown_term');
            return ['path'=>['terms',$tax,(int)$id],'values'=>$overrides];
        }
        if(in_array($scope,['tour','rate'],true)) {
            $type=$scope==='tour'?'tours-and-activities':'tour-rates';
            if(!ctype_digit($id)||get_post_type((int)$id)!==$type)
                return new WP_Error('cache_policy_unknown_product');
            return ['path'=>[$scope.'s',(int)$id],'values'=>$overrides];
        }
        return new WP_Error('cache_policy_unsupported_scope');
    }
    public static function save_admin(): void {
        if(!current_user_can('manage_options'))wp_die('Insufficient permissions','Forbidden',['response'=>403]);
        check_admin_referer('mad4b_pricing_cache_inheritance_save');
        $raw=wp_unslash($_POST);
        $change=self::validated_admin_change($raw);
        $destination=admin_url('tools.php?page=mad4b-pricing-cache-inheritance');
        if(is_wp_error($change)){
            wp_safe_redirect(add_query_arg('pricing_cache_error',$change->get_error_code(),$destination));
            exit;
        }
        $config=self::read_overrides();
        $path=$change['path'];$cursor=&$config;
        for($i=0;$i<count($path)-1;$i++){
            $key=$path[$i];
            if(!isset($cursor[$key])||!is_array($cursor[$key]))$cursor[$key]=[];
            $cursor=&$cursor[$key];
        }
        $key=$path[count($path)-1];
        if(!$change['values'])unset($cursor[$key]);
        else $cursor[$key]=$change['values'];
        unset($cursor);
        $encoded=wp_json_encode($config);
        if(!is_string($encoded)||strlen($encoded)>65536){
            wp_safe_redirect(add_query_arg('pricing_cache_error','config_size_exceeded',$destination));
            exit;
        }
        update_option(self::OPTION,$config,false);
        wp_safe_redirect(add_query_arg('pricing_cache_saved','1',$destination));
        exit;
    }
    public static function admin_notice():void {
        if(!current_user_can('manage_options'))return;
        $page=isset($_GET['page'])?sanitize_key((string)wp_unslash($_GET['page'])):'';
        if(!in_array($page,['etg-dynamic-pricing-controls','mad4b-cache-lifecycle'],true))return;
        echo '<div class="notice notice-info"><p>MAD4B Pricing Cache: policy definitions use scoped Redis caching; amounts, live availability and checkout remain native. ';
        echo '<a href="'.esc_url(admin_url('tools.php?page=mad4b-pricing-cache-inheritance')).'">View inherited cache policies</a></p></div>';
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        $ctx=['tour_id'=>isset($_GET['tour_id'])?absint($_GET['tour_id']):0,
            'rate_id'=>isset($_GET['rate_id'])?absint($_GET['rate_id']):0,
            'tour_mode'=>isset($_GET['tour_mode'])?sanitize_key((string)wp_unslash($_GET['tour_mode'])):''];
        $snapshot=self::overview($ctx);
        echo '<div class="wrap"><h1>Pricing Cache Inheritance</h1>';
        echo '<p>Policy definitions can reuse Redis blueprints; actual rates, availability and checkout are never shared.</p>';
        echo '<p><a href="'.esc_url($snapshot['pricing_policy_admin']).'">Pricing Policies</a> | ';
        echo '<a href="'.esc_url($snapshot['cache_lifecycle_admin']).'">Cache Lifecycle</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Resource</th><th>Class</th><th>Cache</th><th>TTL</th><th>Inheritance</th></tr></thead><tbody>';
        foreach ($snapshot['resources'] as $resource=>$info) {
            echo '<tr><td>'.esc_html($resource).'</td><td>'.esc_html($info['classification']).'</td>';
            echo '<td>'.esc_html($info['cacheable']?'Policy descriptor only':'Request only').'</td>';
            echo '<td>'.esc_html((string)$info['ttl_seconds']).'s</td><td>'.esc_html(implode(' → ',$info['inheritance'])).'</td></tr>';
        }
        echo '</tbody></table><p>Company → Mode → Program/Season → Tour → Rate → Active MAD4B registry policy.</p>';
        if(isset($_GET['pricing_cache_saved']))echo '<div class="notice notice-success inline"><p>Cache policy saved.</p></div>';
        if(isset($_GET['pricing_cache_error']))echo '<div class="notice notice-error inline"><p>Save rejected: '.esc_html(sanitize_key((string)wp_unslash($_GET['pricing_cache_error']))).'</p></div>';
        echo '<h2>Override inherited cache behaviour</h2>';
        echo '<p>These settings change only the reuse of policy definitions. They do not change prices, live rates, quotes or checkout calculations.</p>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
        wp_nonce_field('mad4b_pricing_cache_inheritance_save');
        echo '<input type="hidden" name="action" value="mad4b_pricing_cache_inheritance_save">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="mad4b-cache-scope">Scope</label></th><td><select id="mad4b-cache-scope" name="scope">';
        foreach(['company'=>'Company','mode'=>'Tour mode','program'=>'Program','season'=>'Season','tour'=>'Tour','rate'=>'Rate'] as $key=>$title)
            echo '<option value="'.esc_attr($key).'">'.esc_html($title).'</option>';
        echo '</select> <input type="text" name="scope_id" maxlength="65" placeholder="Mode name / term or post ID">';
        echo '<p class="description">Company scope needs no ID. Other scopes require an existing Tour mode, term or post ID.</p></td></tr>';
        foreach(['static_ttl'=>'Static TTL','policy_ttl'=>'Pricing definitions TTL','conditional_ttl'=>'Conditional content TTL'] as $key=>$label){
            echo '<tr><th scope="row"><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th>';
            echo '<td><input id="'.esc_attr($key).'" type="number" name="'.esc_attr($key).'" min="0" max="2400" placeholder="Inherit">';
            echo ' seconds (0 disables caching at this scope)</td></tr>';
        }
        echo '<tr><th scope="row">Cache decision</th><td><select name="mode">';
        foreach(['inherit'=>'Inherit','auto'=>'Auto (policy blueprints only)','disabled'=>'Disabled','private_only'=>'Request only'] as $key=>$text)
           echo '<option value="'.esc_attr($key).'">'.esc_html($text).'</option>';
        echo '</select></td></tr></tbody></table>';
        submit_button('Save scoped cache policy');
        echo '</form><h2>Saved company overrides</h2>';
        $saved=self::read_overrides();
        echo '<pre>'.esc_html(wp_json_encode($saved,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
        echo '</div>';
    }
    private static array $pending_change_events=[];

    private static function pricing_registry_rules($raw): array {
        $result=[];
        foreach ((array)($raw['rules']??[]) as $row) {
            if (!is_array($row))continue;
            $id=(string)($row['policy_id']??'');
            if (str_starts_with($id,'pricing.'))$result[]=$row;
        }
        return $result;
    }
    public static function policy_option_saved($name,$old=null,$new=null): void {
        $name=(string)$name;
        if (!str_starts_with($name,'etg-dynamic-pricing-controls_')
            && !in_array($name,[
              self::OPTION,'mad4b_company_component_pricing_defaults_v1',
              'booking_experience_market_price_rules_v1','shared_product_pricing_policies',
              'shared_product_payment_offer_rules','_wcml_settings','woocommerce_currency',
              'mad4b_company_policy_registry_v2'
            ],true))return;
        if ($old!==null && $new!==null && $old===$new)return;
        if ($name==='mad4b_company_policy_registry_v2' && is_array($old)&&is_array($new)
            && self::pricing_registry_rules($old)===self::pricing_registry_rules($new))return;
        self::$pending_change_events['pricing_policy_option']=true;
    }
    public static function policy_option_added($name,$value): void {
        self::policy_option_saved($name,null,$value);
    }
    public static function policy_option_deleted($name): void {
        self::policy_option_saved($name,null,null);
    }
    public static function pricing_meta_changed($meta_id,$post_id,$key): void {
        $type=get_post_type((int)$post_id);
        if (!in_array($type,['tours-and-activities','tour-rates','properties','property-rates','transport-rates'],true))
            return;
        if (!preg_match('/(?:price|rate|amount|date|season|occupancy|available|availab|stop_sale|discount|market|currency|program|package|group|adult|child|infant|tax|tour_mode)/i',(string)$key))
            return;
        self::$pending_change_events['priced_product_meta']=true;
    }
    public static function priced_post_changed($post_id,$post=null,$update=false): void {
        if (wp_is_post_revision((int)$post_id)||wp_is_post_autosave((int)$post_id))return;
        if (!in_array(get_post_type((int)$post_id),[
            'tours-and-activities','tour-rates','properties','property-rates','transport-rates'
        ],true))return;
        self::$pending_change_events['priced_product_save']=true;
    }
    public static function pricing_term_changed($term_id,$tt_id=0,$taxonomy=''): void {
        if (in_array((string)$taxonomy,['season','programs','package_levels','tour-types',
            'destinations','duration-options'],true))
            self::$pending_change_events['pricing_taxonomy']=true;
    }
    public static function flush_invalidation_signal(): void {
        if (!self::$pending_change_events)return;
        $path=WP_CONTENT_DIR.'/.mad4b-pricing-policy-change.signal';
        if (is_link($path)||(file_exists($path)&&!is_file($path)))return;
        $event=['contract'=>'mad4b.pricing-cache-policy-change.v1',
            'event_id'=>wp_generate_uuid4(),
            'scope'=>'published_public_price_surfaces',
            'reason_classes'=>array_keys(self::$pending_change_events),
            'created_utc'=>gmdate('c')];
        // No pricing data, customer identity or page content leaves WordPress.
        $encoded=wp_json_encode($event,JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded)>2048)return;
        if (@file_put_contents($path,$encoded."\n",LOCK_EX)===false) {
            error_log('MAD4B_PRICING_POLICY_INVALIDATION_SIGNAL_FAILED');
            return;
        }
        @chmod($path,0600);
        self::$pending_change_events=[];
    }
    public static function boot(): void {
        add_filter('mad4b/policy_registry/providers',[self::class,'registry_provider'],30);
        add_action('rest_api_init',[self::class,'rest'],20);
        add_action('admin_menu',[self::class,'menu'],60);
        add_action('admin_notices',[self::class,'admin_notice'],20);
        add_action('admin_post_mad4b_pricing_cache_inheritance_save',[self::class,'save_admin']);
        add_action('updated_option',[self::class,'policy_option_saved'],35,3);
        add_action('added_option',[self::class,'policy_option_added'],35,2);
        add_action('deleted_option',[self::class,'policy_option_deleted'],35,1);
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
            add_action($hook,[self::class,'pricing_meta_changed'],35,3);
        add_action('save_post',[self::class,'priced_post_changed'],35,3);
        foreach (['created_term','edited_term','delete_term'] as $hook)
            add_action($hook,[self::class,'pricing_term_changed'],35,3);
        add_action('shutdown',[self::class,'flush_invalidation_signal'],PHP_INT_MAX);
    }
}
MAD4B_Pricing_Cache_Inheritance_V1::boot();
}
