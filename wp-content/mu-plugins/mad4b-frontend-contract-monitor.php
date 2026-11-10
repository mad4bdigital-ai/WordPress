<?php
/**
 * Plugin Name: MAD4B Frontend Contract Monitor
 * Description: Read-only verified browser QA results in Configuration Jobs; no frontend processing.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Frontend_Contract_Monitor_V1 {
    const OPTION='mad4b_frontend_contract_monitor_v1';
    const CONTRACT='mad4b.frontend-contract-evidence.v1';
    const MAX_AGE=3*HOUR_IN_SECONDS;
    const CHECKS=['wpml_active_language','wpml_translation_navigation','jetengine_nested_tab_state','jetengine_ajax_response','navigation_menu_language_order'];
    public static function signals(array $rows):array {
        $state=get_option(self::OPTION,null);
        if(!is_array($state) || ($state['contract']??'')!==self::CONTRACT){
            if(get_option('mad4b_frontend_contract_enabled_v1',false)) {
                $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
                $rows[]=['provider'=>'frontend-contract','code'=>'browser_probe_not_enrolled','scope'=>'site','source'=>'probe','severity'=>'high',
                  'label'=>'Required frontend monitoring has no verified evidence',
                  'guidance'=>'Check site enrollment, systemd browser probe service and signed company identity.'];
            }
            return $rows;
        }
        $time=(int)($state['checked_epoch']??0);
        if($time<=0 || $time>time()+120 || time()-$time>self::MAX_AGE){
            $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
            $rows[]=['provider'=>'frontend-contract','code'=>'browser_probe_stale','scope'=>'site','source'=>'probe','severity'=>'high',
                'label'=>'Frontend browser health evidence expired',
                'guidance'=>'Investigate the scheduled browser monitor, WP runtime and collector; do not automatically resolve existing incidents.'];
            return $rows;
        }
        foreach(self::CHECKS as $check){
            $result=$state['checks'][$check]??null;
            if($result==='pass')continue;
            if($result!=='fail'){
                $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
                $rows[]=['provider'=>'frontend-contract','code'=>'browser_probe_incomplete','scope'=>$check,'source'=>'probe','severity'=>'high',
                    'label'=>'Frontend contract observation was incomplete',
                    'guidance'=>'Inspect Playwright network/DOM evidence and rerun after dependencies recover; do not mark missing checks as passed.'];
                continue;
            }
            $guidance=[
                'wpml_active_language'=>'Match document lang, switcher label and WPML aria-current for every tested language. Inspect cached HTML and selector precedence.',
                'wpml_translation_navigation'=>'Click the original WPML alternate-language link and verify the translated destination, never only a displayed flag.',
                'jetengine_nested_tab_state'=>'Open a nested Elementor destination tab and verify it remains selected after signed JetEngine AJAX completion.',
                'jetengine_ajax_response'=>'Verify JetEngine request returns 200 and usable HTML. Inspect Nginx client_body_temp_path, permissions, PHP and admission limits.',
                'navigation_menu_language_order'=>'Compare exact top-level Elementor header link order in each WPML language. Check translated nav-menu menu_order and selectively invalidate _elementor_element_cache for native navigation headers.',
            ];
            $rows[]=['provider'=>'frontend-contract','code'=>$check,'scope'=>'public-mobile','source'=>'probe','severity'=>'high',
                'label'=>'Frontend regression detected: '.str_replace('_',' ',$check),
                'guidance'=>$guidance[$check]];
        }
        return $rows;
    }
    public static function import(string $input):array|WP_Error {
        if(strlen($input)>8192)return new WP_Error('frontend_contract_evidence_too_large');
        $data=json_decode($input,true);
        if(!is_array($data)||($data['contract']??'')!==self::CONTRACT)return new WP_Error('frontend_contract_invalid');
        $profile=(array)get_option('mad4b_scp_site_profile_v2',[]);
        if(!hash_equals(strtolower((string)($profile['site_uuid']??'')),strtolower((string)($data['site_uuid']??''))))return new WP_Error('frontend_contract_site_mismatch');
        $epoch=$data['checked_epoch']??null;
        if(!is_int($epoch)||abs(time()-$epoch)>600)return new WP_Error('frontend_contract_time_invalid');
        if(!isset($data['checks'])||!is_array($data['checks']) || count($data['checks'])!==count(self::CHECKS))return new WP_Error('frontend_contract_missing_checks');
        foreach(self::CHECKS as $check)if(!array_key_exists($check,$data['checks'])||!in_array($data['checks'][$check],['pass','fail','incomplete'],true))return new WP_Error('frontend_contract_invalid_result');
        if(array_diff(array_keys($data['checks']),self::CHECKS))return new WP_Error('frontend_contract_unknown_check');
        $prev=get_option(self::OPTION,[]);
        if(is_array($prev) && (int)($prev['checked_epoch']??0)>$epoch)return new WP_Error('frontend_contract_stale_write');
        $save=['contract'=>self::CONTRACT,'site_uuid'=>strtolower((string)$data['site_uuid']),
            'checked_epoch'=>$epoch,'checks'=>$data['checks'],
            'evidence_sha256'=>preg_match('/^[a-f0-9]{64}$/D',(string)($data['evidence_sha256']??''))?$data['evidence_sha256']:''];
        update_option(self::OPTION,$save,false);
        return ['status'=>'stored','checks'=>$save['checks'],'sha256'=>$save['evidence_sha256']];
    }
}
add_filter('mad4b/configuration_jobs/discover',[MAD4B_Frontend_Contract_Monitor_V1::class,'signals'],20,1);
if(defined('WP_CLI')&&WP_CLI){
    WP_CLI::add_command('mad4b frontend-contract ingest',static function(){
        $data=stream_get_contents(STDIN,8193);
        $result=MAD4B_Frontend_Contract_Monitor_V1::import($data);
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        echo wp_json_encode($result).'\n';
    });
}
