<?php
/**
 * MAD4B Company public login and admin appearance.
 * Styles only. WordPress core owns passwords, reset, sessions and redirects.
 * Never adds users, changes roles, or changes login/lostpassword execution.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/company-admin-experience-v1.php';
final class MAD4B_Company_System_Appearance_V1 {
    public const CONTRACT='mad4b.company-system-appearance.v1';
    private static function name():string {
        $s=trim(wp_strip_all_tags((string)get_bloginfo('name')));
        return $s!==''?mb_substr($s,0,72):'Company Website';
    }
    private static function local_logo():string {
        $id=(int)get_theme_mod('custom_logo');
        if(!$id)$id=(int)get_option('site_logo');
        $url=$id?wp_get_attachment_image_url($id,'full'):false;
        if(!$url)$url=get_site_icon_url(192);
        if(!is_string($url)||!preg_match('~^https://~i',$url))return '';
        $host=wp_parse_url(home_url('/'),PHP_URL_HOST);
        if(strcasecmp((string)wp_parse_url($url,PHP_URL_HOST),(string)$host)!==0)return '';
        return esc_url_raw($url);
    }
    /** Original public API kept for Source and Company Reconcile. */
    public static function palette():array {return MAD4B_Admin_Experience_V1::site_palette();}
    public static function palette_style():string {
        $colors=self::palette();
        $vars=['primary'=>'primary','secondary'=>'secondary','text'=>'text','accent'=>'accent',
            'warm'=>'warm','light'=>'light'];
        $s='body.login,body.wp-admin{';
        foreach($vars as $key=>$css){$s.='--mad4b-'.$css.':'.$colors[$key].';';}
        return $s.'}';
    }
    /** Keep WPML's original login selector when its URL converter is enabled.
     * When a tenant disables that provider, expose safe WordPress wp_lang
     * navigation without changing rewrite rules, login endpoints or cookies. */
    public static function login_language_fallback():void {
        if(!defined('ICL_SITEPRESS_VERSION'))return;
        if(class_exists('\WPML\UrlHandling\WPLoginUrlConverter',false) &&
            \WPML\UrlHandling\WPLoginUrlConverter::isEnabled())return;
        $languages=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if(!is_array($languages) || count($languages)<2)return;
        global $sitepress;
        $options=[];
        foreach($languages as $code=>$item){
            if(!is_array($item))continue;
            $lang=(string)($item['language_code']??$code);
            if(!preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/iD',$lang))continue;
            $locale=is_object($sitepress)&&method_exists($sitepress,'get_locale_from_language_code')
                ?(string)$sitepress->get_locale_from_language_code($lang):'';
            if(!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2,3})?$/D',$locale))continue;
            $name=(string)($item['native_name']??$item['translated_name']??$lang);
            $options[$locale]=mb_substr(wp_strip_all_tags($name),0,42);
        }
        if(count($options)<2)return;
        $selected=isset($_GET['wp_lang'])?sanitize_text_field(wp_unslash($_GET['wp_lang'])):determine_locale();
        if(!isset($options[$selected]))$selected=array_key_first($options);
        echo '<div class="mad4b-login-language" role="navigation" aria-label="'.esc_attr__('Login language','default').'">';
        echo '<form method="get" action="'.esc_url(site_url('wp-login.php','login')).'">';
        echo '<label for="mad4b-login-language-choice">'.esc_html__('Language','default').'</label>';
        echo '<select id="mad4b-login-language-choice" name="wp_lang">';
        foreach($options as $locale=>$name)echo '<option value="'.esc_attr($locale).'" '.selected($selected,$locale,false).'>'.esc_html($name).'</option>';
        echo '</select>';
        $action=isset($_GET['action'])?sanitize_key(wp_unslash($_GET['action'])):'';
        if(in_array($action,['lostpassword','retrievepassword','register','rp','resetpass'],true))
            echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
        // Preserve a safe recovery flow without copying arbitrary URLs.
        foreach(['key','login'] as $param){
            if(in_array($action,['rp','resetpass'],true)&&isset($_GET[$param])&&is_string($_GET[$param])){
                $v=sanitize_text_field(wp_unslash($_GET[$param]));
                if(strlen($v)<=128)echo '<input type="hidden" name="'.esc_attr($param).'" value="'.esc_attr($v).'">';
            }
        }
        echo '<button type="submit" class="button">'.esc_html__('Change','default').'</button>';
        echo '</form></div>';
    }
    /** WordPress admin commits refresh the offline maintenance public snapshot.
     * No background poll, personal data, credentials, or WP authentication changes.
     */
    public static function sync_public_appearance():bool {
        if (!class_exists('MAD4B_SCP_Site_Profile',false))return false;
        $status=MAD4B_SCP_Site_Profile::status();
        if (empty($status['configured'])||empty($status['origin_match'])||
            empty($status['environment_match'])||empty($status['deployment_binding_match']))return false;
        $name=self::name();$origin=untrailingslashit(home_url('/'));
        $colors=self::palette();
        if(!preg_match('~^https://[a-z0-9.-]+$~iD',$origin))return false;
        $public=['contract'=>'mad4b.company-appearance-public.v1','site_name'=>$name,
            'site_origin'=>$origin,'logo_url'=>self::local_logo(),
            'kit_id'=>(int)get_option('elementor_active_kit')];
        foreach(['primary','secondary','accent','warm'] as $k){
            $v=$colors[$k]??'';
            if(!is_string($v)||!preg_match('/^#[a-f0-9]{6}$/iD',$v))return false;
            $public[$k]=$v;
        }
        $destination=WP_CONTENT_DIR.'/mad4b-company-appearance-public.json';
        $current=is_readable($destination)?json_decode((string)file_get_contents($destination),true):null;
        if($current===$public)return true;
        // Temp files are local to wp-content to guarantee atomic same-volume rename.
        if(!is_writable(WP_CONTENT_DIR))return false;
        $temp=WP_CONTENT_DIR.'/.mad4b-appearance-public-'.wp_generate_password(12,false,false).'.tmp';
        $payload=wp_json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
        if(!is_string($payload)||strlen($payload)>8192)return false;
        if(file_put_contents($temp,$payload,LOCK_EX)!==strlen($payload)){
            @unlink($temp);return false;
        }
        @chmod($temp,0644);
        if(!rename($temp,$destination)){@unlink($temp);return false;}
        return true;
    }
    public static function on_site_option_change($option):void {
        if(!is_string($option))return;
        if(in_array($option,['elementor_active_kit','blogname','site_icon'],true)||
            str_starts_with($option,'theme_mods_'))self::sync_public_appearance();
    }
    public static function on_kit_meta_change($metaId,$postId,$key):void {
        if((int)$postId===(int)get_option('elementor_active_kit') &&
            $key==='_elementor_page_settings')self::sync_public_appearance();
    }
    public static function style_file():string {return __DIR__.'/assets/company-system-appearance-v1.css';}
    public static function enqueue_login():void {
        $file=self::style_file();if(!is_readable($file))return;
        wp_enqueue_style('mad4b-company-system-appearance',plugins_url('assets/company-system-appearance-v1.css',__FILE__),[],(string)filemtime($file));
        $name=wp_json_encode(self::name(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
        $inline='body.login #login h1 a::after{content:'.$name.';}';
        $logo=self::local_logo();
        if($logo){
            // Only WordPress library images on the site's own HTTPS hostname.
            $inline.='body.login #login h1 a{background-image:url('.wp_json_encode($logo,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP).')!important;background-size:contain!important;}';
            $inline.='body.login #login h1 a::before{display:none}';
        }
        wp_add_inline_style('mad4b-company-system-appearance',self::palette_style().$inline);
    }
    /** User-admin locale labels; frontend WPML language selection is separate. */
    public static function admin_labels(string $locale=''):array {
        $lang=strtolower(substr($locale!==''?$locale:get_user_locale(),0,2));
        if($lang==='ar')return [
            'notices'=>['title'=>'الإشعارات','description'=>'تجميع حسب الإضافة ونوع المشكلة، مع الاحتفاظ بأزرار الإشعارات الأصلية.',
                'errorLabel'=>'أخطاء','warningLabel'=>'تحذيرات',
                'families'=>['mad4b'=>'MAD4B · الشركات','jet'=>'JetEngine وCrocoblock',
                    'elementor'=>'Elementor','wpml'=>'WPML · اللغات','woo'=>'WooCommerce',
                    'fluent'=>'Fluent Suite','seo'=>'SEO والتحليلات','astra'=>'القوالب والتصميم',
                    'other'=>'WordPress وإضافات أخرى'],
                'topics'=>['critical'=>'أخطاء · تحتاج معالجة','warning'=>'تحذيرات · تحتاج مراجعة',
                    'update'=>'تحديثات وتوافق','security'=>'الأمان والصلاحيات',
                    'content'=>'المحتوى والترجمة','performance'=>'الأداء والكاش',
                    'success'=>'مكتمل','info'=>'معلومات وإعدادات']],
            'table'=>['cards'=>'بطاقات','table'=>'جدول','records'=>'السجلات',
                'scroll'=>'مرّر أفقيًا لعرض باقي الأعمدة',
                'showDetails'=>'تفاصيل إضافية','hideDetails'=>'إخفاء التفاصيل']];
        if($lang==='es')return [
            'notices'=>['title'=>'Notificaciones','description'=>'Agrupadas por complemento y tipo de problema.',
                'errorLabel'=>'errores','warningLabel'=>'avisos',
                'topics'=>['critical'=>'Errores · Acción necesaria','warning'=>'Avisos · Revisar',
                    'update'=>'Actualizaciones','security'=>'Seguridad y acceso',
                    'content'=>'Contenido y traducción','performance'=>'Rendimiento y caché',
                    'success'=>'Completado','info'=>'Información y ajustes']],
            'table'=>['cards'=>'Tarjetas','table'=>'Tabla','records'=>'Registros',
                'scroll'=>'Desplaza horizontalmente para ver más columnas',
                'showDetails'=>'Más campos','hideDetails'=>'Menos campos']];
        return ['notices'=>[],'table'=>[]];
    }
    public static function enqueue_admin():void {
        MAD4B_Admin_Experience_V1::enqueue();
    }
    /** Original native Company Reconcile provider. No WordPress mutations. */
    public static function reconcile_readiness():array {
        $profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
        $identity=!empty($profile['configured'])&&!empty($profile['origin_match'])&&
            !empty($profile['environment_match'])&&!empty($profile['deployment_binding_match']);
        $css=self::style_file();
        $js=__DIR__.'/assets/company-admin-notice-accordion-v1.js';
        $themeToggle=__DIR__.'/assets/company-admin-theme-toggle-v1.js';
        $contrastAuditor=__DIR__.'/assets/company-admin-contrast-auditor-v1.js';
        $uiAutoScan=__DIR__.'/assets/company-admin-ui-auto-scan-v1.js';
        $maintenance=WP_CONTENT_DIR.'/maintenance.php';
        $snapshot=WP_CONTENT_DIR.'/mad4b-company-appearance-public.json';
        $data=null;
        if(is_readable($snapshot) && filesize($snapshot)>0 && filesize($snapshot)<8192){
            $read=file_get_contents($snapshot);
            $data=is_string($read)?json_decode($read,true):null;
        }
        $palette=self::palette();
        $snapshotFresh=is_array($data) &&
            ($data['contract']??'')==='mad4b.company-appearance-public.v1' &&
            ($data['site_origin']??'')===untrailingslashit(home_url('/')) &&
            ($data['site_name']??'')===self::name() &&
            (int)($data['kit_id']??-1)===(int)get_option('elementor_active_kit') &&
            (string)($data['logo_url']??'')===self::local_logo();
        foreach(['primary','secondary','accent','warm'] as $key){
            if(!$snapshotFresh)break;
            $snapshotFresh=isset($data[$key]) && hash_equals((string)$palette[$key],(string)$data[$key]);
        }
        $settings=get_option('icl_sitepress_settings',[]);
        $languages=[];
        if(is_array($settings)){
            foreach((array)($settings['active_languages']??[]) as $lang){
                if(is_string($lang) && preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/iD',$lang))$languages[]=$lang;
            }
        }
        $native=class_exists('\WPML\UrlHandling\WPLoginUrlConverter',false)
            ?\WPML\UrlHandling\WPLoginUrlConverter::isEnabled():false;
        $fallback=has_action('login_footer',[__CLASS__,'login_language_fallback'])!==false;
        $issues=[];
        if(!$identity)$issues[]='company_site_identity_not_verified';
        if(!is_readable($css))$issues[]='system_appearance_css_missing';
        if(!class_exists('MAD4B_Admin_Experience_V1',false))$issues[]='company_admin_experience_module_missing';
        if(!is_readable($js))$issues[]='admin_notice_nested_script_missing';
        if(!is_readable($themeToggle))$issues[]='admin_dark_mode_switcher_missing';
        if(!is_readable($contrastAuditor))$issues[]='admin_semantic_contrast_auditor_missing';
        if(!is_readable($uiAutoScan)||!class_exists('MAD4B_Admin_UI_Auto_Scan_V1',false))$issues[]='admin_ui_auto_scan_missing';
        if(!is_readable($maintenance))$issues[]='maintenance_template_missing';
        if(!$snapshotFresh)$issues[]='maintenance_live_brand_kit_snapshot_stale';
        if(has_action('login_enqueue_scripts',[__CLASS__,'enqueue_login'])===false)$issues[]='login_style_hook_missing';
        if(has_action('admin_enqueue_scripts',[__CLASS__,'enqueue_admin'])===false)$issues[]='admin_style_hook_missing';
        if(count($languages)>1&&!$native&&!$fallback)$issues[]='multilingual_login_switcher_missing';
        return ['contract'=>'mad4b.company-system-appearance-readiness.v1',
            'state'=>$issues?'RECONCILE_REQUIRED':'SOURCE_SYSTEM_APPEARANCE_READBACK_PASS',
            'site_origin'=>untrailingslashit(home_url('/')),
            'site_uuid'=>$profile['site_uuid']??null,
            'active_elementor_kit'=>(int)get_option('elementor_active_kit'),
            'admin_appearance_palette_source'=>MAD4B_Admin_Experience_V1::palette_source(),
            'admin_appearance_module_contract'=>MAD4B_Admin_Experience_V1::MODULE_CONTRACT,
            'palette'=>$palette,
            'source_styling_css_sha256'=>is_readable($css)?hash_file('sha256',$css):null,
            'admin_notice_js_sha256'=>is_readable($js)?hash_file('sha256',$js):null,
            'admin_theme_switcher_js_sha256'=>is_readable($themeToggle)?hash_file('sha256',$themeToggle):null,
            'admin_semantic_contrast_js_sha256'=>is_readable($contrastAuditor)?hash_file('sha256',$contrastAuditor):null,
            'admin_ui_auto_scan_js_sha256'=>is_readable($uiAutoScan)?hash_file('sha256',$uiAutoScan):null,
            'maintenance_template_sha256'=>is_readable($maintenance)?hash_file('sha256',$maintenance):null,
            'live_maintenance_kit_snapshot_fresh'=>$snapshotFresh,
            'active_wpml_languages'=>$languages,
            'login_switcher_provider'=>count($languages)<2?'not_required':($native?'native_wpml':($fallback?'safe_locale_fallback':'missing')),
            'nested_notice_behavior_requires_browser_receipt'=>true,
            'login_and_admin_computed_css_requires_browser_receipt'=>true,
            'user_roles_or_passwords_modified'=>false,
            'problems'=>$issues];
    }
    public static function inspect():array {
        return ['contract'=>self::CONTRACT,'site_origin'=>untrailingslashit(home_url('/')),
           'site_name'=>self::name(),'css_file_sha256'=>is_readable(self::style_file())?hash_file('sha256',self::style_file()):null,
           'logo_present'=>self::local_logo()!=='','active_kit_id'=>(int)get_option('elementor_active_kit'),
           'live_kit_palette'=>self::palette(),
           'wpml_native_login_languages_enabled'=>class_exists('\WPML\UrlHandling\WPLoginUrlConverter',false)
                ?\WPML\UrlHandling\WPLoginUrlConverter::isEnabled():null,
           'login_style_registered'=>has_action('login_enqueue_scripts',[__CLASS__,'enqueue_login'])!==false,
           'admin_style_registered'=>has_action('admin_enqueue_scripts',[__CLASS__,'enqueue_admin'])!==false,
           'admin_nested_notice_script_sha256'=>is_readable(__DIR__.'/assets/company-admin-notice-accordion-v1.js')
              ?hash_file('sha256',__DIR__.'/assets/company-admin-notice-accordion-v1.js'):null,
           'admin_theme_switcher_js_sha256'=>is_readable(__DIR__.'/assets/company-admin-theme-toggle-v1.js')
              ?hash_file('sha256',__DIR__.'/assets/company-admin-theme-toggle-v1.js'):null,
           'admin_semantic_contrast_js_sha256'=>is_readable(__DIR__.'/assets/company-admin-contrast-auditor-v1.js')
              ?hash_file('sha256',__DIR__.'/assets/company-admin-contrast-auditor-v1.js'):null,
           'admin_ui_auto_scan_js_sha256'=>is_readable(__DIR__.'/assets/company-admin-ui-auto-scan-v1.js')
              ?hash_file('sha256',__DIR__.'/assets/company-admin-ui-auto-scan-v1.js'):null,
           'auth_or_password_reset_changes'=>false,'user_mutation'=>false];
    }
}
add_action('login_enqueue_scripts',[MAD4B_Company_System_Appearance_V1::class,'enqueue_login'],20);
add_action('admin_enqueue_scripts',[MAD4B_Company_System_Appearance_V1::class,'enqueue_admin'],80);
add_action('login_footer',[MAD4B_Company_System_Appearance_V1::class,'login_language_fallback'],50);
add_action('updated_option',[MAD4B_Company_System_Appearance_V1::class,'on_site_option_change'],20,1);
add_action('added_post_meta',[MAD4B_Company_System_Appearance_V1::class,'on_kit_meta_change'],20,3);
add_action('updated_post_meta',[MAD4B_Company_System_Appearance_V1::class,'on_kit_meta_change'],20,3);
add_filter('login_headerurl',static fn()=>home_url('/'));
add_filter('login_headertext',static fn()=>wp_strip_all_tags(get_bloginfo('name')));
add_filter('mad4b_company_reconcile_component_readiness',static function($components){
    if(!is_array($components))$components=[];
    $components['company_system_appearance']=MAD4B_Company_System_Appearance_V1::reconcile_readiness();
    // Passive and advisory-only. Never block company provisioning or repairs
    // because a human hasn't yet visited a particular admin screen.
    if(class_exists('MAD4B_Admin_UI_Auto_Scan_V1',false)){
        $scan=MAD4B_Admin_UI_Auto_Scan_V1::summary();
        $components['company_admin_ui_auto_scan']=[
            'contract'=>'mad4b.admin-ui-auto-scan.reconcile-readback.v1',
            'state'=>'ACTIVE_PASSIVE_SCAN',
            'pages_scanned'=>(int)($scan['pages_scanned']??0),
            'issue_counts'=>$scan['issues']??[],
            'family_counts'=>$scan['families']??[],
            'source'=>'visited_authenticated_admin_pages',
            'unvisited_pages_not_claimed_as_scanned'=>true,
            'blocks_provisioning'=>false,
            'modifies_content'=>false,
        ];
    }
    return $components;
},35);
