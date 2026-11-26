<?php
/**
 * Plugin Name: Commerce Event Bridge
 * Description: Per-site signed webhook intake and verified event delivery to native WordPress/Bit Flows hooks.
 * Version: 0.2.2
 * Requires PHP: 8.1
 * Requires Plugins: commerce-composition
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** This is a transport adapter; Commerce Composition owns state, queue and approvals. */
final class Commerce_Event_Bridge_V2
{
    public const OPTION = 'commerce_event_bridge_v2';
    public const KEY_OPTION = 'commerce_event_bridge_hmac_key_v2';
    public const EVENT_HOOK = 'commerce_composition_bitflows_event_v1';
    public const REQUEST_HOOK = 'commerce_composition_bitflows_request_created_v1';
    public const CONTACT_HOOK = 'commerce_composition_bitflows_contact_linked_v1';

    /** Generate a unique disabled configuration per installation, never copy QA credentials. */
    public static function installDisabled(): void
    {
        $env=wp_get_environment_type();
        if (!in_array($env,['local','staging'],true)) return;
        if (get_option(self::OPTION,false)===false) {
            add_option(self::OPTION,[
                'enabled'=>false,'environments'=>[$env],
                'site_id'=>bin2hex(random_bytes(16)),
                'key_id'=>bin2hex(random_bytes(8)),
                'bound_site_hash'=>hash('sha256',untrailingslashit((string)get_option('siteurl'))),
                'actor_id'=>0,'form_ids'=>[],'emit_events'=>[]
            ],'','no');
        }
        if (get_option(self::KEY_OPTION,false)===false)
            add_option(self::KEY_OPTION,bin2hex(random_bytes(32)),'','no');
    }

    public static function bootstrap(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('commerce_composition_request_submitted', [self::class, 'onRequestSubmitted'], 40, 2);
        add_action('commerce_composition_contact_linked', [self::class, 'onContactLinked'], 40, 2);
        add_action('action_scheduler_stored_action', [self::class, 'signalWorker'], 30, 1);
    }

    public static function config(): array
    {
        $c = get_option(self::OPTION, []);
        return is_array($c) ? $c : [];
    }

    /** Fail closed. Production rollout is a separate release, never unlocked by copied options. */
    private static function enabled(): bool
    {
        $c = self::config();
        return in_array(wp_get_environment_type(), ['local', 'staging'], true)
            && ($c['enabled'] ?? null) === true
            && in_array(wp_get_environment_type(), (array)($c['environments'] ?? []), true)
            && is_string($c['bound_site_hash'] ?? null)
            && hash_equals(hash('sha256', untrailingslashit((string)get_option('siteurl'))), $c['bound_site_hash'])
            && is_string($c['site_id'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/D', $c['site_id'])
            && is_string($c['key_id'] ?? null)
            && preg_match('/^[a-f0-9]{16}$/D', $c['key_id']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('commerce-orchestration/v2', '/webhook', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'handleWebhook'],
        ]);
    }

    public static function handleWebhook(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!self::enabled() || !is_callable(['\\Commerce\\Composition\\WorkflowJobs','enqueue'])
            || !is_callable(['\\Commerce\\Composition\\SubmissionProvenance','verify']))
            return new \WP_Error('event_bridge_unavailable', 'Gateway disabled', ['status'=>503]);

        $cfg = self::config();
        $body = $request->get_body();
        $time = (string)$request->get_header('x-commerce-timestamp');
        $signature = (string)$request->get_header('x-commerce-signature');
        $keyId = (string)$request->get_header('x-commerce-key-id');
        $secret = get_option(self::KEY_OPTION, '');
        if (!is_string($body) || $body === '' || strlen($body)>2048
            || !is_string($secret) || strlen($secret)<32
            || !preg_match('/^[0-9]{10,11}$/D',$time)
            || abs(time()-(int)$time)>300
            || !hash_equals($cfg['key_id'], $keyId)
            || !preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)
            || !hash_equals('sha256='.hash_hmac('sha256',$time.'.'.$body,$secret),$signature))
            return new \WP_Error('event_bridge_auth_failed','Invalid webhook authentication',['status'=>401]);

