<?php
/**
 * Portable Elementor Theme Builder footer metadata.
 * Options authority: JetEngine Look & Feel; language: native WPML.
 */
defined('ABSPATH') || exit;

final class MAD4B_Footer_LookFeel {
    const SLUG = 'look-and-feel';

    private static function options(): array {
        $raw = get_option(self::SLUG, []);
        return is_array($raw) ? $raw : [];
    }
    private static function language(): string {
        $lang = (string) apply_filters('wpml_current_language', 'en');
        return in_array($lang, ['en','fr','de','es','it','ar'], true) ? $lang : 'en';
    }
    private static function labels(): array {
        return [
            'en'=>['legal'=>'Legal','payments'=>'Payment methods','languages'=>'Languages','social'=>'Social Channels','contact'=>'Contact details','address'=>'Address','privacy'=>'Privacy Policy','terms'=>'Terms of Use'],
            'fr'=>['legal'=>'Informations légales','payments'=>'Moyens de paiement','languages'=>'Langues','social'=>'Réseaux sociaux','contact'=>'Coordonnées','address'=>'Adresse','privacy'=>'Politique de confidentialité','terms'=>"Conditions d’utilisation"],
            'de'=>['legal'=>'Rechtliches','payments'=>'Zahlungsmethoden','languages'=>'Sprachen','social'=>'Soziale Kanäle','contact'=>'Kontaktinformationen','address'=>'Adresse','privacy'=>'Datenschutzrichtlinie','terms'=>'Nutzungsbedingungen'],
            'es'=>['legal'=>'Información legal','payments'=>'Métodos de pago','languages'=>'Idiomas','social'=>'Redes sociales','contact'=>'Datos de contacto','address'=>'Dirección','privacy'=>'Política de privacidad','terms'=>'Términos de uso'],
            'it'=>['legal'=>'Informazioni legali','payments'=>'Metodi di pagamento','languages'=>'Lingue','social'=>'Canali social','contact'=>'Contatti','address'=>'Indirizzo','privacy'=>'Informativa sulla privacy','terms'=>'Condizioni di utilizzo'],
            'ar'=>['legal'=>'روابط قانونية','payments'=>'طرق الدفع','languages'=>'اللغات','social'=>'قنوات التواصل الاجتماعي','contact'=>'بيانات التواصل','address'=>'العنوان','privacy'=>'سياسة الخصوصية','terms'=>'شروط الاستخدام'],
        ];
    }
    public static function heading(string $name): string {
        if (!in_array($name, ['legal','payments','languages','social'], true)) return '';
        $field = 'footer_heading_'.$name;
        $opts = self::options();
        $value = is_string($opts[$field]??null) ? trim($opts[$field]) : '';
        $lang = self::language();
        if ($value !== '') {
            $translated = apply_filters('wpml_translate_single_string',$value,'mad4b-footer',$field,$lang);
            if (is_string($translated) && trim($translated)!=='' && ($lang==='en' || $translated!==$value))
                return $translated;
            if ($lang==='en') return $value;
        }
        return self::labels()[$lang][$name];
    }
    public static function option_fields($fields, $page) {
        if (!is_object($page) || ($page->slug??'')!==self::SLUG || !is_array($fields)) return $fields;
        $add = [
            ['name'=>'mad4b_footer_layout_start','title'=>'Footer Metadata','object_type'=>'endpoint'],
            ['name'=>'mad4b_footer_metadata_tab','title'=>'Footer metadata','object_type'=>'tab','tab_layout'=>'horizontal'],
            ['name'=>'footer_logo_inverse','title'=>'White / inverse brand logo','type'=>'media','object_type'=>'field','value_format'=>'id','allowed_mime_types'=>['image/png','image/jpeg','image/webp'],'description'=>'Use a white logo for dark backgrounds. Blank inherits the existing inverse Brand Kit logo; no demo/company logo is imposed.'],
            ['name'=>'footer_heading_legal','title'=>'Legal links heading (WPML)','type'=>'text','object_type'=>'field','description'=>'Translate using WPML String Translation, domain mad4b-footer.'],
            ['name'=>'footer_heading_payments','title'=>'Payment methods heading (WPML)','type'=>'text','object_type'=>'field'],
            ['name'=>'footer_heading_languages','title'=>'Languages heading (WPML)','type'=>'text','object_type'=>'field'],
            ['name'=>'footer_heading_social','title'=>'Social Channels heading (WPML)','type'=>'text','object_type'=>'field'],
            ['name'=>'footer_brand_description','title'=>'Footer brand description (WPML)','type'=>'textarea','object_type'=>'field'],
            ['name'=>'footer_privacy_page','title'=>'Privacy Policy · published page','type'=>'posts','post_type'=>'page','is_multiple'=>false,'object_type'=>'field','description'=>'Use a published page; the footer will resolve its WPML translation automatically. No placeholder URL is rendered.'],
            ['name'=>'footer_terms_page','title'=>'Terms of Use · published page','type'=>'posts','post_type'=>'page','is_multiple'=>false,'object_type'=>'field','description'=>'Use a published page; the footer will resolve its WPML translation automatically.'],
            ['name'=>'footer_payment_methods','title'=>'Payment icons · add or reorder methods','type'=>'repeater','object_type'=>'field','repeater-fields'=>[
                ['name'=>'name','title'=>'Payment method name','type'=>'text','object_type'=>'field'],
                ['name'=>'image','title'=>'Icon image','type'=>'media','object_type'=>'field','value_format'=>'id','allowed_mime_types'=>['image/png','image/jpeg','image/webp']],
            ],'description'=>'Each method uses its own Media Library image. Add or remove methods as required.'],
            ['name'=>'mad4b_footer_metadata_end','title'=>'End footer metadata','object_type'=>'endpoint']
        ];
        $known=[];
        foreach ($fields as $field) if (is_array($field) && isset($field['name'])) $known[$field['name']]=true;
        foreach ($add as $field) if (!isset($known[$field['name']])) $fields[]=$field;
        return $fields;
    }
    public static function register_strings(): void {
        $o=self::options();
        foreach (['footer_heading_legal','footer_heading_payments','footer_heading_languages','footer_heading_social','footer_brand_description'] as $key) {
            $value=$o[$key]??null;
            if (is_string($value) && trim($value)!=='')
                do_action('wpml_register_single_string','mad4b-footer',$key,$value);
        }
        $kit=get_post_meta((int)get_option('elementor_active_kit'),'_elementor_page_settings',true);
        $site_tagline=is_array($kit) ? ($kit['site_description']??'') : '';
        if (is_string($site_tagline) && trim($site_tagline)!=='')
            do_action('wpml_register_single_string','mad4b-footer','elementor-kit-site-description',trim($site_tagline));
    }
    private static function page_url(string $type): string {
        $o=self::options();
        $field=$type==='privacy'?'footer_privacy_page':'footer_terms_page';
        $id=(int)($o[$field]??0);
        if (!$id) $id=(int)get_option($type==='privacy'?'wp_page_for_privacy_policy':'woocommerce_terms_page_id');
        if (!$id) return '';
        $id=(int)apply_filters('wpml_object_id',$id,'page',false,self::language());
        if ($id<1 || get_post_status($id)!=='publish' || get_post_type($id)!=='page') return '';
        return get_permalink($id) ?: '';
    }
    public static function legal(): string {
        $labels=self::labels()[self::language()];
        $links=[];
        foreach (['privacy','terms'] as $key) {
            $url=self::page_url($key);
            if ($url!=='') $links[]='<a href="'.esc_url($url).'">'.esc_html($labels[$key]).'</a>';
        }
        return $links?'<nav class="mad4b-footer-legal" aria-label="'.esc_attr($labels['legal']).'">'.implode('',$links).'</nav>':'';
    }
    private static function image_id($raw): int {
        $id=is_array($raw)?($raw['id']??0):$raw;
        $id=is_numeric($id)?(int)$id:0;
        return $id && get_post_type($id)==='attachment' && in_array(get_post_mime_type($id),['image/png','image/jpeg','image/webp'],true)?$id:0;
    }
    public static function payments(): string {
        $o=self::options();
        $items=$o['footer_payment_methods']??[];
        if (!is_array($items)) return '';
        $html='';
        foreach (array_slice($items,0,16) as $item) {
            if (!is_array($item)) continue;
            $id=self::image_id($item['image']??0);
            $name=sanitize_text_field((string)($item['name']??''));
            if (!$id || !$name) continue;
            $image=wp_get_attachment_image($id,'medium',false,['alt'=>$name,'loading'=>'eager','decoding'=>'async','data-no-lazy'=>'1','class'=>'mad4b-footer-payment-image skip-lazy']);
            if ($image) $html.='<li title="'.esc_attr($name).'">'.$image.'</li>';
        }
        return $html?'<ul class="mad4b-footer-payments" aria-label="'.esc_attr(self::heading('payments')).'">'.$html.'</ul>':'';
    }

