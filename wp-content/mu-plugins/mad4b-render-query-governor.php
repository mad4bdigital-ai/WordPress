<?php
/**
 * Plugin Name: MAD4B Shared Renderer Query Governor
 * Description: Capability-aware, versioned JetEngine data-query caching, independent of tenant IDs and domains.
 * Version: 1.5.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A request-provided market or geo hint is NOT a trusted WCML runtime
 * binding. Refuse shared cache access if any such HTTP header is present,
 * including newly introduced header spellings from clients/proxies.
 * This semantic guard covers both Query Builder and public fragments.
 */
function mad4b_render_query_has_untrusted_market_header(): bool {
    foreach ($_SERVER as $name=>$value) {
        if (!is_string($name) || !str_starts_with($name,'HTTP_')
            || !is_scalar($value) || (string)$value==='')continue;
        if (preg_match('/(?:GEO|COUNTRY|CURRENCY|MARKET|PASSPORT)/i',$name))
            return true;
    }
    return false;
}

/**
 * A market-signed visitor may reuse safe JetEngine result objects without
 * reusing a full personalized HTML page. All unknown request dimensions fail
 * closed. This is deliberately independent of the anonymous FastCGI policy.
 */
function mad4b_render_query_signed_market_scope(): string {
    static $resolved=false, $scope='';
    if ($resolved) return $scope;
    $resolved=true;
    $mode=(string)get_option('mad4b_render_query_signed_market_v1','off');
    if (!in_array($mode,['canary','on'],true))return '';
    if ($mode==='canary'
        && (string)($_SERVER['HTTP_X_MAD4B_SIGNED_QUERY_CANARY']??'')!=='v1')return '';
    if (defined('WP_CLI') && WP_CLI)return '';
    if ((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST)!=='wayfatours.com'
        || !in_array((string)($_SERVER['REQUEST_URI']??''),['/','/es/'],true)
        || (string)($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
        || (string)($_SERVER['QUERY_STRING']??'')!==''
        || !is_front_page() || is_admin() || is_user_logged_in()
        || is_preview() || wp_doing_ajax()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || !wp_using_ext_object_cache()
        || !class_exists('MAD4B_Country_Switcher',false)
        || !class_exists('MAD4B_Public_Cookie_Cache_Contract_V1',false)
        || !class_exists('MAD4B_Experience_Site_Scope_V1',false)
        || !MAD4B_Experience_Site_Scope_V1::bound())return '';
    if (mad4b_render_query_has_untrusted_market_header())return '';
    foreach ([
        'HTTP_AUTHORIZATION','PHP_AUTH_USER','HTTP_X_WP_NONCE',
        'HTTP_X_REQUESTED_WITH','HTTP_X_MAD4B_BYPASS_CACHE',
        'HTTP_X_MAD4B_BY_PASS_CACHE','HTTP_CF_IPCOUNTRY',
        'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY',
        'HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT',
        'HTTP_X_COUNTRY_CODE','HTTP_X_GEOIP_COUNTRY'
    ] as $header)if (!empty($_SERVER[$header]))return '';
    foreach ($_SERVER as $header=>$val) {
        if (str_starts_with((string)$header,'HTTP_X_MAD4B_')
            && $header!=='HTTP_X_MAD4B_SIGNED_QUERY_CANARY'
            && is_scalar($val) && (string)$val!=='')return '';
    }
    if (function_exists('WC') && WC()->session
        && method_exists(WC()->session,'has_session')
        && WC()->session->has_session())return '';
    $language=(string)apply_filters('wpml_current_language','');
    $route=(string)($_SERVER['REQUEST_URI']??'');
    if (!in_array($language,['en','es'],true)
        || $language!==($route==='/es/'?'es':'en'))return '';
    $raw=(string)($_SERVER['HTTP_COOKIE']??'');
    if (strlen($raw)<35 || strlen($raw)>4096)return '';
    $marketing=[];$countryCount=0;$seen=[];
    foreach (explode(';',$raw) as $pair) {
        $pair=trim($pair);
        if ($pair==='' || !str_contains($pair,'='))return '';
        [$name,$val]=explode('=',$pair,2);
        if ($name==='' || isset($seen[$name]))return '';
        $seen[$name]=true;
        if ($name==='mad4b_country_selected_v1') {
            $countryCount++;
            if ($countryCount!==1 || $val==='' || strlen($val)>1500
                || !isset($_COOKIE[$name]) || !is_string($_COOKIE[$name])
                || !hash_equals((string)$_COOKIE[$name],rawurldecode($val)))return '';
        } else $marketing[]=$pair;
    }
    if ($countryCount!==1)return '';
    $safe=implode('; ',$marketing);
    if ($safe!=='' && !MAD4B_Public_Cookie_Cache_Contract_V1::safe_marketing_cookie($safe))return '';
    $country=(string)MAD4B_Country_Switcher::saved_country(); // native site-HMAC verified
    if (!preg_match('/^[A-Z]{2}$/D',$country))return '';
    $market=mad4b_render_query_market_identity(); // actual WCML identity, if enabled
    if (!$market || !preg_match('/^[A-Z]{3}$/D',(string)($market['currency']??''))
        || !preg_match('/^(?:base|[A-Z]{2})$/D',(string)($market['country']??'')))return '';
    $approved=MAD4B_Country_Switcher::resolve($country,$language);
    $profile=is_array($approved)?(string)($approved['profile_id']??''):'';
    if (strlen($profile)>128)return '';
    // Group per trusted market and currency, not IP or user-identifying cookie.
    // A new policy generation cannot reuse a legacy anonymous query group.
    $encoded=wp_json_encode([
        'v'=>'signed-market-v1','site'=>(int)get_current_blog_id(),
        'country'=>$country,'profile'=>$profile,
        'wcml_country'=>$market['country'],'currency'=>$market['currency'],
        'language'=>$language,'device'=>wp_is_mobile()?'mobile':'desktop'
    ]);
    if (!is_string($encoded))return '';
    return $scope='sm1-'.substr(hash('sha256',$encoded),0,24);
}

function mad4b_render_query_private_context() {
    // Only company-bound, anonymous public contexts can populate shared
    // JetEngine Query Builder results. Preserve all market, commerce and
    // ownership gates while retaining the source's newer dependency graph.
    if (!class_exists('MAD4B_Experience_Site_Scope_V1', false)
        || !MAD4B_Experience_Site_Scope_V1::bound()) return true;
    if (mad4b_render_query_has_untrusted_market_header())return true;
    if ( is_admin() || wp_doing_ajax() || is_user_logged_in() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
    foreach (['HTTP_AUTHORIZATION','PHP_AUTH_USER','HTTP_X_WP_NONCE',
        'HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT','HTTP_X_MAD4B_PASSPORT',
        'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY','HTTP_CF_IPCOUNTRY',
        'HTTP_X_COUNTRY_CODE','HTTP_X_GEOIP_COUNTRY','HTTP_X_REQUESTED_WITH',
        'HTTP_X_MAD4B_BYPASS_CACHE'] as $header) {
        if (!empty($_SERVER[$header])) return true;
    }
    if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'MAD4B_RENDER_QUERY_QA' ) ) { return true; }
    foreach ( array_keys( (array) $_GET ) as $key ) {
        if ( ! preg_match( '/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string) $key ) ) { return true; }
    }
    // A public Query Builder result must not inherit an unknown visitor
    // preference. Accept only an exact WPML locale cookie; all session,
    // market, analytics or unclassified cookies force native execution.
    $language=(string)apply_filters('wpml_current_language','');
    $wire=(string)($_SERVER['HTTP_COOKIE']??'');
    if ($wire!=='') {
        if (strlen($wire)>160 || str_contains($wire,';'))return true;
        $pair=explode('=',trim($wire),2);
        if (count($pair)!==2 || $pair[0]!=='wp-wpml_current_language'
            || rawurldecode($pair[1])!==$language)return true;
    }
    foreach ((array)$_COOKIE as $name=>$value) {
        if ($name!=='wp-wpml_current_language'
            || !is_string($value) || $value!==$language)return true;
    }
    return false;
}

/**
 * Market identity is owned by the installed WCML runtime, not fabricated from
 * client headers or a locale-to-currency lookup table. Unknown identity
 * fails closed to native query execution; never write it to shared Redis.
 */
function mad4b_render_query_market_identity(): array {
    $base=(string)get_option('woocommerce_currency','');
    $currency=function_exists('get_woocommerce_currency')
        ?(string)get_woocommerce_currency():$base;
    if (!preg_match('/^[A-Z]{3}$/D',$base)
        || !preg_match('/^[A-Z]{3}$/D',$currency))return [];
    $multicurrency=function_exists('wcml_is_multi_currency_on')
        && wcml_is_multi_currency_on();
    if (!$multicurrency) return $currency===$base
        ? ['country'=>'base','currency'=>$base] : [];
    $cls='WCML\MultiCurrency\Geolocation';
    if (!class_exists($cls) || !method_exists($cls,'getUserCountry'))return [];
    $country=strtoupper((string)$cls::getUserCountry());
    if (!preg_match('/^[A-Z]{2}$/D',$country))return [];
    return ['country'=>$country,'currency'=>$currency];
}

/**
 * Context-sensitive Query Builder results (Rate, occupancy, availability,
 * room units, extras etc.) must never populate a shared results cache
 * merely because a referenced CPT happens to be publicly viewable.
 * Classification is semantic; no Query Builder or Elementor IDs are used.
 */
function mad4b_render_query_sensitive_semantics($query): bool {
    $name=(string)($query->name??'');
    if (preg_match('/(?:^|[^A-Za-z0-9])(?:rates?|pricing|prices?|quotes?|bookings?|orders?|costs?|suppliers?|availability|occupancy|packages?|tiers?|stocks?|inventor(?:y|ies)|units?|extras?|transport(?:s|ations?)?|upsells?)(?=$|[^A-Za-z0-9])/i',$name))
        return true;
    // A renamed Query Builder item can carry market-sensitive meta
    // selectors; classify the field schema, not a hard-coded query ID.
    $final=is_array($query->final_query??null)?$query->final_query:[];
    $scan=function($nodes)use(&$scan):bool {
        foreach ((array)$nodes as $k=>$v) {
            if (is_string($k) && in_array(strtolower($k),['key','meta_key','field','meta_query_key'],true)
                && is_string($v)
                && preg_match('/(?:price|rate|cost|stock|inventor|booking|availability|occupancy|supplier|deposit|payment)/i',$v))return true;
            if (is_array($v) && $scan($v))return true;
        }
        return false;
    };
    return $scan($final);
}

function mad4b_render_query_market_partition_ready(): bool {
    if (!(function_exists('wcml_is_multi_currency_on') && wcml_is_multi_currency_on()))return true;
    $mode=(string)get_option('mad4b_render_query_market_partition_mode','hold');
    if ($mode==='active')return true;
    return $mode==='canary' && wp_get_environment_type()==='staging'
        && (string)($_SERVER['HTTP_X_MAD4B_QUERY_MARKET_QA']??'')==='canary-v1';
}

function mad4b_render_query_policy( $query ) {
    if ( ! is_object( $query ) || empty( $query->id ) || ! isset( $query->query_type ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unidentified' ]; }
    if ( ! wp_using_ext_object_cache() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'no_persistent_backend' ]; }
    if ( 'off' === get_option( 'mad4b_render_query_governor', 'safe' ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'policy_off' ]; }
    $signedScope=mad4b_render_query_signed_market_scope();
    if ( !$signedScope && mad4b_render_query_private_context() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'private_context' ]; }
    // Signed-market path caches only native public post/term query RESULTS.
    // Never cache pricing, repeater, SQL, quote or visitor-specific sources.
    if ($signedScope && !in_array((string)$query->query_type,['posts','terms'],true))
        return [ 'cache'=>false,'ttl'=>0,'reason'=>'signed_market_query_type_veto' ];
    if ( !$signedScope && !mad4b_render_query_market_partition_ready() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'market_partition_hold' ]; }
    if ( mad4b_render_query_sensitive_semantics($query) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'sensitive_semantic_query' ]; }
    if ( !mad4b_render_query_market_identity() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unverified_market_identity' ]; }
    $type = (string) $query->query_type;
    $args = is_array( $query->final_query ?? null ) ? $query->final_query : [];
    $deny = '/(?:^|[_-])(?:orders?|bookings?|checkout|quotes?|payments?|customers?|users?|sessions?|suppliers?|invoices?|rates?|pricing|prices?|costs?|reservations?)(?:[_-]|$)/i';
    if ( 'posts' === $type ) {
        $types = (array) ( $args['post_type'] ?? [] );
        $statuses = array_values( array_unique( (array) ( $args['post_status'] ?? [ 'publish' ] ) ) );
        if ( ! $types || count( $statuses ) !== 1 || 'publish' !== $statuses[0] ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'non_public_status' ]; }
        foreach ( $types as $post_type ) {
            if ( ! is_string( $post_type ) || preg_match( $deny, $post_type ) || 'product' === $post_type ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'sensitive_type' ]; }
            $object = get_post_type_object( $post_type );
            if ( ! $object || empty( $object->public ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'not_public_type' ]; }
        }
        // General public lists keep a short fallback; stable tour relations/properties have long TTLs.
        // Defaults are shared across all companies, while each site can adjust its own TTL profile.
        $kind = 'general';
        $taxes = (array) ( $args['tax_query'] ?? [] );
        foreach ( $taxes as $tax ) {
            if ( is_array( $tax ) && isset( $tax['taxonomy'] ) && preg_match( '/(?:destination|location|region|city)/i', (string) $tax['taxonomy'] ) ) { $kind = 'related_tours'; break; }
        }
        foreach ( $types as $post_type ) {
            if ( preg_match( '/(?:property|properties|hotel|accommodation)/i', $post_type ) ) { $kind = 'properties'; break; }
        }
        $defaults = [ 'general' => 900, 'related_tours' => 7 * DAY_IN_SECONDS, 'properties' => 3 * DAY_IN_SECONDS ];
        $overrides = get_option( 'mad4b_render_query_ttls', [] );
        $value = is_array( $overrides ) && isset( $overrides[ $kind ] ) && is_numeric( $overrides[ $kind ] )
            ? (int) $overrides[ $kind ] : $defaults[ $kind ];
        // Bound persistent cache memory and ensure entries expire even if a bulk import skips WP hooks.
        $ttl = max( 300, min( 30 * DAY_IN_SECONDS, $value ) );
        return [ 'cache' => true, 'ttl' => $ttl, 'reason' => 'public_posts_' . $kind ];
    }
    if ( 'terms' === $type ) {
        $taxes = (array) ( $args['taxonomy'] ?? [] );
        if ( ! $taxes ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unscoped_terms' ]; }
        foreach ( $taxes as $tax ) {
            $obj = is_string( $tax ) ? get_taxonomy( $tax ) : null;
            if ( ! $obj || empty( $obj->public ) || preg_match( $deny, (string) $tax ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'sensitive_terms' ]; }
        }
        return [ 'cache' => true, 'ttl' => 900, 'reason' => 'public_terms' ];
    }
    if ( 'repeater' === $type ) {
        $field = (string) ( $args['jet_engine_field'] ?? '' );
        // Discover content-only repeater fields from JetEngine metadata; never cache prices/extras/quotes.
        if ( 'jet_engine' === ( $args['source'] ?? '' )
            && preg_match( '/(?:^|::|_)(?:faq(?:_items?)?|itinerary(?:_items?)?|includes?|excludes?|amenities)(?:$|_item|_items)/i', $field )
            && ! preg_match( '/(?:price|rate|cost|extra|booking|checkout|quote|supplier|customer)/i', $field ) ) {
            return [ 'cache' => true, 'ttl' => 900, 'reason' => 'public_content_repeater' ];
        }
    }
    // SQL/users/orders/pricing and other dynamic sources must run against current data.
    return [ 'cache' => false, 'ttl' => 0, 'reason' => 'dynamic_or_unclassified' ];
}


