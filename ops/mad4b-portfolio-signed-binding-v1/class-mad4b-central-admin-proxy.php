<?php
defined('ABSPATH') || exit;

/**
 * A Central WordPress Administrator acts as a disclosed *platform operator*
 * for an enrolled company. Never impersonates that company's owner or manager.
 * Journalled intent is a handoff, not a provider grant or company signature.
 */
final class MAD4B_Central_Admin_Company_Proxy {
    const CONTRACT='mad4b.central-admin-company-proxy.v1';
    const OPTION_PREFIX='mad4b_admin_proxy_intent_v1_';
    const OPERATIONS=[
        'company_operations','company_strategy','content_plan',
        'content_update','seo_plan','seo_update','provider_reconcile',
    ];
    public static function allowed() {
        return MAD4B_Company_Portfolio_Manager_V1::staging()
            && current_user_can('manage_options')
            && current_user_can('activate_plugins');
    }
    private static function error($code) {
        return new WP_Error('mad4b_proxy_'.$code,'Central company representation denied: '.$code,
            ['status'=>409]);
    }
    public static function context($input=[]) {
        if(!self::allowed())return self::error('admin_permission');
        if(!is_array($input))return self::error('invalid_input');
        $code=(string)($input['company_code']??'');
        // No silent normalization that could select the wrong company.
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
            return self::error('company_invalid');
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($binding))return $binding;
        $actor=get_current_user_id();
        return [
            'contract'=>self::CONTRACT.'.context',
            'principal_type'=>'wordpress_user',
            'principal_user_id'=>$actor,
            'representation_mode'=>'central_admin_proxy',
            'represented_party_type'=>'company',
            'company_code'=>$code,
            'company_site_uuid'=>$binding['company_site_uuid'],
            'company_binding_sha256'=>$binding['binding_sha256'],
            'representation_basis'=>'verified_central_platform_administrator',
            'operational_requests_allowed'=>self::OPERATIONS,
            'owner_approval_substituted'=>false,
            'remote_write_authority_granted'=>false,
            'company_ownership_transferred'=>false,
            'identity_impersonation_performed'=>false,
            'provider_specific_permission_required'=>true,
            'mutation_performed'=>false,
        ];
    }
    public static function request($input=[]) {
        if(!self::allowed() || !class_exists('MAD4B_Lifecycle_Orchestrator'))
            return self::error('not_available');
        $ctx=self::context($input);
        if(is_wp_error($ctx))return $ctx;
        $op=(string)($input['operation']??'');
        $reason=(string)($input['reason']??'');
        $key=(string)($input['event_key']??'');
        $expected=(string)($input['expected_binding_sha256']??'');
        if(!in_array($op,self::OPERATIONS,true))return self::error('operation_invalid');
        if(strlen($reason)<12||strlen($reason)>1200||trim($reason)==='')
            return self::error('reason_required');
        if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$key))
            return self::error('event_key_invalid');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
            !hash_equals($ctx['company_binding_sha256'],$expected))
            return self::error('company_binding_stale');
        $event_key='proxy-'.hash('sha256',implode('|',[
            $ctx['principal_user_id'],$ctx['company_code'],$op,$key,$expected,
        ]));
        $event=MAD4B_Lifecycle_Orchestrator::record([
            'goal'=>'company','target_kind'=>'company','target_id'=>0,
            'company_code'=>$ctx['company_code'],'manager_id'=>'',
            'representation_mode'=>'central_admin_proxy',
            'event_type'=>'platform_admin_request',
            'event_key'=>$event_key,
        ]);
        if(is_wp_error($event))return $event;
        $row=[
            'contract'=>self::CONTRACT.'.intent',
            'event_id'=>$event['event_id'],
            'principal_user_id'=>$ctx['principal_user_id'],
            'representation_mode'=>'central_admin_proxy',
            'company_code'=>$ctx['company_code'],
            'company_binding_sha256'=>$expected,
            'operation'=>$op,
            // The reason is journalled only as digest, not stored as company PII.
            'reason_sha256'=>hash('sha256',$reason),
            'requested_at'=>gmdate('c'),
            'native_execution_performed'=>false,
            'owner_approval_substituted'=>false,
        ];
        $option=self::OPTION_PREFIX.$event['event_id'];
        if(!add_option($option,$row,'',false)){
            $existing=get_option($option,false);
            if(!is_array($existing)||($existing['principal_user_id']??null)!==$ctx['principal_user_id']
                ||($existing['company_binding_sha256']??'')!==$expected ||
                ($existing['operation']??'')!==$op ||
                ($existing['reason_sha256']??'')!==$row['reason_sha256'])
                return self::error('idempotency_conflict');
            $row=$existing;
        }
        return [
            'contract'=>self::CONTRACT.'.request',
            'event_id'=>$event['event_id'],
            'idempotent_replay'=>!empty($event['idempotent_replay']),
            'representation'=>$ctx,
            'operation'=>$op,'intent_sha256'=>hash('sha256',wp_json_encode($row)),
            'state'=>'PENDING_NATIVE_AUTHORIZATION',
            'native_execution_performed'=>false,
            'owner_approval_substituted'=>false,
            'handoff'=>'use_original_company_provider_ability_with_same_wp_principal',
            'mutation_performed'=>true,'provider_mutation_performed'=>false,
        ];
    }
    public static function read_intent($input=[]) {
        if(!self::allowed()||!is_array($input))return self::error('admin_permission');
        $id=(string)($input['event_id']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))return self::error('event_invalid');
        $row=get_option(self::OPTION_PREFIX.$id,false);
        if(!is_array($row)||($row['event_id']??'')!==$id)return self::error('intent_missing');
        $ctx=self::context(['company_code'=>$row['company_code']]);
        if(is_wp_error($ctx))return $ctx;
        if(!hash_equals($row['company_binding_sha256'],$ctx['company_binding_sha256']))
            return self::error('company_binding_changed');
        if($row['principal_user_id']!==get_current_user_id())
            return self::error('original_actor_required');
        return ['contract'=>self::CONTRACT.'.readback','intent'=>$row,
            'native_execution_performed'=>false,
            'write_grant_created'=>false];
    }
    public static function admin_menu() {
        if(!MAD4B_Company_Portfolio_Manager_V1::staging())return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Central Administrator Representation','Act for Company',
            'manage_options','mad4b-company-admin-proxy',[__CLASS__,'admin_page']);
    }
    public static function admin_apply() {
        if(!self::allowed())wp_die('Central Administrator required',403);
        check_admin_referer('mad4b_company_admin_proxy_request');
        $raw=wp_unslash($_POST);
        $input=[
            'company_code'=>(string)($raw['company_code']??''),
            'operation'=>(string)($raw['operation']??''),
            'reason'=>sanitize_textarea_field((string)($raw['reason']??'')),
            'event_key'=>(string)($raw['event_key']??''),
            'expected_binding_sha256'=>(string)($raw['expected_binding_sha256']??''),
        ];
        $result=self::request($input);
        $status=is_wp_error($result)?$result->get_error_code():'recorded';
        $params=['page'=>'mad4b-company-admin-proxy','notice'=>$status];
        if(!is_wp_error($result))$params['event_id']=$result['event_id'];
        wp_safe_redirect(add_query_arg($params,admin_url('admin.php')));
        exit;
    }
    public static function admin_page() {
        if(!self::allowed())wp_die('Central Administrator required',403);
        $registry=get_option(MAD4B_Company_Portfolio_Manager_V1::REGISTRY,[]);
        if(!is_array($registry))$registry=[];
        $rows=[];
        foreach(array_keys($registry) as $company){
            $ctx=self::context(['company_code'=>$company]);
            if(!is_wp_error($ctx))$rows[]=$ctx;
        }
        echo '<div class="wrap"><h1>Central Administrator · Act for Company</h1>';
        echo '<p>Use your own WordPress administrator identity as a disclosed platform representative. '
            .'This prepares a governed native-provider handoff; it does not impersonate the company owner, '
            .'replace an owner approval, or directly modify a remote site.</p>';
        if(isset($_GET['notice']))echo '<div class="notice notice-info"><p>'
            .esc_html(sanitize_text_field(wp_unslash($_GET['notice']))).'</p></div>';
        if(isset($_GET['event_id'])) {
            $record=self::read_intent(['event_id'=>(string)wp_unslash($_GET['event_id'])]);
            if(!is_wp_error($record))echo '<p>Recorded intent: <code>'
                .esc_html($record['intent']['event_id']).'</code> · Requires native provider authorization.</p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Binding</th><th>Actual actor</th></tr></thead><tbody>';
        foreach($rows as $ctx)echo '<tr><td>'.esc_html($ctx['company_code']).'</td><td><code>'
            .esc_html(substr($ctx['company_binding_sha256'],0,16)).'…</code></td><td>WordPress User #'
            .(int)$ctx['principal_user_id'].'</td></tr>';
        echo '</tbody></table>';
        foreach($rows as $ctx){
            echo '<h2>'.esc_html($ctx['company_code']).'</h2><form method="post" action="'
                .esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_admin_proxy_request">'
                .'<input type="hidden" name="company_code" value="'.esc_attr($ctx['company_code']).'">'
                .'<input type="hidden" name="expected_binding_sha256" value="'.esc_attr($ctx['company_binding_sha256']).'">'
                .'<input type="hidden" name="event_key" value="'.esc_attr(wp_generate_uuid4()).'">';
            wp_nonce_field('mad4b_company_admin_proxy_request');
            echo '<p><label>Workflow <select name="operation">';
            foreach(self::OPERATIONS as $operation)echo '<option value="'.esc_attr($operation).'">'
                .esc_html(str_replace('_',' ',ucwords($operation,'_'))).'</option>';
            echo '</select></label></p><p><label>Representation purpose (audit digest) '
                .'<textarea name="reason" rows="3" cols="65" minlength="12" maxlength="1200" required></textarea>'
                .'</label></p><p><button class="button button-primary">Record governed handoff</button></p></form>';
        }
        if(!$rows)echo '<p>No verified company site bindings available.</p>';
        echo '</div>';
    }
    public static function routes() {
        if(!MAD4B_Company_Portfolio_Manager_V1::staging())return;
        register_rest_route('mad4b/v1','/central-admin/company-representation',[
            ['methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
                'callback'=>static fn($r)=>rest_ensure_response(self::context($r->get_params()))],
            ['methods'=>'POST',
                'permission_callback'=>['MAD4B_Company_Portfolio_Manager_V1','rest_write_permission'],
                'callback'=>static fn($r)=>rest_ensure_response(self::request((array)$r->get_json_params()))],
        ]);
        register_rest_route('mad4b/v1','/central-admin/company-representation/(?P<event_id>[a-f0-9]{64})',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
            'callback'=>static fn($r)=>rest_ensure_response(self::read_intent($r->get_params()))]);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability')||wp_has_ability('mad4b/central-admin-company-context'))
            return;
        wp_register_ability('mad4b/central-admin-company-context',[
            'label'=>'Central administrator company representation',
            'description'=>'Read-only verified company representation with original WordPress administrator identity.',
            'category'=>'mad4b-read',
            'permission_callback'=>[__CLASS__,'allowed'],
            'execute_callback'=>[__CLASS__,'context'],
            'input_schema'=>['type'=>'object','properties'=>['company_code'=>['type'=>'string']],
                'required'=>['company_code'],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>[
                'type'=>'tool','surface'=>'read','public'=>false],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
    }
    public static function boot() {
        add_action('rest_api_init',[__CLASS__,'routes'],75);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],75);
        add_action('admin_menu',[__CLASS__,'admin_menu'],95);
        add_action('admin_post_mad4b_admin_proxy_request',[__CLASS__,'admin_apply']);
    }
}
MAD4B_Central_Admin_Company_Proxy::boot();
