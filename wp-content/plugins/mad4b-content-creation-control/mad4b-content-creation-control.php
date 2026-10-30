<?php
/**
 * Plugin Name: MAD4B Content Creation Control Bridge
 * Description: Central ContentJob inspection, governed first-party intake, native company assignment and event-scoped review backlog.
 * Version: 0.2.5-seo-cycle-staging
 * Author: MAD4B
 */
defined('ABSPATH') || exit;

// The featured-image editorial publishing guard is lightweight and must be
// present on normal WordPress/REST/WP-CLI/cron requests, not only central MCP.
// Activate only on Staging; Production remains unchanged.
if ( 'staging' === wp_get_environment_type() ) {
    require_once __DIR__ . '/class-mad4b-editorial-featured-image-gate.php';
    MAD4B_Editorial_Featured_Image_Gate::boot();
    require_once __DIR__ . '/class-mad4b-serp-policy.php';
    // This is admin UI only; atomic deployments may stage it after the policy engine.
    // A missing UI file must not turn public site requests into HTTP 500.
    if (is_readable(__DIR__ . '/class-mad4b-serp-policy-admin.php')) {
        require_once __DIR__ . '/class-mad4b-serp-policy-admin.php';
    require_once __DIR__ . '/class-mad4b-seo-content-lifecycle.php';
    require_once __DIR__ . '/class-mad4b-seo-cycle-admin.php';
    }
}


final class MAD4B_Content_Creation_Control_Bridge {
    const CONTRACT = 'mad4b.content-creation-control.v1';
    const REVIEW_OPTION = 'mad4b_content_creation_review_backlog_v1';
    const BOARD_SOURCE = 'mad4b-content-job-review';
    const MAX_REVIEWS = 200;

    public static function boot() {
        if (!self::central()) return;
        add_action('rest_api_init', [__CLASS__, 'routes'], 65);
        add_action('wp_abilities_api_init', [__CLASS__, 'abilities'], 65);
        add_action('mad4b_scp_content_job_created', [__CLASS__, 'on_event'], 75, 1);
        add_action('mad4b_scp_content_job_transitioned', [__CLASS__, 'on_event'], 75, 1);
        add_action('mad4b_scp_content_artifact_created', [__CLASS__, 'on_event'], 75, 1);
        add_action('mad4b_hub_research_candidate_persisted', [__CLASS__, 'on_event'], 75, 1);
    }

