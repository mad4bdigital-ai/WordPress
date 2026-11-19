<?php
/**
 * Browser-observed public locale experience gaps, read-only first-party provider.
 *
 * The observation is evidence, not an instruction to publish, modify WPML,
 * infer translations, launch an external scraper or bypass ContentJobs.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Locale_Experience_Alerts {
    const VERSION = '1.0.0';
    const OPTION = 'mad4b_locale_browser_observations_v1';
    const MAX_AGE = 21600;
    const CODES = array('asset_http_error','cross_locale_link','visible_term_parity','filter_visibility_drift','untranslated_ui_candidate');

    private static function site() {
        return class_exists('MAD4B_SCP_Site_Profile')
            ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
    }
    private static function languages() {
        if (!has_filter('wpml_active_languages')) return array();
        $active=apply_filters('wpml_active_languages',null,array('skip_missing'=>0));
        if (!is_array($active)) return array();
        $result=array();
        foreach($active as $key=>$entry) {
            $lang=sanitize_key((string)($entry['language_code']??$key));
            if(preg_match('/^[a-z]{2}$/D',$lang))$result[$lang]=true;
        }
        return array_keys($result);
    }
    private static function local_url($url) {
        if(!is_string($url)||strlen($url)>1200||!str_starts_with($url,'https://'))return false;
        $site=wp_parse_url(home_url('/'));
        $candidate=wp_parse_url($url);
        return is_array($site)&&is_array($candidate)&&
            strtolower((string)($site['host']??''))===strtolower((string)($candidate['host']??''))&&
            ($candidate['scheme']??'')==='https'&&
            !isset($candidate['user'])&&!isset($candidate['pass'])&&
            !isset($candidate['port']);
    }
    private static function normalized($snapshot) {
        if(!is_array($snapshot)||($snapshot['contract']??'')!=='mad4b.locale-browser-observation.v1'||
           ($snapshot['site_uuid']??'')!==self::site() || !self::site())return null;
        $at=strtotime((string)($snapshot['captured_at']??''));
        if(!$at||$at>time()+300||$at<time()-self::MAX_AGE)return null;
        $default=(string)apply_filters('wpml_default_language',null);
        $active=self::languages();
        if(!$active || !in_array($default,$active,true) || count($active)>16)return null;
        $surfaces=(array)($snapshot['surfaces']??array());
        if(!$surfaces||count($surfaces)>4||
           array_values(array_unique($surfaces))!==$surfaces||
           array_diff($surfaces,array('home','archive')))return null;
        $screens=(array)($snapshot['screens']??array());
        if(count($screens)>128 || count($screens)!==count($active)*2*count($surfaces))
            return null;
        $observed=array();
        foreach($surfaces as $surface)foreach($active as $lang) {
            foreach(array('desktop','mobile') as $device) {
                $key=$surface.'|'.$lang.'|'.$device;
                $screen=$screens[$key]??null;
                if(!is_array($screen)||($screen['language']??'')!==$lang||
                   ($screen['device']??'')!==$device||
                   (int)($screen['status']??0)!==200||
                   !self::local_url($screen['url']??null) ||
                   !is_array($screen['sliders']??null) ||
                   !is_array($screen['cross_locale_urls']??null)||
                   !is_array($screen['asset_errors']??null)||
                   !is_array($screen['suspect_labels']??null)||
                   !is_array($screen['visible_filters']??null))return null;
                $sliders=array();
                foreach(array_slice($screen['sliders'],0,8) as $n){
                    if(!is_int($n)||$n<0||$n>2000)return null;
                    $sliders[]=$n;
                }
                $urls=array();$assets=array();$labels=array();
                foreach(array_slice($screen['cross_locale_urls'],0,12) as $url) {
                    if(!self::local_url($url))return null;
                    $urls[]=$url;
                }
                foreach(array_slice($screen['asset_errors'],0,12) as $x) {
                    if(!is_array($x)||!in_array((int)($x['status']??0),array(404,410),true)||
                       !self::local_url($x['url']??null))return null;
                    $assets[]=array('status'=>(int)$x['status'],'url'=>(string)$x['url']);
                }
                foreach(array_slice($screen['suspect_labels'],0,12) as $label) {
                    if(!is_string($label)||mb_strlen($label)>100||trim($label)==='')return null;
                    $labels[]=sanitize_text_field($label);
                }
                $filters=array();
                foreach(array_slice($screen['visible_filters'],0,16) as $filter) {
                    if(!is_string($filter)||!preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$filter))return null;
                    $filters[$filter]=true;
                }
                $observed[$key]=array(
                    'url'=>$screen['url'],'sliders'=>$sliders,
                    'cross_locale_urls'=>array_values(array_unique($urls)),
                    'asset_errors'=>$assets,'suspect_labels'=>array_values(array_unique($labels)),
                    'visible_filters'=>array_keys($filters)
                );
            }
        }
        return array('contract'=>self::VERSION,'site_uuid'=>self::site(),
            'captured_at'=>gmdate('c',$at),'source_language'=>$default,
            'languages'=>$active,'surfaces'=>$surfaces,'screens'=>$observed);
    }
    public static function import_observations($input) {
        if(!current_user_can('manage_options')||
           !class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate())
            return new WP_Error('locale_browser_observation_authority_required');
        $data=self::normalized($input);
        if(!$data)return new WP_Error('locale_browser_observation_invalid_or_stale');
        $data['sha256']=hash('sha256',wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(!update_option(self::OPTION,$data,false))
            return new WP_Error('locale_browser_observation_not_updated');
        $saved=get_option(self::OPTION,null);
        if($saved!==$data)return new WP_Error('locale_browser_observation_readback_failed');
        return array('contract'=>'mad4b.locale-browser-observation-import.v1',
                     'site_uuid'=>self::site(),'sha256'=>$data['sha256'],
                     'screens'=>count($data['screens']),'readback_verified'=>true);
    }
    public static function current() {
        $stored=get_option(self::OPTION,null);
        if(!is_array($stored)||!preg_match('/^[a-f0-9]{64}$/D',(string)($stored['sha256']??'')))return null;
        $sha=$stored['sha256'];unset($stored['sha256']);
        if(!hash_equals($sha,hash('sha256',wp_json_encode($stored,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))))
            return null;
        // Normalized format is immutable and never treated as arbitrary new commands.
        if(($stored['contract']??'')!==self::VERSION||
           ($stored['site_uuid']??'')!==self::site() ||
           strtotime((string)($stored['captured_at']??''))<time()-self::MAX_AGE)return null;
        $stored['sha256']=$sha;
        return $stored;
    }
    private static function issue($lang,$kind,$severity,$priority,$label,$evidence) {
        $code='lexp_'.$kind.'_'.$lang;
        $id=hash('sha256',get_current_blog_id().'|site|0|'.$code);
        return array('id'=>$id,'post_type'=>'site','object_id'=>0,'code'=>$code,
            'severity'=>$severity,'priority'=>$priority,'label'=>$label,
            'source'=>'mad4b-locale-experience','source_evidence'=>$evidence);
    }
    public static function discover() {
        $snapshot=self::current();
        if(!$snapshot)return array('complete'=>true,'fresh'=>false,'issues'=>array());
        $screens=$snapshot['screens'];$source=$snapshot['source_language'];
        $issues=array();
        foreach($snapshot['languages'] as $lang) {
            $findings=array();
            foreach($snapshot['surfaces'] as $surface)
            foreach(array('desktop','mobile') as $device) {
                $cur=$screens[$surface.'|'.$lang.'|'.$device]??null;
                $base=$screens[$surface.'|'.$source.'|'.$device]??null;
                if(!$cur||!$base)continue;
                $common=array('language'=>$lang,'surface'=>$surface,'device'=>$device,'page_url'=>$cur['url'],
                    'snapshot_sha256'=>$snapshot['sha256'],
                    'observed_at'=>$snapshot['captured_at']);
                if($cur['asset_errors']){
                    $e=$common;$e['assets']=array_slice($cur['asset_errors'],0,5);
                    $findings['asset_http_error'][]=$e;
                }
                if($cur['cross_locale_urls']){
                    $e=$common;$e['links']=array_slice($cur['cross_locale_urls'],0,5);
                    $findings['cross_locale_link'][]=$e;
                }
                if($cur['suspect_labels']){
                    $e=$common;$e['labels']=array_slice($cur['suspect_labels'],0,5);
                    $findings['untranslated_ui_candidate'][]=$e;
                }
                // Counts are an investigation candidate, NOT a claim of missing
                // WPML translations. Owner must verify is_public/is_featured
                // and archive query eligibility before remediation.
                $a=(int)($base['sliders'][0]??0);$b=(int)($cur['sliders'][0]??0);
                if($lang!==$source && $surface==='home' && $a>=3 && $b<$a){
                    $e=$common;$e['source_visible_count']=$a;$e['locale_visible_count']=$b;
                    $e['diagnosis_required']='WPML term mapping, is_public, is_featured and actual native query';
                    $findings['visible_term_parity'][]=$e;
                }
                $missing=array_values(array_diff($base['visible_filters'],$cur['visible_filters']));
                $extra=array_values(array_diff($cur['visible_filters'],$base['visible_filters']));
                if($lang!==$source && $surface==='archive' && ($missing||$extra)){
                    $e=$common;$e['missing_filter_families']=array_slice($missing,0,12);
                    $e['extra_filter_families']=array_slice($extra,0,12);
                    $findings['filter_visibility_drift'][]=$e;
                }
            }
            $policy=array(
              'asset_http_error'=>array('high',94,'Published localized page requests missing 404/410 media'),
              'cross_locale_link'=>array('high',91,'Published listing links navigate into another language'),
              'filter_visibility_drift'=>array('high',88,'Visible native archive filters differ from default language'),
              'untranslated_ui_candidate'=>array('medium',82,'Visible interface label needs locale-owner translation review'),
              'visible_term_parity'=>array('medium',78,'Published destination slider has fewer visible terms than source; eligibility unverified')
            );
            foreach($findings as $kind=>$proofs) {
                $meta=$policy[$kind];
                $issues[]=self::issue($lang,$kind,$meta[0],$meta[1],
                    $meta[2].' ('.$lang.')',array('observations'=>$proofs,
                        'remediation_auto_authorized'=>false,
                        'source'=>'browser_observation_not_wpml_approval'));
            }
        }
        return array('complete'=>true,'fresh'=>true,'issues'=>$issues,
                     'evidence_sha256'=>$snapshot['sha256']);
    }
}
