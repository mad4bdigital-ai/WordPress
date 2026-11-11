<?php
/**
 * Plugin Name: MAD4B Hub Auto Distribute (Staging)
 * Description: Connect -> Select -> Enable automatic PRIVATE reference distribution.
 * Version: 0.1.0
 *
 * Never publishes consumer WordPress posts, public JetEngine endpoints or prices.
 * Uses the existing Content Hub source records; no custom P-256 or HMAC protocol.
 */
if (!defined('ABSPATH')) { exit; }
final class MAD4B_Hub_Auto_Distribute_V1 {
    const OPTIONS = 'mad4b_hub_auto_distribute_v1';
    const STATE = 'mad4b_hub_auto_distribute_state_v1';
    const EVENT = 'mad4b_hub_auto_distribute_tick';
    const SOURCE = 'tourism-almallahgroup';
    const KINDS = ['tour', 'property', 'transportation', 'nile_tour', 'nile_category'];
    const MODES = ['import_to_hub', 'reference_only'];
    const TRANSPORTS = ['wp_rest_application_password','wp_cli_cross_ssh'];
    const POST_TYPES = ['tours-and-activities', 'properties', 'transportations'];
    const LANGUAGES = ['en', 'ar', 'fr', 'de', 'it', 'es'];

    public static function enabled(): bool {
        return class_exists('MAD4B_Central_Simple_Connections_V1') &&
            MAD4B_Central_Simple_Connections_V1::enabled();
    }
    public static function admin(): bool { return self::enabled() && current_user_can('manage_options'); }
    private static function fail($code, $status=400) {
        return new WP_Error('mad4b_hub_auto_' . $code, 'Auto distribution request rejected.', ['status'=>$status]);
    }
    public static function settings(): array {
        $raw = get_option(self::OPTIONS, []);
        return is_array($raw) ? array_merge([
            'enabled'=>false, 'consumers'=>[], 'kinds'=>[], 'languages'=>[],
            'selected_source_ids'=>[], 'auto_include_new'=>false,
            'source_mode'=>'import_to_hub', 'source_post_types'=>['tours-and-activities'],
            'source_transport'=>'wp_rest_application_password',
        ], $raw) : ['enabled'=>false,'consumers'=>[],'kinds'=>[],'languages'=>[],
            'selected_source_ids'=>[], 'auto_include_new'=>false,
            'source_mode'=>'import_to_hub', 'source_post_types'=>['tours-and-activities'],
            'source_transport'=>'wp_rest_application_password'];
    }
    public static function normalize($input) {
        if (!is_array($input) || array_diff(array_keys($input),
            ['enabled','consumers','kinds','languages','selected_source_ids','auto_include_new','source_mode','source_post_types','source_transport'])) {
            return self::fail('invalid_settings');
        }
        foreach (['enabled','auto_include_new'] as $bool) {
            if (!is_bool($input[$bool] ?? null)) return self::fail('invalid_boolean');
        }
        $mode=$input['source_mode']??'import_to_hub';
        if(!is_string($mode) || !in_array($mode,self::MODES,true))return self::fail('invalid_mode');
        $transport=$input['source_transport']??'wp_rest_application_password';
        if(!is_string($transport)||!in_array($transport,self::TRANSPORTS,true))return self::fail('invalid_transport');
        $types=$input['source_post_types']??['tours-and-activities'];
        if(!is_array($types) || !$types || array_values($types)!==$types ||
            count($types)>3 || count($types)!==count(array_unique($types)))return self::fail('invalid_post_types');
        foreach($types as $type)if(!is_string($type)||!in_array($type,self::POST_TYPES,true))
            return self::fail('invalid_post_types');
        $known = array_keys(MAD4B_Central_Company_REST_V1::COMPANIES);
        $consumerAllowed = array_values(array_diff($known,[self::SOURCE]));
        $selection = [];
        foreach (['consumers'=>$consumerAllowed,'kinds'=>self::KINDS,'languages'=>self::LANGUAGES] as $key=>$allowed) {
            if (!is_array($input[$key] ?? null) || count($input[$key]) > 10) return self::fail('invalid_selection');
            $values = $input[$key];
            if (array_values($values) !== $values || count($values) !== count(array_unique($values))) {
                return self::fail('duplicate_selection');
            }
            foreach ($values as $value) {
                if (!is_string($value) || !in_array($value,$allowed,true)) return self::fail('unknown_selection');
            }
            $selection[$key] = $values;
        }
        $ids = $input['selected_source_ids'] ?? null;
        if (!is_array($ids) || count($ids) > 150 || array_values($ids)!==$ids) {
            return self::fail('invalid_source_ids');
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id<=0 || isset($seen[$id])) return self::fail('invalid_source_ids');
            $seen[$id]=true;
        }
        if ($input['enabled'] && (!$selection['kinds'] || !$selection['languages'] ||
            ($mode==='reference_only' && !$selection['consumers']) ||
            ($selection['consumers'] && !$ids && !$input['auto_include_new']))) {
            return self::fail('incomplete_enabled_policy');
        }
        return [
            'enabled'=>$input['enabled'],
            'consumers'=>$selection['consumers'],
            'kinds'=>$selection['kinds'],
            'languages'=>$selection['languages'],
            'selected_source_ids'=>$ids,
            'auto_include_new'=>$input['auto_include_new'],
            'source_mode'=>$mode,'source_post_types'=>$types,'source_transport'=>$transport
        ];
    }
    public static function configure(WP_REST_Request $request) {
        if (!self::admin()) return self::fail('forbidden',403);
        $normalized=self::normalize($request->get_json_params());
        if (is_wp_error($normalized))return $normalized;
        update_option(self::OPTIONS,$normalized,false);
        self::schedule();
        return new WP_REST_Response(['saved'=>true,'settings'=>$normalized],200);
    }
    public static function status() {
        return new WP_REST_Response([
            'contract'=>'mad4b.hub.auto-distribute.v1',
            'settings'=>self::settings(),
            'state'=>get_option(self::STATE,[]),
            'schedule_active'=>(bool)wp_next_scheduled(self::EVENT),
            'content_hub_only'=>true,
            'consumer_public_listing_active'=>false,
            'commercial_rates_distributed'=>false
        ],200);
    }
    private static function eligible_references(array $policy) {
        if (!post_type_exists('mad4b_hub_nile')) return self::fail('hub_unavailable',409);
        $ids=get_posts([
            'post_type'=>'mad4b_hub_nile','post_status'=>'publish',
            'numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true
        ]);
        if (!is_array($ids) || count($ids)>5000)return self::fail('hub_read_failed',503);
        $selected = array_fill_keys($policy['selected_source_ids'], true);
        $out=[];$seen=[];
        foreach ($ids as $hubId) {
            $hubId=(int)$hubId;
            if (!$policy['auto_include_new'] && !isset($selected[$hubId]))continue;
            $kind=(string)get_post_meta($hubId,'_mad4b_hub_source_kind',true);
            $company=(string)get_post_meta($hubId,'_mad4b_hub_source_company',true);
            if ($company!==self::SOURCE || !in_array($kind,$policy['kinds'],true))continue;
            if ((string)get_post_meta($hubId,'_mad4b_hub_publication_state',true)!=='published_content_hub' ||
                (string)get_post_meta($hubId,'_mad4b_hub_content_owned_by',true)!==self::SOURCE) {
                return self::fail('source_record_not_verified',409);
            }
            $json=(string)get_post_meta($hubId,'_mad4b_hub_source_payload_json',true);
            $stored=(string)get_post_meta($hubId,'_mad4b_hub_source_sha256',true);
            if (!$json || !preg_match('/^[a-f0-9]{64}$/D',$stored) ||
                !hash_equals($stored,hash('sha256',$json)))return self::fail('source_digest_mismatch',409);
            $data=json_decode($json,true);
            if (!is_array($data) || ($data['source_company']??'')!==self::SOURCE) {
                return self::fail('source_identity_mismatch',409);
            }
            $language=$kind==='nile_tour' ?
                ($data['translation_source']['language_code']??'') : ($data['source_language_code']??'');
            if (!in_array($language,$policy['languages'],true))continue;
            $sourceId=(int)get_post_meta($hubId,'_mad4b_hub_source_id',true);
            $expectedId=$kind==='nile_tour'?($data['source_tour_id']??null):($data['source_term_id']??null);
            if ($sourceId<1 || !is_int($expectedId) || $sourceId!==$expectedId) {
                return self::fail('source_id_mismatch',409);
            }
            $key=$kind.':'.$language.':'.$sourceId;
            if (isset($seen[$key]))return self::fail('duplicate_source_record',409);
            $seen[$key]=true;
            $title=$kind==='nile_tour'?($data['title']??''):($data['name']??'');
            $summary=$kind==='nile_tour'?($data['summary']??''):'';
            if (!is_string($title) || $title==='' || !is_string($summary))return self::fail('invalid_source_text',409);
            $out[]=[
                'source_company'=>self::SOURCE,'kind'=>$kind,'language'=>$language,
                'source_id'=>$sourceId,'title'=>sanitize_text_field(mb_substr($title,0,240)),
                'summary'=>sanitize_textarea_field(mb_substr($summary,0,450)),
                'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false
            ];
            if (count($out)>150)return self::fail('too_many_records',409);
        }
        if (!$policy['auto_include_new'] && count($out)!==count($selected)) {
            // Explicit selection must not silently omit a stale, blocked or foreign record.
            return self::fail('selected_source_missing',409);
        }
        usort($out, static function($a,$b) {
            return strcmp($a['kind'].':'.$a['language'].':'.sprintf('%010d',$a['source_id']),
                $b['kind'].':'.$b['language'].':'.sprintf('%010d',$b['source_id']));
        });
        return $out;
    }
    public static function distribute() {
        if (!self::enabled()) return self::fail('central_unavailable',403);
        $policy=self::settings();
        if (!$policy['enabled'])return self::fail('not_enabled',409);
        $valid=self::normalize($policy);
        if (is_wp_error($valid))return $valid;
        $import=null;
        if($valid['source_mode']==='import_to_hub') {
            if(!class_exists('MAD4B_Hub_Source_Import_V1'))return self::fail('importer_unavailable',409);
            if($valid['source_transport']==='wp_cli_cross_ssh')
                return self::fail('cross_ssh_run_on_vps_host_only',409);
            $import=MAD4B_Hub_Source_Import_V1::import(self::SOURCE,$valid['source_post_types']);
            if(is_wp_error($import))return $import;
            $items=$valid['consumers'] ? MAD4B_Hub_Source_Import_V1::active_items(self::SOURCE,
                $valid['kinds'],$valid['languages'],$valid['selected_source_ids'],$valid['auto_include_new']) : [];
        } else $items=self::eligible_references($valid);
        if (!$valid['consumers'] && $valid['source_mode']==='import_to_hub' && !is_wp_error($import)) {
            $state=[
                'last_run_at'=>gmdate('c'),
                'last_successful_consumers'=>[],
                'failed_consumer_ids'=>[],
                'source_reference_count'=>(int)($import['imported']??0),
                'snapshot_sha256'=>(string)($import['snapshot_sha256']??''),
                'status'=>'imported_only',
                'source_mode'=>'import_to_hub',
                'hub_import'=>$import
            ];
            update_option(self::STATE,$state,false);
            return $state;
        }
        if (is_wp_error($items))return $items;
        $digest=hash('sha256',wp_json_encode($items,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $success=[];$failures=[];
        foreach ($valid['consumers'] as $company) {
            $snapshot=[
                'contract'=>'mad4b.hub.private-reference-snapshot.v1',
                'source_company'=>self::SOURCE,'consumer_company'=>$company,
                'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
                'items'=>$items,'snapshot_sha256'=>$digest
            ];
            $result=MAD4B_Central_Simple_Connections_V1::deliver_references($company,$snapshot);
            if (is_wp_error($result))$failures[]=$company;
            else $success[]=['company_id'=>$company,'stored_items'=>$result['stored_items']??count($items)];
        }
        $state=[
            'last_run_at'=>gmdate('c'),
            'last_successful_consumers'=>$success,
            'failed_consumer_ids'=>$failures,
            'source_reference_count'=>count($items),
            'snapshot_sha256'=>$digest,
            'status'=>!$failures?($valid['consumers']?'success':'imported_only'):
                (!empty($success)?'partial':'failed'),
            'source_mode'=>$valid['source_mode'],
            'hub_import'=>$import
        ];
        update_option(self::STATE,$state,false);
        return $state;
    }
    public static function cron(): void { if (self::enabled() && self::settings()['enabled'])self::distribute(); }
    public static function cron_intervals($schedules) {
        $schedules['mad4b_15_minutes']=['interval'=>900,'display'=>'MAD4B every 15 minutes'];
        return $schedules;
    }
    public static function schedule(): void {
        if (!self::enabled())return;
        if (self::settings()['enabled'] && self::settings()['source_transport']!=='wp_cli_cross_ssh') {
            if (!wp_next_scheduled(self::EVENT))wp_schedule_event(time()+60,'mad4b_15_minutes',self::EVENT);
        } elseif (wp_next_scheduled(self::EVENT))wp_clear_scheduled_hook(self::EVENT);
    }
    public static function rest_run() {
        if (!self::admin())return self::fail('forbidden',403);
        $state=self::distribute();
        return is_wp_error($state)?$state:new WP_REST_Response($state,200);
    }
    public static function register_rest() {
        register_rest_route('mad4b-central/v1','/hub-auto-distribute',[
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>[__CLASS__,'admin'],'callback'=>[__CLASS__,'status']
        ]);
        register_rest_route('mad4b-central/v1','/hub-auto-distribute/settings',[
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>[__CLASS__,'admin'],'callback'=>[__CLASS__,'configure']
        ]);
        register_rest_route('mad4b-central/v1','/hub-auto-distribute/run',[
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>[__CLASS__,'admin'],'callback'=>[__CLASS__,'rest_run']
        ]);
    }
    public static function menu() {
        $parent=post_type_exists('mad4b_hub_nile')?'edit.php?post_type=mad4b_hub_nile':'tools.php';
        add_submenu_page($parent,'Content Hub Auto Distribute','Auto Distribute',
            'manage_options','mad4b-hub-auto-distribute',[__CLASS__,'screen']);
    }
    public static function screen() {
        if (!self::admin())return;
        $policy=self::settings();
        $state=get_option(self::STATE,[]);
        echo '<div class="wrap"><h1>Content Hub — Auto Distribute</h1>';
        echo '<p>Default: pull source content into Content Hub, then optionally distribute private previews every 15 minutes. No public publishing, bookings or prices.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_hub_auto_save');
        echo '<input type="hidden" name="action" value="mad4b_hub_auto_save">';
        echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($policy['enabled'],true,false).'> Enable auto distribution</label></p>';
        echo '<h2>Source → Content Hub</h2><p>Default: import selected published source content into private Content Hub records. Importing does not publish or enable checkout.</p>';
        echo '<p><label>Ingestion mode <select name="source_mode">';
        foreach(self::MODES as $mode)echo '<option value="'.esc_attr($mode).'" '.
            selected($policy['source_mode'],$mode,false).'>'.esc_html($mode==='import_to_hub'?'Import into Content Hub (default)':'Use existing Content Hub references').'</option>';
        echo '</select></label></p><p><label>Source connection <select name="source_transport">';
        foreach(self::TRANSPORTS as $transport) echo '<option value="'.esc_attr($transport).'" '.selected($policy['source_transport'],$transport,false).'>'.
            esc_html($transport==='wp_cli_cross_ssh'?'WP-CLI Cross SSH (VPS runner, pinned host key, no App Password)':'WordPress REST (Application Password)').'</option>';
        echo '</select></label></p><p>SSH mode runs from the VPS host, not from WordPress or WP-Cron. No SSH key is stored in this site. Import and distribution remain separate; enabling this selection never executes SSH from PHP.</p><p>Content types to fetch</p>';
        foreach(self::POST_TYPES as $type)echo '<label style="margin-right:20px"><input type="checkbox" name="source_post_types[]" value="'.
            esc_attr($type).'" '.checked(in_array($type,$policy['source_post_types'],true),true,false).'> '.esc_html($type).'</label>';
        echo '<h2>To companies (optional for import-only)</h2>';
        foreach (MAD4B_Central_Company_REST_V1::COMPANIES as $id=>$company) {
            if($id===self::SOURCE)continue;
            echo '<p><label><input type="checkbox" name="consumers[]" value="'.esc_attr($id).'" '.
                checked(in_array($id,$policy['consumers'],true),true,false).'> '.esc_html($company['name']).'</label></p>';
        }
        echo '<h2>Content types</h2>';
        foreach(self::KINDS as $kind) echo '<label style="margin-right:20px"><input type="checkbox" name="kinds[]" value="'.
            esc_attr($kind).'" '.checked(in_array($kind,$policy['kinds'],true),true,false).'> '.esc_html($kind).'</label>';
        echo '<h2>Languages</h2>';
        foreach(self::LANGUAGES as $lang) echo '<label style="margin-right:20px"><input type="checkbox" name="languages[]" value="'.
            esc_attr($lang).'" '.checked(in_array($lang,$policy['languages'],true),true,false).'> '.esc_html(strtoupper($lang)).'</label>';
        echo '<p><label><input type="checkbox" name="auto_include_new" value="1" '.
            checked($policy['auto_include_new'],true,false).'> Automatically include new matching Content Hub records</label></p>';
        echo '<p><label>Selected Content Hub post IDs (comma-separated; used when auto-include is off)<br><input type="text" class="regular-text" name="selected_source_ids" value="'.
            esc_attr(implode(',',$policy['selected_source_ids'])).'"></label></p>';
        submit_button('Save Distribution Settings');
        echo '</form><hr>';
        if($policy['source_transport']==='wp_cli_cross_ssh') {
            echo '<p><strong>WP-CLI Cross SSH:</strong> Import runs from the VPS host using the pinned registered SSH target. Use the installed <code>run-tourism-cross-ssh.sh</code> command, not a browser or WP-Cron. The site stores no SSH key. This imports privately and does not publish or deliver content.</p>';
        } else {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_hub_auto_run');
            echo '<input type="hidden" name="action" value="mad4b_hub_auto_run">';
            submit_button('Run Distribution Now','secondary');
            echo '</form>';
        }
        echo '<h2>Last Run</h2><pre>'.esc_html(wp_json_encode($state,JSON_PRETTY_PRINT)).'</pre></div>';
    }
    private static function redirect_screen($message) {
        $parent=post_type_exists('mad4b_hub_nile')?'edit.php?post_type=mad4b_hub_nile':'tools.php';
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-hub-auto-distribute','result'=>$message],
            admin_url($parent)));exit;
    }
    public static function save_form() {
        if (!self::admin() || !check_admin_referer('mad4b_hub_auto_save'))wp_die('Forbidden');
        $ids=[];
        $raw=isset($_POST['selected_source_ids'])?sanitize_text_field(wp_unslash($_POST['selected_source_ids'])):'';
        if ($raw!=='') {
            foreach(explode(',',$raw) as $part) {
                $part=trim($part);
                if(!ctype_digit($part)||strlen($part)>10){self::redirect_screen('invalid_ids');}
                $ids[]=(int)$part;
            }
        }
        $input=[
            'enabled'=>!empty($_POST['enabled']),
            'auto_include_new'=>!empty($_POST['auto_include_new']),
            'consumers'=>isset($_POST['consumers'])&&is_array($_POST['consumers'])?array_map('sanitize_key',wp_unslash($_POST['consumers'])):[],
            'kinds'=>isset($_POST['kinds'])&&is_array($_POST['kinds'])?array_map('sanitize_key',wp_unslash($_POST['kinds'])):[],
            'languages'=>isset($_POST['languages'])&&is_array($_POST['languages'])?array_map('sanitize_key',wp_unslash($_POST['languages'])):[],
            'selected_source_ids'=>$ids,
            'source_mode'=>isset($_POST['source_mode'])?sanitize_key(wp_unslash($_POST['source_mode'])):'import_to_hub',
            'source_transport'=>isset($_POST['source_transport'])?sanitize_key(wp_unslash($_POST['source_transport'])):'wp_rest_application_password',
            'source_post_types'=>isset($_POST['source_post_types'])&&is_array($_POST['source_post_types'])?
                array_map('sanitize_key',wp_unslash($_POST['source_post_types'])):['tours-and-activities']
        ];
        $out=self::normalize($input);
        if(is_wp_error($out))self::redirect_screen($out->code);
        update_option(self::OPTIONS,$out,false);
        self::schedule();
        self::redirect_screen('saved');
    }
    public static function run_form() {
        if(!self::admin()||!check_admin_referer('mad4b_hub_auto_run'))wp_die('Forbidden');
        $result=self::distribute();
        self::redirect_screen(is_wp_error($result)?$result->code:$result['status']);
    }
    public static function boot(): void {
        if (!self::enabled())return;
        add_filter('cron_schedules',[__CLASS__,'cron_intervals']);
        add_action('init',[__CLASS__,'schedule'],50);
        add_action(self::EVENT,[__CLASS__,'cron']);
        add_action('rest_api_init',[__CLASS__,'register_rest']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_mad4b_hub_auto_save',[__CLASS__,'save_form']);
        add_action('admin_post_mad4b_hub_auto_run',[__CLASS__,'run_form']);
    }
}
MAD4B_Hub_Auto_Distribute_V1::boot();