    /**
     * Positive image-owned evidence always wins over Elementor's text tagline.
     * The company artwork signoff applies ONLY to its currently enrolled
     * primary/inverse brand assets; a custom footer image cannot inherit it.
     * Media review evidence is bound to exact attachment file bytes.
     */
    private static function logo_embeds_slogan(int $id, array $options): bool {
        if (!$id) return false;
        $signoff=trim((string)($options['mad4b_logo_artwork_signoff']??''));
        if ($signoff!=='') {
            $approved=[];
            foreach (['mad4b_brand_logo_media','mad4b_brand_logo_inverse_media',
                      'company_brand_logo_default','company_brand_logo_inverse'] as $key) {
                $logo_id=self::image_id($options[$key]??0);
                if ($logo_id) $approved[]=$logo_id;
            }
            if (function_exists('company_brand_context')) {
                $context=company_brand_context();
                $media=is_array($context)?($context['media_ids']??[]):[];
                if (is_array($media)) {
                    foreach (['logo_default','logo_inverse'] as $key) {
                        $logo_id=self::image_id($media[$key]??0);
                        if ($logo_id) $approved[]=$logo_id;
                    }
                }
            }
            if (in_array($id,$approved,true)) return true;
        }
        $review=get_post_meta($id,'_mad4b_brand_logo_slogan_review_v1',true);
        if (!is_array($review)
            || ($review['contract']??'')!=='mad4b.brand-logo-slogan-review.v1'
            || ($review['status']??'')!=='embedded'
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($review['file_sha256']??''))) return false;
        $file=get_attached_file($id);
        if (!is_string($file) || !is_file($file)) return false;
        $actual=hash_file('sha256',$file);
        return is_string($actual) && hash_equals($review['file_sha256'],$actual);
    }

    /**
     * Media-library reviewer may classify just the current brand logo.
     * Absence of proof preserves existing text-tagline behavior. Classification
     * never grants an external asset permission or rewrites the brand artwork.
     */
    public static function logo_slogan_media_fields($fields, $post) {
        if (!is_object($post) || empty($post->ID) || !is_array($fields)
            || !current_user_can('manage_options')) return $fields;
        $id=(int)$post->ID;
        if (!self::image_id($id)) return $fields;
        $options=self::options();
        $known=[];
        foreach (['footer_logo_inverse','mad4b_brand_logo_media','mad4b_brand_logo_inverse_media',
                  'company_brand_logo_default','company_brand_logo_inverse'] as $key) {
            $asset=self::image_id($options[$key]??0);
            if ($asset) $known[]=$asset;
        }
        if (function_exists('company_brand_context')) {
            $context=company_brand_context();
            $media=is_array($context)?($context['media_ids']??[]):[];
            if (is_array($media)) foreach (['logo_default','logo_inverse'] as $key) {
                $asset=self::image_id($media[$key]??0);
                if ($asset) $known[]=$asset;
            }
        }
        if (!in_array($id,$known,true)) return $fields;
        $review=get_post_meta($id,'_mad4b_brand_logo_slogan_review_v1',true);
        $status=is_array($review)?(string)($review['status']??'unknown'):'unknown';
        if (!in_array($status,['unknown','embedded','plain'],true)) $status='unknown';
        if ($status!=='unknown') {
            $file=get_attached_file($id);
            $actual=is_string($file) && is_file($file) ? hash_file('sha256',$file) : '';
            if (!is_string($actual) || !is_array($review)
                || !hash_equals((string)($review['file_sha256']??''),$actual))
                $status='unknown';
        }
        $html='<select name="attachments['.$id.'][mad4b_logo_slogan_presence]">';
        foreach (['unknown'=>'Not reviewed','embedded'=>'Slogan included in logo image',
                   'plain'=>'Logo has no embedded slogan'] as $value=>$caption) {
            $html.='<option value="'.esc_attr($value).'"'.
                ($status===$value?' selected="selected"':'').'>'.esc_html($caption).'</option>';
        }
        $html.='</select>';
        $fields['mad4b_logo_slogan_presence']=[
            'label'=>'Embedded logo slogan',
            'input'=>'html',
            'html'=>$html,
            'helps'=>'The message inside the approved artwork always takes priority; text is displayed only when the selected logo has no confirmed embedded slogan.',
        ];
        return $fields;
    }

    public static function save_logo_slogan_media_field($post, $attachment) {
        if (!is_array($post) || !is_array($attachment)
            || !isset($attachment['mad4b_logo_slogan_presence'])) return $post;
        $id=absint($post['ID']??0);
        if (!$id || !current_user_can('manage_options')
            || !current_user_can('edit_post',$id) || !self::image_id($id)) return $post;
        $selected=self::logo_slogan_media_fields([],(object)['ID'=>$id]);
        if (!isset($selected['mad4b_logo_slogan_presence'])) return $post;
        $status=(string)$attachment['mad4b_logo_slogan_presence'];
        if (!in_array($status,['unknown','embedded','plain'],true)) return $post;
        $existing=get_post_meta($id,'_mad4b_brand_logo_slogan_review_v1',true);
        if ($status==='unknown') {
            if (!is_array($existing) || !$existing) return $post;
            delete_post_meta($id,'_mad4b_brand_logo_slogan_review_v1');
            self::invalidate_brand_public_html();
            return $post;
        }
        $file=get_attached_file($id);
        if (!is_string($file) || !is_file($file)) return $post;
        $sha=hash_file('sha256',$file);
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha)) return $post;
        $next=[
            'contract'=>'mad4b.brand-logo-slogan-review.v1',
            'status'=>$status,
            'file_sha256'=>$sha,
        ];
        if ($existing===$next) return $post;
        update_post_meta($id,'_mad4b_brand_logo_slogan_review_v1',$next);
        self::invalidate_brand_public_html();
        return $post;
    }

    private static function invalidate_brand_public_html(): void {
        // Existing company-scoped Safe Public HTML purge rotates LiteSpeed
        // public pages + the host FastCGI epoch; never flush Redis/Object Cache.
        do_action('mad4b/public_html/safe_purge_all','footer_logo_slogan_source_changed');
    }

    public static function brand_options_updated($before, $after): void {
        if (!is_array($before) || !is_array($after)) return;
        foreach (['footer_logo_inverse','mad4b_brand_logo_media',
                  'mad4b_brand_logo_inverse_media','company_brand_logo_default',
                  'company_brand_logo_inverse','mad4b_logo_artwork_signoff'] as $key) {
            if (($before[$key]??null)!==($after[$key]??null)) {
                self::invalidate_brand_public_html();
                return;
            }
        }
    }

    public static function kit_tagline_updated($meta_id, $post_id, $key): void {
        if ($key==='_elementor_page_settings'
            && (int)$post_id===(int)get_option('elementor_active_kit'))
            self::invalidate_brand_public_html();
    }

    public static function brand(): string {
        $o=self::options();
        $id=self::image_id($o['footer_logo_inverse']??0);
        if (!$id) $id=self::image_id($o['company_brand_logo_inverse']??0);
        if (!$id && function_exists('company_brand_context')) {
            $c=company_brand_context();
            $id=self::image_id($c['media_ids']['logo_inverse']??0);
        }
        $fallback=false;
        if (!$id) {
            $id=self::image_id($o['company_brand_logo_default']??0);
            $fallback=(bool)$id;
        }
        $name=(string)get_bloginfo('name');
        $logo=$id?wp_get_attachment_image($id,'full',false,['class'=>'mad4b-footer-logo-image'.($fallback?' mad4b-footer-logo-monochrome':''),'alt'=>$name,'loading'=>'lazy']):'';
        $rendered_logo_image=(bool)$logo;
        if (!$logo) $logo='<span class="mad4b-footer-wordmark">'.esc_html($name).'</span>';
        // Elementor Site Settings / Active Kit is the authoritative
        // tagline source. Translate the registered string through WPML.
        $kit=get_post_meta((int)get_option('elementor_active_kit'),'_elementor_page_settings',true);
        $description=is_array($kit) && is_string($kit['site_description']??null)
            ? trim($kit['site_description']) : '';
        if ($description==='') $description=trim((string)get_bloginfo('description'));
        // Artwork-owned slogan wins even when the separately configured text differs.
        if ($rendered_logo_image && $id && self::logo_embeds_slogan($id,$o)) $description='';
        if ($description!=='') {
            $translated=apply_filters('wpml_translate_single_string',$description,
                'mad4b-footer','elementor-kit-site-description',self::language());
            if (is_string($translated) && $translated!=='') $description=$translated;
        }
        return '<div class="mad4b-footer-brand"><a href="'.esc_url(apply_filters('wpml_home_url',home_url('/'))).'" aria-label="'.esc_attr($name).'">'.$logo.'</a>'.
            ($description?'<p class="mad4b-footer-kit-tagline">'.esc_html($description).'</p>':'').'</div>';
    }
    public static function contact(): string {
        // Source of truth: the company's existing JetEngine Contact & Help Info Page Options.
        $o=get_option('contact-and-help-info', []);
        if (!is_array($o)) return '';
        $labels=self::labels()[self::language()];
        $items=[];
        $make_item=static function (string $label, string $value, string $url='', bool $external=false): string {
            $title='<span class="mad4b-footer-contact-label">'.esc_html($label).'</span>';
            $content='<span class="mad4b-footer-contact-value">'.esc_html($value).'</span>';
            if ($url!=='' && esc_url($url)!=='') {
                $attrs=$external?' target="_blank" rel="noopener noreferrer"':'';
                $content='<a href="'.esc_url($url).'"'.$attrs.'>'.$content.'</a>';
            }
            return '<li>'.$title.$content.'</li>';
        };
        $email=sanitize_email((string)($o['site_email']??''));
        if ($email!=='' && is_email($email))
            $items[]=$make_item('Email',$email,'mailto:'.$email);
        $phone=sanitize_text_field((string)($o['site_whatsapp_num']??''));
        $digits=preg_replace('/\D+/', '', $phone);
        if (strlen($digits)>=8 && strlen($digits)<=15)
            $items[]=$make_item('WhatsApp',$phone,'https://wa.me/'.$digits,true);
        $address=sanitize_text_field((string)($o['site_location_address']??''));
        $map=(string)($o['site_location_address_link']??'');
        $map=(wp_parse_url($map,PHP_URL_SCHEME)==='https') ? $map : '';
        if ($address!=='')
            $items[]=$make_item($labels['address'],$address,$map,true);
        if (!$items) return '';
        return '<ul class="mad4b-footer-contact" aria-label="'.esc_attr($labels['contact']).'">'.implode('',$items).'</ul>';
    }

    public static function copyright(): string {
        $name=(string)get_bloginfo('name');
        $lang=self::language();
        $year=(string)gmdate('Y');
        return '<div class="mad4b-footer-copyright"><small>'.
          esc_html(($lang==='ar'?'جميع الحقوق محفوظة':'©').' '.$year.' '.$name.($lang==='ar'?' ©':'')).'</small></div>';
    }
    public static function tags($manager): void {
        if (!class_exists('Elementor\\Core\\DynamicTags\\Tag') || !class_exists('Elementor\\Modules\\DynamicTags\\Module')) return;
        require_once __DIR__.'/372-mad4b-footer-heading-tag.php';
        $manager->register(new MAD4B_Footer_Heading_Dynamic_Tag());
    }
    public static function enqueue(): void {
        if (is_admin()) return;
        $asset=dirname(__DIR__).'/assets/mad4b-footer.css';
        if (is_file($asset)) wp_enqueue_style('mad4b-footer',plugins_url('assets/mad4b-footer.css', dirname(__DIR__).'/mad4b-footer-bridge.php'),[],(string)filemtime($asset));
        $interaction=dirname(__DIR__).'/assets/mad4b-footer-interactions.js';
        if (is_file($interaction)) wp_enqueue_script('mad4b-footer-interactions',plugins_url('assets/mad4b-footer-interactions.js', dirname(__DIR__).'/mad4b-footer-bridge.php'),[],(string)filemtime($interaction),true);
    }
    public static function boot(): void {
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'option_fields'],95,2);
        add_action('init',[__CLASS__,'register_strings'],60);
        add_action('elementor/dynamic_tags/register',[__CLASS__,'tags'],55);
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],125);
        add_filter('attachment_fields_to_edit',[__CLASS__,'logo_slogan_media_fields'],20,2);
        add_filter('attachment_fields_to_save',[__CLASS__,'save_logo_slogan_media_field'],20,2);
        add_action('update_option_look-and-feel',[__CLASS__,'brand_options_updated'],70,2);
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $event)
            add_action($event,[__CLASS__,'kit_tagline_updated'],70,3);
        add_shortcode('mad4b_footer_brand',[__CLASS__,'brand']);
        add_shortcode('mad4b_footer_payments',[__CLASS__,'payments']);
        add_shortcode('mad4b_footer_legal',[__CLASS__,'legal']);
        add_shortcode('mad4b_footer_contact',[__CLASS__,'contact']);
        add_shortcode('mad4b_footer_copyright',[__CLASS__,'copyright']);
    }
}
MAD4B_Footer_LookFeel::boot();