        $p = json_decode($body,true);
        if (!is_array($p) || array_is_list($p) || count($p)!==5)
            return new \WP_Error('event_bridge_payload_invalid','Unsupported event',['status'=>422]);
        $keys=array_keys($p);sort($keys,SORT_STRING);
        if ($keys!==['event','event_id','form_id','site_id','submission_id']
            || $p['event']!=='form.submission.saved'
            || !is_string($p['event_id']) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$p['event_id'])
            || !is_string($p['site_id']) || !hash_equals($cfg['site_id'],$p['site_id'])
            || !is_int($p['form_id']) || !is_int($p['submission_id'])
            || $p['submission_id']<1 || !in_array($p['form_id'],(array)($cfg['form_ids']??[]),true))
            return new \WP_Error('event_bridge_payload_invalid','Unsupported event',['status'=>422]);

        $actor=(int)($cfg['actor_id']??0);
        if ($actor<1 || !user_can($actor,'manage_options'))
            return new \WP_Error('event_bridge_actor_unavailable','No authorized actor',['status'=>503]);
        $source=\Commerce\Composition\SubmissionProvenance::verify($p['submission_id'],$p['form_id']);
        if (is_wp_error($source))
            return new \WP_Error('event_bridge_source_unverified','Unverified stored source',['status'=>422]);
        $job=\Commerce\Composition\WorkflowJobs::enqueue(
            'create_request_from_saved_submission',
            ['form_id'=>$p['form_id'],'submission_id'=>$p['submission_id']],
            'verified-form-request:v1:'.$p['form_id'].':'.$p['submission_id'],
            $actor,'event'
        );
        if (is_wp_error($job))
            return new \WP_Error($job->get_error_code(),'Queue refused event',['status'=>422]);
        $response=new \WP_REST_Response([
            'accepted'=>true, 'event_type'=>$p['event'], 'job_id'=>(int)$job['job_id'],
            'queue_status'=>$job['status'],'idempotent_replay'=>(bool)$job['already_exists']
        ],$job['already_exists']?200:202);
        $response->header('Cache-Control','private,no-store');
        return $response;
    }

    /** Read source truth from the saved request; never echo an unverified incoming event. */
    private static function requestEvidence(int $id): array|\WP_Error
    {
        global $wpdb;
        if ($id<1 || !is_callable(['\\Commerce\\Composition\\SubmissionProvenance','verify'])
            || !is_callable(['\\Commerce\\Composition\\RequestCheckout','ready'])
            || !\Commerce\Composition\RequestCheckout::ready())
            return new \WP_Error('event_bridge_registry_unavailable');
        $t=$wpdb->prefix.'commerce_request_batches';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,state,pricing_state,sales_order_id,snapshot_json FROM $t WHERE id=%d", $id
        ),ARRAY_A);
        if (!is_array($row) || $row['state']!=='pending_review'
            || $row['sales_order_id']!==null || $row['pricing_state']!=='not_quoted')
            return new \WP_Error('event_bridge_request_not_eligible');
        $sources=json_decode((string)$row['snapshot_json'],true);
        if (!is_array($sources) || !array_is_list($sources) || !$sources || count($sources)>20)
            return new \WP_Error('event_bridge_invalid_saved_snapshot');
        $verified=[];
        foreach ($sources as $src) {
            if (!is_array($src)) return new \WP_Error('event_bridge_invalid_saved_source');
            $postId=(int)($src['source_id']??0);
            $formId=(int)($src['form_id']??0);
            $submissionId=(int)($src['source_submission_id']??0);
            $postType=get_post_type($postId);
            if ($postId<1 || $formId<1 || $submissionId<1
                || !is_string($postType) || get_post_status($postId)!=='publish')
                return new \WP_Error('event_bridge_saved_source_not_valid');
            $proof=\Commerce\Composition\SubmissionProvenance::verify($submissionId,$formId);
            if (is_wp_error($proof)) return new \WP_Error('event_bridge_saved_submission_unverified');
            $verified[]=[
                'post_id'=>$postId,'post_type'=>$postType,'form_id'=>$formId,
                'submission_id'=>$submissionId,'rate_id'=>(int)($src['rate_id']??0)
            ];
        }
        return [
            'request_id'=>$id,
            'source_count'=>count($verified),
            'source'=>$verified[0], // first verified source; complete source list remains in registry
            'sources'=>$verified
        ];
    }

    private static function emit(array $event, string $specificHook): void
    {
        if (!self::enabled()) return;
        $cfg=self::config();
        if (!in_array($event['event_type'],(array)($cfg['emit_events']??[]),true)) return;
        $envelope=['schema'=>2,'site_id'=>$cfg['site_id'],'event_type'=>$event['event_type'],
            'type'=>$event['event_type'],'source_verified'=>true]+$event;
        // Native Bit Flows WordPress Action Hooks are registered on each request.
        do_action(self::EVENT_HOOK,$envelope);
        do_action($specificHook,$envelope);
    }

    public static function onRequestSubmitted($result,$sources): void
    {
        if (!self::enabled() || !is_array($result) || ($result['status']??null)!=='request_submitted') return;
        $id=(int)($result['request_id']??0);
        $proof=self::requestEvidence($id);
        if (is_wp_error($proof)) return;
        $submitted=[];
        foreach ((array)$sources as $src)
            if (is_array($src) && (int)($src['source_submission_id']??0)>0)
                $submitted[]=(int)$src['source_submission_id'];
        if (array_values(array_unique($submitted))!==array_column($proof['sources'],'submission_id')) return;
        self::emit(['event_type'=>'commerce.request.created']+$proof,self::REQUEST_HOOK);
    }

    public static function onContactLinked($relation,$source): void
    {
        if (!self::enabled() || !is_array($relation) || !in_array($relation['domain']??null,['request','fluent_form'],true)
            || ($relation['role']??null)!=='requester') return;
        $id=(int)($relation['source_id']??0);
        $contactId=(int)($relation['contact_id']??0);
        $sourceKey=(string)($relation['source_key']??'');
        if ($id<1 || $contactId<1 || $sourceKey===''
            || !is_callable(['\\Commerce\\Composition\\ContactBridge','sourceEvidence'])) return;
        $proof=\Commerce\Composition\ContactBridge::sourceEvidence($sourceKey,$id);
        if (is_wp_error($proof) || ($proof['domain']??null)!==$relation['domain']) return;
        global $wpdb;
        $links=$wpdb->prefix.'commerce_contact_links';
        $exists=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $links WHERE contact_id=%d AND source_id=%d AND source_key=%s AND domain=%s AND role='requester' LIMIT 1",
            $contactId,$id,$sourceKey,$relation['domain']
        ));
        if (!$exists) return;
        self::emit([
            'event_type'=>'commerce.contact.linked',
            'request_id'=>$relation['domain']==='request'?$id:0,
            'source'=>['domain'=>$relation['domain'],'source_key'=>$sourceKey,'source_id'=>$id],
            'contact_id'=>$contactId
        ],self::CONTACT_HOOK);
    }

    public static function signalWorker($actionId): void
    {
        if (!self::enabled() || !class_exists('ActionScheduler')) return;
        try {
            $action=\ActionScheduler::store()->fetch_action((int)$actionId);
            if (!$action || $action->get_hook()!=='commerce_composition_process_workflow_job') return;
        } catch (\Throwable $e) { return; }
        $path=WP_CONTENT_DIR.'/commerce-queue.kick';
        if (is_file($path) && is_writable($path)) touch($path);
    }
}
register_activation_hook(__FILE__,[Commerce_Event_Bridge_V2::class,'installDisabled']);
add_action('plugins_loaded',[Commerce_Event_Bridge_V2::class,'bootstrap'],55);
