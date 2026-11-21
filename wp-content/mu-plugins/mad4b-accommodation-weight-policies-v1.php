<?php
/**
 * Plugin Name: MAD4B Accommodation Weight Policies — Native Property Rate Shadow
 * Description: Governed, source-aware accommodation occupancy and room-grade weight preview, inherited from Global, Property Type, Destination, Property and Unit; never alters supplier/native Property Rates or checkout.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Accommodation_Weight_Policies_V1',false)):
final class MAD4B_Accommodation_Weight_Policies_V1 {
    public const ACTION='mad4b_accommodation_weight_policy_v1';
    public const META='_mad4b_accommodation_weight_policy_v1';
    public const GLOBAL='mad4b_accommodation_weight_policy_global_v1';
    public const CONTRACT='mad4b.accommodation-weight-policy.v1';
    private const SCOPES=['global','property_type','destination','property','unit'];
    private const FIELDS=['single_pct','double_pct','triple_pct','standard_pct','deluxe_pct','suite_pct'];
    private const OCC=['single','double','triple'];
    private const GRADE=['standard','deluxe','suite'];
    public static function can():bool {
        return current_user_can('commerce_manage_pricing') &&
          (current_user_can('manage_options') ||
           (class_exists('MAD4B_Stay_Pricing_Controls_Panel_V1',false)
            && MAD4B_Stay_Pricing_Controls_Panel_V1::permitted()));
    }
    private static function integer($raw):int {
        if(!is_scalar($raw)||is_bool($raw))return 0;
        $v=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $v===false?0:(int)$v;
    }
    public static function validate($raw) {
        if(!is_array($raw)||array_diff(array_keys($raw),['schema','reference','weights','mode']) ||
           ($raw['schema']??null)!==1 ||
           !in_array((string)($raw['mode']??''),['shadow_only'],true))
            return new WP_Error('accommodation_weight_schema_invalid');
        $reference=(string)($raw['reference']??'inherit');
        if(!in_array($reference,['inherit','single','double','triple'],true))
            return new WP_Error('accommodation_weight_reference_invalid');
        $weights=$raw['weights']??null;
        if(!is_array($weights)||array_diff(array_keys($weights),self::FIELDS))
            return new WP_Error('accommodation_weight_fields_invalid');
        $out=[];
        foreach(self::FIELDS as $field){
            $value=$weights[$field]??null;
            if($value===''||$value===null||$value==='inherit'){
                $out[$field]=null;continue;
            }
            if(!is_scalar($value)||is_bool($value) ||
               !preg_match('/^-?(?:[0-9]{1,3})(?:\.[0-9]{1,2})?$/D',(string)$value) ||
               !is_numeric($value)||!is_finite((float)$value) ||
               (float)$value< -80||(float)$value>300)
                return new WP_Error('accommodation_weight_invalid_'.$field);
            $out[$field]=number_format((float)$value,2,'.','');
        }
        // Source-specific reference may be inherited, but an explicit
        // reference must never carry its own nonzero weight.
        if($reference!=='inherit' && $out[$reference.'_pct']!==null &&
           (float)$out[$reference.'_pct']!==0.)
            return new WP_Error('accommodation_weight_reference_must_be_zero');
        return ['schema'=>1,'mode'=>'shadow_only','reference'=>$reference,'weights'=>$out];
    }
    private static function taxonomy(string $scope):?string {
        return match($scope){'property_type'=>'property-types','destination'=>'destinations',default=>null};
    }
    public static function authorize(string $scope,int $id,bool $write=false) {
        if(!self::can())return new WP_Error('accommodation_weight_forbidden');
        if(!in_array($scope,self::SCOPES,true))return new WP_Error('accommodation_weight_scope_invalid');
        if($scope==='global')
            return !$id && current_user_can('manage_options') ? true : new WP_Error('accommodation_weight_global_access');
        if($id<1)return new WP_Error('accommodation_weight_target_missing');
        if($taxonomy=self::taxonomy($scope)){
            $term=get_term($id,$taxonomy);
            if(!($term instanceof WP_Term)||is_wp_error($term))return new WP_Error('accommodation_weight_term_invalid');
            $tax=get_taxonomy($taxonomy);
            $cap=$tax && isset($tax->cap->manage_terms)?$tax->cap->manage_terms:'manage_categories';
            if($write&&!current_user_can($cap))return new WP_Error('accommodation_weight_term_write_denied');
            return true;
        }
        $type=$scope==='property'?'properties':'units';
        if(get_post_type($id)!==$type || !in_array(get_post_status($id),['publish','draft','pending'],true))
            return new WP_Error('accommodation_weight_post_invalid');
        if(!current_user_can('edit_post',$id))return new WP_Error('accommodation_weight_post_forbidden');
        return true;
    }
    private static function storage(string $scope,int $id):array {
        global $wpdb;
        if($scope==='global'){
            $key=self::GLOBAL;
            $row=$wpdb->get_row($wpdb->prepare(
              "SELECT option_id AS rowid,option_value AS raw FROM {$wpdb->options} WHERE option_name=%s",$key),ARRAY_A);
            return ['kind'=>'option','table'=>$wpdb->options,'row'=>$row?:null,'key'=>$key,
                'id_field'=>'option_id','value_field'=>'option_value','meta_type'=>''];
        }
        $type=self::taxonomy($scope)?'term':'post';
        $table=$type==='term'?$wpdb->termmeta:$wpdb->postmeta;
        $parent=$type==='term'?'term_id':'post_id';
        $rows=$wpdb->get_results($wpdb->prepare(
          "SELECT meta_id AS rowid,meta_value AS raw FROM {$table} WHERE {$parent}=%d AND meta_key=%s LIMIT 2",
          $id,self::META),ARRAY_A);
        return ['kind'=>'meta','table'=>$table,'row'=>count($rows)===1?$rows[0]:null,
            'duplicates'=>count($rows)>1,'key'=>self::META,
            'id_field'=>'meta_id','value_field'=>'meta_value',
            'meta_type'=>$type];
    }
    public static function read(string $scope,int $id=0) {
        $auth=self::authorize($scope,$id,false);
        if(is_wp_error($auth))return $auth;
        $storage=self::storage($scope,$id);
        if(!empty($storage['duplicates']))return new WP_Error('accommodation_weight_duplicate_scope_meta');
        $raw=$storage['row']['raw']??null;
        $revision=hash('sha256',$raw===null?'SCOPE_ABSENT_V1':$raw);
        if($raw===null)return [
          'configured'=>false,'revision'=>$revision,'policy'=>[
            'schema'=>1,'mode'=>'shadow_only','reference'=>'inherit',
            'weights'=>array_fill_keys(self::FIELDS,null)]
        ];
        $valid=self::validate(maybe_unserialize($raw));
        if(is_wp_error($valid))return $valid;
        return ['configured'=>true,'revision'=>$revision,'policy'=>$valid];
    }
    private static function rawPolicy(string $scope,int $id) {
        $storage=self::storage($scope,$id);
        if(!empty($storage['duplicates']))return new WP_Error('accommodation_weight_duplicate_scope_meta');
        if(!$storage['row'])return null;
        return self::validate(maybe_unserialize($storage['row']['raw']));
    }
    public static function save(string $scope,int $id,$input,string $expected) {
        $auth=self::authorize($scope,$id,true);
        if(is_wp_error($auth))return $auth;
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected))return new WP_Error('accommodation_weight_revision_missing');
        $valid=self::validate($input);
        if(is_wp_error($valid))return $valid;
        global $wpdb;
        $storage=self::storage($scope,$id);
        if(!empty($storage['duplicates']))return new WP_Error('accommodation_weight_duplicate_scope_meta');
        $raw=$storage['row']['raw']??null;
        $revision=hash('sha256',$raw===null?'SCOPE_ABSENT_V1':$raw);
        if(!hash_equals($revision,$expected))
            return new WP_Error('accommodation_weight_stale_editor_revision');
        $new=maybe_serialize($valid);
        if($raw===$new)
            return ['write_performed'=>false,'unchanged'=>true,'scope'=>$scope,'target_id'=>$id,
                'configured'=>true,'revision'=>$revision,'policy'=>$valid];
        if($raw!==null) {
            $affected=$wpdb->query($wpdb->prepare(
                "UPDATE {$storage['table']} SET {$storage['value_field']}=%s WHERE {$storage['id_field']}=%d AND {$storage['value_field']}=%s",
                $new,(int)$storage['row']['rowid'],$raw));
            if($affected!==1)return new WP_Error('accommodation_weight_concurrent_write_rejected');
        }elseif($scope==='global'){
            if(!add_option(self::GLOBAL,$valid,'',false))
                return new WP_Error('accommodation_weight_option_create_conflict');
        }else{
            if(!add_metadata($storage['meta_type'],$id,self::META,$valid,true))
                return new WP_Error('accommodation_weight_meta_create_conflict');
        }
        if($scope==='global'){
            wp_cache_delete(self::GLOBAL,'options');
            wp_cache_delete('notoptions','options');
            wp_cache_delete('alloptions','options');
        }else{
            wp_cache_delete($id,$storage['meta_type'].'_meta');
        }
        $read=self::read($scope,$id);
        if(is_wp_error($read)||
           $read['policy']!==$valid)
            return new WP_Error('accommodation_weight_readback_failed');
        return ['write_performed'=>true,'scope'=>$scope,'target_id'=>$id,
            'revision'=>$read['revision'],'configured'=>true,'policy'=>$valid,
            'affected_price_records'=>0,'checkout_modified'=>false,'published_rates_modified'=>false];
    }
    private static function exactUnit(int $property,int $unit):bool {
        if($unit<1)return true;
        if(get_post_type($unit)!=='units'||get_post_type($property)!=='properties')return false;
        if(!function_exists('jet_engine'))return false;
        $relation=jet_engine()->relations->get_active_relations()[223]??null;
        if(!$relation)return false;
        $children=$relation->get_children($property);
        foreach((array)$children as $child)
            if((int)(is_array($child)?($child['child_object_id']??0):(is_object($child)?($child->child_object_id??$child->ID??0):$child))===$unit)return true;
        return false;
    }
    private static function taxonomyRow(int $property,string $taxonomy,string $scope) {
        $terms=wp_get_object_terms($property,$taxonomy,['fields'=>'ids']);
        if(is_wp_error($terms))return $terms;
        $rows=[];
        foreach((array)$terms as $termId){
            $termId=(int)$termId;
            if($termId<1)continue;
            $r=self::rawPolicy($scope,$termId);
            if(is_wp_error($r))return $r;
            if(is_array($r))$rows[]=['scope'=>$scope,'id'=>$termId,'policy'=>$r];
        }
        if(count($rows)>1)return new WP_Error('accommodation_weight_ambiguous_'.$scope);
        return $rows[0]??null;
    }
    public static function resolve(int $property,int $unit=0) {
        if(!self::can())return new WP_Error('accommodation_weight_forbidden');
        if(get_post_type($property)!=='properties' ||
           ($unit>0&&!self::exactUnit($property,$unit)))
            return new WP_Error('accommodation_weight_property_unit_relation_invalid');
        $base=['reference'=>'double','weights'=>array_fill_keys(self::FIELDS,'0.00')];
        $trace=[];$entries=[];
        $global=self::rawPolicy('global',0);
        if(is_wp_error($global))return $global;
        if(is_array($global))$entries[]=['scope'=>'global','id'=>0,'policy'=>$global];
        foreach([['property-types','property_type'],['destinations','destination']] as [$taxonomy,$scope]){
            $row=self::taxonomyRow($property,$taxonomy,$scope);
            if(is_wp_error($row))return $row;
            if($row!==null)$entries[]=$row;
        }
        $p=self::rawPolicy('property',$property);
        if(is_wp_error($p))return $p;
        if(is_array($p))$entries[]=['scope'=>'property','id'=>$property,'policy'=>$p];
        if($unit>0){
            $u=self::rawPolicy('unit',$unit);
            if(is_wp_error($u))return $u;
            if(is_array($u))$entries[]=['scope'=>'unit','id'=>$unit,'policy'=>$u];
        }
        foreach($entries as $entry){
            $policy=$entry['policy'];$location=$entry['scope'].':'.$entry['id'];
            if($policy['reference']!=='inherit'){
                $base['reference']=$policy['reference'];
                $trace['reference']=$location;
            }
            foreach(self::FIELDS as $field){
                if($policy['weights'][$field]===null)continue;
                $base['weights'][$field]=$policy['weights'][$field];
                $trace[$field]=$location;
            }
        }
        $ref=$base['reference'];
        if((float)$base['weights'][$ref.'_pct']!==0.0)
            return new WP_Error('accommodation_weight_effective_reference_not_neutral');
        return ['status'=>$entries?'configured_preview_only':'unconfigured_neutral_preview',
            'contract'=>self::CONTRACT,'reference'=>$ref,
            'weights'=>$base['weights'],'sources'=>$trace,
            'applied_scopes'=>array_map(static fn($x)=>$x['scope'].':'.$x['id'],$entries),
            'commercial_authority'=>false];
    }
    public static function preview(array $data) {
        if(!self::can())return new WP_Error('accommodation_weight_forbidden');
        $property=self::integer($data['property_id']??0);
        $unit=self::integer($data['unit_id']??0);
        if(!$property)return new WP_Error('accommodation_weight_property_required');
        $auth=self::authorize('property',$property);
        if(is_wp_error($auth))return $auth;
        if($unit){
            $auth=self::authorize('unit',$unit);
            if(is_wp_error($auth))return $auth;
        }
        $effective=self::resolve($property,$unit);
        if(is_wp_error($effective))return $effective;
        $occupancy=sanitize_key((string)($data['occupancy']??''));
        $grade=sanitize_key((string)($data['room_grade']??''));
        if(!in_array($occupancy,self::OCC,true)||!in_array($grade,self::GRADE,true))
            return new WP_Error('accommodation_weight_preview_dimensions_invalid');
        // A supplier category is not interchangeable with a hotel grade.
        // Use the original Unit source receipt or native classification;
        // never infer a "Standard" room merely from a rate-sheet group.
        $gradeEvidence=['status'=>'no_unit_selected','source_category'=>null,
            'recognized_grade'=>null,'verified'=>false];
        $unitCapacity=null;
        if($unit>0){
            $capacityRaw=get_post_meta($unit,'unit_max_occupancy',true);
            if(is_numeric($capacityRaw)&&(int)$capacityRaw>0)
                $unitCapacity=(int)$capacityRaw;
            $headcount=match($occupancy){'single'=>1,'double'=>2,'triple'=>3};
            if($unitCapacity!==null && $headcount>$unitCapacity)
                return new WP_Error('accommodation_weight_occupancy_exceeds_native_unit_capacity');
            $receipt=get_post_meta($unit,'_mad4b_room_source_receipt_v1',true);
            $sourceCategory=is_array($receipt)&&
                (int)($receipt['property_id']??0)===$property
                    ?trim((string)($receipt['room_category']??'')):'';
            $origin=$sourceCategory!==''?'original_source_receipt':'native_unit_fields';
            if($sourceCategory===''){
                $nativeRoom=get_post_meta($unit,'room_type',true);
                if(is_string($nativeRoom)&&$nativeRoom!=='')
                    $sourceCategory=$nativeRoom;
                else{
                    $terms=wp_get_object_terms($unit,'unit-type',['fields'=>'names']);
                    if(!is_wp_error($terms)&&count($terms)===1)
                        $sourceCategory=(string)$terms[0];
                }
            }
            $found=[];
            foreach(self::GRADE as $item)
                if($sourceCategory!=='' &&
                   preg_match('/\\b'.preg_quote($item,'/').'\\b/iu',$sourceCategory))
                    $found[]=$item;
            $gradeEvidence=['status'=>count($found)===1?'classified_from_'.$origin:
                (count($found)>1?'ambiguous_source_room_class':'room_class_unclassified'),
                'source_category'=>$sourceCategory?:null,
                'recognized_grade'=>count($found)===1?$found[0]:null,
                'verified'=>count($found)===1];
            if(count($found)===1 && $found[0]!==$grade)
                return new WP_Error('accommodation_weight_unit_room_grade_mismatch');
        }
        $basis=sanitize_key((string)($data['price_unit']??''));
        if(!in_array($basis,['per_person','per_person_night','per_room_night','per_cabin_night','per_stay'],true))
            return new WP_Error('accommodation_weight_preview_basis_invalid');
        $currency=strtoupper((string)($data['currency']??''));
        if(!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('accommodation_weight_currency_invalid');
        $raw=$data['base_amount']??null;
        if(!is_scalar($raw)||is_bool($raw)||
            !preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,4})?$/D',(string)$raw)||
            (float)$raw<=0)return new WP_Error('accommodation_weight_preview_reference_amount_invalid');
        $amount=(float)$raw;
        $occupancyPercent=(float)$effective['weights'][$occupancy.'_pct'];
        $roomPercent=(float)$effective['weights'][$grade.'_pct'];
        // Unknown or composite source room types must not receive a
        // positive/negative grade adjustment from a manually chosen label.
        if($unit>0 && !$gradeEvidence['verified'] && $roomPercent!==0.0)
            return new WP_Error('accommodation_weight_room_class_unverified_for_nonzero_adjustment');
        $occFactor=1+$occupancyPercent/100;
        $roomFactor=1+$roomPercent/100;
        $origin=sanitize_key((string)($data['origin']??'manual_preview'));
        if(!in_array($origin,['manual_preview','sheet_unapproved','explicit_rate'],true))
            return new WP_Error('accommodation_weight_origin_invalid');
        // A recorded explicit Property Rate is authoritative for its own
        // dimensions. Never compound generated weight percentages on top.
        $explicit=$origin==='explicit_rate';
        if($explicit)return new WP_Error('accommodation_weight_explicit_rate_must_be_resolved_by_native_rate');
        $computed=round($amount*$occFactor*$roomFactor,4);
        // Board selection is delegated to the original native meal policy,
        // never converted to a percentage or silently charged a second time.
        $mealContext=['status'=>'meal_policy_unavailable',
            'base_board'=>null,'source'=>null,'contract_confirmed'=>false];
        if(class_exists('MAD4B_Hotel_Meal_Base_Policy_V1',false)){
            $meal=MAD4B_Hotel_Meal_Base_Policy_V1::resolve($property);
            if(is_wp_error($meal)){
                $mealContext['status']=$meal->get_error_code();
            }else{
                $mealContext=[
                    'status'=>'recommended_board_requires_rate_confirmation',
                    'base_board'=>(string)$meal['base_board'],
                    'source'=>(string)$meal['source'],
                    'source_ref'=>$meal['source_ref']??null,
                    'contract_confirmed'=>false,
                    'optional_extras_included_in_estimate'=>false
                ];
            }
        }
        return ['contract'=>self::CONTRACT,'status'=>'estimate_only_not_supplier_cost',
            'origin'=>$origin,'property_id'=>$property,'unit_id'=>$unit?:null,
            'reference_amount'=>number_format($amount,4,'.',''),
            'reference_basis'=>$basis,'currency'=>$currency,
            'occupancy'=>$occupancy,'room_grade'=>$grade,
            'unit_capacity'=>$unitCapacity,
            'room_grade_evidence'=>$gradeEvidence,
            'occupancy_weight_percent'=>$occupancyPercent,
            'room_weight_percent'=>$roomPercent,
            'occupancy_factor'=>number_format($occFactor,4,'.',''),
            'room_factor'=>number_format($roomFactor,4,'.',''),
            'estimated_unit_amount'=>number_format($computed,4,'.',''),
            'effective_policy'=>$effective,
            'priced_season'=>null,'date_window_verified'=>false,
            'meal_base_policy'=>$mealContext,
            'supplier_contract_approved'=>false,'availability_verified'=>false,
            'base_board_included_verified'=>false,
            'commercial_ready'=>false,'rate_written'=>false,'checkout_ready'=>false,
            'warnings'=>[
                'Reference inputs and policy weights are a shadow scenario, not a supplier receipt.',
                'Single/Double/Triple refer to occupancy; room grade must be verified from an original Unit.',
                'Per-person and per-room units are never implicitly interchanged.',
                'Optional board extras and compulsory gala fees require separate dated source evidence.']];
    }
    public static function search(string $scope,string $query):array {
        if(!self::can())return [];
        $query=trim(wp_strip_all_tags($query));
        if(mb_strlen($query)<2)return [];
        if(($taxonomy=self::taxonomy($scope))){
            $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,
                'search'=>$query,'number'=>25]);
            $out=[];
            if(!is_wp_error($terms))foreach($terms as $term)
                if($term instanceof WP_Term)$out[]=['id'=>(int)$term->term_id,'title'=>$term->name];
            return $out;
        }
        $type=match($scope){'property'=>'properties','unit'=>'units',default=>''};
        if(!$type)return [];
        $args=['post_type'=>$type,'post_status'=>['publish','draft'],'posts_per_page'=>25,
            's'=>$query,'fields'=>'ids','suppress_filters'=>true,'no_found_rows'=>true];
        if(ctype_digit($query))$args=['post_type'=>$type,'post_status'=>['publish','draft'],
            'post__in'=>[(int)$query],'posts_per_page'=>1,'fields'=>'ids',
            'suppress_filters'=>true,'no_found_rows'=>true];
        $result=get_posts($args);$out=[];
        foreach($result as $id)if(current_user_can('edit_post',(int)$id))
            $out[]=['id'=>(int)$id,'title'=>get_the_title($id)];
        return $out;
    }
    public static function ajax():void {
        if(!self::can())wp_send_json_error(['code'=>'accommodation_weight_forbidden'],403);
        if(!check_ajax_referer(self::ACTION,'nonce',false))
            wp_send_json_error(['code'=>'accommodation_weight_nonce_invalid'],403);
        $mode=sanitize_key((string)($_POST['mode']??''));
        $scope=sanitize_key((string)($_POST['scope']??'global'));
        $id=self::integer($_POST['target_id']??0);
        if($mode==='search'){
            $term=sanitize_text_field(wp_unslash((string)($_POST['search']??'')));
            if(strlen($term)>95)wp_send_json_error(['code'=>'accommodation_weight_search_length'],422);
            wp_send_json_success(['items'=>self::search($scope,$term),'read_only'=>true]);
        }
        if($mode==='preview'){
            $fields=['property_id','unit_id','occupancy','room_grade',
                'price_unit','currency','base_amount','origin'];
            $data=[];
            foreach($fields as $field)if(isset($_POST[$field])&&is_scalar($_POST[$field]))
                $data[$field]=sanitize_text_field(wp_unslash((string)$_POST[$field]));
            $out=self::preview($data);
        }elseif($mode==='load')$out=self::read($scope,$id);
        elseif($mode==='save'){
            if(!isset($_POST['policy'])||!is_string($_POST['policy'])||
               strlen($_POST['policy'])>3500)
                wp_send_json_error(['code'=>'accommodation_weight_invalid_payload'],422);
            $raw=json_decode(wp_unslash($_POST['policy']),true);
            $expected=sanitize_text_field((string)($_POST['revision']??''));
            $out=self::save($scope,$id,$raw,$expected);
        }else $out=new WP_Error('accommodation_weight_action_invalid');
        if(is_wp_error($out))wp_send_json_error(['code'=>$out->get_error_code()],422);
        wp_send_json_success($out);
    }
    public static function assets():void {
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true) ||
           !self::can())return;
        $path=WPMU_PLUGIN_DIR.'/assets/mad4b-accommodation-weights-v1.js';
        $style=WPMU_PLUGIN_DIR.'/assets/mad4b-accommodation-weights-v1.css';
        if(!is_file($path)||!is_file($style))return;
        wp_enqueue_style('mad4b-accommodation-weight-policy',content_url('mu-plugins/assets/'.basename($style)),
            [],(string)filemtime($style));
        wp_enqueue_script('mad4b-accommodation-weight-policy',content_url('mu-plugins/assets/'.basename($path)),
            ['mad4b-stay-pricing-inline'],(string)filemtime($path),true);
        wp_add_inline_script('mad4b-accommodation-weight-policy',
            'window.MAD4BAccommodationWeights='.wp_json_encode([
              'endpoint'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,
              'nonce'=>wp_create_nonce(self::ACTION),'contract'=>self::CONTRACT,
              'scopes'=>[
                ['id'=>'global','label'=>'Global'],
                ['id'=>'property_type','label'=>'Property Type taxonomy'],
                ['id'=>'destination','label'=>'Destination taxonomy'],
                ['id'=>'property','label'=>'Original Property'],
                ['id'=>'unit','label'=>'Original Unit']]],
               JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    }
}
add_action('wp_ajax_'.MAD4B_Accommodation_Weight_Policies_V1::ACTION,
    [MAD4B_Accommodation_Weight_Policies_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Accommodation_Weight_Policies_V1::class,'assets'],92);
endif;
