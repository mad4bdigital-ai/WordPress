<?php
/**
 * Plugin Name: MAD4B Compiled Public Tour Family
 * Description: Exact-identity, page-specific public Elementor template snapshots with immutable family admission and native commerce.
 * Version: 0.2.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Compiled_Tour_Family_V1 {
    private const OPTION = 'mad4b_compiled_tour_family_v1';
    private const GROUP = 'mad4b-compiled-tour-family-v1';
    private const CONTRACT = 'mad4b.template-family-public-snapshot.v1';
    private static int $tour = 0;
    private static array $receipt = [];
    private static ?array $config = null;

    public static function capture(): void {
        self::$tour=0;
        if (!is_singular('tours-and-activities') || !empty($_SERVER['QUERY_STRING'])
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET' || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview() || (defined('REST_REQUEST')&&REST_REQUEST)) return;
        $id=absint(get_queried_object_id());
        $post=$id?get_post($id):null;
        if (!($post instanceof WP_Post) || $post->post_status!=='publish'
            || $post->post_type!=='tours-and-activities'
            || !is_post_publicly_viewable($post) || post_password_required($post)) return;
        $canonical=(string)wp_parse_url(get_permalink($post),PHP_URL_PATH);
        $actual=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if ($canonical!=='' && $canonical===$actual)self::$tour=$id;
    }

    public static function config(): array {
        if (self::$config!==null)return self::$config;
        $cfg=get_option(self::OPTION,[]);
        if (!is_array($cfg) || ($cfg['contract']??'')!==self::CONTRACT
            || ($cfg['site_host']??'')!==wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($cfg['status']??'')!=='approved'
            || !in_array((string)($cfg['environment']??''),['staging','production'],true)
            || ($cfg['environment']??'')!==wp_get_environment_type())return self::$config=[];
        foreach (['family_document','widget_id','content_document','family_sha256','content_sha256'] as $key)
            if (!isset($cfg[$key]))return self::$config=[];
        if (!preg_match('/^[0-9]{1,8}$/D',(string)$cfg['family_document'])
            || !preg_match('/^[a-f0-9]{7}$/D',(string)$cfg['widget_id'])
            || !preg_match('/^[0-9]{1,8}$/D',(string)$cfg['content_document'])
            || !preg_match('/^[a-f0-9]{64}$/D',(string)$cfg['family_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',(string)$cfg['content_sha256']))return self::$config=[];
        return self::$config=$cfg;
    }

    public static function mode(): string {
        $mode=(string)get_option('mad4b_compiled_tour_family_mode_v1','off');
        if ($mode==='on')return 'on';
        return $mode==='canary'
            && in_array(wp_get_environment_type(),['staging','production'],true)
            && ($_SERVER['HTTP_X_MAD4B_COMPILED_TOUR_CANARY']??'')==='family-20261124'
            ? 'canary' : 'off';
    }

    public static function eligible(): bool {
        if (!self::$tour || self::mode()==='off' || !self::config()
            || is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST')&&REST_REQUEST)
            || (defined('WP_CLI')&&WP_CLI)
            || !empty($_SERVER['QUERY_STRING'])
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET'
            || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound())return false;
        if (function_exists('mad4b_render_query_has_untrusted_market_header')
            && mad4b_render_query_has_untrusted_market_header())return false;
        foreach ($_SERVER as $key=>$value) {
            if (is_string($key) && is_scalar($value) && (string)$value!==''
                && preg_match('/^HTTP_X_(?:MAD4B_(?:COUNTRY|CURRENCY|PASSPORT|MARKET)|GEO|COUNTRY_CODE|FORWARDED_GEO|WP_NONCE)/',$key))
                return false;
        }
        $lang=(string)apply_filters('wpml_current_language','');
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD',$lang))return false;
        $wire=(string)($_SERVER['HTTP_COOKIE']??'');
        if ($wire!=='') {
            if (strlen($wire)>160 || str_contains($wire,';'))return false;
            $parts=explode('=',trim($wire),2);
            if (count($parts)!==2 || $parts[0]!=='wp-wpml_current_language'
                || rawurldecode($parts[1])!==$lang)return false;
        }
        foreach ((array)$_COOKIE as $key=>$value)
            if ($key!=='wp-wpml_current_language'
                || !is_string($value) || $value!==$lang)return false;
        if (function_exists('is_checkout') && (is_checkout()||is_cart()||is_account_page()))return false;
        $family=(int)self::config()['family_document'];
        $raw=get_post_meta($family,'_elementor_data',true);
        if (!is_string($raw) || !hash_equals((string)self::config()['family_sha256'],hash('sha256',$raw)))
            return false;
        $child=(int)self::config()['content_document'];
        $contents=get_post_meta($child,'_elementor_data',true);
        if (!is_string($contents) || !hash_equals((string)self::config()['content_sha256'],hash('sha256',$contents))
            || get_post_status($child)!=='publish'
            || !self::public_template_structure($contents))
            return false;
        $needle='"id":"'.self::config()['widget_id'].'"';
        return str_contains($raw,$needle)
            && str_contains($raw,'"template_id":"'.$child.'"');
    }

    private static function market(): array {
        if (!function_exists('mad4b_render_query_market_identity')
            || !function_exists('get_woocommerce_currency'))return [];
        $m=mad4b_render_query_market_identity();
        if (!is_array($m) || !preg_match('/^[A-Z]{3}$/D',(string)($m['currency']??''))
            || (string)$m['currency']!==(string)get_woocommerce_currency())return [];
        $geo='WCML\\MultiCurrency\\Geolocation';
        $country=(string)($m['country']??'');
        if ($country==='base' && class_exists($geo) && method_exists($geo,'getUserCountry'))
            $country=strtoupper((string)$geo::getUserCountry());
        if (!preg_match('/^[A-Z]{2}$/D',$country))return [];
        return ['country'=>$country,'currency'=>$m['currency']];
    }

    private static function fingerprint(int $template): string {
        $market=self::market();
        if (!$market)return '';
        $post=get_post(self::$tour);
        if (!($post instanceof WP_Post))return '';
        $terms=wp_get_object_terms(self::$tour,'destinations');
        if (is_wp_error($terms))return '';
        $termRows=[];
        foreach ((array)$terms as $term) {
            if (!($term instanceof WP_Term))continue;
            $termRows[]=[
                (int)$term->term_id,$term->slug,$term->name,
                $term->description,$term->parent,$term->count,
                get_term_meta((int)$term->term_id),
            ];
        }
        // Compile per tour + language + verified country/currency, never a
        // shared document across tours. Source and content revisions qualify
        // the template family; post meta/terms invalidate only this tour.
        $family=(int)self::config()['family_document'];
        $deps=[
            'contract'=>self::CONTRACT,'site'=>home_url('/'),
            'route'=>wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH),
            'tour'=>self::$tour,'post_modified'=>$post->post_modified_gmt,
            'post_meta'=>hash('sha256',maybe_serialize(get_post_meta(self::$tour))),
            'terms'=>$termRows,
            'lang'=>(string)apply_filters('wpml_current_language',''),
            'locale'=>get_locale(),'market'=>$market,
            'mobile'=>wp_is_mobile(),
            'ua_digest'=>hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??'')),
            'accept_language'=>substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??''),0,128),
            'family'=>$family,'family_sha'=>self::config()['family_sha256'],
            'content_template'=>$template,'content_sha'=>self::config()['content_sha256'],
            'css'=>hash('sha256',maybe_serialize(get_post_meta($template,'_elementor_css',true))),
            'elementor_kit'=>(int)get_option('elementor_active_kit'),
            'terms_epoch'=>(string)wp_cache_get_last_changed('terms'),
            // Public child lists are term-backed. A global posts last-changed
            // epoch would invalidate every Tour on unrelated admin/content
            // writes. We already key the actual Tour by post modified+meta.
            'term_meta_epoch'=>(string)get_option('mad4b_compiled_termmeta_epoch_v1','0'),
            'generation'=>(string)get_option('mad4b_render_query_data_generation','initial')
        ];
        // Include nested listing revisions and style digests. A taxonomy
        // listing/template edit never shares previously compiled HTML.
        $raw=get_post_meta($template,'_elementor_data',true);
        if (!is_string($raw))return '';
        preg_match_all('/"lisitng_id":"([1-9][0-9]{0,7})"/',$raw,$match);
        $listingIds=array_values(array_unique(array_map('intval',$match[1]??[])));
        if (count($listingIds)>20)return '';
        foreach($listingIds as $id) {
            $s=get_post_meta($id,'_elementor_data',true);
            if (!is_string($s) || get_post_status($id)!=='publish')return '';
            $deps['listing_'.$id]=[hash('sha256',$s),
                hash('sha256',maybe_serialize(get_post_meta($id,'_elementor_css',true)))];
        }
        $encoded=wp_json_encode($deps,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($encoded)?hash('sha256',$encoded):'';
    }

    public static function eligible_widget($widget,$templateId): bool {
        return self::eligible() && method_exists($widget,'get_id')
            && (string)$widget->get_id()===(string)self::config()['widget_id']
            && (int)$templateId===(int)self::config()['content_document'];
    }

    public static function replay_or_native($widget,callable $render,int $templateId): string {
        if (!self::eligible_widget($widget,$templateId))return $render();
        $key=self::fingerprint($templateId);
        if ($key==='')return $render();
        $control=($_SERVER['HTTP_X_MAD4B_COMPILED_TOUR_CONTROL']??'')==='native-v1';
        $started=hrtime(true);
        $entry=$control?false:wp_cache_get('fragment_'.$key,self::GROUP);
        if (is_array($entry) && isset($entry['html'],$entry['sha'],$entry['scripts'],$entry['styles'])
            && is_string($entry['html'])
            && strlen($entry['html'])>5000 && strlen($entry['html'])<300000
            && hash_equals((string)$entry['sha'],hash('sha256',$entry['html']))
            && self::safe_html($entry['html'])
            && is_array($entry['scripts']) && is_array($entry['styles'])) {
            // Elementor's document CSS normally is enqueued by the nested
            // renderer. Re-enqueue captured handles only; if CSS is not
            // registered, its post-CSS must be explicitly prepared.
            if (class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
                $css=\Elementor\Core\Files\CSS\Post::create($templateId);
                if ($css)$css->enqueue();
            }
            foreach($entry['styles'] as $name) {
                if (!is_string($name) || strlen($name)>100
                    || !wp_style_is($name,'registered'))return $render();
                wp_enqueue_style($name);
            }
            foreach($entry['scripts'] as $name) {
                if (!is_string($name) || strlen($name)>100
                    || !wp_script_is($name,'registered'))return $render();
                wp_enqueue_script($name);
            }
            self::receipt('HIT',$key,$started,strlen($entry['html']));
            return $entry['html'];
        }
        $beforeJS=wp_scripts()->queue;
        $beforeCSS=wp_styles()->queue;
        $html=$render();
        $classification=self::classify_html($html);
        if ($classification['kind']!=='public_snapshot') {
            // Native output is still displayed. Only the public snapshot
            // optimization is skipped for this one component.
            self::receipt('NATIVE_'.$classification['reason'],$key,$started,strlen($html));
            return $html;
        }
        $newJS=array_values(array_diff(wp_scripts()->queue,$beforeJS));
        $newCSS=array_values(array_diff(wp_styles()->queue,$beforeCSS));
        if (count($newJS)>32 || count($newCSS)>48) {
            self::receipt('MISS_NATIVE_ASSETS',$key,$started,strlen($html));
            return $html;
        }
        $ttl=120;
        $entry=['html'=>$html,'sha'=>hash('sha256',$html),
            'styles'=>$newCSS,'scripts'=>$newJS];
        $stored=wp_cache_set('fragment_'.$key,$entry,self::GROUP,$ttl);
        self::receipt($stored?'MISS_STORED':'MISS_WRITE_FAILED',$key,$started,strlen($html));
        if(class_exists('MAD4B_Page_Dependency_Cache',false)) {
            MAD4B_Page_Dependency_Cache::depend('template:'.(int)self::config()['family_document']);
            MAD4B_Page_Dependency_Cache::depend('template:'.$templateId);
            MAD4B_Page_Dependency_Cache::depend('post:'.self::$tour);
        }
        return $html;
    }

    /**
     * Template metadata is approved by an exact content SHA, but eligibility
     * must also be based on actual Elementor widget taxonomy, not matching
     * words in public copy such as "price", "booking" or "payment".
     * This first family intentionally supports only public terms listings.
     */
    public static function public_template_structure($source): bool {
        if (!is_string($source) || strlen($source)>300000)return false;
        $elements=json_decode($source,true);
        if (!is_array($elements) || !$elements)return false;
        $allowedWidgets=[
            'heading'=>true,'text-editor'=>true,
            'jet-listing-dynamic-field'=>true,'jet-listing-grid'=>true
        ];
        $count=0;$publicListing=false;
        $walk=static function(array $nodes) use (&$walk,&$count,&$publicListing,$allowedWidgets): bool {
            foreach($nodes as $element) {
                if (!is_array($element) || ++$count>220)return false;
                $type=(string)($element['elType']??'');
                if (!in_array($type,['container','widget'],true))return false;
                if ($type==='widget') {
                    $name=(string)($element['widgetType']??'');
                    if (!isset($allowedWidgets[$name]))return false;
                    if ($name==='jet-listing-grid') {
                        $settings=$element['settings']??[];
                        if (!is_array($settings))return false;
                        $listing=absint($settings['lisitng_id']??0);
                        if (!$listing || get_post_status($listing)!=='publish'
                            || get_post_type($listing)!=='jet-engine')return false;
                        $model=get_post_meta($listing,'_listing_data',true);
                        if (!is_array($model) || ($model['source']??'')!=='terms'
                            || ($model['tax']??'')!=='destinations')return false;
                        $publicListing=true;
                    }
                }
                $children=$element['elements']??[];
                if (!is_array($children) || ($children && !$walk($children)))return false;
            }
            return true;
        };
        return $walk($elements) && $publicListing;
    }

    /**
     * A shared snapshot is accepted by STRUCTURE, not English keywords.
     * Commercial and stateful HTML remains in its original live Elementor
     * component. A single unsafe island does not block the rest of the
     * page or other separately approved public components.
     *
     * This does not automatically extract live fields from one mixed widget.
     * Such widgets remain native until certified into separate components.
     */
    public static function classify_html($html): array {
        if (!is_string($html) || strlen($html)<=5000 || strlen($html)>=300000)
            return ['kind'=>'native_unknown','reason'=>'invalid_size'];
        if (!str_contains($html,'elementor-template')
            || !str_contains($html,'jet-listing-dynamic-post-'))
            return ['kind'=>'native_unknown','reason'=>'missing_public_listing'];
        if (preg_match('~<\s*(?:script|form|input|iframe|textarea|select|object|embed)\b~i',$html))
            return ['kind'=>'native_dynamic','reason'=>'interactive_dom'];
        // Attribute names/structure are authoritative. Ordinary article
        // phrases (e.g. "payment terms" or "price guide") are not signals.
        if (preg_match('~\bdata-(?:nonce|price|rate|checkout|payment|booking|cart|session|user|customer|token|order|quote)(?:[-_a-z0-9]*)\s*=~i',$html)
            || preg_match('~\bon[a-z]{3,25}\s*=~i',$html)
            || preg_match('~\b(?:_wpnonce|wp_nonce|auth_token|access_token|session_id|client_secret|order_key)\s*=~i',$html))
            return ['kind'=>'native_dynamic','reason'=>'stateful_attribute'];
        if (preg_match('~\bclass\s*=\s*["\'][^"\']{0,400}\b(?:fluentform|woocommerce-Price-amount|woocommerce-cart|checkout-form|booking-form|rate-calculator|payment-form|order-total)(?:\b|[-_])~i',$html))
            return ['kind'=>'native_dynamic','reason'=>'commerce_component'];
        // Actual monetary amounts are live unless that price-bearing widget
        // is specifically certified for every date/occupancy/market context.
        // A standalone currency word or discussion about price is public.
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (preg_match('~(?:[$€£]\s*\d{1,3}(?:[.,]\d{3})*|\b(?:USD|EUR|GBP|EGP)\s*\d{1,}(?:[.,]\d+)?|\d{1,}(?:[.,]\d+)?\s*(?:USD|EUR|GBP|EGP)\b)~iu',$text))
            return ['kind'=>'native_dynamic','reason'=>'monetary_value'];
        return ['kind'=>'public_snapshot','reason'=>'approved_public_structure'];
    }

    public static function safe_html($html): bool {
        return self::classify_html($html)['kind']==='public_snapshot';
    }

    private static function receipt(string $event,string $key,int $started,int $bytes): void {
        if (($_SERVER['HTTP_X_MAD4B_COMPILED_RECEIPT']??'')!=='receipt-20261124')return;
        self::$receipt[]=[
            'event'=>$event,'time_ms'=>round((hrtime(true)-$started)/1e6,2),
            'bytes'=>$bytes,'digest'=>substr($key,0,16),'tour'=>self::$tour
        ];
    }
    public static function dump(): void {
        if (!self::$receipt
            || ($_SERVER['HTTP_X_MAD4B_COMPILED_RECEIPT']??'')!=='receipt-20261124')return;
        @file_put_contents('/tmp/mad4b-compiled-family-20261124.jsonl',
            wp_json_encode(['time'=>microtime(true),'events'=>self::$receipt]).PHP_EOL,
            FILE_APPEND|LOCK_EX);
    }

    /** Update only dependency revision, never globally flush Redis/CSS. */
    public static function changed_term_meta($metaId,$termId,$metaKey,$value): void {
        $termId=absint($termId);
        if (!$termId || !term_exists($termId,'destinations'))return;
        $next=(int)get_option('mad4b_compiled_termmeta_epoch_v1',0)+1;
        update_option('mad4b_compiled_termmeta_epoch_v1',(string)$next,false);
    }

    public static function register_widget($manager): void {
        // Elementor's widget registry is global. Swap its Template widget
        // only after capturing an audited anonymous public Tour route.
        if(!self::$tour || is_admin() || is_user_logged_in() || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('WP_CLI') && WP_CLI)
            || self::mode()==='off'
            || !class_exists('\\ElementorPro\\Modules\\Library\\Widgets\\Template')
            || !method_exists($manager,'unregister') || !method_exists($manager,'register'))return;
        $classFile=__DIR__.'/includes/mad4b-compiled-tour-template-widget-v1.php';
        if (!is_readable($classFile) || is_link($classFile))return;
        require_once $classFile;
        if (!class_exists('MAD4B_Compiled_Tour_Family_V1_Widget',false))return;
        $manager->unregister('template');
        $manager->register(new MAD4B_Compiled_Tour_Family_V1_Widget());
    }
}

add_action('wp',['MAD4B_Compiled_Tour_Family_V1','capture'],108);
add_action('added_term_meta',['MAD4B_Compiled_Tour_Family_V1','changed_term_meta'],10,4);
add_action('updated_term_meta',['MAD4B_Compiled_Tour_Family_V1','changed_term_meta'],10,4);
add_action('deleted_term_meta',['MAD4B_Compiled_Tour_Family_V1','changed_term_meta'],10,4);
add_action('elementor/widgets/register',['MAD4B_Compiled_Tour_Family_V1','register_widget'],999);
add_action('shutdown',['MAD4B_Compiled_Tour_Family_V1','dump'],999);
