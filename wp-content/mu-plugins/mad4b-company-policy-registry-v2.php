<?php
/**
 * Plugin Name: MAD4B Company Dynamic Policy Registry v2
 * Description: Tenant-local, versioned provider policies and deterministic context decisions; native engines retain amount and rendering authority.
 * Version: 2.0.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Policy_Registry_V2 {
    public const VERSION = '2.0.1';
    public const OPTION = 'mad4b_company_policy_registry_v2';
    private static array $providers = [];
    private static array $last = [];

    public static function register_provider(string $id, array $definition): bool {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/D', $id)
            || empty($definition['policy_id']) || empty($definition['fields']) || !is_array($definition['fields'])) return false;
        self::$providers[$id] = $definition;
        return true;
    }
    public static function providers(): array {
        $core = [
            'jetengine-rendering' => [
                'label' => 'JetEngine / Elementor Listing Rendering',
                'policy_id' => 'listing.render.adaptive',
                'fields' => [
                    'strategy' => ['type'=>'enum','options'=>['ajax_only'=>'Deferred AJAX only','off'=>'Disable automatic deferral']],
                    'prefetch_px' => ['type'=>'int','min'=>0,'max'=>2500],
                    'rows' => ['type'=>'int','min'=>1,'max'=>6],
                ],
                'allowed_scopes' => ['company','template_family','listing','widget'],
            ],
            'shared-commerce-pricing' => [
                'label' => 'Shared Product Core / Commerce Pricing Source',
                'policy_id' => 'pricing.source.selection',
                'fields' => [
                    'strategy' => ['type'=>'enum','options'=>[
                        'fixed_seasonal'=>'Existing fixed seasonal source',
                        'dynamic_hotel_rates'=>'Existing hotel/Property Rates',
                        'hybrid'=>'Existing seasonal base + hotel components',
                    ]],
                ],
                'allowed_scopes' => ['company','program','season','tour','property','rate'],
            ],
        ];
        $providers = apply_filters('mad4b/policy_registry/providers', array_replace($core, self::$providers));
        $out = [];
        foreach ((array)$providers as $id=>$spec) {
            if (!is_string($id) || !is_array($spec) || empty($spec['policy_id']) || empty($spec['fields'])
                || !is_array($spec['fields'])) continue;
            $out[$id] = $spec;
        }
        return $out;
    }
    public static function state(): array {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) $raw = [];
        return array_replace(['schema'=>2,'revision'=>0,'rules'=>[],'history'=>[],'epochs'=>[]], $raw);
    }
    private static function valid_day(string $value): bool {
        if (!$value) return true;
        if (!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $value)) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d') === $value;
    }
    public static function validate_rule(array $raw, ?array $old=null) {
        $providers = self::providers();
        $provider = sanitize_key((string)($raw['provider_id']??''));
        if (!isset($providers[$provider])) return new WP_Error('policy_provider_unknown');
        $spec=$providers[$provider];
        $scope=sanitize_key((string)($raw['scope']??'company'));
        if (!in_array($scope,$spec['allowed_scopes']??['company'],true)) return new WP_Error('policy_scope_unsupported');
        $scope_id=$scope==='widget'?sanitize_key((string)($raw['scope_id']??'')):absint($raw['scope_id']??0);
        if ($scope !== 'company' && !$scope_id) return new WP_Error('policy_scope_id_required');
        $state=sanitize_key((string)($raw['state']??'draft'));
        if (!in_array($state,['draft','active','canary','paused'],true)) return new WP_Error('policy_state_invalid');
        $from=sanitize_text_field((string)($raw['valid_from']??''));
        $to=sanitize_text_field((string)($raw['valid_to']??''));
        if (!self::valid_day($from)||!self::valid_day($to)||($from&&$to&&$to<$from)) return new WP_Error('policy_dates_invalid');
        $fields=[];
        foreach ($spec['fields'] as $key=>$field) {
            if (!isset($raw['values'][$key]) || $raw['values'][$key] === '') continue;
            $value=$raw['values'][$key];
            if (($field['type']??'')==='enum') {
                if (!is_scalar($value)||!isset($field['options'][(string)$value])) return new WP_Error('policy_value_invalid_'.$key);
                $fields[$key]=(string)$value;
            } elseif (($field['type']??'')==='int') {
                if (!is_scalar($value)||!ctype_digit((string)$value)) return new WP_Error('policy_value_invalid_'.$key);
                $num=(int)$value;
                if ($num<($field['min']??0)||$num>($field['max']??PHP_INT_MAX)) return new WP_Error('policy_value_out_of_range_'.$key);
                $fields[$key]=$num;
            } else return new WP_Error('policy_field_type_unsupported');
        }
        if (!$fields) return new WP_Error('policy_values_required');
        $conditions=[];
        foreach (['device','placement','query_kind','language','component'] as $key) {
            $value=sanitize_key((string)($raw['conditions'][$key]??''));
            if ($value !== '') $conditions[$key]=$value;
        }
        foreach (['device'=>['mobile','tablet','desktop'],
                  'placement'=>['below_fold','above_fold','unknown'],
                  'query_kind'=>['grid','slider','query_builder'],
                  'component'=>['base','hotel']] as $key=>$allowed)
            if (isset($conditions[$key])&&!in_array($conditions[$key],$allowed,true)) return new WP_Error('policy_condition_invalid_'.$key);
        if (isset($raw['conditions']['min_items']) && $raw['conditions']['min_items']!=='') {
            $n=filter_var($raw['conditions']['min_items'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>5000]]);
            if ($n===false) return new WP_Error('policy_min_items_invalid');
            $conditions['min_items']=$n;
        }
        $id=$old['id']??sanitize_key((string)($raw['id']??''));
        if (!$id) $id='rule-'.str_replace('-','',wp_generate_uuid4());
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,85}$/D',$id))return new WP_Error('policy_id_invalid');
        return [
            'id'=>$id,'provider_id'=>$provider,'policy_id'=>$spec['policy_id'],
            'scope'=>$scope,'scope_id'=>$scope_id,'conditions'=>$conditions,'values'=>$fields,
            'priority'=>max(-100,min(100,(int)($raw['priority']??0))),
            'state'=>$state,'valid_from'=>$from,'valid_to'=>$to,
            'revision'=>(int)($old['revision']??0)+1,
            'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id(),
        ];
    }
    private static function matches(array $rule,array $ctx): bool {
        $scope=$rule['scope']??'';
        if ($scope!=='company') {
            $map=['template_family'=>'template_family_id','listing'=>'listing_id','widget'=>'widget_id',
                  'program'=>'program_term_id','season'=>'season_id','tour'=>'tour_id',
                  'property'=>'property_id','rate'=>'rate_id'];
            if (!isset($map[$scope]) || (string)($ctx[$map[$scope]]??'') !== (string)($rule['scope_id']??'')) return false;
        }
        $today=gmdate('Y-m-d');
        if ((!empty($rule['valid_from']) && $today < $rule['valid_from'])
            ||(!empty($rule['valid_to']) && $today > $rule['valid_to'])) return false;
        foreach ((array)($rule['conditions']??[]) as $key=>$value) {
            if ($key==='min_items') {
                if (!isset($ctx['items']) || (int)$ctx['items']<(int)$value)return false;
            } elseif (!isset($ctx[$key]) || (string)$ctx[$key] !== (string)$value) return false;
        }
        return true;
    }
    private static function scope_weight(string $scope): int {
        return ['company'=>0,'template_family'=>10,'program'=>10,'season'=>15,'listing'=>20,
                'property'=>20,'tour'=>25,'widget'=>30,'rate'=>35][$scope]??0;
    }
    public static function resolve(string $policy_id, array $context=[], array $defaults=[]): array {
        $store=self::state();$rank=-999999;$chosen=null;
        foreach ((array)$store['rules'] as $rule) {
            if (!is_array($rule) || ($rule['policy_id']??'')!==$policy_id || ($rule['state']??'')!=='active'
                || !self::matches($rule,$context))continue;
            $score=self::scope_weight((string)$rule['scope'])*10000
                +min(99,count((array)($rule['conditions']??[])))*1000+(int)($rule['priority']??0);
            if ($score>$rank||($score===$rank&&strcmp((string)($rule['id']??''),(string)($chosen['id']??''))<0)) {
                $rank=$score;$chosen=$rule;
            }
        }
        $effective=array_replace($defaults,(array)($chosen['values']??[]));
        $receipt=[
            'contract'=>'mad4b.policy.decision.v2','policy_id'=>$policy_id,
            'registry_revision'=>(int)$store['revision'],'rule_id'=>$chosen['id']??null,
            'rule_revision'=>$chosen['revision']??null,
            'scope'=>$chosen['scope']??'legacy_company_default',
            'origin'=>$chosen?'active_versioned_rule':'existing_company_policy',
            'context'=>array_intersect_key($context,array_flip([
                'device','placement','query_kind','component','listing_id','widget_id','tour_id',
                'property_id','rate_id','season_id','program_term_id','language'
            ])),
        ];
        $receipt['decision_id']=substr(hash('sha256',wp_json_encode([$receipt,$effective])),0,20);
        self::$last[$policy_id.'|'.($context['component']??$context['listing_id']??'default')]=$receipt;
        do_action('mad4b/policy/decision_made',$receipt,$effective);
        return ['values'=>$effective,'receipt'=>$receipt];
    }
    public static function last_decisions(): array {return self::$last;}
    public static function device(): string {
        $detected=wp_is_mobile()?'mobile':'desktop';
        $device=apply_filters('mad4b/policy_registry/device',$detected);
        return in_array($device,['mobile','tablet','desktop'],true)?$device:$detected;
    }
    private static function canonical_meta(int $id,string $type): string {
        if (!$id) return '';
        $key=class_exists('MAD4B_Company_Adaptive_Controls') ? MAD4B_Company_Adaptive_Controls::PRICE_META : '_mad4b_company_pricing_mode';
        $v=(string)get_post_meta($id,$key,true);
        if (in_array($v,['fixed_seasonal','dynamic_hotel_rates','hybrid'],true))return $v;
        if (has_filter('wpml_object_id')) {
            $lang=apply_filters('wpml_default_language',null);
            if (is_string($lang)&&$lang!=='') {
                $master=(int)apply_filters('wpml_object_id',$id,$type,true,$lang);
                if($master && $master!==$id) {
                    $v=(string)get_post_meta($master,$key,true);
                    if(in_array($v,['fixed_seasonal','dynamic_hotel_rates','hybrid'],true))return $v;
                }
            }
        }
        return '';
    }
    public static function pricing(int $tour_id=0,int $property_id=0,int $rate_id=0,int $program_term_id=0,int $season_id=0): array {
        $p=class_exists('MAD4B_Company_Adaptive_Controls')?MAD4B_Company_Adaptive_Controls::policy():[];
        $company=$p['pricing_default']??'fixed_seasonal';
        $metaKey=class_exists('MAD4B_Company_Adaptive_Controls')?MAD4B_Company_Adaptive_Controls::PRICE_META:'_mad4b_company_pricing_mode';
        $base=$company;$baseSource='company';
        if ($program_term_id) {
            $v=(string)get_term_meta($program_term_id,$metaKey,true);
            if(in_array($v,['fixed_seasonal','dynamic_hotel_rates','hybrid'],true)){$base=$v;$baseSource='program';}
        }
        if($tour_id) {
            $v=self::canonical_meta($tour_id,'tours-and-activities');
            if($v!==''){$base=$v;$baseSource='tour';}
        }
        $rateType=$rate_id?get_post_type($rate_id):'';
        if($rateType==='tour-rates') {
            $v=self::canonical_meta($rate_id,'tour-rates');
            if($v!==''){$base=$v;$baseSource='tour_rate';}
        }
        // Property overrides affect only the hotel component, not the Tour base.
        $hotel=$company==='fixed_seasonal'?'fixed_seasonal':'dynamic_hotel_rates';$hotelSource='company';
        if($property_id) {
            $v=self::canonical_meta($property_id,'properties');
            if($v!==''){$hotel=$v;$hotelSource='property';}
        }
        if($rateType==='property-rates') {
            $v=self::canonical_meta($rate_id,'property-rates');
            if($v!==''){$hotel=$v;$hotelSource='property_rate';}
        }
        $context=['tour_id'=>$tour_id,'property_id'=>$property_id,'rate_id'=>$rate_id,
                  'program_term_id'=>$program_term_id,'season_id'=>$season_id,
                  'language'=>function_exists('apply_filters')?(string)apply_filters('wpml_current_language',''):''];
        $baseDecision=self::resolve('pricing.source.selection',$context+['component'=>'base'],['strategy'=>$base]);
        $hotelDecision=self::resolve('pricing.source.selection',$context+['component'=>'hotel'],['strategy'=>$hotel]);
        $base=$baseDecision['values']['strategy'];$hotel=$hotelDecision['values']['strategy'];
        $strategy=$base==='hybrid'||$hotel==='hybrid'||($base==='fixed_seasonal'&&$hotel==='dynamic_hotel_rates')
            ?'hybrid':$base;
        return [
            'contract'=>'mad4b.company-adaptive-pricing.v2','strategy'=>$strategy,
            'base_strategy'=>$base,'hotel_component_strategy'=>$hotel,
            'base_source'=>$baseDecision['receipt']['rule_id']??$baseSource,
            'hotel_source'=>$hotelDecision['receipt']['rule_id']??$hotelSource,
            'season_taxonomy'=>'season','amount_authority'=>'existing_rate_records',
            'calculation_owner'=>'native_rate_generator_and_quote_engine',
            'status'=>'source_decision_only_existing_rate_authority',
            'decision_receipts'=>[$baseDecision['receipt'],$hotelDecision['receipt']],
        ];
    }
    public static function render_policy(array $settings): array {
        $p=class_exists('MAD4B_Company_Adaptive_Controls')?MAD4B_Company_Adaptive_Controls::policy():[];
        $device=self::device();$mobile=$device==='mobile';$tablet=$device==='tablet';
        $rows=(int)($p[$mobile?'mobile_rows':($tablet?'tablet_rows':'desktop_rows')]??2);
        $px=(int)($p[$mobile?'mobile_prefetch_px':'desktop_prefetch_px']??900);
        $slider=false;
        foreach(['is_slider','carousel_enabled','scroll_slider_enabled'] as $flag)
            if(!empty($settings[$flag])&&filter_var($settings[$flag],FILTER_VALIDATE_BOOLEAN))$slider=true;
        $kind=$slider?'slider':(!empty($settings['custom_query'])?'query_builder':'grid');
        $listingId=absint($settings['lisitng_id']??0);
        $familyId=$listingId;
        if($listingId && get_post_meta($listingId,'_mad4b_render_inheritance_disabled',true)!=='1'
            && has_filter('wpml_object_id')) {
            $base=(string)apply_filters('wpml_default_language','');
            $canonical=$base?(int)apply_filters('wpml_object_id',$listingId,'jet-engine',true,$base):0;
            if($canonical)$familyId=$canonical;
        }
        $context=[
            'listing_id'=>$listingId,
            'widget_id'=>sanitize_key((string)($settings['_id']??'')),
            'template_family_id'=>$familyId,
            'device'=>$device,'query_kind'=>$kind,
            'items'=>absint($settings['posts_num']??0),
            'placement'=>in_array(($settings['mad4b_placement']??''),['above_fold','below_fold'],true)
                ?$settings['mad4b_placement']:'unknown',
            'language'=>(string)apply_filters('wpml_current_language',''),
        ];
        return self::resolve('listing.render.adaptive',$context,[
            'strategy'=>$p['listing_mode']??'ajax_only','prefetch_px'=>$px,'rows'=>$rows]);
    }
    private static function epoch_touch(array &$store,array $rule): void {
        $key=($rule['policy_id']??'').':'.($rule['scope']??'company').':'.(int)($rule['scope_id']??0);
        $store['epochs'][$key]=(int)($store['revision']??0);
        if(count($store['epochs'])>150)$store['epochs']=array_slice($store['epochs'],-150,null,true);
    }
    public static function save(array $input) {
        $store=self::state();
        $id=sanitize_key((string)($input['id']??''));
        $old=$id&&isset($store['rules'][$id])?$store['rules'][$id]:null;
        $validated=self::validate_rule($input,$old);
        if(is_wp_error($validated))return $validated;
        if(count($store['rules'])>=250 && !$old)return new WP_Error('policy_rule_limit');
        $id=$validated['id'];
        $store['revision']=(int)$store['revision']+1;
        $store['rules'][$id]=$validated;
        $store['history'][]=['change_id'=>$store['revision'],'rule_id'=>$id,'before'=>$old,
                              'after'=>$validated,'actor'=>get_current_user_id(),'at'=>gmdate('c')];
        if(count($store['history'])>60)$store['history']=array_slice($store['history'],-60);
        self::epoch_touch($store,$validated);
        update_option(self::OPTION,$store,false);
        do_action('mad4b/policy/invalidate',$validated['policy_id'],$validated['scope'],
                  $validated['scope_id'],$store['revision']);
        do_action('mad4b/policy/revision_saved',$validated,$old,$store['revision']);
        return ['rule_id'=>$id,'revision'=>$validated['revision'],'registry_revision'=>$store['revision']];
    }
    public static function rollback(string $id,int $change_id) {
        $store=self::state();$snapshot=null;
        if(!isset($store['rules'][$id]))return new WP_Error('policy_rule_missing');
        foreach(array_reverse($store['history']) as $event)
            if(($event['rule_id']??'')===$id && (int)($event['change_id']??0)===$change_id) {
                $snapshot=$event['before'];break;
            }
        if(!is_array($snapshot))return new WP_Error('policy_previous_revision_unavailable');
        $raw=$snapshot;$raw['id']=$id;
        return self::save($raw);
    }
    public static function admin_page_section(): void {
        if(!current_user_can('manage_options'))return;
        $providers=self::providers();$store=self::state();
        echo '<hr><h2>Dynamic Policy Registry v2</h2><p>Provider-discovered controls, scoped rules and version history. Active rules only affect live decisions; draft/canary/paused are inert.</p>';
        echo '<p><strong>Registry revision:</strong> '.(int)$store['revision'].' · <strong>Registered providers:</strong> '.count($providers).' · <strong>Rules:</strong> '.count($store['rules']).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_policy_registry_save');
        echo '<input type="hidden" name="action" value="mad4b_policy_registry_save"><table class="form-table">';
        echo '<tr><th>Provider / Policy</th><td><select name="rule[provider_id]" id="mad4b-pv2-provider">';
        foreach($providers as $id=>$spec)echo '<option value="'.esc_attr($id).'">'.esc_html($spec['label'].' · '.$spec['policy_id']).'</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Rule ID (edit existing)</th><td><input name="rule[id]" placeholder="Leave blank for new rule"></td></tr>';
        echo '<tr><th>Scope</th><td><select name="rule[scope]">';
        foreach(['company','template_family','listing','widget','program','season','tour','property','rate'] as $scope)
            echo '<option value="'.esc_attr($scope).'">'.esc_html($scope).'</option>';
        echo '</select> <input name="rule[scope_id]" placeholder="Scope ID (0 for company; widget hex allowed)"></td></tr>';
        echo '<tr><th>State</th><td><select name="rule[state]">';
        foreach(['draft','active','canary','paused'] as $state)echo '<option value="'.esc_attr($state).'">'.esc_html(ucfirst($state)).'</option>';
        echo '</select> <label>Priority <input name="rule[priority]" type="number" min="-100" max="100" value="0"></label></td></tr>';
        echo '<tr><th>Conditions</th><td>';
        foreach(['device'=>'Device: mobile/tablet/desktop','placement'=>'Placement: below_fold/above_fold/unknown',
                 'query_kind'=>'Type: grid/slider/query_builder','component'=>'Pricing component: base/hotel',
                 'language'=>'Language code','min_items'=>'Minimum items'] as $k=>$label)
            echo '<label style="display:inline-block;margin:3px 12px 3px 0">'.esc_html($label).' <input name="rule[conditions]['.esc_attr($k).']" size="12"></label>';
        echo '</td></tr><tr><th>Validity (UTC)</th><td><input type="date" name="rule[valid_from]"> to <input type="date" name="rule[valid_to]"></td></tr>';
        echo '<tr><th>Provider fields</th><td>';
        foreach($providers as $id=>$spec) {
            echo '<fieldset class="mad4b-pv2-fields" data-provider="'.esc_attr($id).'"><legend>'.esc_html($spec['label']).'</legend>';
            foreach($spec['fields'] as $key=>$field) {
                echo '<label style="display:inline-block;margin:6px">'.esc_html($key).' ';
                $n='rule[values]['.$key.']';
                if(($field['type']??'')==='enum') {
                    echo '<select name="'.esc_attr($n).'"><option value="">Inherit</option>';
                    foreach($field['options'] as $k=>$label)
                        echo '<option value="'.esc_attr($k).'">'.esc_html($label).'</option>';
                    echo '</select>';
                } elseif(($field['type']??'')==='int')
                    echo '<input type="number" name="'.esc_attr($n).'" min="'.(int)$field['min'].'" max="'.(int)$field['max'].'" placeholder="Inherit">';
                echo '</label>';
            }
            echo '</fieldset>';
        }
        echo '</td></tr></table>';
        submit_button('Save policy revision');
        echo '</form><script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("mad4b-pv2-provider");if(!s)return;function sync(){document.querySelectorAll(".mad4b-pv2-fields").forEach(function(f){var on=f.dataset.provider===s.value;f.hidden=!on;f.querySelectorAll("input,select").forEach(function(e){e.disabled=!on;});});}s.addEventListener("change",sync);sync();});</script>';
        if($store['rules']){
            echo '<h3>Saved rules</h3><table class="widefat striped"><thead><tr><th>ID</th><th>Policy</th><th>Scope</th><th>State</th><th>Revision</th><th>Values</th><th>Recovery</th></tr></thead><tbody>';
            foreach($store['rules'] as $r){
                echo '<tr><td><code>'.esc_html($r['id']).'</code></td><td>'.esc_html($r['policy_id']).'</td><td>'.esc_html($r['scope'].' #'.$r['scope_id']).'</td><td>'.esc_html($r['state']).'</td><td>'.(int)$r['revision'].'</td><td><code>'.esc_html(wp_json_encode($r['values'])).'</code></td><td>';
                $prev=null;
                foreach(array_reverse($store['history']) as $e)if(($e['rule_id']??'')===$r['id']&&is_array($e['before'])){$prev=$e;break;}
                if($prev){
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('mad4b_policy_registry_rollback');
                    echo '<input type="hidden" name="action" value="mad4b_policy_registry_rollback"><input type="hidden" name="rule_id" value="'.esc_attr($r['id']).'"><input type="hidden" name="change_id" value="'.(int)$prev['change_id'].'">';
                    echo '<button class="button" type="submit">Restore previous revision</button></form>';
                } else echo 'First revision';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p>Decisions retain source and revision receipts. Prices remain sourced from existing Rate records; publishing and checkout authority are unchanged.</p>';
    }
    public static function admin_save(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_policy_registry_save');
        $raw=isset($_POST['rule'])&&is_array($_POST['rule'])?wp_unslash($_POST['rule']):[];
        $result=self::save($raw);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),400);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-adaptive-controls','policy_saved'=>$result['registry_revision']],admin_url('options-general.php')));exit;
    }
    public static function admin_rollback(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_policy_registry_rollback');
        $id=sanitize_key((string)($_POST['rule_id']??''));$change=absint($_POST['change_id']??0);
        $result=self::rollback($id,$change);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),400);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-adaptive-controls','policy_restored'=>$result['registry_revision']],admin_url('options-general.php')));exit;
    }
    public static function report_summary(): array {
        $store=self::state();
        $counts=['active'=>0,'draft'=>0,'canary'=>0,'paused'=>0];
        foreach((array)$store['rules'] as $rule) {
            $state=(string)($rule['state']??'draft');
            if(isset($counts[$state]))$counts[$state]++;
        }
        $last=end($store['history']);
        return [
            'contract'=>'mad4b.policy.registry.summary.v2',
            'provider'=>'mad4b-company-policy-registry',
            'version'=>self::VERSION,
            'revision'=>(int)$store['revision'],
            'provider_ids'=>array_keys(self::providers()),
            'rule_count'=>count((array)$store['rules']),
            'states'=>$counts,
            'change_at'=>is_array($last)?($last['at']??null):null,
            'change_revision'=>is_array($last)?(int)($last['change_id']??0):null,
            'execution_location'=>'company_local',
            'pricing_authority'=>'existing_native_rate_records',
            'policy_receipts'=>'on_demand',
            'front_end_performance_verified'=>false,
        ];
    }
    public static function api_permission(): bool {
        return current_user_can('manage_options')
            || (bool)apply_filters('mad4b/policy_registry/summary_permission',false);
    }
    public static function register_rest_routes(): void {
        register_rest_route('mad4b-policy/v2','/summary',[
            'methods'=>'GET',
            'permission_callback'=>[self::class,'api_permission'],
            'callback'=>static fn()=>rest_ensure_response(self::report_summary()),
        ]);
    }
    public static function readiness($providers): array {
        if(!is_array($providers))$providers=[];
        $state=self::state();
        $providers['dynamic_policy_registry_v2']=[
            'version'=>self::VERSION,'providers'=>array_keys(self::providers()),
            'registry_revision'=>(int)$state['revision'],'rules'=>count($state['rules']),
            'active'=>count(array_filter($state['rules'],static fn($r)=>($r['state']??'')==='active')),
            'status'=>'READY','amount_authority'=>'existing_rate_records'];
        return $providers;
    }
    public static function boot(): void {
        add_action('mad4b/company_adaptive/page_after',[self::class,'admin_page_section'],10,0);
        add_action('admin_post_mad4b_policy_registry_save',[self::class,'admin_save']);
        add_action('admin_post_mad4b_policy_registry_rollback',[self::class,'admin_rollback']);
        add_filter('mad4b_company_reconcile_component_readiness',[self::class,'readiness'],36);
        add_action('rest_api_init',[self::class,'register_rest_routes']);
    }
}
MAD4B_Company_Policy_Registry_V2::boot();
function mad4b_policy_register_provider(string $id,array $definition): bool {
    return MAD4B_Company_Policy_Registry_V2::register_provider($id,$definition);
}
function mad4b_policy_resolve(string $policy_id,array $context=[],array $defaults=[]): array {
    return MAD4B_Company_Policy_Registry_V2::resolve($policy_id,$context,$defaults);
}
