<?php
/**
 * MAD4B company country operating scope v1.
 * Service Activation OWNS the operating territory. Destination Activation is
 * a presentation/discovery catalog, not a permit to sell or auto-publish.
 * Country identity comes from the canonical Destination taxonomy, not titles.
 * Does not change Rates, discounts, payment modes or source publication.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Country_Operating_Scope_V1 {
    public const FIELD='operating_country_ids';
    public const VERSION='country_operating_scope_version';
    public const META='_mad4b_required_country_ids_v1';
    private static bool $authorized_scope_save=false;
    public static function boot():void {
        add_filter('pre_update_option_service-activation',[__CLASS__,'preserve_existing_scope'],20,3);
        add_filter('wp_insert_post_data',[__CLASS__,'publication_guard'],90,2);
        add_action('save_post_tours-and-activities',[__CLASS__,'verify_after_save'],70,3);
        add_filter('native_destinations/public_sale_product_allowed',[__CLASS__,'public_sale_allowed'],20,3);
        add_action('template_redirect',[__CLASS__,'guard_single_page'],2);
        if(is_admin()){
            add_action('admin_menu',[__CLASS__,'menu'],80);
            add_action('admin_notices',[__CLASS__,'context_notice'],25);
            add_action('admin_post_mad4b_save_company_operating_scope_v1',[__CLASS__,'save']);
            add_action('add_meta_boxes_tours-and-activities',[__CLASS__,'add_tour_country_meta_box']);
            add_action('save_post_tours-and-activities',[__CLASS__,'save_tour_country_requirements'],25,3);
        }
    }
    public static function preserve_existing_scope($value,$old,$name){
        if(!is_array($value)||!is_array($old))return $value;
        // Native JetEngine option-page saves must never silently erase a field
        // whose UI belongs to the separate authorized geographic-scope editor.
        foreach([self::FIELD,self::VERSION] as $key){
            // Source-clone and generic JetEngine writes cannot widen a tenant's
            // operational territory; only the dedicated nonce-checked editor can.
            if(array_key_exists($key,$old) &&
               (!self::$authorized_scope_save || !array_key_exists($key,$value)))
                $value[$key]=$old[$key];
        }
        return $value;
    }
    /**
     * Marketing attribution parameters are read-only presentation metadata,
     * not a mutation of taxonomy parent rows. Permit their use only for the
     * request-local *raw row memo* on public Tour detail GET. Every pricing,
     * authorization, payment, date/filter and unknown query remains fresh.
     */
    private static function read_only_tracking_query():bool {
        if (!empty($_POST) || !is_array($_GET) || count($_GET)>12) return false;
        if (!$_GET) return empty($_SERVER['QUERY_STRING']);
        foreach ($_GET as $name=>$value) {
            if (!is_string($name) || !preg_match('/^(?:utm_[a-z0-9_]{1,60}|gclid|fbclid|msclkid)$/iD',$name)
                || !is_string($value) || strlen($value)>256) return false;
        }
        return true;
    }
    public static function canonical_country(int $term_id):int {
        if($term_id<1 || !taxonomy_exists('destinations'))return 0;
        // WPML's get_term() changes a canonical EN term into the *current*
        // language, so reading its localized parent and returning that ID
        // can silently turn canonical Egypt 665 into Spanish Egypt 669.
        // Resolve the input to canonical EN, then walk unfiltered native term
        // taxonomy parent IDs. Never use locale-mutated get_term()->parent.
        global $wpdb;
        // The underlying taxonomy parent row is locale-independent by term_id;
        // only the WPML canonical mapping is recalculated for each request.
        // Memoize *rows*, never the eligibility/activation/country decision.
        // Request-local only: no transients, object-cache persistence or writes.
        // All private, administrative, write-capable or unknown contexts must
        // still read the current DB state at every authorization decision.
        static $public_get_parent_rows=[];
        $safe_public_get =
            (string)($_SERVER['REQUEST_METHOD']??'')==='GET'
            && !is_admin()
            && !(defined('WP_CLI')&&WP_CLI)
            && !(defined('REST_REQUEST')&&REST_REQUEST)
            && !(defined('DOING_AJAX')&&DOING_AJAX)
            && !(defined('DOING_CRON')&&DOING_CRON)
            && empty($_GET) && empty($_POST)
            && empty($_SERVER['QUERY_STRING'])
            // WPML can synthesize this locale hint *inside* PHP even when
            // the browser sent no Cookie header. It is not a private session;
            // a real inbound cookie, unknown synthetic cookie or malformed
            // locale must still bypass the parent row memo entirely.
            && (empty($_COOKIE) || (
                is_array($_COOKIE) && count($_COOKIE)===1
                && array_key_exists('wp-wpml_current_language',$_COOKIE)
                && is_string($_COOKIE['wp-wpml_current_language'])
                && preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/iD',$_COOKIE['wp-wpml_current_language'])
            ))
            && empty($_SERVER['HTTP_COOKIE'])
            && empty($_SERVER['HTTP_AUTHORIZATION'])
            && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            && empty($_SERVER['PHP_AUTH_USER'])
            && empty($_SERVER['HTTP_X_WP_NONCE'])
            && !(function_exists('is_user_logged_in')&&is_user_logged_in());
        // A normal public Tour URL can carry a logged-in editor session or a
        // harmless/unknown visitor cookie. Those cookies cannot mutate the
        // *taxonomy parent row* within this read-only HTML render. Reuse raw
        // parent rows for this exact presentation context only; do not memoize
        // the final country approval, public sale or publication decision.
        // Safe analytics UTMs only may use the same raw parent row memo.
        // This is NOT a public-cache bypass, user authorization or WPML
        // cross-request cache. All POST/AJAX/REST/admin/CLI/cron and preview
        // requests retain fresh parent-row reads for every decision.
        $read_only_tour_display =
            (string)($_SERVER['REQUEST_METHOD']??'')==='GET'
            && !is_admin()
            && !(defined('WP_CLI')&&WP_CLI)
            && !(defined('REST_REQUEST')&&REST_REQUEST)
            && !(defined('DOING_AJAX')&&DOING_AJAX)
            && !(defined('DOING_CRON')&&DOING_CRON)
            && self::read_only_tracking_query()
            && function_exists('is_singular') && is_singular('tours-and-activities')
            && !(function_exists('is_preview')&&is_preview())
            && empty($_SERVER['HTTP_AUTHORIZATION'])
            && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            && empty($_SERVER['PHP_AUTH_USER'])
            && empty($_SERVER['HTTP_X_WP_NONCE']);
        $memo_parent_rows = $safe_public_get || $read_only_tour_display;
        $en=(int)apply_filters('wpml_object_id',$term_id,'destinations',false,'en');
        $id=$en?:$term_id;
        $seen=[];
        for($n=0;$id>0 && $n<24;$n++){
            if(isset($seen[$id]))return 0;
            $seen[$id]=true;
            // The fixed-capacity map is intentionally scoped to one PHP
            // request. Even a cached negative row cannot authorize a sale.
            if($memo_parent_rows && array_key_exists($id,$public_get_parent_rows)){
                $row=$public_get_parent_rows[$id];
            }else{
                $row=$wpdb->get_row($wpdb->prepare(
                    "SELECT term_id,parent FROM {$wpdb->term_taxonomy}
                     WHERE taxonomy=%s AND term_id=%d LIMIT 1",
                    'destinations',$id),ARRAY_A);
                if($memo_parent_rows && count($public_get_parent_rows)<512)
                    $public_get_parent_rows[$id]=$row;
            }
            if(!is_array($row))return 0;
            // The metadata belongs to canonical term_id, not a translated
            // ancestor returned by WPML's get_term() interception.
            if((string)get_term_meta($id,'destination_type',true)==='country')return $id;
            $id=(int)$row['parent'];
        }
        return 0;
    }
    public static function canonical_set($ids):array {
        if(!is_array($ids))return [];
        $out=[];
        foreach($ids as $raw){
            if(!(is_int($raw)||is_string($raw))||!ctype_digit((string)$raw))continue;
            $id=(int)$raw;
            $country=self::canonical_country($id);
            if($country>0)$out[$country]=$country;
        }
        sort($out,SORT_NUMERIC);
        return array_values($out);
    }
    public static function operating_countries():array {
        $service=get_option('service-activation',[]);
        if(!is_array($service))return [];
        return self::canonical_set($service[self::FIELD]??[]);
    }
    public static function catalog_countries():array {
        $dest=get_option('destination-activation',[]);
        return self::canonical_set(is_array($dest)?($dest['active_countries']??[]):[]);
    }
    /** Itinerary geography is one cross-language commercial fact.
     * The reviewed EN canonical tour owns country truth for its WPML siblings.
     * A translated tour may provide the initial fact only until EN is reviewed.
     */
    public static function reviewed_countries(int $tour_id):array {
        $local=self::canonical_set(get_post_meta($tour_id,self::META,true));
        $en=(int)apply_filters('wpml_object_id',$tour_id,'tours-and-activities',false,'en');
        if($en>0 && $en!==$tour_id && get_post_type($en)==='tours-and-activities'){
            $source=self::canonical_set(get_post_meta($en,self::META,true));
            if($source)return $source;
        }
        return $local;
    }
    public static function language_country_mismatch(int $tour_id):bool {
        $local=self::canonical_set(get_post_meta($tour_id,self::META,true));
        $en=(int)apply_filters('wpml_object_id',$tour_id,'tours-and-activities',false,'en');
        if(!$local || !$en || $en===$tour_id || get_post_type($en)!=='tours-and-activities')
            return false;
        $source=self::canonical_set(get_post_meta($en,self::META,true));
        return $source && $source!==$local;
    }
    public static function required_countries(int $tour_id):array {
        if(get_post_type($tour_id)!=='tours-and-activities')return [];
        $ids=self::reviewed_countries($tour_id);
        $terms=wp_get_object_terms($tour_id,'destinations',['fields'=>'ids']);
        if(!is_wp_error($terms))$ids=array_merge($ids,self::canonical_set($terms));
        return array_values(array_unique($ids,SORT_NUMERIC));
    }
    public static function is_combined(int $tour_id):bool {
        if(get_post_type($tour_id)!=='tours-and-activities')return false;
        // The tour-types slug is translated, too. A Spanish Combined Tour
        // must not bypass the explicit itinerary-country review requirement.
        global $wpdb;
        static $canonical_combined=null;
        if($canonical_combined===null){
            $canonical_combined=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
                  INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                 WHERE tt.taxonomy=%s AND t.slug=%s LIMIT 1",
                'tour-types','combined-tours'));
        }
        if(!$canonical_combined)return false;
        $terms=wp_get_object_terms($tour_id,'tour-types',['fields'=>'ids']);
        if(is_wp_error($terms))return false;
        foreach($terms as $term_id){
            $en=(int)apply_filters('wpml_object_id',(int)$term_id,'tour-types',false,'en');
            if(($en?:$term_id)===$canonical_combined)return true;
        }
        return false;
    }
    public static function decision(int $tour_id):array {
        $operating=self::operating_countries();
        $required=self::required_countries($tour_id);
        sort($required,SORT_NUMERIC);
        // Multi-destination programs need a reviewed itinerary-country list.
        // The taxonomy alone may still contain Egypt when the itinerary visits
        // Jordan/UAE/Saudi Arabia. An unreviewed Combined Tour cannot publish.
        $explicit=self::reviewed_countries($tour_id);
        $mismatched=self::language_country_mismatch($tour_id);
        $unknown=self::is_combined($tour_id)&&!$explicit;
        $excluded=array_values(array_diff($required,$operating));
        $catalog=self::catalog_countries();
        $not_catalog=array_values(array_diff($required,$catalog));
        $code=!$operating?'HOLD_OPERATING_SCOPE_NOT_CONFIGURED':
            ($mismatched?'HOLD_COUNTRY_REQUIREMENTS_MISMATCH':
            ($unknown?'HOLD_COUNTRY_EVIDENCE_MISSING':
                ($excluded?'HOLD_COUNTRY_NOT_ENABLED':
                    ($not_catalog?'HOLD_DESTINATION_NOT_ACTIVATED':'IN_SCOPE'))));
        return ['contract'=>'mad4b.company-country-operating-scope.v1',
                'tour_id'=>$tour_id,'code'=>$code,'country_ids'=>$required,
                'allowed_country_ids'=>$operating,'excluded_country_ids'=>$excluded,
                'inactive_destination_ids'=>$not_catalog,
                'is_combined'=>self::is_combined($tour_id),
                'language_country_requirements_mismatch'=>$mismatched,
                'reviewed_country_source_id'=>(int)apply_filters('wpml_object_id',$tour_id,'tours-and-activities',false,'en'),
                'eligible_for_geographic_publication'=>$code==='IN_SCOPE',
                'publishes_tour'=>false,'mutates_rates'=>false];
    }
    public static function publication_guard(array $data,array $postarr):array {
        if(($data['post_type']??'')!=='tours-and-activities'||($data['post_status']??'')!=='publish')return $data;
        $id=(int)($postarr['ID']??0);
        if(!$id || get_post_type($id)!=='tours-and-activities')return $data;
        if(!self::decision($id)['eligible_for_geographic_publication'])$data['post_status']='draft';
        return $data;
    }
    private static bool $saving=false;
    public static function verify_after_save(int $id,WP_Post $post,bool $update):void {
        if(self::$saving || wp_is_post_revision($id)||$post->post_status==='trash'||$post->post_status==='auto-draft')return;
        $decision=self::decision($id);
        update_post_meta($id,'_mad4b_country_scope_last_evaluated_v1', $decision['code']);
        if($post->post_status==='publish' && !$decision['eligible_for_geographic_publication']){
            self::$saving=true;
            wp_update_post(['ID'=>$id,'post_status'=>'draft']);
            self::$saving=false;
        }
    }
    public static function public_sale_allowed($allowed,$id,$type){
        if(!$allowed || $type!=='tours-and-activities')return $allowed;
        return self::decision((int)$id)['eligible_for_geographic_publication'];
    }
    public static function guard_single_page():void {
        if(is_admin()||is_user_logged_in()||!is_singular('tours-and-activities'))return;
        $id=(int)get_queried_object_id();
        if($id>0 && !self::decision($id)['eligible_for_geographic_publication']){
            global $wp_query;
            if($wp_query instanceof WP_Query)$wp_query->set_404();
            status_header(404);nocache_headers();
            $file=get_404_template();
            if($file)include $file;
            exit;
        }
    }
    public static function permitted():bool {
        return current_user_can('manage_options') && current_user_can('commerce_manage_pricing');
    }
    public static function add_tour_country_meta_box():void {
        add_meta_box('mad4b-tour-itinerary-countries','Itinerary Countries · Publication Scope',
                     [__CLASS__,'render_tour_country_meta_box'],'tours-and-activities','side','default');
    }
    public static function render_tour_country_meta_box(WP_Post $post):void {
        $selected=self::reviewed_countries($post->ID);
        $catalog=self::catalog_countries();
        wp_nonce_field('mad4b_tour_itinerary_country_review_v1','mad4b_tour_itinerary_country_nonce');
        echo '<input type="hidden" name="mad4b_tour_itinerary_country_submitted" value="1">';
        echo '<p>Select all countries actually visited by the itinerary, not only the booking market or enabled destination menu. Combined Tours require explicit review before publishing.</p>';
        foreach($catalog as $id){$term=get_term($id,'destinations');if(!$term instanceof WP_Term)continue;
            echo '<p><label><input type="checkbox" name="mad4b_itinerary_country_ids[]" value="'.absint($id).'" '.checked(in_array($id,$selected,true),true,false).'> '.esc_html($term->name).'</label></p>';
        }
        $d=self::decision($post->ID);
        echo '<p><strong>Company territory:</strong> '.esc_html(self::labels(self::operating_countries())).'</p>';
        echo '<p><strong>Current decision:</strong> <code>'.esc_html($d['code']).'</code></p>';
        echo '<p><small>Countries may be activated for discovery without authorizing operation. This editor never publishes a Tour or changes Rates.</small></p>';
    }
    public static function save_tour_country_requirements(int $id,WP_Post $post,bool $update):void {
        if(!isset($_POST['mad4b_tour_itinerary_country_submitted'])||!isset($_POST['mad4b_tour_itinerary_country_nonce'])
            ||wp_is_post_revision($id)||!current_user_can('edit_post',$id))return;
        $nonce=sanitize_text_field((string)wp_unslash($_POST['mad4b_tour_itinerary_country_nonce']));
        if(!wp_verify_nonce($nonce,'mad4b_tour_itinerary_country_review_v1'))return;
        $raw=isset($_POST['mad4b_itinerary_country_ids'])?wp_unslash($_POST['mad4b_itinerary_country_ids']):[];
        if(!is_array($raw))return;
        $countries=self::canonical_set($raw);
        if(array_diff($countries,self::catalog_countries()))return;
        if(!$countries){delete_post_meta($id,self::META);return;}
        update_post_meta($id,self::META,$countries);
        $lang=apply_filters('wpml_post_language_details',null,$id)['language_code']??'en';
        $existing=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
        if(is_wp_error($existing))return;
        foreach($countries as $country){
            $translated=(int)apply_filters('wpml_object_id',$country,'destinations',false,$lang);
            $term=$translated?:$country;
            if(!in_array($term,$existing,true)){
                $added=wp_set_object_terms($id,[$term],'destinations',true);
                if(!is_wp_error($added))$existing[]=$term;
            }
        }
    }
    public static function menu():void {
        if(!self::permitted())return;
        add_submenu_page('mad4b-pricing-workspace','Company Operating Countries','Operating Countries',
                         'manage_options','mad4b-company-operating-countries',[__CLASS__,'page']);
        // Standalone fallback if the Pricing Workspace module isn't activated.
        if(!function_exists('get_plugin_page_hookname') || !menu_page_url('mad4b-pricing-workspace',false))
            add_options_page('Company Operating Countries','Company Operating Countries',
                             'manage_options','mad4b-company-operating-countries',[__CLASS__,'page']);
    }
    public static function link():string {
        return admin_url('admin.php?page=mad4b-company-operating-countries');
    }
    public static function labels(array $ids):string {
        $out=[];foreach($ids as $id){$term=get_term((int)$id,'destinations');
            if($term instanceof WP_Term)$out[]=$term->name;}
        return implode(', ',$out);
    }
    public static function context_notice():void {
        if(!self::permitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['service-activation','destination-activation','mad4b-pricing-workspace'],true))return;
        $operating=self::operating_countries();$catalog=self::catalog_countries();
        echo '<div class="notice notice-info"><p><strong>Company Operating Countries:</strong> '.esc_html(self::labels($operating)?:'Not configured').'. ';
        echo 'Destination Activation lists '.count($catalog).' catalog countries; this does not authorize operating there. ';
        echo '<a href="'.esc_url(self::link()).'">Review country scope and Tour Rate holds</a>.</p></div>';
    }
    public static function preview_rows():array {
        $ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],
            'numberposts'=>75,'fields'=>'ids','orderby'=>'modified','order'=>'DESC',
            'meta_query'=>[['key'=>self::META,'compare'=>'EXISTS']]]);
        // Combined Tours without a complete explicit country contract are also reviewed.
        $combined=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],
            'numberposts'=>75,'fields'=>'ids','tax_query'=>[['taxonomy'=>'tour-types','field'=>'slug','terms'=>['combined-tours']]]]);
        $ids=array_unique(array_merge($ids,$combined));$rows=[];
        foreach($ids as $id){$status=get_post_status($id);$decision=self::decision((int)$id);
            if($decision['code']==='IN_SCOPE')continue;
            $rows[]=['id'=>(int)$id,'title'=>get_the_title($id),'post_status'=>$status,
                     'required_country_ids'=>$decision['country_ids'],
                     'excluded_country_ids'=>$decision['excluded_country_ids'],
                     'code'=>$decision['code']];
        }
        return $rows;
    }
    public static function page():void {
        if(!self::permitted())wp_die('Not permitted',403);
        $service=get_option('service-activation',[]);$catalog=self::catalog_countries();
        $allowed=self::operating_countries();
        echo '<div class="wrap"><h1>Company Operating Countries</h1>';
        echo '<p>Owned by Service Activation. Destination Activation controls catalog/discovery and does not authorize countries for booking or publication.</p>';
        if(($_GET['saved']??'')==='1')echo '<div class="notice notice-success"><p>Operating scope saved. Draft Tours remain Draft.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_country_operating_scope_save_v1');
        echo '<input type="hidden" name="action" value="mad4b_save_company_operating_scope_v1">';
        echo '<table class="widefat striped"><thead><tr><th>Operate</th><th>Country</th><th>Destination Catalog</th></tr></thead><tbody>';
        foreach($catalog as $id){$t=get_term($id,'destinations');if(!$t instanceof WP_Term)continue;
            echo '<tr><td><input type="checkbox" name="countries[]" value="'.absint($id).'" '.checked(in_array($id,$allowed,true),true,false).'></td><td>'.esc_html($t->name).'</td><td>Available as catalog destination</td></tr>';
        }
        echo '</tbody></table><p class="description">Enabling a country does not publish Tours, activate payments, approve suppliers, or verify visa/transport readiness.</p>';
        submit_button('Save Operating Countries');echo '</form>';
        echo '<h2>Country-related Tour and Rate holds</h2>';
        $rows=self::preview_rows();
        if(!$rows)echo '<p>No scope holds identified in reviewed country-linked and Combined Tours.</p>';
        else{
            echo '<table class="widefat striped"><thead><tr><th>Tour</th><th>Status</th><th>Required countries</th><th>Scope decision</th></tr></thead><tbody>';
            foreach($rows as $row){echo '<tr><td><a href="'.esc_url(get_edit_post_link($row['id'])).'">'.esc_html($row['title']).'</a></td><td>'.esc_html($row['post_status']).'</td><td>'.esc_html(self::labels($row['required_country_ids'])).'</td><td><code>'.esc_html($row['code']).'</code></td></tr>';}
            echo '</tbody></table>';
        }
        echo '<p>Published child Tour Rates are retained for editors. They never override a held parent Tour. Geo eligibility is not financial readiness.</p></div>';
    }
    public static function save():void {
        if(!self::permitted())wp_die('Not permitted',403);
        check_admin_referer('mad4b_country_operating_scope_save_v1');
        $raw=isset($_POST['countries'])?wp_unslash($_POST['countries']):[];
        if(!is_array($raw))wp_die('Invalid scope',400);
        $scope=self::canonical_set($raw);$catalog=self::catalog_countries();
        if(!$scope || array_diff($scope,$catalog))wp_die('At least one activated country required',400);
        $option=get_option('service-activation',[]);
        if(!is_array($option))wp_die('Service Activation unavailable',500);
        $option[self::FIELD]=$scope;$option[self::VERSION]='1';
        self::$authorized_scope_save=true;
        try { update_option('service-activation',$option); }
        finally { self::$authorized_scope_save=false; }
        if(self::operating_countries()!==$scope)wp_die('Country scope write verification failed',500);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-operating-countries','saved'=>'1'],admin_url('admin.php')));
        exit;
    }
}
MAD4B_Company_Country_Operating_Scope_V1::boot();
