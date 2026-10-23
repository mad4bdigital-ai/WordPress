<?php
if (!defined('ABSPATH')) exit;

/**
 * Custom GA4 reports with safe, bounded field selections.
 * Reports are executed with the user's consented GA4 read grant; report
 * definitions live in WordPress, not in GA4's UI report collections.
 */
final class MAD4B_Google_Measurement_Reports {
    const CONTRACT='mad4b.google-measurement.custom-reports.v1';
    const API='https://analyticsdata.googleapis.com/v1beta/';
    const OPTIONS='mad4b_google_measurement_reports_v1';
    const DIMENSIONS=array('date','eventName','pagePath','pageTitle','sessionSourceMedium',
        'sessionCampaignName','deviceCategory','country','city','landingPage',
        'newVsReturning','firstUserSourceMedium','itemId','itemName','itemCategory','itemListName');
    const METRICS=array('eventCount','totalUsers','activeUsers','sessions','engagedSessions',
        'engagementRate','averageSessionDuration','userEngagementDuration','screenPageViews',
        'keyEvents','sessionKeyEventRate','ecommercePurchases','purchaseRevenue',
        'itemsViewed','itemsClickedInList','itemsViewedInList');
    public static function presets() {
        return array(
            'tour_funnel'=>array('dimensions'=>array('eventName'),
                'metrics'=>array('eventCount','totalUsers'),'days'=>28,
                'description'=>'Travel funnel events and volumes'),
            'acquisition'=>array('dimensions'=>array('sessionSourceMedium'),
                'metrics'=>array('sessions','totalUsers','keyEvents'),'days'=>28,
                'description'=>'Acquisition traffic and key events'),
            'tour_products'=>array('dimensions'=>array('itemId','itemName'),
                'metrics'=>array('itemsViewed','itemsClickedInList'),'days'=>28,
                'description'=>'Product views and catalogue selections'),
            'returning_visitors'=>array('dimensions'=>array('newVsReturning','deviceCategory'),
                'metrics'=>array('totalUsers','engagedSessions','userEngagementDuration'),'days'=>28,
                'description'=>'New/returning and engagement'),
        );
    }
    public static function normalize($input) {
        $preset=isset($input['preset'])?(string)$input['preset']:'';
        $defaults=self::presets();
        $row=isset($defaults[$preset])?$defaults[$preset]:array();
        $d=isset($input['dimensions'])?$input['dimensions']:($row['dimensions']??array('eventName'));
        $m=isset($input['metrics'])?$input['metrics']:($row['metrics']??array('eventCount'));
        $days=isset($input['days'])?(int)$input['days']:(int)($row['days']??28);
        $limit=isset($input['limit'])?(int)$input['limit']:100;
        if (!is_array($d)||!is_array($m)||count($d)<1||count($d)>5||
            count($m)<1||count($m)>5||$days<1||$days>365||
            $limit<1||$limit>500||count(array_unique($d))!==count($d)||
            count(array_unique($m))!==count($m))
            return new WP_Error('mad4b_measurement_invalid_report','Invalid report dimensions/metrics or limits.');
        foreach ($d as $name) if (!is_string($name)||!in_array($name,self::DIMENSIONS,true))
            return new WP_Error('mad4b_measurement_unsupported_dimension','Unsupported report dimension.');
        foreach ($m as $name) if (!is_string($name)||!in_array($name,self::METRICS,true))
            return new WP_Error('mad4b_measurement_unsupported_metric','Unsupported report metric.');
        $event=isset($input['event_name'])?(string)$input['event_name']:'';
        if ($event!==''&&!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$event))
            return new WP_Error('mad4b_measurement_invalid_event_filter','Only exact event names are allowed.');
        return array('dimensions'=>array_values($d),'metrics'=>array_values($m),
            'days'=>$days,'limit'=>$limit,'event_name'=>$event,'preset'=>$preset);
    }
    private static function gate() {
        if (!MAD4B_Google_Measurement_Bridge::gate()) return false;
        $b=MAD4B_Google_Measurement_Admin::binding();
        return !empty($b['ready'])?$b:false;
    }
    public static function catalog($input=array()) {
        if (!self::gate()) return new WP_Error('mad4b_measurement_not_authorized','Exact site binding required.');
        return array('contract'=>self::CONTRACT,'presets'=>self::presets(),
            'dimensions'=>self::DIMENSIONS,'metrics'=>self::METRICS,
            'saved_definitions'=>self::saved(),'remote_ga4_reports_modified'=>false);
    }
    public static function saved() {
        $b=self::gate();
        if (!$b) return array();
        $items=get_option(self::OPTIONS,array());
        $key=hash('sha256',$b['site_uuid'].':'.$b['ga4_property']);
        return is_array($items)&&isset($items[$key])&&is_array($items[$key]) ? $items[$key] : array();
    }
    public static function run($input=array()) {
        $b=self::gate();
        if (!$b) return new WP_Error('mad4b_measurement_not_authorized','Exact site binding required.');
        if (!is_array($input)) return new WP_Error('mad4b_measurement_invalid_report','Expected an object.');
        $def=self::normalize($input);
        if (is_wp_error($def)) return $def;
        $token=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ);
        if (is_wp_error($token)) return $token;
        $body=array('dateRanges'=>array(array('startDate'=>$def['days'].'daysAgo','endDate'=>'yesterday')),
            'dimensions'=>array_map(static function($v){return array('name'=>$v);},$def['dimensions']),
            'metrics'=>array_map(static function($v){return array('name'=>$v);},$def['metrics']),
            'limit'=>$def['limit'],'keepEmptyRows'=>false);
        if ($def['event_name']!=='') {
            if (!in_array('eventName',$def['dimensions'],true)) {
                // Report dimensions are bounded; eventName filter dimension included as required.
                return new WP_Error('mad4b_measurement_filter_dimension_missing','Include eventName dimension to filter by event.');
            }
            $body['dimensionFilter']=array('filter'=>array('fieldName'=>'eventName',
                'stringFilter'=>array('matchType'=>'EXACT','value'=>$def['event_name'])));
        }
        $resp=wp_remote_post(self::API.$b['ga4_property'].':runReport',array(
            'timeout'=>12,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json',
                'Accept'=>'application/json'),'body'=>wp_json_encode($body)));
        unset($token);
        if (is_wp_error($resp)) return new WP_Error('mad4b_measurement_report_transport','GA4 report transport failed.');
        $status=(int)wp_remote_retrieve_response_code($resp);
        $value=(string)wp_remote_retrieve_body($resp);
        if ($status!==200||strlen($value)>1048576)
            return new WP_Error('mad4b_measurement_report_rejected','GA4 report request rejected.',array('http_status'=>$status));
        $rows=json_decode($value,true);
        if (!is_array($rows)) return new WP_Error('mad4b_measurement_report_invalid_response','Unexpected report response.');
        return array('contract'=>self::CONTRACT,'definition'=>$def,'property'=>$b['ga4_property'],
            'result'=>$rows,'remote_ga4_reports_modified'=>false,'read_only'=>true);
    }
    public static function register() {
        if (!function_exists('wp_register_ability')) return;
        $schema=array('type'=>'object','additionalProperties'=>false,
            'properties'=>array('preset'=>array('type'=>'string','enum'=>array_keys(self::presets())),
                'dimensions'=>array('type'=>'array','items'=>array('type'=>'string','enum'=>self::DIMENSIONS),'maxItems'=>5),
                'metrics'=>array('type'=>'array','items'=>array('type'=>'string','enum'=>self::METRICS),'maxItems'=>5),
                'days'=>array('type'=>'integer','minimum'=>1,'maximum'=>365),
                'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500),
                'event_name'=>array('type'=>'string','maxLength'=>40)));
        foreach (array('mad4b/google-measurement-report-catalog'=>'catalog',
            'mad4b/google-measurement-report-run'=>'run') as $name=>$method) {
            if (function_exists('wp_has_ability')&&wp_has_ability($name)) continue;
            wp_register_ability($name,array('label'=>$name,'description'=>'Read-only custom GA4 report definitions and execution.',
                'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
                'input_schema'=>$schema,'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
        }
    }
}
