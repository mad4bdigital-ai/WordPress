<?php
/**
 * Plugin Name: MAD4B Company Policy Intelligence
 * Description: Policy anomalies, Content Readiness source, local policy health, and registry-scoped company reporting.
 * Version: 2.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Policy_Intelligence_V2 {
    const STORE='mad4b_company_policy_gaps_v2';
    const REPORTS='mad4b_company_policy_reports_v2';
    const MAX_GAPS=150;
    private const GAP_MAP=[
        'mad4b_rate_no_eligible_candidate'=>['code'=>'policy_rate_no_valid','severity'=>'high','label'=>'No native eligible Tour Rate for the requested date or options'],
        'mad4b_rate_ambiguous'=>['code'=>'policy_rate_ambiguous','severity'=>'high','label'=>'Multiple equally eligible Tour Rates need pricing review'],
        'mad4b_rate_candidates_limit'=>['code'=>'policy_rate_limit','severity'=>'medium','label'=>'Too many Tour Rates to resolve an unambiguous choice'],
        'mad4b_rate_native_provider_unavailable'=>['code'=>'policy_rate_provider_missing','severity'=>'high','label'=>'Native Booking v2 Rate source is not available'],
    ];

    public static function gaps(): array {
        $v=get_option(self::STORE,[]);
        return is_array($v)?$v:[];
    }
    public static function on_gap(string $reason,array $detail=[]): void {
        if(!isset(self::GAP_MAP[$reason]))return;
        $tour=absint($detail['tour_id']??0);
        if(!$tour || get_post_type($tour)!=='tours-and-activities' || get_post_status($tour)!=='publish')return;
        $spec=self::GAP_MAP[$reason];$code=$spec['code'];
        $key=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$code);
        $throttle='mad4b_policy_gap_'.substr($key,0,25);
        if(get_transient($throttle))return;
        set_transient($throttle,'1',HOUR_IN_SECONDS);
        $gaps=self::gaps();
        $previous=$gaps[$key]??null;
        $gaps[$key]=[
          'id'=>$key,'post_type'=>'tours-and-activities','object_id'=>$tour,
          'code'=>$code,'severity'=>$spec['severity'],'label'=>$spec['label'],
          'source'=>'mad4b-policy-intelligence',
          'first_seen'=>$previous['first_seen']??gmdate('c'),
          'last_seen'=>gmdate('c'),'occurrences'=>(int)($previous['occurrences']??0)+1,
          'policy_revision'=>class_exists('MAD4B_Company_Policy_Registry_V2')
              ?(int)MAD4B_Company_Policy_Registry_V2::state()['revision']:0,
        ];
        if(count($gaps)>self::MAX_GAPS){
            uasort($gaps,static fn($a,$b)=>strcmp($a['last_seen']??'',$b['last_seen']??''));
            $gaps=array_slice($gaps,-self::MAX_GAPS,null,true);
        }
        update_option(self::STORE,$gaps,false);
        do_action('mad4b/policy/intelligence_issue_recorded',$gaps[$key]);
    }
    public static function on_rate_selected(array $receipt): void {
        $tour=absint($receipt['tour_id']??0);
        if(!$tour)return;
        $gaps=self::gaps();$changed=false;
        foreach(self::GAP_MAP as $spec){
            $key=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$spec['code']);
            if(isset($gaps[$key])){unset($gaps[$key]);$changed=true;}
        }
        if($changed)update_option(self::STORE,$gaps,false);
    }
    public static function content_issues($issues,$cfg): array {
        if(!is_array($issues))$issues=[];
        foreach(self::gaps() as $row){
            if(!is_array($row)||!in_array($row['code']??'',
               ['policy_rate_no_valid','policy_rate_ambiguous','policy_rate_limit','policy_rate_provider_missing'],true))continue;
            $tour=absint($row['object_id']??0);
            if(!$tour||get_post_status($tour)!=='publish'||get_post_type($tour)!=='tours-and-activities')continue;
            $issues[]=array_intersect_key($row,array_flip(['id','post_type','object_id','code','severity','label','source']));
        }
        return $issues;
    }
    public static function summary(): array {
        $byCode=[];$gaps=self::gaps();
        foreach($gaps as $row){$code=(string)($row['code']??'unknown');$byCode[$code]=($byCode[$code]??0)+1;}
        return [
            'contract'=>'mad4b.policy-intelligence.v2','observed_at'=>gmdate('c'),
            'open_gaps'=>count($gaps),'open_by_code'=>$byCode,
            'source'=>'local_company_policy_events','performance_measurements_verified'=>false,
        ];
    }
    public static function local_summary(): array {
        if(!class_exists('MAD4B_Company_Policy_Registry_V2'))return [];
        $r=MAD4B_Company_Policy_Registry_V2::report_summary();
        $r['intelligence']=self::summary();
        $r['open_gaps']=$r['intelligence']['open_gaps'];
        $r['site_origin']=home_url('/');
        $site=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
        $r['site_uuid']=is_array($site)?($site['site_uuid']??null):null;
        // Existing Render Query Governor owns its performance metrics.
        // Presence is not a claim that LCP/TTFB or AJAX parity passed QA.
        $r['render_metrics_available']=class_exists('MAD4B_Render_Query_Public_Fragments')
            && method_exists('MAD4B_Render_Query_Public_Fragments','metrics');
        return $r;
    }
    private static function registered(): array {
        if(!class_exists('MAD4B_Company_Portfolio_Manager_V1'))return [];
        $raw=get_option(MAD4B_Company_Portfolio_Manager_V1::REGISTRY,[]);
        return is_array($raw)?$raw:[];
    }
    public static function company_rollup(): array {
        $rows=[];$reports=get_option(self::REPORTS,[]);
        if(!is_array($reports))$reports=[];
        foreach(self::registered() as $code=>$row){
            if(!is_string($code)||!is_array($row))continue;
            $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
            if(is_wp_error($binding))continue;
            $receipt=$reports[$code]??null;
            $rows[]=['company_code'=>$code,
                'site_uuid'=>$binding['company_site_uuid'],
                'status'=>is_array($receipt)?'registry_matched_received_not_313_certified':'awaiting_company_report',
                'registry_revision'=>is_array($receipt)?(int)$receipt['revision']:null,
                'open_gaps'=>is_array($receipt)?(int)$receipt['open_gaps']:null,
                'received_at'=>$receipt['received_at']??null,
                'original_313_certified'=>false];
        }
        $local=self::local_summary();
        $rows[]=['company_code'=>'this-site','site_uuid'=>null,'status'=>'local_live',
                 'registry_revision'=>$local['revision']??0,
                 'open_gaps'=>$local['intelligence']['open_gaps']??0,
                 'received_at'=>gmdate('c'),'original_313_certified'=>false];
        return ['contract'=>'mad4b.company-policy-rollup.v2','observed_at'=>gmdate('c'),
            'companies'=>$rows,'company_count'=>count($rows),
            'remote_313_certified'=>0,'source'=>'local_and_registry_matched_receipts_only'];
    }
    /**
     * Local authenticated central collector. Company identity must be
     * registered and match the existing Company Manager binding, and the
     * inbound data remains unverified until its own independent receipt.
     */
    public static function ingest(array $raw) {
        if(!current_user_can('manage_options'))return new WP_Error('policy_report_forbidden');
        $code=sanitize_key((string)($raw['company_code']??''));
        if(!isset(self::registered()[$code]))return new WP_Error('policy_company_unknown');
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($binding))return $binding;
        $origin=(string)($raw['site_origin']??'');
        $registry=self::registered();
        $expected=rtrim((string)($registry[$code]['canonical_origin']??''),'/').'/';
        // The Company Manager registry is authoritative for this verified
        // company's origin; an unregistered origin is never guessed.
        if(!$expected || $expected==='/' || $origin!==$expected
            ||($raw['site_uuid']??'')!==$binding['company_site_uuid'])
            return new WP_Error('policy_report_binding_mismatch');
        if(($raw['contract']??'')!=='mad4b.policy.registry.summary.v2')
            return new WP_Error('policy_report_contract_unknown');
        $rev=filter_var($raw['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $gaps=filter_var($raw['open_gaps']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
        if($rev===false||$gaps===false)return new WP_Error('policy_report_fields_invalid');
        $reports=get_option(self::REPORTS,[]);if(!is_array($reports))$reports=[];
        if(isset($reports[$code]) && $rev<(int)$reports[$code]['revision'])return new WP_Error('policy_report_stale_revision');
        $reports[$code]=['site_uuid'=>$binding['company_site_uuid'],'revision'=>$rev,
            'open_gaps'=>$gaps,'received_at'=>gmdate('c'),'origin'=>$origin,
            'status'=>'registry_matched_received_not_313_certified'];
        update_option(self::REPORTS,$reports,false);
        return ['accepted'=>true,'company_code'=>$code,'revision'=>$rev,
            'receipt_state'=>'registry_matched_received_not_313_certified'];
    }
    public static function routes(): void {
        register_rest_route('mad4b-policy/v2','/intelligence',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static fn()=>rest_ensure_response(self::local_summary()),
        ]);
        register_rest_route('mad4b-policy/v2','/companies',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static fn()=>rest_ensure_response(self::company_rollup()),
        ]);
        register_rest_route('mad4b-policy/v2','/company-receipt',[
            'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function($request){
                $result=self::ingest((array)$request->get_json_params());
                return is_wp_error($result)?$result:rest_ensure_response($result);
            },
        ]);
    }
    public static function menu(): void {
        add_submenu_page('mad4b-company','Policy Intelligence','Policy Intelligence','manage_options',
            'mad4b-company-policy-intelligence',[self::class,'page']);
    }
    public static function page(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $r=self::local_summary();$companies=self::company_rollup();
        echo '<div class="wrap"><h1>MAD4B · Policy Intelligence</h1>';
        echo '<p>Local policy execution remains inside each company. Remote companies appear only after a registry-matched receipt.</p>';
        echo '<h2>Local</h2><p>Revision <strong>'.(int)($r['revision']??0).
             '</strong> · Open native Rate gaps <strong>'.(int)($r['intelligence']['open_gaps']??0).'</strong></p>';
        echo '<h2>Company rollup</h2><table class="widefat striped"><thead><tr><th>Company</th><th>Evidence</th><th>Policy revision</th><th>Open gaps</th><th>Received UTC</th></tr></thead><tbody>';
        foreach($companies['companies'] as $c){
            echo '<tr><td>'.esc_html($c['company_code']).'</td><td>'.esc_html($c['status']).'</td><td>'.
              esc_html((string)($c['registry_revision']??'—')).'</td><td>'.esc_html((string)($c['open_gaps']??'—')).
              '</td><td>'.esc_html((string)($c['received_at']??'—')).'</td></tr>';
        }
        echo '</tbody></table><p>Remote receipts are not Original 313 certification. Rates and bookings are never modified by this report.</p></div>';
    }
    public static function boot(): void {
        add_action('mad4b/policy_rate_selection/gap',[self::class,'on_gap'],10,2);
        add_action('mad4b/policy_rate_selection/selected',[self::class,'on_rate_selected']);
        add_filter('mad4b/content_readiness/external_issues',[self::class,'content_issues'],30,2);
        add_action('rest_api_init',[self::class,'routes']);
        add_action('admin_menu',[self::class,'menu'],94);
    }
}
MAD4B_Company_Policy_Intelligence_V2::boot();
