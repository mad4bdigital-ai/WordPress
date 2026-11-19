<?php
defined('ABSPATH') || exit;

/**
 * Read-only site-scoped, published tour catalog for MAD4B admin preview.
 * Never invokes a quote or changes WordPress post/meta/JetEngine data.
 */
final class MAD4B_JetEngine_Published_Tours_Preview_V1 {
    public const AJAX_ACTION = 'mad4b_jetengine_published_tours_v1';
    private const POST_TYPE = 'tours-and-activities';
    private const PAGE_SIZE = 8;
    private const TAXONOMIES = [
        'destinations',
        'tour-types',
        'tour-styles',
        'duration-options',
        'good-for',
        'package_levels',
        'tour_categories',
        'season',
    ];
    public static function boot(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_list']);
    }
    private static function allowed_taxonomies(): array {
        return MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1::taxonomies();
    }
    public static function facets(array $languages=[]): array {
        $registry = 'MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $lang=$languages?:$registry::selected_languages([]);
        $facets = array_merge($registry::taxonomy_facets($lang), $registry::meta_facets($lang));
        $relations = self::add_catalog_facets([], $lang);
        foreach ($relations as &$item) {
            $item['source']='relation';
            $item['kind']='multi';
        }
        unset($item);
        $all = array_merge($facets, $relations);
        return apply_filters('mad4b/jetengine/published_preview/facets', $all);
    }
    /** Published Rate -> parent Tour mapping. A small bounded index, cached for five minutes. */
    private static function rate_index(): array {
        $cached = get_transient('mad4b_published_tour_rate_index_v1');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID AS rate_id, pm.meta_value AS tour_id FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='related_tour_id'
             WHERE p.post_type='tour-rates' AND p.post_status='publish'
             ORDER BY p.ID DESC LIMIT 3500",
            ARRAY_A
        );
        $index = [];
        foreach ((array)$rows as $row) {
            $rate = absint($row['rate_id'] ?? 0);
            $tour = absint($row['tour_id'] ?? 0);
            if ($rate && $tour) $index[$rate] = $tour;
        }
        set_transient('mad4b_published_tour_rate_index_v1', $index, 300);
        return $index;
    }
    private static function list_ids($raw, array $allowed): array {
        if (!is_array($raw)) return [];
        return array_values(array_unique(array_filter(array_map('strval',
            array_slice($raw, 0, 32)), static fn($v)=>isset($allowed[$v]))));
    }
    private static function decode_ids($raw): array {
        if (is_string($raw)) $raw = maybe_unserialize($raw);
        if (is_string($raw)) $raw = preg_split('/[,\s]+/', $raw);
        if (!is_array($raw)) return [];
        $values = [];
        foreach (array_slice($raw, 0, 80) as $item) {
            if (!is_scalar($item)) continue;
            $id = absint($item);
            if ($id && !in_array($id, $values, true)) $values[] = $id;
        }
        return $values;
    }
    private static function enabled(int $tour, string $key): bool {
        return (string)get_post_meta($tour, $key, true) === '1';
    }
    private static function linked_extras(int $tour): array {
        if (!self::enabled($tour, 'tour_extras_status')) return [];
        if (function_exists('shared_product_optional_tour_ids_for_tour')) {
            $verified = shared_product_optional_tour_ids_for_tour($tour);
            if (is_wp_error($verified)) return [];
            return array_values(array_slice(array_map('absint', $verified), 0, 25));
        }
        $ids = self::decode_ids(get_post_meta($tour, 'tour_optional_extras', true));
        return array_values(array_filter($ids, static function($id) use ($tour) {
            return $id!==$tour && get_post_status($id)==='publish'
                && get_post_type($id)===self::POST_TYPE
                && in_array(get_post_meta($id,'tour_mode',true), ['activity','extra_trip'], true);
        }));
    }
    private static function linked_transports(int $tour): array {
        $mapping = [
            'vehicles'=>['tour_vehicles_transport_status','vehicles_transport_services'],
            'train'=>['tour_train_transport_status','train_transport_services'],
            'air'=>['tour_air_transport_status','air_transport_services'],
        ];
        $found=[];
        foreach ($mapping as $kind => [$enabled, $key]) {
            if (!self::enabled($tour, $enabled)) continue;
            $ids = self::decode_ids(get_post_meta($tour, $key, true));
            $posts = [];
            foreach (array_slice($ids, 0, 15) as $id) {
                if (get_post_type($id)!=='transportations' || get_post_status($id)!=='publish') continue;
                $posts[]=['id'=>$id,'title'=>sanitize_text_field(get_the_title($id))];
            }
            $found[$kind]=$posts;
        }
        return $found;
    }
    private static function add_catalog_facets(array $base, array $languages=[]): array {
        global $wpdb;
        // Derive counts from this admin session's WPML-visible published tours.
        // The full database can contain multiple translations of the same tour.
        $registry='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $visible_tours=array_fill_keys($registry::language_post_ids($languages?:$registry::selected_languages([])),true);
        $visible_ids = array_keys($visible_tours);
        $in = implode(',', array_map('absint', $visible_ids));
        $raw = $in !== '' ? $wpdb->get_results(
            "SELECT pm.meta_value AS mode, COUNT(DISTINCT p.ID) AS total
             FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm
               ON pm.post_id=p.ID AND pm.meta_key='tour_mode'
             WHERE p.ID IN ({$in}) AND p.post_type='tours-and-activities' AND p.post_status='publish'
             GROUP BY pm.meta_value ORDER BY total DESC LIMIT 30",
            ARRAY_A
        ) : [];
        $modes = [];
        foreach ((array)$raw as $item) {
            $mode = sanitize_key((string)($item['mode'] ?? ''));
            if ($mode==='') continue;
            $modes[]=['id'=>$mode,'name'=>ucwords(str_replace('_',' ',$mode)),
                'count'=>max(0,(int)$item['total'])];
        }
        // Tour Mode is now exposed once as a JetEngine CPT Meta Field.
        $rate_index = self::rate_index();
        $with_rates = array_fill_keys(array_values(array_filter(array_unique(array_values($rate_index)),
            static fn($id)=>isset($visible_tours[(int)$id]))), true);
        $base[]=['id'=>'tour-rate','label'=>'Tour Rate','terms'=>[
            ['id'=>'has','name'=>'Has published Rate','count'=>count($with_rates)],
            ['id'=>'none','name'=>'Without published Rate','count'=>max(0,count($visible_tours)-count($with_rates))],
        ]];
        // Concrete published Rate IDs are selectable independently of the availability toggle.
        // A single sidebar should not render thousands of checkbox options on large companies.
        $rate_terms=[];
        foreach ($rate_index as $rate_id=>$tour_id) {
            if (count($rate_terms)>=240) break;
            if (!isset($visible_tours[(int)$tour_id]) ||
                get_post_status((int)$tour_id)!=='publish'
                || get_post_type((int)$tour_id)!==self::POST_TYPE) continue;
            $tour_title=wp_strip_all_tags(get_the_title((int)$tour_id));
            $rate_terms[]=[
                'id'=>(int)$rate_id,
                'name'=>'#'.(int)$rate_id.' · '.(function_exists('mb_substr')
                    ? mb_substr($tour_title,0,45) : substr($tour_title,0,45)),
                'count'=>1,
            ];
        }
        if ($rate_terms) $base[]=['id'=>'rate-ids','label'=>'Specific Tour Rates','terms'=>$rate_terms];
        $extras_count=0;
        $transport_count=['vehicles'=>0,'train'=>0,'air'=>0,'none'=>0];
        foreach ($visible_ids as $id) {
            if (self::enabled((int)$id, 'tour_extras_status')) $extras_count++;
            $transport_map=[
                'vehicles'=>'tour_vehicles_transport_status',
                'train'=>'tour_train_transport_status',
                'air'=>'tour_air_transport_status',
            ];
            $one=false;
            foreach ($transport_map as $kind=>$key) {
                if (self::enabled((int)$id, $key)) {
                    $transport_count[$kind]++;
                    $one=true;
                }
            }
            if (!$one) $transport_count['none']++;
        }
        $base[]=['id'=>'extras','label'=>'Extras','terms'=>[
            ['id'=>'enabled','name'=>'Extras enabled','count'=>$extras_count],
            ['id'=>'none','name'=>'No extras enabled','count'=>max(0,count($visible_ids)-$extras_count)],
        ]];
        $base[]=['id'=>'transport','label'=>'Transport','terms'=>[
            ['id'=>'vehicles','name'=>'Vehicles enabled','count'=>$transport_count['vehicles']],
            ['id'=>'train','name'=>'Train enabled','count'=>$transport_count['train']],
            ['id'=>'air','name'=>'Air enabled','count'=>$transport_count['air']],
            ['id'=>'none','name'=>'No transport enabled','count'=>$transport_count['none']],
        ]];
        return $base;
    }
    private static function parse_filters($requested): array {
        if (!is_array($requested)) return [];
        $out = [];
        $allowed = self::allowed_taxonomies();
        foreach ($requested as $taxonomy => $ids) {
            if (!is_string($taxonomy) || !isset($allowed[$taxonomy]) || !is_array($ids)) continue;
            $clean = array_values(array_unique(array_filter(array_map('absint', array_slice($ids, 0, 24)))));
            if ($clean) $out[$taxonomy] = $clean;
            if (count($out) >= 8) break;
        }
        // Site-independent functional facets support mixed taxonomy and metadata layers.
        if (isset($requested['tour-mode']) && is_array($requested['tour-mode'])) {
            $modes = [];
            foreach (array_slice($requested['tour-mode'], 0, 24) as $mode) {
                if (is_string($mode) && preg_match('/^[a-z][a-z0-9_]{1,40}$/D', $mode)) {
                    $modes[$mode] = $mode;
                }
            }
            if ($modes) $out['tour-mode'] = array_values($modes);
        }
        foreach (['tour-rate'=>['has'=>1,'none'=>1], 'extras'=>['enabled'=>1,'none'=>1],
                  'transport'=>['vehicles'=>1,'train'=>1,'air'=>1,'none'=>1]] as $key=>$choices) {
            $values = self::list_ids($requested[$key]??[], $choices);
            if ($values) $out[$key] = $values;
        }
        $rate_ids = self::list_ids($requested['rate-ids']??[], array_fill_keys(
            array_map('strval', array_keys(self::rate_index())), 1));
        if ($rate_ids) $out['rate-ids'] = array_map('intval', array_slice($rate_ids, 0, 24));
        $meta = MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1::meta_requested($requested);
        foreach ($meta as $name=>$config) $out['meta:'.$name] = $config;
        return $out;
    }
    private static function thumbnail(int $post_id): string {
        $url = get_the_post_thumbnail_url($post_id, 'thumbnail');
        return is_string($url) ? esc_url_raw($url) : '';
    }
    /**
     * Read-only source Rate matrix. A configured amount is not a validated
     * booking quote: eligibility remains with the shared pricing orchestrator.
     * Allows a deterministic pure test without changing any live Rate.
     */
    public static function price_matrix_from_fields(array $fields, ?int $at = null): array {
        $at = $at ?? current_time('timestamp', true);
        $currency = static function ($value): ?string {
            $code = strtoupper(trim((string)$value));
            return preg_match('/^[A-Z]{3}$/D', $code) ? $code : null;
        };
        $money = static function ($raw): ?float {
            if (!is_scalar($raw) || !is_numeric($raw)) return null;
            $value = (float)$raw;
            return is_finite($value) && $value > 0 && $value <= 1000000000
                ? round($value, 4) : null;
        };
        $start = (int)($fields['tour_rate_start_date'] ?? 0);
        $end = (int)($fields['tour_rate_end_date'] ?? 0);
        $reason = null;
        if ((string)($fields['stop_sale'] ?? '') === '1') $reason = 'stop_sale';
        elseif ((string)($fields['avilabile_status'] ?? '') === '0') $reason = 'unavailable';
        elseif ($start && $at < $start) $reason = 'not_started';
        elseif ($end && $at > $end) $reason = 'expired';
        elseif ($start && $end && $end < $start) $reason = 'invalid_period';
        $salesEnabled = (string)($fields['is_on_sale'] ?? '') === '1';
        $groups = [];
        foreach (['international'=>'', 'egyptians'=>'egyptians_'] as $audience => $prefix) {
            $enabled = $audience === 'international'
                || (string)($fields['has_egyptians_price'] ?? '') === '1';
            $cur = $currency($fields[$prefix === '' ? 'base_currency' : 'egyptians_currency'] ?? '');
            $cells = [];
            $count = 0;
            foreach (['single','double','triple'] as $occupancy) {
                $regular = $money($fields[$prefix.$occupancy.'_price'] ?? null);
                $sale = $money($fields[$prefix.$occupancy.'_sale_price'] ?? null);
                $hasSale = $salesEnabled && $regular !== null && $sale !== null
                    && $sale < $regular;
                $sale = $hasSale ? $sale : null;
                $state = !$enabled ? 'audience_disabled'
                    : ($regular === null ? 'price_missing'
                    : ($cur === null ? 'currency_missing' : 'configured'));
                if ($state === 'configured') $count++;
                $cells[$occupancy] = [
                    'status' => $state,
                    'regular' => $enabled ? $regular : null,
                    'sale' => $enabled && $state === 'configured' ? $sale : null,
                    'amount' => $enabled && $state === 'configured'
                        ? ($sale ?? $regular) : null,
                    'discounted' => $enabled && $state === 'configured' && $hasSale,
                    'source' => $prefix.$occupancy.'_price',
                    'sale_source' => $enabled && $hasSale
                        ? $prefix.$occupancy.'_sale_price' : null,
                ];
            }
            $groups[$audience] = [
                'enabled' => $enabled,
                'currency' => $cur,
                'configured_count' => $count,
                'occupancies' => $cells,
                'reference_only' => true,
            ];
        }
        return [
            'groups' => $groups,
            'rate_state' => $reason ?? 'not_checkout_verified',
            'sale_enabled' => $salesEnabled,
            'source' => 'published_tour_rate_fields',
            'reference_only' => true,
            'checkout_ready' => false,
        ];
    }

    private static function related_rates(int $tour): array {
        $index = self::rate_index();
        $ids = [];
        foreach ($index as $id=>$parent) {
            if ($parent === $tour) $ids[] = (int)$id;
            if (count($ids)>=6) break;
        }
        $out=[];
        foreach ($ids as $id) {
            $currency=strtoupper((string)get_post_meta($id,'base_currency',true));
            $amount=get_post_meta($id,'double_price',true);
            $valid=is_numeric($amount) && (float)$amount>0
                && preg_match('/^[A-Z]{3}$/D',$currency);
            $start=get_post_meta($id,'tour_rate_start_date',true);
            $end=get_post_meta($id,'tour_rate_end_date',true);
            $keys=['base_currency','egyptians_currency','has_egyptians_price',
                'single_price','double_price','triple_price',
                'egyptians_single_price','egyptians_double_price','egyptians_triple_price',
                'is_on_sale','stop_sale','avilabile_status',
                'tour_rate_start_date','tour_rate_end_date'];
            foreach (['','egyptians_'] as $pfx) {
                foreach (['single','double','triple'] as $occ) $keys[]=$pfx.$occ.'_sale_price';
            }
            $fields=[];
            foreach ($keys as $key) $fields[$key]=get_post_meta($id,$key,true);
            $matrix=self::price_matrix_from_fields($fields);
            $seasonResolution=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::resolve($id);
            $out[]=[
                'id'=>$id, 'title'=>sanitize_text_field(get_the_title($id)),
                'canonical_title'=>class_exists('MAD4B_JetEngine_Rate_Title_Override_V1')
                    ?MAD4B_JetEngine_Rate_Title_Override_V1::title($id):null,
                'season'=>$seasonResolution['label'],
                'season_status'=>$seasonResolution['status'],
                'season_source'=>$seasonResolution['source'],
                'season_term_id'=>$seasonResolution['term_id'],
                // Currency is native Rate metadata even if a particular
                // occupancy (including Double) has no price configured.
                'base_currency'=>preg_match('/^[A-Z]{3}$/D',$currency)?$currency:null,
                'double_price'=>$valid?(float)$amount:null,
                'currency'=>$valid?$currency:null,
                'price_matrix'=>$matrix,
                'start'=>is_numeric($start)&&$start>0?gmdate('Y-m-d',(int)$start):null,
                'end'=>is_numeric($end)&&$end>0?gmdate('Y-m-d',(int)$end):null,
                'source'=>'published_tour_rate_not_final_quote',
            ];
        }
        return $out;
    }
    private static function get_tour(WP_Post $post): array {
        $id = (int)$post->ID;
        $start = get_post_meta($id, 'start_price', true);
        $currency = strtoupper((string)get_post_meta($id, 'tour_base_currency', true));
        $has_amount = is_numeric($start) && is_finite((float)$start) && (float)$start > 0
            && preg_match('/^[A-Z]{3}$/D', $currency);
        $assigned = [];
        foreach (self::TAXONOMIES as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $terms = wp_get_post_terms($id, $tax, ['fields' => 'names']);
            if (!is_wp_error($terms) && is_array($terms) && $terms) {
                $assigned[$tax] = array_map('sanitize_text_field', array_slice($terms, 0, 5));
            }
        }
        return [
            'id' => $id,
            'title' => html_entity_decode(wp_strip_all_tags(get_the_title($id)), ENT_QUOTES, 'UTF-8'),
            'link' => esc_url_raw(get_permalink($id)),
            'thumbnail' => self::thumbnail($id),
            'summary' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 24, '…'),
            'published' => true,
            'language' => sanitize_key((string)apply_filters('wpml_element_language_code', '',
                ['element_id'=>$id,'element_type'=>'post_'.self::POST_TYPE])),
            'starting_price' => $has_amount ? round((float)$start, 2) : null,
            'currency' => $has_amount ? $currency : null,
            'price_source' => $has_amount ? 'published_tour_start_price' : 'unavailable',
            'price_unit' => sanitize_text_field((string)get_post_meta($id, 'tour_price_unit', true)),
            'tour_mode' => sanitize_key((string)get_post_meta($id, 'tour_mode', true)),
            'tour_rates' => self::related_rates($id),
            'extras' => array_map(static function($extra) {
                return ['id'=>$extra,'title'=>sanitize_text_field(get_the_title($extra))];
            }, self::linked_extras($id)),
            'transport' => self::linked_transports($id),
            'terms' => $assigned,
        ];
    }
    /**
     * Existing Pricing Workspace tour-preview card, scoped to one published
     * source. Consumed by authorized Woo trip-order preview without copying
     * taxonomy, Rate, image or pricing-preview presentation semantics.
     * A starting price / Rate matrix is never an approved checkout quote.
     */
    public static function preview_one(int $postId): array|WP_Error {
        if($postId<=0 || get_post_type($postId)!==self::POST_TYPE ||
           get_post_status($postId)!=='publish')
            return new WP_Error('preview_source_unavailable');
        $post=get_post($postId);
        if(!$post instanceof WP_Post)return new WP_Error('preview_source_unavailable');
        return self::get_tour($post);
    }
    public static function query(array $request): array {
        $filters = self::parse_filters($request['filters'] ?? []);
        $search = is_string($request['search'] ?? null)
            ? sanitize_text_field($request['search']) : '';
        $search = function_exists('mb_substr') ? mb_substr($search, 0, 90) : substr($search, 0, 90);
        $page = min(100, max(1, absint($request['page'] ?? 1)));
        $sort = (string)($request['sort'] ?? 'newest');
        if (!in_array($sort, ['newest', 'title'], true)) $sort = 'newest';
        $registry = 'MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $languages = $registry::selected_languages($request['languages']??[]);
        $markets = $registry::select_markets($request['markets']??[]);
        $language_ids = $registry::language_post_ids($languages);
        $visible_ids = $registry::country_visible_ids($language_ids, $languages, $markets);
        $logic = ($request['logic']??'and')==='or' ? 'or' : 'and';
        $taxOperators = [];
        if(is_array($request['taxonomy_operators']??null)) {
            foreach($request['taxonomy_operators'] as $key=>$op) {
                if(is_string($key) && isset(self::allowed_taxonomies()[$key])) {
                    $taxOperators[$key] = $op==='all'?'AND':'IN';
                }
            }
        }
        $availableTaxonomies=self::allowed_taxonomies();
        $groups=['taxonomy'=>[], 'meta'=>[], 'relation'=>[]];
        foreach($filters as $key=>$value) {
            if(isset($availableTaxonomies[$key])) $groups['taxonomy'][$key]=$value;
            elseif(str_starts_with($key,'meta:')) $groups['meta'][$key]=$value;
            else $groups['relation'][$key]=$value;
        }
        $activeGroups=array_filter($groups,static fn($group)=>count($group)>0);
        if ($logic==='or' && count($activeGroups)>1 && empty($request['ids_only'])) {
            $matching=[];
            foreach($activeGroups as $subset) {
                $partial=self::query([
                    'filters'=>$subset, 'languages'=>$languages, 'markets'=>$markets,
                    'taxonomy_operators'=>$taxOperators, 'logic'=>'and','ids_only'=>true,
                ]);
                foreach($partial['ids'] as $id)$matching[(int)$id]=true;
            }
            $visible_ids=array_values(array_intersect($visible_ids,array_keys($matching)));
            $filters=[];
        }
        $query = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post__in' => $visible_ids ?: [0],
            'posts_per_page' => self::PAGE_SIZE,
            'paged' => $page,
            'ignore_sticky_posts' => true,
            'orderby' => $sort === 'title' ? 'title' : 'date',
            'order' => $sort === 'title' ? 'ASC' : 'DESC',
            's' => $search,
            'no_found_rows' => false,
            // Selected languages are scoped by WPML published translation IDs.
            'suppress_filters' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ];
        if ($filters) {
            $taxonomies=self::allowed_taxonomies();
            foreach($filters as $tax=>$ids) {
                if (!isset($taxonomies[$tax])) continue;
                if (!isset($query['tax_query'])) $query['tax_query']=['relation'=>'AND'];
                $query['tax_query'][] = [
                    'taxonomy'=>$tax,'field'=>'term_id','terms'=>$ids,
                    'operator'=>$taxOperators[$tax]??'IN','include_children'=>true,
                ];
            }
            $meta = ['relation'=>'AND'];
            if (!empty($filters['tour-mode'])) {
                $meta[]=['key'=>'tour_mode','value'=>$filters['tour-mode'],'compare'=>'IN'];
            }
            if (!empty($filters['extras'])) {
                $choices=$filters['extras'];
                if(count($choices)===1) {
                    $meta[]=in_array('enabled',$choices,true)
                        ? ['key'=>'tour_extras_status','value'=>'1']
                        : ['relation'=>'OR',
                           ['key'=>'tour_extras_status','compare'=>'NOT EXISTS'],
                           ['key'=>'tour_extras_status','value'=>'1','compare'=>'!=']];
                }
            }
            if (!empty($filters['transport']) && count($filters['transport'])<4) {
                $checks=['relation'=>'OR'];
                foreach($filters['transport'] as $kind) {
                    if ($kind==='none') {
                        $none=['relation'=>'AND'];
                        foreach(['tour_vehicles_transport_status','tour_train_transport_status','tour_air_transport_status'] as $k) {
                            $none[]=['relation'=>'OR',['key'=>$k,'compare'=>'NOT EXISTS'],
                                ['key'=>$k,'value'=>'1','compare'=>'!=']];
                        }
                        $checks[]=$none;
                        continue;
                    }
                    $k=['vehicles'=>'tour_vehicles_transport_status',
                        'train'=>'tour_train_transport_status','air'=>'tour_air_transport_status'][$kind]??'';
                    if($k) $checks[]=['key'=>$k,'value'=>'1'];
                }
                if(count($checks)>1) $meta[]=$checks;
            }
            foreach($filters as $key=>$rule) {
                if (!is_string($key)||!str_starts_with($key,'meta:')||!is_array($rule)) continue;
                $metaKey=substr($key,5);
                if (($rule['kind']??'')==='range') {
                    $meta[]=['key'=>$metaKey,'value'=>[$rule['min'],$rule['max']],
                        'compare'=>'BETWEEN','type'=>'NUMERIC'];
                } elseif (($rule['kind']??'')==='multi' && !empty($rule['values'])) {
                    $meta[]=['key'=>$metaKey,'value'=>$rule['values'],'compare'=>'IN'];
                }
            }
            if(count($meta)>1)$query['meta_query']=$meta;

            if (!empty($filters['tour-rate']) || !empty($filters['rate-ids'])) {
                $index=self::rate_index();
                $tours=array_values(array_unique(array_map('intval',array_values($index))));
                $choice=$filters['tour-rate']??[];
                if (!empty($filters['rate-ids'])) {
                    $rateTours=[];
                    foreach($filters['rate-ids'] as $rate) if(isset($index[$rate]))$rateTours[]=$index[$rate];
                    $query['post__in']=array_values(array_intersect($visible_ids,$rateTours))?:[0];
                } elseif(count($choice)===1) {
                    if($choice[0]==='has') $query['post__in']=array_values(array_intersect($visible_ids,$tours))?:[0];
                    elseif($choice[0]==='none') $query['post__not_in']=$tours;
                }
            }
        }
        // Strategy conditions only NARROW the scoped, published WPML/market
        // candidate set. They never replace Rate/Meta/Taxonomy query filters.
        $strategyMode=($request['strategy_mode']??'inherit')==='override'
            ?'override':'inherit';
        $targetCount=null;
        if($strategyMode==='override' && !empty($request['strategy_rules'])) {
            $strategy='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
            $target=$strategy::matching_ids(
                $request['strategy_rules'],
                ($request['strategy_combine']??'and')==='or'?'or':'and',
                $languages,$markets
            );
            // Invalid rules must fail closed even if called without AJAX.
            $targetIds=is_wp_error($target)?[]:$target['ids'];
            $targetCount=count($targetIds);
            $query['post__in']=array_values(array_intersect(
                (array)$query['post__in'],$targetIds
            ))?:[0];
        }
        if(!empty($request['ids_only'])) {
            $query['fields']='ids';
            $query['posts_per_page']=6000;
            $query['no_found_rows']=true;
            $query['paged']=1;
            $query['update_post_meta_cache']=false;
            $query['update_post_term_cache']=false;
        }
        $result = new WP_Query($query);
        if(!empty($request['ids_only'])) {
            $ids=array_map('absint',$result->posts);
            wp_reset_postdata();
            return ['ids'=>$ids];
        }
        $items = [];
        foreach ($result->posts as $post) {
            if ($post instanceof WP_Post && $post->post_status === 'publish' &&
                $post->post_type === self::POST_TYPE) $items[] = self::get_tour($post);
        }
        $found = (int)$result->found_posts;
        $pages = (int)$result->max_num_pages;
        wp_reset_postdata();
        return [
            'items' => $items, 'found' => $found,
            'page' => $page, 'pages' => $pages,
            'per_page' => self::PAGE_SIZE, 'filters' => self::parse_filters($request['filters']??[]),
            'logic'=>$logic,'taxonomy_operators'=>$taxOperators,
            'languages'=>$languages,'markets'=>$markets,
            'strategy_targeting'=>[
                'mode'=>$strategyMode,
                'conditions'=>$strategyMode==='override'
                    ?count((array)($request['strategy_rules']??[])):0,
                'matching_before_other_filters'=>$targetCount,
                'preview_only'=>true,'writes'=>0,
                'pricing_policy_unchanged'=>true,
            ],
            'market_authority'=>count($registry::approved_markets())
                ? 'approved_site_market_profiles_discovery_only'
                : 'no_approved_site_market_profiles',
            'language_source'=>'wpml_published_translations',
            'source' => 'published_local_wordpress_tours',
            'pricing' => 'published_start_price_only_not_calculated_quote',
            'saved' => false,
        ];
    }
    public static function ajax_list(): void {
        if (!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized()) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer(self::AJAX_ACTION, 'nonce');
        $search = isset($_POST['search']) && is_string($_POST['search'])
            ? wp_unslash($_POST['search']) : '';
        $filters_json = isset($_POST['filters']) && is_string($_POST['filters'])
            ? wp_unslash($_POST['filters']) : '';
        if (strlen($filters_json) > 5000) {
            wp_send_json_error(['message' => 'Filter request too large'], 400);
        }
        $filters = json_decode($filters_json, true);
        if ($filters_json !== '' && !is_array($filters)) {
            wp_send_json_error(['message' => 'Invalid filters'], 400);
        }
        $languages_raw=isset($_POST['languages'])&&is_string($_POST['languages'])
            ? wp_unslash($_POST['languages']) : '';
        $markets_raw=isset($_POST['markets'])&&is_string($_POST['markets'])
            ? wp_unslash($_POST['markets']) : '';
        if(strlen($languages_raw)>300 || strlen($markets_raw)>300) {
            wp_send_json_error(['message'=>'Selection too large'],400);
        }
        $langs=$languages_raw!==''?json_decode($languages_raw,true):[];
        $markets=$markets_raw!==''?json_decode($markets_raw,true):[];
        if(!is_array($langs)||!is_array($markets)) {
            wp_send_json_error(['message'=>'Invalid language/market filter'],400);
        }
        $page = isset($_POST['paged']) ? min(100, max(1, absint($_POST['paged']))) : 1;
        $tax_operators_json=isset($_POST['taxonomy_operators']) && is_string($_POST['taxonomy_operators'])
            ? wp_unslash($_POST['taxonomy_operators']) : '{}';
        if(strlen($tax_operators_json)>1500) wp_send_json_error(['message'=>'Invalid operators'],400);
        $tax_operators=json_decode($tax_operators_json,true);
        if(!is_array($tax_operators))wp_send_json_error(['message'=>'Invalid operators'],400);
        $logic=isset($_POST['logic']) && $_POST['logic']==='or' ? 'or' : 'and';
        $strategyMode=isset($_POST['strategy_mode'])&&$_POST['strategy_mode']==='override'
            ?'override':'inherit';
        $strategyCombine=isset($_POST['strategy_combine'])&&$_POST['strategy_combine']==='or'
            ?'or':'and';
        $rulesRaw=isset($_POST['strategy_rules'])&&is_string($_POST['strategy_rules'])
            ?wp_unslash($_POST['strategy_rules']):'[]';
        if(strlen($rulesRaw)>5000)wp_send_json_error(
            ['message'=>'Strategy request too large'],400
        );
        $rules=json_decode($rulesRaw,true);
        if(!is_array($rules))wp_send_json_error(
            ['message'=>'Invalid strategy targeting request'],400
        );
        if($strategyMode==='override' && $rules){
            $registry='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
            $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
            $validated=$bridge::rules($rules,$bridge::catalog(
                $registry::selected_languages($langs)
            ));
            if(is_wp_error($validated))wp_send_json_error([
                'message'=>$validated->get_error_message(),
                'code'=>$validated->get_error_code()
            ],400);
        }
        $response = self::query([
            'search' => $search, 'filters' => $filters ?? [],
            'logic'=>$logic,'taxonomy_operators'=>$tax_operators,
            'strategy_mode'=>$strategyMode,
            'strategy_rules'=>$strategyMode==='override'?$rules:[],
            'strategy_combine'=>$strategyCombine,
            'languages'=>$langs,'markets'=>$markets,
            'page' => $page,
            'sort' => isset($_POST['sort']) && is_string($_POST['sort'])
                ? sanitize_key(wp_unslash($_POST['sort'])) : 'newest',
        ]);
        $initial=!empty($_POST['with_facets']);
        $selected=$response['languages'];
        $response['facets'] = $initial ? self::facets($selected) : null;
        $registry = 'MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $response['language_facets'] = $initial ? $registry::language_facets() : null;
        $response['market_facets'] = $initial ? $registry::market_facets() : null;
        $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
        $response['strategy_catalog']=$initial?$bridge::catalog($selected):null;
        $response['filter_inventory'] = $initial ? [
            'taxonomy'=>count($registry::taxonomy_facets($selected)),
            'meta'=>count($registry::meta_facets($selected)),
            'relation'=>count(self::add_catalog_facets([], $selected)),
        ] : null;
        wp_send_json_success($response);
    }
}
