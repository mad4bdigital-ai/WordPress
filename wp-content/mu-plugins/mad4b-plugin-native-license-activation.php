<?php
/**
 * Plugin Name: MAD4B Company Native Plugin License Activation
 * Description: Authenticated, recipient-local vendor activation. Never retrieves source secrets or fakes active status.
 * Version: 0.14.0
 */
if(!defined('ABSPATH'))exit;
final class MAD4B_Company_Native_Plugin_License_Activation {
    const AUDIT='mad4b_native_plugin_license_activation_audit_v1';
    const PAGE='mad4b-plugin-native-license-activation';
    private static function company():bool {
        return class_exists('MAD4B_Secure_Credential_Envelope') &&
               !MAD4B_Secure_Credential_Envelope::source();
    }
    private static function allowed():bool {
        return self::company() && current_user_can('manage_options');
    }
    /** Recipient-only nonsecret activation evidence for Fleet. */
    public static function evidence():array {
        $out=['contract'=>'mad4b.plugin-license-native-activation-evidence.v1',
            'provider_states'=>[],'sensitive_values_included'=>false];
        if(!self::company())return $out;
        $providerIds=['wp_all_import_pro','wp_all_export_pro','fluent_forms_pro'];
        $events=(array)get_option(self::AUDIT,[]);
        $inspect=class_exists('MAD4B_Plugin_License_Provider_Adapters')?
            (array)(MAD4B_Plugin_License_Provider_Adapters::inspect()['providers']??[]):[];
        foreach($providerIds as $id){
            $recent=null;
            foreach(array_reverse($events) as $event){
                if(!is_array($event)||($event['provider_id']??'')!==$id ||
                   ($event['contract']??'')!=='mad4b.native-company-license-activation.v1')continue;
                $when=strtotime((string)($event['time_utc']??''));
                if($when===false||$when>time()+60||$when<time()-30*DAY_IN_SECONDS)continue;
                $recent=$event;break;
            }
            $state=(string)($inspect[$id]['state']??'LICENSE_STATUS_UNAVAILABLE');
            $cached=(bool)($inspect[$id]['native_cached_valid']??false);
            $success=is_array($recent) && ($recent['event']??'')==='NATIVE_VENDOR_ACTIVATED'
                && ($recent['success']??false)===true;
            $out['provider_states'][$id]=[
                'evidence_state'=>$success && $cached?'RECENT_VENDOR_ACTIVATION_RECEIPT_AND_LOCAL_VALID':
                    ($success?'VENDOR_RECEIPT_PRESENT_LOCAL_STATUS_CHANGED':
                    ($recent?'NO_CONFIRMED_VENDOR_ACTIVATION':'NOT_YET_ACTIVATED_BY_MAD4B')),
                'native_license_canonical_state'=>sanitize_key($state),
                'vendor_activated_at_utc'=>$success?(string)$recent['time_utc']:null,
                'last_operation_succeeded'=>$success && $cached,
                'source_secret_transferred'=>false,'key_value_included'=>false];
        }
        return $out;
    }
    public static function native_pages():array {
        return [
            'wp_all_import_pro'=>'admin.php?page=pmxi-admin-settings&tab=licenses',
            'wp_all_export_pro'=>'admin.php?page=pmxe-admin-settings&tab=licenses',
            'ultimate_addons_elementor'=>'admin.php?page=uaepro#settings',
            'fluent_forms_pro'=>'admin.php?page=fluent_forms_settings',
            'elementor_pro'=>'admin.php?page=elementor-license',
        ];
    }
    private static function audit(string $code,bool $success):void {
        $events=(array)get_option(self::AUDIT,[]);
        $events[]=['contract'=>'mad4b.native-company-license-activation.v1',
            'provider_id'=>'fluent_forms_pro','event'=>$code,'success'=>$success,
            'time_utc'=>gmdate('c'),'contains_key'=>false,'vendor_error_included'=>false];
        update_option(self::AUDIT,array_slice($events,-60),false);
    }
    public static function fluent_form_activate(string $raw):array|WP_Error {
        if(!self::allowed())return new WP_Error('mad4b_activation_forbidden','Authenticated company administrator required.');
        $key=trim($raw);
        if(strlen($key)<12 || strlen($key)>512 || preg_match('/[\x00-\x20\x7F]/',$key))
            return new WP_Error('mad4b_activation_key_invalid','A nonempty vendor license key is required.');
        $class='FluentFormPro\\FluentUpdater\\FluentLicensing';
        if(!class_exists($class))return new WP_Error('mad4b_vendor_not_installed','Fluent Forms Pro is not loaded.');
        try{$native=$class::getInstance();}catch(\Throwable $e){return new WP_Error('mad4b_vendor_not_ready','Fluent Forms Pro licensing has not initialized.');}
        $option=(string)$native->settingsKey;
        if($option!=='__fluentformpro_license')return new WP_Error('mad4b_vendor_option_mismatch','Unknown vendor license storage location.');
        $before=get_option($option,null);
        try{$original=(array)$native->getStatus(false);}
        catch(\Throwable $e){return new WP_Error('mad4b_native_status_unavailable','Could not inspect the previous vendor license.');}
        if(!empty($original['license_key']) && !hash_equals((string)$original['license_key'],$key))
            return new WP_Error('mad4b_license_replacement_requires_native_admin','Existing vendor license belongs to another key. Use native vendor settings.');
        if(!empty($original['license_key']) && ($original['status']??'')==='valid'){
            return ['result'=>'ALREADY_ACTIVE','vendor_confirmed'=>false,'cached_vendor_status_only'=>true,
                'changed'=>false,'credential_included'=>false];
        }
        // Use the installed vendor's native activation API and storage. Never
        // synthesize a 'valid' value, nor call an unverified source-side API.
        try{
            $result=$native->activate($key);
            if(is_wp_error($result)){
                // Some vendor versions persist a candidate before returning an
                // error. Restore the exact previous local value even in this
                // branch; do not leave a rejected credential configured.
                if($before===null)delete_option($option);
                else update_option($option,$before,false);
                $restored=get_option($option,null)===$before;
                self::audit($restored?'VENDOR_REFUSED':'ROLLBACK_NOT_CONFIRMED',false);
                return new WP_Error($restored?'mad4b_vendor_activation_failed':
                    'mad4b_vendor_rollback_not_verified',
                    'Vendor did not confirm activation; original local license was restored only if independently verified.');
            }
            $after=(array)$native->getStatus(false);
            if(!is_array($result) || ($result['status']??'')!=='valid' ||
                ($after['status']??'')!=='valid' ||
                empty($after['license_key']) ||
                !hash_equals($key,(string)$after['license_key'])){
                // Restore exact prior WordPress option. Vendor activation is
                // not asserted when its own result/readback is inconclusive.
                if($before===null)delete_option($option);
                else update_option($option,$before,false);
                self::audit('VENDOR_READBACK_UNVERIFIED',false);
                return new WP_Error('mad4b_vendor_activation_unverified','Vendor result/readback was not valid; original local license state restored.');
            }
            self::audit('NATIVE_VENDOR_ACTIVATED',true);
            return ['result'=>'VENDOR_ACTIVATED','vendor_confirmed'=>true,'changed'=>true,
                'credential_included'=>false];
        }catch(\Throwable $e){
            if($before===null)delete_option($option);
            else update_option($option,$before,false);
            self::audit('VENDOR_EXECUTION_FAILED',false);
            return new WP_Error('mad4b_vendor_execution_failed','Vendor activation could not complete; prior state restored.');
        }
    }
    /** WP All Import core license uses per-addon 'licenses' and 'statuses'.
     * The legacy LicenseActivator operates on a removed top-level 'license'
     * field and MUST NOT be used for the current PMXI core product.
     */
    private static function wp_import_activate_current(string $key):array|WP_Error {
        $option='PMXI_Plugin_Options';
        $class='Wpai\\App\\License\\Licensing';
        $responseAdapter='Wpai\\App\\License\\ResponseAdapter';
        if(!class_exists('PMXI_Plugin') || !class_exists($class) ||
           !class_exists($responseAdapter) || !method_exists('PMXI_Plugin','getEddName'))
            return new WP_Error('mad4b_import_vendor_not_ready','WP All Import native licensing is not available.');
        $before=get_option($option,null);
        if(!is_array($before))return new WP_Error('mad4b_import_options_unreadable','Native Import options are unavailable.');
        $existingEncoded=(string)($before['licenses']['PMXI_Plugin']??'');
        $existing=$existingEncoded!==''?(string)PMXI_Plugin::decode($existingEncoded):'';
        if($existing!=='' && !hash_equals($existing,$key))
            return new WP_Error('mad4b_vendor_key_replacement_denied','Existing Import license must be changed in the plugin admin.');
        if($existing!=='' && ($before['statuses']['PMXI_Plugin']??'')==='valid')
            return ['result'=>'ALREADY_ACTIVE','vendor_confirmed'=>false,
                'cached_vendor_status_only'=>true,'changed'=>false,'credential_included'=>false];
        $endpoint=(string)($before['info_api_url_new']??'');
        $parts=wp_parse_url($endpoint);
        if(!is_array($parts) || ($parts['scheme']??'')!=='https' ||
           strtolower((string)($parts['host']??''))!=='update.wpallimport.com' ||
           !empty($parts['port']) || !empty($parts['user']) || !empty($parts['pass']) ||
           !empty($parts['query']) || !empty($parts['fragment']) ||
           !in_array((string)($parts['path']??''),['','/'],true))
            return new WP_Error('mad4b_import_api_origin_invalid','Import vendor API endpoint is not trusted.');
        $item=PMXI_Plugin::getEddName();
        if(!is_string($item)||$item==='')
            return new WP_Error('mad4b_import_product_unavailable','Import vendor product identity is not available.');
        $changed=false;
        try{
            if($existing===''){
                $licenses=(array)($before['licenses']??[]);
                $statuses=(array)($before['statuses']??[]);
                $licenses['PMXI_Plugin']=$key;
                $statuses['PMXI_Plugin']='';
                // Set rollback responsibility before the vendor/plugin
                // mutates an option, including the case of a partial write.
                $changed=true;
                PMXI_Plugin::getInstance()->updateOption(['licenses'=>$licenses,'statuses'=>$statuses]);
            }
            // Matches PMXI_Admin_Settings::activate_licenses() for its core
            // product. The next recheck refreshes current gate and status.
            $url=esc_url_raw(add_query_arg([
                'edd_action'=>'activate_license','license'=>$key,
                'item_name'=>urlencode($item),'url'=>home_url()
            ],rtrim($endpoint,'/').'/check_license'));
            $reply=wp_remote_get($url,['timeout'=>15,'sslverify'=>true,'redirection'=>0]);
            if(is_wp_error($reply))throw new \RuntimeException('Vendor transport returned no answer.');
            $nativeReply=(new $responseAdapter())->adapt([
                'error'=>false,
                'code'=>(int)wp_remote_retrieve_response_code($reply),
                'body'=>(string)wp_remote_retrieve_body($reply)
            ],time());
            if(!is_object($nativeReply) || $nativeReply->license()!=='valid')
                throw new \RuntimeException('Vendor response did not establish a valid license.');
            // The plugin's own current licensing service must store a valid
            // canonical state, not just its legacy UI field.
            $class::instance()->recheck(true);
            wp_cache_delete($option,'options');
            $after=(array)get_option($option,[]);
            $now=(string)($after['licenses']['PMXI_Plugin']??'');
            $now=$now!==''?(string)PMXI_Plugin::decode($now):'';
            if($now==='' || !hash_equals($key,$now) ||
               ($after['statuses']['PMXI_Plugin']??'')!=='valid')
                throw new \RuntimeException('Native plugin license readback is not valid.');
            self::audit_provider('wp_all_import_pro','NATIVE_VENDOR_ACTIVATED',true);
            return ['result'=>'VENDOR_ACTIVATED','vendor_confirmed'=>true,
                'changed'=>true,'credential_included'=>false];
        }catch(\Throwable $e){
            // Restore only the Import main license/status pair; preserving
            // any other addon/user options changed during vendor network I/O.
            if($changed){
                try{
                    wp_cache_delete($option,'options');
                    $current=(array)get_option($option,[]);
                    $licenses=(array)($current['licenses']??[]);
                    $statuses=(array)($current['statuses']??[]);
                    if(array_key_exists('PMXI_Plugin',(array)($before['licenses']??[])))
                        $licenses['PMXI_Plugin']=$before['licenses']['PMXI_Plugin'];
                    else unset($licenses['PMXI_Plugin']);
                    if(array_key_exists('PMXI_Plugin',(array)($before['statuses']??[])))
                        $statuses['PMXI_Plugin']=$before['statuses']['PMXI_Plugin'];
                    else unset($statuses['PMXI_Plugin']);
                    PMXI_Plugin::getInstance()->updateOption([
                        'licenses'=>$licenses,'statuses'=>$statuses]);
                }catch(\Throwable $ignored){}
            }
            wp_cache_delete($option,'options');
            $restored=(array)get_option($option,[]);
            $rollbackVerified=($restored['licenses']['PMXI_Plugin']??'')===($before['licenses']['PMXI_Plugin']??'') &&
                ($restored['statuses']['PMXI_Plugin']??'')===($before['statuses']['PMXI_Plugin']??'');
            self::audit_provider('wp_all_import_pro',
                $rollbackVerified?'VENDOR_ACTIVATION_NOT_VERIFIED':'LOCAL_ROLLBACK_NOT_VERIFIED',false);
            return new WP_Error($rollbackVerified?'mad4b_import_activation_unverified':'mad4b_import_local_rollback_failed',
                'Import activation was not confirmed; local license state checked for restoration. Vendor-side activation must be reconciled separately if a response succeeded.');
        }
    }
    /** Vendor-native import/export activation from key entered ON RECIPIENT.
     * Never pulls a source-side key and never accepts a preselected status.
     */
    public static function wp_all_activate(string $provider,string $raw):array|WP_Error {
        if(!self::allowed())return new WP_Error('mad4b_activation_forbidden','Authenticated company admin required.');
        if(!in_array($provider,['wp_all_import_pro','wp_all_export_pro'],true))
            return new WP_Error('mad4b_vendor_provider_invalid','This vendor adapter is not installed.');
        $key=trim($raw);
        if(strlen($key)<12 || strlen($key)>512 || preg_match('/[\x00-\x20\x7F]/',$key))
            return new WP_Error('mad4b_activation_key_invalid','A vendor license key is required.');
        $isImport=$provider==='wp_all_import_pro';
        if($isImport)return self::wp_import_activate_current($key);
        $klass='PMXE_Plugin';
        $activator=$isImport?'Wpai\\App\\Service\\License\\LicenseActivator':
            'Wpae\\App\\Service\\License\\LicenseActivator';
        $option=$isImport?'PMXI_Plugin_Options':'PMXE_Plugin_Options';
        if(!class_exists($klass) || !class_exists($activator) ||
           !method_exists($klass,'getEddName') || !method_exists($klass,'decode'))
            return new WP_Error('mad4b_vendor_not_ready','Native plugin license API unavailable.');
        // Native plugins use their own encoded key representation. Keep all
        // unrelated options, addon licenses, and scheduling keys unchanged.
        $before=get_option($option,null);
        if(!is_array($before))return new WP_Error('mad4b_vendor_options_unreadable','Native license option store was not readable.');
        $instance=$klass::getInstance();
        $value=$isImport?(string)($before['licenses']['PMXI_Plugin']??''):
            (string)($before['license']??'');
        $existing=$value!==''?(string)$klass::decode($value):'';
        if($existing!=='' && !hash_equals($existing,$key))
            return new WP_Error('mad4b_vendor_key_replacement_denied','An existing license must be replaced in the vendor native admin.');
        $status=$isImport?(string)($before['statuses']['PMXI_Plugin']??''):
            (string)($before['license_status']??'');
        if($existing!=='' && $status==='valid')return [
            'result'=>'ALREADY_ACTIVE','vendor_confirmed'=>false,
            'cached_vendor_status_only'=>true,'changed'=>false,'credential_included'=>false];
        $attemptedChange=false;
        try{
            if($existing===''){
                if($isImport){
                    $licenses=(array)($before['licenses']??[]);
                    $licenses['PMXI_Plugin']=$key;
                    $statuses=(array)($before['statuses']??[]);
                    $statuses['PMXI_Plugin']=''; // Clear any stale prior-key status.
                    $instance->updateOption(['licenses'=>$licenses,'statuses'=>$statuses]);
                }else{$instance->updateOption(['license'=>$key,'license_status'=>'']);}
            }
            $attemptedChange=true;
            $item=$klass::getEddName();
            if(!is_string($item)||$item==='')throw new \RuntimeException('Vendor product identity missing.');
            $native=new $activator();
            $native->activateLicense($item);
            $fresh=(array)get_option($option,[]);
            $actual=$isImport?(string)($fresh['licenses']['PMXI_Plugin']??''):
                (string)($fresh['license']??'');
            $actual=$actual!==''?(string)$klass::decode($actual):'';
            $result=$isImport?(string)($fresh['statuses']['PMXI_Plugin']??''):
                (string)($fresh['license_status']??'');
            if($result!=='valid' || $actual==='' || !hash_equals($key,$actual)){
                // Restore exactly the key and status pair for which this
                // operation is responsible; never claim a vendor activation.
                if($attemptedChange){
                    if($isImport){
                        $instance->updateOption(['licenses'=>(array)$before['licenses'],
                            'statuses'=>(array)$before['statuses']]);
                    }else{$instance->updateOption(['license'=>(string)$before['license'],
                        'license_status'=>(string)$before['license_status']]);}
                }
                self::audit_provider($provider,'VENDOR_NO_VALID_LICENSE_RESPONSE',false);
                return new WP_Error('mad4b_vendor_license_unverified','Vendor did not confirm an active license; prior local license fields restored.');
            }
            self::audit_provider($provider,'NATIVE_VENDOR_ACTIVATED',true);
            return ['result'=>'VENDOR_ACTIVATED','vendor_confirmed'=>true,
                'changed'=>true,'credential_included'=>false];
        }catch(\Throwable $e){
            if($attemptedChange){
                try{
                    if($isImport)$instance->updateOption(['licenses'=>(array)$before['licenses'],
                        'statuses'=>(array)$before['statuses']]);
                    else $instance->updateOption(['license'=>(string)$before['license'],
                        'license_status'=>(string)$before['license_status']]);
                }catch(\Throwable $ignored){}
            }
            self::audit_provider($provider,'VENDOR_EXECUTION_FAILED',false);
            return new WP_Error('mad4b_vendor_execution_failed','Vendor activation failed; previous license fields restored when possible.');
        }
    }
    private static function audit_provider(string $provider,string $event,bool $success):void {
        $events=(array)get_option(self::AUDIT,[]);
        $events[]=['contract'=>'mad4b.native-company-license-activation.v1',
            'provider_id'=>$provider,'event'=>$event,'success'=>$success,
            'time_utc'=>gmdate('c'),'contains_key'=>false,'vendor_error_included'=>false];
        update_option(self::AUDIT,array_slice($events,-60),false);
    }
    /** Ephemeral proof displayed once to the same authenticated admin.
     * A ?result=vendor_activated URL alone must never render success.
     */
    private static function redirect_result(string $provider,array|WP_Error $result):void {
        $code=is_wp_error($result)?$result->get_error_code():(string)$result['result'];
        $state=sanitize_key($code);
        $token=wp_generate_password(32,false,false);
        $option='mad4b_license_flash_'.get_current_user_id().'_'.substr(hash('sha256',$token),0,24);
        set_transient($option,[
            'provider'=>$provider,'result'=>$state,
            'vendor_verified'=>!is_wp_error($result)&&!empty($result['vendor_confirmed']),
            'cached_only'=>!is_wp_error($result)&&!empty($result['cached_vendor_status_only'])
        ],5*MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,
            'provider'=>$provider,'notice'=>$token],admin_url('admin.php')));
        exit;
    }
    public static function process_wp_all():void {
        if(!self::allowed())wp_die('Forbidden',403);
        check_admin_referer('mad4b_wp_all_native_activation');
        $provider=sanitize_key((string)($_POST['provider_id']??''));
        $submitted=$_POST['native_license_key']??null;
        $key=is_string($submitted)?(string)wp_unslash($submitted):'';
        $result=self::wp_all_activate($provider,$key);
        self::redirect_result(in_array($provider,['wp_all_import_pro','wp_all_export_pro'],true)?$provider:'', $result);
    }
    public static function process():void {
        if(!self::allowed())wp_die('Forbidden',403);
        check_admin_referer('mad4b_fluent_forms_native_activation');
        $submitted=$_POST['native_license_key']??null;
        $input=is_string($submitted)?(string)wp_unslash($submitted):'';
        $result=self::fluent_form_activate($input);
        // Only a single-use same-user notice ticket enters the redirect URL.
        self::redirect_result('fluent_forms_pro',$result);
    }
    public static function menu():void {
        if(!self::company())return;
        add_submenu_page('mad4b-company-managed-credentials',
            'Plugin License Activation','Plugin License Activation','manage_options',self::PAGE,[__CLASS__,'render']);
    }
    public static function render():void {
        if(!self::allowed())wp_die('Forbidden',403);
        $statuses=class_exists('MAD4B_Plugin_License_Provider_Adapters')?
            (array)(MAD4B_Plugin_License_Provider_Adapters::inspect()['providers']??[]):[];
        $labels=[
            'wp_all_import_pro'=>'WP All Import Pro',
            'wp_all_export_pro'=>'WP All Export Pro',
            'ultimate_addons_elementor'=>'Ultimate Addons for Elementor',
            'fluent_forms_pro'=>'Fluent Forms Pro',
            'elementor_pro'=>'Elementor Pro',
        ];
        $selected=sanitize_key((string)($_GET['provider']??''));
        if(!isset($labels[$selected]))$selected='';
        echo '<div class="wrap"><h1>Activate plugin licenses on this company</h1>';
        if($selected)echo '<p><strong>Selected plugin:</strong> '.esc_html($labels[$selected]).'</p>';
        echo '<p>Licenses must be entered on this company site, or connected through the plugin\'s official account flow. The central dashboard does not move secret values into this page.</p>';
        $ticket=sanitize_text_field((string)($_GET['notice']??''));
        if($ticket!=='' && strlen($ticket)===32 && ctype_alnum($ticket)){
            $option='mad4b_license_flash_'.get_current_user_id().'_'.substr(hash('sha256',$ticket),0,24);
            $flash=get_transient($option);
            if(is_array($flash) && isset($labels[$flash['provider']??''])){
                delete_transient($option); // Cannot replay a success ticket.
                $verified=!empty($flash['vendor_verified']);
                $cached=!empty($flash['cached_only']);
                $message=$verified?'Vendor activation was confirmed by the installed plugin.':
                    ($cached?'The installed plugin already reports a cached valid license; no new vendor check was performed.':
                    'Vendor activation was not confirmed: '.(string)($flash['result']??'unknown'));
                echo '<div class="notice '.($verified?'notice-success':'notice-warning').'"><p>'.
                    esc_html($message).'</p></div>';
            }
        }
        echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Site license status</th><th>Activate</th></tr></thead><tbody>';
        foreach($labels as $id=>$label){
            $status=(string)($statuses[$id]['state']??'UNVERIFIED');
            $url=admin_url(self::native_pages()[$id]);
            echo '<tr'.($id===$selected?' style="outline:2px solid #2271b1"':'').'><td>'.esc_html($label).'</td><td>'.esc_html($status).'</td><td>';
            if(in_array($id,['wp_all_import_pro','wp_all_export_pro'],true)){
                echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post" autocomplete="off">';
                wp_nonce_field('mad4b_wp_all_native_activation');
                echo '<input type="hidden" name="action" value="mad4b_wp_all_native_activation">';
                echo '<input type="hidden" name="provider_id" value="'.esc_attr($id).'">';
                echo '<label for="mad4b-'.esc_attr($id).'-key">License key</label> ';
                echo '<input id="mad4b-'.esc_attr($id).'-key" type="password" name="native_license_key" required minlength="12" maxlength="512" autocomplete="new-password" size="28"> ';
                echo '<button type="submit" class="button button-primary">Activate with vendor</button></form>';
            }elseif($id==='fluent_forms_pro'){
                echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post" autocomplete="off">';
                wp_nonce_field('mad4b_fluent_forms_native_activation');
                echo '<input type="hidden" name="action" value="mad4b_fluent_forms_native_activation">';
                echo '<label for="mad4b-ff-native-key">Fluent Forms Pro license key</label> ';
                echo '<input id="mad4b-ff-native-key" type="password" name="native_license_key" required minlength="12" maxlength="512" autocomplete="new-password" size="28"> ';
                echo '<button type="submit" class="button button-primary">Activate with vendor</button></form>';
            }elseif($id==='elementor_pro' && class_exists('ElementorPro\\Plugin')){
                // Official Elementor Connect & Activate runs in this company's
                // WordPress session; do not transplant another site's account
                // tokens or fake an Elementor Pro license status.
                $vendorUrl='';
                try{
                    $elementor=\ElementorPro\Plugin::instance();
                    $nativeAdmin=$elementor->license_admin??null;
                    if(is_object($nativeAdmin) && method_exists($nativeAdmin,'get_connect_url'))
                        $vendorUrl=(string)$nativeAdmin->get_connect_url([
                            'utm_source'=>'mad4b-company-license-manager']);
                }catch(\Throwable $e){$vendorUrl='';}
                $scheme=wp_parse_url($vendorUrl,PHP_URL_SCHEME);
                if(is_string($vendorUrl) && $vendorUrl!=='' && $scheme==='https')
                    echo '<a class="button button-primary" rel="noopener noreferrer" href="'.
                        esc_url($vendorUrl).'">Connect & Activate Elementor account</a>';
                else echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=elementor-home')).
                    '">Open Elementor account settings</a>';
            }else {
                echo '<a class="button button-primary" href="'.esc_url($url).'">Open native vendor activation</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p>Fluent Forms activation uses its installed licensing API, vendor verification and native storage. Other providers use their own vendor-supported activation interfaces; no plugin is marked active by MAD4B alone.</p></div>';
    }
}
add_action('admin_menu',['MAD4B_Company_Native_Plugin_License_Activation','menu'],97);
add_action('admin_post_mad4b_fluent_forms_native_activation',
    ['MAD4B_Company_Native_Plugin_License_Activation','process']);
add_action('admin_post_mad4b_wp_all_native_activation',
    ['MAD4B_Company_Native_Plugin_License_Activation','process_wp_all']);
