<?php
/**
 * Shared Product Presentation — native Tours catalog request contract (v1).
 * Consumers: JetEngine Query Builder, JetSmartFilters AJAX, WCML, all price tags.
 * This is a public display context, never an authorization or price authority.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

function shared_presentation_catalog_contract_v1(): array {
    $provider = function_exists('shared_presentation_provider_v2')
        ? shared_presentation_provider_v2('tours.archive') : null;
    return $provider ?: [
        'provider' => 'jet-engine/tours_query_archive',
        'query_id' => 5,
        'listing_id' => 25102,
        'taxonomies' => ['destinations', 'tour-types', 'tour-styles'],
    ];
}

function shared_presentation_catalog_url_context_v1($referrer): ?array {
    if (!is_string($referrer) || strlen($referrer) > 1000 || !str_starts_with($referrer, '/') ||
        str_starts_with($referrer, '//') || str_contains($referrer, '#') || str_contains($referrer, '://')) return null;
    $path = wp_parse_url($referrer, PHP_URL_PATH);
    if (!is_string($path) || $path === '') return null;
    $raw_query = wp_parse_url($referrer, PHP_URL_QUERY);
    if ($raw_query !== null && !is_string($raw_query)) return null;
    $params = [];
    if ($raw_query !== null && $raw_query !== '') {
        parse_str($raw_query, $params);
        if (!is_array($params) || count($params) > 12) return null;
        foreach ($params as $key => $value) {
            if (!is_string($key) || !in_array($key,[
                'post_type','dfsb_qa','wcmlc','utm_source','utm_medium','utm_campaign',
                'utm_content','utm_term','gclid','fbclid'
            ],true) || !is_string($value) || strlen($value) > 160) return null;
        }
    }
    $currency = null;
    if (isset($params['wcmlc'])) {
        $currency = strtoupper($params['wcmlc']);
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) return null;
        $wcml = get_option('_wcml_settings', []);
        if (!is_array($wcml) || !isset($wcml['currency_options'][$currency]) ||
            !array_key_exists($currency, get_woocommerce_currencies())) return null;
    }
    if (isset($params['post_type']) || isset($params['dfsb_qa'])) {
        if ($path !== '/' || ($params['post_type'] ?? '') !== 'tours-and-activities' ||
            (isset($params['dfsb_qa']) && $params['dfsb_qa'] !== '1')) return null;
        return ['path'=>$path,'currency'=>$currency,'language'=>'en','facets'=>[]];
    }
    // Accept at most one native Tax facet bundle and one qualified public
    // price Meta range, in either order. Other meta fields are not trusted
    // display-context inputs. Keep the original JetSmartFilters provider.
    if (!preg_match('~^/(?:((?:ar|de|it|es|fr))/)?tours-and-activities(?:/jsf/jet-engine:tours_query_archive((?:/(?:tax|meta)/[^/?#]+){0,2}))?(?:/pagenum/([1-9][0-9]{0,3}))?/$~D',$path,$m)) return null;
    $facets = [];
    $facetOperators = [];
    $priceRange = null;
    $contract = shared_presentation_catalog_contract_v1();
    $parts = (string)($m[2] ?? '');
    $tokens = $parts !== ''
        ? preg_split('~/(tax|meta)/~',$parts,-1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE)
        : [];
    if (!is_array($tokens) || count($tokens)>4 || count($tokens)%2!==0) return null;
    $seen = [];
    for ($i=0;$i<count($tokens);$i+=2) {
        $type=$tokens[$i];$value=$tokens[$i+1];
        if (!in_array($type,['tax','meta'],true) || isset($seen[$type])) return null;
        $seen[$type]=true;
        if ($type === 'meta') {
            if (!preg_match('/^start_price!range:([0-9]{1,8}(?:\\.[0-9]{1,2})?)_([0-9]{1,8}(?:\\.[0-9]{1,2})?)$/D',$value,$range)) return null;
            if ((float)$range[1]>(float)$range[2]) return null;
            $priceRange=['min'=>(float)$range[1],'max'=>(float)$range[2]];
            continue;
        }
        $taxTokens=explode(';',$value);
        if (count($taxTokens)>count($contract['taxonomies'])) return null;
        foreach ($taxTokens as $token) {
            if (!preg_match('/^([a-z-]+):([a-z0-9_-]{1,80}(?:,[a-z0-9_-]{1,80}){0,9})(?:,(operator_(?:AND|IN)))?$/D',$token,$part)) return null;
            $taxonomy=$part[1];$slugs=explode(',',$part[2]);
            // Explicit operators are native JetSmartFilters grammar, not term
            // slugs. Reject incompatible single-term operators and any unknown
            // suffix rather than silently changing the selected facet.
            $operator=(string)($part[3]??'');
            if ($operator!=='' && count($slugs)<2) return null;
            if ($operator!=='') $facetOperators[$taxonomy]=substr($operator,strlen('operator_'));
            if (!in_array($taxonomy,$contract['taxonomies'],true) || isset($facets[$taxonomy])) return null;
            foreach($slugs as $slug) {
                if (!get_term_by('slug',$slug,$taxonomy)) return null;
                if ($taxonomy==='destinations'
                    && function_exists('shared_presentation_native_destination_sale_authorized_v1')
                    && !shared_presentation_native_destination_sale_authorized_v1($slug)) return null;
            }
            $facets[$taxonomy]=$slugs;
        }
    }
    return ['path'=>$path,'currency'=>$currency,
        'language'=>($m[1]??'') ?: 'en','facets'=>$facets,
        'facet_operators'=>$facetOperators,
        'price_range'=>$priceRange,
        'page'=>isset($m[3]) && $m[3]!=='' ? (int)$m[3] : 1];
}

function shared_presentation_catalog_jsf_request_v1(): ?array {
    if (!wp_doing_ajax() || ($_POST['action'] ?? '') !== 'jet_smart_filters') return null;
    $contract=shared_presentation_catalog_contract_v1();
    if (($_POST['provider'] ?? '') !== $contract['provider']) return null;
    $settings=$_POST['settings'] ?? null;
    if (!is_array($settings) || (string)($settings['lisitng_id'] ?? '') !== (string)$contract['listing_id'] ||
        (string)($settings['custom_query_id'] ?? '') !== (string)$contract['query_id'] ||
        !preg_match('/^[a-f0-9]{32}$/i',(string)($settings['jsf_signature'] ?? ''))) return null;
    $referrer=$_POST['referrer']['uri'] ?? null;
    $context=shared_presentation_catalog_url_context_v1($referrer);
    if (!$context) return null;
    // The new signed facet values are checked by native JetSmartFilters. Validate
    // shape here too: do not convert unknown providers into archive authority.
    $q=$_POST['query'] ?? [];
    if (!is_array($q)) return null;
    $n=0;
    foreach ($q as $key=>$val) {
        if (!is_string($key) || !str_starts_with($key,'_tax_query_')) continue;
        $taxonomy=substr($key,strlen('_tax_query_'));
        if (!in_array($taxonomy,$contract['taxonomies'],true) || ++$n > count($contract['taxonomies'])) return null;
        $values = is_array($val) ? array_values($val) : (is_scalar($val) ? explode(',',(string)$val) : []);
        if (!$values || count($values)>11) return null;
        $operatorCount=0;
        $terms=0;
        foreach ($values as $position=>$v) {
            if (!is_scalar($v) || strlen((string)$v)>80) return null;
            $text=trim((string)$v);
            if ($text==='') continue; // Native filter reset clears this facet.
            if (str_starts_with($text,'operator_')) {
                if (!in_array($text,['operator_AND','operator_IN'],true)
                    || $position!==count($values)-1 || ++$operatorCount!==1) return null;
                continue;
            }
            $term=ctype_digit($text)?get_term((int)$text,$taxonomy):get_term_by('slug',$text,$taxonomy);
            if (!$term instanceof WP_Term) return null;
            $terms++;
        }
        if ($operatorCount && $terms<2) return null;
    }
    return $context;
}

add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    $contract=shared_presentation_catalog_contract_v1();
    if (!is_array($args) || (int)($query->id ?? 0) !== $contract['query_id'] ||
        ($args['jet_smart_filters'] ?? '') !== $contract['provider'] ||
        !is_array($args['tax_query'] ?? null)) return $args;
    $language=apply_filters('wpml_current_language',null);
    if (is_string($language) && preg_match('/^[a-z]{2}$/D',$language)) {
        foreach ($args['tax_query'] as $index=>$row) {
            if (!is_array($row) || !in_array($row['taxonomy'] ?? '',$contract['taxonomies'],true) ||
                ($row['field'] ?? 'term_id') !== 'term_id' || !is_array($row['terms'] ?? null))continue;
            $ids=[];
            foreach($row['terms'] as $raw){
                $term=is_scalar($raw)&&ctype_digit((string)$raw)?get_term((int)$raw,$row['taxonomy']):null;
                $translation=$term instanceof WP_Term
                    ?apply_filters('wpml_object_id',$term->term_id,$row['taxonomy'],false,$language):null;
                $mapped=$translation?get_term((int)$translation,$row['taxonomy']):null;
                $ids[]=$mapped instanceof WP_Term?(int)$mapped->term_id:0;
            }
            $args['tax_query'][$index]['terms']=array_values(array_unique($ids));
        }
    }
    $taxonomies=[];
    foreach ($args['tax_query'] as $row)
        if (is_array($row) && in_array($row['taxonomy'] ?? '',$contract['taxonomies'],true) && !empty($row['terms']))
            $taxonomies[]=$row['taxonomy'];
    if (count(array_unique($taxonomies)) > 1) {
        $args['tax_query']['relation']='AND';
        $args['tax_query_relation']='and';
    }
    return $args;
}, 2000, 2);

add_filter('wcml_client_currency',static function($selected){
    $currency=null;
    if (wp_doing_ajax()) {
        $context=shared_presentation_catalog_jsf_request_v1();
        $currency=$context['currency'] ?? null;
    } elseif (!is_admin() && isset($_GET['wcmlc']) && is_string($_GET['wcmlc'])) {
        $currency=strtoupper(trim(wp_unslash($_GET['wcmlc'])));
    }
    if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D',$currency)) return $selected;
    $wcml=get_option('_wcml_settings',[]);
    if (!is_array($wcml) || (int)($wcml['enable_multi_currency'] ?? 0)!==2 ||
        !isset($wcml['currency_options'][$currency]) ||
        !array_key_exists($currency,get_woocommerce_currencies())) return $selected;
    return $currency;
}, 1000);

// The real archive provider Query Builder items cache does not encode all
// authored layout variants, JSF state and commercial/visibility policy in its
// cache key. A stale empty result was shared between List and Grid AJAX.
 // Disable only this registered and contract-bound Tours query's *items* cache.
 // Redis object caching and other JetEngine query caches stay untouched.
add_action('jet-engine/query-builder/after-queries-setup',static function():void {
    $contract=shared_presentation_catalog_contract_v1();
    $id=absint($contract['query_id']??0);
    $provider=(string)($contract['provider']??'');
    if (!$id || !str_starts_with($provider,'jet-engine/')) return;
    $expectedCustomId=substr($provider,strlen('jet-engine/'));
    $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($id);
    if (!is_object($query) || (int)($query->id??0)!==$id
        || (string)($query->query_id??'')!==$expectedCustomId
        || !property_exists($query,'cache_query')) return;
    $query->cache_query=false;
},5);

// A signed, live JetSmartFilters request has dynamic provider/visibility
// context that cannot be represented by JetEngine's perpetual query hash.
// Do not reuse a stale *items* cache for this exact native Tours provider.
// This is not a capability grant and does not disable Redis site-wide.
add_action('jet-engine/query-builder/filters/before-set-props',static function($query):void {
    if (!is_object($query) || !property_exists($query,'cache_query')) return;
    $contract=shared_presentation_catalog_contract_v1();
    if ((int)($query->id??0)!==(int)($contract['query_id']??0)
        || (string)($query->query_id??'')!==substr((string)($contract['provider']??''),strlen('jet-engine/'))
        || !is_array(shared_presentation_catalog_jsf_request_v1())) return;
    $query->cache_query=false;
},15,1);

// Direct-load pagination must apply consistently to EVERY instance of the
// native catalog Query #5 (desktop, mobile and counting providers). JSF
// already sets some instances to the right page; fill in those it misses.
add_filter('jet-engine/query-builder/types/posts-query/args', static function ($args,$query) {
    $contract=shared_presentation_catalog_contract_v1();
    if (!is_array($args) || (int)($query->id??0)!==(int)$contract['query_id'] || wp_doing_ajax()) return $args;
    // One archive renders this query through several Elementor/JetEngine
    // consumers; only one of them carries the provider flag on initial GET.
    $context=shared_presentation_catalog_url_context_v1($_SERVER['REQUEST_URI']??'');
    if (!is_array($context)) return $args;
    if (!empty($context['facets'])) {
        if (!is_array($args['tax_query']??null)) $args['tax_query']=[];
        $seen=[];
        foreach ($args['tax_query'] as $clause) {
            if (is_array($clause) && is_string($clause['taxonomy']??null)) $seen[$clause['taxonomy']]=true;
        }
        foreach ($context['facets'] as $taxonomy=>$slugs) {
            if (!in_array($taxonomy,$contract['taxonomies'],true) || isset($seen[$taxonomy])) continue;
            $ids=[];
            foreach ((array)$slugs as $slug) {
                $term=get_term_by('slug',$slug,$taxonomy);
                $translated=$term instanceof WP_Term
                    ?apply_filters('wpml_object_id',$term->term_id,$taxonomy,false,$context['language']):null;
                $valid=$translated?get_term((int)$translated,$taxonomy):null;
                $ids[]=$valid instanceof WP_Term?(int)$valid->term_id:0;
            }
            $operator=(string)($context['facet_operators'][$taxonomy]??'IN');
            if (!in_array($operator,['AND','IN'],true)) return $args;
            $args['tax_query'][]=['taxonomy'=>$taxonomy,'field'=>'term_id','terms'=>array_values(array_unique($ids)),'operator'=>$operator];
        }
        $args['tax_query']['relation']='AND';
        $args['tax_query_relation']='and';
    }
    if (($context['page']??1)>1) $args['paged']=(int)$context['page'];
    return $args;
}, 2040, 2);

// The vendor creates pagination hrefs from a pathname+search concat. A scoped
// native-link adapter repairs href syntax; the JSF click/AJAX handlers remain
// untouched, as do other pagination widgets and WordPress links.
add_action('wp_enqueue_scripts',static function(){
    if (!shared_presentation_catalog_url_context_v1($_SERVER['REQUEST_URI']??''))return;
    wp_enqueue_script('shared-catalog-pagination-url-v1',content_url('/mu-plugins/assets/catalog-pagination-url-v1.js'),[], '20261028-2',true);
},70);
