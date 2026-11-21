<?php
/**
 * MAD4B company term eligibility – shared native consumer projection.
 * Adds a single decision contract to existing Service/Destination/Query Builder
 * authorities. It does not replace Elementor, JetSearch or JetSmartFilters.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Term_Eligibility_V1 {
    const META = 'mad4b_public_term_visibility';
    private static array $searchSources=[];

    /** Use JetSearch's actual Elementor widget settings, never a guessed taxonomy owner. */
    public static function capture_search_widget($widget): void {
        if (!is_object($widget) || !method_exists($widget,'get_name')
            || $widget->get_name()!=='jet-ajax-search'
            || !method_exists($widget,'get_settings_for_display')
            || !method_exists($widget,'get_id')) return;
        $settings=(array)$widget->get_settings_for_display();
        $raw=$settings['search_source']??[];
        $types=[];
        foreach ((array)$raw as $type)
            if (is_string($type) && post_type_exists($type)) $types[$type]=true;
        if ($types) self::$searchSources['jet_ajax_search_categories_'.$widget->get_id()]=array_keys($types);
    }



    /** Discover catalog sources from the actual company/WooCommerce CPT registry. */
    public static function catalog_sources(): array {
        static $sources=null;
        if ($sources!==null) return $sources;
        $sources=[];
        if (function_exists('product_navigation_registry')) {
            foreach ((array)product_navigation_registry() as $service=>$entry) {
                $type=(string)($entry['post_type']??'');
                if ($type!=='' && post_type_exists($type)) $sources[$type]=(string)$service;
            }
        }
        if (function_exists('service_channel_owner')) {
            foreach (get_post_types([], 'names') as $type) {
                $service=service_channel_owner($type);
                if (is_string($service) && $service!=='') $sources[$type]=$service;
            }
        }
        if (class_exists('WooCommerce',false) && function_exists('wc_get_product')
            && post_type_exists('product')) $sources['product']='woocommerce';
        return $sources;
    }

    private static function public_facet_taxonomy(string $taxonomy): bool {
        $tax=get_taxonomy($taxonomy);
        if (!$tax) return false;
        // WooCommerce product attributes may have public=false but remain native facets.
        return (bool)$tax->public ||
            (function_exists('taxonomy_is_product_attribute')
                && taxonomy_is_product_attribute($taxonomy) && (bool)$tax->show_ui);
    }

    public static function owners(string $taxonomy): array {
        if (!self::public_facet_taxonomy($taxonomy)) return [];
        $tax=get_taxonomy($taxonomy);
        $owners=[];
        foreach (self::catalog_sources() as $postType=>$service) {
            if (post_type_exists($postType) && in_array($postType,(array)$tax->object_type,true))
                $owners[$postType]=$service;
        }
        return $owners;
    }

    public static function editable_taxonomies(): array {
        $found=[];
        foreach (self::catalog_sources() as $postType=>$service) {
            foreach ((array)get_object_taxonomies($postType) as $taxonomy) {
                $tax=get_taxonomy($taxonomy);
                if ($taxonomy!=='destinations' && $tax && $tax->show_ui && self::owners($taxonomy))
                    $found[$taxonomy]=true;
            }
        }
        return array_keys($found);
    }

    /** Catalog eligibility follows WooCommerce visibility and stock policy. */
    private static function woo_visible_terms(string $taxonomy,string $channel='standalone'): array {
        $key='mad4b_wc_catalog_facet_terms_v1_'.md5($taxonomy.'|'.$channel);
        $cached=get_transient($key);
        if (is_array($cached)) return $cached;
        if (!function_exists('wc_get_product_visibility_term_ids')) return [];
        $visibility=wc_get_product_visibility_term_ids();
        $blockedTerm=$channel==='search'?'exclude-from-search':'exclude-from-catalog';
        $exclusions=[(int)($visibility[$blockedTerm]??0)];
        if (get_option('woocommerce_hide_out_of_stock_items')==='yes')
            $exclusions[]=(int)($visibility['outofstock']??0);
        $exclusions=array_values(array_filter($exclusions));
        global $wpdb;
        $sql="SELECT DISTINCT tt.term_id FROM {$wpdb->posts} p
            JOIN {$wpdb->term_relationships} rel ON rel.object_id=p.ID
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=rel.term_taxonomy_id
            WHERE tt.taxonomy=%s AND p.post_type='product'
              AND p.post_status='publish' AND p.post_password=''";
        if ($exclusions) {
            $slots=implode(',',array_fill(0,count($exclusions),'%d'));
            $sql.=" AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} hidden_rel
                JOIN {$wpdb->term_taxonomy} hidden_tax
                  ON hidden_tax.term_taxonomy_id=hidden_rel.term_taxonomy_id
                WHERE hidden_rel.object_id=p.ID
                  AND hidden_tax.taxonomy='product_visibility'
                  AND hidden_tax.term_id IN ($slots))";
        }
        $rows=$wpdb->get_col($wpdb->prepare($sql,$taxonomy,...$exclusions));
        if ($wpdb->last_error!=='') return [];
        $result=[];
        foreach ((array)$rows as $id) {
            foreach (array_merge([(int)$id],array_map('intval',get_ancestors((int)$id,$taxonomy,'taxonomy'))) as $termId) {
                $result[$termId]=true;
                $canonical=(int)apply_filters('wpml_object_id',$termId,$taxonomy,false,
                    (string)apply_filters('wpml_default_language','en'));
                if ($canonical>0) $result[$canonical]=true;
            }
        }
        $ids=array_keys($result);
        set_transient($key,$ids,5*MINUTE_IN_SECONDS);
        return $ids;
    }

    private static function canonical(WP_Term $term): int {
        $default=(string)apply_filters('wpml_default_language','en');
        $canonical=(int)apply_filters('wpml_object_id',$term->term_id,$term->taxonomy,false,$default);
        return $canonical>0?$canonical:(int)$term->term_id;
    }

    private static function override(WP_Term $term): string {
        if ($term->taxonomy==='destinations') return 'inherit'; // is_public remains the owner.
        $canonical=self::canonical($term);
        $value=(string)get_term_meta($canonical,self::META,true);
        if ($value==='' && $canonical!==(int)$term->term_id)
            $value=(string)get_term_meta($term->term_id,self::META,true);
        return in_array($value,['enabled','disabled'],true)?$value:'inherit';
    }

    /**
     * Host-verified parent Tour; do not accept a URL parameter or unsigned
     * JetSmartFilters AJAX value as Tour context.
     */
    public static function verified_tour_id(): int {
        return function_exists('shared_product_verified_presentation_tour_id')
            ? (int)shared_product_verified_presentation_tour_id() : 0;
    }

    public static function surface_channel(): string {
        if (self::verified_tour_id()>0) return 'tour_component';
        if (function_exists('is_tax') && is_tax('destinations')) return 'destination_discovery';
        return 'standalone';
    }

    /**
     * Visibility in a Tour component is read-only. An inactive standalone
     * service must not erase the metadata/facets of a published parent Tour.
     * This never authorizes an independent service result or payment.
     */
    public static function query_service_allowed(string $service,string $channel): bool {
        if ($channel==='tour_component') {
            return self::verified_tour_id()>0
                && function_exists('service_channel_allowed')
                && service_channel_allowed('tours','tour_component')
                && service_channel_allowed($service,'tour_component');
        }
        return function_exists('service_channel_allowed')
            && service_channel_allowed($service,$channel);
    }

    /** The original taxonomy/query provider continues to own row selection. */
    public static function decide(WP_Term $term,string $postType,string $channel='destination_discovery',bool $requirePublished=true): array {
        if (!taxonomy_exists($term->taxonomy) || !post_type_exists($postType))
            return ['allowed'=>false,'reason'=>'unknown_source'];
        $owner=self::owners($term->taxonomy)[$postType]??null;
        if (!$owner) return ['allowed'=>false,'reason'=>'source_not_in_catalog'];
        if ($owner==='woocommerce') {
            if (!class_exists('WooCommerce',false) || !function_exists('wc_get_product'))
                return ['allowed'=>false,'reason'=>'woocommerce_unavailable'];
        } elseif (!self::query_service_allowed($owner,$channel))
            return ['allowed'=>false,'reason'=>$channel==='tour_component'
                ? 'tour_context_unverified':'service_inactive'];
        $tax=get_taxonomy($term->taxonomy);
        if (!$tax || !in_array($postType,(array)$tax->object_type,true))
            return ['allowed'=>false,'reason'=>'taxonomy_not_owned'];
        if ($term->taxonomy==='destinations') {
            if (!function_exists('destinations_activation_allowed') ||
                !destinations_activation_allowed((int)$term->term_id))
                return ['allowed'=>false,'reason'=>'destination_inactive'];
        } else {
            foreach (array_merge([(int)$term->term_id],
                array_map('intval',get_ancestors((int)$term->term_id,$term->taxonomy,'taxonomy'))) as $termId) {
                $candidate=get_term($termId,$term->taxonomy);
                if ($candidate instanceof WP_Term && self::override($candidate)==='disabled')
                    return ['allowed'=>false,'reason'=>'term_disabled'];
            }
        }
        if ($requirePublished) {
            if ($owner==='woocommerce') {
                $published=self::woo_visible_terms($term->taxonomy,$channel);
            } else {
                if (!function_exists('shared_presentation_published_facet_terms_v1'))
                    return ['allowed'=>false,'reason'=>'source_missing'];
                $published=shared_presentation_published_facet_terms_v1($postType,$term->taxonomy);
            }
            if (!in_array((int)$term->term_id,$published,true) &&
                !in_array(self::canonical($term),$published,true))
                return ['allowed'=>false,'reason'=>'no_published_content'];
        }
        return ['allowed'=>true,'reason'=>'eligible'];
    }

    public static function for_registered_owners(WP_Term $term,string $channel='destination_discovery',bool $requirePublished=true): bool {
        foreach (self::owners($term->taxonomy) as $postType=>$owner)
            if (self::decide($term,$postType,$channel,$requirePublished)['allowed']) return true;
        return false;
    }

    private static function option_term($key,$option,string $taxonomy): ?WP_Term {
        if ($option instanceof WP_Term) return $option->taxonomy===$taxonomy?$option:null;
        $value=is_array($option)?($option['value']??null):null;
        if ($value===null && is_scalar($key)) $value=$key;
        if (!is_scalar($value) || (string)$value==='') return null;
        $term=ctype_digit((string)$value)
            ?get_term((int)$value,$taxonomy):get_term_by('slug',(string)$value,$taxonomy);
        return $term instanceof WP_Term?$term:null;
    }

    public static function smart_options(array $options,int $filterId): array {
        if ((is_admin()&&!wp_doing_ajax()) || !function_exists('shared_presentation_native_facet_binding_v1')) return $options;
        $binding=shared_presentation_native_facet_binding_v1($filterId);
        if (!$binding || ($binding['source']??'')!=='taxonomies') return $options;
        $taxonomy=(string)($binding['taxonomy']??'');
        if (!$taxonomy || !taxonomy_exists($taxonomy)) return $options;
        // Native Query Builder Terms queries have no post_type. In a server-
        // verified Tour their returned term list is the source of truth; do
        // not require a separately published Property or Transportation.
        $termProviders=array_filter((array)($binding['providers']??[]),
            static function($source)use($taxonomy) {
                return is_array($source)
                    && ($source['query_type']??'')==='terms'
                    && in_array($taxonomy,(array)($source['taxonomies']??[]),true);
            });
        if ($termProviders && self::surface_channel()==='tour_component') {
            foreach ($options as $key=>$option) {
                $term=self::option_term($key,$option,$taxonomy);
                if ($term && !self::for_registered_owners($term,'tour_component',false))
                    unset($options[$key]);
            }
            return $options;
        }
        $providers=[];
        $unresolved=false;
        foreach ((array)($binding['providers']??[]) as $provider) {
            $type=(string)($provider['post_type']??'');
            if (!$type) {$unresolved=true;continue;}
            if (self::owners($taxonomy)[$type]??false) $providers[$type]=true;
        }
        // An unknown provider may have a separate native policy (rates, activities).
        // Never pretend to authorize it by guessing the service from the taxonomy.
        if (!$providers || ($unresolved && $taxonomy==='destinations')) return $options;
        foreach ($options as $key=>$option) {
            if ($key==='' || (is_array($option)&&($option['value']??null)==='')) continue;
            $term=self::option_term($key,$option,$taxonomy);
            if (!$term) continue; // preserve non-term native schema without fabricating scope.
            $allowed=false;
            $channel=self::surface_channel();
            foreach ($providers as $postType=>$_) {
                if (self::decide($term,$postType,
                    $postType==='product'?'search':$channel,
                    $channel!=='tour_component')['allowed']) {$allowed=true;break;}
            }
            if (!$allowed) unset($options[$key]);
        }
        return $options;
    }

    public static function search_args(array $args): array {
        if (is_admin()&&!wp_doing_ajax()) return $args;
        $taxonomy=(string)($args['taxonomy']??'');
        $owners=self::owners($taxonomy);
        if (!$owners) return $args;
        // The widget may search only Tours while a taxonomy is also assigned
        // to Properties or Woo products. Keep those independently eligible.
        $widgetId=(string)($args['id']??'');
        $selected=self::$searchSources[$widgetId]??[];
        if ($selected) $owners=array_intersect_key($owners,array_fill_keys($selected,true));
        if (!$owners) { $args['include']=[0];return $args; }
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>0,'fields'=>'all']);
        if (is_wp_error($terms)) return $args;
        $permitted=[];
        foreach ($terms as $term)
            if ($term instanceof WP_Term) {
                foreach ($owners as $postType=>$service) {
                    if (self::decide($term,$postType,
                        $postType==='product'?'search':self::surface_channel())['allowed']) {
                        $permitted[]=(int)$term->term_id; break;
                    }
                }
            }
        if (!empty($args['include'])) {
            $permitted=array_values(array_intersect($permitted,array_map('absint',(array)$args['include'])));
        }
        $args['include']=$permitted?:[0]; // [] means ALL to wp_dropdown_categories.
        $args['hide_empty']=false; // published-catalogue policy is more precise than term->count.
        return $args;
    }

    public static function navigation_terms($terms,$taxonomies,$args) {
        if (!is_array($terms)||!is_array($args)||
            empty($args['mad4b_term_eligibility_service'])) return $terms;
        $owner=(string)$args['mad4b_term_eligibility_service'];
        $postType=(string)(product_navigation_registry()[$owner]['post_type']??'');
        if (!$postType) return $terms;
        $channel=(string)($args['mad4b_term_eligibility_channel']??'standalone');
        $taxArg=$args['taxonomy']??'';
        $taxonomy=is_array($taxArg)?(count($taxArg)===1?(string)reset($taxArg):''):(string)$taxArg;
        return array_values(array_filter($terms,static function($term)use($postType,$channel,$taxonomy) {
            $candidate=$term instanceof WP_Term?$term:
                (is_numeric($term)&&taxonomy_exists($taxonomy)?get_term((int)$term,$taxonomy):null);
            return !$candidate instanceof WP_Term
                || self::decide($candidate,$postType,$channel)['allowed'];
        }));
    }

    public static function filter_query_builder_terms($items,$query) {
        if (!is_array($items) || !is_object($query)
            || (string)($query->query_type??'')!=='terms'
            || self::surface_channel()!=='tour_component') return $items;
        $taxonomies=array_values(array_filter((array)($query->query['taxonomy']??[]),
            static fn($tax)=>is_string($tax)&&taxonomy_exists($tax)));
        if (!$taxonomies) return $items;
        return array_values(array_filter($items,static function($item)use($taxonomies) {
            if (!$item instanceof WP_Term || !in_array($item->taxonomy,$taxonomies,true))
                return true;
            if (!self::owners($item->taxonomy)) return true; // not a product taxonomy
            return self::for_registered_owners($item,'tour_component',false);
        }));
    }

    public static function admin_fields(WP_Term $term): void {
        $canonical=self::canonical($term);
        $value=self::override($term);
        wp_nonce_field('mad4b_term_visibility_'.$canonical,'mad4b_term_visibility_nonce');
        echo '<tr class="form-field"><th scope="row"><label for="mad4b_term_visibility">MAD4B public visibility</label></th><td>';
        echo '<select name="mad4b_term_visibility" id="mad4b_term_visibility">';
        foreach (['inherit'=>'Inherit company policy','enabled'=>'Enabled when eligible','disabled'=>'Disabled'] as $v=>$label)
            echo '<option value="'.esc_attr($v).'" '.selected($value,$v,false).'>'.esc_html($label).'</option>';
        echo '</select><p class="description">Does not override Service Activation, destination hierarchy, or published-content eligibility. WPML terms inherit canonical default-language policy.</p></td></tr>';
    }

    public static function save_term(int $termId): void {
        if (!isset($_POST['mad4b_term_visibility_nonce'],$_POST['mad4b_term_visibility'])) return;
        $term=get_term($termId);
        if (!$term instanceof WP_Term || !in_array($term->taxonomy,self::editable_taxonomies(),true) ||
            !current_user_can('edit_term',$termId)) return;
        $canonical=self::canonical($term);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_term_visibility_nonce'])),
            'mad4b_term_visibility_'.$canonical)) return;
        $value=sanitize_key(wp_unslash($_POST['mad4b_term_visibility']));
        if (!in_array($value,['inherit','enabled','disabled'],true)) return;
        if ($value==='inherit') delete_term_meta($canonical,self::META);
        else update_term_meta($canonical,self::META,$value);
        $cacheClass='RankMath\Sitemap\Cache';
        if (class_exists($cacheClass)) $cacheClass::invalidate_storage();
        do_action('mad4b/public_html/safe_purge_all','company_term_eligibility');
    }
}

