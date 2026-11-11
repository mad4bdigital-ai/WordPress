<?php
/**
 * Plugin Name: MAD4B Company Legal Documents
 * Description: Multilingual, tenant-owned legal pages with per-company identity and dynamic booking policy from the existing Company Policy Library.
 * Version: 1.3.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Company_Legal_Documents',false)) {
final class MAD4B_Company_Legal_Documents {
    const SHORTCODE='mad4b_company_legal_document';
    private static function library():array {
        static $library=null;
        if ($library!==null)return $library;
        $path=__DIR__.'/assets/mad4b-company-legal-copy.json';
        $raw=is_file($path)?file_get_contents($path):'';
        $value=json_decode($raw,true);
        return $library=is_array($value)?$value:[];
    }
    public static function locale():string {
        $lang=(string)apply_filters('wpml_current_language','en');
        return isset(self::library()['labels'][$lang])?$lang:'en';
    }
    private static function config():array {
        $raw=get_option('look-and-feel',[]);
        return is_array($raw)?$raw:[];
    }
    private static function t(string $key):string {
        $l=self::locale(); return (string)(self::library()['labels'][$l][$key]??self::library()['labels']['en'][$key]??$key);
    }
    private static function name():string {
        $c=self::config();
        $name=trim((string)($c['legal_public_brand_name']??''));
        if($name==='')$name=function_exists('mad4b_company_template_public_name')?
            mad4b_company_template_public_name():get_bloginfo('name');
        return sanitize_text_field($name);
    }
    private static function legalIdentity():array {
        $c=self::config();
        $name=trim((string)($c['legal_registered_entity_name']??''));
        $mail=trim((string)($c['legal_privacy_contact_email']??''));
        $country=trim((string)($c['legal_company_jurisdiction']??''));
        $address=trim((string)($c['legal_registered_address']??''));
        $complete=$name!=='' && $country!=='' && is_email($mail)!==false;
        $reviewed=($c['legal_identity_review_status']??'pending')==='reviewed';
        return [
           'entity'=>sanitize_text_field($name),
           'email'=>is_email($mail)?sanitize_email($mail):'',
           'jurisdiction'=>sanitize_text_field($country),
           'address'=>sanitize_textarea_field($address),
           'details_complete'=>$complete,
           // No certificate or OAuth is required. An authorized company admin
           // must explicitly confirm that the entered identity was reviewed.
           'verified'=>$complete && $reviewed,
        ];
    }
    private static function pageLink(string $kind):string {
        $base=(int)get_option($kind==='privacy'?'wp_page_for_privacy_policy':'woocommerce_terms_page_id');
        if(!$base)return '';
        $target=(int)apply_filters('wpml_object_id',$base,'page',false,self::locale());
        if(!$target||get_post_status($target)!=='publish')return '';
        return (string)get_permalink($target);
    }
    public static function render($atts=[]):string {
        $atts=shortcode_atts(['kind'=>'privacy'],$atts,self::SHORTCODE);
        $kind=sanitize_key((string)$atts['kind']);
        if(!in_array($kind,['privacy','terms'],true))return '';
        $lib=self::library();
        if(!$lib)return '';
        $lang=self::locale();
        $name=self::name();
        $ident=self::legalIdentity();
        $rev=get_option('mad4b_company_legal_revision_v1',[]);
        $reviewed=is_array($rev)?(string)($rev['last_reviewed']??'2026-11-10'):'2026-11-10';
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$reviewed))$reviewed='2026-11-10';
        $rtl=$lang==='ar'?' dir="rtl"':'';
        $title=self::t($kind);
        $isDraft=!$ident['verified'];
        $html='<article class="mad4b-company-legal-page" data-legal-kind="'.esc_attr($kind).'" data-legal-lang="'.esc_attr($lang).'"'.$rtl.'>';
        $html.='<header class="mad4b-company-legal-header"><p class="mad4b-company-legal-kicker">'.esc_html($name).'</p>';
        $html.='<h1>'.esc_html($title).'</h1><p class="mad4b-company-legal-date">'.esc_html(self::t('effective')).': <time datetime="'.esc_attr($reviewed).'">'.esc_html($reviewed).'</time></p>';
        if($isDraft)$html.='<p class="mad4b-company-legal-review" role="note">'.esc_html(self::t('unverified')).'</p>';
        $html.='</header>';
        if($kind==='privacy'){
            $html.='<p class="mad4b-company-legal-lead">'.esc_html(self::t('privacy_intro')).'</p>';
            $html.='<section class="mad4b-company-legal-identity"><h2>'.esc_html(self::t('entity')).'</h2>';
            $html.='<p>'.esc_html($ident['entity']?:$name).'</p>';
            if($ident['jurisdiction']!=='')$html.='<p>'.esc_html(self::t('jurisdiction')).': '.esc_html($ident['jurisdiction']).'</p>';
            if($ident['address']!=='')$html.='<p>'.nl2br(esc_html($ident['address'])).'</p>';
            $html.='</section>';
            foreach((array)($lib['privacy'][$lang]??$lib['privacy']['en']??[]) as $ix=>$sec){
                if(!is_array($sec)||count($sec)!==2)continue;
                $html.='<section class="mad4b-company-legal-section"><h2>'.esc_html((string)($ix+1).'. '.$sec[0]).'</h2><p>'.esc_html($sec[1]).'</p></section>';
            }
        } else {
            $html.='<p class="mad4b-company-legal-lead">'.esc_html(self::t('terms_intro')).'</p>';
            $sections=['booking','pricing','cancellation','children','accommodation','tipping','force_majeure','general'];
            $n=0;
            foreach($sections as $key){
                $n++;$html.='<section class="mad4b-company-legal-section"><h2>'.esc_html($n.'. '.self::t($key)).'</h2>';
                if(class_exists('MAD4B_Customer_Policy_Control')) {
                    $html.=MAD4B_Customer_Policy_Control::render($key,0,false);
                } else {
                    $html.='<p>'.esc_html(self::t('unverified')).'</p>';
                }
                $html.='</section>';
            }
            foreach(['complaint','payment','website','changes'] as $key){
                $n++;
                $text=(string)($lib['extras'][$lang][$key]??$lib['extras']['en'][$key]??'');
                $html.='<section class="mad4b-company-legal-section"><h2>'.esc_html($n.'. '.self::t($key)).'</h2><p>'.esc_html($text).'</p></section>';
            }
            $privacy=self::pageLink('privacy');
            if($privacy)$html.='<p class="mad4b-company-legal-linked"><a href="'.esc_url($privacy).'">'.esc_html(self::t('privacy')).'</a></p>';
        }
        $html.='<footer class="mad4b-company-legal-contact"><h2>'.esc_html(self::t('contact')).'</h2>';
        if($ident['email']!=='')$html.='<p><a href="mailto:'.esc_attr($ident['email']).'">'.esc_html($ident['email']).'</a></p>';
        else $html.='<p>'.esc_html(self::t('unverified')).'</p>';
        $other=self::pageLink($kind==='privacy'?'terms':'privacy');
        if($other)$html.='<p><a href="'.esc_url($other).'">'.esc_html(self::t($kind==='privacy'?'terms':'privacy')).'</a></p>';
        $html.='</footer></article>';
        return $html;
    }

    /**
     * Legal page templates on a demonstration tenant may be published for QA
     * while the legal operator identity is not yet approved. Keep them publicly
     * accessible but noindex until a company verifies its legal details.
     */
    private static function isLegalPage():bool {
        if(is_admin() || !is_page())return false;
        $post=get_post(get_queried_object_id());
        if(!$post || get_post_status($post)!=='publish')return false;
        // Includes both source-template and existing company-authored legal pages.
        if(str_contains((string)$post->post_content,'[mad4b_company_legal_document'))return true;
        $id=(int)$post->ID;
        $lang=self::locale();
        foreach(['wp_page_for_privacy_policy','woocommerce_terms_page_id'] as $key){
            $base=(int)get_option($key);
            if($base<1)continue;
            if($base===$id)return true;
            $translated=(int)apply_filters('wpml_object_id',$base,'page',false,$lang);
            if($translated>0 && $translated===$id)return true;
        }
        return false;
    }
    public static function rankMathRobots($robots):array {
        $robots=(array)$robots;
        if(!self::isLegalPage() || self::legalIdentity()['verified']) return $robots;
        return array_values(array_unique(array_merge(
            array_values(array_diff($robots,['index','all'])),
            ['noindex','follow']
        )));
    }

    /** Informational readiness checks; never grant access or override company approvals. */
    public static function readiness():array {
        $identity=self::legalIdentity();
        $cfg=self::config();
        $tracked=in_array('duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php',
            (array)get_option('active_plugins',[]),true);
        $consentStatus=(string)($cfg['legal_tracking_consent_status']??'needs_review');
        $pages=['privacy'=>(int)get_option('wp_page_for_privacy_policy'),
            'terms'=>(int)get_option('woocommerce_terms_page_id')];
        $published=0;
        foreach($pages as $base)if($base>0&&get_post_status($base)==='publish')$published++;
        $library=(array)get_option('mad4b-company-policy-library',[]);
        $sections=['booking','cancellation','children','accommodation',
                   'pricing','tipping','force_majeure','general'];
        $approved=0;
        foreach($sections as $sec)
           if(($library[$sec.'_approval']??'')==='approved' && !empty($library[$sec.'_revision']))$approved++;
        return [
           'published_legal_pages'=>$published===2,
           'legal_identity_details_complete'=>$identity['details_complete'],
           'legal_identity_verified'=>$identity['verified'],
           'consent_gateway_present'=>class_exists('MAD4B_Consent_Gateway',false)
               && MAD4B_Consent_Gateway::id()!==''
               && !apply_filters('gtm4wp_output_container',true),
           'policy_narratives_present'=>$approved===count($sections),
           'tracking_plugin_active'=>$tracked,
           'tracking_consent_review'=>$consentStatus,
           'tracking_consent_review_needed'=>$tracked && !in_array($consentStatus,['verified','not_applicable'],true),
           'company_payment_mode'=>(string)($library['commerce_advance_mode']??'unconfigured'),
           'company_cancellation_reference_approved'=>($library['commerce_cancellation_standard_status']??'draft')==='approved',
        ];
    }
    public static function adminNotice():void {
        if(!current_user_can('manage_options'))return;
        $tab=(string)($_GET['page']??'');
        if(!in_array($tab,['look-and-feel','mad4b-company-policy-library'],true))return;
        $s=self::readiness();
        if($s['legal_identity_verified'] && !$s['tracking_consent_review_needed'])return;
        $items=[];
        if(!$s['legal_identity_verified'])$items[]='Enter the registered company, jurisdiction and legal/privacy contact, then mark identity as reviewed after checking the facts.';
        if($s['tracking_consent_review_needed'])$items[]='Check tracking and cookie-consent controls before enabling marketing tags for affected visitors.';
        echo '<div class="notice notice-warning"><p><strong>MAD4B Legal Readiness:</strong> '
            .esc_html(implode(' ',$items)).' No payment permission is granted by this notice.</p></div>';
    }
    public static function fields($fields,$page) {
        if(!is_object($page)||($page->slug??'')!=='look-and-feel'||!is_array($fields))return $fields;
        $extra=[
          ['name'=>'mad4b_legal_settings_heading','title'=>'Company legal identity / privacy contact','type'=>'html','html'=>'<p>Site-specific only. Complete and verify these details before enabling real payments. Never inherit a different company’s legal identity during clone/reconcile.</p>'],
          ['name'=>'legal_public_brand_name','title'=>'Public travel brand (optional; otherwise site title)','type'=>'text'],
          ['name'=>'legal_registered_entity_name','title'=>'Registered operator / legal entity','type'=>'text'],
          ['name'=>'legal_company_jurisdiction','title'=>'Registered country / jurisdiction','type'=>'text'],
          ['name'=>'legal_registered_address','title'=>'Registered business address','type'=>'textarea'],
          ['name'=>'legal_privacy_contact_email','title'=>'Legal/privacy contact email','type'=>'text'],
          ['name'=>'legal_identity_review_status','title'=>'Company identity review (requires complete details)','type'=>'select','options_source'=>'manual',
            'options'=>[['key'=>'pending','value'=>'Pending: legal identity not reviewed'],
                       ['key'=>'reviewed','value'=>'Reviewed and confirmed by company admin']],],
          ['name'=>'legal_tracking_consent_status','title'=>'Tracking and cookie consent review','type'=>'select','options_source'=>'manual',
            'options'=>[['key'=>'needs_review','value'=>'Requires company review'],
                       ['key'=>'verified','value'=>'Reviewed and configured for company markets'],
                       ['key'=>'not_applicable','value'=>'Not applicable / no optional tracking']],],

        ];
        $known=[];
        foreach($fields as $f)if(is_array($f)&&isset($f['name']))$known[(string)$f['name']]=true;
        foreach($extra as $i=>$f)if(!isset($known[$f['name']]))$fields[]=array_replace([
            'id'=>946300+$i,'object_type'=>'field','width'=>'100%'
        ],$f);
        return $fields;
    }
    public static function enqueue():void {
        if(is_admin()||!is_page())return;
        $post=get_post(get_queried_object_id());
        if(!$post||!str_contains((string)$post->post_content,'[mad4b_company_legal_document'))return;
        $path=__DIR__.'/assets/mad4b-company-legal.css';
        if(is_file($path))wp_enqueue_style('mad4b-company-legal',plugins_url('assets/mad4b-company-legal.css',__FILE__),[],(string)filemtime($path));
    }
    public static function boot():void {
        add_shortcode(self::SHORTCODE,[self::class,'render']);
        add_filter('rank_math/frontend/robots',[self::class,'rankMathRobots'],99);
        add_action('admin_notices',[self::class,'adminNotice'],120);
        add_filter('jet-engine/options-pages/raw-fields',[self::class,'fields'],112,2);
        add_action('wp_enqueue_scripts',[self::class,'enqueue'],115);
    }
}
MAD4B_Company_Legal_Documents::boot();
}
