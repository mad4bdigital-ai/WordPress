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
            'en'=>['legal'=>'Legal','payments'=>'Payment methods','languages'=>'Languages','privacy'=>'Privacy Policy','terms'=>'Terms of Use'],
            'fr'=>['legal'=>'Informations légales','payments'=>'Moyens de paiement','languages'=>'Langues','privacy'=>'Politique de confidentialité','terms'=>"Conditions d’utilisation"],
            'de'=>['legal'=>'Rechtliches','payments'=>'Zahlungsmethoden','languages'=>'Sprachen','privacy'=>'Datenschutzrichtlinie','terms'=>'Nutzungsbedingungen'],
            'es'=>['legal'=>'Información legal','payments'=>'Métodos de pago','languages'=>'Idiomas','privacy'=>'Política de privacidad','terms'=>'Términos de uso'],
            'it'=>['legal'=>'Informazioni legali','payments'=>'Metodi di pagamento','languages'=>'Lingue','privacy'=>'Informativa sulla privacy','terms'=>'Condizioni di utilizzo'],
            'ar'=>['legal'=>'روابط قانونية','payments'=>'طرق الدفع','languages'=>'اللغات','privacy'=>'سياسة الخصوصية','terms'=>'شروط الاستخدام'],
        ];
    }
    public static function heading(string $name): string {
        if (!in_array($name, ['legal','payments','languages'], true)) return '';
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
        foreach (['footer_heading_legal','footer_heading_payments','footer_heading_languages','footer_brand_description'] as $key) {
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
        if (!$logo) $logo='<span class="mad4b-footer-wordmark">'.esc_html($name).'</span>';
        // Elementor Site Settings / Active Kit is the authoritative
        // tagline source. Translate the registered string through WPML.
        $kit=get_post_meta((int)get_option('elementor_active_kit'),'_elementor_page_settings',true);
        $description=is_array($kit) && is_string($kit['site_description']??null)
            ? trim($kit['site_description']) : '';
        if ($description==='') $description=trim((string)get_bloginfo('description'));
        if ($description!=='') {
            $translated=apply_filters('wpml_translate_single_string',$description,
                'mad4b-footer','elementor-kit-site-description',self::language());
            if (is_string($translated) && $translated!=='') $description=$translated;
        }
        return '<div class="mad4b-footer-brand"><a href="'.esc_url(apply_filters('wpml_home_url',home_url('/'))).'" aria-label="'.esc_attr($name).'">'.$logo.'</a>'.
            ($description?'<p class="mad4b-footer-kit-tagline">'.esc_html($description).'</p>':'').'</div>';
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
        $asset=MAD4B_Presentation_Experience::asset_path('mad4b-footer.css');
        if (is_file($asset)) wp_enqueue_style('mad4b-footer',MAD4B_Presentation_Experience::asset_url('mad4b-footer.css'),[],(string)filemtime($asset));
    }
    public static function boot(): void {
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'option_fields'],95,2);
        add_action('init',[__CLASS__,'register_strings'],60);
        add_action('elementor/dynamic_tags/register',[__CLASS__,'tags'],55);
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],125);
        add_shortcode('mad4b_footer_brand',[__CLASS__,'brand']);
        add_shortcode('mad4b_footer_payments',[__CLASS__,'payments']);
        add_shortcode('mad4b_footer_legal',[__CLASS__,'legal']);
        add_shortcode('mad4b_footer_copyright',[__CLASS__,'copyright']);
    }
}
MAD4B_Footer_LookFeel::boot();
