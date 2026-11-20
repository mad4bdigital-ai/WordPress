<?php
/**
 * MAD4B Shared Product Core — Last Minute Pricing Policy v1.
 * Shared read-only price adjustment; does not change any Rate, Woo product, cart or order.
 */
defined('ABSPATH') || exit;
final class MAD4B_Shared_Last_Minute_V1 {
    const OPTION = 'mad4b_last_minute_policy_v1';
    const META = '_mad4b_last_minute_policy_v1';
    const VERSION = '1.0.0';
    public static function active(): bool {
        return (defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true)
            || (defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true);
    }
    public static function defaults(): array {
        return ['mode'=>'off', 'days'=>7, 'min_days'=>0, 'percent'=>0, 'adjustment'=>'discount',
            'from'=>'', 'until'=>'', 'allow_stack'=>false,
            'inventory_mode'=>'off', 'scarce_below_percent'=>25, 'abundant_above_percent'=>65,
            'scarce_adjustment'=>'markup','scarce_percent'=>10,
            'abundant_adjustment'=>'discount','abundant_percent'=>15,
            'scarce_start_days'=>7,'scarce_end_days'=>0,
            'abundant_start_days'=>7,'abundant_end_days'=>0,
            'scarce_campaign_mode'=>'draft','abundant_campaign_mode'=>'draft',
            'scarce_campaign_hours'=>24,'abundant_campaign_hours'=>48];
    }
    public static function validate($raw, bool $isGlobal=false) {
        if (!is_array($raw)) return new WP_Error('last_minute_policy_not_array');
        $allowed = array_keys(self::defaults());
        if (array_diff(array_keys($raw),$allowed)) return new WP_Error('last_minute_policy_extra_fields');
        $mode=(string)($raw['mode']??($isGlobal?'off':'inherit'));
        if (!in_array($mode, $isGlobal?['on','off']:['on','off','inherit'],true))
            return new WP_Error('last_minute_policy_mode');
        $days=filter_var($raw['days']??7,FILTER_VALIDATE_INT);
        $min=filter_var($raw['min_days']??0,FILTER_VALIDATE_INT);
        $pct=$raw['percent']??0;
        if ($days===false || $days<0 || $days>365 || $min===false || $min<0 || $min>$days
            || !is_scalar($pct) || !is_numeric($pct) || !is_finite((float)$pct)
            || (float)$pct<0 || (float)$pct>60) return new WP_Error('last_minute_policy_value');
        $adjustment=(string)($raw['adjustment']??'discount');
        if (!in_array($adjustment,['discount','markup'],true))return new WP_Error('last_minute_policy_adjustment');
        $from=(string)($raw['from']??'');$until=(string)($raw['until']??'');
        foreach([$from,$until] as $date) {
            if ($date!=='' && !self::parse($date))return new WP_Error('last_minute_policy_date');
        }
        if ($from!=='' && $until!=='' && $from>$until)return new WP_Error('last_minute_policy_window');
        $inventoryMode=(string)($raw['inventory_mode']??'off');
        if(!in_array($inventoryMode,['off','adaptive'],true))
            return new WP_Error('last_minute_inventory_mode_invalid');
        $scarce=filter_var($raw['scarce_below_percent']??25,FILTER_VALIDATE_INT);
        $abundant=filter_var($raw['abundant_above_percent']??65,FILTER_VALIDATE_INT);
        if($scarce===false||$abundant===false||$scarce<0||$scarce>80
           ||$abundant<1||$abundant>100||$abundant<=$scarce)
            return new WP_Error('last_minute_inventory_threshold_invalid');
        $stockRules=[];
        foreach(['scarce','abundant'] as $band) {
            $action=(string)($raw[$band.'_adjustment']??($band==='scarce'?'markup':'discount'));
            $pctRow=$raw[$band.'_percent']??($band==='scarce'?10:15);
            if(!in_array($action,['discount','markup','none'],true)
               ||!is_scalar($pctRow)||!is_numeric($pctRow)||!is_finite((float)$pctRow)
               ||(float)$pctRow<0||(float)$pctRow>60)
                return new WP_Error('last_minute_inventory_band_invalid');
            $stockRules[$band.'_adjustment']=$action;
            $stockRules[$band.'_percent']=round((float)$pctRow,3);
        }
        $branchRules=[];
        foreach(['scarce','abundant'] as $branch) {
            // Backward compatibility with the existing general last-minute window.
            $start=self::bounded_int($raw[$branch.'_start_days']??$days,0,365);
            $end=self::bounded_int($raw[$branch.'_end_days']??$min,0,365);
            $hours=self::bounded_int($raw[$branch.'_campaign_hours']??($branch==='scarce'?24:48),1,720);
            $campaign=(string)($raw[$branch.'_campaign_mode']??'draft');
            if($start===null||$end===null||$end>$start||$hours===null
                || !in_array($campaign,['off','draft'],true))
                return new WP_Error('last_minute_branch_duration_invalid');
            $branchRules[$branch.'_start_days']=$start;
            $branchRules[$branch.'_end_days']=$end;
            $branchRules[$branch.'_campaign_hours']=$hours;
            $branchRules[$branch.'_campaign_mode']=$campaign;
        }
        return array_merge(['mode'=>$mode,'days'=>$days,'min_days'=>$min,
            'percent'=>round((float)$pct,3),'adjustment'=>$adjustment,
            'from'=>$from,'until'=>$until,
            'allow_stack'=>in_array($raw['allow_stack']??false,[true,1,'1','on','yes'],true),
            'inventory_mode'=>$inventoryMode,'scarce_below_percent'=>$scarce,
            'abundant_above_percent'=>$abundant],$stockRules,$branchRules);
    }
    private static function bounded_int($v,int $min,int $max):?int {
        $n=filter_var($v,FILTER_VALIDATE_INT);
        return $n!==false&&$n>=$min&&$n<=$max?$n:null;
    }
    public static function effective_policy_for(int $tour,int $rate=0):array|WP_Error {
        $source=self::source($tour,$rate);
        $policy=self::validate($source['row'],$source['scope']==='global');
        if(is_wp_error($policy))return $policy;
        return ['source'=>$source,'policy'=>$policy];
    }
    public static function flag_window(array $policy,string $branch,string $travelDate):array {
        $date=self::parse($travelDate);
        if(!$date||!in_array($branch,['scarce','abundant'],true))
            return ['active'=>false,'reason'=>'invalid_date_or_branch'];
        $today=new DateTimeImmutable('today',wp_timezone());
        $days=(int)$today->diff($date)->format('%r%a');
        $start=$policy[$branch.'_start_days'];
        $end=$policy[$branch.'_end_days'];
        // Store absolute campaign dates in local WP timezone. No stale provider data.
        $open=$date->modify('-'.$start.' days');
        $close=$date->modify('-'.$end.' days');
        $eligible=$days>=$end&&$days<=$start && $days>=0
             && ($policy['from']===''||$travelDate>=$policy['from'])
             && ($policy['until']===''||$travelDate<=$policy['until']);
        return ['active'=>$eligible,'days_until_travel'=>$days,
            'start_days'=>$start,'end_days'=>$end,
            'flag_opens_on'=>$open->format('Y-m-d'),
            'flag_closes_on'=>$close->format('Y-m-d'),
            'timezone'=>wp_timezone_string(),
            'reason'=>$eligible?'window_active':'outside_branch_window'];
    }
    public static function parse($raw): ?DateTimeImmutable {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,wp_timezone());
        return $d&&$d->format('Y-m-d')===$raw?$d:null;
    }
    private static function wpml_lineage(int $id,string $type,bool $taxonomy=false):array {
        if(!$id)return [];
        $values=[$id];
        if(!has_filter('wpml_object_id'))return $values;
        $language=(string)apply_filters('wpml_default_language','en');
        if($language==='')return $values;
        $default=(int)apply_filters('wpml_object_id',$id,$type,false,$language);
        if(!$default||$default===$id)return $values;
        if($taxonomy){
            $source=get_term($id,$type);$canonical=get_term($default,$type);
            if(!$source instanceof WP_Term || !$canonical instanceof WP_Term)return $values;
        }elseif(get_post_type($default)!==$type)return $values;
        $kind=($taxonomy?'tax_':'post_').$type;
        $from=(int)apply_filters('wpml_element_trid',null,$id,$kind);
        $to=(int)apply_filters('wpml_element_trid',null,$default,$kind);
        if($from&&$to===$from)$values[]=$default;
        return $values;
    }
    private static function source(int $tour,int $rate): array {
        if($rate&&get_post_type($rate)==='tour-rates'){
            // Per-language explicit override wins; otherwise inherit canonical
            // WPML source policy without copying it into every translation.
            foreach(self::wpml_lineage($rate,'tour-rates') as $candidate){
                $row=get_post_meta($candidate,self::META,true);
                if(is_array($row)&&($row['mode']??'inherit')!=='inherit')
                    return ['scope'=>'rate','target'=>$candidate,'row'=>$row];
            }
        }
        if($tour&&get_post_type($tour)==='tours-and-activities'){
            foreach(self::wpml_lineage($tour,'tours-and-activities') as $candidate){
                $row=get_post_meta($candidate,self::META,true);
                if(is_array($row)&&($row['mode']??'inherit')!=='inherit')
                    return ['scope'=>'tour','target'=>$candidate,'row'=>$row];
            }
            $terms=wp_get_post_terms($tour,'tour-types',['fields'=>'ids']);
            if(!is_wp_error($terms)) {
                $candidates=[];
                foreach($terms as $term) {
                    foreach(self::wpml_lineage((int)$term,'tour-types',true) as $id){
                        $row=get_term_meta($id,self::META,true);
                        if(is_array($row)&&($row['mode']??'inherit')!=='inherit'){
                            $candidates[$term]=['scope'=>'tour_type','target'=>$id,'row'=>$row];
                            break;
                        }
                    }
                }
                // Multiple tour types are ambiguous. Never combine two finance policies.
                if(count($candidates)>1)
                    return ['scope'=>'conflicting_tour_types','target'=>0,'row'=>['mode'=>'off']];
                if(count($candidates)===1)return reset($candidates);
            }
        }
        return ['scope'=>'global','target'=>0,'row'=>get_option(self::OPTION,self::defaults())];
    }
    public static function quote(array $quote,array $config): array {
        if (!self::active() || isset($quote['last_minute']) || isset($quote['last_minute_decision'])
            || empty($config['travel_date'])
            || empty($quote['lines']) || !is_array($quote['lines']))return $quote;
        $date=self::parse($config['travel_date']);
        if (!$date)return $quote;
        $today=new DateTimeImmutable('today',wp_timezone());
        $days=(int)$today->diff($date)->format('%r%a');
        if ($days<0)return $quote;
        $source=self::source(absint($config['tour_id']??0),absint($config['rate_id']??0));
        $policy=self::validate($source['row'], $source['scope']==='global');
        if (is_wp_error($policy) || $policy['mode']!=='on'
            || ($policy['inventory_mode']==='off'
               && ($days<$policy['min_days']||$days>$policy['days']))
            || ($policy['from']!==''&&$config['travel_date']<$policy['from'])
            || ($policy['until']!==''&&$config['travel_date']>$policy['until']))return $quote;
        $adjustment=$policy['adjustment'];
        $percent=(float)$policy['percent'];
        $stockReceipt=null;
        $stockBand='not_requested';
        if($policy['inventory_mode']==='adaptive'){
            $stockReceipt=function_exists('shared_product_inventory_snapshot_v1')
               ?shared_product_inventory_snapshot_v1($config)
               :['status'=>'unknown','reason'=>'inventory_provider_not_loaded'];
            if(($stockReceipt['eligible_for_dynamic_pricing']??false)!==true){
                $quote['last_minute_decision']=[
                  'status'=>'skipped_inventory_not_verified',
                  'reason'=>$stockReceipt['reason']??$stockReceipt['status']??'unknown',
                  'policy_scope'=>$source['scope'],'travel_date'=>$config['travel_date'],
                  'checkout_ready'=>false];
                return $quote;
            }
            $pct=(float)$stockReceipt['available_percent'];
            if($pct<=$policy['scarce_below_percent'])$stockBand='scarce';
            elseif($pct>=$policy['abundant_above_percent'])$stockBand='abundant';
            else $stockBand='balanced';
            if($stockBand==='balanced'){
                $quote['last_minute_decision']=['status'=>'no_change_balanced_stock',
                 'stock_percent'=>$pct,'travel_date'=>$config['travel_date'],'checkout_ready'=>false];
                return $quote;
            }
            $window=self::flag_window($policy,$stockBand,(string)$config['travel_date']);
            if(!$window['active']){
                $quote['last_minute_decision']=[
                    'status'=>'skipped_outside_branch_window','inventory_band'=>$stockBand,
                    'flag_window'=>$window,'checkout_ready'=>false];
                return $quote;
            }
            $adjustment=$policy[$stockBand.'_adjustment'];
            $percent=(float)$policy[$stockBand.'_percent'];
        }
        if($adjustment==='none'||$percent<=0)return $quote;
        $baseIndex=null;
        foreach($quote['lines'] as $i=>$line)if(($line['kind']??'')==='base'){$baseIndex=$i;break;}
        if ($baseIndex===null)return $quote;
        $base=$quote['lines'][$baseIndex];
        if(!$policy['allow_stack'] && (isset($base['strategy_presets'])||isset($base['market_pricing'])
            ||isset($base['private_pricing'])||isset($base['package_level'])||isset($base['group_tier'])))return $quote;
        $old=$base['amount']??null;$total=$quote['total']??null;
        if(!is_numeric($old)||!is_finite((float)$old)||(float)$old<=0
            || !is_numeric($total)||!is_finite((float)$total)||(float)$total<=0)return $quote;
        // Booking v2 may place Rate-owned optional units and modifiers INSIDE
        // its first base line. Never discount those with a tour-only flag.
        $protected=$base['last_minute_protected_amount']??0;
        if(!is_numeric($protected)||!is_finite((float)$protected)
           ||(float)$protected<0||(float)$protected>=(float)$old) {
            $quote['last_minute_decision']=['status'=>'skipped_invalid_component_allocation',
                'checkout_ready'=>false];
            return $quote;
        }
        $protected=round((float)$protected,2);
        $subject=round((float)$old-$protected,2);
        if($subject<=0)return $quote;
        $factor=$adjustment==='discount'?-1:1;
        $updatedSubject=round($subject*(1+$factor*$percent/100),2);
        $updated=round($updatedSubject+$protected,2);
        $newTotal=round((float)$total-(float)$old+$updated,2);
        if($updated<=0||$newTotal<=0)return $quote;
        $receipt=['contract'=>'mad4b.last-minute.v1','policy_scope'=>$source['scope'],
            'policy_target'=>$source['target'],'policy_revision'=>hash('sha256',wp_json_encode($policy)),
            'travel_date'=>$config['travel_date'],'reference_date'=>$today->format('Y-m-d'),
            'timezone'=>wp_timezone_string(),'days_until_travel'=>$days,
            'threshold_days'=>$policy['days'],'min_days'=>$policy['min_days'],
            'adjustment'=>$adjustment,'percent'=>$percent,
            'inventory_mode'=>$policy['inventory_mode'],'inventory_band'=>$stockBand,
            'flag_window'=>$policy['inventory_mode']==='adaptive'
                ?self::flag_window($policy,$stockBand,(string)$config['travel_date']):null,
            'inventory_snapshot'=>$stockReceipt?[
              'source_id'=>$stockReceipt['source_id'],'source_revision'=>$stockReceipt['source_revision'],
              'snapshot_sha256'=>$stockReceipt['snapshot_sha256'],
              'resource_key'=>$stockReceipt['resource_key'],
              'capacity'=>$stockReceipt['capacity'],'sold'=>$stockReceipt['sold'],
              'held'=>$stockReceipt['held'],'available'=>$stockReceipt['available'],
              'available_percent'=>$stockReceipt['available_percent'],
              'as_of_utc'=>$stockReceipt['as_of_utc'],
              'expires_at_utc'=>$stockReceipt['expires_at_utc']]:null,
            'adjusted_subject'=>'tour_base_only',
            'base_subject_before'=>$subject,'base_subject_after'=>$updatedSubject,
            'protected_components_amount'=>$protected,
            'amount_before'=>round((float)$old,2),'amount_after'=>$updated,
            'currency'=>$quote['currency']??null,'status'=>'price_preview_only_no_booking_authority'];
        $quote['lines'][$baseIndex]['amount']=$updated;
        $quote['lines'][$baseIndex]['last_minute']=$receipt;
        $quote['total']=$newTotal;
        $quote['last_minute']=$receipt;
        // Preserve the original checkout/booking authority. This engine never grants one.
        return $quote;
    }
    public static function register_admin(): void {
        if (!class_exists('WooCommerce'))return;
        add_submenu_page('woocommerce','Last Minute Pricing','Last Minute Pricing','manage_woocommerce',
            'mad4b-last-minute',[self::class,'page']);
    }
    public static function page(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mad4b_last_minute_save'])) {
            check_admin_referer('mad4b_last_minute_global');
            $raw=self::request($_POST);
            $policy=self::validate($raw,true);
            if(is_wp_error($policy))echo '<div class="notice notice-error"><p>'.esc_html($policy->get_error_code()).'</p></div>';
            else {
                update_option(self::OPTION,$policy,false);
                echo '<div class="notice notice-success"><p>Last Minute policy saved. Original product prices unchanged.</p></div>';
            }
        }
        $raw=get_option(self::OPTION,self::defaults());
        $row=self::validate($raw,true);if(is_wp_error($row))$row=self::defaults();
        echo '<div class="wrap"><h1>Last Minute · Shared Product Core</h1>';
        echo '<p>Single pricing authority for Commerce and Presentation. Disabled by default. Travel date is compared against today in WordPress timezone. Source priority: Rate → Tour → Tour Type → Global.</p>';
        echo '<form method="post">';wp_nonce_field('mad4b_last_minute_global');
        self::fields($row,true);
        submit_button('Save Global Policy','primary','mad4b_last_minute_save');
        echo '</form><p>Tour and Tour Rate editors contain scoped overrides. If other modifiers are already applied, this policy skips them unless stacking is explicitly enabled.</p></div>';
    }
    private static function request(array $post): array {
        $v=isset($post['mad4b_lm'])&&is_array($post['mad4b_lm'])?wp_unslash($post['mad4b_lm']):[];
        return ['mode'=>sanitize_key($v['mode']??'inherit'),'days'=>$v['days']??7,
            'min_days'=>$v['min_days']??0,'percent'=>$v['percent']??0,
            'adjustment'=>sanitize_key($v['adjustment']??'discount'),
            'from'=>sanitize_text_field($v['from']??''),'until'=>sanitize_text_field($v['until']??''),
            'allow_stack'=>!empty($v['allow_stack']),
            'inventory_mode'=>sanitize_key($v['inventory_mode']??'off'),
            'scarce_below_percent'=>$v['scarce_below_percent']??25,
            'abundant_above_percent'=>$v['abundant_above_percent']??65,
            'scarce_adjustment'=>sanitize_key($v['scarce_adjustment']??'markup'),
            'scarce_percent'=>$v['scarce_percent']??10,
            'abundant_adjustment'=>sanitize_key($v['abundant_adjustment']??'discount'),
            'abundant_percent'=>$v['abundant_percent']??15,
            'scarce_start_days'=>$v['scarce_start_days']??($v['days']??7),
            'scarce_end_days'=>$v['scarce_end_days']??($v['min_days']??0),
            'abundant_start_days'=>$v['abundant_start_days']??($v['days']??7),
            'abundant_end_days'=>$v['abundant_end_days']??($v['min_days']??0),
            'scarce_campaign_mode'=>sanitize_key($v['scarce_campaign_mode']??'draft'),
            'abundant_campaign_mode'=>sanitize_key($v['abundant_campaign_mode']??'draft'),
            'scarce_campaign_hours'=>$v['scarce_campaign_hours']??24,
            'abundant_campaign_hours'=>$v['abundant_campaign_hours']??48];
    }
    public static function fields(array $row,bool $global=false): void {
        $sel=static function($name,$choices)use($row) {
            echo '<select name="mad4b_lm['.esc_attr($name).']">';
            foreach($choices as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected((string)($row[$name]??''),$key,false).'>'.esc_html($label).'</option>';
            echo '</select>';
        };
        echo '<table class="form-table"><tbody><tr><th>Policy state</th><td>';
        $sel('mode',$global?['off'=>'Off','on'=>'Enabled']:['inherit'=>'Inherit','off'=>'Stop / disabled','on'=>'Override enabled']);
        echo '</td></tr><tr><th>Days until travel</th><td><input type="number" name="mad4b_lm[min_days]" min="0" max="365" value="'.esc_attr((string)($row['min_days']??0)).'"> to <input type="number" name="mad4b_lm[days]" min="0" max="365" value="'.esc_attr((string)($row['days']??7)).'"> days</td></tr>';
        echo '<tr><th>Adjustment</th><td>';$sel('adjustment',['discount'=>'Discount','markup'=>'Markup']);
        echo ' <input type="number" step="0.001" min="0" max="60" name="mad4b_lm[percent]" value="'.esc_attr((string)($row['percent']??0)).'"> %</td></tr>';
        echo '<tr><th>Optional travel window</th><td>From <input type="date" name="mad4b_lm[from]" value="'.esc_attr((string)($row['from']??'')).'"> Until <input type="date" name="mad4b_lm[until]" value="'.esc_attr((string)($row['until']??'')).'"></td></tr>';
        echo '<tr><th>Inventory / demand mode</th><td>';
        $sel('inventory_mode',['off'=>'Date rule only','adaptive'=>'Adaptive: verified travel-date allotment required']);
        echo '<p class="description">No provider or stale stock = NO dynamic adjustment. WooCommerce instock and Group Size are not inventory.</p></td></tr>';
        echo '<tr><th>Scarce inventory</th><td><label>Available % at or below</label> <input type="number" min="0" max="80" name="mad4b_lm[scarce_below_percent]" value="'.esc_attr((string)($row['scarce_below_percent']??25)).'"> % ; ';
        $sel('scarce_adjustment',['none'=>'None','markup'=>'Increase price','discount'=>'Discount']);
        echo ' <input type="number" min="0" max="60" step="0.001" name="mad4b_lm[scarce_percent]" value="'.esc_attr((string)($row['scarce_percent']??10)).'"> %</td></tr>';
        echo '<tr><th>Abundant inventory</th><td><label>Available % at or above</label> <input type="number" min="1" max="100" name="mad4b_lm[abundant_above_percent]" value="'.esc_attr((string)($row['abundant_above_percent']??65)).'"> % ; ';
        $sel('abundant_adjustment',['none'=>'None','discount'=>'Discount','markup'=>'Increase price']);
        echo ' <input type="number" min="0" max="60" step="0.001" name="mad4b_lm[abundant_percent]" value="'.esc_attr((string)($row['abundant_percent']??15)).'"> %</td></tr>';
        foreach(['scarce'=>'Scarce stock','abundant'=>'Abundant stock'] as $branch=>$label) {
            echo '<tr><th>'.esc_html($label).' — flag window</th><td>';
            echo '<label>Opens when departure is </label> <input type="number" min="0" max="365" name="mad4b_lm['.esc_attr($branch).'_start_days]" value="'.esc_attr((string)($row[$branch.'_start_days']??7)).'"> days away; ';
            echo '<label>closes when </label> <input type="number" min="0" max="365" name="mad4b_lm['.esc_attr($branch).'_end_days]" value="'.esc_attr((string)($row[$branch.'_end_days']??0)).'"> days away.';
            echo '<p>On event: ';$sel($branch.'_campaign_mode',['off'=>'No campaign','draft'=>'Generate review-only campaign draft']);
            echo ' for <input type="number" min="1" max="720" name="mad4b_lm['.esc_attr($branch).'_campaign_hours]" value="'.esc_attr((string)($row[$branch.'_campaign_hours']??24)).'"> hours (never later than the flag closing date).</p></td></tr>';
        }
        echo '<tr><th>Stack with other pricing modifiers</th><td><label><input type="checkbox" name="mad4b_lm[allow_stack]" value="1" '.checked(!empty($row['allow_stack']),true,false).'> Explicitly allow stacking</label></td></tr></tbody></table>';
    }
    public static function add_meta_boxes(): void {
        foreach(['tours-and-activities','tour-rates'] as $type)
            add_meta_box('mad4b-last-minute-policy','Last Minute · Shared Pricing',
                [self::class,'meta_box'],$type,'normal','default');
    }
    public static function meta_box($post): void {
        wp_nonce_field('mad4b_last_minute_meta','mad4b_lm_nonce');
        $row=get_post_meta($post->ID,self::META,true);
        $policy=is_array($row)?self::validate($row):self::validate(['mode'=>'inherit']);
        if(is_wp_error($policy))$policy=self::defaults()+['mode'=>'inherit'];
        echo '<p>Inherit site-wide pricing; choose Override to apply to this exact '.esc_html($post->post_type).'.</p>';
        self::fields($policy);
    }
    public static function save_meta($id,$post): void {
        if(!in_array($post->post_type,['tours-and-activities','tour-rates'],true)
            || !isset($_POST['mad4b_lm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_lm_nonce'])),'mad4b_last_minute_meta')
            || wp_is_post_revision($id) || (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)
            || !current_user_can('edit_post',$id))return;
        $r=self::validate(self::request($_POST));
        if(is_wp_error($r))return;
        if($r['mode']==='inherit')delete_post_meta($id,self::META);
        else update_post_meta($id,self::META,$r);
    }
    public static function term_fields($term): void {
        $row=get_term_meta($term->term_id,self::META,true);
        $policy=is_array($row)?self::validate($row):self::validate(['mode'=>'inherit']);
        if(is_wp_error($policy))$policy=self::defaults()+['mode'=>'inherit'];
        echo '<tr class="form-field"><th>Last Minute Pricing</th><td>';
        wp_nonce_field('mad4b_last_minute_term','mad4b_lm_term_nonce');
        self::fields($policy);
        echo '</td></tr>';
    }
    public static function save_term($id):void {
        if(!isset($_POST['mad4b_lm_term_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_lm_term_nonce'])),'mad4b_last_minute_term')
            ||!current_user_can('manage_categories'))return;
        $r=self::validate(self::request($_POST));if(is_wp_error($r))return;
        if($r['mode']==='inherit')delete_term_meta($id,self::META);
        else update_term_meta($id,self::META,$r);
    }
}
function shared_product_last_minute_quote_adjust_v1($quote,$config) {
    return is_array($quote)&&is_array($config)?MAD4B_Shared_Last_Minute_V1::quote($quote,$config):$quote;
}
if (MAD4B_Shared_Last_Minute_V1::active()) {
    add_action('admin_menu',['MAD4B_Shared_Last_Minute_V1','register_admin'],28);
    add_action('add_meta_boxes',['MAD4B_Shared_Last_Minute_V1','add_meta_boxes'],35);
    add_action('save_post',['MAD4B_Shared_Last_Minute_V1','save_meta'],35,2);
    add_action('tour-types_edit_form_fields',['MAD4B_Shared_Last_Minute_V1','term_fields'],12);
    add_action('edited_tour-types',['MAD4B_Shared_Last_Minute_V1','save_term'],12);
}
