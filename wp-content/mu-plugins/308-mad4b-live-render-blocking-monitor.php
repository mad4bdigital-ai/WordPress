<?php
/**
 * Plugin Name: MAD4B Live CSS Render-Blocking Regression Monitor
 * Description: Signed-site-bound mobile browser evidence is reconciled into Content Readiness alerts.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Live_Render_Blocking_Monitor_V1 {
    const STORE='mad4b_live_render_blocking_v1';
    const SOURCE='mad4b-live-performance';
    const CONTRACT='mad4b.live-render-blocking.v1';
    private static function site(){
        if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
        $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($profile)&&($profile['site_uuid']??'')==='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    }
    private static function issue($code,$severity,$label){
        return [
            'id'=>hash('sha256',get_current_blog_id().'|site|0|'.$code),
            'post_type'=>'site','object_id'=>0,'code'=>$code,'severity'=>$severity,
            'label'=>mb_substr((string)$label,0,225),'source'=>self::SOURCE
        ];
    }
    public static function report(){
        $r=get_option(self::STORE,[]);
        $last=strtotime((string)($r['observed_at']??''))?:0;
        return ['contract'=>self::CONTRACT,'complete'=>!empty($r['complete']) && ($last>0),
            'fresh'=>$last>0 && (time()-$last)<5400,'observed_at'=>$r['observed_at']??null,
            'scanned_pages'=>count($r['pages']??[]),'issues'=>$r['issues']??[],
            'site_uuid'=>$r['site_uuid']??null];
    }
    public static function external($input,$config){
        if(!is_array($input)||!self::site())return $input;
        $r=self::report();
        if(!$r['fresh']||!$r['complete']){
            $input[]=self::issue('perf_probe_stale','high','Mobile CSS live browser audit is stale or incomplete; performance observability is not verified.');
            return $input;
        }
        foreach($r['issues'] as $row){
            $code=(string)($row['code']??'');
            if(!preg_match('/^perf_(?:rbcss|lcpdelay)_[a-f0-9]{12}$|^perf_ttfb_site$/D',$code))continue;
            $input[]=self::issue($code,(string)($row['severity']??'high'),(string)($row['label']??'Render-blocking CSS detected.'));
        }
        return $input;
    }
    public static function import($args,$opt){
        if(!self::site())WP_CLI::error('wrong_site');
        if(($args[0]??'')!=='import'||($opt['confirm']??'')!=='IMPORT LIVE CSS EVIDENCE')
            WP_CLI::error('Expected import --confirm="IMPORT LIVE CSS EVIDENCE"');
        $path=(string)($opt['file']??'');
        if($path!=='/tmp/mad4b-live-css-scan.json')WP_CLI::error('unexpected_file_path');
        $raw=@file_get_contents($path);
        $j=json_decode((string)$raw,true);
        if(!is_array($j)||($j['contract']??'')!==self::CONTRACT ||
            ($j['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a' ||
            ($j['origin']??'')!=='https://tours.dev.mad4b.com' ||
            empty($j['complete']) || count((array)($j['pages']??[]))<7 ||
            count((array)($j['pages']??[]))>14 ||
            count((array)($j['issues']??[]))>30) WP_CLI::error('invalid_live_probe');
        $ts=strtotime((string)($j['observed_at']??''))?:0;
        if($ts<time()-1200||$ts>time()+60)WP_CLI::error('stale_or_future_probe');
        $seen=[];
        foreach($j['pages'] as $p){
            if(!is_array($p)||!str_starts_with((string)($p['url']??''),'https://tours.dev.mad4b.com/') ||
                (int)($p['http_status']??0)!==200)WP_CLI::error('incomplete_or_offsite_page');
        }
        foreach($j['issues'] as $issue){
            $code=(string)($issue['code']??'');
            if(!preg_match('/^perf_(?:rbcss|lcpdelay)_[a-f0-9]{12}$|^perf_ttfb_site$/D',$code) ||
                isset($seen[$code]) || !in_array($issue['severity']??'', ['critical','high','medium'],true) ||
                strlen((string)($issue['label']??''))>225)WP_CLI::error('invalid_css_issue');
            $seen[$code]=true;
        }
        update_option(self::STORE,$j,false);
        if(get_option(self::STORE,[])!==$j)WP_CLI::error('scan_write_readback_failed');
        WP_CLI::line(wp_json_encode(['stored'=>true,'pages'=>count($j['pages']),
            'active_css_issues'=>count($j['issues']),'observed_at'=>$j['observed_at'],
            'live_scan_complete'=>true],JSON_PRETTY_PRINT));
    }
    public static function status($args,$opts){
        WP_CLI::line(wp_json_encode(self::report(),JSON_PRETTY_PRINT));
    }
    public static function admin_notice(){
        if(!current_user_can('edit_others_posts'))return;
        $r=self::report();$count=count($r['issues']);
        if(!$r['fresh']){
            echo '<div class="notice notice-warning"><p><strong>MAD4B Live Performance:</strong> Browser CSS regression evidence is stale; last scan must be restored.</p></div>';
        }elseif($count){
            echo '<div class="notice notice-warning"><p><strong>MAD4B Live Performance:</strong> '.
                esc_html((string)$count).' mobile CSS render-blocking issue(s). '.
                '<a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).'">View Content Readiness</a></p></div>';
        }
    }
    public static function ability(){
        if(!function_exists('wp_register_ability') || !self::site())return;
        if(function_exists('wp_has_ability') && wp_has_ability('mad4b/live-css-regressions'))return;
        wp_register_ability('mad4b/live-css-regressions',[
            'label'=>'Live CSS Render-Blocking Audit',
            'description'=>'Read-only current evidence from Staging mobile CSS browser regression checks.',
            'category'=>'mad4b-read',
            'execute_callback'=>static fn($input)=>self::report(),
            'permission_callback'=>static fn($input)=>current_user_can('edit_others_posts'),
            'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
        ]);
    }
    public static function boot(){
        if(!self::site())return;
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],80,2);
        add_action('admin_notices',[__CLASS__,'admin_notice']);
        add_action('wp_abilities_api_init',[__CLASS__,'ability'],40);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')){
            WP_CLI::add_command('mad4b perf monitor',[__CLASS__,'import']);
            WP_CLI::add_command('mad4b perf status',[__CLASS__,'status']);
        }
    }
}
add_action('plugins_loaded',['MAD4B_Live_Render_Blocking_Monitor_V1','boot'],70);
