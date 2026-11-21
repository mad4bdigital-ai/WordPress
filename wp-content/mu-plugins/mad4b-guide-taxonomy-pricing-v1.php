<?php
/**
 * Plugin Name: MAD4B Guide Language Taxonomy Cost
 * Description: Source-currency per-guide-day costs in JetEngine Guide Languages terms, audited approvals and native pricing workspace UI.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Guide_Taxonomy_Pricing_V1 {
    public const META_BOX='mad4b-guide-language-cost-v1';
    public const CERT='_mad4b_guide_cost_cert_v1';
    public const AUDIT='_mad4b_guide_cost_audit_v1';
    public const HISTORY='mad4b_guide_global_cost_audit_v1';
    public const ACTION='mad4b_guide_taxonomy_cost_save_v1';
    public const CURRENCIES=['EGP','USD','EUR','GBP'];
    public const FIELDS=[
        'mad4b_guide_cost_mode','mad4b_guide_base_cost','mad4b_guide_cost_currency',
        'mad4b_guide_supplier','mad4b_guide_cost_unit','mad4b_guide_cost_status',
        'mad4b_guide_cost_valid_from','mad4b_guide_cost_valid_to','mad4b_guide_cost_note',
    ];

    public static function permitted(): bool {
        return current_user_can('commerce_manage_pricing') &&
            current_user_can('manage_woocommerce');
    }
    public static function term(int $id): WP_Term|WP_Error {
        $term=get_term($id,'guide-languages');
        if(!($term instanceof WP_Term)||str_starts_with((string)$term->slug,'qa-'))
            return new WP_Error('guide_cost_language_term_invalid');
        $canonical=apply_filters('wpml_object_id',$id,'guide-languages',false,'en');
        if(is_numeric($canonical) && (int)$canonical>0 && (int)$canonical!==$id)
            return new WP_Error('guide_cost_canonical_term_required');
        $code=MAD4B_Guide_Daily_Base_Cost_V1::identity($id);
        if(is_wp_error($code))return $code;
        return $term;
    }
    public static function nativeBox(): array {
        $select=static function(string $name,string $title,array $options,string $default=''):array {
            return ['object_type'=>'field','width'=>'100%','options_source'=>'manual',
                'isNested'=>false,'name'=>$name,'title'=>$title,'type'=>'select',
                'default_val'=>$default,'description'=>'Draft field. Pricing approval requires company Pricing Controls review.',
                'options'=>array_map(static fn($k,$v)=>['key'=>$k,'value'=>$v],
                    array_keys($options),array_values($options)),'repeater-fields'=>[]];
        };
        $text=static function(string $name,string $title,string $type='text'):array {
            return ['object_type'=>'field','width'=>'100%','options_source'=>'manual',
                'isNested'=>false,'name'=>$name,'title'=>$title,'type'=>$type,
                'description'=>'Cost input; not a selling price or automatic approval.',
                'options'=>[],'repeater-fields'=>[]];
        };
        return [
            'id'=>self::META_BOX,
            'args'=>[
                'name'=>'MAD4B · Guide Cost per Language — Draft Fields',
                'object_type'=>'tax','allowed_tax'=>['guide-languages'],
                'allowed_post_type'=>[],'active_conditions'=>[],
            ],
            'meta_fields'=>[
                $select('mad4b_guide_cost_mode','Guide Cost Mode',['inherit'=>'Inherit company default','override'=>'Override for this language'],'inherit'),
                $text('mad4b_guide_base_cost','Cost amount per guide/day','number'),
                $select('mad4b_guide_cost_currency','Supplier original currency',[
                    'EGP'=>'EGP · Egyptian pound','USD'=>'USD · US dollar',
                    'EUR'=>'EUR · Euro','GBP'=>'GBP · British pound'],'EGP'),
                $text('mad4b_guide_supplier','Guide supplier ID (self or approved provider)'),
                $select('mad4b_guide_cost_unit','Price unit',['per_guide_day'=>'Per guide / full day'],'per_guide_day'),
                $select('mad4b_guide_cost_status','Cost review status',['draft'=>'Draft — never commercial','approved'=>'Approved — requires signed Pricing Controls review'],'draft'),
                $text('mad4b_guide_cost_valid_from','Valid from (YYYY-MM-DD)'),
                $text('mad4b_guide_cost_valid_to','Valid to (YYYY-MM-DD)'),
                $text('mad4b_guide_cost_note','Source / operator cost evidence','textarea'),
            ]
        ];
    }
    public static function registryIsPresent(array $all): bool {
        $box=$all[self::META_BOX]??null;
        return is_array($box)
            &&($box['id']??'')===self::META_BOX
            &&($box['args']['object_type']??'')==='tax'
            &&($box['args']['allowed_tax']??null)===['guide-languages']
            &&array_column((array)($box['meta_fields']??[]),'name')===self::FIELDS;
    }
    public static function raw(int $term): array {
        $raw=[];
        foreach(self::FIELDS as $field) {
            $value=get_term_meta($term,$field,true);
            $raw[$field]=is_scalar($value)?(string)$value:'';
        }
        return $raw;
    }
    public static function rawHash(array $raw): string {
        $ordered=[];
        foreach(self::FIELDS as $field)$ordered[$field]=(string)($raw[$field]??'');
        return hash('sha256',wp_json_encode($ordered));
    }
    private static function money($v): float|WP_Error {
        if(!is_scalar($v)||!preg_match('/^(?:[0-9]{1,7})(?:\.[0-9]{1,2})?$/D',(string)$v))
            return new WP_Error('guide_cost_amount_invalid');
        $number=(float)$v;
        if($number<=0||$number>1000000)return new WP_Error('guide_cost_amount_invalid');
        return $number;
    }
    public static function validDate(string $date): bool {
        if($date==='')return true;
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))return false;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d')===$date;
    }
    public static function validate(array $v): array|WP_Error {
        if(array_diff(array_keys($v),self::FIELDS)||array_diff(self::FIELDS,array_keys($v)))
            return new WP_Error('guide_cost_fields_incomplete');
        foreach($v as $val)if(!is_scalar($val))return new WP_Error('guide_cost_field_type_invalid');
        $r=array_map(static fn($x)=>trim((string)$x),$v);
        $mode=$r['mad4b_guide_cost_mode'];
        if(!in_array($mode,['inherit','override'],true))return new WP_Error('guide_cost_mode_invalid');
        $currency=strtoupper($r['mad4b_guide_cost_currency']);
        if(!in_array($currency,self::CURRENCIES,true))return new WP_Error('guide_cost_currency_not_supported');
        $r['mad4b_guide_cost_currency']=$currency;
        if(($r['mad4b_guide_cost_unit']??'')!=='per_guide_day')
            return new WP_Error('guide_cost_price_unit_invalid');
        $status=$r['mad4b_guide_cost_status'];
        if(!in_array($status,['draft','approved'],true))return new WP_Error('guide_cost_status_invalid');
        foreach(['mad4b_guide_cost_valid_from','mad4b_guide_cost_valid_to'] as $k)
            if(!self::validDate($r[$k]))return new WP_Error('guide_cost_date_invalid');
        if($r['mad4b_guide_cost_valid_from']!=='' && $r['mad4b_guide_cost_valid_to']!=='' &&
            strcmp($r['mad4b_guide_cost_valid_from'],$r['mad4b_guide_cost_valid_to'])>0)
            return new WP_Error('guide_cost_date_window_inverted');
        if(strlen($r['mad4b_guide_cost_note'])>500)
            return new WP_Error('guide_cost_evidence_too_long');
        if($mode==='override') {
            $amount=self::money($r['mad4b_guide_base_cost']);
            if(is_wp_error($amount))return $amount;
            if(!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D',$r['mad4b_guide_supplier']))
                return new WP_Error('guide_cost_supplier_invalid');
            if($r['mad4b_guide_supplier']!=='self')
                return new WP_Error('guide_cost_external_supplier_not_certified');
            if($status==='approved' && mb_strlen($r['mad4b_guide_cost_note'])<12)
                return new WP_Error('guide_cost_source_evidence_required');
        } else {
            // Inherit never materializes a second amount or currency authority.
            $r['mad4b_guide_base_cost']='';
            $r['mad4b_guide_cost_currency']='EGP';
            $r['mad4b_guide_supplier']='self';
            $r['mad4b_guide_cost_valid_from']='';
            $r['mad4b_guide_cost_valid_to']='';
        }
        return $r;
    }

    /** Normalized EGP receipt, with original supplier currency preserved. */
    public static function resolved(string|int $selection): array|WP_Error {
        $term=is_int($selection)?get_term($selection,'guide-languages'):
            get_term_by('slug',(string)$selection,'guide-languages');
        if(!($term instanceof WP_Term))return new WP_Error('guide_language_term_unavailable');
        $checked=self::term((int)$term->term_id);
        if(is_wp_error($checked))return $checked;
        $base=MAD4B_Guide_Daily_Base_Cost_V1::read();
        if(is_wp_error($base))return $base;
        $code=MAD4B_Guide_Daily_Base_Cost_V1::identity((int)$term->term_id);
        if(is_wp_error($code))return $code;
        $tier=$code==='ar'?'ar':($code==='en'?'en':'other');
        $raw=self::raw((int)$term->term_id);
        $hash=self::rawHash($raw);
        $cert=get_term_meta((int)$term->term_id,self::CERT,true);
        $mode=$raw['mad4b_guide_cost_mode'];
        $pending=false;
        if(is_array($cert)) {
            if(($cert['schema']??null)!==1 ||
                !hash_equals((string)($cert['fields_hash']??''),$hash))
                return new WP_Error('guide_cost_term_changed_since_approval');
        }
        if($mode===''||$mode==='inherit'||($mode==='override'&&$raw['mad4b_guide_cost_status']==='draft')) {
            if($mode==='override')$pending=true;
            $v=[
                'term_id'=>$term->term_id,'language_code'=>$code,'tier'=>$tier,
                'supplier'=>'self','currency'=>'EGP','source_currency'=>'EGP',
                'source_unit_cost'=>(int)$base['amounts'][$tier],
                'unit_cost'=>(float)$base['amounts'][$tier],
                'fx_rate'=>1.0,'fx_source'=>'same_currency','fx_asof'=>null,
                'policy_source'=>'global_fallback','pending_draft_override'=>$pending,
                'term_revision'=>is_array($cert)?(int)($cert['revision']??0):0,
            ];
            $v['policy_fingerprint']=hash('sha256',wp_json_encode($v));
            return $v;
        }
        if($mode!=='override')return new WP_Error('guide_cost_mode_invalid');
        if($raw['mad4b_guide_cost_status']!=='approved')
            return new WP_Error('guide_cost_term_status_unapproved');
        if(!is_array($cert))return new WP_Error('guide_cost_taxonomy_override_uncertified');
        $valid=self::validate($raw);
        if(is_wp_error($valid))return $valid;
        $today=gmdate('Y-m-d');
        if(($valid['mad4b_guide_cost_valid_from']!==''&&$today<$valid['mad4b_guide_cost_valid_from'])||
           ($valid['mad4b_guide_cost_valid_to']!==''&&$today>$valid['mad4b_guide_cost_valid_to']))
           return new WP_Error('guide_cost_term_outside_validity_window');
        $currency=$valid['mad4b_guide_cost_currency'];
        $amount=(float)$valid['mad4b_guide_base_cost'];
        $fx=1.0;$fxSource='same_currency';$asof=null;
        if($currency!=='EGP') {
            if(!function_exists('pricing_public_fx_rate'))
                return new WP_Error('guide_cost_fx_provider_unavailable');
            $rate=pricing_public_fx_rate($currency,'EGP');
            if(!is_numeric($rate)||!is_finite((float)$rate)||(float)$rate<=0)
                return new WP_Error('guide_cost_verified_fx_unavailable');
            $fx=(float)$rate;
            $fxSource='verified_public_fx_snapshot';
            $snapshot=function_exists('pricing_public_fx_snapshot')?pricing_public_fx_snapshot():null;
            $asof=is_array($snapshot)?($snapshot['fetched_at']??null):null;
            if(!is_string($asof)||$asof==='')return new WP_Error('guide_cost_fx_snapshot_missing_timestamp');
        }
        $v=[
            'term_id'=>$term->term_id,'language_code'=>$code,'tier'=>$tier,
            'supplier'=>$valid['mad4b_guide_supplier'],'currency'=>'EGP',
            'source_currency'=>$currency,'source_unit_cost'=>$amount,
            'unit_cost'=>round($amount*$fx,2),
            'fx_rate'=>$fx,'fx_source'=>$fxSource,'fx_asof'=>$asof,
            'policy_source'=>'approved_taxonomy_term','pending_draft_override'=>false,
            'term_revision'=>(int)($cert['revision']??0),
            'term_fields_hash'=>$hash,
        ];
        $v['policy_fingerprint']=hash('sha256',wp_json_encode($v));
        return $v;
    }
    public static function allLanguages(): array {
        $terms=get_terms(['taxonomy'=>'guide-languages','hide_empty'=>false,'orderby'=>'term_id','order'=>'ASC']);
        if(is_wp_error($terms))return [];
        $out=[];
        foreach($terms as $term) {
            if(!($term instanceof WP_Term)||str_starts_with((string)$term->slug,'qa-'))continue;
            $code=MAD4B_Guide_Daily_Base_Cost_V1::identity((int)$term->term_id);
            if(is_wp_error($code))continue;
            $raw=self::raw($term->term_id);
            $cert=get_term_meta($term->term_id,self::CERT,true);
            $resolved=self::resolved((int)$term->term_id);
            $out[]=['id'=>$term->term_id,'slug'=>$term->slug,'name'=>$term->name,'locale'=>$code,
                'fields'=>$raw,'revision'=>is_array($cert)?(int)($cert['revision']??0):0,
                'raw_hash'=>self::rawHash($raw),
                'cost'=>is_wp_error($resolved)?null:$resolved,
                'error'=>is_wp_error($resolved)?$resolved->get_error_code():null,
                'term_url'=>get_edit_term_link($term->term_id,'guide-languages','',false)];
        }
        return $out;
    }
    private static function ajaxError(string $error,int $code=409): never {
        wp_send_json_error(['reason'=>$error],$code);
    }
    public static function saveAjax(): void {
        if(!self::permitted())self::ajaxError('guide_cost_company_pricing_permission_required',403);
        if(!check_ajax_referer(self::ACTION,'nonce',false))
            self::ajaxError('guide_cost_nonce_invalid',403);
        $payload=(string)wp_unslash($_POST['payload']??'');
        if(strlen($payload)>11000)self::ajaxError('guide_cost_request_too_large');
        $input=json_decode($payload,true);
        if(!is_array($input))self::ajaxError('guide_cost_payload_invalid');
        $result=self::save($input);
        if(is_wp_error($result))self::ajaxError($result->get_error_code());
        wp_send_json_success($result);
    }
    /** Guarded write used by AJAX only. No direct render/update in checkout. */
    public static function save(array $input): array|WP_Error {
        if(!self::permitted())return new WP_Error('guide_cost_company_pricing_permission_required');
        $scope=(string)($input['scope']??'');
        if($scope==='global')return self::saveGlobal($input);
        if($scope!=='term')return new WP_Error('guide_cost_scope_invalid');
        $id=filter_var($input['term_id']??null,FILTER_VALIDATE_INT);
        if(!$id||!current_user_can('edit_term',(int)$id))
            return new WP_Error('guide_cost_term_permission_denied');
        $term=self::term((int)$id);
        if(is_wp_error($term))return $term;
        $rev=filter_var($input['revision']??null,FILTER_VALIDATE_INT);
        if($rev===false||$rev===null||$rev<0)
            return new WP_Error('guide_cost_revision_invalid');
        $hash=(string)($input['raw_hash']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash))
            return new WP_Error('guide_cost_expected_source_hash_invalid');
        $current=self::raw((int)$id);
        if(!hash_equals(self::rawHash($current),$hash))
            return new WP_Error('guide_cost_source_fields_changed');
        $old=get_term_meta((int)$id,self::CERT,true);
        $oldRevision=is_array($old)?(int)($old['revision']??0):0;
        if($rev!==$oldRevision)return new WP_Error('guide_cost_approval_revision_conflict');
        $values=$input['fields']??null;
        if(!is_array($values))return new WP_Error('guide_cost_fields_missing');
        $clean=self::validate($values);
        if(is_wp_error($clean))return $clean;
        if($clean['mad4b_guide_cost_status']!=='approved')
            return new WP_Error('guide_cost_approval_status_required');
        if($clean['mad4b_guide_cost_mode']==='override') {
            $preview=trim($clean['mad4b_guide_cost_currency']);
            if($preview!=='EGP' && (!function_exists('pricing_public_fx_rate') ||
                !is_numeric(pricing_public_fx_rate($preview,'EGP')) ||
                (float)pricing_public_fx_rate($preview,'EGP')<=0))
                return new WP_Error('guide_cost_fx_not_ready_for_approval');
            if(class_exists('MAD4B_Pricing_Currency_Configuration_V1',false) &&
                !MAD4B_Pricing_Currency_Configuration_V1::authoring_allowed($preview))
                return new WP_Error('guide_cost_currency_not_allowed');
        }
        $lock='mad4b_guide_cost_term_lock_'.(int)$id;
        $existing=get_option($lock,null);
        if(is_array($existing)&&time()-(int)($existing['at']??time())>120)
            delete_option($lock);
        if(!add_option($lock,['at'=>time(),'actor'=>get_current_user_id()],'',false))
            return new WP_Error('guide_cost_term_currently_locked');
        try {
            $again=self::raw((int)$id);
            $old=get_term_meta((int)$id,self::CERT,true);
            if(self::rawHash($again)!==$hash||
                (is_array($old)?(int)($old['revision']??0):0)!==$rev)
                return new WP_Error('guide_cost_concurrent_update');
            foreach(self::FIELDS as $field) {
                $ok=update_term_meta((int)$id,$field,$clean[$field]);
                if(is_wp_error($ok))return $ok;
            }
            $newRaw=self::raw((int)$id);
            if($newRaw!==$clean)return new WP_Error('guide_cost_term_write_readback_failed');
            $receipt=[
                'schema'=>1,'term_id'=>(int)$id,'term_slug'=>$term->slug,
                'fields_hash'=>self::rawHash($newRaw),'revision'=>$rev+1,
                'reviewer'=>get_current_user_id(),'reviewed_at'=>gmdate('c'),
                'pricing_source'=>'jetengine_guide_language_term_meta',
                'approval_mode'=>$clean['mad4b_guide_cost_mode'],
                'approval_channel'=>'company_pricing_controls',
            ];
            $save=update_term_meta((int)$id,self::CERT,$receipt);
            if(is_wp_error($save))return $save;
            $audit=['contract'=>'mad4b.guide-term-cost-audit.v1',
                'old_revision'=>$rev,'revision'=>$rev+1,
                'old_hash'=>$hash,'new_hash'=>$receipt['fields_hash'],
                'actor'=>get_current_user_id(),'timestamp'=>$receipt['reviewed_at'],
                'mode'=>$receipt['approval_mode'],
                'currency'=>$newRaw['mad4b_guide_cost_currency'],
                'amount'=>$newRaw['mad4b_guide_base_cost'],
                'supplier'=>$newRaw['mad4b_guide_supplier']];
            add_term_meta((int)$id,self::AUDIT,$audit);
            $resolved=self::resolved((int)$id);
            if(is_wp_error($resolved))return $resolved;
            return ['term_id'=>$id,'revision'=>$rev+1,'rate'=>$resolved,
                'retail_rate_changed'=>false,'checkout_ready'=>false];
        } finally {delete_option($lock);}
    }
    public static function saveGlobal(array $input): array|WP_Error {
        $base=MAD4B_Guide_Daily_Base_Cost_V1::read();
        if(is_wp_error($base))return $base;
        $expected=(string)($input['expected_hash']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||
            !hash_equals($expected,hash('sha256',wp_json_encode($base))))
            return new WP_Error('guide_global_policy_changed');
        $amounts=$input['amounts']??null;
        if(!is_array($amounts) || array_diff(array_keys($amounts),['ar','en','other']) ||
           array_diff(['ar','en','other'],array_keys($amounts)))
            return new WP_Error('guide_global_amounts_invalid');
        $safe=[];
        foreach(['ar','en','other'] as $key){
            $v=$amounts[$key];
            if(!is_scalar($v)||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$v)||
                (int)$v>1000000)return new WP_Error('guide_global_amount_invalid');
            $safe[$key]=(int)$v;
        }
        $lock='mad4b_guide_cost_global_policy_lock_v1';
        $existing=get_option($lock,null);
        if(is_array($existing) && time()-(int)($existing['at']??time())>120)
            delete_option($lock);
        if(!add_option($lock,['at'=>time(),'actor'=>get_current_user_id()],'',false))
            return new WP_Error('guide_global_policy_currently_locked');
        try {
            $latest=MAD4B_Guide_Daily_Base_Cost_V1::read();
            if(is_wp_error($latest) ||
                !hash_equals($expected,hash('sha256',wp_json_encode($latest))))
                return new WP_Error('guide_global_policy_concurrent_update');
            $revised=$latest;$revised['amounts']=$safe;
            $ok=update_option(MAD4B_Guide_Daily_Base_Cost_V1::OPTION,$revised,false);
            if(!$ok && $revised!==$latest)
                return new WP_Error('guide_global_policy_save_failed');
            $read=MAD4B_Guide_Daily_Base_Cost_V1::read();
            if(is_wp_error($read)||$read!==$revised)
                return new WP_Error('guide_global_policy_readback_failed');
            $audit=(array)get_option(self::HISTORY,[]);
            $audit[]=['timestamp'=>gmdate('c'),'reviewer'=>get_current_user_id(),
                'old'=>$latest['amounts'],'new'=>$safe,'currency'=>'EGP',
                'cost_only'=>true,'retail_price_changed'=>false];
            update_option(self::HISTORY,array_slice($audit,-30),false);
            return ['policy'=>$revised,'policy_hash'=>hash('sha256',wp_json_encode($revised)),
                'retail_rate_changed'=>false,'checkout_ready'=>false];
        } finally {delete_option($lock);}
    }

    public static function asset(): void {
        if(!self::permitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-guide-taxonomy-pricing-v1.js';
        if(!is_readable($file))return;
        wp_enqueue_script('mad4b-guide-taxonomy-pricing-v1',
            content_url('mu-plugins/assets/mad4b-guide-taxonomy-pricing-v1.js'),[],
            (string)filemtime($file),true);
        wp_add_inline_script('mad4b-guide-taxonomy-pricing-v1',
            'window.MAD4BGuideTaxPricing='.wp_json_encode([
                'url'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,
                'nonce'=>wp_create_nonce(self::ACTION),
            ]).';','before');
    }
    public static function panel(): void {
        if(!self::permitted())return;
        if(!in_array(sanitize_key((string)($_GET['page']??'')),
            ['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))return;
        $base=MAD4B_Guide_Daily_Base_Cost_V1::read();
        if(is_wp_error($base))return;
        echo '<div id="mad4b-guide-taxonomy-cost-panel" class="postbox" style="margin:16px 20px;padding:14px;max-width:1300px">';
        echo '<h2>Guide Service · Supplier Cost per Language</h2>';
        echo '<p>Internal cost, NOT selling price. Global EGP fallback, native JetEngine Guide Language Term fields, and review receipts. Original supplier currencies are converted to EGP for Package Cost with verified FX; unavailable FX blocks the cost. No Tour Rate or checkout mutation.</p>';
        echo '<div id="mad4b-guide-cost-result" role="status" aria-live="polite"></div>';
        echo '<section><h3>Global Defaults · EGP / per guide full day</h3><form id="mad4b-guide-default-form">';
        echo '<input type="hidden" name="expected_hash" value="'.esc_attr(hash('sha256',wp_json_encode($base))).'">';
        foreach(['ar'=>'Arabic','en'=>'English','other'=>'Other certified languages'] as $key=>$label) {
            echo '<label style="display:inline-block;margin:4px 14px 10px 0">'.esc_html($label).' ';
            echo '<input name="'.esc_attr($key).'" type="number" min="1" max="1000000" step="1" style="width:110px" value="'.(int)$base['amounts'][$key].'"> EGP</label>';
        }
        echo '<button type="submit" class="button button-secondary">Update global fallback</button></form></section>';
        echo '<section><h3>Guide Languages · JetEngine Taxonomy Overrides</h3>';
        echo '<p><small>Inherit uses the global EGP fallback. Override uses one approved supplier currency. Each term can be edited in JetEngine; changes there need approval here before affecting cost.</small></p>';
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach(['Guide Language','Mode','Supplier Cost','Currency','Supplier','Valid From','Valid To','Evidence','Status','Action'] as $h)
            echo '<th>'.esc_html($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach(self::allLanguages() as $row) {
            $id=(int)$row['id'];$fields=$row['fields'];
            $rawMode=$fields['mad4b_guide_cost_mode']?:'inherit';
            $cost=$row['cost'];
            echo '<tr class="mad4b-guide-term-row" data-id="'.$id.'" data-revision="'.(int)$row['revision'].'" data-hash="'.esc_attr($row['raw_hash']).'">';
            echo '<td><strong>'.esc_html($row['name']).'</strong><small style="display:block">'.esc_html($row['locale']).'</small>';
            if($row['term_url'])echo '<a href="'.esc_url($row['term_url']).'">Edit taxonomy term</a>';
            echo '</td><td><select data-field="mad4b_guide_cost_mode">';
            foreach(['inherit'=>'Inherit','override'=>'Override'] as $k=>$v)
                echo '<option value="'.esc_attr($k).'" '.selected($rawMode,$k,false).'>'.esc_html($v).'</option>';
            echo '</select></td>';
            echo '<td><input data-field="mad4b_guide_base_cost" type="number" min="0.01" max="1000000" step="0.01" style="width:105px" value="'.esc_attr($fields['mad4b_guide_base_cost']).'"></td>';
            echo '<td><select data-field="mad4b_guide_cost_currency">';
            foreach(self::CURRENCIES as $code)
                echo '<option value="'.esc_attr($code).'" '.selected(($fields['mad4b_guide_cost_currency']?:'EGP'),$code,false).'>'.esc_html($code).'</option>';
            echo '</select></td>';
            echo '<td><input data-field="mad4b_guide_supplier" type="text" style="width:90px" value="'.esc_attr($fields['mad4b_guide_supplier']?:'self').'"></td>';
            foreach(['mad4b_guide_cost_valid_from','mad4b_guide_cost_valid_to'] as $f)
                echo '<td><input data-field="'.esc_attr($f).'" type="date" value="'.esc_attr($fields[$f]).'"></td>';
            echo '<td><textarea data-field="mad4b_guide_cost_note" rows="2" maxlength="500" style="min-width:200px">'.esc_textarea($fields['mad4b_guide_cost_note']).'</textarea></td>';
            echo '<td><strong>'.esc_html($row['error']?:($cost['policy_source']??'unknown')).'</strong><small style="display:block">'.($cost?esc_html(number_format((float)$cost['unit_cost'],2).' EGP / day'):'Cost blocked').'</small></td>';
            echo '<td><button type="button" class="button button-small mad4b-guide-approve-term">Approve cost</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
        echo '<p><small>JetEngine Term Meta remains the only detailed rate source. Approval receipt records its fingerprint and reviewer; direct field edits invalidate approval. The financial engine reads original currency and converts to EGP, with no duplicated currency price tables.</small></p>';
        echo '</div>';
    }
}
add_action('wp_ajax_'.MAD4B_Guide_Taxonomy_Pricing_V1::ACTION,
    [MAD4B_Guide_Taxonomy_Pricing_V1::class,'saveAjax']);
add_action('admin_enqueue_scripts',[MAD4B_Guide_Taxonomy_Pricing_V1::class,'asset'],43);
add_action('admin_footer',[MAD4B_Guide_Taxonomy_Pricing_V1::class,'panel'],30);
