<?php
/**
 * Plugin Name: MAD4B JetEngine Native Pricing Optimistic Concurrency
 * Version: 1.0.0
 * Description: Protects the original JetEngine Options Page Save before its native update_options operation.
 */
defined('ABSPATH') || exit;

final class MAD4B_JetEngine_Native_Pricing_CAS_V1 {
    public const PAGE='etg-dynamic-pricing-controls';
    public const ACTION='jet-engine-op-save-settings';
    public const NONCE_ACTION='mad4b_jetengine_native_pricing_cas_v1';
    private static $lockHeld=false;
    private static $lockName='';

    /** Use real JetEngine page's current field names and SEPARATE option identifiers. */
    private static function page() {
        if(!function_exists('jet_engine'))return new WP_Error('native_pricing_cas_jetengine_missing');
        $component=jet_engine()->options_pages??null;
        $pages=is_object($component)?($component->registered_pages??[]):[];
        foreach((array)$pages as $factory)
            if($factory instanceof Jet_Engine_Options_Page_Factory &&
               $factory->slug===self::PAGE &&
               $factory->storage_type==='separate')
                return $factory;
        return new WP_Error('native_pricing_cas_page_not_enrolled');
    }

    /** Query authoritative DB values rather than stale shared object-cache values. */
    public static function source_revision() {
        global $wpdb;
        $page=self::page();
        if(is_wp_error($page))return $page;
        $fields=$page->get_prepared_fields();
        if(!is_array($fields)||count($fields)<50||count($fields)>150)
            return new WP_Error('native_pricing_cas_field_contract_changed');
        $names=[];
        foreach($fields as $key=>$field){
            $option=(string)$page->get_separate_option_name($key);
            if($option===''||strlen($option)>191||isset($names[$option]))
                return new WP_Error('native_pricing_cas_source_mapping_invalid');
            $names[$option]=$option;
        }
        ksort($names,SORT_STRING);
        $args=array_values($names);
        $placeholders=implode(',',array_fill(0,count($args),'%s'));
        $sql=$wpdb->prepare(
            "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
            ...$args
        );
        $records=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($records))return new WP_Error('native_pricing_cas_storage_unavailable');
        $values=[];
        foreach($records as $record)
            $values[$record['option_name']]=$record['option_value'];
        $snap=[];
        foreach($names as $name)
            $snap[$name]=array_key_exists($name,$values)
                ?['exists'=>true,'value'=>$values[$name]]
                :['exists'=>false];
        // WP nonce protects form authorship. HMAC prevents the client
        // forging a revision from individual option values.
        return hash_hmac('sha256',serialize($snap),wp_salt('auth'));
    }

    /** Pure request admission. This is checked again WITH the DB advisory lock. */
    public static function check_request(array $post) {
        $p=self::page();
        if(is_wp_error($p))return $p;
        if(!current_user_can((string)($p->page['capability']??'manage_options'))
           ||!current_user_can('commerce_manage_pricing')
           ||!current_user_can('manage_woocommerce'))
            return new WP_Error('native_pricing_cas_forbidden');
        $nonce=$post['mad4b_native_cas_nonce']??'';
        $revision=$post['mad4b_native_cas_revision']??'';
        if(!is_string($nonce)||!wp_verify_nonce($nonce,self::NONCE_ACTION))
            return new WP_Error('native_pricing_cas_nonce_missing');
        if(!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision))
            return new WP_Error('native_pricing_cas_revision_missing');
        $now=self::source_revision();
        if(is_wp_error($now))return $now;
        if(!hash_equals($now,$revision))
            return new WP_Error('native_pricing_cas_stale_source');
        return ['approved_to_call_native_writer'=>true,
            'source_revision'=>$now,'native_page'=>self::PAGE,
            'write_executed'=>false];
    }

    private static function lock() {
        global $wpdb;
        $key='mad4b:pricing:'.substr(hash('sha256',DB_NAME.':'.self::PAGE),0,38);
        $acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$key));
        if((string)$acquired!=='1')
            return new WP_Error('native_pricing_cas_lock_busy');
        self::$lockHeld=true;
        self::$lockName=$key;
        return true;
    }
    public static function unlock():void {
        if(!self::$lockHeld)return;
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::$lockName));
        self::$lockHeld=false;self::$lockName='';
    }

    /** admin_init priority 39 occurs immediately before vendor save() at 40. */
    public static function before_native_save():void {
        if(!is_admin()||
            ($_SERVER['REQUEST_METHOD']??'')!=='POST' ||
            (string)($_GET['page']??'')!==self::PAGE ||
            (string)($_REQUEST['action']??'')!==self::ACTION)return;

        $lock=self::lock();
        if(is_wp_error($lock))self::deny($lock);
        $request=[];
        foreach(['mad4b_native_cas_nonce','mad4b_native_cas_revision'] as $key){
            $value=$_POST[$key]??'';
            $request[$key]=is_string($value)?sanitize_text_field(wp_unslash($value)):'';
        }
        $valid=self::check_request($request);
        if(is_wp_error($valid)){
            self::unlock();
            self::deny($valid);
        }
        // Let JetEngine's registered save() perform the ONE original update,
        // while the MySQL advisory lock is held. after-save and shutdown
        // release the lock. No shadow option values or secondary form writes.
    }

    private static function deny(WP_Error $error):void {
        $code=$error->get_error_code();
        $stale=$code==='native_pricing_cas_stale_source';
        $message=$stale
            ?'Pricing Controls has changed since this page was opened. No settings were saved. Copy any unsaved edits, reload the page, then review the latest values before saving.'
            :'Protected Pricing Controls Save was not completed ('.$code.'). No settings were saved. Reload the original JetEngine page and retry.';
        wp_die(esc_html($message),'Pricing Controls — Save blocked',
            ['response'=>$stale?409:403,'back_link'=>true]);
    }

    public static function enqueue():void {
        if(!is_admin()||
            !in_array((string)($_GET['page']??''),
              [self::PAGE,'dynamic-pricing-controls'],true))return;
        $page=self::page();
        if(is_wp_error($page) ||
          !current_user_can((string)($page->page['capability']??'manage_options')) ||
          !current_user_can('commerce_manage_pricing') ||
          !current_user_can('manage_woocommerce'))return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-jetengine-native-pricing-cas-v1.js';
        if(!is_readable($file))return;
        $rev=self::source_revision();
        if(is_wp_error($rev))return;
        wp_enqueue_script('mad4b-jetengine-native-pricing-cas-v1',
            content_url('mu-plugins/assets/mad4b-jetengine-native-pricing-cas-v1.js'),
            [],(string)filemtime($file),true);
        wp_add_inline_script('mad4b-jetengine-native-pricing-cas-v1',
            'window.MAD4BJetEngineNativePricingCAS='.wp_json_encode([
                'nonce'=>wp_create_nonce(self::NONCE_ACTION),
                'revision'=>$rev,
                'action'=>self::ACTION,
            ]).';','before');
    }
}
add_action('admin_init',[MAD4B_JetEngine_Native_Pricing_CAS_V1::class,'before_native_save'],39);
add_action('jet-engine/options-pages/after-save/'.MAD4B_JetEngine_Native_Pricing_CAS_V1::PAGE,
    [MAD4B_JetEngine_Native_Pricing_CAS_V1::class,'unlock'],999);
add_action('shutdown',[MAD4B_JetEngine_Native_Pricing_CAS_V1::class,'unlock'],999);
add_action('admin_enqueue_scripts',
    [MAD4B_JetEngine_Native_Pricing_CAS_V1::class,'enqueue'],70);