/**
 * Query-scoped source revisions. Reuse JetEngine's actual query schema and
 * WordPress registered CPT/taxonomy relationships, never a hand-maintained
 * widget/listing ID map. Unknown query contracts retain global generation.
 *
 * No new cache system: only the version dimension of native JetEngine's
 * existing Query Builder object-cache group is changed when admitted.
 */
function mad4b_render_query_scope_option(string $kind, string $name): string {
    if (!in_array($kind,['post_type','taxonomy','membership','membership_taxonomy'],true))return '';
    // Each published taxonomic membership is bound to *both* taxonomy and
    // public post type. A package_levels assignment to a tour cannot rotate
    // the destinations listing's source version merely because both
    // taxonomies happen to be registered to tours-and-activities.
    $pattern=$kind==='membership_taxonomy'
        ? '/^[A-Za-z0-9_-]{1,100}::[A-Za-z0-9_-]{1,100}$/D'
        : '/^[A-Za-z0-9_-]{1,100}$/D';
    if(!preg_match($pattern,$name))return '';
    $prefix=match($kind){
       'post_type'=>'p_','taxonomy'=>'t_','membership'=>'m_','membership_taxonomy'=>'mt_'
    };
    return 'mad4b_rq_source_v1_' . $prefix
        . substr(hash('sha256',$name),0,24);
}
function mad4b_render_query_scope_bump(string $kind,string $name): bool {
    $option=mad4b_render_query_scope_option($kind,$name);
    if($option==='')return false;
    // Nonrepeating opaque versions: concurrent writers can supersede each
    // other, but never resurrect the version of a previously cached result.
    $revision=substr(hash('sha256',wp_generate_uuid4().'|'.microtime(true)),0,32);
    update_option($option,$revision,false);
    return hash_equals($revision,(string)get_option($option,''));
}
function mad4b_render_query_scope_bump_post(int $post_id): bool {
    if(!$post_id || wp_is_post_revision($post_id))return false;
    $type=(string)get_post_type($post_id);
    $obj=$type!==''?get_post_type_object($type):null;
    if(!$obj || empty($obj->public)
        || preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$type))return false;
    return mad4b_render_query_scope_bump('post_type',$type);
}
/**
 * Only relation and published-membership events should rotate this version.
 * Ordinary post meta edits continue to bump their own post-content version
 * for queries returning posts, but not a native terms query.
 */
