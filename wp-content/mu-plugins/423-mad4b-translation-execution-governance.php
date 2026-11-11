<?php
/**
 * Plugin Name: MAD4B Translation Execution Governance
 * Description: Source-scoped notification-only deferral for existing translation jobs, independent of original queue and Readiness alerts.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Translation_Execution_Governance {
    const OPTION = 'mad4b_translation_execution_policy_v1';
    const SITE_UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const ORIGIN = 'https://tours.dev.mad4b.com';
    const CONTRACT = 'mad4b.translation-execution-governance.v1';

    public static function site_is_exact(): bool {
        return untrailingslashit((string)get_option('home')) === self::ORIGIN &&
            wp_get_environment_type() === 'staging';
    }

    public static function policy(): ?array {
        if (!self::site_is_exact()) return null;
        $p = get_option(self::OPTION, null);
        if (!is_array($p) || ($p['contract']??'') !== self::CONTRACT ||
            ($p['site_uuid']??'') !== self::SITE_UUID ||
            ($p['mode']??'') !== 'notification_only' ||
            !array_key_exists('resume_on',$p) || $p['resume_on'] !== null ||
            ($p['resume_requires']??'') !== 'explicit_governed_admin_action' ||
            !isset($p['jobs']) || !is_array($p['jobs']) || count($p['jobs']) !== 42 ||
            !preg_match('/^[a-f0-9]{64}$/D', (string)($p['jobs_sha256']??'')) ||
            !hash_equals((string)$p['jobs_sha256'], hash('sha256',wp_json_encode($p['jobs'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))) return null;
        return $p;
    }

    public static function is_deferred_job(array $job): bool {
        // The original 42 precise deferrals remain authoritative. An
        // independently verified campaign may additionally defer other
        // translation jobs without modifying the core ContentJob renderer.
        if(class_exists('MAD4B_Hub_Translation_Campaign_Hold',false) &&
           MAD4B_Hub_Translation_Campaign_Hold::blocked_job($job))return true;
        $p = self::policy();
        if (!$p || ($job['site_uuid']??'')!==self::SITE_UUID ||
            ($job['content_type']??'')!=='translation' ||
            ($job['target_post_type']??'')!=='tours-and-activities')return false;
        $id=(string)($job['job_id']??'');
        $r=$p['jobs'][$id]??null;
        return is_array($r) && ($r['language']??'')===($job['language']??'') &&
            (int)($r['source_post_id']??0)>0 &&
            str_contains((string)($job['subject']??''),'post #'.(int)$r['source_post_id'].' ');
    }

    public static function is_deferred_source_language(int $source, string $language): bool {
        $p = self::policy();
        if (!$p || !$source || !in_array($language,['fr','de','ar'],true))return false;
        foreach($p['jobs'] as $v){
            if((int)($v['source_post_id']??0)===$source && ($v['language']??'')===$language)return true;
        }
        return false;
    }

    /** Pure pre-mutation guard; called inside the original atomic ContentJob transition. */
    public static function transition_denied(array $job,string $new_state,string $new_stage,string $artifact_id):bool {
        return self::is_deferred_job($job) &&
            ($new_state!==(string)($job['state']??'') ||
             $new_stage!==(string)($job['stage']??'') ||
             $artifact_id!=='');
    }

    public static function status(): array {
        $policy=self::policy();
        $out=['contract'=>self::CONTRACT,'site_uuid'=>self::SITE_UUID,'mode'=>$policy?'notification_only':'not_configured',
            'canonical_job_state_unchanged'=>true,'deferred_jobs'=>0,'open_notifications'=>0,
            'stored_job_states'=>[],'mismatches'=>[],'resume_on'=>null,'queue_mutated'=>false];
        if(!$policy)return $out;
        global $wpdb;
        $out['deferred_jobs']=count($policy['jobs']);
        $out['deferred_source_posts']=count(array_unique(array_map(fn($r)=>(int)$r['source_post_id'],$policy['jobs'])));
        $out['policy_version']=$policy['policy_version']??1;
        $out['reason_code']=$policy['reason_code']??'other_priority';
        $out['configured_at']=$policy['configured_at']??null;
        $out['jobs_sha256']=$policy['jobs_sha256'];
        $table=$wpdb->prefix.'mad4b_content_jobs';
        $alerts=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $sourceIds=array_unique(array_map(fn($r)=>(int)$r['source_post_id'],$policy['jobs']));
        foreach($alerts as $i){
            if(!is_array($i)||!in_array((int)($i['object_id']??0),$sourceIds,true))continue;
            if(($i['status']??'')==='open' && in_array((string)($i['code']??''),['loc_missing_fr','loc_missing_de','loc_missing_ar'],true))$out['open_notifications']++;
        }
        // Bounded single-query readback; do not add 42 SQL roundtrips to the
        // Content Readiness admin page on every request.
        $ids=array_keys($policy['jobs']);
        $placeholders=implode(',',array_fill(0,count($ids),'%s'));
        $sql=$wpdb->prepare("SELECT job_id,site_uuid,content_type,target_post_type,language,state,stage,job_revision,subject FROM {$table} WHERE job_id IN ({$placeholders})",$ids);
        $fetched=(array)$wpdb->get_results($sql,ARRAY_A);
        $byId=[];
        foreach($fetched as $one)$byId[$one['job_id']]=$one;
        foreach($policy['jobs'] as $id=>$expected){
            $row=$byId[$id]??null;
            if(!$row||!self::is_deferred_job($row)){$out['mismatches'][]=['job_id'=>$id,'code'=>'JOB_MISSING_OR_SCOPE_CHANGED'];continue;}
            $state=$row['state'].'/'.$row['stage'];
            $out['stored_job_states'][$state]=($out['stored_job_states'][$state]??0)+1;
            if($state!=='QUEUED/INTAKE')$out['mismatches'][]=['job_id'=>$id,'code'=>'JOB_STATE_CHANGED'];
            if((int)$row['job_revision']!==(int)($expected['revision_at_deferral']??-1))
                $out['mismatches'][]=['job_id'=>$id,'code'=>'JOB_REVISION_CHANGED'];
        }
        $out['state']=$out['mismatches']?'NEEDS_RECONCILIATION':'POLICY_ACTIVE';
        $out['all_42_remain_queued']=count($out['mismatches'])===0 && ($out['stored_job_states']['QUEUED/INTAKE']??0)===42;
        $out['canonical_job_state_unchanged']=$out['all_42_remain_queued'];
        return $out;
    }

    public static function cli(array $args):void {
        if(($args[0]??'status')!=='status') WP_CLI::error('Only status is exposed. Resuming requires an explicit governed policy update.');
        WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public static function admin_notice():void {
        if(!self::site_is_exact() || !current_user_can('manage_options') ||
            (string)($_GET['page']??'')!=='mad4b-content-readiness-alerts')return;
        $s=self::status();if(($s['mode']??'')!=='notification_only')return;
        echo '<div class="notice notice-info"><p><strong>MAD4B Translation Governance:</strong> '.
            esc_html((string)$s['deferred_jobs']).' existing translation jobs are intentionally notification-only; '.
            'Content Readiness alerts stay open. No translation scheduling, job cancellation, or publication was performed. '.
            'Resuming requires an explicit authorized decision.</p></div>';
    }
}
add_action('init',static function(){
    if(defined('WP_CLI') && WP_CLI)WP_CLI::add_command('mad4b translation-governance',[MAD4B_Translation_Execution_Governance::class,'cli']);
},20);
add_action('admin_notices',[MAD4B_Translation_Execution_Governance::class,'admin_notice']);
