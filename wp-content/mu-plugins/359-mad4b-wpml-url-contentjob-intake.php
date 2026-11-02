<?php
/**
 * Plugin Name: MAD4B WPML Rewrite Gap ContentJob Intake
 * Description: Bounded site-local WPML taxonomy/CPT rewrite translation requests into native MAD4B ContentJobs.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_WPML_URL_ContentJob_Intake {
    const CONTRACT='mad4b.wpml-url-contentjob-intake.v1';
    const LOCK='mad4b_wpml_url_jobs_';
    const MARKER='wpml-url-issue-ref:';
    const CONFIRM='CREATE REVIEW-ONLY WPML JOBS';
    const SITE='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const ORIGIN='https://tours.dev.mad4b.com';
    const OPERATOR_ID=26;
    const OPERATOR_LOGIN='mad4b_connection_cc0e557e5804';
    const MAX=5;
    private static function deny($code) {return new WP_Error('mad4b_wpml_url_'.$code,$code);}
    private static function scope() {
        if(!defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli'
            || wp_get_environment_type()!=='staging'
            || rtrim(home_url(),'/')!==self::ORIGIN
            || !class_exists('MAD4B_SCP_Site_Profile')
            || MAD4B_SCP_Site_Profile::site_uuid()!==self::SITE)
            return self::deny('environment_site_scope');
        $cp=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($cp))return $cp;
        if(($cp['scope']['site_uuid']??'')!==self::SITE
            || ($cp['scope']['environment']??'')!=='staging'
            || empty($cp['scope']['brand_ref'])
            || empty($cp['scope']['tenant_ref']))return self::deny('integrity_scope');
        $user=wp_get_current_user();
        if((int)get_current_user_id()!==self::OPERATOR_ID ||
           $user->user_login!==self::OPERATOR_LOGIN ||
           true!==MAD4B_SCP_Policy::can_mutate())
            return self::deny('registered_local_operator_required');
        return $cp['scope'];
    }
    private static function source() {
        $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        $fresh=\DynamicFilterSEOBridge\Integration\WPMLTermRouteGapScanner::scan();
        $items=array();
        foreach($fresh as $observed) {
            $id=(string)($observed['id']??'');
            $issue=$ledger[$id]??null;
            if(!is_array($issue) || ($issue['status']??'')!=='open'
                || ($issue['code']??'')!==($observed['code']??'')
                || ($issue['source']??'')!=='dynamic-filter-seo-bridge'
                || !preg_match('/^dfsb_wpml_(term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_[a-z0-9_-]+_[0-9]+_[a-z]{2}$/D',$observed['code'])
                || ($issue['id']??'')!==$id
                || ($issue['post_type']??'')!=='site'
                || (int)($issue['object_id']??-1)!==0)continue;
            $req=(array)($issue['content_job_request']??array());
            if(($req['source_issue_id']??'')!==$id
                || ($req['deduplication_key']??'')!==$id
                || empty($req['requires_governed_content_job_admission']))continue;
            $score=(int)($observed['priority']??-1);
            if($score<0||$score>100)throw new RuntimeException('invalid_source_priority');
            $items[$id]=array('id'=>$id,'issue'=>$issue,'current'=>$observed,'priority'=>$score);
        }
        uasort($items,static function($a,$b){
            if($a['priority']!==$b['priority'])return $b['priority']<=>$a['priority'];
            return strcmp($a['id'],$b['id']);
        });
        return $items;
    }
    private static function lookup($scope,$id) {
        global $wpdb;
        $t=MAD4B_SCP_Schema::tables()['content_jobs']??'';
        if(!$t)return self::deny('schema');
        $needle=self::MARKER.$id;
        $rows=(array)$wpdb->get_results($wpdb->prepare(
          "SELECT job_id,subject FROM `{$t}` WHERE site_uuid=%s AND tenant_id=%s AND brand_id=%s AND subject LIKE %s LIMIT 3",
          $scope['site_uuid'],$scope['tenant_ref'],$scope['brand_ref'],
          '%'.$wpdb->esc_like($needle).'%'),ARRAY_A);
        $matches=array_values(array_filter($rows,static function($r)use($needle){
            return str_contains((string)$r['subject'],$needle);
        }));
        if(count($matches)>1)return self::deny('duplicate_native_jobs');
        return $matches ? (string)$matches[0]['job_id'] : '';
    }
    public static function plan() {
        $scope=self::scope();if(is_wp_error($scope))return $scope;
        $items=self::source();$pending=0;$linked=0;$priorities=array();
        foreach($items as $row) {
            $linked+=empty($row['issue']['content_job_id'])?0:1;
            if(empty($row['issue']['content_job_id']))$pending++;
            $key=(string)$row['priority'];
            $priorities[$key]=($priorities[$key]??0)+1;
        }
        $fingerprint=hash('sha256',wp_json_encode(array(
            'contract'=>self::CONTRACT,'site_uuid'=>$scope['site_uuid'],
            'actor_id'=>get_current_user_id(),'pending'=>$pending,
            'ids'=>array_keys($items),'fingerprints'=>array_map(static function($v){
                return array($v['id'],$v['priority'],(string)($v['issue']['content_job_id']??''));
            },array_values($items)),
        ),JSON_UNESCAPED_SLASHES));
        return array('contract'=>self::CONTRACT.'.plan','status'=>'READY','site_uuid'=>$scope['site_uuid'],
            'environment'=>'staging','discovered'=>count($items),'pending'=>$pending,'already_linked'=>$linked,
            'priorities'=>$priorities,'plan_sha256'=>$fingerprint,'exact_confirmation'=>self::CONFIRM,
            'max_batch'=>self::MAX,'auto_publish'=>false,'mutation_performed'=>false);
    }
    private static function title($item) {
        $o=$item['current'];$c=(string)$o['code'];
        if(!preg_match('/^dfsb_wpml_(term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_([a-z0-9_-]+)_([0-9]+)_([a-z]{2})$/D',$c,$m))
            return self::deny('issue_code_invalid');
        $kind=$m[1];$subject=$m[2];$id=(int)$m[3];$locale=$m[4];
        if($kind==='term_missing' || $kind==='route_slug') {
            $term=get_term($id,$subject);
            if(!$term instanceof WP_Term)return self::deny('term_removed');
            $label=wp_strip_all_tags($term->name);
        } else {
            $label=$subject.' permalink rewrite base';
        }
        return array('reason'=>$kind,'subject'=>$subject,'term_id'=>$id,'locale'=>$locale,
          'title'=>mb_substr($label,0,125));
    }
    public static function run($args) {
        $scope=self::scope();if(is_wp_error($scope))return $scope;
        $limit=(int)($args['limit']??1);
        if($limit<1||$limit>self::MAX)return self::deny('invalid_limit');
        $plan=self::plan();if(is_wp_error($plan))return $plan;
        if(!hash_equals((string)$plan['plan_sha256'],(string)($args['expected-plan']??'')) ||
            !hash_equals(self::CONFIRM,(string)($args['confirm']??'')))
            return self::deny('stale_or_unconfirmed_plan');
        global $wpdb;
        $lock=self::LOCK.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return self::deny('concurrent_intake');
        $report=array('contract'=>self::CONTRACT.'.run','created'=>0,'reused'=>0,
           'linked'=>0,'queued'=>0,'held'=>0,'errors'=>array(),'jobs'=>array(),
           'site_uuid'=>self::SITE,'published'=>false);
        try {
            $items=self::source();
            foreach($items as $key=>$r) {
                if($report['linked']>=$limit || $report['held']>0)break;
                $prev=$r['issue'];if(!empty($prev['content_job_id']))continue;
                $info=self::title($r);if(is_wp_error($info)){
                    $report['held']++;$report['errors'][]=$info->get_error_code();break;
                }
                $existing=self::lookup($scope,$key);if(is_wp_error($existing)){
                    $report['held']++;$report['errors'][]=$existing->get_error_code();break;
                }
                $job_id=$existing;
                if(!$job_id) {
                    $subject='WPML URL translation: '.$info['title'].' ['.$info['locale'].'] '.
                        $info['subject'].' #'.$info['term_id'].' '.self::MARKER.$key;
                    $made=MAD4B_SCP_Content_Jobs::create_job(array(
                        'brand_id'=>$scope['brand_ref'],
                        'subject'=>$subject,'language'=>$info['locale'],
                        'country'=>'global','content_type'=>'translation',
                        'target_post_type'=>'','research_depth'=>'standard',
                        'automation_level'=>'assisted',
                        'reason'=>'WPML URL issue '.$key.'; priority '.$r['priority'].
                          '; original taxonomy/CPT rewrite or term source. Verify translated slug and WPML links.'.
                          ' Review required; do not auto-publish or rewrite existing URLs.'
                    ));
                    if(is_wp_error($made)) {
                        $report['held']++;
                        $report['errors'][]=$made->get_error_code();
                        $report['errors'][]=(string)(($made->get_error_data()['failure_code']??''));
                        break;
                    }
                    $job_id=(string)($made['job']['job_id']??'');
                    if(!$job_id){$report['held']++;$report['errors'][]='create_readback_missing';break;}
                    $report['created']++;
                } else $report['reused']++;
                $read=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job_id));
                if(is_wp_error($read) || ($read['job']['job_id']??'')!==$job_id) {
                    $report['held']++;$report['errors'][]='native_readback_failed';break;
                }
                $job=$read['job'];
                if(($job['content_type']??'')!=='translation'
                    || ($job['site_uuid']??'')!==$scope['site_uuid']
                    || !str_contains((string)($job['subject']??''),self::MARKER.$key)) {
                    $report['held']++;$report['errors'][]='foreign_job_rejected';break;
                }
                // A native queued ContentJob, not an immediately executed translation.
                if(($job['state']??'')==='NEW') {
                    $next=MAD4B_SCP_Content_Jobs::transition_job(array(
                        'job_id'=>$job_id,'expected_revision'=>(int)$job['job_revision'],
                        'state'=>'QUEUED','stage'=>'INTAKE',
                        'reason'=>'WPML URL source verified; queued for editorial translation and URL QA, no auto-publish.'
                    ));
                    if(is_wp_error($next)) {
                        $report['held']++;$report['errors'][]='queue_'.$next->get_error_code();break;
                    }
                    $report['queued']++;
                }
                $registry=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($registry[$key]['content_job_request']['deduplication_key']??'')!==$key
                    || ($registry[$key]['status']??'')!=='open'
                    || (!empty($registry[$key]['content_job_id']) && $registry[$key]['content_job_id']!==$job_id)){
                    $report['held']++;$report['errors'][]='ledger_changed_during_claim';break;
                }
                $registry[$key]['content_job_id']=$job_id;
                $registry[$key]['priority']=$r['priority'];
                $registry[$key]['triage']=$r['current']['triage'];
                $registry[$key]['content_job_request']['status']='LINKED';
                $registry[$key]['content_job_request']['priority']=$r['priority'];
                $registry[$key]['content_job_request']['triage']=$r['current']['triage'];
                $registry[$key]['content_job_request']['linked_at']=gmdate('c');
                update_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,$registry,false);
                $check=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($check[$key]['content_job_id']??'')!==$job_id){
                    $report['held']++;$report['errors'][]='ledger_readback_uncertain';break;
                }
                $report['linked']++;
                $report['jobs'][]=array('issue_id'=>$key,'job_id'=>$job_id,'priority'=>$r['priority'],
                    'locale'=>$info['locale'],'state'=>'QUEUED','readback_verified'=>true);
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
        return $report;
    }
    public static function cli($args,$assoc) {
        $verb=(string)($args[0]??'');
        if($verb==='plan' && count($args)===1 && !$assoc) {
            $result=self::plan();
        } elseif($verb==='run' && count($args)===1
            && !array_diff(array_keys($assoc),array('expected-plan','confirm','limit'))) {
            $result=self::run($assoc);
        } else WP_CLI::error('Usage: wp mad4b wpml-url-intake plan | run --limit=N --expected-plan=<sha256> --confirm=...');
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if(!empty($result['held']))WP_CLI::error('Intake held; no blind retry.');
    }
    public static function boot() {
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b wpml-url-intake',array(__CLASS__,'cli'));
    }
}
MAD4B_WPML_URL_ContentJob_Intake::boot();
