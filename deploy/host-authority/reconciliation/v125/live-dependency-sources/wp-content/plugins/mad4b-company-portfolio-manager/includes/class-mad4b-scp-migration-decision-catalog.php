<?php
/** Tenant-local, secret-free snapshot comparison. No assets are modified. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Migration_Decision_Catalog {
    const CONTRACT='mad4b.company-migration-asset-catalog.v1';
    public static function definitions(): array {
        return [
            'brand_kit'=>['Elementor Brand Kit','elementor-kit','Brand tokens & Global Styles'],
            'templates'=>['Elementor Templates','elementor-templates','Theme Builder, dynamic tags and conditions'],
            'featured_media'=>['Images & galleries','media','Post-level local featured images and selected media'],
            'presentation'=>['Card / Accordion','presentation','Layout selection through tenant presentation resolver'],
            'snippets'=>['Code Snippets & scripts','script-registry','Reviewed code with no auto execution'],
            'mu_plugins'=>['Must-use and Jet helpers','adapter-registry','Certified providers replacing legacy compatibility patches'],
            'licensed_plugins'=>['Licensed plugins','license-registry','Company-owned entitlements with domain revalidation'],
            'jetengine'=>['JetEngine CPT / relations','jetengine-model','Typed relation, taxonomy and query resolvers'],
            'wpml'=>['Languages and translations','wpml-map','Canonical language and translation mapping'],
            'forms'=>['Fluent Forms & operational data','forms-contract','Form fields and submission event contracts'],
            'commerce'=>['Products / rates / checkout','commerce-resolver','Certified price, inventory and order item resolver'],
            'theme'=>['Theme, CSS & child hooks','theme-tokens','Company-scoped styles, reviewed hooks'],
            'communications'=>['GTM, SMTP, notifications','communication-policy','Event routing, consent and communication preferences'],
            'special_pages'=>['About Us, Privacy & Special Pages','page-migration-policy','Page-specific legal, SEO, layout, content and translation comparison']
        ];
    }
    public static function choices(): array {
        return [
            'use_new'=>'Use verified new system',
            'keep_existing'=>'Keep compatible legacy version',
            'merge_selected'=>'Merge selected company fields',
            'fork_legacy'=>'Fork an isolated legacy variant',
            'retire'=>'Retire only after replacement QA',
            'defer_for_review'=>'Defer for manual review'
        ];
    }
    public static function page_key($id): string {
        return 'page_'.absint($id);
    }
    public static function special_pages(): array {
        global $wpdb;
        $option=(int)get_option('wp_page_for_privacy_policy',0);
        // Bounded discovery only; any explicitly requested page ID can also be reviewed.
        $results=$wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_name,post_title FROM {$wpdb->posts}
             WHERE post_type=%s AND post_status IN ('publish','private','draft','future')
             ORDER BY ID DESC LIMIT 1500",'page'),ARRAY_A);
        $matches=[];
        foreach((array)$results as $r) {
            $id=(int)$r['ID'];
            $label=mb_strtolower($r['post_name'].' '.$r['post_title']);
            if($id===$option || preg_match('~about|about-us|who-we-are|privacy|policy|terms|cookie|contact|faq|refund|return|من نحن|معلومات عنا|الخصوصية|الشروط|تواصل|سياسة|اتصل|من-نحن|خصوصية~u',$label)) {
                $matches[]=$id;
                if(count($matches)>=40)break;
            }
        }
        if($option>0 && !in_array($option,$matches,true) && self::valid_page($option))
            array_unshift($matches,$option);
        return array_values(array_unique($matches));
    }
    public static function valid_page($id): bool {
        $id=(int)$id;
        $p=$id>0?get_post($id):null;
        return $p && $p->post_type==='page' &&
           in_array($p->post_status,['publish','private','draft','future'],true);
    }
    public static function asset_supported($key): bool {
        if(isset(self::definitions()[$key]))return true;
        return preg_match('/^page_([1-9][0-9]*)$/D',(string)$key,$m)===1
           && self::valid_page((int)$m[1]);
    }
    public static function legal_page($id): bool {
        $id=(int)$id;
        $page=get_post($id);
        if(!$page || $page->post_type!=='page')return false;
        $combined=mb_strtolower($page->post_name.' '.$page->post_title);
        return (int)get_option('wp_page_for_privacy_policy',0)===$id ||
           preg_match('~privacy|policy|terms|cookie|refund|return|الخصوصية|الشروط|سياسة|استرجاع|الإرجاع~u',$combined)===1;
    }
    private static function page_summary($id): array {
        $page=get_post((int)$id);
        if(!$page || !self::valid_page($id))return [];
        $elementor=(string)get_post_meta($id,'_elementor_data',true);
        $wpml=has_filter('wpml_post_language_details')
            ?apply_filters('wpml_post_language_details',null,(int)$id):null;
        $language=is_array($wpml)?(string)($wpml['language_code']??''):strtolower(substr(get_locale(),0,2));
        return ['provider'=>'native-wordpress-page','status'=>'inspected',
            'details'=>[
              'page_id'=>(int)$id,
              'title'=>(string)$page->post_title,
              'slug'=>(string)$page->post_name,
              'status'=>(string)$page->post_status,
              'language'=>$language,
              'url'=>get_permalink($id),
              'page_for_privacy_policy'=>(int)get_option('wp_page_for_privacy_policy',0)===(int)$id,
              'legal_review_required'=>self::legal_page($id),
              'template'=>(string)get_page_template_slug($id),
              'elementor_edited'=>get_post_meta($id,'_elementor_edit_mode',true)==='builder',
              'featured_media_id'=>(int)get_post_thumbnail_id($id),
              'content_sha256'=>hash('sha256',(string)$page->post_content),
              'elementor_layout_sha256'=>hash('sha256',$elementor),
              'updated_utc'=>(string)$page->post_modified_gmt,
              'wpml_translation_group'=>is_array($wpml)?(int)($wpml['trid']??0):0,
              'preserve_url_slug_and_legal_validity'=>true,
              'source_page_id_not_cross_site_portable'=>true
            ]];
    }
    private static function matching_plugins(array $needle): array {
        if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $all=get_plugins(); $hits=[];
        foreach($all as $file=>$meta) {
            foreach($needle as $part) {
                if(str_contains(strtolower($file),$part)){
                    $hits[]=['file'=>$file,'version'=>(string)($meta['Version']??''),
                        'active'=>is_plugin_active($file)];
                    break;
                }
            }
        }
        usort($hits,static fn($a,$b)=>strcmp($a['file'],$b['file']));
        return array_slice($hits,0,75);
    }
    private static function counted_table($suffix): int {
        global $wpdb;
        $table=$wpdb->prefix.$suffix;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)
            return 0;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    }
    private static function post_type_count($type): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status NOT IN ('trash','auto-draft')",
            $type));
    }
    public static function current($key): array {
        if(preg_match('/^page_([1-9][0-9]*)$/D',(string)$key,$found))
            return self::page_summary((int)$found[1]);
        $x=['provider'=>'native-wordpress','status'=>'inspected','details'=>[]];
        switch($key) {
        case 'brand_kit':
            $id=(int)get_option('elementor_active_kit',0);
            $settings=$id?get_post_meta($id,'_elementor_page_settings',true):[];
            $x['details']=['active_kit_post_id'=>$id,'kit_exists'=>(bool)get_post($id),
                'global_setting_keys'=>is_array($settings)?array_slice(array_keys($settings),0,80):[],
                'plugins'=>self::matching_plugins(['elementor/','elementor-pro/'])];
            break;
        case 'templates':
            $x['details']=['elementor_library_records'=>self::post_type_count('elementor_library'),
                'template_plugins'=>self::matching_plugins(['elementor/','elementor-pro/','header-footer-elementor/'])];
            break;
        case 'featured_media':
            global $wpdb;
            $x['details']=['posts_with_featured_meta'=>(int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key=%s",'_thumbnail_id')),
                'attachment_records'=>self::post_type_count('attachment')];
            break;
        case 'presentation':
            $x['details']=['layout_extension'=>self::matching_plugins(['jet-engine-layout-switcher/']),
                'supported_new_values'=>['card','accordion']];
            break;
        case 'snippets':
            $x['details']=['snippet_records'=>self::counted_table('snippets'),
                'script_plugins'=>self::matching_plugins(['code-snippets/','custom-css-js/',
                    'insert-headers-and-footers/'])];
            break;
        case 'mu_plugins':
            $dir=WPMU_PLUGIN_DIR;
            $names=is_dir($dir)?array_values(array_filter(scandir($dir),static fn($f)=>
                preg_match('/^[a-z0-9_.-]+\.php$/i',$f))):[];
            $x['details']=['php_file_count'=>count($names),
                'file_names'=>array_slice($names,0,100),
                'jet_extensions'=>self::matching_plugins(['jet-engine-','jet-smart-filters-'])];
            break;
        case 'licensed_plugins':
            $x['details']=['installed_license_candidate_modules'=>self::matching_plugins([
                'elementor-pro/','astra-addon/','jet-engine/','jet-smart-filters/',
                'sitepress-multilingual-cms/','fluentformpro/','seo-by-rank-math-pro/'])];
            break;
        case 'jetengine':
            $x['details']=['jet_post_type_definitions'=>self::counted_table('jet_post_types'),
                'jet_taxonomy_definitions'=>self::counted_table('jet_taxonomies'),
                'jet_relation_rows'=>self::counted_table('jet_rel_default'),
                'plugins'=>self::matching_plugins(['jet-engine/','jet-smart-filters/'])];
            break;
        case 'wpml':
            $x['details']=['translation_relationship_rows'=>self::counted_table('icl_translations'),
                'plugins'=>self::matching_plugins(['sitepress-multilingual-cms/','wpml-'])];
            break;
        case 'forms':
            $x['details']=['form_definitions'=>self::counted_table('fluentform_forms'),
                'form_submissions'=>self::counted_table('fluentform_submissions'),
                'plugins'=>self::matching_plugins(['fluentform/','fluentformpro/','jetformbuilder/'])];
            break;
        case 'commerce':
            $x['details']=['products'=>self::post_type_count('product'),
                'tour_rates'=>self::post_type_count('tour-rates'),
                'orders_cpt'=>self::post_type_count('tour_orders'),
                'woocommerce'=>self::matching_plugins(['woocommerce/'])];
            break;
        case 'theme':
            $theme=wp_get_theme();$parent=$theme->parent();
            $x['details']=['active_theme'=>$theme->get_stylesheet(),
                'parent_theme'=>$parent?$parent->get_stylesheet():'',
                'theme_version'=>(string)$theme->get('Version')];
            break;
        case 'special_pages':
            $pages=self::special_pages();
            $x['details']=['candidate_page_ids'=>$pages,
                'candidate_count'=>count($pages),
                'privacy_page_id'=>(int)get_option('wp_page_for_privacy_policy',0),
                'candidate_detection'=>'read-only; by title/slug and assigned privacy page',
                'manual_post_id_selection_supported'=>true];
            break;
        case 'communications':
            $x['details']=['communication_plugins'=>self::matching_plugins([
                'duracelltomi-google-tag-manager/','fluent-smtp/',
                'insert-headers-and-footers/','wp-mail-smtp/'])];
            break;
        default: return [];
        }
        // Do not return any license, code, credential, serialized template or customer records.
        return $x;
    }
    public static function comparison($key): array {
        $defs=self::definitions();
        if(!self::asset_supported($key))return [];
        $isPage=preg_match('/^page_([1-9][0-9]*)$/D',(string)$key,$m)===1;
        $page=$isPage?get_post((int)$m[1]):null;
        $definition=$isPage?
           ['Page: '.(string)$page->post_title,'page-specific-content',
             'Page-specific template, SEO, legal content and language mapping']:
           $defs[$key];
        $source=self::current($key);
        $target=[
            'capability'=>$definition[1],
            'description'=>$definition[2],
            'installed_scp_version'=>defined('MAD4B_SCP_VERSION')?MAD4B_SCP_VERSION:'unknown',
            'certification'=>'NOT_VERIFIED_FOR_THIS_COMPANY',
            'external_mutation_enabled'=>false
        ];
        return [
            'key'=>$key,'label'=>$definition[0],
            'existing'=>$source,'target'=>$target,
            'existing_sha256'=>hash('sha256',wp_json_encode($source)),
            'target_sha256'=>hash('sha256',wp_json_encode($target)),
            'choices'=>self::choices(),
            'requires_company_specific_review'=>true,
            'requires_legal_signoff'=>$isPage && self::legal_page((int)$m[1]),
            'page_id'=>$isPage?(int)$m[1]:null,
            'page_specific_choice'=>$isPage
        ];
    }
}
