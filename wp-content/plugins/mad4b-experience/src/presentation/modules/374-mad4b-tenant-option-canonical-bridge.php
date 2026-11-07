<?php
/**
 * MAD4B Tenant Options Canonical Bridge — tenant-portable runtime source.
 *
 * An existing tenant Options UI may keep its familiar form, but values for
 * supported fields always come from the actual WordPress/Elementor/JetEngine
 * owner. Its saved options are mirrors, NOT another source of truth.
 *
 * The legacy Wayfa_Options_Source_Bridge MU owns the active hooks until
 * reconciled. This module deliberately never overrides a loaded MU class.
 *
 * No central identity, commerce prices, CRM, SMTP, permissions or demo
 * branding are transferred or changed by this module.
 */
defined('ABSPATH') || exit;

class MAD4B_Tenant_Option_Canonical_Bridge_V1 {
    public const CONTRACT = 'mad4b.tenant-option-canonical-bridge.v1';
    public const VERSION = '1.0.0';
    private const LANGS = ['en','es'];
    private const ALLOWED_WRITE = [
        'site'=>[
            'brand_name','tagline','brand_logo','brand_favicon',
            'primary_cta_text','primary_cta_url','contact_email','contact_phone',
            'instagram_url','facebook_url',
        ],
        'header_footer'=>['newsletter_template'],
    ];
    private const CONTACT_FIELDS = [
        'contact_email'=>'site_email','contact_phone'=>'site_whatsapp_num',
        'instagram_url'=>'site_instagram_page_link','facebook_url'=>'site_facebook_page_link',
    ];

