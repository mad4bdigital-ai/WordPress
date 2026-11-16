<?php
/**
 * Plugin Name: MAD4B Tour Media Association
 * Description: Signed, site-pinned non-destructive tour media associations; no attachment ownership changes.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Media_Association_V1 {
    const CONTRACT='mad4b.tour-media-association.v1';
    const SLOT='_mad4b_tour_media_association_v1';
    private static function error($code){return new WP_Error($code,'Exact signed tour media association rejected.');}
    private static function status(){
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return [];
        $s=MAD4B_SCP_Site_Profile::status();
        return !empty($s['site_uuid'])&&!empty($s['authority_ready'])&&!empty($s['origin_match'])
            &&!empty($s['environment_match'])&&!empty($s['deployment_binding_match'])?$s:[];
    }
    private static function family($id){
        $trid=apply_filters('wpml_element_trid',null,$id,'post_tours-and-activities');
        $trs=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
        $family=[];
        foreach((array)$trs as $lang=>$t){$pid=(int)($t->element_id??0);
            if(preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)$lang) && $pid && get_post_type($pid)==='tours-and-activities' && get_post_status($pid)==='publish')$family[$lang]=$pid;
        }
        ksort($family);return $family;
    }
    public static function state_sha256($id){
        $v=get_post_meta($id,self::SLOT,true);
        return hash('sha256',wp_json_encode($v?:null,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function verified_ids($id,array $family){
        $site=self::status();$m=get_post_meta($id,self::SLOT,true);
        if(!$site||!is_array($m)||($m['contract']??'')!==self::CONTRACT||($m['site_uuid']??'')!==$site['site_uuid']||($m['canonical_post_id']??0)!==$id||($m['wpml_family']??[])!==$family)return [];
        $items=$m['items']??[];
        if(!is_array($items)||!array_is_list($items)||count($items)<4||count($items)>12)return [];
        $ids=[];$shas=[];
        foreach($items as $row){
            $aid=$row['attachment_id']??0;$sha=$row['sha256']??'';
            if(!is_int($aid)||$aid<1||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||isset($ids[$aid])||isset($shas[$sha]))return [];
            $trs=class_exists('MAD4B_WPML_Attachment_Family_Readback_V1',false)?MAD4B_WPML_Attachment_Family_Readback_V1::translations($aid):[];
            if(get_post_type($aid)!=='attachment')return [];
            $sourceFile=get_attached_file($aid);
            if(!is_file((string)$sourceFile)||!hash_equals($sha,hash_file('sha256',$sourceFile)))return [];
            foreach($family as $lang=>$pid){
                $local=(int)($trs[$lang]->element_id??0);
                if(!$local||get_post_type($local)!=='attachment')return [];
                $f=get_attached_file($local);
                if(!is_file((string)$f)||!hash_equals($sha,hash_file('sha256',$f)))return [];
            }
            $ids[$aid]=true;$shas[$sha]=true;
        }
        return array_keys($ids);
    }
    public static function execute(array $body){
        if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)||!MAD4B_SCP_Host_Agent_Bridge::active())return self::error('mad4b_media_associate_signed_host_required');
        $keys=array_keys($body);sort($keys);
        $required=['contract','site_uuid','canonical_post_id','expected_state_sha256','action','items','review'];sort($required);
        if($keys!==$required||($body['contract']??'')!==self::CONTRACT)return self::error('mad4b_media_associate_invalid_schema');
        $site=self::status();$id=$body['canonical_post_id'];
        if(!$site||!is_int($id)||$id<1||($body['site_uuid']??'')!==$site['site_uuid']||get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return self::error('mad4b_media_associate_site_tour_mismatch');
        $family=self::family($id);$source=(string)apply_filters('wpml_default_language','en');
        if(!$family||($family[$source]??0)!==$id)return self::error('mad4b_media_associate_not_canonical');
        $before=self::state_sha256($id);
        if(!is_string($body['expected_state_sha256'])||!hash_equals($before,$body['expected_state_sha256']))return self::error('mad4b_media_associate_state_drift');
        if(!in_array($body['action'],['apply','revoke'],true))return self::error('mad4b_media_associate_action_invalid');
        $review=$body['review'];$rkeys=is_array($review)?array_keys($review):[];sort($rkeys);$expected=['contact_sheet_sha256','method','notes','provenance_status'];sort($expected);
        if($rkeys!==$expected||($review['method']??'')!=='ai_visual_review'||($review['provenance_status']??'')!=='unverified'||!preg_match('/^[a-f0-9]{64}$/D',(string)($review['contact_sheet_sha256']??''))||mb_strlen((string)($review['notes']??''))<30)return self::error('mad4b_media_associate_review_invalid');
        $items=$body['items'];
        if($body['action']==='revoke'){
            if($items!==[])return self::error('mad4b_media_associate_revoke_not_empty');
            delete_post_meta($id,self::SLOT);
            if(get_post_meta($id,self::SLOT,true))return self::error('mad4b_media_associate_revoke_readback_failed');
            return ['contract'=>self::CONTRACT,'status'=>'revoked_verified','site_uuid'=>$site['site_uuid'],'canonical_post_id'=>$id,'before_sha256'=>$before,'after_sha256'=>self::state_sha256($id),'attachment_parent_changed'=>false];
        }
        if(!is_array($items)||!array_is_list($items)||count($items)<4||count($items)>12)return self::error('mad4b_media_associate_count_invalid');
        $seen=[];$physical=[];
        foreach($items as $entry){
            $ek=array_keys((array)$entry);sort($ek);
            if($ek!==['attachment_id','sha256']||!is_int($entry['attachment_id'])||$entry['attachment_id']<1||!is_string($entry['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$entry['sha256'])||isset($seen[$entry['attachment_id']])||isset($physical[$entry['sha256']]))return self::error('mad4b_media_associate_entry_invalid');
            $aid=$entry['attachment_id'];$sha=$entry['sha256'];$seen[$aid]=true;$physical[$sha]=true;
            if(get_post_type($aid)!=='attachment')return self::error('mad4b_media_associate_missing_attachment');
            $tr=MAD4B_WPML_Attachment_Family_Readback_V1::translations($aid);
            if((int)($tr[$source]->element_id??0)!==$aid)return self::error('mad4b_media_associate_attachment_not_canonical');
            foreach($family as $lang=>$pid){
                $local=(int)($tr[$lang]->element_id??0);$file=$local?get_attached_file($local):null;
                if(!$local||get_post_type($local)!=='attachment'||!is_file((string)$file)||!hash_equals($sha,hash_file('sha256',$file)))return self::error('mad4b_media_associate_wpml_hash_mismatch');
            }
        }
        $saved=['contract'=>self::CONTRACT,'site_uuid'=>$site['site_uuid'],'canonical_post_id'=>$id,'wpml_family'=>$family,'items'=>$items,'review'=>$review,'rights_verified'=>false,'source'=>'signed_host_editorial_image_review','created_utc'=>gmdate('c')];
        $ok=update_post_meta($id,self::SLOT,$saved);
        if(!$ok||get_post_meta($id,self::SLOT,true)!==$saved)return self::error('mad4b_media_associate_storage_failed');
        $verified=self::verified_ids($id,$family);
        if(count($verified)!==count($items)){
            delete_post_meta($id,self::SLOT);
            return self::error('mad4b_media_associate_verified_readback_failed');
        }
        return ['contract'=>self::CONTRACT,'status'=>'associated_verified','site_uuid'=>$site['site_uuid'],'canonical_post_id'=>$id,'attachment_ids'=>$verified,'before_sha256'=>$before,'after_sha256'=>self::state_sha256($id),'attachment_parent_changed'=>false,'wpml_post_fields_changed'=>false,'rights_verified'=>false];
    }
}
