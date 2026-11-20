<?php
/**
 * MAD4B Currency Choice Provider.
 *
 * JetEngine glossary is the sole SOURCE of currency choice *codes*.
 * WooCommerce is the separate supported-currency validator and provides
 * standardized display names when glossary labels contain country-specific
 * duplicates (for example EUR appears several times in the legacy glossary).
 *
 * The adapter never changes WooCommerce, WCML, FX rates, CPT values, native
 * JetEngine field types, settings defaults, or a price.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Currency_Glossary_V1 {
    private const GLOSSARY_ID=101;
    private static ?array $cache=null;

    public static function glossary_id():int {
        return self::read()['id'];
    }

    private static function read():array {
        if(self::$cache!==null)return self::$cache;
        global $wpdb;
        $table=$wpdb->prefix.'jet_post_types';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,labels,meta_fields FROM {$table} WHERE status=%s AND id=%d LIMIT 1",
            'glossary',self::GLOSSARY_ID
        ),ARRAY_A);
        $title=is_array($row)?maybe_unserialize($row['labels']??''):[];
        if(!is_array($row)||!is_array($title)
            ||strcasecmp(trim((string)($title['name']??'')),'Currencies')!==0){
            // Company IDs can vary after provisioning: locate the named
            // native glossary, but do not invent or silently copy another.
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,labels,meta_fields FROM {$table} WHERE status=%s ORDER BY id ASC LIMIT 120",
                'glossary'
            ),ARRAY_A);
            $row=null;
            foreach((array)$rows as $r){
                $labels=maybe_unserialize($r['labels']??'');
                if(is_array($labels)
                  &&strcasecmp(trim((string)($labels['name']??'')),'Currencies')===0){
                    $row=$r;break;
                }
            }
        }
        $woocommerce=function_exists('get_woocommerce_currencies')
            ?get_woocommerce_currencies():[];
        $options=[];$catalog=[];$duplicates=0;$rejected=0;$sourceCount=0;
        if(is_array($row)){
            $values=maybe_unserialize($row['meta_fields']??'');
            foreach((array)$values as $item){
                ++$sourceCount;
                if(!is_array($item)){$rejected++;continue;}
                $code=strtoupper(trim((string)($item['value']??'')));
                if(!preg_match('/^[A-Z]{3}$/D',$code)){
                    ++$rejected;continue;
                }
                if(isset($catalog[$code])){$duplicates++;continue;}
                $nativeLabel=wp_strip_all_tags((string)($item['label']??$code));
                $catalog[$code]=$code.' — '.$nativeLabel;
                // Keep the full JetEngine catalog visible in Configuration,
                // but a separate WooCommerce-supported list is the safe
                // currency universe for pricing/Rate authoring.
                if(!isset($woocommerce[$code])){$rejected++;continue;}
                $options[$code]=$code.' — '.wp_strip_all_tags((string)$woocommerce[$code]);
            }
        }
        ksort($options,SORT_STRING);
        ksort($catalog,SORT_STRING);
        return self::$cache=[
            'id'=>is_array($row)?(int)$row['id']:0,
            'source'=>'jetengine_glossary',
            'name'=>'Currencies',
            'options'=>$options,'catalog'=>$catalog,'raw_entries'=>$sourceCount,
            'duplicates_collapsed'=>$duplicates,
            'non_supported_entries'=>$rejected,
            'status'=>$options?'ready':'unavailable'
        ];
    }

    public static function choices():array {return self::read()['options'];}
    public static function catalog():array {return self::read()['catalog'];}
    public static function diagnostics():array {
        return array_diff_key(self::read(),['options'=>true,'catalog'=>true]);
    }
    public static function supports(string $currency):bool {
        return isset(self::choices()[strtoupper(trim($currency))]);
    }
    public static function options_html(string $selected='',bool $allowEmpty=false):string {
        $html=$allowEmpty?'<option value="">Inherit WooCommerce currency</option>':'';
        foreach(self::choices() as $code=>$label)
            $html.='<option value="'.esc_attr($code).'"'.
                selected($code,$selected,false).'>'.esc_html($label).'</option>';
        return $html;
    }

    public static function boot():void {
        if(!is_admin())return;
        // Let JetEngine itself draw the complete glossary select for
        // the settings field instead of a cramped browser datalist.
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'native_fields'],20,2);
        // On some companies JetEngine constructs the Options Page earlier
        // than MAD4B Functional Modes boot. Reconcile the already-registered
        // in-memory native page before JetEngine initializes its editor.
        add_action('admin_init',[__CLASS__,'sync_registered_page'],5);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],85);
    }
    public static function native_fields($fields,$page) {
        $slug=is_object($page)?(string)($page->slug??''):'';
        if(!in_array($slug,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true)
          ||!is_array($fields)||!self::glossary_id())return $fields;
        foreach($fields as &$field) {
            if(!is_array($field)||($field['name']??'')!=='default_currency')continue;
            $field['type']='select';
            $field['options_source']='glossary';
            $field['glossary_id']=(string)self::glossary_id();
            $field['is_multiple']=false;
            $field['placeholder']='Inherit WooCommerce store currency';
            break;
        }
        unset($field);
        return $fields;
    }

    public static function sync_registered_page():void {
        if(!is_admin()||!self::glossary_id()
            ||!function_exists('jet_engine'))return;
        $manager=jet_engine()->options_pages??null;
        if(!is_object($manager)||!isset($manager->registered_pages)
            ||!is_array($manager->registered_pages))return;
        foreach(['etg-dynamic-pricing-controls','dynamic-pricing-controls'] as $slug) {
            $page=$manager->registered_pages[$slug]??null;
            if(!is_object($page)||!is_array($page->meta_box??null)
                ||!is_callable([$page,'prepare_meta_fields']))continue;
            $before=$page->meta_box;
            $updated=self::native_fields($before,$page);
            if($updated!==$before){
                $page->meta_box=$updated;
                $page->page['fields']=$page->prepare_meta_fields($updated);
            }
            // This also applies when the native raw-fields hook was
            // registered early enough to run in JetEngine's constructor.
            // Constructor may use a WooCommerce USD schema default even
            // though no separate company option exists. Empty remains
            // Inherit, rather than silently writing a store override.
            $stored=get_option($slug.'_default_currency','__UNSET__');
            if($stored==='__UNSET__' &&
                isset($page->page['fields']['default_currency']))
                $page->page['fields']['default_currency']['value']='';
            $page->prepared_fields=null;
        }
    }

    public static function assets($hook=''):void {
        if(!current_user_can('edit_posts') && !current_user_can('commerce_manage_pricing'))return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array((string)$hook,['post.php','post-new.php'],true)
           &&$page!=='dynamic-pricing-controls'
           &&$page!=='etg-dynamic-pricing-controls')return;
        if(!self::choices())return; // fail closed without touching vendor fields
        $path=__DIR__.'/assets/currency-glossary-admin.js';
        wp_enqueue_script('mad4b-jetengine-currency-glossary',
            plugins_url('assets/currency-glossary-admin.js',__FILE__),[],
            (string)filemtime($path),true);
        wp_add_inline_script('mad4b-jetengine-currency-glossary',
            'window.MAD4BJetCurrencyChoices='.wp_json_encode([
                'source'=>'JetEngine Glossary #'.self::glossary_id(),
                'options'=>self::choices(),
            ]).';','before');
    }
}