add_action('elementor/frontend/widget/before_render',
    ['MAD4B_Company_Term_Eligibility_V1','capture_search_widget'],5,1);
add_filter('jet-search/ajax-search/categories-select/args',
    ['MAD4B_Company_Term_Eligibility_V1','search_args'],40);
// JetEngine Terms Query Builder (including cached results) is independent of WP_Query.
add_filter('jet-engine/query-builder/query/items',
    ['MAD4B_Company_Term_Eligibility_V1','filter_query_builder_terms'],1400,2);
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filterId) {
    return is_array($options)?MAD4B_Company_Term_Eligibility_V1::smart_options($options,(int)$filterId):$options;
},900,2);
add_filter('product_navigation_term_args',static function($args,$service,$taxonomy,$language) {
    if (!is_array($args)) return $args;
    $args['mad4b_term_eligibility_service']=(string)$service;
    $args['mad4b_term_eligibility_channel']='standalone';
    return $args;
},190,4);
add_filter('get_terms',['MAD4B_Company_Term_Eligibility_V1','navigation_terms'],190,3);

// Preserve authored Elementor layout; only remove unauthorized generated links.
add_filter('product_navigation_sections',static function($sections,$language,$surface) {
    if (!is_array($sections) || !function_exists('product_navigation_registry')) return $sections;
    foreach ($sections as &$section) {
        $service=(string)($section['key']??'');
        $postType=(string)(product_navigation_registry()[$service]['post_type']??'');
        if (!$postType || !is_array($section['children']??null)) continue;
        $section['children']=array_values(array_filter($section['children'],
            static function($child)use($postType) {
                $key=(string)($child['key']??'');
                if (!preg_match('/^([a-z0-9_-]+):(\d+)$/D',$key,$m) || !taxonomy_exists($m[1])) return true;
                $term=get_term((int)$m[2],$m[1]);
                return !$term instanceof WP_Term ||
                    MAD4B_Company_Term_Eligibility_V1::decide($term,$postType)['allowed'];
            }));
    }
    unset($section);
    return $sections;
},190,3);

