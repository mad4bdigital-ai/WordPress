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
        if($scope==='global'){
            if($id!==0)return new WP_Error('accommodation_weight_global_target_invalid');
            return (!$write||current_user_can('manage_options'))
                ? true : new WP_Error('accommodation_weight_global_write_denied');
        }
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
        $canWrite=self::authorize($scope,$id,true)===true;
        if($raw===null)return [
          'configured'=>false,'revision'=>$revision,'can_write'=>$canWrite,
          'has_override'=>false,'policy'=>[
            'schema'=>1,'mode'=>'shadow_only','reference'=>'inherit',
            'weights'=>array_fill_keys(self::FIELDS,null)]
        ];
        $valid=self::validate(maybe_unserialize($raw));
        if(is_wp_error($valid))return $valid;
        return ['configured'=>true,'revision'=>$revision,
            'can_write'=>$canWrite,'has_override'=>self::hasOverride($valid),
            'policy'=>$valid];
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

    /** An all-Inherit record is present for audit but does not override an effective rule. */
    private static function hasOverride(array $policy):bool {
        if(($policy['reference']??'inherit')!=='inherit')return true;
        foreach(self::FIELDS as $key)
            if(($policy['weights'][$key]??null)!==null)return true;
        return false;
    }
    private static function policyDetails(array $policy):array {
        $fields=[];
        if(($policy['reference']??'inherit')!=='inherit')
            $fields[]=['field'=>'reference','value'=>$policy['reference']];
        foreach(self::FIELDS as $field)
            if(array_key_exists($field,$policy['weights']??[]) &&
               $policy['weights'][$field]!==null)
                $fields[]=['field'=>$field,'value'=>$policy['weights'][$field]];
        $nonzero=0;
        foreach(self::FIELDS as $field)
            if(isset($policy['weights'][$field])&&
               (float)$policy['weights'][$field]!==0.)$nonzero++;
        return ['fields'=>$fields,'field_count'=>count($fields),
            'nonzero_adjustments'=>$nonzero,
            'status'=>!$fields?'stored_inherit_only':
                ($nonzero?'configured_adjustments':'configured_neutral')];
    }
    private static function originalUnitParent(int $unit):?int {
        if($unit<1||get_post_type($unit)!=='units'||!function_exists('jet_engine'))
            return null;
        $relation=jet_engine()->relations->get_active_relations()[223]??null;
        if(!$relation)return null;
        $parents=$relation->get_parents($unit);
        $candidates=[];
        foreach((array)$parents as $entry){
            $id=(int)(is_array($entry)?($entry['parent_object_id']??0):
                (is_object($entry)?($entry->parent_object_id??0):0));
            if($id>0&&get_post_type($id)==='properties')$candidates[$id]=true;
        }
        return count($candidates)===1?(int)array_key_first($candidates):null;
    }
    private static function layer(string $scope,int $id,string $label) {
        $source=self::read($scope,$id);
        if(is_wp_error($source))return $source;
        $details=self::policyDetails($source['policy']);
        return ['scope'=>$scope,'target_id'=>$id,'label'=>$label,
            'related_property_id'=>$scope==='unit'?self::originalUnitParent($id):null,
            'configured'=>$source['configured'],'has_override'=>$source['has_override'],
            'can_write'=>$source['can_write'],
            'status'=>$source['configured']?$details['status']:'not_configured',
            'fields'=>$details['fields']];
    }
    /** Read-only effective matrix and original configured sources, without a price input. */
    public static function effective(array $input) {
        if(!self::can())return new WP_Error('accommodation_weight_forbidden');
        $property=self::integer($input['property_id']??0);
        $unit=self::integer($input['unit_id']??0);
        if(!$property)return new WP_Error('accommodation_weight_property_required');
        $permission=self::authorize('property',$property);
        if(is_wp_error($permission))return $permission;
        if($unit){
            $permission=self::authorize('unit',$unit);
            if(is_wp_error($permission))return $permission;
        }
        $resolved=self::resolve($property,$unit);
        if(is_wp_error($resolved))return $resolved;
        $layers=[];
        $global=self::layer('global',0,'Global base');
        if(is_wp_error($global))return $global;
        $layers[]=$global;
        foreach([['property-types','property_type','Property type'],
                  ['destinations','destination','Destination']] as [$taxonomy,$scope,$caption]){
            $terms=wp_get_object_terms($property,$taxonomy,['fields'=>'ids']);
            if(is_wp_error($terms))return $terms;
            foreach($terms as $termId){
                $term=get_term((int)$termId,$taxonomy);
                if(!($term instanceof WP_Term))continue;
                $layer=self::layer($scope,(int)$termId,$caption.' · '.$term->name);
                if(is_wp_error($layer))return $layer;
                $layers[]=$layer;
            }
        }
        $layer=self::layer('property',$property,'Property · '.get_the_title($property));
        if(is_wp_error($layer))return $layer;
        $layers[]=$layer;
        if($unit){
            $layer=self::layer('unit',$unit,'Unit · '.get_the_title($unit));
            if(is_wp_error($layer))return $layer;
            $layers[]=$layer;
        }
        $fieldRows=[];
        foreach(self::FIELDS as $field)
            $fieldRows[]=['field'=>$field,'percent'=>$resolved['weights'][$field],
                'source'=>$resolved['sources'][$field]??'neutral_default'];
        return ['contract'=>self::CONTRACT,'status'=>'policy_readback_only',
            'property_id'=>$property,'property_name'=>get_the_title($property),
            'unit_id'=>$unit?:null,'unit_name'=>$unit?get_the_title($unit):null,
            'reference'=>$resolved['reference'],
            'reference_source'=>$resolved['sources']['reference']??'neutral_default',
            'fields'=>$fieldRows,'layers'=>$layers,
            'configured_layer_count'=>count(array_filter($layers,
                static fn($x)=>$x['has_override'])),
            'neutral_fallback_used'=>count($resolved['sources'])<7,
            'commercial_authority'=>false,'price_written'=>false,
            'note'=>'Policy effective weights are diagnostic; only source-approved native Property Rates can authorize prices.'];
    }
    /** Compact scoped registry. Does not invent a second rate/policy store. */
    public static function inventory() {
        if(!self::can())return new WP_Error('accommodation_weight_forbidden');
        global $wpdb;
        $rows=[];$seen=[];
        $push=static function(string $scope,int $id,string $label)use(&$rows,&$seen) {
            $key=$scope.':'.$id;
            if(isset($seen[$key]))return;
            $seen[$key]=true;
            $entry=self::layer($scope,$id,$label);
            if(is_wp_error($entry))return;
            if($entry['configured'])$rows[]=$entry;
        };
        $push('global',0,'Global base');
        $terms=$wpdb->get_results($wpdb->prepare(
            "SELECT m.term_id,t.name,tt.taxonomy FROM {$wpdb->termmeta} m
             INNER JOIN {$wpdb->terms} t ON t.term_id=m.term_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=m.term_id
             WHERE m.meta_key=%s AND tt.taxonomy IN ('property-types','destinations')
             ORDER BY m.meta_id DESC LIMIT 201",self::META),ARRAY_A);
        foreach($terms as $row){
            $scope=$row['taxonomy']==='property-types'?'property_type':'destination';
            $push($scope,(int)$row['term_id'],
                  ($scope==='property_type'?'Property type':'Destination').' · '.$row['name']);
        }
        $posts=$wpdb->get_results($wpdb->prepare(
            "SELECT m.post_id,p.post_type,p.post_title FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
             WHERE m.meta_key=%s AND p.post_type IN ('properties','units')
             AND p.post_status IN ('publish','draft','pending')
             ORDER BY m.meta_id DESC LIMIT 201",self::META),ARRAY_A);
        foreach($posts as $row){
            $id=(int)$row['post_id'];
            if(!current_user_can('edit_post',$id))continue;
            $scope=$row['post_type']==='units'?'unit':'property';
            $push($scope,$id,($scope==='unit'?'Unit':'Property').' · '.$row['post_title']);
        }
        $count=['configured_adjustments'=>0,'configured_neutral'=>0,'stored_inherit_only'=>0];
        foreach($rows as $entry)
            if(isset($count[$entry['status']]))$count[$entry['status']]++;
        // Report incomplete results explicitly; never silently claim global coverage.
        return ['contract'=>self::CONTRACT,'status'=>'configured_policy_inventory_readonly',
            'items'=>array_slice($rows,0,200),'visible_count'=>min(200,count($rows)),
            'counts'=>$count,'truncated'=>count($terms)>=201||count($posts)>=201||count($rows)>200,
            'commercial_authority'=>false];
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
            $out[]=['id'=>(int)$id,'title'=>get_the_title($id),
                'related_property_id'=>$scope==='unit'?self::originalUnitParent((int)$id):null];
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
        if($mode==='effective'){
            $data=[
                'property_id'=>sanitize_text_field(wp_unslash((string)($_POST['property_id']??''))),
                'unit_id'=>sanitize_text_field(wp_unslash((string)($_POST['unit_id']??'')))];
            $out=self::effective($data);
        }elseif($mode==='inventory'){
            $out=self::inventory();
        }elseif(str_starts_with($mode,'margin_')){
            $out=MAD4B_Accommodation_Stay_Margin_V1::dispatch($mode);
        }elseif($mode==='rate_source'){
            $fields=['property_id','unit_id','from','to','currency','price_basis'];
            $data=[];
            foreach($fields as $field)if(isset($_POST[$field])&&is_scalar($_POST[$field]))
                $data[$field]=sanitize_text_field(wp_unslash((string)$_POST[$field]));
            $out=MAD4B_Accommodation_Rate_Source_Preview_V1::inspect($data);
        }elseif($mode==='preview'){
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
                ['id'=>'unit','label'=>'Original Unit'],
                ['id'=>'rate','label'=>'Original Property Rate (margin only)']]],
               JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
        // Financial-length editor composes with the existing weights panel:
        // it uses the original Property/Unit and Nights selection, not a new catalog.
        $marginJS=WPMU_PLUGIN_DIR.'/assets/mad4b-accommodation-stay-margin-v1.js';
        $marginCSS=WPMU_PLUGIN_DIR.'/assets/mad4b-accommodation-stay-margin-v1.css';
        if(is_readable($marginJS)&&is_readable($marginCSS)){
            wp_enqueue_style('mad4b-accommodation-stay-margin',
                content_url('mu-plugins/assets/'.basename($marginCSS)),
                ['mad4b-accommodation-weight-policy'],(string)filemtime($marginCSS));
            wp_enqueue_script('mad4b-accommodation-stay-margin',
                content_url('mu-plugins/assets/'.basename($marginJS)),
                ['mad4b-accommodation-weight-policy'],(string)filemtime($marginJS),true);
        }
    }
}
add_action('wp_ajax_'.MAD4B_Accommodation_Weight_Policies_V1::ACTION,
    [MAD4B_Accommodation_Weight_Policies_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Accommodation_Weight_Policies_V1::class,'assets'],92);
endif;


/**
 * Native accommodation financial policy: stay-length target margin or markup.
 * Same original scope identities and original JetEngine pricing workspace;
 * separate financial source keys to prevent double application with room weights.
 */
if(!class_exists('MAD4B_Accommodation_Stay_Margin_V1',false)){
final class MAD4B_Accommodation_Stay_Margin_V1 {
    public const CONTRACT='mad4b.accommodation-stay-margin.v1';
    public const GLOBAL='mad4b_accommodation_stay_margin_global_v1';
    public const META='_mad4b_accommodation_stay_margin_v1';
    private const SCOPES=['global','property_type','destination','property','unit','rate'];
    public static function allowed():bool {
        return MAD4B_Accommodation_Weight_Policies_V1::can();
    }
    private static function number($raw):int {
        if(!is_scalar($raw)||is_bool($raw))return 0;
        $n=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $n===false?0:(int)$n;
    }
    private static function tax(string $scope):?string {
        return match($scope){'property_type'=>'property-types','destination'=>'destinations',default=>null};
    }
    public static function authorize(string $scope,int $id,bool $write=false) {
        if(!self::allowed())return new WP_Error('stay_margin_forbidden');
        if(!in_array($scope,self::SCOPES,true))return new WP_Error('stay_margin_scope_invalid');
        if($scope!=='rate')
            return MAD4B_Accommodation_Weight_Policies_V1::authorize($scope,$id,$write);
        if($id<1 || get_post_type($id)!=='property-rates' ||
           !in_array(get_post_status($id),['publish','draft','pending'],true))
            return new WP_Error('stay_margin_rate_invalid');
        if(!current_user_can('edit_post',$id))return new WP_Error('stay_margin_rate_access_denied');
        return true;
    }
    private static function defaults():array {
        return ['schema'=>1,'mode'=>'preview_only',
            'pricing_rule_kind'=>'inherit','bands'=>[]];
    }
    public static function validate($raw) {
        if(!is_array($raw)||array_diff(array_keys($raw),
            ['schema','mode','pricing_rule_kind','bands'])||
           ($raw['schema']??null)!==1||
           ($raw['mode']??null)!=='preview_only')
            return new WP_Error('stay_margin_schema_invalid');
        $kind=$raw['pricing_rule_kind']??null;
        if(!in_array($kind,['inherit','target_margin_percent','markup_percent'],true))
            return new WP_Error('stay_margin_kind_invalid');
        $bands=$raw['bands']??null;
        if(!is_array($bands)||!array_is_list($bands)||count($bands)>24)
            return new WP_Error('stay_margin_bands_invalid');
        if(($kind==='inherit'&&$bands)||($kind!=='inherit'&&!$bands))
            return new WP_Error('stay_margin_kind_bands_mismatch');
        $out=[];
        foreach($bands as $band){
            if(!is_array($band)||array_diff(array_keys($band),
                  ['min_nights','max_nights','percent']))
                return new WP_Error('stay_margin_band_fields_invalid');
            $min=filter_var($band['min_nights']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>365]]);
            $max=filter_var($band['max_nights']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>365]]);
            if($min===false||$max===false||$min===null||$max===null||$min>$max)
                return new WP_Error('stay_margin_night_range_invalid');
            $percent=$band['percent']??null;
            if(!is_scalar($percent)||is_bool($percent)||
               !preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D',(string)$percent))
                return new WP_Error('stay_margin_percent_invalid');
            $n=(float)$percent;
            $limit=$kind==='target_margin_percent'?80:100;
            if(!is_finite($n)||$n>$limit)
                return new WP_Error('stay_margin_percent_out_of_range');
            $out[]=['min_nights'=>(int)$min,'max_nights'=>(int)$max,
                'percent'=>number_format($n,2,'.','')];
        }
        usort($out,static fn($a,$b)=>$a['min_nights']<=>$b['min_nights']);
        $prev=0;
        foreach($out as $band){
            if($band['min_nights']<=$prev)
                return new WP_Error('stay_margin_overlapping_bands');
            $prev=$band['max_nights'];
        }
        return ['schema'=>1,'mode'=>'preview_only',
            'pricing_rule_kind'=>$kind,'bands'=>$out];
    }
    private static function storage(string $scope,int $id):array {
        global $wpdb;
        if($scope==='global'){
            $row=$wpdb->get_row($wpdb->prepare(
              "SELECT option_id AS rowid,option_value AS raw FROM {$wpdb->options} WHERE option_name=%s",self::GLOBAL),ARRAY_A);
            return ['table'=>$wpdb->options,'row'=>$row?:null,
                'kind'=>'option','id_field'=>'option_id','value_field'=>'option_value'];
        }
        $isTerm=self::tax($scope)!==null;
        $table=$isTerm?$wpdb->termmeta:$wpdb->postmeta;
        $parent=$isTerm?'term_id':'post_id';
        $rows=$wpdb->get_results($wpdb->prepare(
          "SELECT meta_id AS rowid,meta_value AS raw FROM {$table} WHERE {$parent}=%d AND meta_key=%s LIMIT 2",
          $id,self::META),ARRAY_A);
        return ['table'=>$table,'row'=>count($rows)===1?$rows[0]:null,
            'duplicate'=>count($rows)>1,'kind'=>$isTerm?'term':'post',
            'id_field'=>'meta_id','value_field'=>'meta_value'];
    }
    private static function raw(string $scope,int $id) {
        $s=self::storage($scope,$id);
        if(!empty($s['duplicate']))return new WP_Error('stay_margin_duplicate_meta');
        if(!$s['row'])return null;
        return self::validate(maybe_unserialize($s['row']['raw']));
    }
    public static function read(string $scope,int $id=0) {
        $auth=self::authorize($scope,$id,false);
        if(is_wp_error($auth))return $auth;
        $s=self::storage($scope,$id);
        if(!empty($s['duplicate']))return new WP_Error('stay_margin_duplicate_meta');
        $raw=$s['row']['raw']??null;
        $rev=hash('sha256',$raw===null?'STAY_MARGIN_SCOPE_ABSENT_V1':$raw);
        $v=$raw===null?self::defaults():self::validate(maybe_unserialize($raw));
        if(is_wp_error($v))return $v;
        return ['policy'=>$v,'revision'=>$rev,
            'configured'=>$raw!==null&&$v['pricing_rule_kind']!=='inherit',
            'record_present'=>$raw!==null,
            'can_write'=>self::authorize($scope,$id,true)===true];
    }
    public static function save(string $scope,int $id,$policy,string $revision) {
        $auth=self::authorize($scope,$id,true);
        if(is_wp_error($auth))return $auth;
        if(!preg_match('/^[a-f0-9]{64}$/D',$revision))
            return new WP_Error('stay_margin_revision_required');
        $v=self::validate($policy);if(is_wp_error($v))return $v;
        $s=self::storage($scope,$id);
        if(!empty($s['duplicate']))return new WP_Error('stay_margin_duplicate_meta');
        $raw=$s['row']['raw']??null;
        $actual=hash('sha256',$raw===null?'STAY_MARGIN_SCOPE_ABSENT_V1':$raw);
        if(!hash_equals($actual,$revision))
            return new WP_Error('stay_margin_stale_editor_revision');
        $next=maybe_serialize($v);
        if($raw===$next)
            return ['written'=>false,'revision'=>$actual,'policy'=>$v,
                'prices_modified'=>false,'rates_modified'=>false];
        global $wpdb;
        if($raw!==null){
            $affected=$wpdb->query($wpdb->prepare(
              "UPDATE {$s['table']} SET {$s['value_field']}=%s WHERE {$s['id_field']}=%d AND {$s['value_field']}=%s",
              $next,(int)$s['row']['rowid'],$raw));
            if($affected!==1)return new WP_Error('stay_margin_concurrent_write_rejected');
        }elseif($scope==='global'){
            if(!add_option(self::GLOBAL,$v,'',false))
                return new WP_Error('stay_margin_option_create_conflict');
        }elseif(!add_metadata($s['kind'],$id,self::META,$v,true))
            return new WP_Error('stay_margin_meta_create_conflict');
        if($scope==='global'){
            wp_cache_delete(self::GLOBAL,'options');
            wp_cache_delete('notoptions','options');
            wp_cache_delete('alloptions','options');
        }else wp_cache_delete($id,$s['kind'].'_meta');
        $read=self::read($scope,$id);
        if(is_wp_error($read)||$read['policy']!==$v)
            return new WP_Error('stay_margin_readback_failed');
        return ['written'=>true,'revision'=>$read['revision'],
            'policy'=>$v,'configured'=>$read['configured'],
            'prices_modified'=>false,'rates_modified'=>false,
            'checkout_modified'=>false];
    }
    private static function relation(int $property,int $unit,int $rate) {
        if(get_post_type($property)!=='properties')
            return new WP_Error('stay_margin_property_invalid');
        $p=MAD4B_Accommodation_Weight_Policies_V1::authorize('property',$property);
        if(is_wp_error($p))return $p;
        if($unit>0){
            $p=MAD4B_Accommodation_Weight_Policies_V1::authorize('unit',$unit);
            if(is_wp_error($p))return $p;
            if(!function_exists('jet_engine'))
                return new WP_Error('stay_margin_relation_provider_missing');
            $relation=jet_engine()->relations->get_active_relations()[223]??null;
            if(!$relation)return new WP_Error('stay_margin_relation_provider_missing');
            $matches=0;
            foreach((array)$relation->get_children($property) as $child){
                $c=(int)(is_array($child)?($child['child_object_id']??0):
                    (is_object($child)?($child->child_object_id??0):$child));
                if($c===$unit)$matches++;
            }
            if($matches!==1)return new WP_Error('stay_margin_wrong_property_unit');
        }
        if($rate>0){
            $p=self::authorize('rate',$rate);
            if(is_wp_error($p))return $p;
            $relatedProperty=(int)get_post_meta($rate,'prop_related_property_id',true);
            $relatedUnit=(int)get_post_meta($rate,'prop_related_unit_id',true);
            if($relatedProperty!==$property||$relatedUnit!==$unit)
                return new WP_Error('stay_margin_rate_relation_mismatch');
        }
        return true;
    }
    private static function applicable(array $policy,int $nights):?array {
        if($policy['pricing_rule_kind']==='inherit')return null;
        foreach($policy['bands'] as $band)
            if($band['min_nights']<=$nights && $nights<=$band['max_nights'])
                return $band;
        return null;
    }
    private static function terms(int $property,string $taxonomy,string $scope,int $nights) {
        $ids=wp_get_object_terms($property,$taxonomy,['fields'=>'ids']);
        if(is_wp_error($ids))return $ids;
        $out=[];
        foreach($ids as $id){
            $policy=self::raw($scope,(int)$id);
            if(is_wp_error($policy))return $policy;
            if($policy && ($band=self::applicable($policy,$nights))){
                $term=get_term((int)$id,$taxonomy);
                $out[]=['scope'=>$scope,'target_id'=>(int)$id,
                    'label'=>$term instanceof WP_Term?$term->name:'#'.$id,
                    'pricing_rule_kind'=>$policy['pricing_rule_kind'],
                    'band'=>$band];
            }
        }
        if(count($out)>1)return new WP_Error('stay_margin_ambiguous_'.$scope);
        return $out[0]??null;
    }
    public static function resolve(int $property,int $unit,int $rate,int $nights) {
        if(!self::allowed())return new WP_Error('stay_margin_forbidden');
        if($nights<1||$nights>365)return new WP_Error('stay_margin_nights_invalid');
        $linked=self::relation($property,$unit,$rate);
        if(is_wp_error($linked))return $linked;
        $chain=[];$winner=null;
        $add=static function(string $scope,int $id,string $label)use($nights,&$winner,&$chain) {
            $policy=self::raw($scope,$id);
            if(is_wp_error($policy))return $policy;
            if(!$policy)return true;
            $band=self::applicable($policy,$nights);
            $chain[]=['scope'=>$scope,'target_id'=>$id,'label'=>$label,
                'configured'=>true,'match'=>$band!==null,'kind'=>$policy['pricing_rule_kind'],
                'bands'=>count($policy['bands'])];
            if($band!==null)
                $winner=['scope'=>$scope,'target_id'=>$id,'label'=>$label,
                    'pricing_rule_kind'=>$policy['pricing_rule_kind'],'band'=>$band];
            return true;
        };
        $ok=$add('global',0,'Global');
        if(is_wp_error($ok))return $ok;
        foreach([['property-types','property_type'],['destinations','destination']] as [$taxonomy,$scope]){
            $row=self::terms($property,$taxonomy,$scope,$nights);
            if(is_wp_error($row))return $row;
            if($row!==null){
                $ok=$add($scope,(int)$row['target_id'],$row['label']);
                if(is_wp_error($ok))return $ok;
            }
        }
        $ok=$add('property',$property,get_the_title($property));
        if(is_wp_error($ok))return $ok;
        if($unit){
            $ok=$add('unit',$unit,get_the_title($unit));
            if(is_wp_error($ok))return $ok;
        }
        if($rate){
            $ok=$add('rate',$rate,get_the_title($rate));
            if(is_wp_error($ok))return $ok;
        }
        return ['contract'=>self::CONTRACT,'status'=>$winner?'effective_preview_rule':'margin_not_configured',
            'property_id'=>$property,'unit_id'=>$unit?:null,'rate_id'=>$rate?:null,
            'nights'=>$nights,'effective'=>$winner,'source_chain'=>$chain,
            'supplier_authority'=>false,'rates_modified'=>false,'checkout_ready'=>false];
    }
    public static function preview(array $input) {
        $property=self::number($input['property_id']??0);
        $unit=self::number($input['unit_id']??0);
        $rate=self::number($input['rate_id']??0);
        $nights=filter_var($input['nights']??null,FILTER_VALIDATE_INT,
           ['options'=>['min_range'=>1,'max_range'=>365]]);
        if(!$property||$nights===false||$nights===null)
            return new WP_Error('stay_margin_preview_input_invalid');
        $resolved=self::resolve($property,$unit,$rate,(int)$nights);
        if(is_wp_error($resolved))return $resolved;
        $kind=$input['source_kind']??'manual_cost_reference';
        if(!in_array($kind,['manual_cost_reference','unapproved_sheet_cost','explicit_selling_price'],true))
            return new WP_Error('stay_margin_source_kind_invalid');
        if($rate>0){
            $priceRole=(string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true);
            // A published selling Rate (including legacy rate without a cost flag)
            // must NOT be priced again by a nights margin.
            if($priceRole!=='cost' && get_post_status($rate)==='publish')
                return ['contract'=>self::CONTRACT,'status'=>'native_selling_rate_takes_precedence',
                    'effective_policy'=>$resolved,'proposed_sell_price'=>null,
                    'price_written'=>false,'checkout_ready'=>false];
        }
        if($kind==='explicit_selling_price')
            return ['contract'=>self::CONTRACT,'status'=>'native_selling_price_takes_precedence',
                'effective_policy'=>$resolved,'proposed_sell_price'=>null,
                'price_written'=>false,'checkout_ready'=>false];
        $raw=$input['unit_cost_per_night']??null;
        if(!is_scalar($raw)||is_bool($raw)||
           !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/D',(string)$raw)||
           (float)$raw<=0)
            return new WP_Error('stay_margin_cost_invalid');
        $currency=strtoupper((string)($input['currency']??''));
        if(!preg_match('/^[A-Z]{3}$/D',$currency))
            return new WP_Error('stay_margin_currency_invalid');
        $basis=$input['cost_basis']??'per_room_night';
        if(!in_array($basis,['per_room_night','per_cabin_night','per_person_night'],true))
            return new WP_Error('stay_margin_cost_basis_invalid');
        $rule=$resolved['effective'];
        if(!$rule)
            return ['contract'=>self::CONTRACT,'status'=>'margin_not_configured',
                'effective_policy'=>$resolved,'proposed_sell_price'=>null,
                'price_written'=>false,'checkout_ready'=>false];
        $cost=round((float)$raw*(int)$nights,4);
        $percent=(float)$rule['band']['percent'];
        if($rule['pricing_rule_kind']==='target_margin_percent')
            $selling=round($cost/(1-$percent/100),4);
        else $selling=round($cost*(1+$percent/100),4);
        $profit=round($selling-$cost,4);
        return ['contract'=>self::CONTRACT,'status'=>'estimated_margin_from_unapproved_cost',
            'nights'=>(int)$nights,'currency'=>$currency,'cost_basis'=>$basis,
            'unit_cost_per_night'=>number_format((float)$raw,4,'.',''),
            'total_cost'=>number_format($cost,4,'.',''),
            'pricing_rule_kind'=>$rule['pricing_rule_kind'],
            'requested_percent'=>$rule['band']['percent'],
            'actual_gross_margin_percent'=>number_format(100*$profit/$selling,2,'.',''),
            'actual_markup_percent'=>number_format(100*$profit/$cost,2,'.',''),
            'estimated_profit'=>number_format($profit,4,'.',''),
            'proposed_sell_price'=>number_format($selling,4,'.',''),
            'effective_policy'=>$resolved,'supplier_cost_approved'=>false,
            'season_verified'=>false,'tax_included'=>false,'meals_verified'=>false,
            'inventory_verified'=>false,'rate_written'=>false,
            'price_written'=>false,'checkout_ready'=>false];
    }
    public static function inventory() {
        if(!self::allowed())return new WP_Error('stay_margin_forbidden');
        global $wpdb;
        $out=[];$seen=[];
        $add=static function(string $scope,int $id,string $label)use(&$out,&$seen){
            $key=$scope.':'.$id;
            if(isset($seen[$key]))return;
            $seen[$key]=true;
            $read=self::read($scope,$id);
            if(is_wp_error($read)||!$read['record_present'])return;
            $out[]=['scope'=>$scope,'target_id'=>$id,'label'=>$label,
                'configured'=>$read['configured'],'can_write'=>$read['can_write'],
                'pricing_rule_kind'=>$read['policy']['pricing_rule_kind'],
                'bands'=>$read['policy']['bands']];
        };
        $add('global',0,'Global');
        $terms=$wpdb->get_results($wpdb->prepare(
          "SELECT m.term_id,t.name,tt.taxonomy FROM {$wpdb->termmeta} m
           INNER JOIN {$wpdb->terms} t ON t.term_id=m.term_id
           INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=m.term_id
           WHERE m.meta_key=%s AND tt.taxonomy IN ('property-types','destinations')
           ORDER BY m.meta_id DESC LIMIT 201",self::META),ARRAY_A);
        foreach($terms as $r){
            $scope=$r['taxonomy']==='property-types'?'property_type':'destination';
            $add($scope,(int)$r['term_id'],$r['name']);
        }
        $posts=$wpdb->get_results($wpdb->prepare(
          "SELECT m.post_id,p.post_title,p.post_type FROM {$wpdb->postmeta} m
           INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
           WHERE m.meta_key=%s AND p.post_type IN ('properties','units','property-rates')
           AND p.post_status IN ('publish','draft','pending')
           ORDER BY m.meta_id DESC LIMIT 201",self::META),ARRAY_A);
        foreach($posts as $r){
            $id=(int)$r['post_id'];
            if(!current_user_can('edit_post',$id))continue;
            $scope=match($r['post_type']){'units'=>'unit','property-rates'=>'rate',default=>'property'};
            $add($scope,$id,$r['post_title']);
        }
        $active=count(array_filter($out,static fn($r)=>$r['configured']));
        return ['contract'=>self::CONTRACT,'status'=>'margin_policy_inventory_readonly',
            'items'=>array_slice($out,0,200),'configured_count'=>$active,
            'inherit_only_count'=>count($out)-$active,
            'truncated'=>count($terms)>=201||count($posts)>=201||count($out)>200,
            'commercial_authority'=>false];
    }
    public static function search(string $scope,string $term):array {
        if(!self::allowed()||!in_array($scope,self::SCOPES,true)||$scope==='global')return [];
        $term=trim(sanitize_text_field($term));
        if(mb_strlen($term)<2)return [];
        if(($tax=self::tax($scope))){
            $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,
                'search'=>$term,'number'=>25]);
            $out=[];
            if(!is_wp_error($terms))foreach($terms as $t)
                if($t instanceof WP_Term)$out[]=['id'=>(int)$t->term_id,'title'=>$t->name];
            return $out;
        }
        $postType=match($scope){'property'=>'properties','unit'=>'units','rate'=>'property-rates'};
        $q=['post_type'=>$postType,'post_status'=>['publish','draft'],
            'posts_per_page'=>25,'fields'=>'ids','suppress_filters'=>true,'no_found_rows'=>true];
        if(ctype_digit($term))$q['post__in']=[(int)$term];
        else $q['s']=$term;
        $out=[];
        foreach(get_posts($q) as $id){
            $id=(int)$id;
            if(!current_user_can('edit_post',$id))continue;
            $r=['id'=>$id,'title'=>get_the_title($id)];
            if($scope==='rate'){
                $r['property_id']=(int)get_post_meta($id,'prop_related_property_id',true);
                $r['unit_id']=(int)get_post_meta($id,'prop_related_unit_id',true);
            }
            if($scope==='unit'){
                $result=MAD4B_Accommodation_Weight_Policies_V1::search('unit',(string)$id);
                foreach($result as $item)if($item['id']===$id){
                    $r['property_id']=$item['related_property_id']??0;break;
                }
            }
            $out[]=$r;
        }
        return $out;
    }
    /** Same authenticated native Pricing Controls AJAX endpoint; no new route. */
    public static function dispatch(string $mode) {
        if(!self::allowed())return new WP_Error('stay_margin_forbidden');
        $scope=sanitize_key((string)($_POST['scope']??'global'));
        $id=self::number($_POST['target_id']??0);
        if($mode==='margin_inventory')return self::inventory();
        if($mode==='margin_search'){
            $q=sanitize_text_field(wp_unslash((string)($_POST['search']??'')));
            if(strlen($q)>95)return new WP_Error('stay_margin_search_too_long');
            return ['items'=>self::search($scope,$q)];
        }
        if($mode==='margin_load')return self::read($scope,$id);
        if($mode==='margin_save'){
            if(!isset($_POST['policy'])||!is_string($_POST['policy'])||
               strlen($_POST['policy'])>4600)return new WP_Error('stay_margin_payload_invalid');
            $p=json_decode(wp_unslash($_POST['policy']),true);
            $revision=sanitize_text_field((string)($_POST['revision']??''));
            return self::save($scope,$id,$p,$revision);
        }
        $keys=['property_id','unit_id','rate_id','nights','unit_cost_per_night',
            'currency','cost_basis','source_kind'];
        $input=[];
        foreach($keys as $key)if(isset($_POST[$key])&&is_scalar($_POST[$key]))
            $input[$key]=sanitize_text_field(wp_unslash((string)$_POST[$key]));
        $property=self::number($input['property_id']??0);
        $unit=self::number($input['unit_id']??0);
        $rate=self::number($input['rate_id']??0);
        $nights=filter_var($input['nights']??0,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>365]]);
        if(!$property||$nights===false)return new WP_Error('stay_margin_inputs_invalid');
        if($mode==='margin_effective')return self::resolve($property,$unit,$rate,(int)$nights);
        if($mode==='margin_preview')return self::preview($input);
        return new WP_Error('stay_margin_mode_unknown');
    }
}
}
/**
 * Original accommodation rate-source selector, strictly READ-ONLY.
 * Candidate precedence is Unit cost Rate -> scoped Property cost Rate.
 * It never verifies a supplier receipt, changes price, or authorizes checkout.
 */
