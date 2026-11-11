<?php
/**
 * MAD4B: Extensible source-backed Price Group Controller.
 * Scope: admin-only Native Tour Rate fields. Defaults to INHERIT; no automatic writes.
 * Original customizations are captured separately from the selected overrides.
 */
defined('ABSPATH') || exit;

final class MAD4B_JetEngine_Price_Group_Controller_V1 {
    public const ACTION = 'mad4b_jetengine_price_group_controller_v1';
    private const STATE = '_mad4b_rate_price_group_controller_v1';
    private const MAX_BODY = 8000;

    public static function boot(): void {
        add_action('wp_ajax_'.self::ACTION, [__CLASS__, 'ajax']);
    }

    /** The native Tour Rate schema is fixed for this version's write authority. */
    private static function tour_schema(): array {
        return [
            'post_type' => 'tour-rates',
            'audiences' => [
                'international' => [
                    'currency'=>'base_currency', 'enabled'=>null,
                    'regular'=>['single'=>'single_price','double'=>'double_price','triple'=>'triple_price'],
                    'sale'=>['single'=>'single_sale_price','double'=>'double_sale_price','triple'=>'triple_sale_price'],
                ],
                'egyptians' => [
                    'currency'=>'egyptians_currency', 'enabled'=>'has_egyptians_price',
                    'regular'=>[
                        'single'=>'egyptians_single_price',
                        'double'=>'egyptians_double_price',
                        'triple'=>'egyptians_triple_price',
                    ],
                    'sale'=>[
                        'single'=>'egyptians_single_sale_price',
                        'double'=>'egyptians_double_sale_price',
                        'triple'=>'egyptians_triple_sale_price',
                    ],
                ],
            ],
            'flags'=>['sale_enabled'=>'is_on_sale'],
        ];
    }
    /** Adapter discovery can expand without widening the auditable write allowlist. */
    public static function adapters(): array {
        $tour=self::tour_schema();
        // Adapter discovery is extendable for other price-group clients; write
        // operations remain constrained to our audited native Tour Rate schema.
        $registered=apply_filters('mad4b/jetengine/price_group_adapters_v1', ['tour-rates'=>$tour]);
        return is_array($registered) ? $registered : ['tour-rates'=>$tour];
    }

    public static function native_fields(): array {
        $tour = self::tour_schema();
        $keys=[];
        foreach ((array)($tour['audiences']??[]) as $group) {
            if (!empty($group['currency']))$keys[]=$group['currency'];
            if (!empty($group['enabled']))$keys[]=$group['enabled'];
            foreach (['regular','sale'] as $role) {
                foreach ((array)($group[$role]??[]) as $field) $keys[]=$field;
            }
        }
        foreach ((array)($tour['flags']??[]) as $field)$keys[]=$field;
        return array_values(array_unique($keys));
    }

    public static function validate_target(int $rate, bool $for_write=false) {
        if ($rate<=0 || get_post_type($rate)!=='tour-rates'
            || !in_array(get_post_status($rate),['publish','draft','pending'],true)) {
            return new WP_Error('rate_invalid','No eligible Tour Rate');
        }
        if (!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized()
            || !current_user_can('edit_post',$rate)) {
            return new WP_Error('rate_forbidden','Insufficient permissions');
        }
        if (!$for_write && get_post_status($rate)!=='publish') {
            // Drafts may be tested locally through methods, but not discovered in the inspector.
            return new WP_Error('rate_unpublished','Only published Rates may be inspected');
        }
        return true;
    }

    public static function snapshot(int $rate): array {
        $values=[];
        foreach (self::native_fields() as $key) {
            $raw=get_post_meta($rate,$key,true);
            $values[$key]=[
                'exists'=>metadata_exists('post',$rate,$key),
                'value'=>is_scalar($raw) ? (string)$raw : '',
                'scalar'=>is_scalar($raw),
            ];
        }
        return $values;
    }

    private static function state(int $rate): array {
        $raw=get_post_meta($rate,self::STATE,true);
        if(!is_array($raw)||($raw['schema']??0)!==1)return [
            'schema'=>1,'revision'=>0,'managed'=>[],
        ];
        $raw['managed']=is_array($raw['managed']??null)?$raw['managed']:[];
        return $raw;
    }

    public static function fingerprint(array $fields, array $state): string {
        return hash('sha256',wp_json_encode([
            'fields'=>$fields,
            'revision'=>(int)($state['revision']??0),
            'managed'=>$state['managed']??[],
        ],JSON_UNESCAPED_SLASHES));
    }