// Native WordPress nav menus, including manually authored taxonomy entries.
add_filter('wp_nav_menu_objects',static function($items) {
    if (!is_array($items) || is_admin()) return $items;
    $removed=[];
    foreach ($items as $item) {
        if (($item->type??'')!=='taxonomy') continue;
        $tax=(string)($item->object??'');
        $term=taxonomy_exists($tax)?get_term((int)($item->object_id??0),$tax):null;
        if (!$term instanceof WP_Term || !MAD4B_Company_Term_Eligibility_V1::owners($tax)) continue;
        if (!MAD4B_Company_Term_Eligibility_V1::for_registered_owners(
            $term,MAD4B_Company_Term_Eligibility_V1::surface_channel()))
            $removed[(int)$item->ID]=true;
    }
    // Retain menu parent/child integrity when a taxonomy parent is hidden.
    do {
        $changed=false;
        foreach ($items as $item)
            if (!isset($removed[(int)$item->ID]) && isset($removed[(int)($item->menu_item_parent??0)])) {
                $removed[(int)$item->ID]=true;$changed=true;
            }
    } while($changed);
    return array_values(array_filter($items,static fn($item)=>!isset($removed[(int)$item->ID])));
},190,1);

add_action('init',static function() {
    foreach (MAD4B_Company_Term_Eligibility_V1::editable_taxonomies() as $taxonomy)
        add_action($taxonomy.'_edit_form_fields',['MAD4B_Company_Term_Eligibility_V1','admin_fields'],40,1);
},99);
add_action('edited_term',static function($termId) {
    MAD4B_Company_Term_Eligibility_V1::save_term((int)$termId);
},90,1);

