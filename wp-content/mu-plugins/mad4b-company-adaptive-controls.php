<?php
/**
 * Plugin Name: MAD4B Company Adaptive Controls
 * Description: Company-owned JetEngine AJAX listing policy and inheritable seasonal vs hotel-rate pricing-source selection.
 * Version: 1.1.2
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Adaptive_Controls {
    public const VERSION = '1.1.2';
    public const OPTION = 'mad4b_company_adaptive_controls_v1';
    public const LISTING_META = '_mad4b_adaptive_listing_mode';
    public const PRICE_META = '_mad4b_company_pricing_mode';
    public const MODES = ['inherit','fixed_seasonal','dynamic_hotel_rates','hybrid'];
    private static array $decisions = [];

    public static function defaults(): array {
        return [
            'listing_mode'=>'ajax_only',
            'mobile_prefetch_px'=>800,
            'desktop_prefetch_px'=>1100,
            'mobile_rows'=>2,
            'tablet_rows'=>2,
            'desktop_rows'=>3,
            'pricing_default'=>'fixed_seasonal',
            'pricing_season_source'=>'season',
            'pricing_source_authority'=>'existing_rate_records',
        ];
    }
    public static function policy(): array {
        $o=get_option(self::OPTION,[]);
        return array_replace(self::defaults(),is_array($o)?$o:[]);
    }
    public static function sanitize($raw): array {
        $current=self::policy();if(!is_array($raw))return $current;
        $out=$current;
        if(isset($raw['listing_mode']))$out['listing_mode']=in_array($raw['listing_mode'],['ajax_only','off'],true)?$raw['listing_mode']:$current['listing_mode'];
        foreach(['mobile_prefetch_px'=>[0,1800],'desktop_prefetch_px'=>[0,2500],'mobile_rows'=>[1,6],'tablet_rows'=>[1,6],'desktop_rows'=>[1,6]] as $key=>$bounds) {
            if(isset($raw[$key])&&is_scalar($raw[$key])&&ctype_digit((string)$raw[$key]))
                $out[$key]=max($bounds[0],min($bounds[1],(int)$raw[$key]));
        }
        if(isset($raw['pricing_default'])&&in_array($raw['pricing_default'],array_slice(self::MODES,1),true))$out['pricing_default']=$raw['pricing_default'];
        $out['pricing_season_source']='season';$out['pricing_source_authority']='existing_rate_records';
        return $out;
    }
    private static function context(): bool {
        if(is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
           || (defined('WP_CLI')&&WP_CLI)
           || (defined('REST_REQUEST')&&REST_REQUEST)
           || (defined('DOING_CRON')&&DOING_CRON))return false;
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')return false;
        foreach(array_keys((array)$_GET) as $key) {
            // Query-driven filters, dates and market switches may need their own synchronous query handling.
            if(!preg_match('/^(utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i',(string)$key))return false;
        }
        if(class_exists('\Elementor\Plugin')&&isset(\Elementor\Plugin::$instance->editor)
           &&\Elementor\Plugin::$instance->editor->is_edit_mode())return false;
        return true;
    }
    /**
     * A nested JetEngine grid runs inside its parent's current object, not the
     * WordPress queried page. Detached lazy AJAX drops that parent context,
     * breaking Query Builder macros such as Current Post destinations.
     * Render the nested grid during its parent's own response (which may itself
     * be deferred); do NOT switch the entire page back to synchronous rendering.
     */
    public static function parent_object_context_required(): bool {
        if (!function_exists('jet_engine')) return false;
        $jet=jet_engine();
        $data=is_object($jet) && isset($jet->listings->data) ? $jet->listings->data : null;
        if (!is_object($data) || !method_exists($data,'get_current_object')) return false;
        $current=$data->get_current_object();
        $main_id=(int)get_queried_object_id();
        if ($current instanceof \WP_Post) return (int)$current->ID>0 && (int)$current->ID!==$main_id;
        if ($current instanceof \WP_Term) return (int)$current->term_id>0 && (int)$current->term_id!==$main_id;
        return false;
    }
    /** Prevent detached AJAX for nested object-relative grids even if another
     * integration enabled it earlier in the JetEngine filter chain. */
    public static function preserve_parent_context($enabled,$settings) {
        return self::parent_object_context_required() ? false : $enabled;
    }
    private static function safe_listing(array $settings): bool {
        $id=absint($settings['lisitng_id']??0);
        if(!$id||!in_array(get_post_status($id),['publish'],true))return false;
        $override=(string)get_post_meta($id,self::LISTING_META,true);
        if($override==='native')return false;
        foreach(['is_archive_template','use_load_more','pagination','use_load_more_pagination'] as $flag)
            if(!empty($settings[$flag])&&filter_var($settings[$flag],FILTER_VALIDATE_BOOLEAN))return false;
        if(!empty($settings['use_random_posts_num'])&&filter_var($settings['use_random_posts_num'],FILTER_VALIDATE_BOOLEAN))return false;
        $listing_data=get_post_meta($id,'_listing_data',true);
        if(is_array($listing_data)){
            $source=strtolower((string)($listing_data['listing_source']??$listing_data['source']??''));
            $type=strtolower((string)($listing_data['listing_post_type']??$listing_data['post_type']??''));
            if(preg_match('/(?:order|booking|quote|checkout|payment|supplier|customer|rate|price|invoice)/i',$source.' '.$type))return false;
        }
        $qid=absint($settings['custom_query_id']??0);
        if($qid && function_exists('mad4b_secondary_grid_query_visibility_dependency')
           && mad4b_secondary_grid_query_visibility_dependency($qid))return false;
        if(!empty($settings['custom_query'])&&filter_var($settings['custom_query'],FILTER_VALIDATE_BOOLEAN) && !$qid)return false;
        return (bool)apply_filters('mad4b/company_adaptive/listing_eligible',true,$settings,$id);
    }
    public static function decision($enabled,$settings) {
        if(self::parent_object_context_required())return false;
        if(!self::context()||!is_array($settings))return $enabled;
        $id=absint($settings['lisitng_id']??0);
        $override=$id?(string)get_post_meta($id,self::LISTING_META,true):'';
        if($override==='native')return false;
        $mode=class_exists('MAD4B_Company_Policy_Registry_V2')
            ?(MAD4B_Company_Policy_Registry_V2::render_policy($settings)['values']['strategy']??'off')
            :self::policy()['listing_mode'];
        if($mode!=='ajax_only')return $enabled;
        if(self::safe_listing($settings))return true;
        return $enabled; // Existing third-party/editor lazy settings are preserved; no force-blank rendering.
    }
    public static function offset($options,$settings) {
        if(!is_array($options)||!is_array($settings)||self::parent_object_context_required()||!self::context()||!self::safe_listing($settings))return $options;
        $p=self::policy();$px=wp_is_mobile()?(int)$p['mobile_prefetch_px']:(int)$p['desktop_prefetch_px'];
        if(class_exists('MAD4B_Company_Policy_Registry_V2')){
            $r=MAD4B_Company_Policy_Registry_V2::render_policy($settings);
            if(($r['values']['strategy']??'off')!=='ajax_only')return $options;
            $px=(int)($r['values']['prefetch_px']??$px);
            $options['mad4b_policy_decision_id']=$r['receipt']['decision_id'];
        }elseif($p['listing_mode']!=='ajax_only')return $options;
        $options['offset']=max(0,min(2500,$px)).'px';
        $options['mad4b_company_loading']='ajax_only_v2';
        return $options;
    }
    /**
     * Configure JetEngine's existing AJAX/lazy renderer before Elementor builds
     * any item HTML. Explicitly no initial native card rendering for eligible
     * listings. Grids with a normal posts_num are progressively paginated using
     * JetEngine Infinite Scroll; Query Builder and Sliders retain their own
     * native query size on the deferred AJAX request.
     */
    public static function before_widget($widget): void {
        if(!self::context()||!is_object($widget)
            ||!method_exists($widget,'get_name')||$widget->get_name()!=='jet-listing-grid'
            ||!method_exists($widget,'get_settings_for_display')||!method_exists($widget,'set_settings'))return;
        $s=$widget->get_settings_for_display();
        if(!is_array($s)||self::parent_object_context_required()||!self::safe_listing($s))return;
        $p=self::policy();
        $device=class_exists('MAD4B_Company_Policy_Registry_V2')
            ?MAD4B_Company_Policy_Registry_V2::device():(wp_is_mobile()?'mobile':'desktop');
        $mobile=$device==='mobile';$tablet=$device==='tablet';
        $rows=$mobile?(int)$p['mobile_rows']:($tablet?(int)$p['tablet_rows']:(int)$p['desktop_rows']);
        $offset=$mobile?(int)$p['mobile_prefetch_px']:(int)$p['desktop_prefetch_px'];
        if(class_exists('MAD4B_Company_Policy_Registry_V2')){
            $resolved=MAD4B_Company_Policy_Registry_V2::render_policy($s);
            if(($resolved['values']['strategy']??'off')!=='ajax_only')return;
            $offset=(int)($resolved['values']['prefetch_px']??$offset);
            $rows=(int)($resolved['values']['rows']??$rows);
        }elseif($p['listing_mode']!=='ajax_only')return;
        $widget->set_settings('lazy_load','yes');
        $widget->set_settings('lazy_load_offset',['size'=>$offset,'unit'=>'px']);
        $slider=false;
        foreach(['is_slider','carousel_enabled','scroll_slider_enabled'] as $flag)
            if(!empty($s[$flag])&&filter_var($s[$flag],FILTER_VALIDATE_BOOLEAN))$slider=true;
        if($slider || (!empty($s['custom_query'])&&filter_var($s['custom_query'],FILTER_VALIDATE_BOOLEAN)))return;
        $columns=$mobile?(int)($s['columns_mobile']??$s['columns_tablet']??$s['columns']??1)
            :($tablet?(int)($s['columns_tablet']??$s['columns']??2):(int)($s['columns']??3));
        if($columns<1||$columns>12)return;
        $batch=$columns*max(1,min(6,$rows));
        $original=(int)($s['posts_num']??0);
        if($original>0&&$original<=$batch)return;
        $widget->set_settings('posts_num',$batch);
        $widget->set_settings('use_load_more','yes');
        $widget->set_settings('load_more_type','scroll');
    }
    public static function entity_types(): array {return ['tours-and-activities','tour-rates','properties','property-rates'];}
    private static function inherited_meta(int $id,string $type): string {
        if(!$id)return '';
        $v=(string)get_post_meta($id,self::PRICE_META,true);
        if(in_array($v,array_slice(self::MODES,1),true))return $v;
        if(has_filter('wpml_object_id')){
            $default=apply_filters('wpml_default_language',null);
            if(is_string($default)&&$default!==''){
                $canonical=(int)apply_filters('wpml_object_id',$id,$type,true,$default);
                if($canonical && $canonical!==$id){$v=(string)get_post_meta($canonical,self::PRICE_META,true);if(in_array($v,array_slice(self::MODES,1),true))return $v;}
            }
        }
        return '';
    }
    /** Effective source selection, NOT a replacement for the native quote/pricing calculator. */
    public static function pricing(int $tour_id=0,int $property_id=0,int $rate_id=0,int $program_term_id=0): array {
        if(class_exists('MAD4B_Company_Policy_Registry_V2')){
            $resolved=MAD4B_Company_Policy_Registry_V2::pricing($tour_id,$property_id,$rate_id,$program_term_id);
            $resolved['source']=$resolved['base_source'];
            return apply_filters('mad4b/company_adaptive/pricing_policy',$resolved,
                                 $tour_id,$property_id,$rate_id,$program_term_id);
        }
        $p=self::policy();$mode=$p['pricing_default'];$source='company';
        if($program_term_id){$v=(string)get_term_meta($program_term_id,self::PRICE_META,true);
            if(in_array($v,array_slice(self::MODES,1),true)){$mode=$v;$source='program_term';}}
        if($property_id){$v=self::inherited_meta($property_id,'properties');if($v!==''){$mode=$v;$source='property';}}
        if($tour_id){$v=self::inherited_meta($tour_id,'tours-and-activities');if($v!==''){$mode=$v;$source='tour';}}
        if($rate_id){$type=get_post_type($rate_id);
            if(in_array($type,['tour-rates','property-rates'],true)){$v=self::inherited_meta($rate_id,$type);if($v!==''){$mode=$v;$source='rate';}}}
        return apply_filters('mad4b/company_adaptive/pricing_policy',[
            'contract'=>'mad4b.company-adaptive-pricing.v1','strategy'=>$mode,'source'=>$source,
            'season_taxonomy'=>'season','amount_authority'=>'existing_rate_records',
            'calculation_owner'=>'native_rate_generator_and_quote_engine','status'=>'policy_selection_only',
        ],$tour_id,$property_id,$rate_id,$program_term_id);
    }
    public static function readiness($providers): array {
        if(!is_array($providers))$providers=[];
        $providers['company_adaptive_controls']=['version'=>self::VERSION,'effective'=>self::policy(),'status'=>'CONFIGURED',
            'pricing_amounts_changed'=>false,'render_strategy'=>'JetEngine_signed_AJAX_for_eligible_listings'];
        return $providers;
    }
    public static function menu(): void {
        add_submenu_page('mad4b-company','MAD4B Company Controls','Adaptive Controls','manage_options','mad4b-company-adaptive-controls',[self::class,'page']);
        add_submenu_page('options-general.php','MAD4B Company Controls','MAD4B Company Controls','manage_options','mad4b-company-adaptive-controls',[self::class,'page']);
    }
    private static function select(string $field,array $choices,string $selected): void {
        echo '<select name="policy['.esc_attr($field).']" id="'.esc_attr($field).'">';
        foreach($choices as $k=>$label)echo '<option value="'.esc_attr($k).'" '.selected($selected,$k,false).'>'.esc_html($label).'</option>';
        echo '</select>';
    }
    public static function page(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden');
        $p=self::policy();
        echo '<div class="wrap"><h1>MAD4B · Company Loading & Pricing Controllers</h1>';
        if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>Company policy saved.</p></div>';
        echo '<p>These settings belong to this company. Overrides are available on individual Listings, Tours, Programs and Properties.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_company_adaptive_save">';wp_nonce_field('mad4b_company_adaptive_save');
        echo '<h2>JetEngine Listing Loading</h2><table class="form-table"><tbody><tr><th>Loading strategy</th><td>';
        self::select('listing_mode',['ajax_only'=>'AJAX only for eligible Listings · default ON','off'=>'Do not force MAD4B adaptive loading'],$p['listing_mode']);
        echo '<p class="description">Eligible grids use JetEngine signed AJAX placeholders; no initial card HTML. Existing filtered, checkout, editing and unsafe contexts are not forcibly converted.</p></td></tr>';
        foreach(['mobile_prefetch_px'=>'Mobile prefetch distance (px)','desktop_prefetch_px'=>'Desktop prefetch distance (px)',
                'mobile_rows'=>'Mobile target rows','tablet_rows'=>'Tablet target rows','desktop_rows'=>'Desktop target rows'] as $key=>$label)
            echo '<tr><th>'.esc_html($label).'</th><td><input type="number" min="0" step="1" name="policy['.esc_attr($key).']" value="'.esc_attr($p[$key]).'"></td></tr>';
        echo '</tbody></table><p class="description">Grid row targets drive JetEngine Infinite Scroll for eligible ordinary grids. Slider and custom Query Builder results load as one deferred AJAX request; Slider pagination remains dependent on its native configuration.</p>';
        echo '<h2>Company Price Source Strategy</h2><table class="form-table"><tbody><tr><th>Default for programs</th><td>';
        self::select('pricing_default',self::pricing_labels(),$p['pricing_default']);
        echo '<p class="description">Season-based fixed amounts are read from existing valid Tour Rates. Dynamic hotel mode requests native Property Rates; final eligibility and amounts remain with the existing calculation engines.</p></td></tr>';
        echo '</tbody></table><p><strong>Precedence:</strong> Program base: Company → Program → Tour → Tour Rate. Hotel component: Company → Property → Property Rate. Versioned rules also match season and request context. WPML translations inherit canonical defaults unless explicitly overridden.</p>';
        submit_button('Save Company Controls');echo '</form>';
        do_action('mad4b/company_adaptive/page_after',$p);
        echo '</div>';
    }
    public static function pricing_labels(): array {
        return ['fixed_seasonal'=>'Fixed programs / fixed prices by season',
            'dynamic_hotel_rates'=>'Dynamic hotel and Property Rates',
            'hybrid'=>'Hybrid: seasonal program and dynamic hotel components'];
    }
    public static function save_company(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('mad4b_company_adaptive_save');
        $raw=isset($_POST['policy'])&&is_array($_POST['policy'])?wp_unslash($_POST['policy']):[];
        $value=self::sanitize($raw);update_option(self::OPTION,$value,false);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-adaptive-controls','updated'=>1],admin_url('options-general.php')));exit;
    }
    public static function meta_box(): void {
        foreach(self::entity_types() as $type)if(post_type_exists($type))add_meta_box('mad4b-company-pricing-source','MAD4B · Pricing Source',[self::class,'meta_box_ui'],$type,'side','default');
        foreach(['jet-engine','elementor_library'] as $type)if(post_type_exists($type))add_meta_box('mad4b-adaptive-listing','MAD4B · Listing Loading',[self::class,'listing_meta_ui'],$type,'side','default');
    }
    public static function meta_box_ui($post): void {
        wp_nonce_field('mad4b_company_object_save','mad4b_company_object_nonce');
        $v=(string)get_post_meta($post->ID,self::PRICE_META,true);
        echo '<label for="mad4b_company_price_mode">Pricing mode override</label><p><select style="width:100%" id="mad4b_company_price_mode" name="mad4b_company_price_mode">';
        foreach(['inherit'=>'Inherit company / parent strategy']+self::pricing_labels() as $key=>$label)
            echo '<option value="'.esc_attr($key).'" '.selected($v?:'inherit',$key,false).'>'.esc_html($label).'</option>';
        echo '</select></p><small>Existing season and Rate records remain authoritative for amounts.</small>';
    }
    public static function listing_meta_ui($post): void {
        wp_nonce_field('mad4b_company_object_save','mad4b_company_object_nonce');
        $v=(string)get_post_meta($post->ID,self::LISTING_META,true);
        echo '<label for="mad4b_company_listing_mode">Loading mode</label><p><select style="width:100%" id="mad4b_company_listing_mode" name="mad4b_company_listing_mode">';
        foreach(['inherit'=>'Company default (AJAX if eligible)','async'=>'Force eligible AJAX','native'=>'Native SSR (explicit opt-out)'] as $key=>$label)
            echo '<option value="'.esc_attr($key).'" '.selected($v?:'inherit',$key,false).'>'.esc_html($label).'</option>';
        echo '</select></p>';
    }
    public static function save_post(int $post_id): void {
        if(empty($_POST['mad4b_company_object_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_company_object_nonce'])),'mad4b_company_object_save')
            ||(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||wp_is_post_revision($post_id)||!current_user_can('edit_post',$post_id))return;
        if(in_array(get_post_type($post_id),self::entity_types(),true)&&isset($_POST['mad4b_company_price_mode'])){
            $v=sanitize_key(wp_unslash($_POST['mad4b_company_price_mode']));
            if(in_array($v,self::MODES,true)){if($v==='inherit')delete_post_meta($post_id,self::PRICE_META);else update_post_meta($post_id,self::PRICE_META,$v);}
        }
        if(in_array(get_post_type($post_id),['jet-engine','elementor_library'],true)&&isset($_POST['mad4b_company_listing_mode'])){
            $v=sanitize_key(wp_unslash($_POST['mad4b_company_listing_mode']));
            if(in_array($v,['inherit','async','native'],true)){if($v==='inherit')delete_post_meta($post_id,self::LISTING_META);else update_post_meta($post_id,self::LISTING_META,$v);}
        }
    }
    public static function term_edit($term): void {
        $v=(string)get_term_meta($term->term_id,self::PRICE_META,true);
        echo '<tr class="form-field"><th><label>MAD4B pricing source</label></th><td><select name="mad4b_company_term_price_mode">';
        foreach(['inherit'=>'Company default']+self::pricing_labels() as $key=>$label)
            echo '<option value="'.esc_attr($key).'" '.selected($v?:'inherit',$key,false).'>'.esc_html($label).'</option>';
        echo '</select>';wp_nonce_field('mad4b_company_term_save','mad4b_company_term_nonce');echo '</td></tr>';
    }
    public static function term_add(): void {
        echo '<div class="form-field"><label>MAD4B pricing source</label><select name="mad4b_company_term_price_mode">';
        foreach(['inherit'=>'Company default']+self::pricing_labels() as $k=>$v)echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';
        echo '</select>';wp_nonce_field('mad4b_company_term_save','mad4b_company_term_nonce');echo '</div>';
    }
    public static function term_save(int $term_id): void {
        if(empty($_POST['mad4b_company_term_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_company_term_nonce'])),'mad4b_company_term_save')
           ||!current_user_can('manage_categories')||!isset($_POST['mad4b_company_term_price_mode']))return;
        $v=sanitize_key(wp_unslash($_POST['mad4b_company_term_price_mode']));
        if(!in_array($v,self::MODES,true))return;
        if($v==='inherit')delete_term_meta($term_id,self::PRICE_META);else update_term_meta($term_id,self::PRICE_META,$v);
    }
    public static function boot(): void {
        add_action('elementor/frontend/widget/before_render',[self::class,'before_widget'],82,1);
        add_filter('jet-engine/listing/grid/is_lazy_load',[self::class,'decision'],90,2);
        add_filter('jet-engine/listing/grid/is_lazy_load',[self::class,'preserve_parent_context'],999,2);
        add_filter('jet-engine/listing/grid/lazy-load/options',[self::class,'offset'],90,2);
        add_filter('mad4b_company_reconcile_component_readiness',[self::class,'readiness'],35,1);
        add_action('admin_menu',[self::class,'menu'],96);
        add_action('admin_post_mad4b_company_adaptive_save',[self::class,'save_company']);
        add_action('add_meta_boxes',[self::class,'meta_box']);
        add_action('save_post',[self::class,'save_post'],20,1);
        add_action('programs_edit_form_fields',[self::class,'term_edit']);
        add_action('programs_add_form_fields',[self::class,'term_add']);
        add_action('edited_programs',[self::class,'term_save']);
        add_action('created_programs',[self::class,'term_save']);
    }
}
MAD4B_Company_Adaptive_Controls::boot();
function mad4b_company_adaptive_pricing_resolve(int $tour_id=0,int $property_id=0,int $rate_id=0,int $program_term_id=0): array {
    return MAD4B_Company_Adaptive_Controls::pricing($tour_id,$property_id,$rate_id,$program_term_id);
}