function mad4b_render_query_scope_bump_membership_post(
    int $post_id, ?string $changed_taxonomy=null
): bool {
    if(!$post_id || wp_is_post_revision($post_id))return false;
    $type=(string)get_post_type($post_id);
    $obj=$type!==''?get_post_type_object($type):null;
    if(!$obj || empty($obj->public)
        || preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$type))return false;

    $all=(array)get_object_taxonomies($type);
    $public=[];
    foreach($all as $taxonomy){
        if(!is_string($taxonomy))continue;
        $tax=get_taxonomy($taxonomy);
        if($tax && !empty($tax->public)
            && in_array($type,(array)($tax->object_type??[]),true))
            $public[$taxonomy]=true;
    }
    if($changed_taxonomy!==null){
        // set_object_terms affects exactly one taxonomy, never the complete
        // list of taxonomies registered to the same post type.
        if(!isset($public[$changed_taxonomy]))return false;
        return mad4b_render_query_scope_bump(
            'membership_taxonomy',$changed_taxonomy.'::'.$type
        );
    }
    if(!$public)return false;
    // A publish/unpublish/delete can affect any public taxonomy bound to
    // this post type. Rotate all *registered* taxonomy/type pairs (not
    // unrelated post types or taxonomies). This intentionally avoids using
    // WPML-filtered term lookups that could miss assignments in other
    // languages during a status change.
    $any=false;
    foreach(array_keys($public) as $taxonomy){
        $any=mad4b_render_query_scope_bump(
            'membership_taxonomy',$taxonomy.'::'.$type
        )||$any;
    }
    return $any;
}
function mad4b_render_query_scope_bump_term(string $taxonomy): bool {
    $obj=get_taxonomy($taxonomy);
    if(!$obj || empty($obj->public))return false;
    return mad4b_render_query_scope_bump('taxonomy',$taxonomy);
}
function mad4b_render_query_scope_spec($query): array {
    if(!is_object($query) || !in_array((string)($query->query_type??''),['posts','terms'],true))return [];
    $raw=$query->query??null;
    $final=$query->final_query??null;
    if(!is_array($raw))return [];
    $type=(string)$query->query_type;
    $scopes=[];
    if($type==='posts'){
        $types=$raw['post_type']??($final['post_type']??[]);
        if(is_string($types))$types=[$types];
        if(!is_array($types) || !$types || count($types)>18)return [];
        foreach($types as $pt){
            if(!is_string($pt) || $pt==='any' || !get_post_type_object($pt)
                || empty(get_post_type_object($pt)->public))return [];
            $scopes[]=['post_type',$pt];
        }
        // Term-meta based filters may change without a post edit. Include
        // the public taxonomies explicitly selected by JetEngine Query Builder.
        $walk=static function($clauses)use(&$walk,&$scopes):bool{
            if(!is_array($clauses))return false;
            foreach($clauses as $k=>$clause){
                if(!is_array($clause))continue;
                if(isset($clause['taxonomy'])){
                    $tax=(string)$clause['taxonomy'];
                    $obj=get_taxonomy($tax);
                    if($tax==='' || !$obj || empty($obj->public))return false;
                    $scopes[]=['taxonomy',$tax];
                }elseif(is_int($k) && !$walk($clause))return false;
            }
            return true;
        };
        if(isset($raw['tax_query']) && !$walk($raw['tax_query']))return [];
    }else{
        $taxes=$raw['taxonomy']??($final['taxonomy']??[]);
        if(is_string($taxes))$taxes=[$taxes];
        if(!is_array($taxes) || !$taxes || count($taxes)>16)return [];
        foreach($taxes as $tax){
            if(!is_string($tax) || $tax==='')return [];
            $obj=get_taxonomy($tax);
            if(!$obj || empty($obj->public))return [];
            $scopes[]=['taxonomy',$tax];
            // hide_empty and object counts depend on source posts. A post
            // save cannot silently keep an old public terms-query result.
            foreach((array)($obj->object_type??[]) as $pt){
                if(is_string($pt) && get_post_type_object($pt)
                    && !empty(get_post_type_object($pt)->public)
                    && !preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$pt))
                    $scopes[]=['membership_taxonomy',$tax.'::'.$pt];
            }
        }
    }
    $out=[];foreach($scopes as [$kind,$name]){
        $option=mad4b_render_query_scope_option($kind,$name);
        if($option==='')return [];
        $out[$option]=true;
    }
    if(!$out || count($out)>45)return [];
    ksort($out,SORT_STRING);
    return array_keys($out);
}
function mad4b_render_query_effective_generation($query): string {
    $original=(string)get_option('mad4b_render_query_data_generation','initial');
    if((string)get_option('mad4b_render_query_scoped_generation_v1','off')!=='on')return $original;
    $spec=mad4b_render_query_scope_spec($query);
    if(!$spec)return $original; // fail closed; unknown still globally invalidated
    $versions=[];
    foreach($spec as $option)$versions[$option]=(string)get_option($option,'baseline');
    $safety=(string)get_option('mad4b_render_query_global_safety_generation_v1','initial');
    $source=is_array($query->query??null)?$query->query:[];
    return 'scoped:v1:' . hash('sha256',wp_json_encode([
        'query_id'=>(int)($query->id??0),
        'type'=>(string)($query->query_type??''),
        'source_sha256'=>hash('sha256',wp_json_encode($source)),
        'scope_versions'=>$versions,
        'safety'=>$safety
    ]));
}

/**
 * Browser QA certificates and native Query Builder cache use one version
 * authority. A global fragment reset must not expire an unrelated listing's
 * expensive SSR/AJAX parity certificate when its source scopes are unchanged.
 */
function mad4b_render_query_generation_for_id(int $query_id): string {
    if($query_id<1 || $query_id>PHP_INT_MAX-1
        || !class_exists('Jet_Engine\Query_Builder\Manager'))return '';
    $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query_id);
    if(!is_object($query) || (int)($query->id??0)!==$query_id)return '';
    return mad4b_render_query_effective_generation($query);
}

add_action( 'jet-engine/query-builder/query/after-query-setup', static function ( $query ) {
    if ( ! is_object( $query ) || ! property_exists( $query, 'cache_query' ) ) { return; }
    $decision = mad4b_render_query_policy( $query );
    $signedScope=mad4b_render_query_signed_market_scope();
    $query->cache_query = $decision['cache'];
    if ( ! $decision['cache'] ) { $query->cache_expires = 0; return; }
    // Canonical tenant ID is stable across WPML languages; current language is
    // independently included in the group below. Avoid expensive WPML home_url
    // permalink/callstack filters on every JetEngine Query Builder setup.
    $canonical_home = get_option('home','');
    $canonical_home = is_string($canonical_home) && $canonical_home !== ''
        ? untrailingslashit($canonical_home) : home_url('/');
    $site = substr(hash('sha256',get_current_blog_id().'|'.$canonical_home),0,10);
    $market=mad4b_render_query_market_identity();
    if (!$market) { $query->cache_query=false; $query->cache_expires=0; return; }
    $marketSegment=strtolower($market['country']).'-'.strtolower($market['currency']);
    $language = (string) apply_filters( 'wpml_current_language', null );
    if ( '' === $language ) { $language = determine_locale(); }
    $language = sanitize_key( $language );
    $version = mad4b_render_query_effective_generation($query);
    $version = (string) apply_filters('mad4b/render_query/query_version',$version,$query,$decision);
    $query->cache_query = true;
    // Signed guests have a short bounded TTL; legacy public policy unchanged.
    $query->cache_expires = $signedScope
        ? min(90,(int)$decision['ttl']) : $decision['ttl'];
    $query->cache_group = 'mad4b-rg-' . $site . '-' . $language . '-' . $marketSegment
        . ($signedScope ? '-' . $signedScope : '')
        . '-' . (int) $query->id . '-' . substr( md5( $version ), 0, 10 );
}, 100, 1 );

function mad4b_render_query_invalidate($origin = null, bool $scope_already_bumped = false) {
    if (false === apply_filters('mad4b/render_query/global_invalidation_required',true,$origin))return;
    // The shared public fragment cache still relies on global invalidation.
    // The native Query Builder group uses a separate global SAFETY version
    // only when the event cannot be proven scoped (policy, permissions, etc.).
    static $safety_bumped = false;
    if(!$scope_already_bumped && !$safety_bumped){
        $safety_bumped=true;
        update_option('mad4b_render_query_global_safety_generation_v1',
            substr(hash('sha256',wp_generate_uuid4().'|'.microtime(true)),0,32),false);
    }
    static $bumped = false;
    if ( $bumped ) { return; }
    $bumped = true;
    // Staging-only diagnostics use a bounded, allowlisted source category.
    // No content, post IDs, meta values, IPs, or request arguments are stored.
    $GLOBALS['mad4b_render_query_invalidation_origin'] = is_array($origin)
        ? $origin : ['kind'=>'hook','hook'=>(string)current_filter()];
    try {
        update_option( 'mad4b_render_query_data_generation', sprintf( '%d-%d', time(), wp_rand( 100000, 999999 ) ), false );
    } finally {
        unset($GLOBALS['mad4b_render_query_invalidation_origin']);
    }
}
// Preserve the legacy explicit invalidation contract: callers may update
// mad4b_render_query_data_generation directly instead of firing a MAD4B action.
// External writes are security-sensitive unknown changes; invalidate all scoped
// query groups. Ordinary native content hooks have an explicit origin marker.
add_action('updated_option',static function($name,$old,$new){
    if($name!=='mad4b_render_query_data_generation' || $old===$new
        || isset($GLOBALS['mad4b_render_query_invalidation_origin']))return;
    update_option('mad4b_render_query_global_safety_generation_v1',
        substr(hash('sha256',wp_generate_uuid4().'|'.microtime(true)),0,32),false);
},20,3);

// Observe successful generation changes, including writers outside this governor.
// Store hook names only, never option values, post content or request credentials.
add_action('updated_option', static function($name,$old,$new) {
    if ($name!=='mad4b_render_query_data_generation' || wp_get_environment_type()!=='staging') return;
    global $wp_current_filter;
    $hooks=array_values(array_filter((array)$wp_current_filter,static fn($h)=>is_string($h)));
    $event=['time'=>microtime(true),'previous'=>(string)$old,'generation'=>(string)$new,
        'hooks'=>array_slice($hooks,-8),
        'channel'=>(defined('WP_CLI') && WP_CLI)?'cli':((defined('DOING_CRON') && DOING_CRON)?'cron':'http'),
        'origin'=>$GLOBALS['mad4b_render_query_invalidation_origin']??['kind'=>'external_writer']];
    $slot=hexdec(substr(hash('sha256',(string)$new),0,2))%32;
    wp_cache_set('generation-'.$slot,$event,'mad4b-fragment-lifecycle',86400);
    do_action('mad4b/render_query/generation_changed',$event);
},999,3);

function mad4b_render_query_changed_post( $post_id, $origin = null ) {
    if ( ! $post_id || wp_is_post_revision( $post_id ) ) { return; }
    $type = get_post_type( $post_id );
    if ( $type && preg_match( '/(?:order|booking|checkout|payment|customer|reservation|invoice)/i', $type ) ) { return; }
    $obj = $type ? get_post_type_object( $type ) : null;
    if ( $obj && ! empty( $obj->public ) ) {
        $source=is_array($origin)?$origin:['kind'=>'public_post_change','hook'=>(string)current_filter()];
        $source['post_type']=(string)$type;
        $source['post_id']=(int)$post_id;
        $scoped=mad4b_render_query_scope_bump_post((int)$post_id);
        mad4b_render_query_invalidate($source,$scoped);
    }
}
// Native term counts depend on published relation membership, not arbitrary
// draft metadata. Explicit transitions and deletions preserve completeness.
add_action('transition_post_status',static function($new,$old,$post){
    if($new===$old || ($new!=='publish' && $old!=='publish')
       || !is_object($post) || empty($post->ID))return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$post->ID);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'public_membership_status','post_type'=>(string)get_post_type($post->ID)
    ],true);
},40,3);
add_action('before_delete_post',static function($post_id){
    if(get_post_status($post_id)!=='publish')return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$post_id);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'published_membership_delete','post_type'=>(string)get_post_type($post_id)
    ],true);
},41,1);
add_action( 'save_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'before_delete_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'trashed_post', 'mad4b_render_query_changed_post', 40, 1 );
/**
 * Elementor's generated render artifacts are not content source mutations.
 * Discover their current meta-key contracts from installed Elementor classes,
 * rather than pinning a list of generated keys that ages with upgrades.
 * Real Elementor documents/settings, custom fields and prices still invalidate.
 */
