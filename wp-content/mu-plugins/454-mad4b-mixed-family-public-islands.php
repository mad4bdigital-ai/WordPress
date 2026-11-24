<?php
/**
 * Plugin Name: MAD4B Mixed Elementor Family — Public Island Compiler
 * Description: Splits approved public Tour containers from live Rate/Form/booking siblings without modifying Elementor templates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Mixed_Family_Public_Islands_V1 {
    private const POLICY = 'mad4b_mixed_family_public_islands_v1';
    private const MODE = 'mad4b_mixed_family_public_islands_mode_v1';
    private const CONTRACT = 'mad4b.mixed-elementor-family-public-island.v1';
    private const GROUP = 'mad4b-mixed-family-public-v1';
    private static int $tour = 0;
    private static ?array $manifest = null;
    private static array $roots = [];
    private static array $receipts = [];

    public static function mode(): string {
        $mode=(string)get_option(self::MODE,'off');
        if ($mode==='on')return 'on';
        return $mode==='canary' && ($_SERVER['HTTP_X_MAD4B_MIXED_FAMILY_CANARY']??'')==='mixed-20261124'
            ? 'canary' : 'off';
    }
    public static function config(): array {
        if (self::$manifest!==null)return self::$manifest;
        $raw=get_option(self::POLICY,[]);
        if (!is_array($raw) || ($raw['contract']??'')!==self::CONTRACT
            || ($raw['host']??'')!==wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($raw['environment']??'')!==wp_get_environment_type()
            || ($raw['status']??'')!=='approved'
            || !is_array($raw['roots']??null)
            || count($raw['roots'])>12)return self::$manifest=[];
        self::$roots=[];
        foreach ($raw['roots'] as $root) {
            if (!is_array($root)
                || !preg_match('/^[1-9][0-9]{3,7}$/D',(string)($root['family']??''))
                || !preg_match('/^[a-f0-9]{7}$/D',(string)($root['id']??''))
                || !preg_match('/^[a-f0-9]{64}$/D',(string)($root['family_sha256']??''))
                || !preg_match('/^[a-f0-9]{64}$/D',(string)($root['root_sha256']??''))
                || ($root['type']??'')!=='public_tour_inclusion'
                || !is_array($root['listings']??null)
                || count($root['listings'])>12)return self::$manifest=[];
            $key=$root['family'].'|'.$root['id'];
            if(isset(self::$roots[$key]))return self::$manifest=[];
            self::$roots[$key]=$root;
        }
        return self::$manifest=$raw;
    }
    public static function capture(): void {
        self::$tour=0;
        if(self::mode()==='off'
            || !class_exists('MAD4B_Compiled_Tour_Family_V1',false)
            || !MAD4B_Compiled_Tour_Family_V1::eligible()
            || !self::config())return;
        $id=absint(get_queried_object_id());
        if ($id>0 && is_singular('tours-and-activities'))self::$tour=$id;
    }
    public static function register_container(): void {
        if(!self::$tour || is_admin() || is_user_logged_in()
            || (defined('WP_CLI')&&WP_CLI)
            || (defined('REST_REQUEST')&&REST_REQUEST)
            || !class_exists('\\Elementor\\Plugin',false))return;
        $manager=\Elementor\Plugin::$instance->elements_manager??null;
        if(!is_object($manager)
            || !method_exists($manager,'get_element_types')
            || !method_exists($manager,'register_element_type'))return;
        $original=$manager->get_element_types('container');
        if(!is_object($original))return;
        if($original instanceof MAD4B_Mixed_Family_Container_V1)return;
        $file=__DIR__.'/includes/mad4b-mixed-family-container-v1.php';
        if (!is_readable($file) || is_link($file))return;
        require_once $file;
        if(!class_exists('MAD4B_Mixed_Family_Container_V1',false))return;
        $manager->register_element_type(new MAD4B_Mixed_Family_Container_V1());
    }
    public static function tree_find(array $nodes,string $wanted): ?array {
        foreach($nodes as $node){
            if(!is_array($node))return null;
            if(($node['id']??'')===$wanted)return $node;
            if(is_array($node['elements']??null)) {
                $match=self::tree_find($node['elements'],$wanted);
                if($match!==null)return $match;
            }
        }
        return null;
    }
    public static function qualify($node): ?array {
        if (!self::$tour || !is_object($node) || !method_exists($node,'get_id')
            || !class_exists('\\Elementor\\Plugin',false))return null;
        $document=\Elementor\Plugin::$instance->documents->get_current();
        if(!is_object($document)||!method_exists($document,'get_id'))return null;
        $family=(int)$document->get_id();
        $id=(string)$node->get_id();
        $root=self::$roots[$family.'|'.$id]??null;
        if(!is_array($root))return null;
        $raw=get_post_meta($family,'_elementor_data',true);
        if (!is_string($raw) || !hash_equals($root['family_sha256'],hash('sha256',$raw)))
            return null;
        $nodes=json_decode($raw,true);
        if(!is_array($nodes))return null;
        $sub=self::tree_find($nodes,$id);
        if (!is_array($sub)||($sub['elType']??'')!=='container')return null;
        $hash=hash('sha256',(string)wp_json_encode($sub,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (!hash_equals($root['root_sha256'],$hash)
            || !self::public_structure($sub,$root))return null;
        return $root;
    }

    public static function public_structure(array $node,array $root): bool {
        $allowed=['container'=>true,'heading'=>true,'icon-box'=>true,'jet-listing-grid'=>true];
        $ids=[];
        $count=0;
        $walk=static function($nodes)use(&$walk,&$ids,&$count,$allowed):bool{
            foreach((array)$nodes as $n) {
                if (!is_array($n)||++$count>90)return false;
                $t=(string)($n['widgetType']??$n['elType']??'');
                if (!isset($allowed[$t]))return false;
                if ($t==='jet-listing-grid'){
                    $id=absint($n['settings']['lisitng_id']??0);
                    if(!$id||get_post_status($id)!=='publish'
                        || get_post_type($id)!=='jet-engine')return false;
                    $listing=get_post_meta($id,'_listing_data',true);
                    if(!is_array($listing)
                        || !in_array((string)($listing['source']??''),['posts','repeater'],true)
                        || ($listing['post_type']??'')!=='tours-and-activities')return false;
                    $ids[$id]=true;
                }
                $children=$n['elements']??[];
                if(!is_array($children)||!$walk($children))return false;
            }
            return true;
        };
        if(!$walk([$node]))return false;
        $expected=array_map('absint',(array)$root['listings']);
        $actual=array_keys($ids);
        sort($expected);sort($actual);
        return $actual===$expected && count($actual)>=1;
    }

    private static function fingerprint(array $root): string {
        $tour=self::$tour;
        $post=get_post($tour);
        if(!($post instanceof WP_Post)||$post->post_status!=='publish')return '';
        $m=function_exists('mad4b_render_query_market_identity')?mad4b_render_query_market_identity():[];
        if (!is_array($m)|| !isset($m['currency'])
            || (string)$m['currency']!==(string)get_woocommerce_currency())return '';
        $lang=(string)apply_filters('wpml_current_language','');
        if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD',$lang))return '';
        $terms=wp_get_object_terms($tour,'destinations');
        if(is_wp_error($terms))return '';
        $termsData=[];
        foreach((array)$terms as $t)if($t instanceof WP_Term) {
            $termsData[]=[
                $t->term_id,$t->slug,$t->name,$t->description,$t->count,
                hash('sha256',maybe_serialize(get_term_meta($t->term_id)))
            ];
        }
        $listingSha=[];
        foreach($root['listings'] as $listing){
            $listing=absint($listing);
            $s=get_post_meta($listing,'_elementor_data',true);
            if(!is_string($s)||get_post_status($listing)!=='publish')return '';
            $listingSha[$listing]=hash('sha256',$s.'|'.maybe_serialize(get_post_meta($listing,'_listing_data',true)));
        }
        $deps=[
            'v'=>2,'site'=>home_url('/'),
            'route'=>wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH),
            'tour'=>$tour,'modified'=>$post->post_modified_gmt,
            'meta'=>hash('sha256',maybe_serialize(get_post_meta($tour))),
            'terms'=>$termsData,
            'lang'=>$lang,'locale'=>get_locale(),'market'=>$m,
            'woocommerce_currency'=>get_woocommerce_currency(),
            'country_cookie'=>hash('sha256',(string)($_SERVER['HTTP_COOKIE']??'')),
            'ua'=>hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??'')),
            'accept_lang'=>substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??''),0,128),
            'mobile'=>wp_is_mobile(),
            'root_sha'=>$root['root_sha256'],'family_sha'=>$root['family_sha256'],
            'listings'=>$listingSha,
            'posts_epoch'=>(string)wp_cache_get_last_changed('posts'),
            'terms_epoch'=>(string)wp_cache_get_last_changed('terms'),
            'termmeta_epoch'=>(string)get_option('mad4b_compiled_termmeta_epoch_v1','0'),
            'generation'=>(string)get_option('mad4b_render_query_data_generation','initial')
        ];
        $json=wp_json_encode($deps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?hash('sha256',$json):'';
    }
    public static function safe_html($html): bool {
        if(!is_string($html)||strlen($html)<400||strlen($html)>350000)return false;
        // Verify native Elementor presentation, without text-based keyword
        // censorship: only actual commerce DOM/state attributes are rejected.
        if(!str_contains($html,'jet-listing-grid'))return false;
        if (preg_match('~<\\s*(?:script|form|input|iframe|textarea|select|object|embed)\\b~i',$html))return false;
        if (preg_match('~\\bdata-(?:nonce|price|rate|checkout|payment|booking|cart|session|user|customer|token|order|quote)[-_a-z0-9]*\\s*=~i',$html)
          || preg_match('~\\bon[a-z]{3,25}\\s*=~i',$html)
          || preg_match('~\\b(?:_wpnonce|auth_token|access_token|session_id|client_secret|order_key)\\s*=~i',$html))return false;
        if (preg_match('~\\bclass\\s*=\\s*["\\\'][^"\\\']{0,400}\\b(?:fluentform|woocommerce-Price-amount|woocommerce-cart|checkout-form|booking-form|rate-calculator|payment-form|order-total)(?:\\b|[-_])~i',$html))return false;
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return !preg_match('~(?:[$€£]\\s*\\d{1,3}(?:[.,]\\d{3})*|\\b(?:USD|EUR|GBP|EGP)\\s*\\d+(?:[.,]\\d+)?|\\d+(?:[.,]\\d+)?\\s*(?:USD|EUR|GBP|EGP)\\b)~iu',$text);
    }
    private static function receipt($event,$key,$ms,$length):void {
        if (($_SERVER['HTTP_X_MAD4B_MIXED_FAMILY_RECEIPT']??'')!=='mixed-receipt-20261124')return;
        self::$receipts[]=['event'=>$event,'root'=>'409e1bc','tour'=>self::$tour,
            'key'=>substr($key,0,16),'ms'=>round($ms,2),'bytes'=>$length];
    }
    public static function render($node, callable $native):string {
        $root=self::qualify($node);
        if(!$root)return $native();
        $key=self::fingerprint($root);
        if($key==='')return $native();
        $t=hrtime(true);
        $entry=(self::mode()==='canary'
            && ($_SERVER['HTTP_X_MAD4B_MIXED_FAMILY_FORCE_NATIVE']??'')==='yes')
            ?false:wp_cache_get('island_'.$key,self::GROUP);
        if(is_array($entry) && is_string($entry['html']??null)
            && is_string($entry['sha']??null)
            && hash_equals($entry['sha'],hash('sha256',$entry['html']))
            && self::safe_html($entry['html'])
            && is_array($entry['scripts']??null)
            && is_array($entry['styles']??null)){
            // A missing handle triggers native render; never replay a partial
            // asset set or change the live form/rate siblings.
            $js=$entry['scripts'];$css=$entry['styles'];
            foreach($js as $h)if(!is_string($h)||strlen($h)>100||!wp_script_is($h,'registered'))return $native();
            foreach($css as $h)if(!is_string($h)||strlen($h)>100||!wp_style_is($h,'registered'))return $native();
            foreach($css as $h)wp_enqueue_style($h);
            foreach($js as $h)wp_enqueue_script($h);
            self::receipt('HIT',$key,(hrtime(true)-$t)/1e6,strlen($entry['html']));
            return $entry['html'];
        }
        $jsBefore=wp_scripts()->queue;
        $cssBefore=wp_styles()->queue;
        $html=$native();
        if(!self::safe_html($html)){
            self::receipt('NATIVE_DYNAMIC',$key,(hrtime(true)-$t)/1e6,strlen($html));
            return $html;
        }
        $scripts=array_values(array_diff(wp_scripts()->queue,$jsBefore));
        $styles=array_values(array_diff(wp_styles()->queue,$cssBefore));
        if(count($scripts)>32||count($styles)>48)return $html;
        $data=['html'=>$html,'sha'=>hash('sha256',$html),'scripts'=>$scripts,'styles'=>$styles];
        $stored=wp_cache_set('island_'.$key,$data,self::GROUP,120);
        self::receipt($stored?'MISS_STORED':'MISS_WRITE_FAILED',$key,(hrtime(true)-$t)/1e6,strlen($html));
        return $html;
    }
    public static function shutdown():void {
        if(!self::$receipts || ($_SERVER['HTTP_X_MAD4B_MIXED_FAMILY_RECEIPT']??'')!=='mixed-receipt-20261124')return;
        @file_put_contents('/tmp/mad4b-mixed-family-receipts-20261124.jsonl',
            wp_json_encode(self::$receipts).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
}
add_action('wp',['MAD4B_Mixed_Family_Public_Islands_V1','capture'],120);
add_action('wp',['MAD4B_Mixed_Family_Public_Islands_V1','register_container'],180);
add_action('shutdown',['MAD4B_Mixed_Family_Public_Islands_V1','shutdown'],999);
