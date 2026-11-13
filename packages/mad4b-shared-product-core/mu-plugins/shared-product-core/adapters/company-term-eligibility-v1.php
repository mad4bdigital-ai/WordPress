<?php
/**
 * MAD4B company term eligibility – shared native consumer projection.
 * Adds a single decision contract to existing Service/Destination/Query Builder
 * authorities. It does not replace Elementor, JetSearch or JetSmartFilters.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Term_Eligibility_V1 {
    const META = 'mad4b_public_term_visibility';

    public static function owners(string $taxonomy): array {
        if (!taxonomy_exists($taxonomy) || !function_exists('product_navigation_registry')) return [];
        $tax=get_taxonomy($taxonomy);
        if (!$tax || !$tax->public) return [];
        $owners=[];
        foreach ((array) product_navigation_registry() as $service=>$definition) {
            $postType=(string)($definition['post_type']??'');
            if ($postType && in_array($postType,(array)$tax->object_type,true)
                && post_type_exists($postType)) $owners[$postType]=(string)$service;
        }
        return $owners;
    }

    public static function editable_taxonomies(): array {
        if (!function_exists('product_navigation_registry')) return [];
        $found=[];
        foreach ((array)product_navigation_registry() as $definition) {
            $postType=(string)($definition['post_type']??'');
            if (!$postType || !post_type_exists($postType)) continue;
            foreach ((array)get_object_taxonomies($postType) as $tax) {
                if ($tax!=='destinations' && self::owners($tax)) $found[$tax]=true;
            }
        }
        return array_keys($found);
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

    /** Only narrows existing authorizations. No term can enable a disabled service. */
    public static function decide(WP_Term $term,string $postType,string $channel='destination_discovery',bool $requirePublished=true): array {
        if (!taxonomy_exists($term->taxonomy) || !post_type_exists($postType))
            return ['allowed'=>false,'reason'=>'unknown_source'];
        $owner=function_exists('service_channel_owner')?service_channel_owner($postType):null;
        if (!$owner || !function_exists('service_channel_allowed') ||
            !service_channel_allowed($owner,$channel))
            return ['allowed'=>false,'reason'=>'service_inactive'];
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
            if (!function_exists('shared_presentation_published_facet_terms_v1'))
                return ['allowed'=>false,'reason'=>'source_missing'];
            $published=shared_presentation_published_facet_terms_v1($postType,$term->taxonomy);
            if (!in_array((int)$term->term_id,$published,true) &&
                !in_array(self::canonical($term),$published,true))
                return ['allowed'=>false,'reason'=>'no_published_content'];
        }
        return ['allowed'=>true,'reason'=>'eligible'];
    }

    public static function for_registered_owners(WP_Term $term,string $channel='destination_discovery'): bool {
        foreach (self::owners($term->taxonomy) as $postType=>$owner)
            if (self::decide($term,$postType,$channel)['allowed']) return true;
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
            foreach ($providers as $postType=>$_) {
                if (self::decide($term,$postType)['allowed']) {$allowed=true;break;}
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
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>0,'fields'=>'all']);
        if (is_wp_error($terms)) return $args;
        $permitted=[];
        foreach ($terms as $term)
            if ($term instanceof WP_Term && self::for_registered_owners($term)) $permitted[]=(int)$term->term_id;
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
        $channel=(string)($args['mad4b_term_eligibility_channel']??'destination_discovery');
        $taxonomy=(string)($args['taxonomy']??'');
        return array_values(array_filter($terms,static function($term)use($postType,$channel,$taxonomy) {
            $candidate=$term instanceof WP_Term?$term:
                (is_numeric($term)&&taxonomy_exists($taxonomy)?get_term((int)$term,$taxonomy):null);
            return !$candidate instanceof WP_Term
                || self::decide($candidate,$postType,$channel)['allowed'];
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
        do_action('mad4b/public_html/safe_purge_all','company_term_eligibility');
    }
}

add_filter('jet-search/ajax-search/categories-select/args',
    ['MAD4B_Company_Term_Eligibility_V1','search_args'],40);
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filterId) {
    return is_array($options)?MAD4B_Company_Term_Eligibility_V1::smart_options($options,(int)$filterId):$options;
},900,2);
add_filter('product_navigation_term_args',static function($args,$service,$taxonomy,$language) {
    if (!is_array($args)) return $args;
    $args['mad4b_term_eligibility_service']=(string)$service;
    $args['mad4b_term_eligibility_channel']='destination_discovery';
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
        if (!MAD4B_Company_Term_Eligibility_V1::for_registered_owners($term))
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

add_action('update_option_destination-activation',static function($old,$new) {
    if ($old!==$new)do_action('mad4b/public_html/safe_purge_all','company_destination_eligibility');
},30,2);