function mad4b_render_query_is_generated_meta_artifact(string $meta_key): bool {
    // This watermark *detection fingerprint* is generated by the media
    // diagnostics cron. It is not the underlying image/alt/copyright source.
    // Its hourly refresh must not invalidate destination card AJAX proofs.
    if (in_array($meta_key,[
        '_edit_lock','_edit_last','_elementor_css','_mad4b_watermark_fingerprint_v1'
    ],true)) return true;
    foreach ([
        ['Elementor\\Core\\Base\\Document','CACHE_META_KEY'],
        ['Elementor\\Core\\Base\\Elements_Iteration_Actions\\Assets','ASSETS_META_KEY'],
    ] as [$owner,$constant]) {
        if (!class_exists($owner) || !defined($owner.'::'.$constant)) continue;
        $name=constant($owner.'::'.$constant);
        if (is_string($name) && $name!=='' && $meta_key===$name) return true;
    }
    return false;
}

foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
    add_action( $hook, static function ( $meta_id, $post_id, $meta_key ) use ($hook) {
        // Elementor generated stylesheet metadata is a render artifact, not
        // a content/query predicate change. Invalidation belongs to the
        // _elementor_data, page settings, template and save_post hooks.
        // Bumping the global Query generation on every CSS rebuild turns
        // normal frontend traffic into a cache-thrashing feedback loop.
        if(mad4b_render_query_is_generated_meta_artifact((string)$meta_key))return;
        $kind=match((string)$meta_key) {
            '_elementor_css' => 'generated_elementor_css',
            '_elementor_data' => 'elementor_document',
            '_listing_data' => 'listing_definition',
            '_elementor_page_settings' => 'elementor_settings',
            default => 'other_post_meta'
        };
        mad4b_render_query_changed_post((int)$post_id,
            ['kind'=>'post_meta','operation'=>$hook,'meta_class'=>$kind,
             'meta_sensitive'=>preg_match('/(?:elementor|listing_data|rate|price|cost|booking|availability|stock|inventory|occupancy|supplier|wcml|currency|market|relation|query|template|capabilit|access|visibility|permission)/i',(string)$meta_key)?1:0]);
    }, 40, 3 );
}
add_action( 'set_object_terms', static function ( $id, $terms, $tt_ids, $taxonomy, $append=false, $old_tt_ids=[] ) {
    $tax = get_taxonomy( $taxonomy );
    if ( !$tax || empty( $tax->public ) || get_post_status($id)!=='publish' )return;
    // WordPress can fire set_object_terms even for a no-op replay; do not
    // rotate the certificate without a changed relation membership set.
    $before=array_values(array_unique(array_map('absint',(array)$old_tt_ids)));
    $after=array_values(array_unique(array_map('absint',(array)$tt_ids)));
    // When append=true WordPress provides only the supplied new term IDs,
    // not necessarily the whole existing set; merge before comparing.
    if($append)$after=array_values(array_unique(array_merge($before,$after)));
    sort($before,SORT_NUMERIC);sort($after,SORT_NUMERIC);
    if($before===$after)return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$id,(string)$taxonomy);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'public_term_membership','taxonomy'=>(string)$taxonomy,
        'post_type'=>(string)get_post_type($id)
    ],true);
}, 40, 6 );
add_action( 'edited_term', static function ($term_id,$tt_id,$taxonomy) {
    $scoped=mad4b_render_query_scope_bump_term((string)$taxonomy);
    mad4b_render_query_invalidate(['kind'=>'taxonomy','taxonomy'=>(string)$taxonomy,'term_id'=>(int)$term_id],$scoped);
}, 40, 3 );
add_action( 'deleted_term', static function ($term_id,$tt_id,$taxonomy) {
    $scoped=mad4b_render_query_scope_bump_term((string)$taxonomy);
    mad4b_render_query_invalidate(['kind'=>'taxonomy','taxonomy'=>(string)$taxonomy,'term_id'=>(int)$term_id],$scoped);
}, 40, 3 );
// JetEngine term meta predicates (e.g. destination_type) can change without
// a term edit. These events must invalidate the corresponding taxonomy only.
foreach(['added_term_meta','updated_term_meta','deleted_term_meta'] as $hook){
    add_action($hook,static function($meta_id,$term_id,$key)use($hook){
        $term=get_term((int)$term_id);
        if(!$term || is_wp_error($term) || empty($term->taxonomy))return;
        $scoped=mad4b_render_query_scope_bump_term((string)$term->taxonomy);
        mad4b_render_query_invalidate(['kind'=>'term_meta','operation'=>$hook,
            'taxonomy'=>(string)$term->taxonomy,'term_id'=>(int)$term_id],$scoped);
    },40,3);
}
add_action( 'updated_option', static function ( $name ) {
    // Public listing fragments may contain destination/service links.
    // Invalidate on access-policy changes, not just content/price events.
    // This keeps the extended public-only TTL independent of active
    // service activation, visitor markets and booking state.
    if ( in_array( (string)$name, [
        'service-activation',
        'destination-activation',
        'mad4b_render_public_fragments_enabled'
    ], true ) || preg_match( '/(?:^|[_-])(?:rates?|pricing|suppliers?|markets?|currenc(?:y|ies)|seasons?|occupancy|wcml)(?:[_-]|$)/i', (string) $name ) ) {
        mad4b_render_query_invalidate();
    }
}, 40, 1 );
// Query Builder emits this after successful edits/creation; invalidate definitions too.
add_action( 'jet-engine/query-builder/after-query-update', 'mad4b_render_query_invalidate', 40 );
// Bulk imports that update SQL directly can explicitly call this action.
add_action( 'mad4b/render_query/invalidate', 'mad4b_render_query_invalidate' );

/**
 * Selective public listing fragment memo (v1). Shares the query governor's
 * data-generation invalidation; no extra database tables, services or broker.
 * The tenant's audited templates are declared in a separate JSON policy.
 * Default OFF; new companies without a matching local policy render natively.
 */
final class MAD4B_Render_Query_Public_Fragments {
    private const GROUP = 'mad4b-rq-public-fragments-v1';
    private const VERSION = '1.2.2';
    private static array $templates = [];
    // Validated once by WordPress on the outer route before Elementor/JetEngine
    // swap the global WP_Query while assembling nested listing documents.
    private static bool $verifiedTourRoute = false;
    private static int $verifiedTourPost = 0;

    private static array $metrics = ['hit' => 0, 'miss' => 0, 'reject' => 0, 'bytes' => 0];
    private static array $audited = [];
    private static array $verifiedTemplateSha = [];
    private static bool $qa = false;
    private static array $requestFragments = [];
    private static int $requestBytes = 0;
    private static array $costs = [];

    private static array $lifecycle = [];
    private static array $proofs = [];
    private static bool $traceRegistered = false;

    private static function lifecycle(int $id, string $event, string $key='', string $html=''): void {
        // Explicit staging profiling only. No HTML, cookies, URLs or user data retained.
        if (wp_get_environment_type()!=='staging'
            || ($_SERVER['HTTP_X_MAD4B_RENDER_PROFILE']??'')!=='v1') return;
        if (!self::$traceRegistered) {
            self::$traceRegistered=true;
            add_action('shutdown',[self::class,'save_lifecycle_sample'],99999);
        }
        self::$lifecycle[$id][$event]=(self::$lifecycle[$id][$event]??0)+1;
        if ($key!=='' && (isset(self::$proofs[$key]) || count(self::$proofs)<128)) {
            self::$proofs[$key]['template']=$id;
            self::$proofs[$key]['events'][$event]=(self::$proofs[$key]['events'][$event]??0)+1;
            if ($html!=='') self::$proofs[$key]['html_sha256']=hash('sha256',$html);
        }
    }

    public static function save_lifecycle_sample(): void {
        if (!self::$lifecycle) return;
        $sample=['contract'=>'mad4b.fragment-lifecycle.v1','time'=>microtime(true),
            'sample_id'=>wp_generate_uuid4(),
            'language'=>(string)apply_filters('wpml_current_language',''),
            'generation'=>(string)get_option('mad4b_render_query_data_generation','initial'),
            'ttl_seconds'=>(int)(self::policy()['ttl_seconds']??0),
            'lifecycle'=>self::$lifecycle,'proofs'=>self::$proofs,'costs'=>self::$costs];
        // A bounded diagnostic ring, not an authoritative readiness receipt.
        // Each write atomically replaces a slot; collisions may drop a sample.
        $slot=hexdec(substr(hash('sha256',$sample['sample_id']),0,2))%16;
        wp_cache_set('sample-'.$slot,$sample,'mad4b-fragment-lifecycle',900);
        do_action('mad4b/render_query/fragment_lifecycle_sample',$sample);
    }

    public static function invalidation_history(): array {
        $events=[];
        for($i=0;$i<32;$i++) {
            $v=wp_cache_get('generation-'.$i,'mad4b-fragment-lifecycle');
            if (is_array($v) && isset($v['time'],$v['generation'],$v['hooks'])) $events[]=$v;
        }
        usort($events,static fn($a,$b)=>$a['time']<=>$b['time']);
        return $events;
    }

    public static function lifecycle_samples(): array {
        $samples=[];
        for($i=0;$i<16;$i++) {
            $v=wp_cache_get('sample-'.$i,'mad4b-fragment-lifecycle');
            if (is_array($v) && ($v['contract']??'')==='mad4b.fragment-lifecycle.v1') $samples[]=$v;
        }
        usort($samples,static fn($a,$b)=>$a['time']<=>$b['time']);
        return $samples;
    }

    public static function metrics(): array {
        return ['cache'=>self::$metrics, 'costs'=>self::$costs, 'lifecycle'=>self::$lifecycle,
            'layout'=>self::$layoutStats, 'memo_entries'=>count(self::$requestFragments), 'memo_bytes'=>self::$requestBytes];
    }

