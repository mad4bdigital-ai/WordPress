<?php
/**
 * Source-bound, per-company WordPress native intake for observed locale gaps.
 * Invoked only from the existing site-bound browser evidence runner.
 * Never repairs text/media/taxonomy/pricing, publishes, or completes jobs.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Locale_Native_Intake {
    const CONTRACT = 'mad4b.locale-native-contentjob-intake.v1';
    const MAX_PER_RUN = 5;
    public static function eligible($issues, $ledger, $proof, $minimum='high') {
        $rank=['critical'=>0,'high'=>1,'medium'=>2,'low'=>3];
        $ceiling=$rank[$minimum]??1;
        $picked=[];
        foreach((array)$issues as $issue){
            if (!is_array($issue) || !is_string($issue['id']??null)) continue;
            $id=$issue['id'];
            $row=$ledger[$id]??null;
            $request=is_array($row['content_job_request']??null)?$row['content_job_request']:[];
            $s=(array)($row['source_evidence']??[]);
            $observations=(array)($s['observations']??[]);
            $first=is_array($observations[0]??null)?$observations[0]:[];
            $severity=$issue['severity']??'';
            $lang=(string)($request['language']??'');
            if (!isset($rank[$severity]) || $rank[$severity]>$ceiling
                || !is_array($row) || ($row['status']??'')!=='open'
                || ($row['source']??'')!=='mad4b-locale-experience'
                || ($row['code']??'')!==($issue['code']??'')
                || ($row['severity']??'')!==$severity
                || !empty($row['content_job_id'])
                || ($request['status']??'')!=='PRIORITY_INTAKE'
                || ($request['requires_governed_content_job_admission']??null)!==true
                || ($request['auto_publication_authorized']??null)!==false
                || ($request['site_uuid']??'')!==($proof['site_uuid']??'')
                || ($first['snapshot_sha256']??'')!==($proof['sha256']??'')
                || !preg_match('/^[a-f0-9]{64}$/D',(string)($request['source_sha256']??''))
                || !preg_match('/^[a-z]{2}$/D',$lang))
                continue;
            $picked[]=['id'=>$id,'issue'=>$issue,'source_sha256'=>$request['source_sha256'],
                'language'=>$lang,'severity'=>$severity,
                'priority'=>max(0,min(100,(int)($issue['priority']??0)))];
        }
        usort($picked,static function($a,$b)use($rank) {
            $d=$rank[$a['severity']]<=>$rank[$b['severity']];
            return $d?:($b['priority']<=>$a['priority']?:strcmp($a['id'],$b['id']));
        });
        return $picked;
    }
    public static function run() {
        if (PHP_SAPI!=='cli' || !function_exists('wp_get_environment_type')
            || getenv('MAD4B_LOCALE_NATIVE_INTAKE_ENABLED')!=='1')
            return new WP_Error('mad4b_locale_original_intake_not_enabled');
        $env=(string)getenv('MAD4B_SITE_ENVIRONMENT');
        $home=rtrim((string)getenv('MAD4B_SITE_BASE'),'/');
        $site=(string)getenv('MAD4B_SITE_UUID');
        if (!in_array($env,['staging','development','local'],true)
            || wp_get_environment_type()!==$env
            || $home==='' || rtrim(home_url(),'/')!==$home
            || !preg_match('/^[a-f0-9-]{36}$/D',$site)
            || !class_exists('MAD4B_SCP_Site_Profile')
            || MAD4B_SCP_Site_Profile::site_uuid()!==$site
            || !current_user_can('manage_options')
            || !class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate())
            return new WP_Error('mad4b_locale_original_site_owner_denied');
        $checkpoint=MAD4B_SCP_Operational_Integrity::capture();
        if (is_wp_error($checkpoint)
            || ($checkpoint['scope']['site_uuid']??'')!==$site
            || empty($checkpoint['scope']['brand_ref']))
            return new WP_Error('mad4b_locale_original_site_integrity_failed');
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if (empty($workflow['board_tasks'])
            || !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
                $workflow['board_id']??0,$workflow['stage_id']??0))
            return new WP_Error('mad4b_locale_original_board_policy_disabled');
        $proof=MAD4B_SCP_Locale_Experience_Alerts::current();
        $fresh=MAD4B_SCP_Locale_Experience_Alerts::discover();
        if (!is_array($proof) || empty($fresh['fresh'])
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($proof['sha256']??''))
            || ($proof['site_uuid']??'')!==$site
            || !isset($fresh['issues']) || !is_array($fresh['issues']))
            return new WP_Error('mad4b_locale_original_browser_source_stale');
        $market=(string)getenv('MAD4B_SITE_COUNTRY');
        if (!$market) $market=(string)get_option('woocommerce_default_country');
        $country=strtolower(explode(':',$market)[0]);
        if (!preg_match('/^[a-z]{2}$/D',$country))
            return new WP_Error('mad4b_locale_original_country_unconfigured');
        $dry=getenv('MAD4B_LOCALE_NATIVE_INTAKE_DRY_RUN')==='1';
        $limit=min(self::MAX_PER_RUN,max(1,(int)($workflow['batch_limit']??self::MAX_PER_RUN)));
        global $wpdb;
        $lock='mad4b_wpml_url_jobs_'.get_current_blog_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return new WP_Error('mad4b_locale_original_ledger_lock_busy');
        $processed=[];
        try {
            $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
            $items=self::eligible($fresh['issues'],$ledger,$proof,
                (string)($workflow['minimum_severity']??'high'));
            foreach(array_slice($items,0,$limit) as $item) {
                $id=$item['id'];$row=$ledger[$id]??[];
                $reservation='mad4b_locale_intake_'.hash('sha256',$site.'|'.$id);
                // A prior uncertain attempt must be reconciled; never repeat it.
                $hold=get_option($reservation,false);
                if ($hold!==false) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_EXISTING_ADMISSION_RESERVATION'];
                    continue;
                }
                if ($dry) {
                    $processed[]=['issue'=>$id,'status'=>'DRY_RUN_ELIGIBLE'];
                    continue;
                }
                $board=MAD4B_SCP_Content_Readiness_Alerts::sync_board($id);
                if (is_wp_error($board)) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_BOARD_'.$board->get_error_code()];
                    continue;
                }
                $task=absint($board['task_id']??0);
                $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
                if (!$task || (int)($ledger[$id]['board_task_id']??0)!==$task) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_BOARD_READBACK'];
                    continue;
                }
                $reservationProof=['status'=>'CREATING','site_uuid'=>$site,
                    'issue_id'=>$id,'source_sha256'=>$item['source_sha256'],
                    'snapshot_sha256'=>$proof['sha256'],'created_at'=>gmdate('c')];
                if (!add_option($reservation,$reservationProof,'',false)) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_RESERVATION_RACE'];
                    continue;
                }
                $out=MAD4B_SCP_Content_Jobs::create_job([
                    'brand_id'=>(string)$checkpoint['scope']['brand_ref'],
                    'subject'=>'Investigate published locale experience: '.(string)$item['issue']['code'].
                        ' — '.sanitize_text_field((string)$item['issue']['label']),
                    'language'=>$item['language'],'country'=>$country,
                    'content_type'=>'localization_observation',
                    'automation_level'=>'strict','research_depth'=>'standard',
                    'reason'=>'Source-bound Readiness issue '.$id.
                        '; browser '.$proof['sha256'].'; request '.$item['source_sha256'].
                        '; original ContentJob only, independent QA before publication.'
                ]);
                if(is_wp_error($out)) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_CONTENTJOB_'.$out->get_error_code()];
                    continue;
                }
                $job=(array)($out['job']??[]);
                $jobId=(string)($job['job_id']??'');
                if (!preg_match('/^[a-f0-9-]{36}$/D',$jobId)
                    || ($job['site_uuid']??'')!==$site
                    || ($job['state']??'')!=='NEW'
                    || ($job['stage']??'')!=='INTAKE'
                    || ($job['automation_level']??'')!=='strict'
                    || ($job['language']??'')!==$item['language']) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_CREATED_JOB_READBACK_UNCERTAIN'];
                    continue;
                }
                $reservationProof['status']='LINKED';
                $reservationProof['job_id']=$jobId;
                if (!update_option($reservation,$reservationProof,false)) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_CREATED_BUT_RESERVATION_UPDATE_UNCERTAIN'];
                    continue;
                }
                $ledger[$id]['content_job_id']=$jobId;
                $ledger[$id]['content_job_request']['status']='LINKED';
                $ledger[$id]['content_job_request']['original_content_job_revision']=1;
                $ledger[$id]['content_job_link_evidence_sha256']=hash('sha256',
                    $id.'|'.$jobId.'|'.$proof['sha256'].'|'.$item['source_sha256']);
                if (!update_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,$ledger,false)) {
                    $processed[]=['issue'=>$id,'status'=>'HOLD_CREATED_BUT_LEDGER_UPDATE_UNCERTAIN'];
                    continue;
                }
                $read=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
                $verified=(($read[$id]['content_job_id']??'')===$jobId
                    && ($read[$id]['content_job_request']['status']??'')==='LINKED');
                $processed[]=['issue'=>$id,
                    'status'=>$verified?'CREATED_LINKED_READBACK':'HOLD_LEDGER_READBACK_UNCERTAIN',
                    'job_id'=>$jobId,'board_task_id'=>$task];
            }
            return [
                'contract'=>self::CONTRACT,'site_uuid'=>$site,
                'source_evidence_sha256'=>$proof['sha256'],
                'matched_eligible'=>count($items),'max_per_run'=>$limit,
                'processed'=>count($processed),'results'=>$processed,
                'dry_run'=>$dry,'publication_authorized'=>false,
                'mutation_performed'=>!$dry && count($processed)>0,
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
}
if (defined('MAD4B_LOCALE_NATIVE_INTAKE_EXECUTE') && MAD4B_LOCALE_NATIVE_INTAKE_EXECUTE) {
    $result=MAD4B_Locale_Native_Intake::run();
    if (is_wp_error($result))throw new RuntimeException($result->get_error_code());
    echo wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
}
