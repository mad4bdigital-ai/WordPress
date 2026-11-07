<?php
/**
 * Plugin Name: MAD4B Company Policy REST v2
 * Description: Authenticated provider discovery, scoped policy write/read, CAS revisions, simulation and rollback.
 * Version: 2.3.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Policy_REST_V2 {
    private const NS = 'mad4b-policy/v2';
    private const OPTION = 'mad4b_company_policy_registry_v2';
    private const MAX_BODY_BYTES = 16384;
    private const DIAGNOSTICS_KEY = 'mad4b_company_policy_rest_simulations_v2';
    private const RULE_KEYS = [
        'id','provider_id','scope','scope_id','conditions','values',
        'priority','state','valid_from','valid_to'
    ];

    public static function can_read(): bool {
        return current_user_can('manage_options');
    }
    public static function can_write(): bool {
        // Do not grant writes through a broad summary-permission filter or
        // through central-company registration alone.
        return current_user_can('manage_options');
    }
    private static function problem(string $code, int $status=400, string $message='Policy request rejected'): WP_Error {
        return new WP_Error($code, $message, ['status'=>$status]);
    }
    private static function registry_ready(): bool {
        return class_exists('MAD4B_Company_Policy_Registry_V2');
    }
    private static function body(WP_REST_Request $request): array|WP_Error {
        $raw=$request->get_json_params();
        if(!is_array($raw) || array_is_list($raw))
            return self::problem('policy_json_object_required',400);
        $json=wp_json_encode($raw);
        if(!is_string($json) || strlen($json)>self::MAX_BODY_BYTES)
            return self::problem('policy_body_too_large',413);
        return $raw;
    }
    private static function permitted_keys(array $raw, array $keys): bool {
        return !array_diff(array_keys($raw),$keys);
    }
    private static function target_check(array $body): ?WP_Error {
        if(array_key_exists('target_origin',$body)) {
            if(!is_string($body['target_origin']) ||
               untrailingslashit($body['target_origin'])!==untrailingslashit(home_url('/')))
                return self::problem('policy_target_origin_mismatch',409);
        }
        if(array_key_exists('target_site_uuid',$body)) {
            $profile=class_exists('MAD4B_SCP_Site_Profile')
                ?MAD4B_SCP_Site_Profile::status():[];
            if(!is_array($profile) || empty($profile['site_uuid']) ||
               !is_string($body['target_site_uuid']) ||
               $body['target_site_uuid']!==$profile['site_uuid'])
                return self::problem('policy_target_site_uuid_mismatch',409);
        }
        return null;
    }
    private static function number(array $body,string $key): int|WP_Error {
        if(!array_key_exists($key,$body) || !is_int($body[$key]) || $body[$key]<0)
            return self::problem('policy_'.$key.'_required',428,'Provide an integer revision precondition');
        return $body[$key];
    }
    private static function current_raw(): array {
        global $wpdb;
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::OPTION));
        if($raw===null)return ['exists'=>false,'raw'=>null,'store'=>[
            'schema'=>2,'revision'=>0,'rules'=>[],'history'=>[],'epochs'=>[]
        ]];
        $decoded=maybe_unserialize($raw);
        if(!is_array($decoded))return ['exists'=>true,'raw'=>$raw,'corrupt'=>true];
        return ['exists'=>true,'raw'=>$raw,'store'=>array_replace([
            'schema'=>2,'revision'=>0,'rules'=>[],'history'=>[],'epochs'=>[]
        ],$decoded)];
    }
    private static function validate_shape(array $input,bool $isPatch): ?WP_Error {
        $keys=array_merge(self::RULE_KEYS,[
            'expected_registry_revision','expected_rule_revision','target_origin','target_site_uuid'
        ]);
        if(!self::permitted_keys($input,$keys))return self::problem('policy_unknown_fields',422);
        if(isset($input['conditions']) &&
           (!is_array($input['conditions']) || array_is_list($input['conditions'])))
            return self::problem('policy_conditions_object_required',422);
        if(isset($input['values']) &&
           (!is_array($input['values']) || array_is_list($input['values'])))
            return self::problem('policy_values_object_required',422);
        if(!$isPatch && (empty($input['provider_id']) || !isset($input['values'])))
            return self::problem('policy_provider_values_required',422);
        if(isset($input['values']) && count($input['values'])>32)
            return self::problem('policy_values_limit',422);
        if(isset($input['conditions']) && count($input['conditions'])>32)
            return self::problem('policy_conditions_limit',422);
        return null;
    }
    private static function prepare_patch(array $old,array $input): array|WP_Error {
        if(isset($input['id']) && $input['id']!==$old['id'])
            return self::problem('policy_id_immutable',422);
        if(isset($input['provider_id']) && $input['provider_id']!==$old['provider_id'])
            return self::problem('policy_provider_immutable',422);
        $next=array_intersect_key($old,array_flip(self::RULE_KEYS));
        foreach($input as $key=>$value) {
            if(!in_array($key,self::RULE_KEYS,true))continue;
            if($key==='values' || $key==='conditions') {
                if(!is_array($value))return self::problem('policy_object_expected',422);
                $merged=$next[$key]??[];
                foreach($value as $k=>$v) {
                    if(!is_string($k))return self::problem('policy_field_key_invalid',422);
                    if($v===null)unset($merged[$k]);
                    else $merged[$k]=$v;
                }
                $next[$key]=$merged;
            }else $next[$key]=$value;
        }
        $next['id']=$old['id'];
        $next['provider_id']=$old['provider_id'];
        return $next;
    }
    private static function result_from_validation($v): WP_Error {
        return self::problem(is_wp_error($v)?$v->get_error_code():'policy_validation_failed',422);
    }
    /**
     * Exact compare-and-swap at the database row, not read/then update_option.
     * The expected registry revision and rule revision are mandatory.
     * A conflicting writer receives 409; the request is not retried for it.
     */
    private static function mutate(string $operation,array $body,string $id=''): array|WP_Error {
        if(!self::registry_ready())return self::problem('policy_registry_unavailable',503);
        $expected=self::number($body,'expected_registry_revision');
        if(is_wp_error($expected))return $expected;
        if($operation!=='create') {
            $ruleExpected=self::number($body,'expected_rule_revision');
            if(is_wp_error($ruleExpected))return $ruleExpected;
        } else $ruleExpected=null;
        $target=self::target_check($body);
        if($target)return $target;
        if($operation!=='rollback') {
            $shape=self::validate_shape($body,$operation==='patch');
            if($shape)return $shape;
        }else {
            if(!self::permitted_keys($body,['expected_registry_revision','expected_rule_revision','change_id','target_origin','target_site_uuid']))
                return self::problem('policy_unknown_fields',422);
            if(!isset($body['change_id'])||!is_int($body['change_id'])||$body['change_id']<1)
                return self::problem('policy_change_id_required',422);
        }
        $snapshot=self::current_raw();
        if(!empty($snapshot['corrupt']))return self::problem('policy_store_corrupt',503);
        $state=$snapshot['store'];
        if(!isset($state['revision']) || !is_int($state['revision']) || !is_array($state['rules'])
            || !is_array($state['history']) || !is_array($state['epochs']))
            return self::problem('policy_store_shape_invalid',503);
        if($expected!==$state['revision'])return self::problem('policy_registry_revision_conflict',409);
        if($operation==='create') {
            $input=array_intersect_key($body,array_flip(self::RULE_KEYS));
            if(!empty($input['id']) && isset($state['rules'][sanitize_key((string)$input['id'])]))
                return self::problem('policy_rule_already_exists',409);
            if(count($state['rules'])>=250)return self::problem('policy_rule_limit',409);
            $old=null;
        }else {
            if(!isset($state['rules'][$id]) || !is_array($state['rules'][$id]))
                return self::problem('policy_rule_not_found',404);
            $old=$state['rules'][$id];
            if($ruleExpected!==(int)($old['revision']??0))
                return self::problem('policy_rule_revision_conflict',409);
            if($operation==='patch'){
                $input=self::prepare_patch($old,$body);
                if(is_wp_error($input))return $input;
            }else{
                $before=null;
                foreach(array_reverse($state['history']) as $entry) {
                    if(($entry['rule_id']??'')===$id &&
                       (int)($entry['change_id']??0)===$body['change_id']) {
                        $before=$entry['before']??null;break;
                    }
                }
                if(!is_array($before))return self::problem('policy_rollback_snapshot_missing',404);
                $input=$before;
                $input['id']=$id;
            }
        }
        $provider=MAD4B_Company_Policy_Registry_V2::providers()[$input['provider_id']??'']??null;
        if(is_array($provider)) {
            $declared=array_keys((array)($provider['fields']??[]));
            if(array_diff(array_keys((array)($input['values']??[])),$declared))
                return self::problem('policy_value_field_unsupported',422);
            $allowedConditions=['device','placement','query_kind','language','component','min_items',
                'content_family','data_dependency'];
            if(array_diff(array_keys((array)($input['conditions']??[])),$allowedConditions))
                return self::problem('policy_condition_field_unsupported',422);
        }
        $v=MAD4B_Company_Policy_Registry_V2::validate_rule($input,$old);
        if(is_wp_error($v))return self::result_from_validation($v);
        $key=$v['id'];
        if($operation==='create' && isset($state['rules'][$key]))
            return self::problem('policy_rule_already_exists',409);
        $state['revision']++;
        $state['rules'][$key]=$v;
        $state['history'][]=['change_id'=>$state['revision'],'rule_id'=>$key,
            'before'=>$old,'after'=>$v,'actor'=>get_current_user_id(),'at'=>gmdate('c'),
            'channel'=>'rest_v2','operation'=>$operation];
        if(count($state['history'])>60)$state['history']=array_slice($state['history'],-60);
        $epochKey=$v['policy_id'].':'.$v['scope'].':'.(string)$v['scope_id'];
        $state['epochs'][$epochKey]=$state['revision'];
        if(count($state['epochs'])>150)$state['epochs']=array_slice($state['epochs'],-150,null,true);
        $serialized=maybe_serialize($state);
        global $wpdb;
        if(!$snapshot['exists']) {
            $written=add_option(self::OPTION,$state,'',false)?1:0;
        }else{
            $query=$wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
                $serialized,self::OPTION,$snapshot['raw']);
            $written=$wpdb->query($query);
        }
        if($written!==1)return self::problem(
            $written===false?'policy_store_write_failed':'policy_concurrent_write_conflict',
            $written===false?503:409);
        wp_cache_delete(self::OPTION,'options');
        wp_cache_delete('alloptions','options');
        wp_cache_delete('notoptions','options');
        do_action('mad4b/policy/invalidate',$v['policy_id'],$v['scope'],$v['scope_id'],$state['revision']);
        do_action('mad4b/policy/revision_saved',$v,$old,$state['revision']);
        return [
            'contract'=>'mad4b.policy.mutation.v2','operation'=>$operation,
            'rule_id'=>$key,'rule_revision'=>$v['revision'],
            'registry_revision'=>$state['revision'],'state'=>$v['state'],
            'decision_receipt'=>['policy_id'=>$v['policy_id'],'scope'=>$v['scope'],
                'scope_id'=>$v['scope_id'],'updated_by'=>get_current_user_id(),
                'change_id'=>$state['revision']],
        ];
    }

    private static function company_snapshot(): array {
        $value=class_exists('MAD4B_Company_Adaptive_Controls')
            ?MAD4B_Company_Adaptive_Controls::policy():[];
        $allowed=['listing_mode','mobile_prefetch_px','desktop_prefetch_px',
            'mobile_rows','tablet_rows','desktop_rows','pricing_default'];
        $value=array_intersect_key($value,array_flip($allowed));
        ksort($value,SORT_STRING);
        return ['values'=>$value,'sha256'=>hash('sha256',wp_json_encode($value))];
    }
    public static function company_defaults_get(WP_REST_Request $r): WP_REST_Response|WP_Error {
        if(!class_exists('MAD4B_Company_Adaptive_Controls'))
            return self::problem('policy_company_controls_unavailable',503);
        $current=self::company_snapshot();
        return rest_ensure_response([
            'contract'=>'mad4b.policy.company-defaults.v2',
            'origin'=>home_url('/'),
            'values'=>$current['values'],'sha256'=>$current['sha256'],
            'editing'=>'PATCH with expected_defaults_sha256, no automatic Rate or product writes',
        ]);
    }
    public static function company_defaults_patch(WP_REST_Request $r): WP_REST_Response|WP_Error {
        if(!class_exists('MAD4B_Company_Adaptive_Controls'))
            return self::problem('policy_company_controls_unavailable',503);
        $body=self::body($r);if(is_wp_error($body))return $body;
        if(!self::permitted_keys($body,['policy','expected_defaults_sha256','target_origin','target_site_uuid']))
            return self::problem('policy_unknown_fields',422);
        $target=self::target_check($body);if($target)return $target;
        if(!is_string($body['expected_defaults_sha256']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',$body['expected_defaults_sha256']))
            return self::problem('policy_defaults_precondition_required',428);
        $patch=$body['policy']??null;
        if(!is_array($patch)||array_is_list($patch)||!$patch)
            return self::problem('policy_defaults_object_required',422);
        $bounds=[
            'mobile_prefetch_px'=>[0,1800],
            'desktop_prefetch_px'=>[0,2500],
            'mobile_rows'=>[1,6],'tablet_rows'=>[1,6],'desktop_rows'=>[1,6],
        ];
        if(!self::permitted_keys($patch,array_merge(['listing_mode','pricing_default'],array_keys($bounds))))
            return self::problem('policy_defaults_unknown_field',422);
        foreach($patch as $key=>$value) {
            if($key==='listing_mode'&&!in_array($value,['ajax_only','off'],true))
                return self::problem('policy_defaults_listing_mode_invalid',422);
            if($key==='pricing_default'&&!in_array($value,['fixed_seasonal','dynamic_hotel_rates','hybrid'],true))
                return self::problem('policy_defaults_pricing_mode_invalid',422);
            if(isset($bounds[$key])&&(!is_int($value)||
                $value<$bounds[$key][0]||$value>$bounds[$key][1]))
                return self::problem('policy_defaults_numeric_out_of_range',422);
        }
        $option=MAD4B_Company_Adaptive_Controls::OPTION;
        global $wpdb;
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$option));
        $decoded=$raw===null?[]:maybe_unserialize($raw);
        if(!is_array($decoded))return self::problem('policy_defaults_store_invalid',503);
        $effective=array_replace(MAD4B_Company_Adaptive_Controls::defaults(),$decoded);
        $subset=array_intersect_key($effective,array_flip([
            'listing_mode','mobile_prefetch_px','desktop_prefetch_px',
            'mobile_rows','tablet_rows','desktop_rows','pricing_default'
        ]));
        ksort($subset,SORT_STRING);
        $oldHash=hash('sha256',wp_json_encode($subset));
        if(!hash_equals($oldHash,$body['expected_defaults_sha256']))
            return self::problem('policy_defaults_conflict',409);
        $merged=array_replace($decoded,$patch);
        $validated=MAD4B_Company_Adaptive_Controls::sanitize($merged);
        // sanitize() must never silently change values or amount authority from REST.
        foreach($patch as $k=>$v)
            if(($validated[$k]??null)!==$v)return self::problem('policy_defaults_validation_failed',422);
        if($validated===$effective) {
            return rest_ensure_response(['contract'=>'mad4b.policy.company-defaults.v2',
                'changed'=>false,'values'=>$subset,'sha256'=>$oldHash]);
        }
        if($raw===null)$affected=add_option($option,$validated,'',false)?1:0;
        else $affected=$wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
            maybe_serialize($validated),$option,$raw));
        if($affected!==1)return self::problem(
            $affected===false?'policy_defaults_write_failed':'policy_defaults_concurrent_change',
            $affected===false?503:409);
        wp_cache_delete($option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
        do_action('mad4b/policy/company_defaults_updated',$patch,$validated);
        $final=self::company_snapshot();
        return rest_ensure_response(['contract'=>'mad4b.policy.company-defaults.v2',
            'changed'=>true,'values'=>$final['values'],'sha256'=>$final['sha256'],
            'published_rates_changed'=>false]);
    }
    public static function providers(WP_REST_Request $r): WP_REST_Response|WP_Error {
        if(!self::registry_ready())return self::problem('policy_registry_unavailable',503);
        $profile=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
        return rest_ensure_response([
            'contract'=>'mad4b.policy.providers.v2','version'=>MAD4B_Company_Policy_Registry_V2::VERSION,
            'origin'=>home_url('/'),'site_uuid'=>is_array($profile)?($profile['site_uuid']??null):null,
            'registry_revision'=>MAD4B_Company_Policy_Registry_V2::state()['revision'],
            'providers'=>MAD4B_Company_Policy_Registry_V2::providers(),
            'writes_require'=>'authenticated_manage_options_and_expected_revisions',
        ]);
    }
    public static function listing(WP_REST_Request $r): WP_REST_Response|WP_Error {
        if(!self::registry_ready())return self::problem('policy_registry_unavailable',503);
        $state=MAD4B_Company_Policy_Registry_V2::state();
        $items=[];
        foreach($state['rules'] as $rule) {
            if(!is_array($rule))continue;
            $ok=true;
            foreach(['state','scope','provider_id','policy_id'] as $key) {
                $filter=(string)$r->get_param($key);
                if($filter!=='' && ($rule[$key]??'')!==$filter)$ok=false;
            }
            if($ok)$items[]=$rule;
        }
        usort($items,static fn($a,$b)=>strcmp((string)($a['id']??''),(string)($b['id']??'')));
        $page=max(1,min(100000,(int)($r->get_param('page')??1)));
        $perPage=max(1,min(100,(int)($r->get_param('per_page')??25)));
        return rest_ensure_response([
            'contract'=>'mad4b.policy.list.v2','registry_revision'=>$state['revision'],
            'items'=>array_slice($items,($page-1)*$perPage,$perPage),
            'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>count($items),
                'total_pages'=>(int)ceil(count($items)/$perPage)],
        ]);
    }
    public static function single(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $state=MAD4B_Company_Policy_Registry_V2::state();
        $id=(string)$r['id'];
        if(!isset($state['rules'][$id]))return self::problem('policy_rule_not_found',404);
        $revisions=[];
        foreach($state['history'] as $event) {
            if(($event['rule_id']??'')!==$id)continue;
            $revisions[]=['change_id'=>$event['change_id'],'at'=>$event['at'],
                'operation'=>$event['operation']??'legacy','actor'=>$event['actor'],
                'restorable'=>is_array($event['before']??null)];
        }
        return rest_ensure_response(['contract'=>'mad4b.policy.item.v2',
            'registry_revision'=>$state['revision'],'rule'=>$state['rules'][$id],
            'history'=>$revisions]);
    }
    public static function create(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $body=self::body($r);if(is_wp_error($body))return $body;
        $result=self::mutate('create',$body);
        if(is_wp_error($result))return $result;
        return new WP_REST_Response($result,201);
    }
    public static function assignment(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $body=self::body($r);if(is_wp_error($body))return $body;
        // A local assignment is just a versioned policy at a specific local
        // company, Tour, hotel, Listing or Rate scope. No remote mutation here.
        if(!isset($body['scope'])||!in_array($body['scope'],[
            'company','template_family','listing','widget','query','program','season','tour','property','rate'
        ],true))return self::problem('policy_assignment_scope_required',422);
        $result=self::mutate('create',$body);
        if(is_wp_error($result))return $result;
        return new WP_REST_Response($result,201);
    }
    public static function patch(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $body=self::body($r);if(is_wp_error($body))return $body;
        $result=self::mutate('patch',$body,(string)$r['id']);
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }
    public static function rollback(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $body=self::body($r);if(is_wp_error($body))return $body;
        $result=self::mutate('rollback',$body,(string)$r['id']);
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }
    private static function valid_context(array $ctx): bool {
        $idFields=['template_family_id','listing_id','widget_id','query_id','program_term_id','season_id',
            'tour_id','property_id','rate_id','items'];
        $enums=[
            'device'=>['mobile','tablet','desktop'],
            'placement'=>['below_fold','above_fold','unknown'],
            'query_kind'=>['grid','slider','query_builder'],
            'component'=>['base','hotel'],
            'content_family'=>['destination','transportation','unit','property','blog','tour','other','unknown'],
            'data_dependency'=>['independent','parent_object','rate_sensitive','unknown']
        ];
        $keys=array_merge($idFields,array_keys($enums),['language','effective_date']);
        if(!self::permitted_keys($ctx,$keys))return false;
        foreach($ctx as $key=>$value){
            if(in_array($key,$idFields,true)){
                if($key==='widget_id') {
                    if(!(is_string($value)&&preg_match('/^[a-z0-9_-]{1,64}$/D',$value)))return false;
                }elseif(!is_int($value)||$value<0||$value>100000000)return false;
            }elseif(isset($enums[$key])){
                if(!is_string($value)||!in_array($value,$enums[$key],true))return false;
            }elseif($key==='language'){
                if(!is_string($value)||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$value))return false;
            }elseif($key==='effective_date'){
                if(!is_string($value)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$value))return false;
                $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
                if(!$d||$d->format('Y-m-d')!==$value)return false;
            }
        }
        return true;
    }
    private static function defaults(string $policy,array $context): array {
        $company=class_exists('MAD4B_Company_Adaptive_Controls')
            ?MAD4B_Company_Adaptive_Controls::policy():[];
        if($policy==='pricing.rate.choice')return ['mode'=>'unambiguous_only'];
        if($policy==='pricing.source.selection')
            return ['strategy'=>$company['pricing_default']??'fixed_seasonal'];
        if($policy==='listing.render.adaptive'){
            $device=$context['device']??'desktop';
            return [
                'strategy'=>$company['listing_mode']??'ajax_only',
                'rows'=>(int)($company[$device.'_rows']??2),
                'prefetch_px'=>(int)($company[$device==='mobile'?'mobile_prefetch_px':'desktop_prefetch_px']??900),
            ];
        }
        return [];
    }
    public static function resolve(WP_REST_Request $r): WP_REST_Response|WP_Error {
        if(!self::registry_ready())return self::problem('policy_registry_unavailable',503);
        $input=self::body($r);if(is_wp_error($input))return $input;
        if(!self::permitted_keys($input,['policy_id','context','defaults','target_origin','target_site_uuid']))
            return self::problem('policy_unknown_fields',422);
        $target=self::target_check($input);if($target)return $target;
        $policy=$input['policy_id']??null;
        if(!is_string($policy))return self::problem('policy_id_required',422);
        $providers=MAD4B_Company_Policy_Registry_V2::providers();
        $provider=null;
        foreach($providers as $value)if(($value['policy_id']??'')===$policy){$provider=$value;break;}
        if(!$provider)return self::problem('policy_provider_unknown',422);
        $context=$input['context']??[];
        $defaults=$input['defaults']??[];
        if(!is_array($context)||!is_array($defaults)||!self::valid_context($context)
            ||($context!==[] && array_is_list($context))
            ||($defaults!==[] && array_is_list($defaults)))
            return self::problem('policy_context_invalid',422);
        $fallback=self::defaults($policy,$context);
        // Explicit simulator defaults are validated against provider field schema.
        foreach($defaults as $key=>$value){
            $spec=$provider['fields'][$key]??null;
            if(!is_array($spec))return self::problem('policy_default_field_invalid',422);
            if(($spec['type']??'')==='enum'){
                if(!is_string($value)||!isset($spec['options'][$value]))
                    return self::problem('policy_default_enum_invalid',422);
            }elseif(($spec['type']??'')==='int'){
                if(!is_int($value)||$value<($spec['min']??0)||$value>($spec['max']??PHP_INT_MAX))
                    return self::problem('policy_default_number_invalid',422);
            }else return self::problem('policy_default_type_invalid',422);
            $fallback[$key]=$value;
        }
        $result=MAD4B_Company_Policy_Registry_V2::resolve($policy,$context,$fallback);
        $result['contract']='mad4b.policy.simulation.v2';
        $result['simulation']=true;
        $result['non_mutating_policy_store']=true;
        $log=get_transient(self::DIAGNOSTICS_KEY);
        if(!is_array($log))$log=[];
        $log[]=['at'=>gmdate('c'),'policy_id'=>$policy,
            'decision_id'=>$result['receipt']['decision_id']??null,
            'rule_id'=>$result['receipt']['rule_id']??null,
            'registry_revision'=>$result['receipt']['registry_revision']??null,
            'source'=>'explicit_api_simulation_only'];
        set_transient(self::DIAGNOSTICS_KEY,array_slice($log,-40),DAY_IN_SECONDS);
        return rest_ensure_response($result);
    }
    public static function decisions(WP_REST_Request $r): WP_REST_Response {
        $rows=get_transient(self::DIAGNOSTICS_KEY);
        return rest_ensure_response(['contract'=>'mad4b.policy.simulation-receipts.v2',
            'source'=>'manual_api_simulations_only_not_live_frontend',
            'items'=>is_array($rows)?array_reverse($rows):[],
            'live_runtime_decisions_included'=>false]);
    }
    public static function routes(): void {
        $read=['permission_callback'=>[self::class,'can_read']];
        $write=['permission_callback'=>[self::class,'can_write']];
        register_rest_route(self::NS,'/company-defaults',[
            ['methods'=>'GET','callback'=>[self::class,'company_defaults_get']]+$read,
            ['methods'=>'PATCH','callback'=>[self::class,'company_defaults_patch']]+$write,
        ]);
        register_rest_route(self::NS,'/providers',[
            'methods'=>'GET','callback'=>[self::class,'providers']]+$read);
        register_rest_route(self::NS,'/policies',[
            ['methods'=>'GET','callback'=>[self::class,'listing']]+$read,
            ['methods'=>'POST','callback'=>[self::class,'create']]+$write,
        ]);
        register_rest_route(self::NS,'/policies/(?P<id>[a-z0-9_-]{3,86})',[
            ['methods'=>'GET','callback'=>[self::class,'single']]+$read,
            ['methods'=>'PATCH','callback'=>[self::class,'patch']]+$write,
        ]);
        register_rest_route(self::NS,'/policies/(?P<id>[a-z0-9_-]{3,86})/rollback',[
            'methods'=>'POST','callback'=>[self::class,'rollback']]+$write);
        register_rest_route(self::NS,'/assignments',[
            'methods'=>'POST','callback'=>[self::class,'assignment']]+$write);
        register_rest_route(self::NS,'/resolve',[
            'methods'=>'POST','callback'=>[self::class,'resolve']]+$read);
        register_rest_route(self::NS,'/decisions',[
            'methods'=>'GET','callback'=>[self::class,'decisions']]+$read);
    }
}
add_action('rest_api_init',['MAD4B_Company_Policy_REST_V2','routes'],85);