    private static function central() {
        if (getenv('MAD4B_CENTRAL_HUB_ENABLED') !== '1' || wp_get_environment_type() !== 'staging') return false;
        if (!class_exists('MAD4B_Content_Hub') || !class_exists('MAD4B_SCP_Content_Jobs')) return false;
        $res = MAD4B_Content_Hub::status_rest();
        $data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
        return is_array($data) && ($data['controller_enabled'] ?? false) === true;
    }
    public static function can_read() { return self::central() && current_user_can('mad4b_manage_company_operations') && current_user_can('edit_posts'); }
    public static function can_write() { return self::central() && current_user_can('mad4b_manage_company_operations') && current_user_can('edit_posts'); }
    private static function err($code, $status = 409) { return new WP_Error('mad4b_creation_'.$code, $code, ['status'=>$status]); }
    private static function uuid($id) { return is_string($id) && function_exists('wp_is_uuid') && wp_is_uuid($id); }
    private static function native_profile_type($type) {
        if (!is_string($type) || strlen($type)<2 || strlen($type)>64 || !preg_match('/^[a-z0-9_-]+$/D',$type)) return false;
        if (in_array($type,['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_global_styles'],true)) return false;
        $object=function_exists('get_post_type_object')?get_post_type_object($type):null;
        if (!$object || empty($object->show_ui) || empty($object->cap->edit_posts)) return false;
        return current_user_can($object->cap->edit_posts);
    }
    private static function read_job($id) {
        if (!self::uuid($id)) return self::err('invalid_job_id', 400);
        $result = MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
        if (is_wp_error($result)) return $result;
        return isset($result['job']) && is_array($result['job']) ? $result['job'] : self::err('job_unavailable', 404);
    }
    private static function active_artifacts($id) {
        $result = MAD4B_SCP_Artifacts::list_artifacts(['job_id'=>$id]);
        if (is_wp_error($result)) return $result;
        $counts = [];
        foreach (($result['items'] ?? []) as $row) {
            if (($row['status'] ?? '') !== 'active') continue;
            $type = (string)($row['artifact_type'] ?? '');
            if ($type === '') continue;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }
    private static function hub_source($id) {
        if (!class_exists('MAD4B_Hub_Canonical_Store')) return ['status'=>'unavailable'];
        global $wpdb;
        $tables = MAD4B_Hub_Canonical_Store::tables();
        $runs = $tables['runs']; $records = $tables['records'];
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.record_key,r.status,r.current_version_sha256 FROM {$runs} u JOIN {$records} r ON r.record_key=u.record_key WHERE u.job_id=%s ORDER BY u.id DESC LIMIT 1",
            $id
        ), ARRAY_A);
        if (!$row) return ['status'=>'not_ingested'];
        return ['status'=>(string)$row['status'], 'record_key'=>(string)$row['record_key'], 'version_sha256'=>(string)$row['current_version_sha256']];
    }
    private static function recipe($job) {
        $target = (string)($job['target_post_type'] ?? '');
        $kind = (string)($job['content_type'] ?? '');
        $read = MAD4B_SCP_Content_Experience_Profiles::profile_status([]);
        $count = (int)($read['count'] ?? 0);
        foreach (($read['profiles'] ?? []) as $p) {
            if ($target === '' || ($p['slug'] ?? '') !== $kind || ($p['post_type'] ?? '') !== $target) continue;
            if (empty($p['enabled']) || empty($p['authority_current']) || empty($p['runtime_post_type_ready'])
                || empty($p['helper_catalog_match']) || !empty($p['migration_required'])) continue;
            return ['ready'=>true,'slug'=>$kind,'target_post_type'=>$target,'configured_profiles'=>$count];
        }
        return ['ready'=>false,'slug'=>'','target_post_type'=>$target,'configured_profiles'=>$count];
    }
    /** Execution Router is authoritative. A client handoff never certifies model output. */
    private static function selected_executor($job = null) {
        $route = class_exists('MAD4B_Hub_Execution_Router')
            ? MAD4B_Hub_Execution_Router::status(is_array($job) ? ['job_id'=>(string)$job['job_id']] : [])
            : new WP_Error('mad4b_hub_executor_router_unavailable');
        if (is_wp_error($route) || !is_array($route)) return [
            'default_executor'=>'mcp_client','selected_executor'=>'unknown',
            'provider'=>'unknown','model'=>'','ready'=>false,'handoff_ready'=>false,
            'client_execution_required'=>true,'model_execution_verified'=>false,
            'reason_codes'=>['native_execution_router_unavailable'],
        ];
        $default = (string)($route['default_executor'] ?? 'mcp_client');
        $selected = (string)($route['selected_executor'] ?? $default);
        $client = in_array($selected, ['mcp_client','chatgpt_client'], true);
        $handoff = $client && class_exists('MAD4B_Hub_Trigger_Bus');
        if ($client) {
            // A routable ChatGPT MCP task is not the same as executed text or a QA artifact.
            return [
                'default_executor'=>$default,'selected_executor'=>$selected,
                'provider'=>'chatgpt_mcp','model'=>'',
                'ready'=>$handoff,'handoff_ready'=>$handoff,
                'client_execution_required'=>true,'model_execution_verified'=>false,
                'reason_codes'=>$handoff ? [] : ['native_mcp_handoff_unavailable'],
            ];
        }
        // Background AI is optional. Only this explicit selection checks Gemini/provider credentials.
        $model = class_exists('MAD4B_SCP_AI_Task_Routing')
            ? MAD4B_SCP_AI_Task_Routing::plan('content_draft') : null;
        $ready = $selected === 'background_ai' && !empty($route['background_ai_ready'])
            && is_array($model) && !empty($model['can_execute']);
        return [
            'default_executor'=>$default,'selected_executor'=>$selected,
            'provider'=>is_array($model) ? (string)($model['provider'] ?? 'background_ai') : 'background_ai',
            'model'=>is_array($model) ? (string)($model['model'] ?? '') : '',
            'ready'=>$ready,'handoff_ready'=>false,'client_execution_required'=>false,
            'model_execution_verified'=>false,
            'reason_codes'=>$ready ? [] : array_values(array_unique(array_merge(
                empty($route['background_ai_ready']) ? ['background_ai_not_enabled_or_runner_missing'] : [],
                is_array($model) ? ($model['reason_codes'] ?? []) : ['background_ai_task_plan_missing']
            ))),
        ];
    }
    /** Dependencies become actionable handoffs, not invented evidence or false approvals. */
    private static function dependency_actions(array $blockers, array $executor) {
        $actions = [];
        $mapping = [
          'required_knowledge_missing'=>['kind'=>'source_reconciliation','next'=>'mad4b/context-pack-preview'],
          'context_not_ready'=>['kind'=>'context_authority_recovery','next'=>'mad4b/context-pack-preview'],
          'writer_profile_unbound'=>['kind'=>'writer_profile_binding','next'=>'resolve_verified_writer_profile'],
          'serp_research_missing'=>['kind'=>'evidence_research','next'=>'mad4b/research-serp-record'],
          'competitor_analysis_missing'=>['kind'=>'evidence_research','next'=>'discover_native_competitor_research'],
          'semantic_recipe_missing'=>['kind'=>'recipe_preflight','next'=>'mad4b/content-creation-profile-plan'],
          'blueprint_missing'=>['kind'=>'content_pipeline','next'=>'mad4b/blueprint-build'],
          'article_draft_missing'=>['kind'=>'content_pipeline','next'=>'mad4b/draft-append'],
          'final_qa_missing'=>['kind'=>'content_pipeline','next'=>'mad4b/qa-bundle-append'],
          'human_or_operator_review_required'=>['kind'=>'company_review','next'=>'mad4b/content-creation-review-sync'],
          'managed_company_owner_missing'=>['kind'=>'company_assignment','next'=>'resolve_managed_company_owner'],
        ];
        foreach($blockers as $code) {
            $entry = $mapping[$code] ?? ['kind'=>'discovery','next'=>'mad4b/content-creation-plan'];
            $actions[] = ['for'=>$code,'kind'=>$entry['kind'],'next'=>$entry['next'],
                'requires_verified_evidence'=>true,'auto_approve'=>false];
        }
        if ($executor['client_execution_required']) $actions[] = [
            'for'=>'client_execution','kind'=>'mcp_client_handoff',
            'next'=>'MAD4B_Hub_Execution_Router::handoff',
            'requires_verified_evidence'=>true,'auto_approve'=>false];
        return $actions;
    }
    private static function stage_owner_group($stage) {
        if ($stage === 'SEO') return 'seo';
        if ($stage === 'MEDIA') return 'media';
        if ($stage === 'PUBLISHING' || $stage === 'POST_PUBLISH') return 'operations';
        return 'content';
    }
    private static function eligible_owner($id) {
        $id = absint($id);
        if (!$id || !class_exists('MAD4B_SCP_Company_Master_Admin') || !MAD4B_SCP_Company_Master_Admin::managedStaff($id)) return false;
        $user = get_userdata($id);
        return $user && user_can($user, 'edit_others_posts');
    }
    private static function configured_owner($stage) {
        if (!class_exists('MAD4B_SCP_Readiness_Workflow_Options')) return 0;
        $cfg = MAD4B_SCP_Readiness_Workflow_Options::settings();
        $group = self::stage_owner_group($stage);
        $id = absint($cfg['owners'][$group] ?? 0);
        if (self::eligible_owner($id)) return $id;
        $role = (string)($cfg['owner_roles'][$group] ?? '');
        if ($role === '') return 0;
        $users = get_users(['role'=>$role,'number'=>2]);
        if (count($users) !== 1) return 0;
        return self::eligible_owner($users[0]->ID) ? (int)$users[0]->ID : 0;
    }
    public static function plan($input = []) {
        if (!self::can_read()) return self::err('read_denied', 403);
        $id = (string)($input['job_id'] ?? '');
        $job = self::read_job($id);
        if (is_wp_error($job)) return $job;
        $artifacts = self::active_artifacts($id);
        if (is_wp_error($artifacts)) return $artifacts;
        $requirements = MAD4B_SCP_Context_Pack::resolve_requirements(['job_id'=>$id]);
        $context = MAD4B_SCP_Context_Pack::preview(['job_id'=>$id]);
        $missing = is_array($context) ? ($context['missing_required_classes'] ?? []) : [];
        $recipe = self::recipe($job);
        $executor = self::selected_executor($job);
        $state = (string)$job['state']; $stage = (string)$job['stage'];
        $blockers = [];
        if (is_wp_error($requirements)) $blockers[] = 'knowledge_requirements_unavailable';
        if (is_wp_error($context)) $blockers[] = 'context_pack_unavailable';
        if (!empty($missing)) $blockers[] = 'required_knowledge_missing';
        if (!is_array($context) || ($context['ready'] ?? false) !== true) $blockers[] = 'context_not_ready';
        if (!empty($job['target_post_type']) && (empty($job['writer_profile_id']) || empty($job['writer_profile_version'])))
            $blockers[] = 'writer_profile_unbound';
        if ($recipe['target_post_type'] !== '' && !$recipe['ready']) $blockers[] = 'semantic_recipe_missing';
        if ($stage === 'INFORMATION_GAIN') {
            foreach (['keyword_research','serp_research'] as $type)
                if (empty($artifacts[$type])) $blockers[] = $type.'_missing';
            // Native Research Intelligence records competitor selection + its linked
            // coverage matrix rather than a legacy competitor_analysis artifact.
            $native_competitor_evidence = !empty($artifacts['competitor_set'])
                && (!empty($artifacts['evidence_coverage_matrix']) || !empty($artifacts['topic_coverage_matrix']));
            if (empty($artifacts['competitor_analysis']) && !$native_competitor_evidence)
                $blockers[] = 'competitor_analysis_missing';
        }
        if (in_array($stage, ['BLUEPRINT_QA','WRITING','FACT_QA','EDITORIAL_QA','SEO','FINAL_QA','DRAFT','PUBLISHING','POST_PUBLISH'], true)
            && empty($artifacts['blueprint'])) $blockers[] = 'blueprint_missing';
        if (in_array($stage, ['FACT_QA','EDITORIAL_QA','SEO','FINAL_QA','DRAFT','PUBLISHING','POST_PUBLISH'], true)
            && empty($artifacts['draft'])) $blockers[] = 'article_draft_missing';
        if (in_array($stage, ['DRAFT','PUBLISHING','POST_PUBLISH'], true) && empty($artifacts['final_qa'])) $blockers[] = 'final_qa_missing';
        $ai_ready = !empty($executor['ready']);
        if (in_array($stage, ['BLUEPRINT','BLUEPRINT_QA','WRITING'], true) && !$ai_ready) $blockers[] = 'content_ai_execution_not_ready';
        if (in_array($state, ['WAITING_REVIEW','BLOCKED','FAILED'], true)) $blockers[] = 'human_or_operator_review_required';
        $owner = self::configured_owner($stage);
        if (in_array($state,['WAITING_REVIEW','BLOCKED','FAILED'],true) && !$owner) $blockers[] = 'managed_company_owner_missing';
        return [
            'contract'=>self::CONTRACT,'job_id'=>$id,'revision'=>(int)$job['job_revision'],
            'state'=>$state,'stage'=>$stage,'language'=>(string)$job['language'],
            'country'=>(string)$job['country'],'content_type'=>(string)$job['content_type'],
            'automation_level'=>(string)$job['automation_level'],'artifacts'=>$artifacts,
            'target_post_type'=>(string)($job['target_post_type']??''),
            'context'=>['required_classes'=>is_array($requirements)?($requirements['required_classes']??[]):[],
                         'missing_required_classes'=>$missing,'ready'=>is_array($context)&&($context['ready']??false)===true&&empty($missing)],
            'recipe'=>$recipe,'hub_source'=>self::hub_source($id),
            'ai'=>$executor,
            'human_review'=>['required'=>in_array($state,['WAITING_REVIEW','BLOCKED','FAILED'],true),
                             'assignee_wp_user_id'=>$owner,'work_group'=>self::stage_owner_group($stage)],
            'blockers'=>array_values(array_unique($blockers)),
            'dependency_actions'=>self::dependency_actions(array_values(array_unique($blockers)), $executor),
            'recommended_action'=>in_array($state,['WAITING_REVIEW','BLOCKED','FAILED'],true)?'assign_review':(!empty($blockers)?'resolve_exact_dependencies':'continue_existing_stage'),
            'publication_authorized'=>false,'distribution_authorized'=>false,'mutation_performed'=>false,
        ];
    }
    public static function on_event($event) {
        if (!self::central() || !is_array($event) || !self::uuid($event['job_id'] ?? null)) return;
        $id = $event['job_id'];
        $job = self::read_job($id);
        if (is_wp_error($job)) return;
        $state = (string)$job['state'];
        $rows = get_option(self::REVIEW_OPTION, []);
        if (!is_array($rows)) $rows = [];
        if (in_array($state, ['CANCELLED','COMPLETED'], true)) unset($rows[$id]);
        elseif (in_array($state, ['WAITING_REVIEW','BLOCKED','FAILED'], true)) {
            $rows[$id] = ['job_id'=>$id,'stage'=>(string)$job['stage'], 'state'=>$state,
                          'revision'=>(int)$job['job_revision'],'owner_id'=>self::configured_owner((string)$job['stage']),
                          'observed_at'=>gmdate('c')];
        } else unset($rows[$id]);
        if (count($rows)>self::MAX_REVIEWS) $rows=array_slice($rows,-self::MAX_REVIEWS,null,true);
        $previous = get_option(self::REVIEW_OPTION, []);
        if ($rows !== (is_array($previous)?$previous:[])) update_option(self::REVIEW_OPTION, $rows, false);
        if (in_array($state, ['WAITING_REVIEW','BLOCKED','FAILED'], true) &&
            self::can_write() && self::configured_owner((string)$job['stage'])) {
            // Only an eligible, explicitly configured company member can receive a native card.
            // This is additive; never modifies the canonical ContentJob state.
            self::sync_review(['job_id'=>$id,'expected_revision'=>(int)$job['job_revision']]);
        }
        // Native event-driven progression: enqueue only a bounded stage handoff.
        // The MCP client executes subsequent evidence/authoring Abilities itself.
        if ($state === 'RUNNING' && self::can_write() &&
            class_exists('MAD4B_Hub_Bulk_Jobs_Queue') &&
            in_array((string)$job['stage'], MAD4B_Hub_Bulk_Jobs_Queue::stages(), true)) {
            $key = 'cc-auto-' . substr(hash('sha256', $id.'|'.(string)$job['stage'].'|'.(int)$job['job_revision']), 0, 52);
            self::stage_resume(['job_id'=>$id,'expected_revision'=>(int)$job['job_revision'],
                'event_key'=>$key,'dry_run'=>false]);
        }
    }
    public static function refresh_job($input = []) {
        if (!self::can_write()) return self::err('write_denied',403);
        $id = (string)($input['job_id']??'');
        $job = self::read_job($id);
        if (is_wp_error($job)) return $job;
        if ((int)($input['expected_revision']??0) !== (int)$job['job_revision'])
            return self::err('revision_changed');
        self::on_event(['job_id'=>$id]);
        return self::plan(['job_id'=>$id]);
    }
    public static function status($input = []) {
        if (!self::can_read()) return self::err('read_denied',403);
        $rows = get_option(self::REVIEW_OPTION, []);
        $rows = is_array($rows)?$rows:[];
        $profiles = MAD4B_SCP_Content_Experience_Profiles::profile_status([]);
        $executor = self::selected_executor();
        $live=[];
        if (class_exists('MAD4B_SCP_Schema')) {
            global $wpdb;
            $tables=MAD4B_SCP_Schema::tables();
            $table=$tables['content_jobs']??'';
            if ($table) {
                $candidates=$wpdb->get_results("SELECT job_id FROM {$table} WHERE state IN ('WAITING_REVIEW','BLOCKED','FAILED') ORDER BY updated_at DESC LIMIT 100",ARRAY_A);
                foreach((array)$candidates as $candidate) {
                    $job=self::read_job((string)$candidate['job_id']);
                    if (is_wp_error($job)) continue;
                    $live[]=['job_id'=>(string)$job['job_id'],'state'=>(string)$job['state'],
                             'stage'=>(string)$job['stage'],'revision'=>(int)$job['job_revision'],
                             'eligible_owner_id'=>self::configured_owner((string)$job['stage'])];
                }
            }
        }
        return ['contract'=>self::CONTRACT,'central'=>true,
          'live_pending_reviews'=>$live,'live_pending_review_count'=>count($live),
          'configured_recipe_profiles'=>(int)($profiles['count']??0),
          'review_backlog'=>array_values($rows),'review_count'=>count($rows),
          'default_executor'=>$executor['default_executor'],
          'selected_executor'=>$executor['selected_executor'],
          'content_draft_executor_ready'=>$executor['ready'],
          'content_draft_executor_handoff_ready'=>$executor['handoff_ready'],
          'content_draft_execution_verified'=>false,
          'content_draft_blockers'=>$executor['reason_codes'],
          'canonical_job_authority'=>'MAD4B_SCP_Content_Jobs',
          'canonical_content_store'=>'MAD4B_Hub_Canonical_Store',
          'company_board_source'=>self::BOARD_SOURCE,'publication_authorized'=>false,
          'mutation_performed'=>false];
    }
    /** Native Board only; never creates a second board or assigns the CLI actor by default. */
    public static function sync_review($input = []) {
        if (!self::can_write()) return self::err('write_denied',403);
        $plan = self::plan($input);
        if (is_wp_error($plan)) return $plan;
        if (!$plan['human_review']['required']) return self::err('job_not_waiting_for_review');
        $id = $plan['job_id']; $stage = $plan['stage'];
        $expected = absint($input['expected_revision'] ?? 0);
        if ($expected !== $plan['revision']) return self::err('job_revision_changed');
        $user = absint($input['assignee_wp_user_id'] ?? 0);
        if (!$user) $user = (int)$plan['human_review']['assignee_wp_user_id'];
        if (!self::eligible_owner($user))
            return ['contract'=>self::CONTRACT,'state'=>'WAITING_OWNER','job_id'=>$id,
                    'work_group'=>$plan['human_review']['work_group'],'assignee_wp_user_id'=>0,
                    'board_task_created'=>false,'execution_performed'=>false];
        if (!class_exists('MAD4B_SCP_Readiness_Workflow_Options') ||
            !class_exists('FluentBoards\\App\\Services\\TaskService')) return self::err('company_board_provider_unavailable',503);
        $cfg = MAD4B_SCP_Readiness_Workflow_Options::settings();
        $board = absint($cfg['board_id'] ?? 0);$stage_id = absint($cfg['stage_id'] ?? 0);
        if (empty($cfg['board_tasks']) || !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($board,$stage_id))
            return self::err('configured_company_board_unavailable');
        global $wpdb;
        $table = $wpdb->prefix.'fbs_tasks';
        $ref = hash('sha256', $id.'|'.$stage);
        $lock = 'mad4b:cc:review:'.get_current_blog_id().':'.substr($ref,0,32);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::err('handoff_busy',423);
        try {
            $job = self::read_job($id);
            if (is_wp_error($job) || (int)($job['job_revision']??0)!==$expected ||
                (string)($job['stage']??'')!==$stage ||
                !in_array($job['state']??'', ['WAITING_REVIEW','BLOCKED','FAILED'],true))
                return self::err('job_changed_before_assignment');
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id,board_id,source,source_id FROM {$table} WHERE board_id=%d AND source=%s AND source_id=%s ORDER BY id ASC LIMIT 1",
                $board,self::BOARD_SOURCE,$ref),ARRAY_A);
            if ($existing) return ['contract'=>self::CONTRACT,'state'=>'ALREADY_LINKED',
                'job_id'=>$id,'board_id'=>$board,'board_task_id'=>(int)$existing['id'],
                // The existing Board assignee may differ from the requested one.
                // Never claim reassignment occurred without native provider readback.
                'requested_assignee_wp_user_id'=>$user,'assignee_wp_user_id'=>null,
                'new_assignment_verified'=>false,'board_task_created'=>false];
            $title='['.$job['state'].'] ContentJob '.substr($id,0,8).' · '.$stage;
            $description='Canonical ContentJob: '.$id."\nStage: ".$stage.
                "\nRevision: ".$expected."\nWork group: ".$plan['human_review']['work_group'].
                "\nReview the exact source artifacts and blocker codes in MAD4B Content Creation Control; closing a board card does not approve QA or publication.";
            try {
                $task=(new \FluentBoards\App\Services\TaskService())->createTask([
                    'title'=>$title,'description'=>$description,'stage_id'=>$stage_id,
                    'source'=>self::BOARD_SOURCE,'source_id'=>$ref,'assignees'=>[$user],
                ],$board);
            } catch (Throwable $e) { return self::err('native_board_provider_write_uncertain',502); }
            $task_id=absint($task->id??0);
            if (!$task_id) return self::err('board_receipt_missing',502);
            $read=$wpdb->get_row($wpdb->prepare("SELECT id,source,source_id,board_id FROM {$table} WHERE id=%d",$task_id),ARRAY_A);
            if (!$read || (int)$read['board_id']!==$board || $read['source']!==self::BOARD_SOURCE || $read['source_id']!==$ref)
                return self::err('board_readback_requires_reconciliation',502);
            $task->load('assignees');
            $assignees=[];
            foreach($task->assignees as $member)$assignees[]=absint($member->ID??$member->id??0);
            if (!in_array($user,$assignees,true)) return self::err('assignee_readback_requires_reconciliation',502);
            return ['contract'=>self::CONTRACT,'state'=>'LINKED','job_id'=>$id,'board_id'=>$board,
              'board_task_id'=>$task_id,'assignee_wp_user_id'=>$user,'new_assignment_verified'=>true,
              'board_task_created'=>true,'source_job_unchanged'=>true,'publication_authorized'=>false];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function intake($input = []) {
        if (!self::can_write()) return self::err('write_denied',403);
        if (!class_exists('MAD4B_Hub_Control_Ingestion')) return self::err('native_intake_unavailable',503);
        $allowed=['operation_key','language','subject'];
        if (!is_array($input) || array_diff(array_keys($input),$allowed)) return self::err('unsupported_intake_fields',400);
        $key=(string)($input['operation_key']??'');
        if (!preg_match('/^[a-zA-Z0-9._:-]{16,120}$/D',$key)) return self::err('operation_key_required',400);
        return MAD4B_Hub_Control_Ingestion::run([
            'operation_key'=>$key,'language'=>(string)($input['language']??'en'),
            'subject'=>(string)($input['subject']??'Central first-party source research intake'),
        ]);
    }
    /** Deterministic, read-only native Content Experience bootstrap proposal. */
    public static function profile_plan($input = []) {
        if (!self::can_read()) return self::err('read_denied',403);
        $type = sanitize_key((string)($input['post_type']??''));
        if (!self::native_profile_type($type)) return self::err('unsupported_profile_type',400);
        if (!class_exists('MAD4B_SCP_Content_Experience_Bootstrap') ||
            !class_exists('MAD4B_SCP_Content_Experience_Profiles')) return self::err('native_recipe_provider_missing',503);
        $proposal = MAD4B_SCP_Content_Experience_Bootstrap::plan(['post_type'=>$type]);
        if (is_wp_error($proposal)) return $proposal;
        $native = $proposal['profile_plan']??null;
        return [
            'contract'=>self::CONTRACT.'.profile-plan.v1', 'post_type'=>$type,
            'state'=>$proposal['state']??'UNKNOWN',
            'profile_slug'=>is_array($native)?($native['profile']['slug']??''):'',
            'native_plan_sha256'=>is_array($native)?($native['plan_sha256']??''):'',
            'apply_supported'=>($proposal['state']??'')==='PROFILE_PROPOSED',
            'native_plan'=>$native, 'warnings'=>$proposal['warnings']??[],
            'mutation_performed'=>false,'publication_authorized'=>false,
        ];
    }
    /** Delegate ONLY to the native plan/apply contract with exact pinned source. */
    public static function profile_apply($input = []) {
        if (!self::can_write()) return self::err('write_denied',403);
        $type=sanitize_key((string)($input['post_type']??''));
        if (!self::native_profile_type($type)) return self::err('unsupported_profile_type',400);
        $expected=(string)($input['expected_plan_sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected)) return self::err('exact_plan_sha_required',400);
        $proposal=MAD4B_SCP_Content_Experience_Bootstrap::plan(['post_type'=>$type]);
        if (is_wp_error($proposal)) return $proposal;
        if (($proposal['state']??'')==='ALREADY_CONFIGURED')
            return ['contract'=>self::CONTRACT,'state'=>'ALREADY_CONFIGURED',
                'post_type'=>$type,'profile_applied'=>false,'mutation_performed'=>false];
        if (($proposal['state']??'')!=='PROFILE_PROPOSED' || !is_array($proposal['apply_input']??null))
            return self::err('native_proposal_not_applicable');
        $native=$proposal['apply_input'];
        if (!hash_equals($expected,(string)($native['plan_sha256']??'')))
            return self::err('native_profile_plan_drift',409);
        $profile=$native['profile']??[];
        if (($profile['post_type']??'')!==$type || ($profile['creation_status']??'')!=='draft'
            || ($profile['live_update_mode']??'')!=='draft_first'
            || !empty($profile['enabled_helpers'])) return self::err('unsafe_profile_proposal',409);
        if (isset($native["profile"]["import_contract"]) && empty($native["profile"]["import_contract"]["configured_explicitly"])) unset($native["profile"]["import_contract"]);
        $r=MAD4B_SCP_Content_Experience_Profiles::profile_apply($native);
        if (is_wp_error($r)) return $r;
        if (($r['applied']??false)!==true) return self::err('native_profile_receipt_missing',502);
        $after=MAD4B_SCP_Content_Experience_Profiles::profile_status([]);
        foreach((array)($after['profiles']??[]) as $row) {
            if (($row['slug']??'')===$type && ($row['post_type']??'')===$type &&
                !empty($row['enabled']) && !empty($row['authority_current']) &&
                !empty($row['runtime_post_type_ready']) && !empty($row['helper_catalog_match']))
                return ['contract'=>self::CONTRACT,'state'=>'PROFILE_ACTIVE',
                    'post_type'=>$type,'profile_revision'=>$row['revision'],
                    'profile_applied'=>true,'readback_verified'=>true,
                    'publications_performed'=>false];
        }
        return self::err('profile_apply_readback_requires_reconciliation',502);
    }
    /** Use existing dynamic-runtime trigger, not a second queue. No model results are synthesized. */
    public static function stage_resume($input = []) {
        if (!self::can_write()) return self::err('write_denied',403);
        if (!class_exists('MAD4B_Hub_Trigger_Bus')) return self::err('native_trigger_unavailable',503);
        $id=(string)($input['job_id']??'');
        $plan=self::plan(['job_id'=>$id]);
        if (is_wp_error($plan)) return $plan;
        $revision=(int)($input['expected_revision']??0);
        if ($revision!==$plan['revision']) return self::err('job_revision_changed');
        if (in_array($plan['state'],['FAILED','BLOCKED','WAITING_REVIEW','CANCELLED','COMPLETED'],true))
            return self::err('job_not_runnable');
        // Missing research evidence is an expected input to a research task, not
        // a reason to prevent research. Context/QA is a hard gate only at the
        // stages where those artifacts must already exist.
        $hard=[];
        $stage=$plan['stage'];
        foreach($plan['blockers'] as $blocker) {
            if (in_array($blocker,['human_or_operator_review_required','managed_company_owner_missing'],true)) $hard[]=$blocker;
            if (in_array($stage,['BLUEPRINT','BLUEPRINT_QA','WRITING','FACT_QA','EDITORIAL_QA','SEO','FINAL_QA','DRAFT','PUBLISHING','POST_PUBLISH'],true)
                && in_array($blocker,['context_pack_unavailable','required_knowledge_missing','context_not_ready','writer_profile_unbound','semantic_recipe_missing'],true)) $hard[]=$blocker;
            if (in_array($stage,['WRITING','FACT_QA','EDITORIAL_QA','SEO','FINAL_QA','DRAFT','PUBLISHING','POST_PUBLISH'],true)
                && in_array($blocker,['blueprint_missing','article_draft_missing','final_qa_missing','content_ai_execution_not_ready'],true)) $hard[]=$blocker;
        }
        $hard=array_values(array_unique($hard));
        if ($hard) return ['contract'=>self::CONTRACT,'state'=>'DEPENDENCY_HOLD','job_id'=>$id,
                'revision'=>$revision,'blockers'=>$hard,
                'queued'=>false,'mutation_performed'=>false];
        $event=(string)($input['event_key']??'');
        if (!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$event)) return self::err('exact_event_key_required',400);
        $dry=$input['dry_run']??true;
        if (!is_bool($dry)) return self::err('invalid_dry_run_type',400);
        $request=['event_key'=>$event,'job_id'=>$id,'stage'=>$plan['stage'],
                 'expected_job_revision'=>$revision,'dry_run'=>$dry];
        return MAD4B_Hub_Trigger_Bus::manual($request);
    }
    /** Read-only dependence chain for audit and owner routing. */
    public static function pipeline($input = []) {
        if (!self::can_read()) return self::err('read_denied',403);
        $plan=self::plan($input);
        if (is_wp_error($plan)) return $plan;
        $a=$plan['artifacts'];
        $steps=[
          ['INTAKE','mad4b/content-job-create',true],
          ['RESEARCH','mad4b/research-keyword-record',!empty($a['keyword_research'])],
          ['SERP','mad4b/research-serp-record',!empty($a['serp_research'])],
          ['CONTEXT','mad4b/context-pack-build',!empty($a['context_pack'])],
          ['BLUEPRINT','mad4b/blueprint-build',!empty($a['blueprint'])],
          ['BLUEPRINT_QA','mad4b/blueprint-qa',!empty($a['blueprint_qa'])],
          ['DRAFT','mad4b/draft-append',!empty($a['draft'])],
          ['FINAL_QA','mad4b/qa-bundle-append',!empty($a['final_qa'])],
          ['HUB_RELEASE','mad4b/hub-canonical-release',($plan['hub_source']['status']??'')==='approved'],
          ['WORDPRESS_DRAFT','mad4b/draft-apply',false],
        ];
        return ['contract'=>self::CONTRACT.'.pipeline.v1','job_id'=>$plan['job_id'],
           'revision'=>$plan['revision'],'current_stage'=>$plan['stage'],
           'current_state'=>$plan['state'],
           'steps'=>array_map(static function($step){return ['stage'=>$step[0],
              'native_ability'=>$step[1],'evidence_present'=>(bool)$step[2]];},$steps),
           'blockers'=>$plan['blockers'],'dependency_actions'=>$plan['dependency_actions'],
           'executor'=>$plan['ai'],'publication_authorized'=>false,
           'mutation_performed'=>false];
    }

    public static function routes() {
        $ns='mad4b/content-creation/v1';
        register_rest_route($ns,'/status',['methods'=>'GET','callback'=>[__CLASS__,'rest_status'],
            'permission_callback'=>[__CLASS__,'can_read']]);
        register_rest_route($ns,'/plan',['methods'=>'GET','callback'=>[__CLASS__,'rest_plan'],
            'permission_callback'=>[__CLASS__,'can_read']]);
        register_rest_route($ns,'/intake',['methods'=>'POST','callback'=>[__CLASS__,'rest_intake'],
            'permission_callback'=>[__CLASS__,'can_write']]);
        register_rest_route($ns,'/refresh-job',['methods'=>'POST','callback'=>[__CLASS__,'rest_refresh'],
            'permission_callback'=>[__CLASS__,'can_write']]);
        register_rest_route($ns,'/review-sync',['methods'=>'POST','callback'=>[__CLASS__,'rest_sync'],
            'permission_callback'=>[__CLASS__,'can_write']]);
        register_rest_route($ns,'/profile-plan',['methods'=>'GET','callback'=>[__CLASS__,'rest_profile_plan'],
            'permission_callback'=>[__CLASS__,'can_read']]);
        register_rest_route($ns,'/profile-apply',['methods'=>'POST','callback'=>[__CLASS__,'rest_profile_apply'],
            'permission_callback'=>[__CLASS__,'can_write']]);
        register_rest_route($ns,'/stage-resume',['methods'=>'POST','callback'=>[__CLASS__,'rest_stage_resume'],
            'permission_callback'=>[__CLASS__,'can_write']]);
        register_rest_route($ns,'/pipeline',['methods'=>'GET','callback'=>[__CLASS__,'rest_pipeline'],
            'permission_callback'=>[__CLASS__,'can_read']]);
    }
    public static function rest_status($request) {return rest_ensure_response(self::status());}
    public static function rest_plan($request) {return rest_ensure_response(self::plan(['job_id'=>(string)$request->get_param('job_id')]));}
    public static function rest_intake($request) {return rest_ensure_response(self::intake((array)$request->get_json_params()));}
    public static function rest_sync($request) {return rest_ensure_response(self::sync_review((array)$request->get_json_params()));}
    public static function rest_refresh($request) {return rest_ensure_response(self::refresh_job((array)$request->get_json_params()));}
    public static function rest_profile_plan($request) {return rest_ensure_response(self::profile_plan(['post_type'=>(string)$request->get_param('post_type')]));}
    public static function rest_profile_apply($request) {return rest_ensure_response(self::profile_apply((array)$request->get_json_params()));}
    public static function rest_stage_resume($request) {return rest_ensure_response(self::stage_resume((array)$request->get_json_params()));}
    public static function rest_pipeline($request) {return rest_ensure_response(self::pipeline(['job_id'=>(string)$request->get_param('job_id')]));}
    public static function abilities() {
        if (!function_exists('wp_register_ability')) return;
        $specs=[
            'mad4b/content-creation-status'=>['status',true,[]],
            'mad4b/content-creation-plan'=>['plan',true,['job_id']],
            'mad4b/content-creation-intake'=>['intake',false,['operation_key']],
            'mad4b/content-creation-refresh-job'=>['refresh_job',false,['job_id','expected_revision']],
            'mad4b/content-creation-review-sync'=>['sync_review',false,['job_id','expected_revision']],
            'mad4b/content-creation-profile-plan'=>['profile_plan',true,['post_type']],
            'mad4b/content-creation-profile-apply'=>['profile_apply',false,['post_type','expected_plan_sha256']],
            'mad4b/content-creation-stage-resume'=>['stage_resume',false,['job_id','expected_revision','event_key']],
            'mad4b/content-creation-pipeline'=>['pipeline',true,['job_id']],
        ];
        foreach($specs as $name=>$spec) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            $properties=['job_id'=>['type'=>'string'],'expected_revision'=>['type'=>'integer'],
                         'assignee_wp_user_id'=>['type'=>'integer'],'operation_key'=>['type'=>'string'],
                         'language'=>['type'=>'string'],'subject'=>['type'=>'string'],
                         'post_type'=>['type'=>'string'],'expected_plan_sha256'=>['type'=>'string'],
                         'event_key'=>['type'=>'string'],
                         'dry_run'=>['type'=>'boolean']];
            wp_register_ability($name,[
                'label'=>$name,'description'=>'Central ContentJob control using existing domain owner services.',
                'category'=>$spec[1]?'mad4b-read':'mad4b-write',
                'execute_callback'=>[__CLASS__,$spec[0]],
                'permission_callback'=>[__CLASS__,$spec[1]?'can_read':'can_write'],
                'input_schema'=>['type'=>'object','properties'=>$properties,'required'=>$spec[2],
                                 'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$spec[1]?'read':'write'],
                    'annotations'=>['readonly'=>$spec[1],'destructive'=>false,'idempotent'=>$spec[1]]],
            ]);
        }
    }
}
add_action('plugins_loaded', ['MAD4B_Content_Creation_Control_Bridge','boot'], 105);
add_action('plugins_loaded', ['MAD4B_SERP_Presentation_Policy','boot'], 108);
if (class_exists('MAD4B_SERP_Policy_Admin', false)) add_action('plugins_loaded', ['MAD4B_SERP_Policy_Admin','boot'], 109);
add_action('plugins_loaded', ['MAD4B_SEO_Content_Lifecycle','boot'], 110);
add_action('plugins_loaded', ['MAD4B_SEO_Cycle_Admin','boot'], 111);