// Search engines must not publish inactive product taxonomies in sitemaps.
// Destinations keep their dedicated activation and SEO authority.
add_filter('rank_math/sitemap/entry',static function($entry,$type,$term) {
    if ($type!=='term' || !$term instanceof WP_Term
        || $term->taxonomy==='destinations'
        || !MAD4B_Company_Term_Eligibility_V1::owners($term->taxonomy)) return $entry;
    return MAD4B_Company_Term_Eligibility_V1::for_registered_owners($term,'standalone')
        ? $entry : false;
},1250,3);

add_action('update_option_destination-activation',static function($old,$new) {
    if ($old!==$new)do_action('mad4b/public_html/safe_purge_all','company_destination_eligibility');
},30,2);


// Woo catalog changes invalidate only Woo facet snapshots, not Redis or other services.
function mad4b_company_term_woo_invalidate_v1($productId=0): void {
    if (!class_exists('WooCommerce',false)) return;
    foreach (get_object_taxonomies('product') as $taxonomy) {
        foreach (['standalone','destination_discovery','search','tour_component'] as $channel)
            delete_transient('mad4b_wc_catalog_facet_terms_v1_'.md5($taxonomy.'|'.$channel));
    }
}
add_action('woocommerce_update_product','mad4b_company_term_woo_invalidate_v1',30,1);
add_action('woocommerce_new_product','mad4b_company_term_woo_invalidate_v1',30,1);
add_action('set_object_terms',static function($objectId) {
    if (get_post_type((int)$objectId)==='product') mad4b_company_term_woo_invalidate_v1((int)$objectId);
},45,1);
add_action('transition_post_status',static function($new,$old,$post) {
    if ($new!==$old && $post instanceof WP_Post && $post->post_type==='product')
        mad4b_company_term_woo_invalidate_v1((int)$post->ID);
},45,3);
add_action('update_option_woocommerce_hide_out_of_stock_items',
    'mad4b_company_term_woo_invalidate_v1',30,0);