if(!class_exists('MAD4B_Accommodation_Rate_Source_Preview_V1',false)){
final class MAD4B_Accommodation_Rate_Source_Preview_V1 {
    public const CONTRACT='mad4b.accommodation-rate-source-preview.v1';
    private static function positive($value):int {
        if(!is_scalar($value)||is_bool($value))return 0;
        $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $v===false?0:(int)$v;
    }
    private static function iso($value):?string {
        if(!is_string($value)||!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$value))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d')===$value?$value:null;
    }
    public static function inspect(array $input) {
        if(!class_exists('MAD4B_Accommodation_Weight_Policies_V1',false)||
           !MAD4B_Accommodation_Weight_Policies_V1::can())
            return new WP_Error('accommodation_rate_source_forbidden');
        $property=self::positive($input['property_id']??0);
        $unit=self::positive($input['unit_id']??0);
        if(!$property||!$unit||get_post_type($property)!=='properties'||
           get_post_type($unit)!=='units'||!current_user_can('edit_post',$property)||
           !current_user_can('edit_post',$unit))
            return new WP_Error('accommodation_rate_source_identity_invalid');
        $from=self::iso($input['from']??null);$to=self::iso($input['to']??null);
        if(!$from||!$to||$from>$to)
            return new WP_Error('accommodation_rate_source_window_invalid');
        $basis=(string)($input['price_basis']??'');
        if(!in_array($basis,['per_room_night','per_person_night','per_cabin_night'],true))
            return new WP_Error('accommodation_rate_source_basis_invalid');
        $currency=strtoupper((string)($input['currency']??''));
        if(!preg_match('/^[A-Z]{3}$/D',$currency))
            return new WP_Error('accommodation_rate_source_currency_invalid');
        $out=['contract'=>self::CONTRACT,'property_id'=>$property,'unit_id'=>$unit,
            'from'=>$from,'to'=>$to,'currency'=>$currency,'price_basis'=>$basis,
            'priority'=>['unit_cost_rate','property_cost_rate_fallback'],
            'status'=>'source_unavailable','source'=>null,'rate_id'=>null,
            'scope_candidates'=>['unit'=>0,'property'=>0],
            'supplier_cost_receipt_approved'=>false,
            'seasonal_property_assignment_verified'=>false,
            'inventory_verified'=>false,'rate_written'=>false,
            'commercial_ready'=>false,'checkout_ready'=>false];
        // A generic Property Rate is not a Unit contract. Unit provenance,
        // capacity and publication must be independently verified first.
        if(get_post_status($property)!=='publish'||get_post_status($unit)!=='publish')
            return array_replace($out,['status'=>'property_or_unit_unpublished']);
        global $wpdb;
        if(!isset($wpdb)||!isset($wpdb->prefix))
            return new WP_Error('accommodation_rate_source_relation_unavailable');
        $relation=$wpdb->prefix.'jet_rel_default';
        $count=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relation} WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
            223,$property,$unit));
        $receipt=get_post_meta($unit,'_mad4b_room_source_receipt_v1',true);
        if($count!==1||!is_array($receipt)||
           (int)($receipt['property_id']??0)!==$property||
           (int)get_post_meta($unit,'unit_max_occupancy',true)<1)
            return array_replace($out,['status'=>'unit_source_relation_or_capacity_unverified']);
        $rates=get_posts(['post_type'=>'property-rates',
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>201,'fields'=>'ids','no_found_rows'=>true,
            'suppress_filters'=>true,
            'meta_query'=>[['key'=>'prop_related_property_id','value'=>(string)$property]]]);
        if(count($rates)>=201)
            return array_replace($out,['status'=>'rate_search_truncated_review_required']);
        $matches=['unit'=>[],'property'=>[]];
        foreach($rates as $rate){
            $rate=(int)$rate;
            $related=(int)get_post_meta($rate,'prop_related_unit_id',true);
            if($related!==0&&$related!==$unit)continue;
            $scope=$related===$unit?'unit':'property';
            $out['scope_candidates'][$scope]++;
            // Historical retail prices and unapproved Draft quotes cannot
            // masquerade as a published supplier cost Rate.
            if(get_post_status($rate)!=='publish'||
               (string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true)!=='cost'||
               get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)||
               get_post_meta($rate,'_qa_disallow_public_checkout',true))continue;
            $stop=strtolower((string)get_post_meta($rate,'stop_sale',true));
            if(!in_array($stop,['0','false','no','off'],true)||
               (string)get_post_meta($rate,'avilabile_status',true)==='0')continue;
            if((string)get_post_meta($rate,'currency',true)!==$currency||
               (string)get_post_meta($rate,'prop_price_unit',true)!==$basis||
               !is_numeric(get_post_meta($rate,'base_amount',true))||
               (float)get_post_meta($rate,'base_amount',true)<=0)continue;
            $start=self::iso(get_post_meta($rate,'valid_from',true));
            $end=self::iso(get_post_meta($rate,'valid_to',true));
            // One published rate must cover the ENTIRE period. No guessed
            // Season inheritance or stitching disjoint supplier periods.
            if(!$start||!$end||$start>$from||$end<$to)continue;
            $matches[$scope][]=$rate;
        }
        foreach(['unit','property'] as $scope){
            if(count($matches[$scope])>1)
                return array_replace($out,['status'=>'ambiguous_'.$scope.'_rates_review_required']);
            if(count($matches[$scope])===1)
                return array_replace($out,['status'=>$scope==='unit'
                    ?'unit_rate_candidate_requires_receipt'
                    :'property_rate_fallback_candidate_requires_receipt',
                    'source'=>$scope==='unit'?'unit_cost_rate':'property_cost_rate_fallback',
                    'rate_id'=>$matches[$scope][0]]);
        }
        return array_replace($out,['status'=>'no_published_matching_cost_rate']);
    }
}}
