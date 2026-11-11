<?php
defined('ABSPATH') || exit;
/**
 * Administrative read model for ContentJob/Artifact continuity.
 * No provider calls, job transitions, model calls or publishing.
 */
final class MAD4B_Hub_Workflow_Operations {
    const CONTRACT = 'mad4b.hub.workflow-operations.v1';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'routes'), 45);
        add_action('wp_abilities_api_init', array(__CLASS__, 'abilities'), 47);
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
    }
    public static function allowed() {
        return class_exists('MAD4B_Content_Hub') && MAD4B_Content_Hub::permission();
    }
    private static function error($code, $status=400) {
        return new WP_Error('mad4b_hub_operations_'.$code, $code,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    public static function jobs($input=array()) {
        if (!self::allowed()) return self::error('access_denied',403);
        $limit=max(1,min(100,(int)($input['limit']??30)));
        $result=MAD4B_SCP_Content_Jobs::list_jobs(array('limit'=>$limit));
        if (is_wp_error($result)) return $result;
        $rows=array();
        foreach ((array)($result['items']??array()) as $job) {
            $rows[]=array(
                'job_id'=>(string)($job['job_id']??''),
                'state'=>(string)($job['state']??''),
                'stage'=>(string)($job['stage']??''),
                'revision'=>(int)($job['job_revision']??0),
                'subject'=>(string)($job['subject']??''),
                'automation_level'=>(string)($job['automation_level']??'')
            );
        }
        return array('contract'=>self::CONTRACT,'jobs'=>$rows,
            'count'=>count($rows),'scope'=>'current_authorized_site',
            'mutation_performed'=>false);
    }
    public static function inspect($input=array()) {
        if (!self::allowed()) return self::error('access_denied',403);
        $id=(string)($input['job_id']??'');
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id))
            return self::error('invalid_job_id');
        $res=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$id));
        if (is_wp_error($res)) return $res;
        $job=$res['job']??array();
        if (!is_array($job) || ($job['job_id']??'')!==$id)
            return self::error('job_missing',404);
        $artifacts=MAD4B_SCP_Artifacts::list_artifacts(array('job_id'=>$id));
        if (is_wp_error($artifacts)) return $artifacts;
        $by_type=array();
        $ids=array();
        $information_gain=array();
        foreach ((array)($artifacts['items']??array()) as $item) {
            if (($item['status']??'')!=='active') continue;
            $type=(string)($item['artifact_type']??'');
            if ($type==='') continue;
            $by_type[$type]=($by_type[$type]??0)+1;
            $ids[$type][]=(string)($item['artifact_id']??'');
            if ($type==='information_gain') {
                $information_gain=array_intersect_key((array)($item['payload']??array()),
                    array_flip(array('missing_questions_entities_topics',
                        'recommended_priorities','unsupported_areas_to_avoid')));
            }
        }
        ksort($by_type);
        $missing=array();
        $stage=(string)($job['stage']??'');
        $next='';
        if ($stage==='SCRAPING') {
            if (empty($by_type['scraped_page'])) $missing[]='persisted_source_evidence';
            $next='mad4b/control-workflow-resume';
        } elseif ($stage==='COMPETITOR_ANALYSIS') {
            if (empty($by_type['scraped_page'])) $missing[]='scraped_page';
            if (empty($by_type['evidence_coverage_matrix'])) $missing[]='evidence_coverage_matrix';
            $next='mad4b/control-workflow-baseline';
        } elseif ($stage==='INFORMATION_GAIN') {
            if (empty($by_type['keyword_research'])) $missing[]='keyword_research';
            if (empty($by_type['serp_research'])) $missing[]='serp_research';
            if (empty($by_type['competitor_set'])) $missing[]='competitor_set';
            if (empty($by_type['entity_coverage_matrix'])) $missing[]='entity_coverage_matrix';
            if (empty($by_type['question_coverage_matrix'])) $missing[]='question_coverage_matrix';
            if (empty($by_type['topic_coverage_matrix'])) $missing[]='topic_coverage_matrix';
            if (empty($by_type['ux_coverage_matrix'])) $missing[]='ux_coverage_matrix';
            if (empty($by_type['keyword_research'])) $next='mad4b/research-keyword-record';
            elseif (empty($by_type['serp_research'])) $next='mad4b/research-serp-record';
            elseif (empty($by_type['competitor_set'])) $next='mad4b/research-competitor-select';
            else $next='coverage_review_and_blueprint_planning';
        } elseif ($stage==='BLUEPRINT' || $stage==='WRITING') {
            if (empty($by_type['blueprint'])) $missing[]='blueprint';
            if (empty($by_type['final_qa'])) $missing[]='final_qa';
            $next='writer_critic_quality_loop';
        } else {
            $next='stage_specific_workflow_planning';
        }
        $executor=MAD4B_Hub_Execution_Router::status(array('job_id'=>$id));
        if (is_wp_error($executor)) return $executor;
        $terminal=in_array((string)($job['state']??''),array('COMPLETED','CANCELLED','FAILED'),true);
        return array(
            'contract'=>self::CONTRACT,'job_id'=>$id,
            'job_state'=>(string)($job['state']??''),
            'job_stage'=>$stage,'job_revision'=>(int)($job['job_revision']??0),
            'active_artifact_counts'=>$by_type,'active_artifact_ids'=>$ids,
            'missing_evidence'=>$missing,'information_gain'=>$information_gain,
            'next_ability_or_step'=>$terminal?'':$next,
            'continuity_state'=>$terminal?'terminal':($missing?'awaiting_evidence':'needs_next_stage_assessment'),
            'executor'=>(string)($executor['selected_executor']??'unknown'),
            'server_background_execution'=>(bool)($executor['background_ai_ready']??false),
            'automatic_execution_performed'=>false,
            'canonical_release_verified'=>false,
            'distribution_verified'=>false,
            'mutation_performed'=>false
        );
    }
    public static function routes() {
        register_rest_route('mad4b-hub/v1','/control-workflow/operations',array(
            'methods'=>'GET','callback'=>static function($r){return self::jobs($r->get_params());},
            'permission_callback'=>array(__CLASS__,'allowed')));
        register_rest_route('mad4b-hub/v1','/control-workflow/inspect',array(
            'methods'=>'GET','callback'=>static function($r){return self::inspect($r->get_params());},
            'permission_callback'=>array(__CLASS__,'allowed')));
    }
    public static function abilities() {
        if (!function_exists('wp_register_ability')) return;
        foreach (array('mad4b/hub-workflow-operations'=>'jobs',
                       'mad4b/hub-workflow-inspect'=>'inspect') as $name=>$method) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Content Workflow '.$method,
                'description'=>'Authorized site-scoped ContentJob and Artifact readback; no model or provider execution.',
                'category'=>'mad4b-read',
                'execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array(__CLASS__,'allowed'),
                'input_schema'=>array('type'=>'object',
                    'properties'=>$method==='inspect'
                        ?array('job_id'=>array('type'=>'string','minLength'=>36,'maxLength'=>36))
                        :array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100)),
                    'required'=>$method==='inspect'?array('job_id'):array(),
                    'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
            ));
        }
    }
    public static function menu() {
        if (!self::allowed()) return;
        add_submenu_page('mad4b-shared-content-hub','Workflow Operations',
            'Workflow Operations','manage_options','mad4b-hub-workflow-operations',
            array(__CLASS__,'page'));
    }
    public static function page() {
        if (!self::allowed()) wp_die('Forbidden',403);
        $jobs=self::jobs(array('limit'=>50));
        echo '<div class="wrap"><h1>Content Hub — Workflow Operations</h1>';
        echo '<p>Authorized ContentJob readback. No automatic model execution, publishing, or distribution is implied.</p>';
        if (is_wp_error($jobs)) {echo '<p>'.esc_html($jobs->get_error_code()).'</p></div>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Job ID</th><th>State / Stage</th><th>Revision</th><th>Next evidence / action</th></tr></thead><tbody>';
        foreach ($jobs['jobs'] as $job) {
            $status=self::inspect(array('job_id'=>$job['job_id']));
            $next=is_wp_error($status)?$status->get_error_code()
                :(string)$status['next_ability_or_step'];
            $missing=is_wp_error($status)?array():(array)$status['missing_evidence'];
            echo '<tr><td><code>'.esc_html($job['job_id']).'</code></td><td>'
                .esc_html($job['state'].' / '.$job['stage']).'</td><td>'
                .esc_html((string)$job['revision']).'</td><td>'
                .esc_html($next);
            if ($missing) echo '<p><small>Missing: '.esc_html(implode(', ',$missing)).'</small></p>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