    public static function read(int $rate): array {
        $fields=self::snapshot($rate);
        $state=self::state($rate);
        $tour=absint(get_post_meta($rate,'related_tour_id',true));
        $policy=($tour && function_exists('shared_product_occupancy_policy_resolve'))
            ? shared_product_occupancy_policy_resolve($tour) : ['status'=>'unavailable','source'=>'none'];
        return [
            'rate_id'=>$rate,
            'title'=>sanitize_text_field(get_the_title($rate)),
            'tour_id'=>$tour,
            'version'=>self::fingerprint($fields,$state),
            'native_fields'=>$fields,
            'managed'=>$state['managed'],
            'revision'=>(int)($state['revision']??0),
            'adapters'=>['tour-rates'=>self::tour_schema()],
            'occupancy_policy'=>[
                'status'=>sanitize_key((string)($policy['status']??'unavailable')),
                'source'=>sanitize_key((string)($policy['source']??'none')),
            ],
            'authority'=>'native_postmeta_only_explicit_admin_save',
            'warning'=>'Save applies only explicit overrides to native Tour Rate fields; may change published prices. Inherit restores this controller’s captured original field value. Existing booking gates remain in force.',
        ];
    }

    private static function normalize(string $key,$raw) {
        $keys=self::native_fields();
        if (!in_array($key,$keys,true)) return new WP_Error('price_group_unknown_field');
        if (!is_scalar($raw)||is_bool($raw)) return new WP_Error('price_group_invalid_scalar');
        $raw=trim((string)$raw);
        if(in_array($key,['base_currency','egyptians_currency'],true)){
            $raw=strtoupper($raw);
            if(!preg_match('/^[A-Z]{3}$/D',$raw)
               || !array_key_exists($raw,get_woocommerce_currencies()))
                return new WP_Error('price_group_currency_invalid');
            return $raw;
        }
        if(in_array($key,['has_egyptians_price','is_on_sale'],true)){
            if(!in_array($raw,['0','1'],true))return new WP_Error('price_group_switch_invalid');
            return $raw;
        }
        // A sale price of "0" explicitly disables an existing sale amount.
        $isSale=str_ends_with($key,'_sale_price');
        if(!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D',$raw))
            return new WP_Error('price_group_amount_invalid');
        $amount=(float)$raw;
        if(!is_finite($amount)||$amount>99999999||(!$isSale && $amount<=0))
            return new WP_Error('price_group_amount_out_of_range');
        return number_format($amount,4,'.','');
    }

    /**
     * Build and validate exact actions before any writes. The 'before' field
     * records pre-controller customizations (and whether the key existed).
     */
    public static function plan(array $fields,array $state,array $submitted) {
        $allowed=self::native_fields();
        if(count($submitted)>count($allowed)
           || array_diff(array_keys($submitted),$allowed))
            return new WP_Error('price_group_unknown_fields');
        $managed=$state['managed']??[];
        $next=$managed;
        $actions=[];
        foreach ($submitted as $key=>$rule) {
            if (!is_array($rule)||array_diff(array_keys($rule),['mode','value']))
                return new WP_Error('price_group_invalid_rule');
            $mode=$rule['mode']??null;
            if(!in_array($mode,['inherit','override'],true))
                return new WP_Error('price_group_invalid_mode');
            $live=$fields[$key]??null;
            if(!is_array($live) || ($live['scalar']??false)!==true)
                return new WP_Error('price_group_non_scalar_field');
            if(isset($managed[$key])) {
                $after=$managed[$key]['after']??null;
                if(!is_string($after)||$live['value']!==$after)
                    return new WP_Error('price_group_source_changed',
                      'Native Rate field was edited outside the controller');
            }
            if($mode==='override'){
                if(!array_key_exists('value',$rule))
                    return new WP_Error('price_group_override_missing');
                $value=self::normalize($key,$rule['value']);
                if(is_wp_error($value))return $value;
                if(isset($managed[$key])){
                    $before=$managed[$key]['before'];
                }else{
                    $before=['exists'=>$live['exists'],'value'=>$live['value']];
                }
                $next[$key]=['before'=>$before,'after'=>$value];
                if($live['value']!==$value || !$live['exists']){
                    $actions[$key]=['action'=>'set','value'=>$value];
                }
            }elseif(isset($managed[$key])){
                $original=$managed[$key]['before'];
                if(!is_array($original)||!array_key_exists('exists',$original)
                    || !isset($original['value'])||!is_string($original['value']))
                    return new WP_Error('price_group_baseline_invalid');
                $actions[$key]=$original['exists']
                    ? ['action'=>'set','value'=>$original['value']]
                    : ['action'=>'delete'];
                unset($next[$key]);
            }
        }
        // Check the post-save effective fields without writing anything yet.
        $effective=[];
        foreach($fields as $key=>$info)$effective[$key]=$info['value'];
        foreach($actions as $key=>$op){
            $effective[$key]=$op['action']==='delete'?'':$op['value'];
        }
        $egyptianEnabled=($effective['has_egyptians_price']??'')==='1';
        $egyptianTouched=isset($submitted['has_egyptians_price'])
            && ($submitted['has_egyptians_price']['mode']??'')==='override';
        if($egyptianEnabled && $egyptianTouched){
            if(!preg_match('/^[A-Z]{3}$/D',(string)($effective['egyptians_currency']??''))
               || !array_key_exists((string)$effective['egyptians_currency'],get_woocommerce_currencies()))
                return new WP_Error('price_group_egyptian_currency_required');
            foreach (['single','double','triple'] as $occ) {
                $key='egyptians_'.$occ.'_price';
                if(!is_numeric($effective[$key]??null)||(float)$effective[$key]<=0)
                    return new WP_Error('price_group_egyptian_matrix_incomplete');
            }
        }
        foreach(['','egyptians_'] as $prefix){
            foreach(['single','double','triple'] as $occ){
                $regular=$prefix.$occ.'_price';
                $sale=$prefix.$occ.'_sale_price';
                if(!isset($actions[$regular])&&!isset($actions[$sale]))continue;
                $regularValue=$effective[$regular]??'';
                $saleValue=$effective[$sale]??'';
                if(is_numeric($saleValue)&&(float)$saleValue>0 &&
                   is_numeric($regularValue)&&(float)$regularValue>0 &&
                   (float)$saleValue >= (float)$regularValue)
                    return new WP_Error('price_group_sale_not_lower_than_regular');
            }
        }
        return ['actions'=>$actions,'managed'=>$next,'effective'=>$effective];
    }

    public static function apply(int $rate,array $submitted,string $expected) {
        $can=self::validate_target($rate,true);
        if(is_wp_error($can))return $can;
        if(strlen($expected)!==64||!ctype_xdigit($expected))
            return new WP_Error('price_group_version_missing');
        // Serialize writes to this exact Rate and re-check the source revision
        // *inside* the lock. No other Rate or price policy receives a lock.
        global $wpdb;
        if($wpdb->query('START TRANSACTION')===false)
            return new WP_Error('price_group_transaction_unavailable');
        $locked=$wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$rate
        ));
        if((int)$locked!==$rate){
            $wpdb->query('ROLLBACK');
            return new WP_Error('price_group_rate_lock_failed');
        }
        wp_cache_delete($rate,'post_meta');
        $before=self::snapshot($rate);
        $state=self::state($rate);
        if(!hash_equals(self::fingerprint($before,$state),$expected)){
            $wpdb->query('ROLLBACK');
            return new WP_Error('price_group_stale_revision',
                'Rate or controller state changed; reload before saving');
        }
        $plan=self::plan($before,$state,$submitted);
        if(is_wp_error($plan)){
            $wpdb->query('ROLLBACK');
            return $plan;
        }
        $changes=$plan['actions'];
        $newManaged=$plan['managed'];
        if(!$changes && $newManaged===$state['managed']){
            $wpdb->query('ROLLBACK');
            return ['changed'=>false,'data'=>self::read($rate)];
        }
        $ok=true;
        foreach($changes as $key=>$op){
            if($op['action']==='delete'){
                if(!delete_post_meta($rate,$key) && metadata_exists('post',$rate,$key)){
                    $ok=false;break;
                }
            }elseif(!update_post_meta($rate,$key,$op['value'])
                    && (string)get_post_meta($rate,$key,true)!==(string)$op['value']){
                $ok=false;break;
            }
        }
        if($ok){
            $state['revision']=(int)$state['revision']+1;
            $state['managed']=$newManaged;
            $state['updated_at']=gmdate('c');
            $state['updated_by']=get_current_user_id();
            $state['last_changed_keys']=array_keys($changes);
            if(!update_post_meta($rate,self::STATE,$state)){
                $ok=false;
            }
        }
        if(!$ok){
            $wpdb->query('ROLLBACK');
            clean_post_cache($rate);
            wp_cache_delete($rate,'post_meta');
            return new WP_Error('price_group_write_failed','Failed to save all Rate changes');
        }
        $wpdb->query('COMMIT');
        clean_post_cache($rate);
        wp_cache_delete($rate,'post_meta');
        do_action('mad4b/jetengine/price_group_applied',$rate,array_keys($changes),$state);
        return ['changed'=>true,'changed_keys'=>array_keys($changes),
            'data'=>self::read($rate)];
    }

    public static function ajax(): void {
        if(!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())wp_send_json_error(['message'=>'Forbidden'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $rate=absint($_POST['rate_id']??0);
        $operation=isset($_POST['operation'])&&is_string($_POST['operation'])
            ? sanitize_key(wp_unslash($_POST['operation'])) : 'get';
        $access=self::validate_target($rate,$operation==='save');
        if(is_wp_error($access)){
            wp_send_json_error(['message'=>$access->get_error_message(),
                'code'=>$access->get_error_code()],403);
        }
        if($operation==='get'){
            wp_send_json_success(['data'=>self::read($rate)]);
        }
        if($operation!=='save')wp_send_json_error(['message'=>'Operation not supported'],400);
        $raw=isset($_POST['changes'])&&is_string($_POST['changes'])
            ?wp_unslash($_POST['changes']):'';
        if(strlen($raw)>self::MAX_BODY)wp_send_json_error(['message'=>'Invalid size'],400);
        $submitted=json_decode($raw,true);
        if(!is_array($submitted))wp_send_json_error(['message'=>'Invalid rules'],400);
        $version=isset($_POST['version'])&&is_string($_POST['version'])
            ?sanitize_text_field(wp_unslash($_POST['version'])):'';
        $result=self::apply($rate,$submitted,$version);
        if(is_wp_error($result)){
            wp_send_json_error(['message'=>$result->get_error_message(),
                'code'=>$result->get_error_code()],409);
        }
        wp_send_json_success($result);
    }
}
