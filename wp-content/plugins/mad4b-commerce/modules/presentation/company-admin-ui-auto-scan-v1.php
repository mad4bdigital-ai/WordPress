<?php
/**
 * MAD4B Admin UI Auto Scan — private, same-origin, metadata-only receipts.
 * No content, passwords, cookies, names, full URLs or editor source collected.
 * No frontend requests and no account/order mutations.
 */
defined('ABSPATH') || exit;
final class MAD4B_Admin_UI_Auto_Scan_V1 {
    public const CONTRACT='mad4b.admin-ui-auto-scan.v1';
    private const OPTION='mad4b_admin_ui_auto_scan_v1';
    private const ALLOWED_FAMILIES=['wordpress','woocommerce','fluent','elementor','wpml',
        'rank-math','mad4b','third-party','unknown'];
    private const ALLOWED_ISSUES=['text_contrast','surface_contrast','horizontal_overflow',
        'clipped_text','small_target','overlay_overlap','unscanned_opaque_frame'];
    public static function install():void {
        add_action('wp_ajax_mad4b_admin_ui_auto_scan_v1',[__CLASS__,'receive']);
    }
    public static function receive():void {
        if(!current_user_can('manage_options'))wp_send_json_error(['code'=>'not_authorized'],403);
        check_ajax_referer('mad4b_admin_ui_auto_scan_v1','nonce');
        if(!isset($_POST['payload'])||!is_string($_POST['payload'])||strlen($_POST['payload'])>12000)
            wp_send_json_error(['code'=>'payload_invalid'],400);
        $data=json_decode(wp_unslash($_POST['payload']),true,24);
        if(!is_array($data)||($data['contract']??'')!==self::CONTRACT)
            wp_send_json_error(['code'=>'contract_invalid'],400);
        $route=(string)($data['route']??'');
        if(!preg_match('/^[a-zA-Z0-9_\/?.=&-]{1,150}$/D',$route)||str_contains($route,'..')||str_contains($route,'nonce'))
            wp_send_json_error(['code'=>'route_invalid'],400);
        $mode=(string)($data['mode']??'');
        if(!in_array($mode,['dark','light'],true))wp_send_json_error(['code'=>'mode_invalid'],400);
        $width=(int)($data['viewport']??0);
        if($width<200||$width>9000)wp_send_json_error(['code'=>'viewport_invalid'],400);
        $metrics=[];
        foreach(['examined','auto_text_fixed','auto_surfaces_fixed','remaining_issues',
                  'horizontal_overflow_px','uninspectable_frames','scan_duration_ms'] as $key){
            $num=$data['metrics'][$key]??null;
            if(!is_numeric($num)||$num<0||$num>250000)$num=0;
            $metrics[$key]=min(250000,(int)$num);
        }
        $families=[];
        foreach((array)($data['families']??[]) as $key=>$value){
            if(!in_array((string)$key,self::ALLOWED_FAMILIES,true))continue;
            $families[$key]=min(10000,max(0,(int)$value));
        }
        $issues=[];
        foreach(array_slice((array)($data['issues']??[]),0,12) as $issue){
            if(!is_array($issue))continue;
            $type=(string)($issue['type']??'');
            $family=(string)($issue['family']??'unknown');
            if(!in_array($type,self::ALLOWED_ISSUES,true))continue;
            if(!in_array($family,self::ALLOWED_FAMILIES,true))$family='unknown';
            // Use stable semantic DOM region IDs only, never user-entered text.
            $region=(string)($issue['region']??'');
            if(!preg_match('/^[a-zA-Z0-9_-]{1,70}$/D',$region))$region='unknown';
            $issues[]=['type'=>$type,'family'=>$family,'region'=>$region,
                'count'=>min(10000,max(1,(int)($issue['count']??1)))];
        }
        $now=time();
        $existing=get_option(self::OPTION,[]);
        if(!is_array($existing))$existing=[];
        foreach($existing as $key=>$entry){
            if(!is_array($entry)||($entry['scanned_epoch']??0)<$now-7*DAY_IN_SECONDS)unset($existing[$key]);
        }
        $key=$route.'|'.$mode.'|'.($width<=782?'mobile':'desktop');
        $existing[$key]=['contract'=>self::CONTRACT,'route'=>$route,'mode'=>$mode,
           'viewport'=>$width,'scanned_epoch'=>$now,'metrics'=>$metrics,
           'families'=>$families,'issues'=>$issues];
        if(count($existing)>60){
            uasort($existing,static fn($a,$b)=>($a['scanned_epoch']??0)<=>($b['scanned_epoch']??0));
            $existing=array_slice($existing,-60,null,true);
        }
        update_option(self::OPTION,$existing,false);
        wp_send_json_success(['stored'=>true,'screens'=>count($existing),'route'=>$route]);
    }
    public static function summary():array {
        $rows=get_option(self::OPTION,[]);
        if(!is_array($rows))$rows=[];
        $fresh=[]; $now=time();
        foreach($rows as $row){
            if(is_array($row)&&($row['scanned_epoch']??0)>=$now-7*DAY_IN_SECONDS)$fresh[]=$row;
        }
        $family=[]; $issues=[];
        foreach($fresh as $row){
            foreach((array)($row['families']??[]) as $k=>$n)$family[$k]=($family[$k]??0)+(int)$n;
            foreach((array)($row['issues']??[]) as $v){
                if(!is_array($v))continue;
                $k=(string)($v['type']??'unknown');
                $issues[$k]=($issues[$k]??0)+(int)($v['count']??1);
            }
        }
        return ['contract'=>self::CONTRACT,'state'=>'READ_ONLY_SCAN_REPORT',
                'pages_scanned'=>count($fresh),'families'=>$family,'issues'=>$issues,
                'records'=>$fresh,'source'=>'authenticated_visited_admin_pages_only',
                'automatic_unvisited_page_crawl'=>false];
    }
}
MAD4B_Admin_UI_Auto_Scan_V1::install();