    private static function remember(string $key, string $html): void {
        // Bound additional request memory; persistent keys retain all tenant,
        // language, route, template, term and generation dependencies.
        if (isset(self::$requestFragments[$key]) || count(self::$requestFragments)>=128
            || self::$requestBytes+strlen($html)>2097152) return;
        self::$requestFragments[$key]=$html;
        self::$requestBytes+=strlen($html);
    }

    private static function cost(int $id, string $phase, int $start): void {
        if (!$start) return;
        if (!isset(self::$costs[$id][$phase])) self::$costs[$id][$phase]=['calls'=>0,'ns'=>0];
        self::$costs[$id][$phase]['calls']++;
        self::$costs[$id][$phase]['ns']+=hrtime(true)-$start;
    }

    private static function policy(): array {
        static $policy = null;
        if ($policy !== null) return $policy;
        $policy = [];
        $file = WPMU_PLUGIN_DIR . '/mad4b-selective-render-policy.json';
        if (!is_readable($file) || is_link($file)) return [];
        $cfg = json_decode((string) @file_get_contents($file), true);
        if (!is_array($cfg) || ($cfg['contract'] ?? '') !== 'mad4b.render-query.public-fragments.v1'
            || ($cfg['environment'] ?? '') !== wp_get_environment_type()
            || ($cfg['site_host'] ?? '') !== wp_parse_url(home_url('/'), PHP_URL_HOST)
            || !is_array($cfg['templates'] ?? null)
            || !in_array(($cfg['status'] ?? ''), ['canary', 'active'], true)) return [];
        $ttl = $cfg['ttl_seconds'] ?? 0;
        // Only the pinned public destination/term template manifest may use
        // a longer TTL; each hit still checks template, WPML, term meta,
        // activation and content generation dependencies via term_key().
        if (!is_int($ttl) || $ttl < 15 || $ttl > 600) return [];
        return $policy = $cfg;
    }

    private static function test_request(): bool {
        // Query-based canary access is never permitted in the active public mode.
        return (self::policy()['status'] ?? '') === 'canary'
            && isset($_GET['mad4b_public_fragment_qa'])
            && count($_GET) === 1
            && $_GET['mad4b_public_fragment_qa'] === '1';
    }

    private static function tour_fragment_route(): bool {
        if (!is_singular('tours-and-activities') || !empty($_SERVER['QUERY_STRING']))return false;
        $scope=(string)get_option('mad4b_render_public_fragments_tour_scope','off');
        if ($scope!=='active'
            && !($scope==='canary'
                && in_array(wp_get_environment_type(),['staging','production'],true)
                && ($_SERVER['HTTP_X_MAD4B_TOUR_FRAGMENT_QA']??'')==='v1'))return false;
        $id=absint(get_queried_object_id());
        $post=$id?get_post($id):null;
        if (!$post instanceof WP_Post || $post->post_type!=='tours-and-activities'
            || $post->post_status!=='publish'
            || !is_post_publicly_viewable($post) || post_password_required($post))return false;
        $canonical=(string)wp_parse_url(get_permalink($post),PHP_URL_PATH);
        $route=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        return $canonical!=='' && $canonical===$route;
    }

    /**
     * Shared fragment-market binding. WCML may have multi-currency disabled
     * for a company while its first-party Geolocation provider is available.
     * Never let "base" collapse multiple origins into a single fragment key.
     * Keep the Query Builder policy/identity untouched.
     */
    private static function fragment_market_identity(): array {
        if (!function_exists('mad4b_render_query_market_identity')
            || !function_exists('get_woocommerce_currency'))return [];
        $market=mad4b_render_query_market_identity();
        if (!is_array($market) || !$market)return [];
        $currency=(string)get_woocommerce_currency();
        if (!preg_match('/^[A-Z]{3}$/D',$currency)
            || (string)($market['currency']??'')!==$currency)return [];
        $country=(string)($market['country']??'');
        if ($country==='base') {
            $base=(string)get_option('woocommerce_currency','');
            if ($currency!==$base
                || (function_exists('wcml_is_multi_currency_on')
                    && wcml_is_multi_currency_on()))return [];
            $geo='WCML\\MultiCurrency\\Geolocation';
            if (!class_exists($geo) || !method_exists($geo,'getUserCountry'))return [];
            $country=strtoupper((string)$geo::getUserCountry());
        }
        if (!preg_match('/^[A-Z]{2}$/D',$country))return [];
        return ['country'=>$country,'currency'=>$currency];
    }