    /** Read-only, portable contract information for Start Company acceptance. */
    public static function contract(): array {
        return [
            'contract'=>self::CONTRACT,'version'=>self::VERSION,
            'runtime_owner'=>'mad4b-experience',
            'replaces_legacy_class'=>'Wayfa_Options_Source_Bridge',
            'legacy_must_be_detached_after_exact_target_readback'=>true,
            'admin_values_are_canonical_projections'=>true,
            'unsupported_controls'=>'read_only_until_widget_owner_verified',
            'tenant_owned_identity_preserved'=>true,
            'pricing_owner'=>'woocommerce_orchestrator','pricing_writable'=>false,
            'target_wpml_languages_not_changed'=>true,
            'source_demo_values_are_not_imported'=>true,
            'target_native_operation_and_receipt_required_for_deployment'=>true,
        ];
    }
    public static function manifest_digest(): string {
        return hash('sha256',(string)wp_json_encode([
            'contract'=>self::contract(),'write_fields'=>self::ALLOWED_WRITE,
            'contact_fields'=>self::CONTACT_FIELDS
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function operating():bool {
        return class_exists('Wayfa_Dynamic_Page_Options',false)
            && method_exists('Wayfa_Dynamic_Page_Options','scope')
            && Wayfa_Dynamic_Page_Options::scope()
            && class_exists('MAD4B_Presentation_Experience',false)
            && MAD4B_Presentation_Experience::bound()
            && MAD4B_Presentation_Experience::enabled('tenant-option-bridge');
    }
    public static function legacy_present():bool {
        // The original MU class is loaded before ordinary WordPress plugins.
        return defined('MAD4B_WAYFA_OPTIONS_LEGACY_OWNER_PRESENT')
            || (class_exists('Wayfa_Options_Source_Bridge',false)
                && !is_subclass_of('Wayfa_Options_Source_Bridge',self::class));
    }
    public static function is_readonly(string $group,string $field,string $lang='en'):bool {
        if(!self::operating() || !in_array($lang,self::LANGS,true))return true;
        if(!in_array($field,self::ALLOWED_WRITE[$group]??[],true))return true;
        if($group==='site' && $lang==='es' && in_array($field,['brand_name','tagline'],true))
            return true; // Brand master and unapproved ES slogan are not localized.
        if($group==='site' && in_array($field,['primary_cta_text','primary_cta_url'],true))
            return self::cta_binding($lang)===null;
        if($group==='header_footer' && $field==='newsletter_template')
            return self::home_template_binding($lang,'newsletter_template')===null;
        return false;
    }
    public static function hint(string $group,string $field,string $lang='en'):string {
        if($group==='site' && $field==='default_currency')
            return 'WooCommerce / WCML owns currency and all commercial prices; read-only here.';
        if($group==='site' && $lang==='es' && in_array($field,['brand_name','tagline'],true))
            return 'Brand name is mastered in EN; Spanish slogan requires editorial approval.';
        if($group==='site' && isset(self::CONTACT_FIELDS[$field]))
            return 'Canonical source: JetEngine → Contact and Help Info. Optional until verified.';
        if($group==='site' && in_array($field,['brand_name','tagline','brand_logo','brand_favicon'],true))
            return 'Canonical source: Elementor active Site Kit; this editor writes through to it.';
        if($group==='site' && in_array($field,['primary_cta_text','primary_cta_url'],true))
            return 'Linked only after exact two-button Elementor header readback in this language.';
        if($group==='header_footer' && $field==='newsletter_template')
            return 'Linked to the unique Newsletter widget on this language home page.';
        if($group==='header_footer' && str_contains($field,'footer'))
            return 'Footer Theme Builder / site theme owns this value; not a copy in Site Options.';
        return 'Read-only reference: runtime consumer is not bound. Resolve its owner in Company Reconcile.';
    }
    private static function kit():array {
        $id=(int)get_option('elementor_active_kit');
        if($id<1||get_post_type($id)!=='elementor_library')return [];
        $s=get_post_meta($id,'_elementor_page_settings',true);
        return is_array($s)?$s:[];
    }
    private static function jet(string $page,string $key) {
        $x=get_option($page,[]);
        return is_array($x)?($x[$key]??''):'';
    }
    private static function src_data():array {
        $w=get_option('wayfa_site_options_v1',[]);
        return is_array($w)?$w:[];
    }
    private static function local_lang(?string $lang):string {
        return in_array($lang,self::LANGS,true)?$lang:'en';
    }
    private static function language_of(int $id):string {
        $d=apply_filters('wpml_post_language_details',null,$id);
        return is_array($d)?(string)($d['language_code']??''):'';
    }
    /** An existing alias is only a selector candidate, never authority to write. */
    private static function header(string $lang):int {
        $w=self::src_data();
        $id=(int)($w['header_footer']['header_template__'.$lang]??0);
        if(!$id||get_post_type($id)!=='elementor_library'||get_post_status($id)!=='publish'||
            get_post_meta($id,'_elementor_template_type',true)!=='header'||
            self::language_of($id)!==$lang)return 0;
        $en=(int)($w['header_footer']['header_template__en']??0);
        if($lang!=='en' && $en){
            $wpml=(int)apply_filters('wpml_object_id',$en,'elementor_library',false,$lang);
            if($wpml!==$id)return 0;
        }
        return $id;
    }
    private static function home(string $lang):int {
        $front=(int)get_option('page_on_front');
        if(!$front)return 0;
        // WPML may have filtered page_on_front for the current admin locale.
        // Resolve the EN source first, then the requested site language.
        $en=(int)apply_filters('wpml_object_id',$front,'page',false,'en');
        if(!$en)$en=$front;
        $id=$lang==='en'?$en:(int)apply_filters('wpml_object_id',$en,'page',false,$lang);
        if(!$id||get_post_type($id)!=='page'||get_post_status($id)!=='publish'||
            self::language_of($id)!==$lang)return 0;
        return $id;
    }
    private static function nodes(int $post):array {
        if(!$post)return [];
        $tree=json_decode((string)get_post_meta($post,'_elementor_data',true),true);
        if(!is_array($tree))return [];
        $found=[];
        $walk=function($children)use(&$walk,&$found){
            foreach((array)$children as $n){
                if(!is_array($n))continue;
                if(!empty($n['id']))$found[]=$n;
                $walk($n['elements']??[]);
            }
        };
        $walk($tree);
        return $found;
    }
    private static function url(string $value):string {
        if($value==='')return '';
        if(str_starts_with($value,'/'))$value=home_url($value);
        $parts=wp_parse_url($value);
        if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host']))return '';
        $path='/'.trim((string)($parts['path']??'/'),'/');
        if($path!=='/')$path.='/';
        $out=strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).$path;
        if(isset($parts['query']))$out.='?'.$parts['query'];
        return $out;
    }
    private static function cta_binding(string $lang):?array {
        $doc=self::header($lang);
        if(!$doc)return null;
        $site=self::src_data()['site']??[];
        $label=(string)($site['primary_cta_text__'.$lang]??'');
        $url=(string)($site['primary_cta_url__'.$lang]??'');
        if($label===''||$url==='')return null;
        $hits=[];
        foreach(self::nodes($doc) as $node) {
            if(($node['widgetType']??'')!=='button')continue;
            $settings=$node['settings']??[];
            if(!empty($settings['__dynamic__']['text'])||!empty($settings['__dynamic__']['link']))continue;
            if(html_entity_decode((string)($settings['text']??''),ENT_QUOTES,'UTF-8')!==
               html_entity_decode($label,ENT_QUOTES,'UTF-8'))continue;
            if(self::url((string)($settings['link']['url']??''))!==self::url($url))continue;
            $hits[]=(string)($node['id']??'');
        }
        if(count($hits)!==2||count(array_unique($hits))!==2)return null;
        return ['document'=>$doc,'node_ids'=>$hits,'text'=>$label,'url'=>self::url($url),'language'=>$lang];
    }
    private static function home_template_binding(string $lang,string $field):?array {
        if(!in_array($field,['newsletter_template','faq_template','seo_intro_template','reviews_template'],true))
            return null;
        $id=self::home($lang);
        if(!$id)return null;
        $homeSaved=get_post_meta($id,'_wayfa_page_options_v1_home',true);
        $homeSaved=is_array($homeSaved)?$homeSaved:[];
        $alias=$field==='newsletter_template'
            ?(int)(self::src_data()['header_footer']['newsletter_template__'.$lang]??0)
            :(int)($homeSaved[$field]??0);
        if(!$alias||get_post_type($alias)!=='elementor_library'||
           get_post_status($alias)!=='publish'||self::language_of($alias)!==$lang)return null;
        $hits=[];
        foreach(self::nodes($id) as $node)
            if(($node['widgetType']??'')==='template' &&
               (int)($node['settings']['template_id']??0)===$alias)
                $hits[]=(string)($node['id']??'');
        // Multiple matching widgets are ambiguous — do not guess ownership.
        if(count($hits)!==1)return null;
        return ['document'=>$id,'node_ids'=>$hits,'template_id'=>$alias,'language'=>$lang];
    }
    public static function read(string $group,string $field,int $object=0,string $lang='en') {
        if(!self::operating())return null;
        $lang=self::local_lang($lang);
        if($group==='site'){
            switch($field){
                case 'brand_name':return (string)(self::kit()['site_name']??get_option('blogname',''));
                case 'tagline':return $lang==='es'?'':(string)(self::kit()['site_description']??get_option('blogdescription',''));
                case 'brand_logo':return (int)(self::kit()['site_logo']['id']??0);
                case 'brand_favicon':return (int)(self::kit()['site_favicon']['id']??get_option('site_icon',0));
                case 'default_currency':return (string)get_option('woocommerce_currency','USD');
                case 'primary_cta_text':
                case 'primary_cta_url':
                    $binding=self::cta_binding($lang);
                    return $binding?($field==='primary_cta_text'?$binding['text']:$binding['url']):null;
                case 'contact_email':return (string)self::jet('contact-and-help-info','site_email');
                case 'contact_phone':return (string)self::jet('contact-and-help-info','site_whatsapp_num');
                case 'instagram_url':return (string)self::jet('contact-and-help-info','site_instagram_page_link');
                case 'facebook_url':return (string)self::jet('contact-and-help-info','site_facebook_page_link');
            }
        }
        if($group==='header_footer'){
            if($field==='header_template')return self::header($lang)?:null;
            if($field==='newsletter_template'){
                $ref=self::home_template_binding($lang,'newsletter_template');
                return $ref?$ref['template_id']:null;
            }
            if($field==='show_language_switcher'){
                $id=self::header($lang);
                return $id && str_contains((string)get_post_meta($id,'_elementor_data',true),
                    'wpml-language-switcher')?'1':'0';
            }
            return null;
        }
        if($group==='home' && $object && $object===self::home($lang)){
            $ref=self::home_template_binding($lang,$field);
            return $ref?$ref['template_id']:null;
        }
        return null;
    }
    /** Read-only diagnostic; no tenant changes, regardless of caller privileges. */
    public static function health():array {
        if(!self::operating())return ['contract'=>self::CONTRACT,'state'=>'TENANT_ADAPTER_NOT_CONNECTED',
            'write_available'=>false,'production_mutation'=>false];
        $locale=[];
        foreach(self::LANGS as $lang){
            $locale[$lang]=[
                'header_valid'=>self::header($lang)>0,'home_valid'=>self::home($lang)>0,
                'cta_two_buttons_unique'=>self::cta_binding($lang)!==null,
                'newsletter_widget_unique'=>self::home_template_binding($lang,'newsletter_template')!==null,
            ];
        }
        return [
            'contract'=>self::CONTRACT,
            'state'=>self::legacy_present()?'LEGACY_MU_OWNER_ACTIVE':'CANONICAL_BRIDGE_PRESENT',
            'target_origin'=>untrailingslashit(home_url('/')),
            'languages'=>$locale,
            'duplicate_hook_owners'=>false,
            'source_demo_brand_copied'=>false,
            'prices_changed'=>false,'production_mutation'=>false,
        ];
    }
    private static function valid_media(int $id):bool {
        $file=get_attached_file($id);
        return $id>0&&get_post_type($id)==='attachment'&&wp_attachment_is_image($id)
            &&is_string($file)&&is_file($file)&&wp_get_attachment_url($id)!==false;
    }
    private static function check_value(string $field,$value):bool {
        if(in_array($field,['brand_logo','brand_favicon'],true))
            return self::valid_media((int)$value);
        if($field==='brand_name')return is_string($value)&&mb_strlen(trim($value))>=2
            &&mb_strlen($value)<=100;
        if($field==='tagline')return is_string($value)&&mb_strlen($value)<=220;
        if($field==='primary_cta_text')return is_string($value)&&
            mb_strlen(trim($value))>0&&mb_strlen($value)<=90;
        if($field==='primary_cta_url')return self::is_site_https((string)$value);
        if($field==='contact_email')return $value===''||is_email((string)$value)!==false;
        if($field==='contact_phone')return $value===''||
            preg_match('/^\+?[0-9 ()-]{7,24}$/D',(string)$value)===1;
        if(in_array($field,['facebook_url','instagram_url'],true)){
            if($value==='')return true;
            $host=strtolower((string)wp_parse_url($value,PHP_URL_HOST));
            if(wp_parse_url($value,PHP_URL_SCHEME)!=='https')return false;
            $allowed=$field==='facebook_url'
                ?['facebook.com','www.facebook.com','m.facebook.com']
                :['instagram.com','www.instagram.com'];
            return in_array($host,$allowed,true);
        }
        if($field==='newsletter_template')return (int)$value>0 &&
            get_post_type((int)$value)==='elementor_library'&&
            get_post_status((int)$value)==='publish';
        return false;
    }
    private static function is_site_https(string $url):bool {
        $parts=wp_parse_url($url);
        $site=wp_parse_url(home_url('/'));
        return is_array($parts)&&($parts['scheme']??'')==='https'&&
            strtolower((string)($parts['host']??''))===strtolower((string)($site['host']??''))&&
            !isset($parts['user'])&&!isset($parts['pass']);
    }
    /** Dry-run contract; caller's existing read-only MU editor fields stay untouched. */
    public static function prepare(string $group,string $lang,array $old,array $new):array|WP_Error {
        if(!self::operating())return new WP_Error('mad4b_tenant_source_not_bound','Tenant adapter is not active');
        if(!in_array($lang,self::LANGS,true))
            return new WP_Error('mad4b_tenant_language_not_active','Requested language unsupported');
        if(!class_exists('Wayfa_Dynamic_Page_Options',false))return new WP_Error('mad4b_tenant_options_missing');
        $defs=Wayfa_Dynamic_Page_Options::definitions($group);
        if(!is_array($defs))return new WP_Error('mad4b_tenant_options_schema_missing');
        $changes=[];$plans=[];
        foreach($defs as $field=>$spec){
            if(self::is_readonly($group,$field,$lang))continue;
            $key=!empty($spec[2])?$field.'__'.$lang:$field;
            if(!array_key_exists($key,$new))continue;
            $current=self::read($group,$field,0,$lang);
            if($current===null)return new WP_Error('mad4b_tenant_canonical_source_unavailable',$field);
            if((string)$new[$key]===(string)$current)continue;
            if(!self::check_value($field,$new[$key]))
                return new WP_Error('mad4b_tenant_canonical_invalid_value',$field);
            if(array_key_exists($key,$old)&&is_scalar($old[$key]) &&
                (string)$old[$key]!=='' && (string)$old[$key]!==(string)$current) {
                return new WP_Error('mad4b_tenant_canonical_stale_alias',
                    'Refresh and reconcile saved option before editing '.$field);
            }
            if($field==='primary_cta_text'||$field==='primary_cta_url'){
                if(!self::cta_binding($lang))return new WP_Error('mad4b_tenant_cta_binding_missing');
            }
            if($field==='newsletter_template') {
                $ref=self::home_template_binding($lang,$field);
                if(!$ref || self::language_of((int)$new[$key])!==$lang)
                    return new WP_Error('mad4b_tenant_template_language_or_binding_invalid');
            }
            $changes[$field]=$new[$key];
            $plans[$field]=['owner'=>isset(self::CONTACT_FIELDS[$field])
                    ?'jetengine_contact':(str_starts_with($field,'primary_cta')
                        ?'elementor_header':($field==='newsletter_template'?'elementor_home':'elementor_site_kit')),
                'language'=>$lang,'tenant_owned'=>true];
        }
        return ['contract'=>self::CONTRACT.'.prepare','group'=>$group,'language'=>$lang,
            'change_fields'=>array_keys($changes),'changes'=>$changes,'owners'=>$plans,
            'no_pricing_write'=>true,'ready'=>true,'writes_performed'=>false];
    }
    private static function set_jet(string $name,array $changes):void {
        if(!$changes)return;
        $old=get_option($name,[]);
        if(!is_array($old))throw new RuntimeException('JetEngine option page schema invalid');
        if(!update_option($name,array_replace($old,$changes),false)&&
            get_option($name,[])!==array_replace($old,$changes))
            throw new RuntimeException('JetEngine option write/readback failed');
    }
    private static function set_kit(array $changes):void {
        if(!$changes)return;
        $id=(int)get_option('elementor_active_kit');
        if(!get_post($id)||get_post_type($id)!=='elementor_library')
            throw new RuntimeException('Active Elementor Kit not valid');
        $old=self::kit();$new=array_replace($old,$changes);
        if(!update_post_meta($id,'_elementor_page_settings',$new)&&
            get_post_meta($id,'_elementor_page_settings',true)!==$new)
            throw new RuntimeException('Kit settings write/readback failed');
    }
    private static function patch_widgets(int $post,array $nodes,string $kind,array $values):void {
        $raw=(string)get_post_meta($post,'_elementor_data',true);
        $tree=json_decode($raw,true);
        if(!is_array($tree)||get_post_status($post)!=='publish')
            throw new RuntimeException('Elementor document unavailable');
        $seen=[];
        $walk=function(&$children)use(&$walk,$nodes,$kind,$values,&$seen){
            foreach($children as &$widget){
                $id=$widget['id']??'';
                if(in_array($id,$nodes,true)){
                    $type=$widget['widgetType']??'';
                    if($type!==$kind)throw new RuntimeException('Elementor widget type changed');
                    $seen[]=$id;
                    if($kind==='button'){
                        if(array_key_exists('text',$values))$widget['settings']['text']=$values['text'];
                        if(array_key_exists('url',$values)){
                            // Elementor normally stores a same-origin relative URL.
                            $path=(string)(wp_parse_url($values['url'],PHP_URL_PATH)??'/');
                            $query=wp_parse_url($values['url'],PHP_URL_QUERY);
                            $widget['settings']['link']['url']=$path.($query?'?'.$query:'');
                        }
                    }else{
                        $widget['settings']['template_id']=(int)$values['template_id'];
                    }
                }
                if(isset($widget['elements'])&&is_array($widget['elements']))$walk($widget['elements']);
            }
            unset($widget);
        };
        $walk($tree);
        if(count($seen)!==count($nodes)||count(array_unique($seen))!==count($nodes))
            throw new RuntimeException('Elementor widget ownership changed');
        $json=wp_json_encode($tree,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!$json || !update_post_meta($post,'_elementor_data',wp_slash($json)))
            throw new RuntimeException('Elementor document write failed');
        if(!is_array(json_decode((string)get_post_meta($post,'_elementor_data',true),true)))
            throw new RuntimeException('Elementor document readback failed');
    }
    /**
     * Original admin-post UI handles nonce+capability, and bridge repeats
     * them before touching owner settings. Every field is preflighted first.
     * The UI's mirror option is saved only if this returns null.
     */
    public static function commit(string $group,string $lang,array $old,array $next):?WP_Error {
        if(!self::operating() || !current_user_can('manage_options')
            || !is_admin() || (string)($_POST['action']??'')!=='wayfa_save_options'
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'wayfa_options_'.$group))
            return new WP_Error('mad4b_tenant_bridge_admin_nonce_required','Authorized options form required');
        $plan=self::prepare($group,$lang,$old,$next);
        if(is_wp_error($plan))return $plan;
        $changes=$plan['changes'];
        if(!$changes)return null;
        global $wpdb;
        $lock='mad4b:tenant-options:'.(defined('MAD4B_TENANT_SITE_UUID')?MAD4B_TENANT_SITE_UUID:md5(home_url()));
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return new WP_Error('mad4b_tenant_bridge_busy','Options update already running');
        try{
            $afterLock=self::prepare($group,$lang,$old,$next);
            if(is_wp_error($afterLock) || !is_array($afterLock) ||
                $afterLock['changes']!==$changes)
                return new WP_Error('mad4b_tenant_bridge_revision_conflict','Canonical values changed during request');
            $kit=[];$look=[];$contact=[];$homeWidgets=[];$headerWidgets=[];
            foreach($changes as $field=>$value){
                if($group==='site'){
                    switch($field){
                        case 'brand_name':$kit['site_name']=$value;$look['mad4b_brand_name']=$value;break;
                        case 'tagline':$kit['site_description']=$value;$look['mad4b_brand_tagline']=$value;break;
                        case 'brand_logo':
                            $image=['id'=>(int)$value,'url'=>wp_get_attachment_url((int)$value),'size'=>'full','source'=>'library'];
                            $kit['site_logo']=$image;
                            $look['mad4b_brand_logo_media']=(int)$value;
                            $contact['site_icon_280x100_site_header']=(int)$value;
                            break;
                        case 'brand_favicon':
                            $image=['id'=>(int)$value,'url'=>wp_get_attachment_url((int)$value),'size'=>'full','source'=>'library'];
                            $kit['site_favicon']=$image;
                            $look['mad4b_brand_favicon_media']=(int)$value;
                            $contact['site_icon_512x512']=(int)$value;
                            break;
                        case 'contact_email':
                            $contact['site_email']=$value;
                            $contact['site_email_link']=$value!==''?'mailto:'.$value:'';
                            break;
                        case 'contact_phone':
                            $contact['site_whatsapp_num']=$value;
                            $contact['site_wa_tel_link']=$value!==''?'tel:'.preg_replace('/[^+0-9]/','',$value):'';
                            break;
                        case 'instagram_url':$contact['site_instagram_page_link']=$value;break;
                        case 'facebook_url':$contact['site_facebook_page_link']=$value;break;
                        case 'primary_cta_text':$headerWidgets['text']=$value;break;
                        case 'primary_cta_url':$headerWidgets['url']=$value;break;
                    }
                }elseif($group==='header_footer' && $field==='newsletter_template'){
                    $homeWidgets['template_id']=(int)$value;
                }
            }
            // Recoverable backup for the exact owner keys, not a copy of
            // passwords, content jobs, or WooCommerce transactions.
            $kitid=(int)get_option('elementor_active_kit');
            $docs=[];
            if($headerWidgets){$binding=self::cta_binding($lang);$docs[$binding['document']]=$binding;}
            if($homeWidgets){$binding=self::home_template_binding($lang,'newsletter_template');$docs[$binding['document']]=$binding;}
            $snapshot=[
                'kit_id'=>$kitid,
                'kit'=>get_post_meta($kitid,'_elementor_page_settings',true),
                'look'=>get_option('look-and-feel',[]),
                'contact'=>get_option('contact-and-help-info',[]),
                'blogname'=>get_option('blogname'),'blogdescription'=>get_option('blogdescription'),
                'site_icon'=>get_option('site_icon'),'custom_logo'=>get_theme_mod('custom_logo',0),
                'docs'=>array_map(fn($doc)=>get_post_meta((int)$doc,'_elementor_data',true),array_keys($docs))
            ];
            if($wpdb->query('START TRANSACTION')===false)
                throw new RuntimeException('Options transaction unavailable');
            try{
                if($look)self::set_jet('look-and-feel',$look);
                if($contact)self::set_jet('contact-and-help-info',$contact);
                if($kit)self::set_kit($kit);
                if(isset($changes['brand_name']))update_option('blogname',$changes['brand_name']);
                if(isset($changes['tagline']))update_option('blogdescription',$changes['tagline']);
                if(isset($changes['brand_logo']))set_theme_mod('custom_logo',(int)$changes['brand_logo']);
                if(isset($changes['brand_favicon']))update_option('site_icon',(int)$changes['brand_favicon']);
                if($headerWidgets){
                    $binding=self::cta_binding($lang);
                    self::patch_widgets($binding['document'],$binding['node_ids'],'button',$headerWidgets);
                }
                if($homeWidgets){
                    $binding=self::home_template_binding($lang,'newsletter_template');
                    self::patch_widgets($binding['document'],$binding['node_ids'],'template',$homeWidgets);
                }
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('Transaction commit failed');
            }catch(Throwable $e){
                $wpdb->query('ROLLBACK');
                wp_cache_delete('alloptions','options');
                wp_cache_delete('notoptions','options');
                wp_cache_delete('look-and-feel','options');
                wp_cache_delete('contact-and-help-info','options');
                clean_post_cache($kitid);
                foreach(array_keys($docs) as $doc)clean_post_cache((int)$doc);
                throw $e;
            }
            // Elementor source CSS is rebuilt from the actual target docs.
            if(class_exists('Elementor\\Plugin',false))
                \Elementor\Plugin::instance()->files_manager->clear_cache();
            do_action('litespeed_purge_all');
            return null;
        }catch(Throwable $e){
            error_log('[MAD4B canonical tenant bridge] '.$e->getMessage());
            return new WP_Error('mad4b_tenant_bridge_write_failed','Canonical provider update did not complete');
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /** Legacy post UI saves options after this hook; use its current meta as
     * the old target ID while preflighting a new, language-matched ID. */
    public static function sync_home(int $postId):void {
        if(!self::operating()||!current_user_can('edit_post',$postId)||
            wp_is_post_revision($postId) ||
            (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) ||
            !isset($_POST['wayfa_nonce_home']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wayfa_nonce_home'])),
                'wayfa_post_home'))return;
        $lang=self::language_of($postId);
        if(!in_array($lang,self::LANGS,true) || self::home($lang)!==$postId)return;
        $submitted=is_array($_POST['wayfa_home']??null)?$_POST['wayfa_home']:[];
        $changes=[];
        foreach(['faq_template','seo_intro_template','reviews_template'] as $field) {
            if(!array_key_exists($field,$submitted))continue;
            $spec=Wayfa_Dynamic_Page_Options::definitions('home')[$field]??null;
            if(!is_array($spec))continue;
            $value=Wayfa_Dynamic_Page_Options::sanitize_value($spec,$submitted[$field]);
            if(!is_int($value)||$value<=0)continue;
            $binding=self::home_template_binding($lang,$field);
            if(!$binding || self::language_of($value)!==$lang)
                wp_die('Home section binding is not unique or its translation is mismatched.',
                    'MAD4B Canonical Home Options',['response'=>409]);
            if($binding['template_id']===$value)continue;
            $changes[$field]=['binding'=>$binding,'template_id'=>$value];
        }
        if(!$changes)return;
        // Preflight all three sections before editing any Elementor bytes.
        // Exact source IDs are rediscovered per-company, not copied from demo.
        global $wpdb;
        $lock='mad4b:tenant-home:'.(defined('MAD4B_TENANT_SITE_UUID')
            ?MAD4B_TENANT_SITE_UUID:md5(home_url()));
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            wp_die('Another page reconciliation is running.','MAD4B Home Options',['response'=>423]);
        try{
            foreach($changes as $field=>$item)
                if(self::home_template_binding($lang,$field)!==$item['binding'])
                    throw new RuntimeException('Home section changed during preflight');
            $tree=json_decode((string)get_post_meta($postId,'_elementor_data',true),true);
            if(!is_array($tree))throw new RuntimeException('Elementor home JSON invalid');
            $targetMap=[];
            foreach($changes as $item)$targetMap[$item['binding']['node_ids'][0]]=$item['template_id'];
            $seen=[];
            $visit=function(&$nodes)use(&$visit,$targetMap,&$seen) {
                foreach($nodes as &$node){
                    $id=$node['id']??'';
                    if(array_key_exists($id,$targetMap)){
                        if(($node['widgetType']??'')!=='template')
                            throw new RuntimeException('Home widget type changed');
                        $node['settings']['template_id']=(int)$targetMap[$id];
                        $seen[]=$id;
                    }
                    if(isset($node['elements'])&&is_array($node['elements']))
                        $visit($node['elements']);
                }
                unset($node);
            };
            $visit($tree);
            if(count($seen)!==count($targetMap) ||
                count(array_unique($seen))!==count($targetMap))
                throw new RuntimeException('Home widget IDs not unique');
            $json=wp_json_encode($tree,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($json))throw new RuntimeException('Home JSON serialization failed');
            if(!update_post_meta($postId,'_elementor_data',wp_slash($json)))
                throw new RuntimeException('Elementor home write failed');
            if(!is_array(json_decode((string)get_post_meta($postId,'_elementor_data',true),true)))
                throw new RuntimeException('Home readback invalid');
            if(class_exists('Elementor\\Plugin',false))
                \Elementor\Plugin::instance()->files_manager->clear_cache();
        }catch(Throwable $e){
            error_log('[MAD4B canonical home option bridge] '.$e->getMessage());
            wp_die('The requested Home section could not be linked. Nothing else should be submitted.',
                'MAD4B Home Options',['response'=>409]);
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
}

/**
 * Backward-compatible Wayfa form API. If legacy MU is still present the
 * class already exists, so no duplicate mutation hooks are registered.
 */
if(!class_exists('Wayfa_Options_Source_Bridge',false)) {
    class Wayfa_Options_Source_Bridge extends MAD4B_Tenant_Option_Canonical_Bridge_V1 {}
    add_action('save_post',['Wayfa_Options_Source_Bridge','sync_home'],7,1);
}
