<?php
/**
 * Plugin Name: MAD4B Shared Renderer Query Governor
 * Description: Capability-aware, versioned JetEngine data-query caching, independent of tenant IDs and domains.
 * Version: 1.2.5
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mad4b_render_query_private_context() {
    if ( is_admin() || wp_doing_ajax() || is_user_logged_in() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
    if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'MAD4B_RENDER_QUERY_QA' ) ) { return true; }
    foreach ( array_keys( (array) $_GET ) as $key ) {
        if ( ! preg_match( '/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string) $key ) ) { return true; }
    }
    foreach ( array_keys( (array) $_COOKIE ) as $key ) {
        if ( preg_match( '/(?:currency|market|passport|country|booking|checkout|order|cart|customer|session|woocommerce|nonce|token|jet_smart_filters|jet-engine-query)/i', (string) $key ) ) { return true; }
    }
    return false;
}

function mad4b_render_query_policy( $query ) {
    if ( ! is_object( $query ) || empty( $query->id ) || ! isset( $query->query_type ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unidentified' ]; }
    if ( ! wp_using_ext_object_cache() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'no_persistent_backend' ]; }
    if ( 'off' === get_option( 'mad4b_render_query_governor', 'safe' ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'policy_off' ]; }
    if ( mad4b_render_query_private_context() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'private_context' ]; }
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

add_action( 'jet-engine/query-builder/query/after-query-setup', static function ( $query ) {
    if ( ! is_object( $query ) || ! property_exists( $query, 'cache_query' ) ) { return; }
    $decision = mad4b_render_query_policy( $query );
    $query->cache_query = $decision['cache'];
    if ( ! $decision['cache'] ) { $query->cache_expires = 0; return; }
    // Canonical tenant ID is stable across WPML languages; current language is
    // independently included in the group below. Avoid expensive WPML home_url
    // permalink/callstack filters on every JetEngine Query Builder setup.
    $canonical_home = get_option('home','');
    $canonical_home = is_string($canonical_home) && $canonical_home !== ''
        ? untrailingslashit($canonical_home) : home_url('/');
    $site = substr(hash('sha256',get_current_blog_id().'|'.$canonical_home),0,10);
    $language = (string) apply_filters( 'wpml_current_language', null );
    if ( '' === $language ) { $language = determine_locale(); }
    $language = sanitize_key( $language );
    $version = (string) get_option( 'mad4b_render_query_data_generation', 'initial' );
    $version = (string) apply_filters('mad4b/render_query/query_version',$version,$query,$decision);
    $query->cache_query = true;
    $query->cache_expires = $decision['ttl'];
    $query->cache_group = 'mad4b-rg-' . $site . '-' . $language . '-' . (int) $query->id . '-' . substr( md5( $version ), 0, 10 );
}, 100, 1 );

function mad4b_render_query_invalidate($origin = null) {
    if (false === apply_filters('mad4b/render_query/global_invalidation_required',true,$origin))return;
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
        mad4b_render_query_invalidate($source);
    }
}
add_action( 'save_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'before_delete_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'trashed_post', 'mad4b_render_query_changed_post', 40, 1 );
foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
    add_action( $hook, static function ( $meta_id, $post_id, $meta_key ) use ($hook) {
        // Elementor generated stylesheet metadata is a render artifact, not
        // a content/query predicate change. Invalidation belongs to the
        // _elementor_data, page settings, template and save_post hooks.
        // Bumping the global Query generation on every CSS rebuild turns
        // normal frontend traffic into a cache-thrashing feedback loop.
        if(in_array((string)$meta_key,
            ['_edit_lock','_edit_last','_elementor_css'],true))return;
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
add_action( 'set_object_terms', static function ( $id, $terms, $tt_ids, $taxonomy ) {
    $type = get_post_type( $id );
    $tax = get_taxonomy( $taxonomy );
    if ( $tax && ! empty( $tax->public ) && ! ( $type && preg_match( '/(?:order|booking|checkout|payment|customer|reservation|invoice)/i', $type ) ) ) {
        mad4b_render_query_invalidate(['kind'=>'object_terms','post_id'=>(int)$id,'taxonomy'=>(string)$taxonomy]);
    }
}, 40, 4 );
add_action( 'edited_term', static function ($term_id,$tt_id,$taxonomy) {
    mad4b_render_query_invalidate(['kind'=>'taxonomy','taxonomy'=>(string)$taxonomy,'term_id'=>(int)$term_id]);
}, 40, 3 );
add_action( 'deleted_term', static function ($term_id,$tt_id,$taxonomy) {
    mad4b_render_query_invalidate(['kind'=>'taxonomy','taxonomy'=>(string)$taxonomy,'term_id'=>(int)$term_id]);
}, 40, 3 );
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
    private const VERSION = '1.1.0';
    private static array $templates = [];
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

    private static function context(): bool {
        // Both Staging and Production are eligible only when this company's
        // own environment-matched manifest and explicit on-switch are present.
        if (!in_array(wp_get_environment_type(), ['staging', 'production'], true)
            || !wp_using_ext_object_cache()
            || (defined('WP_CLI') && WP_CLI) || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview() || !is_front_page()
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
            'HTTP_X_REQUESTED_WITH','HTTP_X_MAD4B_BY_PASS'] as $key) {
            if (!empty($_SERVER[$key])) return false;
        }
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '' || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $host) return false;
        if (!class_exists('MAD4B_Stable_Listing_CSS_Identity', false)
            || !MAD4B_Stable_Listing_CSS_Identity::cache_ready()) return false;
        $base_currency = (string)get_option('woocommerce_currency', '');
        if (get_option('woocommerce_default_customer_address', '') !== 'base'
            || get_option('woocommerce_calc_taxes', '') !== 'no'
            || !preg_match('/^[A-Z]{3}$/D', $base_currency)
            || (function_exists('get_woocommerce_currency')
                && get_woocommerce_currency() !== $base_currency)) return false;
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
            if (!$target || $target===$source || isset($map[$target])) continue;
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
            'queried_page' => get_queried_object_id(),
            'generation' => $version,
            'assets' => [get_option('elementor_css_print_method'), get_option('elementor_experiment-e_element_cache'),
                $kit, $kit ? get_post_modified_time('U', true, $kit) : 0,
                defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
                defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : '',
                defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
                (string)wp_get_theme()->get('Version')],
        ];
        $encoded = wp_json_encode($payload);
        return is_string($encoded) ? hash('sha256', $encoded) : '';
    }

    /** Request-only scope: a policy may opt in a template for mobile QA
     * without affecting desktops, anonymous visitors, or existing templates. */
    private static function template_request_allowed(array $cfg): bool {
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
        $term = jet_engine()->listings->data->get_current_object();
        if (!($term instanceof WP_Term) || !in_array($term->taxonomy, (array)($cfg['taxonomies'] ?? []), true)) {
            self::$metrics['reject']++; return $native($input, $listing_id);
        }
        $profile = ($_SERVER['HTTP_X_MAD4B_RENDER_PROFILE'] ?? '') === 'v1';
        $started = $profile ? hrtime(true) : 0;
        $key = self::term_key($term, $id, $cfg, $lang);
        self::cost($id, 'key', $started);
        if (!$key) { self::lifecycle($id,'key_rejected'); return $native($input, $listing_id); }
        $force_native = self::$qa && ($_SERVER['HTTP_X_MAD4B_FRAGMENT_CONTROL'] ?? '') === 'native-v1';
        $started = $profile ? hrtime(true) : 0;
        $memo = !$force_native && isset(self::$requestFragments[$key]);
        $found=false;
        $cache = $force_native ? false : ($memo ? self::$requestFragments[$key] : wp_cache_get($key, self::GROUP, false, $found));
        self::lifecycle($id,$force_native?'native_control':($memo?'memo_found':($found?'persistent_found':'absent_unknown_cause')),$key);
        self::cost($id, $memo ? 'memo_lookup' : 'cache_lookup', $started);
        $started = $profile ? hrtime(true) : 0;
        $safe = $memo || self::safe_html($cache);
        self::cost($id, 'validate', $started);
        if (!$safe && ($memo || $found)) self::lifecycle($id,'cached_html_rejected',$key);
        if ($safe && class_exists('Elementor\Core\Files\CSS\Post')) {
            $started = $profile ? hrtime(true) : 0;
            $css = \Elementor\Core\Files\CSS\Post::create($id);
            if ($css) {
                $css->enqueue();
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