    private static function context(): bool {
        // Both Staging and Production are eligible only when this company's
        // own environment-matched manifest and explicit on-switch are present.
        if (!in_array(wp_get_environment_type(), ['staging', 'production'], true)
            || !wp_using_ext_object_cache()
            || (defined('WP_CLI') && WP_CLI) || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview()
            || !(is_front_page() || self::tour_fragment_route())
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['PHP_AUTH_USER'])) return false;
        // Read only the on-wire cookie header. Accept a single WPML locale
        // cookie when it matches the already resolved server-side language;
        // reject analytics/session/cart/market/currency or ambiguous cookies.
        $wire = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($wire !== '') {
            if (strlen($wire) > 256) return false;
            $pairs = explode(';', $wire);
            if (count($pairs) !== 1) return false;
            $pair = explode('=', trim($pairs[0]), 2);
            if (count($pair) !== 2 || $pair[0] !== 'wp-wpml_current_language'
                || rawurldecode($pair[1]) !== (string)apply_filters('wpml_current_language', '')) return false;
        }
        // WPML may populate this language state during bootstrap without
        // receiving a Cookie header. No arbitrary or visitor-derived cookie
        // values are permitted in a shared fragment cache context.
        foreach ((array) $_COOKIE as $cookie_name => $cookie_value) {
            if ($cookie_name !== 'wp-wpml_current_language'
                || !is_string($cookie_value)
                || $cookie_value !== (string)apply_filters('wpml_current_language', '')) return false;
        }
        if ($_GET && !self::test_request()) return false;
        foreach (['HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT',
            'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY','HTTP_CF_IPCOUNTRY',
            'HTTP_X_COUNTRY_CODE','HTTP_X_GEOIP_COUNTRY','HTTP_X_WP_NONCE',
            'HTTP_X_REQUESTED_WITH','HTTP_X_MAD4B_PASSPORT',
            'HTTP_X_MAD4B_BY_PASS','HTTP_X_MAD4B_BY_PASS_CACHE',
            'HTTP_X_MAD4B_BYPASS_CACHE'] as $key) {
            if (!empty($_SERVER[$key])) return false;
        }
        // New client header aliases must not silently bypass the market guard.
        if (mad4b_render_query_has_untrusted_market_header())return false;
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '' || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $host) return false;
        if (!class_exists('MAD4B_Stable_Listing_CSS_Identity', false)
            || !MAD4B_Stable_Listing_CSS_Identity::cache_ready()) return false;
        $base_currency = (string)get_option('woocommerce_currency', '');
        if (get_option('woocommerce_default_customer_address', '') !== 'base'
            || get_option('woocommerce_calc_taxes', '') !== 'no'
            || !preg_match('/^[A-Z]{3}$/D', $base_currency)
            || !function_exists('get_woocommerce_currency')) return false;
        $currency=(string)get_woocommerce_currency();
        if (!preg_match('/^[A-Z]{3}$/D',$currency))return false;
        if (is_singular('tours-and-activities')) {
            // Market admission is delegated to the SAME WCML identity and
            // governed partition policy already used by the Query Governor.
            // Never substitute unverified client headers or locale lookups.
            if (!function_exists('mad4b_render_query_market_partition_ready')
                || !mad4b_render_query_market_partition_ready()
                || !function_exists('mad4b_render_query_market_identity'))return false;
            $market=self::fragment_market_identity();
            if (!$market || (string)($market['currency']??'')!==$currency)return false;
        } elseif ($currency!==$base_currency) return false;
        return true;
    }

    /**
     * A WPML translation can inherit the source's audited policy, not its
     * rendered HTML or Elementor CSS. Canonical layout parity ignores only
     * empty CSS-class fields added by WPML and harmless localized labels.
     * Dynamic tags, widget bindings, conditions, IDs and all other settings
     * must remain exactly equivalent to the audited primary template.
     */
    private static array $layoutPlans = [];
    private static array $layoutStats = ['request_hit'=>0,'persistent_hit'=>0,'compiled'=>0];

    public static function layout_plan(string $raw): array {
        if (strlen($raw)<200 || strlen($raw)>50000) return [];
        $rawSha=hash('sha256',$raw);
        $key='layout-v1:'.get_current_blog_id().':'.$rawSha;
        if (isset(self::$layoutPlans[$key])) {
            self::$layoutStats['request_hit']++;
            return self::$layoutPlans[$key];
        }
        $cached=wp_cache_get($key,'mad4b-render-layout-plans');
        if (is_array($cached) && ($cached['source_sha']??'')===$rawSha
            && preg_match('/^[a-f0-9]{64}$/D',(string)($cached['signature']??''))) {
            self::$layoutStats['persistent_hit']++;
            return self::$layoutPlans[$key]=$cached;
        }
        $signature=self::compile_layout_signature($raw);
        if (!$signature) return [];
        $plan=['source_sha'=>$rawSha,'signature'=>$signature];
        // Content-addressed analysis: editing this template invalidates only
        // its descriptor. No global cache purge and no translated HTML reuse.
        wp_cache_set($key,$plan,'mad4b-render-layout-plans',86400);
        self::$layoutStats['compiled']++;
        if (count(self::$layoutPlans)<128) self::$layoutPlans[$key]=$plan;
        return $plan;
    }

    private static function layout_signature(string $raw): string {
        return (string)(self::layout_plan($raw)['signature']??'');
    }

    public static function inherit_listing_data($value, $id, $key, $single, $type) {
        static $resolving=false;
        if ($resolving || $value!==null || $key!=='_listing_data' || !$single) return $value;
        $resolving=true;
        try {
            // Preserve explicitly stored values, including an intentional empty value.
            if (metadata_exists('post',$id,$key)) return $value;
            $post=get_post($id);
            if (!$post || $post->post_type!=='jet-engine' || $post->post_status!=='publish'
                || get_post_meta($id,'_mad4b_render_inheritance_disabled',true)==='1') return $value;
            $trid=apply_filters('wpml_element_trid',null,$id,'post_jet-engine');
            if (!$trid) return $value;
            $members=apply_filters('wpml_get_element_translations',null,$trid,'post_jet-engine');
            $base=(string)apply_filters('wpml_default_language',null);
            $source=(int)($members[$base]->element_id??0);
            if (!$source || $source===(int)$id) return $value;
            $linked=false;
            foreach ((array)$members as $member) {
                if ((int)($member->element_id??0)===(int)$id) $linked=true;
            }
            if (!$linked) return $value;
            $cfg=self::policy()['templates'][(string)$source]??null;
            $master=get_post($source);
            $raw=get_post_meta($source,'_elementor_data',true);
            $local=get_post_meta($id,'_elementor_data',true);
            if (!is_array($cfg) || !$master || $master->post_status!=='publish'
                || !is_string($raw) || !is_string($local)
                || !hash_equals((string)($cfg['elementor_sha256']??''),hash('sha256',$raw))) return $value;
            $signature=self::layout_signature($raw);
            if (!$signature || !hash_equals($signature,self::layout_signature($local))) return $value;
            $data=get_post_meta($source,$key,true);
            if (!is_array($data) || ($data['source']??'')!=='terms'
                || !in_array(($data['tax']??''),(array)($cfg['taxonomies']??[]),true)) return $value;
            return [$data];
        } finally { $resolving=false; }
    }

    public static function family_descriptor(int $id): array {
        $post=get_post($id);
        if (!$post || $post->post_type!=='jet-engine') return [];
        $trid=apply_filters('wpml_element_trid',null,$id,'post_jet-engine');
        $members=$trid?apply_filters('wpml_get_element_translations',null,$trid,'post_jet-engine'):[];
        $base=(string)apply_filters('wpml_default_language',null);
        $source=(int)($members[$base]->element_id??0);
        if (!$source) return ['id'=>$id,'state'=>'unlinked','strategy'=>'native'];
        $raw=get_post_meta($source,'_elementor_data',true);
        $master=is_string($raw)?self::layout_plan($raw):[];
        $out=['trid'=>(string)$trid,'source_id'=>$source,'members'=>[]];
        foreach ((array)$members as $language=>$member) {
            $local=(int)($member->element_id??0);
            $localPost=get_post($local);
            $localRaw=get_post_meta($local,'_elementor_data',true);
            $plan=is_string($localRaw)?self::layout_plan($localRaw):[];
            $disabled=get_post_meta($local,'_mad4b_render_inheritance_disabled',true)==='1';
            $equal=!$disabled && $localPost && $localPost->post_status==='publish'
                && !empty($master['signature']) && ($plan['signature']??'')===$master['signature']
                && get_post_meta($source,'_listing_data',true)===get_post_meta($local,'_listing_data',true);
            $out['members'][$language]=['id'=>$local,'layout_shared'=>$equal,
                'layout_signature'=>$plan['signature']??'',
                'source_sha'=>$plan['source_sha']??'',
                'override'=>$disabled?'disabled':($equal?'localized_content':'local_layout'),
                'html_scope'=>'local_template_and_language'];
        }
        return $out;
    }

    /**
     * Read-only, bounded dependency discovery for Elementor/JetEngine documents.
     * This is an inspection API, not a cache-hit authorizer. It never enqueues
     * assets, disables invalidation, shares HTML, or persists a graph.
     * Unknown references fail closed so a future cache-hit asset provider can
     * require a complete graph before replacing native rendering.
     */
    public static function dependency_index(int $root_id): array {
        $root_id=absint($root_id);
        $out=['contract'=>'mad4b.fragment-dependencies.v1','root'=>$root_id,
            'status'=>'complete','docs'=>[],'edges'=>[],'unresolved'=>[],
            'widget_types'=>[],'asset_css'=>[]];
        if (!$root_id) { $out['status']='unknown';$out['unresolved'][]='invalid_root';return $out; }
        $visited=[];$active=[];$nodes_seen=0;
        $mark=function(string $reason) use (&$out):void {
            $out['status']='unknown';
            if (count($out['unresolved'])<24) $out['unresolved'][]=$reason;
        };
        $visit=function(int $id,int $depth) use (&$visit,&$visited,&$active,&$out,&$nodes_seen,$mark):void {
            if ($depth>5 || count($visited)>=32) { $mark('graph_limit');return; }
            if (isset($active[$id])) { $mark('template_cycle');return; }
            if (isset($visited[$id])) return;
            $post=get_post($id);
            if (!$post || !in_array((string)$post->post_type,['jet-engine','elementor_library'],true)
                || $post->post_status!=='publish') { $mark('missing_or_unpublished_document');return; }
            $raw=get_post_meta($id,'_elementor_data',true);
            if (!is_string($raw) || strlen($raw)<2 || strlen($raw)>600000) {
                $mark('invalid_document_data');return;
            }
            $tree=json_decode($raw,true);
            if (!is_array($tree) || json_last_error()!==JSON_ERROR_NONE) {
                $mark('invalid_document_json');return;
            }
            $visited[$id]=true;$active[$id]=true;
            $out['docs'][$id]=['post_type'=>$post->post_type,
                'content_sha256'=>hash('sha256',$raw)];
            $css=get_post_meta($id,'_elementor_css',true);
            $out['asset_css'][$id]=['digest'=>hash('sha256',maybe_serialize($css)),
                'present'=>is_array($css)];
            $walk=function(array $elements) use (&$walk,&$out,&$nodes_seen,$id,$depth,$visit,$mark):void {
                foreach ($elements as $el) {
                    if (!is_array($el)) { $mark('invalid_element');continue; }
                    if (++$nodes_seen>1000) { $mark('node_limit');return; }
                    $type=(string)($el['widgetType']??$el['elType']??'unknown');
                    $out['widget_types'][$type]=($out['widget_types'][$type]??0)+1;
                    $settings=$el['settings']??[];
                    if (!is_array($settings)) { $mark('invalid_widget_settings');continue; }
                    $reference_keys=[];
                    if (in_array($type,['template','global'],true)) $reference_keys=['template_id'];
                    elseif (str_contains($type,'jet-listing-grid')) $reference_keys=['lisitng_id','listing_id'];
                    foreach ($reference_keys as $field) {
                        if (!array_key_exists($field,$settings)) continue;
                        $target=$settings[$field];
                        if (!(is_int($target) || (is_string($target) && preg_match('/^[1-9][0-9]{0,9}$/D',$target))) || (int)$target<1) {
                            $mark('dynamic_or_invalid_template_reference');continue;
                        }
                        $to=(int)$target;
                        $out['edges'][$id][]=$to;
                        $visit($to,$depth+1);
                    }
                    // JetTabs' native accordion references Elementor templates in
                    // its repeater rather than in widget settings.template_id.
                    // Include these nodes in the existing complete dependency
                    // graph so any asset certificate fails closed after edits.
                    if ($type==='jet-accordion' && isset($settings['toggles'])) {
                        if (!is_array($settings['toggles'])) $mark('invalid_accordion_repeater');
                        else foreach($settings['toggles'] as $toggle) {
                            if(!is_array($toggle)) { $mark('invalid_accordion_item');continue; }
                            $reference=$toggle['item_template_id']??null;
                            if ($reference===null || $reference==='')continue; // inline item
                            if (!(is_int($reference) || (is_string($reference)
                                && preg_match('/^[1-9][0-9]{0,9}$/D',$reference)))) {
                                $mark('dynamic_accordion_template');continue;
                            }
                            if(!empty($toggle['__dynamic__']['item_template_id'])) {
                                $mark('dynamic_accordion_template');continue;
                            }
                            $to=(int)$reference;
                            $out['edges'][$id][]=$to;
                            $visit($to,$depth+1);
                        }
                    }
                    if ($reference_keys && !array_intersect($reference_keys,array_keys($settings))) {
                        $mark('missing_template_reference');
                    }
                    $dynamic=$settings['__dynamic__']??[];
                    if ($reference_keys && !empty($dynamic) && is_array($dynamic)
                        && array_intersect($reference_keys,array_keys($dynamic))) $mark('dynamic_template_binding');
                    if ($type==='shortcode' || $type==='wp-widget-shortcode') {
                        // Shortcodes can render a hidden Elementor document or
                        // register assets without a static template_id field.
                        $mark('unresolved_shortcode_assets');
                    }
                    $children=$el['elements']??[];
                    if (!is_array($children)) $mark('invalid_child_elements');
                    else $walk($children);
                }
            };
            $walk($tree);unset($active[$id]);
        };
        $visit($root_id,0);
        ksort($out['docs'],SORT_NUMERIC);ksort($out['edges'],SORT_NUMERIC);
        foreach ($out['edges'] as &$edges) { $edges=array_values(array_unique($edges));sort($edges,SORT_NUMERIC); }
        unset($edges);
        ksort($out['asset_css'],SORT_NUMERIC);ksort($out['widget_types'],SORT_STRING);
        $out['unresolved']=array_values(array_unique($out['unresolved']));
        $out['manifest_sha256']=hash('sha256',wp_json_encode([
            'docs'=>$out['docs'],'edges'=>$out['edges'],
            'css'=>$out['asset_css'],'status'=>$out['status'],
            'unresolved'=>$out['unresolved']]));
        return $out;
    }

    private static function compile_layout_signature(string $raw): string {
        if (strlen($raw)<200 || strlen($raw)>50000) return '';
        $tree=json_decode($raw,true);
        if (!is_array($tree) || json_last_error()!==JSON_ERROR_NONE) return '';
        $valid=true;
        $normalize=static function($item) use (&$normalize,&$valid) {
            if (!is_array($item)) return $item;
            $result=[];
            foreach ($item as $name=>$value) {
                if ($name==='_css_classes' && $value==='') continue;
                if (in_array($name,['link_label','dynamic_excerpt_more'],true)) {
                    if (!is_string($value) || strlen($value)>250
                        || preg_match('/[<>]/',$value)) {
                        $valid=false;
                        return [];
                    }
                    $result[$name]='__locale_text__';
                    continue;
                }
                $result[$name]=$normalize($value);
            }
            return $result;
        };
        $canonical=$normalize($tree);
        if (!$valid) return '';
        $json=wp_json_encode($canonical);
        return is_string($json)?hash('sha256',$json):'';
    }

    /**
     * The source manifest is authoritative for groups/taxonomies/TTL. Resolve
     * only the current locale's linked listing through the WPML translation
     * table. Never infer a relationship from title or duplicated template IDs.
     */
    private static function templates_for_language(array $masters, string $language): array {
        $map=[];
        $base=(string)apply_filters('wpml_default_language',null);
        if (!preg_match('/^[a-z]{2}$/D',$language)
            || !preg_match('/^[a-z]{2}$/D',$base)) return [];
        foreach ($masters as $sourceKey=>$cfg) {
            $source=absint($sourceKey);
            if (!$source || !is_array($cfg) || empty($cfg['taxonomies'])) continue;
            if ($language===$base) {
                $map[$source]=$cfg;
                continue;
            }
            $trid=apply_filters('wpml_element_trid',null,$source,'post_jet-engine');
            if (!$trid) continue;
            $translations=apply_filters('wpml_get_element_translations',null,$trid,'post_jet-engine');
            if (!is_array($translations)
                || (int)($translations[$base]->element_id??0)!==$source) continue;
            $target=absint($translations[$language]->element_id??0);
            if (!$target) {
                // JetEngine can render the source listing document for a
                // translated public Term when WPML has no translated listing.
                // This is *not* permission to infer arbitrary template links:
                // only an explicitly opted-in, proven source-only family may
                // reuse its own public HTML, still isolated by language/market.
                // Creating a translation disables this fallback automatically.
                if (is_singular('tours-and-activities')
                    && !empty($cfg['tour_public_fragment'])
                    && !empty($cfg['tour_untranslated_fallback'])
                    && $language!==$base
                    && !isset($translations[$language])
                    && !isset($map[$source])) {
                    $cfg['fallback_source_language']=$language;
                    $cfg['inherit_source_id']=$source;
                    $map[$source]=$cfg;
                }
                continue;
            }
            if ($target===$source || isset($map[$target]))continue;
            $cfg['inherit_source_id']=$source;
            $cfg['inherit_language']=$language;
            $cfg['inherit_trid']=(string)$trid;
            $map[$target]=$cfg;
        }
        return $map;
    }

    private static function template_allowed(int $id, array $cfg): bool {
        // An in-request WPML locale switch or a changed group configuration
        // must never reuse a previously approved audit from another context.
        $auditKey=$id.'|'.(string)apply_filters('wpml_current_language',null)
            .'|'.hash('sha256',(string)wp_json_encode($cfg));
        if (array_key_exists($auditKey,self::$audited)) return self::$audited[$auditKey];
        $source=(int)($cfg['inherit_source_id']??$id);
        $sourcePost=get_post($source);
        $sourceRaw=get_post_meta($source,'_elementor_data',true);
        $sourceSha=(string)($cfg['elementor_sha256']??'');
        if (!$sourcePost || $sourcePost->post_status!=='publish'
            || $sourcePost->post_type!=='jet-engine'
            || !is_string($sourceRaw) || strlen($sourceRaw)<200
            || strlen($sourceRaw)>50000
            || !preg_match('/^[a-f0-9]{64}$/D',$sourceSha)
            || !hash_equals($sourceSha,hash('sha256',$sourceRaw))) {
            return self::$audited[$auditKey]=false;
        }
        if ($source===$id) {
            self::$verifiedTemplateSha[$id]=$sourceSha;
            return self::$audited[$auditKey]=true;
        }
        $lang=(string)($cfg['inherit_language']??'');
        $trid=(string)($cfg['inherit_trid']??'');
        $targetPost=get_post($id);
        $targetRaw=get_post_meta($id,'_elementor_data',true);
        $masterData=get_post_meta($source,'_listing_data',true);
        $targetData=get_post_meta($id,'_listing_data',true);
        $srcLayout=self::layout_signature($sourceRaw);
        if (!$targetPost || $targetPost->post_status!=='publish'
            || $targetPost->post_type!=='jet-engine'
            || $lang==='' || $lang===(string)apply_filters('wpml_default_language',null)
            || $lang!==(string)apply_filters('wpml_current_language',null)
            || !$trid
            || (string)apply_filters('wpml_element_trid',null,$id,'post_jet-engine')!==$trid
            || (string)apply_filters('wpml_element_trid',null,$source,'post_jet-engine')!==$trid
            || get_post_meta($source,'_elementor_template_type',true)!=='jet-listing-items'
            || get_post_meta($id,'_elementor_template_type',true)!=='jet-listing-items'
            || !is_array($masterData) || !is_array($targetData)
            || $masterData!==$targetData
            || ($masterData['source']??'')!=='terms'
            || !in_array(($masterData['tax']??''),(array)($cfg['taxonomies']??[]),true)
            || get_post_meta($id,'_mad4b_render_inheritance_disabled',true)==='1'
            || !is_string($targetRaw)
            || !$srcLayout || !hash_equals($srcLayout,self::layout_signature($targetRaw))) {
            return self::$audited[$auditKey]=false;
        }
        self::$verifiedTemplateSha[$id]=hash('sha256',$targetRaw);
        return self::$audited[$auditKey]=true;
    }

    private static function safe_html($html): bool {
        return is_string($html) && strlen($html) >= 60 && strlen($html) <= 200000
            && !preg_match('~<\s*(?:script|form|iframe|input)\b|(?:wp-admin|wp-login|woocommerce-cart|wc_fragments|cart_hash|checkout|customer|billing|shipping|add-to-cart|nonce|data-price|data-rate|data-amount|payment|booking|(?:\b(?:USD|EUR|EGP|GBP)\b)|(?:\$\s*\d))~i', $html);
    }

    public static function stable_css_manifest_digest($stored_css): string {
        // Elementor regenerates _elementor_css['time'] without necessarily
        // changing rules. Regeneration alone must not evict dozens of term
        // fragments; retain every non-volatile CSS metadata field, and the
        // separately verified Elementor template content SHA.
        if (is_array($stored_css)) unset($stored_css['time']);
        return hash('sha256',maybe_serialize($stored_css));
    }

    private static function term_key(WP_Term $term, int $template_id, array $cfg, string $lang): string {
        // The same term/template is rendered repeatedly by JetEngine listings.
        // Cache only during this read-only HTTP request, preserving route, locale,
        // template version, term content and inherited-source identity.
        static $requestKeys = [];
        $route=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $isTour=self::$verifiedTourRoute;
        $market=null;
        if ($isTour) {
            $market=self::fragment_market_identity();
            if (!is_array($market)
                || !preg_match('/^[A-Z]{2}$/D',(string)($market['country']??''))
                || !preg_match('/^[A-Z]{3}$/D',(string)($market['currency']??'')))return '';
        }
        $identity=hash('sha256',wp_json_encode([
            $template_id,$lang,get_locale(),$route,wp_is_mobile(),
            $market,
            $term->term_id,$term->taxonomy,$term->term_taxonomy_id,
            $term->name,$term->slug,$term->description,$term->parent,$term->count,
            (string)($cfg['elementor_sha256']??''),(int)($cfg['inherit_source_id']??$template_id),
            self::$verifiedTemplateSha[$template_id]??''
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (isset($requestKeys[$identity]))return $requestKeys[$identity];
        $post = get_post($template_id);
        $term_url = get_term_link($term);
        if (!is_string($term_url) || !str_starts_with($term_url, 'https://')) return '';
        // JetEngine's get_current_object_unique_id() uses hrtime(); never put
        // that request-volatile selector in a persistent fragment key.
        // 414 normalizes the matching HTML/CSS class with this stable identity.
        $unique = substr(hash('sha256', get_class($term) . ':' . (string)$term->term_id), 0, 16);
        $version = (string)get_option('mad4b_render_query_data_generation', 'initial');
        $site = get_option('mad4b_scp_brand_context_profile_v1', []);
        $kit = absint(get_option('elementor_active_kit'));
        static $template_css = [];
        if (!isset($template_css[$template_id])) {
            $stored_css = get_post_meta($template_id, '_elementor_css', true);
            $template_css[$template_id] = self::stable_css_manifest_digest($stored_css);
        }
        $payload = [
            'contract' => self::VERSION, 'site' => home_url('/'),
            'tenant' => is_array($site) ? ($site['site_uuid'] ?? '') : '',
            'route' => (string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            'lang' => $lang, 'locale' => get_locale(), 'device' => wp_is_mobile() ? 'mobile' : 'desktop',
            'base_currency' => (string)get_option('woocommerce_currency', ''),
            'template' => $template_id,
            // The group policy is inherited, but rendered fragments always
            // have a distinct localized-content SHA, template ID and locale.
            'template_sha' => self::$verifiedTemplateSha[$template_id] ?? '',
            'inherit_source_id' => (int)($cfg['inherit_source_id']??$template_id),
            'inherit_source_sha' => $cfg['elementor_sha256'],
            'template_modified' => $post->post_modified_gmt,
            'template_css' => $template_css[$template_id],
            'term' => [$term->term_id, $term->taxonomy, $term->term_taxonomy_id, $unique,
                $term->name, $term->slug, $term->description, $term->parent, $term->count,
                get_term_meta($term->term_id), $term_url],
            'queried_page' => $isTour ? self::$verifiedTourPost : get_queried_object_id(),
            'generation' => $version,
            'assets' => [get_option('elementor_css_print_method'), get_option('elementor_experiment-e_element_cache'),
                $kit, $kit ? get_post_modified_time('U', true, $kit) : 0,
                defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
                defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : '',
                defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
                (string)wp_get_theme()->get('Version')],
        ];
        // No existing Home cache keys change. Tour Single has its own
        // normalized-CSS contract, on top of route/locale/term/asset versions.
        if ($isTour) {
            $payload['tour_fragment_schema']='mad4b-term-css-fallback-market-v2';
            $payload['wcml_market_country']=$market['country'];
            $payload['wcml_currency']=$market['currency'];
            $payload['market_partition_policy']=(string)get_option(
                'mad4b_render_query_market_partition_mode','hold');
        }
        $encoded = wp_json_encode($payload);
        $digest=is_string($encoded) ? hash('sha256', $encoded) : '';
        if($digest!=='' && count($requestKeys)<128)$requestKeys[$identity]=$digest;
        return $digest;
    }

    /** Request-only scope: a policy may opt in a template for mobile QA
     * without affecting desktops, anonymous visitors, or existing templates. */
    private static function template_request_allowed(array $cfg): bool {
        // A company can certify a public listing for Tour Singles without
        // implicitly enabling that new render path on the Home page.
        if (!empty($cfg['tour_only']) && !self::$verifiedTourRoute)return false;
        if (!empty($cfg['mobile_only']) && !wp_is_mobile()) return false;
        // A read-only QA control to compare the same mobile route with the
        // unmodified native renderer; it never changes public defaults.
        if (!empty($cfg['mobile_only'])
            && ($_SERVER['HTTP_X_MAD4B_INNER_TERMS_QA'] ?? '') === 'native-v1') return false;
        $mode = (string)($cfg['activation'] ?? 'active');
        if ($mode === 'header_canary') {
            return ($_SERVER['HTTP_X_MAD4B_INNER_TERMS_QA'] ?? '') === 'canary-v1';
        }
        return $mode === 'active';
    }

    public static function render($input, $listing_id, callable $native, string $lang) {
        $id = absint($listing_id);
        $cfg = self::$templates[$id] ?? null;
        if (!is_array($cfg) || !self::template_request_allowed($cfg)
            || !self::template_allowed($id, $cfg)) return $native($input, $listing_id);
        $tour=self::$verifiedTourRoute;
        if ($tour && (empty($cfg['tour_public_fragment'])
            || ($cfg['tour_css_identity']??'')!=='jet_term_fallback'))
            return $native($input,$listing_id);
        $term = jet_engine()->listings->data->get_current_object();
        if (!($term instanceof WP_Term) || !in_array($term->taxonomy, (array)($cfg['taxonomies'] ?? []), true)) {
            self::$metrics['reject']++; return $native($input, $listing_id);
        }
        $profile = ($_SERVER['HTTP_X_MAD4B_RENDER_PROFILE'] ?? '') === 'v1';
        $started = $profile ? hrtime(true) : 0;
        $key = self::term_key($term, $id, $cfg, $lang);
        self::cost($id, 'key', $started);
        if (!$key) { self::lifecycle($id,'key_rejected'); return $native($input, $listing_id); }
        $force_native = (self::$qa && ($_SERVER['HTTP_X_MAD4B_FRAGMENT_CONTROL'] ?? '') === 'native-v1')
            || ($tour && ($_SERVER['HTTP_X_MAD4B_TOUR_FRAGMENT_CONTROL']??'')==='native-v1');
        $started = $profile ? hrtime(true) : 0;
        $memo = !$force_native && isset(self::$requestFragments[$key]);
        $found=false;
        $cache = $force_native ? false : ($memo ? self::$requestFragments[$key] : wp_cache_get($key, self::GROUP, false, $found));
        self::lifecycle($id,$force_native?'native_control':($memo?'memo_found':($found?'persistent_found':'absent_unknown_cause')),$key);
        self::cost($id, $memo ? 'memo_lookup' : 'cache_lookup', $started);
        $started = $profile ? hrtime(true) : 0;
        $safe = $memo || self::safe_html($cache);
        if ($safe && $tour) {
            // Revalidate and normalize *the exact cached HTML* before
            // replay, not only the native output on MISS. JetEngine may
            // regenerate inline DCSS selectors after content preparation;
            // a volatile style token must never escape a cached fragment.
            $normalized=class_exists('MAD4B_Stable_Listing_CSS_Identity',false)
                && method_exists('MAD4B_Stable_Listing_CSS_Identity','normalize_public_term_fragment')
                ?MAD4B_Stable_Listing_CSS_Identity::normalize_public_term_fragment($cache,$term,$id):null;
            if (is_string($normalized) && self::safe_html($normalized)) {
                $cache=$normalized;
            } else {
                $safe=false;
                self::$metrics['reject']++;
                self::lifecycle($id,'cache_css_proof_rejected',$key);
            }
        }
        self::cost($id, 'validate', $started);
        if (!$safe && ($memo || $found)) self::lifecycle($id,'cached_html_rejected',$key);
        if ($safe && class_exists('Elementor\Core\Files\CSS\Post')) {
            $started = $profile ? hrtime(true) : 0;
            // A listing renders many cards from the same Elementor template.
            // Enqueue its CSS once per request instead of re-resolving Post CSS.
            static $assetsEnqueued = [];
            if (empty($assetsEnqueued[$id])) {
                $css = \Elementor\Core\Files\CSS\Post::create($id);
                if ($css) {
                    $css->enqueue();
                    $assetsEnqueued[$id]=true;
                }
            }
            if (!empty($assetsEnqueued[$id])) {
                self::cost($id, 'assets', $started);
                self::remember($key, $cache);
                self::lifecycle($id,$memo?'memo_served':'persistent_served',$key,$cache);
                self::$metrics['hit']++;
                self::$metrics['bytes'] += strlen($cache);
                return $cache;
            }
            self::cost($id, 'assets', $started);
        }
        $started = $profile ? hrtime(true) : 0;
        if ($safe) self::lifecycle($id,'assets_unavailable',$key);
        self::lifecycle($id,'build_started',$key);
        $html = $native($input, $listing_id);
        self::lifecycle($id,'build_completed',$key);
        self::cost($id, 'native', $started);
        self::$metrics['miss']++;
        if ($tour && !$force_native) {
            // Normalize only the pre-certified public term fragment.
            // Dynamic CSS without one unambiguous stable selector stays native.
            $normalized=class_exists('MAD4B_Stable_Listing_CSS_Identity',false)
                && method_exists('MAD4B_Stable_Listing_CSS_Identity','normalize_public_term_fragment')
                ? MAD4B_Stable_Listing_CSS_Identity::normalize_public_term_fragment($html,$term,$id) : null;
            if (!is_string($normalized)) {
                self::$metrics['reject']++;
                return $html;
            }
            $html=$normalized;
        }
        $safe = self::safe_html($html);
        if (!$force_native && $safe) {
            self::remember($key, $html);
            $ttl = (int)(self::policy()['ttl_seconds'] ?? 45);
            $started = $profile ? hrtime(true) : 0;
            self::lifecycle($id,'storage_accepted',$key);
            $written=wp_cache_set($key, $html, self::GROUP, $ttl);
            self::lifecycle($id,$written?'write_acknowledged':'write_failed',$key);
            if ($profile && wp_get_environment_type()==='staging' && $written) {
                $verified=false;
                $readback=wp_cache_get($key,self::GROUP,true,$verified);
                self::lifecycle($id,$verified && is_string($readback) && hash_equals($html,$readback)
                    ?'write_readback_verified':'write_readback_failed',$key,$html);
            }
            self::cost($id, 'cache_store', $started);
        } else if (!$safe) { self::$metrics['reject']++; self::lifecycle($id,'built_html_rejected',$key); }
        return $html;
    }

    public static function activate(): void {
        $policy = self::policy();
        if (!$policy || !self::context()) return;
        // Admission is based on the genuine outer WP_Query and canonical Tour
        // path; never re-evaluate it inside nested Elementor/JetEngine loops.
        self::$verifiedTourRoute=is_singular('tours-and-activities')
            && self::tour_fragment_route();
        self::$verifiedTourPost=self::$verifiedTourRoute
            ? absint(get_queried_object_id()):0;
        self::$qa = self::test_request();
        if (($policy['status'] ?? '') !== 'active' && !self::$qa) return;
        if (!self::$qa && (string)get_option('mad4b_render_public_fragments_enabled', '0') !== '1') return;
        if (empty($policy['templates']) || !is_array($policy['templates'])) return;
        $lang=(string)apply_filters('wpml_current_language','');
        // Only the current WPML locale inherits an audited source group.
        // No global cache stores translation maps across companies/locales.
        self::$templates=self::templates_for_language($policy['templates'],$lang);
        if (!self::$templates) return;
        global $wp_filter;
        $hook = $wp_filter['jet-engine/listing/content/elementor'] ?? null;
        if (!($hook instanceof WP_Hook) || count($hook->callbacks[10] ?? []) !== 1) return;
        $native = null;
        foreach ($hook->callbacks[10] as $entry) {
            $cb = $entry['function'];
            if (is_array($cb) && is_object($cb[0])
                && get_class($cb[0]) === 'Jet_Engine_Elementor_Frontend'
                && $cb[1] === 'get_listing_content_cb') $native = $cb;
        }
        if (!$native) return;
        remove_filter('jet-engine/listing/content/elementor', $native, 10);
        add_filter('jet-engine/listing/content/elementor',
            static fn($html, $id) => self::render($html, $id, $native, $lang), 10, 2);
        // QA telemetry was kept only during staging acceptance; no public diagnostic writes.

    }
}
add_action('wp', ['MAD4B_Render_Query_Public_Fragments', 'activate'], 125);
add_filter('get_post_metadata', ['MAD4B_Render_Query_Public_Fragments','inherit_listing_data'], 100, 5);

/**
 * WPML stores taxon permalink results in the persistent
 * icl_tax_permalink_filter group with no TTL. Changing a term, its parent,
 * or its WPML linkage can leave an old-language URL behind even while the
 * term's source language is correct. Clear only this WPML group on
 * destination taxonomy changes; never flush the whole Redis/object cache.
 * This also covers descendants whose path includes the changed ancestor.
 * The explicit action is for governed imports that write relationships in SQL.
 */
function mad4b_render_query_invalidate_wpml_term_links() {
    static $flushed = false;
    if ($flushed || !class_exists('WPML_WP_Cache', false)) return;
    $flushed = true;
    (new WPML_WP_Cache('icl_tax_permalink_filter'))->flush_group_cache(true);
    mad4b_render_query_invalidate(['kind'=>'taxonomy_links','taxonomy'=>'destinations']);
}
foreach (['created_term','edited_term','delete_term'] as $mad4b_term_hook) {
    add_action($mad4b_term_hook, static function($term_id,$tt_id,$taxonomy) {
        if ((string)$taxonomy === 'destinations') mad4b_render_query_invalidate_wpml_term_links();
    }, 50, 3);
}
add_action('mad4b/wpml/term_permalink/invalidate','mad4b_render_query_invalidate_wpml_term_links',50);
// A central Content Job / controlled SQL import already emits this action.
// It only flushes WPML term permalinks when explicitly marked as a taxonomy migration.
add_action('mad4b/render_query/term_taxonomy_migrated','mad4b_render_query_invalidate_wpml_term_links',50);
