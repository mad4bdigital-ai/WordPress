<?php
/**
 * Staging-only ETG mutation adapter. No SEO/publication controls.
 *
 * Mutations execute exclusively through MAD4B's policy/NHI/authorization/
 * reversible journal and central mad4b/mutation-undo; this class does not
 * expose a second write transport, custom rollback endpoint, or SQL interface.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_SCP_ETG_DFSB_Write_Adapter extends MAD4B_SCP_Adapter_Base {
    const CONTRACT='mad4b.etg-dfsb-governed-write.v1';
    const SLOT_APPLY='etg-dfsb/slot-apply';
    const PROFILE_APPLY='etg-dfsb/profile-apply';
    const VERIFY='etg-dfsb/write-verify';
    const SLOT_ROLLBACK='mad4b.rollback.etg-dfsb-slot.v1';
    const PROFILE_ROLLBACK='mad4b.rollback.etg-dfsb-profile.v1';
    const MAX_PROFILE_OPTION_BYTES=160000;
    private $reversibleScope=null;

    public function id(){return 'etg-dfsb-write';}
    public function label(){return 'ETG DFSB Staging Mutation Operations';}
    protected function certified_provider_key(){return 'etg-dfsb';}
    protected function mutation_requires_certification(){return true;}
    protected function detect_plugin_version(){return defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'';}
    public function is_available(){
        return defined('ETG_DFSB_VERSION')&&'0.4.0-alpha.13'===ETG_DFSB_VERSION
            &&function_exists('wp_get_environment_type')
            &&'staging'===wp_get_environment_type()
            &&class_exists('\ETG\DynamicFilterSEOBridge\Config\Configuration')
            &&class_exists('\ETG\DynamicFilterSEOBridge\Config\ProfileRegistry')
            &&class_exists('\ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory')
            &&class_exists('\ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner')
            &&class_exists('\ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry');
    }
    public function ability_names(){
        return array('read'=>array(self::VERIFY),'admin'=>array(self::SLOT_APPLY,self::PROFILE_APPLY),'content'=>array(),'write'=>array());
    }
    public function reversible_contracts(){
        return array(self::SLOT_APPLY=>self::SLOT_ROLLBACK,self::PROFILE_APPLY=>self::PROFILE_ROLLBACK);
    }
    public function status(){
        $result=parent::status();
        $result['contract']=self::CONTRACT;
        $result['staging_only']=true;
        $result['seo_publication_supported']=false;
        $result['production_allowed']=false;
        $result['mutation_requires_certification']=true;
        $result['undo_ability']='mad4b/mutation-undo';
        $result['authority_inferred_from_read_abilities']=false;
        return $result;
    }
    public function can_read_verify($input=array()){
        return $this->is_available()&&current_user_can('manage_options');
    }
    public function can_write_etg($input=array()){
        if(!$this->is_available()||!current_user_can('manage_options')){
            return new WP_Error('mad4b_etg_staging_admin_required','ETG mutation requires Staging and a WordPress administrator.');
        }
        return true;
    }
    public function register_abilities(){
        $read=array('MAD4B_SCP_Policy','can_read');
        $admin=array($this,'can_write_etg');
        $hex=array('type'=>'string','pattern'=>'^[a-f0-9]{64}$');
        $rev=array('type'=>'string','minLength'=>16,'maxLength'=>64);
        $this->add_ability(self::VERIFY,'Read Staging ETG State Verification','write_verify',$read,
            $this->schema(array(
                'target_type'=>array('type'=>'string','enum'=>array('slot','profile')),
                'target_id'=>array('type'=>'string','minLength'=>1,'maxLength'=>64),
                'expected_sha256'=>$hex
            ),array('target_type','target_id')),'read',true,false,true);
        $this->add_ability(self::SLOT_APPLY,'Apply ETG Content Slot on Staging','slot_apply',$admin,
            $this->schema(array(
                'confirmed'=>array('type'=>'boolean','const'=>true),
                'slot'=>array('type'=>'object','maxProperties'=>24,'additionalProperties'=>true),
                'expected_revision'=>$rev,
                'expected_proposed_sha256'=>$hex
            ),array('confirmed','slot','expected_revision','expected_proposed_sha256')),'admin',false,true,false);
        $this->add_ability(self::PROFILE_APPLY,'Apply Verified Disabled ETG Profile Binding on Staging','profile_apply',$admin,
            $this->schema(array(
                'confirmed'=>array('type'=>'boolean','const'=>true),
                'profile_id'=>array('type'=>'string','minLength'=>1,'maxLength'=>64),
                'expected_revision'=>array('type'=>'string','minLength'=>16,'maxLength'=>16),
                'snapshot_fingerprint'=>array('type'=>'string','minLength'=>16,'maxLength'=>128)
            ),array('confirmed','profile_id','expected_revision','snapshot_fingerprint')),'admin',false,true,false);
    }
    private function guard(){
        if(!$this->is_available()||!current_user_can('manage_options')){
            return new WP_Error('mad4b_etg_staging_only','ETG writes are unavailable outside authorized Staging.');
        }
        $cfg=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
        if($cfg->enabled() || !empty($cfg->validationErrors())){
            return new WP_Error('mad4b_etg_global_or_config_unsafe','Global Bridge must be OFF and ETG configuration valid.');
        }
        return true;
    }
    private function check_executor_context(){
        if(!class_exists('MAD4B_SCP_Reversible_Adapter_Mutations')
            || !method_exists('MAD4B_SCP_Reversible_Adapter_Mutations','current_mutation_context')){
            return new WP_Error('mad4b_etg_mutation_journal_missing','No governed reversible mutation journal.');
        }
        $ctx=MAD4B_SCP_Reversible_Adapter_Mutations::current_mutation_context();
        if(!is_array($ctx)||empty($ctx['mutation_id'])||'etg-dfsb-write'!==($ctx['adapter_id']??'')){
            return new WP_Error('mad4b_etg_unjournaled_mutation_denied','ETG mutations must run inside the governed reversible execution context.');
        }
        return true;
    }
    private function clean_id($v){return is_scalar($v)?sanitize_key((string)$v):'';}
    private function is_hex($v,$len){return is_string($v)&&strlen($v)===$len&&1===preg_match('/^[a-f0-9]+$/D',$v);}
    private function lock($kind,$id){
        $resource='slot'===$kind?'etg_dfsb_dynamic_content_slots':
            ('profile'===$kind?'etg_dfsb_settings':'');
        if(''===$resource)return new WP_Error('mad4b_etg_scope_target_invalid','Unsupported ETG storage resource.');
        $key='mad4b_etg_lock_'.substr(hash('sha256','option|'.$resource),0,36);
        if(is_array($this->reversibleScope)&&$this->reversibleScope['key']===$key){
            return array('key'=>$key,'token'=>$this->reversibleScope['token'],'resource'=>$resource,'borrowed'=>true);
        }
        if(!class_exists('\\ETG\\DynamicFilterSEOBridge\\Runtime\\OptionMutationGate')){
            return new WP_Error('mad4b_etg_option_gate_missing','Shared ETG option mutex is not available.');
        }
        return \ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate::acquire($resource);
    }
    private function unlock($lock){
        if(!is_array($lock)||!empty($lock['borrowed']))return;
        \ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate::release($lock);
    }
    public function acquire_reversible_scope($ability,array $input){
        if(null!==$this->reversibleScope)return new WP_Error('mad4b_etg_nested_scope_denied','Nested ETG mutation scope denied.');
        $kind=self::SLOT_APPLY===$ability?'slot':(self::PROFILE_APPLY===$ability?'profile':'');
        $id=$this->clean_id('slot'===$kind?($input['slot']['id']??''):($input['profile_id']??''));
        if(''===$kind||''===$id)return new WP_Error('mad4b_etg_scope_target_invalid','Cannot lock an invalid ETG target.');
        $lock=$this->lock($kind,$id);
        if(is_wp_error($lock))return $lock;
        $this->reversibleScope=$lock;
        return $lock;
    }
    public function acquire_reversible_undo_scope($ability,array $target){
        $kind=(string)($target['kind']??'');
        $id=$this->clean_id($target['id']??'');
        if(($kind==='slot'&&$ability!==self::SLOT_APPLY)||($kind==='profile'&&$ability!==self::PROFILE_APPLY)
            ||!in_array($kind,array('slot','profile'),true)||''===$id){
            return new WP_Error('mad4b_etg_undo_scope_invalid','Undo target does not match ETG ability.');
        }
        return $this->acquire_reversible_scope($ability,array('slot'=>array('id'=>$id),'profile_id'=>$id));
    }
    public function retain_reversible_scope($lock){
        if(!is_array($lock)||!is_array($this->reversibleScope)
            ||!isset($lock['key'],$lock['token'])
            ||$lock['key']!==$this->reversibleScope['key']
            ||!hash_equals((string)$lock['token'],(string)$this->reversibleScope['token']))return false;
        return \ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate::retain($this->reversibleScope);
    }
    public function release_reversible_scope($lock){
        if(!is_array($lock)||!is_array($this->reversibleScope)||!isset($lock['token'],$lock['key'])
            ||$lock['key']!==$this->reversibleScope['key']
            ||!hash_equals((string)$this->reversibleScope['token'],(string)$lock['token']))return;
        $this->unlock($this->reversibleScope);
        $this->reversibleScope=null;
    }
    private function snapshot($kind,$id){
        if('slot'===$kind){
            $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
            return array('slot'=>$slots->get($id));
        }
        if('profile'===$kind){
            $raw=get_option(\ETG\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME,array());
            if(!is_array($raw)||!isset($raw['profiles_json'])||!is_string($raw['profiles_json'])){
                return new WP_Error('mad4b_etg_profile_raw_missing','Stored ETG profile settings are unavailable.');
            }
            $encoded=wp_json_encode($raw);
            if(false===$encoded||strlen($encoded)>self::MAX_PROFILE_OPTION_BYTES){
                return new WP_Error('mad4b_etg_profile_snapshot_too_large','Profile option exceeds safe reversible scope.');
            }
            return array('option'=>$raw);
        }
        return new WP_Error('mad4b_etg_target_invalid','Unsupported ETG target.');
    }
    public function read_reversible_state($ability_name,array$target){
        $kind=(string)($target['kind']??'');
        $id=$this->clean_id($target['id']??'');
        if(!$id || !in_array($kind,array('slot','profile'),true)){
            return new WP_Error('mad4b_etg_readback_target_invalid','Target identity invalid.');
        }
        return $this->snapshot($kind,$id);
    }
    public function write_verify($input=array()){
        $guard=$this->guard();
        if(is_wp_error($guard))return $guard;
        $input=is_array($input)?$input:array();
        $kind=(string)($input['target_type']??'');
        $id=$this->clean_id($input['target_id']??'');
        $snapshot=$this->read_reversible_state(self::VERIFY,array('kind'=>$kind,'id'=>$id));
        if(is_wp_error($snapshot))return $snapshot;
        $digest=hash('sha256',wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $expected=(string)($input['expected_sha256']??'');
        return array('contract'=>'mad4b.etg-dfsb-write-verification.v1','target_type'=>$kind,'target_id'=>$id,
            'state_sha256'=>$digest,'expected_matches'=>''!==$expected&&$this->is_hex($expected,64)&&hash_equals($expected,$digest),
            'read_only'=>true,'authorizing'=>false,'mutation_performed'=>false);
    }
    public function capture_reversible_state($ability_name,array$input){
        $guard=$this->guard();if(is_wp_error($guard))return $guard;
        if(true!==($input['confirmed']??null)){
            return new WP_Error('mad4b_etg_confirmation_required','Explicit confirmed=true is required.');
        }
        if(self::SLOT_APPLY===$ability_name){
            $slot=$input['slot']??array();
            if(!is_array($slot))return new WP_Error('mad4b_etg_slot_invalid','Slot must be an object.');
            $serialized=wp_json_encode($slot);
            if(!is_string($serialized)||strlen($serialized)>32768)return new WP_Error('mad4b_etg_slot_payload_too_large','Slot mutation payload exceeds the bounded limit.');
            $id=$this->clean_id($slot['id']??'');
            if(''===$id||0===strpos($id,'etg_background_')){
                return new WP_Error('mad4b_etg_reserved_slot','Reserved or missing slot identifier.');
            }
            $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
            if(!hash_equals($slots->revision(),(string)($input['expected_revision']??''))){
                return new WP_Error('mad4b_etg_slot_stale','Slot registry revision changed.');
            }
            $normalized=$slots->normalize($slot);
            if(!hash_equals(hash('sha256',wp_json_encode($normalized)),(string)($input['expected_proposed_sha256']??''))){
                return new WP_Error('mad4b_etg_slot_plan_mismatch','Slot payload differs from the reviewed plan.');
            }
            $kind='slot';
        }elseif(self::PROFILE_APPLY===$ability_name){
            $id=$this->clean_id($input['profile_id']??'');
            if(''===$id)return new WP_Error('mad4b_etg_profile_id_required','Profile ID required.');
            $cfg=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
            if(!hash_equals($cfg->revision(),(string)($input['expected_revision']??''))){
                return new WP_Error('mad4b_etg_profile_stale','Profile configuration changed after review.');
            }
            $kind='profile';
            $proposal=$this->safe_profile_proposal($id,(string)($input['snapshot_fingerprint']??''));
            if(is_wp_error($proposal))return $proposal;
        }else{
            return new WP_Error('mad4b_etg_ability_unsupported','Unsupported ETG reversible operation.');
        }
        $state=$this->snapshot($kind,$id);
        if(is_wp_error($state))return $state;
        return array('target_type'=>'etg_'.$kind,'target_id'=>$id,'target'=>array('kind'=>$kind,'id'=>$id),'state'=>$state);
    }
    private function safe_profile_proposal($id,$fingerprint){
        $cfg=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
        $profiles=new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry($cfg);
        $inventory=new \ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory();
        $snapshot=$inventory->collect();
        $actual=(string)($snapshot['snapshot_fingerprint']??'');
        if(''===$fingerprint||''===$actual||!hash_equals($fingerprint,$actual)){
            return new WP_Error('mad4b_etg_snapshot_drift','Inventory fingerprint changed or is missing.');
        }
        $plan=(new \ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner())->plan($snapshot,$profiles->all());
        $candidate=$plan['proposals'][$id]??array();
        if(!is_array($candidate)||empty($candidate['safe_to_apply'])||empty($candidate['proposed_profile'])){
            return new WP_Error('mad4b_etg_profile_plan_blocked','No safe verified inventory proposal exists.');
        }
        $profilesRaw=json_decode((string)($cfg->all()['profiles_json']??''),true);
        if(!is_array($profilesRaw))return new WP_Error('mad4b_etg_profiles_invalid','Profiles JSON is invalid.');
        $found=false;
        foreach($profilesRaw as $profile){
            if(is_array($profile)&&$id===$this->clean_id($profile['id']??'')){
                $found=true;
                if(!empty($profile['enabled'])){
                    return new WP_Error('mad4b_etg_profile_enabled','Enabled profiles cannot be changed by this operation.');
                }
                break;
            }
        }
        if(!$found)return new WP_Error('mad4b_etg_profile_missing','Profile not found in stored configuration.');
        return $candidate;
    }
    public function slot_apply($input=array()){
        $ctx=$this->check_executor_context();if(is_wp_error($ctx))return $ctx;
        $guard=$this->guard();if(is_wp_error($guard))return $guard;
        $slot=is_array($input['slot']??null)?$input['slot']:array();
        $id=$this->clean_id($slot['id']??'');
        $lock=$this->lock('slot',$id);if(is_wp_error($lock))return $lock;
        try{
            $before=$this->capture_reversible_state(self::SLOT_APPLY,$input);
            if(is_wp_error($before))return $before;
            $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
            $result=$slots->save($slot,(string)$input['expected_revision']);
            if(empty($result['saved']))return new WP_Error('mad4b_etg_slot_write_failed','Native slot save/revision/readback rejected mutation.');
            $after=$this->snapshot('slot',$id);
            if(is_wp_error($after)||empty($after['slot'])){
                return new WP_Error('mad4b_etg_slot_readback_failed','Slot write was not verified.');
            }
            return array('contract'=>self::CONTRACT,'slot_id'=>$id,'native_result'=>(string)($result['reason']??''),
                'verified'=>true,'seo_publication_performed'=>false);
        }finally{$this->unlock($lock);}
    }
    public function profile_apply($input=array()){
        $ctx=$this->check_executor_context();if(is_wp_error($ctx))return $ctx;
        $guard=$this->guard();if(is_wp_error($guard))return $guard;
        $id=$this->clean_id($input['profile_id']??'');
        $lock=$this->lock('profile',$id);if(is_wp_error($lock))return $lock;
        try{
            $before=$this->capture_reversible_state(self::PROFILE_APPLY,$input);
            if(is_wp_error($before))return $before;
            $proposal=$this->safe_profile_proposal($id,(string)$input['snapshot_fingerprint']);
            if(is_wp_error($proposal))return $proposal;
            $cfg=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
            $raw=$cfg->all();
            if(!hash_equals($cfg->revision(),(string)$input['expected_revision'])){
                return new WP_Error('mad4b_etg_profile_raced','Profile revision changed during mutation.');
            }
            $rows=json_decode((string)($raw['profiles_json']??''),true);
            if(!is_array($rows))return new WP_Error('mad4b_etg_profiles_invalid','Profiles JSON invalid.');
            $changed=false;
            foreach($rows as &$row){
                if(!is_array($row)||$id!==$this->clean_id($row['id']??''))continue;
                $old=$row;
                $proposed=(array)$proposal['proposed_profile'];
                foreach(array('post_types','require_post_type_binding','post_type_authority','routes')as$field){
                    if(array_key_exists($field,$proposed))$row[$field]=$proposed[$field];
                }
                if(empty($row['archive_paths'])&&!empty($proposed['archive_paths']))$row['archive_paths']=$proposed['archive_paths'];
                $row['enabled']=false;
                if($old!==$row){
                    $publication=is_array($row['publication']??null)?$row['publication']:array();
                    foreach(array('elementor_content_verified','provider_observation_verified','result_count_parity_verified')as$flag)$publication[$flag]=false;
                    foreach(array('elementor_verification_evidence_id','provider_observation_evidence_id','result_count_parity_evidence_id')as$field)$publication[$field]='';
                    $row['publication']=$publication;
                    $changed=true;
                }
                break;
            }
            unset($row);
            if(!$changed)return array('contract'=>self::CONTRACT,'profile_id'=>$id,'verified'=>true,'unchanged'=>true,'seo_publication_performed'=>false);
            $raw['enabled']=false;
            $raw['profiles_json']=wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $target=$cfg->sanitizeForStorage($raw);
            if(!is_array($target)||!empty($target['enabled'])){
                return new WP_Error('mad4b_etg_profile_target_unsafe','Normalized profile patch would enable Global Bridge.');
            }
            update_option(\ETG\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME,$target,false);
            if(get_option(\ETG\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME,array())!==$target){
                return new WP_Error('mad4b_etg_profile_persist_failed','Profile patch did not pass exact persistent readback.');
            }
            return array('contract'=>self::CONTRACT,'profile_id'=>$id,'verified'=>true,
                'applied_disabled_profile_only'=>true,'seo_publication_performed'=>false);
        }finally{$this->unlock($lock);}
    }
    public function restore_reversible_state($ability_name,array$target,array$state,array$record){
        // Undo must remain possible when a failed apply leaves invalid config;
        // require Staging/admin/Global OFF, but do not require the after-state
        // to pass the normal forward-write validation gate.
        if(!$this->is_available()||!current_user_can('manage_options')){
            return new WP_Error('mad4b_etg_undo_staging_admin_required','ETG undo requires Staging administrator.');
        }
        $restoreConfig=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
        if($restoreConfig->enabled()){
            return new WP_Error('mad4b_etg_undo_global_on','Global Bridge must be OFF before ETG undo.');
        }
        if(!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate()){
            return new WP_Error('mad4b_etg_undo_authority_required','Central MAD4B mutation authority required for undo.');
        }
        $kind=(string)($target['kind']??'');$id=$this->clean_id($target['id']??'');
        if(''===$id||!in_array($kind,array('slot','profile'),true)
            ||(self::SLOT_APPLY===$ability_name&&'slot'!==$kind)
            ||(self::PROFILE_APPLY===$ability_name&&'profile'!==$kind)){
            return new WP_Error('mad4b_etg_undo_target_mismatch','Rollback target does not match ability.');
        }
        $lock=$this->lock($kind,$id);if(is_wp_error($lock))return $lock;
        try{
            $current=$this->snapshot($kind,$id);
            if(is_wp_error($current))return $current;
            $currentSha=hash('sha256',wp_json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            if(empty($record['after_sha256'])||!hash_equals((string)$record['after_sha256'],$currentSha)){
                return new WP_Error('mad4b_etg_undo_state_drift','Target changed since verified write; refuse rollback.');
            }
            if('slot'===$kind){
                $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
                $prior=is_array($state['slot']??null)?$state['slot']:array();
                $origin=(string)($prior['origin']??'');
                if(in_array($origin,array('custom','override'),true)){
                    unset($prior['origin']);
                    $saved=$slots->save($prior,$slots->revision());
                    if(empty($saved['saved']))return new WP_Error('mad4b_etg_undo_slot_failed','Native slot restore rejected.');
                }else{
                    if(!$slots->delete($id,$slots->revision()))return new WP_Error('mad4b_etg_undo_slot_delete_failed','Native slot delete rejected.');
                }
            }else{
                $option=is_array($state['option']??null)?$state['option']:array();
                if(!$option||!array_key_exists('profiles_json',$option)||!empty($option['enabled'])){
                    return new WP_Error('mad4b_etg_undo_profile_unsafe','Previous profile config is absent or would enable Global.');
                }
                $rows=json_decode((string)$option['profiles_json'],true);
                if(!is_array($rows))return new WP_Error('mad4b_etg_undo_profiles_invalid','Previous profile JSON invalid.');
                foreach($rows as $row){
                    if(is_array($row)&&$id===$this->clean_id($row['id']??'')&&!empty($row['enabled'])){
                        return new WP_Error('mad4b_etg_undo_profile_enabled','Rollback cannot enable a Profile.');
                    }
                }
                update_option(\ETG\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME,$option,false);
                if(get_option(\ETG\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME,array())!==$option){
                    return new WP_Error('mad4b_etg_undo_profile_readback_failed','Rollback option readback mismatch.');
                }
            }
            $verify=$this->snapshot($kind,$id);
            if(is_wp_error($verify)||$verify!==$state){
                return new WP_Error('mad4b_etg_undo_readback_mismatch','Restored ETG state differs from captured before-state.');
            }
            return array('restored'=>true,'verified'=>true,'contract'=>self::CONTRACT);
        }finally{$this->unlock($lock);}
    }
}
add_action('mad4b_scp_register_adapters',static function($registry){
    if(is_object($registry)&&method_exists($registry,'register')){
        $registry->register(new MAD4B_SCP_ETG_DFSB_Write_Adapter());
    }
});
