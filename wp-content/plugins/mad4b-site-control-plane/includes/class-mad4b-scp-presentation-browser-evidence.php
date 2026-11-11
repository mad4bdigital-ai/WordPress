<?php
/**
 * Browser evidence ingress for MAD4B Presentation Integrity.
 * Imports local, bounded Chromium audit results into an immutable site-scoped
 * option. Does not trigger browser work or perform network IO during WP GET.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Presentation_Browser_Evidence_V1 {
    public const CONTRACT = 'mad4b.presentation-browser-evidence.v1';
    public const OPTION = 'mad4b_presentation_browser_evidence_v1';
    public const SOURCE = 'mad4b-presentation-integrity';
    public const FRESH_SECONDS = 86400;

    private static function site_host(): string {
        return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
    }
    private static function site_languages(): array {
        $raw = (array)get_option('icl_sitepress_settings', []);
        $langs = (array)($raw['active_languages'] ?? []);
        if (!$langs) $langs=[substr(get_locale(),0,2)];
        $out=[];
        foreach ($langs as $lang) if (is_string($lang) && preg_match('/^[a-z]{2}$/D',$lang)) $out[]=$lang;
        $out=array_values(array_unique($out));sort($out,SORT_STRING);
        return $out;
    }
    private static function valid(array $d,bool $fresh=true):bool {
        if(($d['contract']??null)!==self::CONTRACT || empty($d['complete']) ||
           strtolower((string)($d['site_host']??''))!==self::site_host())return false;
        $date=strtotime((string)($d['generated_at']??''));
        if($date===false || $date>time()+300 || ($fresh&&time()-$date>self::FRESH_SECONDS))return false;
        $coverage=(array)($d['locale_coverage']??[]);sort($coverage,SORT_STRING);
        if($coverage!==self::site_languages())return false;
        $links=$d['links']??null;$contrast=$d['contrast']??null;
        if(!is_array($links)||!is_array($contrast)||empty($links['complete'])||empty($contrast['complete'])||
           !is_array($links['issues']??null)||!is_array($links['pages']??null)||
           !is_array($contrast['issues']??null)||count($links['issues'])>90||
           count($contrast['issues'])>65||count($links['pages'])>35||
           (int)($links['summary']['links_examined']??0)<10 ||
           (int)($contrast['sampled']??0)<5)return false;
        foreach($coverage as $lang){
            $found=false;
            foreach($links['pages'] as $p){
                if(is_array($p)&&($p['locale']??'')===$lang&&
                    ($p['actual_language']??'')===$lang&&($p['mode']??'')==='mobile'){
                    $found=true;break;
                }
            }
            if(!$found)return false;
        }
        foreach($links['issues'] as $issue){
            if(!is_array($issue)||!in_array((string)($issue['code']??''),[
                'language_path_escape','target_language_mismatch','broken_target',
                'non_navigable_card_anchor','link_pointer_disabled','click_target_obscured',
                'unexpected_external_redirect','page_language_mismatch'],true)||
                !in_array((string)($issue['language']??''),$coverage,true)||
                !is_array($issue['examples']??null)||count($issue['examples'])>3||
                (int)($issue['occurrences']??0)<1)return false;
        }
        foreach($contrast['issues'] as $issue){
            if(!is_array($issue)||!in_array((string)($issue['viewport']??''),['desktop','mobile'],true)||
                !isset($issue['worst_ratio_p20'],$issue['threshold'])||
                !is_numeric($issue['worst_ratio_p20'])||!is_numeric($issue['threshold'])||
                (float)$issue['threshold']<3||(float)$issue['threshold']>7||
                (float)$issue['worst_ratio_p20']<1||(float)$issue['worst_ratio_p20']>=((float)$issue['threshold'])||
                (float)$issue['worst_ratio_p20']>21||
                !is_array($issue['examples']??null)||count($issue['examples'])>3)return false;
        }
        return true;
    }
    public static function fresh():bool {
        return self::valid((array)get_option(self::OPTION,[]));
    }
    public static function summary():array {
        $data=(array)get_option(self::OPTION,[]);
        $good=self::valid($data);
        return ['contract'=>self::CONTRACT,'site_host'=>self::site_host(),'fresh'=>$good,
           'last_scan_at'=>(string)($data['generated_at']??''),
           'languages'=>$good?$data['locale_coverage']:[],
           'links_checked'=>$good?(int)($data['links']['summary']['links_examined']??0):0,
           'pixel_samples'=>$good?(int)($data['contrast']['sampled']??0):0,
           'link_groups'=>$good?count($data['links']['issues']):0,
           'untranslated_fallback_links'=>$good?max(0,(int)($data['links']['summary']['untranslated_fallback_links']??0)):0,
           'contrast_groups'=>$good?count($data['contrast']['issues']):0,
           'read_only'=>true,'frontend_network_requests'=>0];
    }
    private static function alert(string $code,string $severity,string $label,array $evidence):array {
        return ['id'=>hash('sha256',get_current_blog_id().'|site|0|'.$code),
            'post_type'=>'site','object_id'=>0,'code'=>$code,
            'source'=>self::SOURCE,'severity'=>$severity,
            'label'=>mb_substr($label,0,225),
            'source_evidence'=>$evidence,
            'recommendation'=>'Review native Elementor/JetEngine styling, destination translation and click target; do not auto-override.'];
    }
    public static function issues():array {
        $data=(array)get_option(self::OPTION,[]);
        if(!self::valid($data))return [];
        $output=[];$at=(string)$data['generated_at'];
        foreach($data['links']['issues'] as $item){
            $code_name=(string)$item['code'];$lang=(string)$item['language'];
            $widget=preg_replace('/[^a-z0-9_-]/i','',(string)($item['widget_id']??''));
            $code='pres_browser_link_'.substr(hash('sha256',$code_name.'|'.$lang.'|'.$widget),0,16);
            $count=(int)($item['occurrences']??1);
            $e=[];
            foreach($item['examples'] as $example){
                if(!is_array($example))continue;
                $e[]=array_map(static fn($v)=>mb_substr((string)$v,0,315),
                    array_intersect_key($example,array_flip(['label','href','page_path','post_id','expected_language','target_language','status'])));
            }
            $output[]=self::alert($code,in_array($code_name,['broken_target','click_target_obscured','target_language_mismatch'],true)?'high':'medium',
                "Card navigation {$lang}: {$code_name}, {$count} observed; Elementor widget {$widget}.",
                ['report_at'=>$at,'category'=>'localized_card_link','language'=>$lang,'widget_id'=>$widget,
                 'finding'=>$code_name,'occurrences'=>$count,'examples'=>$e,
                 'confidence'=>'browser_observed_sample']);
        }
        foreach($data['contrast']['issues'] as $item){
            $widget=preg_replace('/[^a-z0-9_-]/i','',(string)($item['widget_id']??''));
            $page=mb_substr((string)($item['page']??'/'),0,180);
            $viewport=(string)$item['viewport'];$ratio=(float)$item['worst_ratio_p20'];
            $target=(float)$item['threshold'];
            $code='pres_browser_contrast_'.substr(hash('sha256',$viewport.'|'.$page.'|'.$widget),0,16);
            $output[]=self::alert($code,$ratio<3?'high':'medium',
                'Card text contrast candidate '.number_format($ratio,2).':1 vs target '.number_format($target,1).':1 (' .
                $viewport.', widget '.$widget.', '.mb_substr($page,0,60).').',
                ['report_at'=>$at,'category'=>'rendered_image_text_contrast','page'=>$page,
                 'viewport'=>$viewport,'widget_id'=>$widget,'ratio_p20'=>$ratio,
                 'threshold'=>$target,'sampled'=>15,'instances'=>(int)($item['occurrences']??1),
                 'example_text'=>mb_substr((string)($item['worst_text']??''),0,92),
                 'confidence'=>'sampled_pixels_not_full_accessibility_certification']);
        }
        return array_slice($output,0,160);
    }
    public static function cli_import(array $args=[],array $assoc=[]):void {
        $base=WP_CONTENT_DIR.'/mad4b-private/presentation-audits';
        $baseReal=realpath($base);
        $file=isset($args[0])?realpath((string)$args[0]):false;
        if(!$baseReal||!$file||!str_starts_with($file,$baseReal.DIRECTORY_SEPARATOR)||
            is_link($file)||!is_file($file)||filesize($file)>256000||filesize($file)<100)
            WP_CLI::error('Invalid local presentation evidence path or file size');
        $raw=file_get_contents($file);$data=json_decode((string)$raw,true);
        if(!is_array($data)||!self::valid($data))WP_CLI::error('Presentation evidence contract/site/coverage/freshness invalid');
        $existing=(array)get_option(self::OPTION,[]);
        if(strtotime((string)($existing['generated_at']??''))>strtotime($data['generated_at']))
            WP_CLI::error('Refusing to replace newer browser evidence with older audit');
        if(!update_option(self::OPTION,$data,false) && get_option(self::OPTION)!==$data)
            WP_CLI::error('Presentation evidence option update failed');
        if((array)get_option(self::OPTION,[])!==$data)WP_CLI::error('Presentation evidence readback mismatch');
        WP_CLI::line(wp_json_encode(self::summary(),JSON_UNESCAPED_SLASHES));
    }
    public static function cli_status(array $args=[],array $assoc=[]):void {
        WP_CLI::line(wp_json_encode(self::summary(),JSON_UNESCAPED_SLASHES));
    }
}
if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI',false)){
 WP_CLI::add_command('mad4b presentation-browser-import',['MAD4B_SCP_Presentation_Browser_Evidence_V1','cli_import']);
 WP_CLI::add_command('mad4b presentation-browser-status',['MAD4B_SCP_Presentation_Browser_Evidence_V1','cli_status']);
}
