<?php
/** Provider-specific read-only diagnostic adapters. No provider option writes. */
defined('ABSPATH') || exit;
final class MAD4B_Configuration_Provider_Adapters {
    const V='mad4b.configuration-provider-adapters.v1';
    private static function enabled(string $slug):bool {
        foreach((array)get_option('active_plugins',[]) as $f)
            if($f===$slug || str_starts_with($f,$slug.'/'))return true;
        return false;
    }
    private static function signal(string $p,string $code,string $severity,string $label,string $guidance,string $scope='site'):array {
        return compact('severity','label','guidance','scope')+['provider'=>$p,'code'=>$code,'source'=>'probe'];
    }
    private static function table(string $suffix):bool {
        global $wpdb;$t=$wpdb->prefix.$suffix;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t;
    }
    /** Failures within a provider never silently close its previously open alerts. */
    private static function each(string $provider,callable $fn,array &$out):void {
        try{
            $found=$fn();
            if(!is_array($found))throw new RuntimeException('invalid_adapter_result');
            foreach($found as $r)if(is_array($r))$out[]=$r;
        }catch(Throwable $e){
            $out[]=self::signal($provider,'provider_diagnostics_unavailable','medium',
                'Plugin diagnostics could not complete reliably',
                'Retry the bounded provider health read; verify plugin bootstrap and site database before resolving existing incidents.','diagnostic');
        }
    }
    public static function discover(array $signals):array {
        if(self::enabled('elementor'))self::each('elementor',[__CLASS__,'elementor'],$signals);
        if(self::enabled('jet-engine'))self::each('jet-engine',[__CLASS__,'jetengine'],$signals);
        if(self::enabled('sitepress-multilingual-cms'))self::each('wpml',[__CLASS__,'wpml'],$signals);
        if(self::enabled('litespeed-cache'))self::each('litespeed-cache',[__CLASS__,'litespeed'],$signals);
        if(self::enabled('seo-by-rank-math'))self::each('rank-math',[__CLASS__,'rankmath'],$signals);
        if(self::enabled('fluentform'))self::each('fluent-forms',[__CLASS__,'fluentforms'],$signals);
        if(self::enabled('fluent-smtp'))self::each('fluent-smtp',[__CLASS__,'fluentsmtp'],$signals);
        return $signals;
    }
    public static function elementor():array {
        $out=[];$kit=(int)get_option('elementor_active_kit');
        if($kit && get_post_type($kit)!=='elementor_library')
            $out[]=self::signal('elementor','active_kit_invalid','high',
                'Elementor Active Kit points to a missing or incorrect document',
                'Inspect Elementor Site Settings > Active Kit and verify the linked Elementor Library record.','site-kit');
        $upload=wp_upload_dir(null,false);
        if(empty($upload['error']) && !empty($upload['basedir'])){
            $path=trailingslashit($upload['basedir']).'elementor/css';
            if(is_dir($path) && !is_writable($path))
                $out[]=self::signal('elementor','generated_css_directory_readonly','high',
                    'Elementor generated CSS directory is not writable',
                    'Inspect filesystem ownership, generated CSS delivery and regeneration; verify frontend CSS before closing.','css-output');
        }
        return $out;
    }
    public static function jetengine():array {
        $out=[];$boxes=get_option('jet_engine_meta_boxes',null);
        if($boxes!==null && !is_array($boxes))
            $out[]=self::signal('jet-engine','meta_box_definitions_invalid','high',
                'JetEngine Meta Box option is not a valid structured collection',
                'Inspect JetEngine Meta Boxes and repair the stored definition format from the current company schema.','meta-box');
        $modules=get_option('jet_engine_modules',null);
        if($modules!==null && !is_array($modules))
            $out[]=self::signal('jet-engine','module_configuration_invalid','high',
                'JetEngine module settings have an unexpected data type',
                'Review JetEngine modules and registered features; preserve company custom fields and relationships.','modules');
        return $out;
    }
    public static function wpml():array {
        $out=[];$settings=get_option('icl_sitepress_settings',null);
        if(!is_array($settings))return [self::signal('wpml','core_settings_invalid','high',
            'WPML core language settings are unavailable or malformed',
            'Inspect WPML Settings, its language tables, and native setup; do not infer translations.','languages')];
        $default=sanitize_key((string)($settings['default_language']??''));
        $languages=array_values(array_filter(array_map('sanitize_key',(array)($settings['active_languages']??[]))));
        if($default!=='' && !in_array($default,$languages,true))
            $out[]=self::signal('wpml','default_language_missing_from_active','high',
                'WPML default language is not present in the active language registry',
                'Verify source language and enabled languages; never rewrite translation links without WPML readback.','languages');
        return $out;
    }
    public static function litespeed():array {
        $out=[];
        // Native LiteSpeed API: return effective runtime config, not a guessed option name.
        if(class_exists('LiteSpeed\\Base')){
            $guest=apply_filters('litespeed_conf',\LiteSpeed\Base::O_GUEST);
            if($guest===1 || $guest==='1' || $guest===true)
                $out[]=self::signal('litespeed-cache','guest_mode_unexpected','medium',
                    'LiteSpeed Guest Mode enabled despite site-specific cache variation policy',
                    'Review LiteSpeed Guest Mode, Nginx language/device cache variation and guest requests before changing settings.','cache-guest');
        }
        return $out;
    }
    public static function rankmath():array {
        $out=[];$modules=(array)get_option('rank_math_modules',[]);
        if(in_array('analytics',$modules,true) &&
            (get_option('rank_math_console_connection_error',false)||get_option('rank_math_analytics_connection_error',false)))
            $out[]=self::signal('rank-math','connected_analytics_error','medium',
                'Rank Math Analytics or Search Console reports a connection error',
                'Inspect Rank Math Analytics > Status & Tools, scope and provider connection. Do not expose tokens or force OAuth.','analytics');
        if(get_option('rank_math_is_configured',null)==='0')
            $out[]=self::signal('rank-math','setup_incomplete','medium',
                'Rank Math setup wizard is marked incomplete',
                'Confirm whether setup is intentionally deferred before changing Search Appearance, sitemaps or site identity.','setup');
        return $out;
    }
    public static function fluentforms():array {
        $out=[];
        if(!self::table('fluentform_forms'))
            $out[]=self::signal('fluent-forms','form_definitions_table_missing','high',
                'Fluent Forms primary form definitions table is missing',
                'Verify Fluent Forms database migration and original form IDs before running any repair.','schema');
        return $out;
    }
    /** Provider evidence for *transport recovery*, not mailbox delivery.
     * Preserve failed Fluent SMTP logs; never claim retries or receipt by recipient.
     */
    public static function smtp_recovery_proof():array {
        $unverified=['contract'=>'mad4b.fluent-smtp-transport-recovery.v1',
            'recovered'=>false,'verified'=>false,'reason'=>'evidence_incomplete'];
        if(!self::enabled('fluent-smtp')||!defined('FLUENT_MAIL_DB_PREFIX'))return $unverified;
        $suffix=FLUENT_MAIL_DB_PREFIX.'email_logs';
        if(!self::table($suffix))return $unverified;
        global $wpdb;
        $table=$wpdb->prefix.$suffix;
        $query="SELECT status, COUNT(*) n, MAX(created_at) latest FROM `{$table}`
            WHERE created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY AND status IN ('failed','sent') GROUP BY status";
        $counts=$wpdb->get_results($query,ARRAY_A);
        if(!is_array($counts)||$wpdb->last_error)return $unverified;
        $events=[];
        foreach($counts as $x)$events[$x['status']]=['count'=>(int)$x['n'],'last'=>(string)$x['latest']];
        $fails=(int)($events['failed']['count']??0);
        $lastFailure=(string)($events['failed']['last']??'');
        if(!$fails||$lastFailure==='')return [
            'contract'=>'mad4b.fluent-smtp-transport-recovery.v1','recovered'=>false,
            'verified'=>true,'reason'=>'no_recent_failed_events'];
        $lastSuccess=(string)($events['sent']['last']??'');
        if($lastSuccess===''||strcmp($lastSuccess,$lastFailure)<=0)return $unverified+['reason'=>'no_newer_success'];
        $after=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE status='sent' AND created_at > %s",$lastFailure));
        if($wpdb->last_error||$after<2)return $unverified+['reason'=>'insufficient_success_after_failure'];
        $health=get_option('_fluentsmtp_connection_health',[]);
        if(!is_array($health)||!$health)return $unverified+['reason'=>'connection_health_unavailable'];
        foreach($health as $row){
            if(!is_array($row)||($row['status']??'')!=='healthy'
              ||strcmp((string)($row['checked_at']??''),$lastFailure)<=0)
                return $unverified+['reason'=>'transport_health_unverified'];
        }
        return ['contract'=>'mad4b.fluent-smtp-transport-recovery.v1',
           'verified'=>true,'recovered'=>true,'status'=>'accepted_after_prior_failures',
           'last_failed_utc'=>$lastFailure,'last_accepted_utc'=>$lastSuccess,
           'failed_records_preserved'=>$fails,'accepted_after_failure'=>$after,
           'healthy_connections'=>count($health),
           'does_not_prove_inbox_delivery'=>true];
    }
    public static function fluentsmtp():array {
        $out=[];
        $settings=get_option('fluentmail-settings',null);
        if($settings!==null && !is_array($settings))
            $out[]=self::signal('fluent-smtp','mail_transport_settings_invalid','high',
                'Fluent SMTP saved transport configuration is not structured correctly',
                'Review Fluent SMTP Connections and native wp_mail fallback; do not disclose credentials.','transport');
        if(defined('FLUENT_MAIL_DB_PREFIX')){
            $suffix=FLUENT_MAIL_DB_PREFIX.'email_logs';
            if(self::table($suffix)){
                global $wpdb;$table=$wpdb->prefix.$suffix;
                $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status='failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY");
                if($wpdb->last_error)throw new RuntimeException('mail_log_read_incomplete');
                $proof=self::smtp_recovery_proof();
                if($count>0 && empty($proof['recovered']))
                    $out[]=self::signal('fluent-smtp','email_failures_recent',$count>=10?'high':'medium',
                        'Fluent SMTP has '.$count.' failed transport attempts within seven days',
                        'Review current provider errors and separately verify inbox delivery; failed history remains immutable.','mail-logs');
            }
        }
        return $out;
    }

}
add_filter('mad4b/configuration_jobs/discover',['MAD4B_Configuration_Provider_Adapters','discover'],10,1);
