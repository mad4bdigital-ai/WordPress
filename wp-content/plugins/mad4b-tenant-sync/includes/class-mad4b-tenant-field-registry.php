<?php
/**
 * Explicit, extensible content-field registry.
 * A site may customize any *registered content field*, but financial, order,
 * payment, credential and private user data cannot enter content sync.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Field_Registry {
    const CONTRACT='mad4b.tenant-field-registry.v1';
    const TYPES=['text','hash','taxonomy_refs','media_ref','media_collection'];
    const POLICIES=['inherit','local_wins','review','local_only'];
    public static function definitions($post_type='') {
        $rows=[
            'title'=>['type'=>'text','default'=>'review','per_language'=>true,'provider'=>'wordpress','label'=>'Title'],
            'excerpt'=>['type'=>'text','default'=>'review','per_language'=>true,'provider'=>'wordpress','label'=>'Excerpt'],
            'body_sha256'=>['type'=>'hash','default'=>'review','per_language'=>true,'provider'=>'wordpress','label'=>'Content fingerprint'],
            'taxonomy_refs'=>['type'=>'taxonomy_refs','default'=>'review','per_language'=>true,'provider'=>'wordpress','label'=>'Destinations / taxonomies'],
            'featured_media_global_id'=>['type'=>'media_ref','default'=>'local_wins','per_language'=>true,'provider'=>'wordpress','label'=>'Featured image'],
            'gallery_media'=>['type'=>'media_collection','default'=>'local_wins','per_language'=>true,'provider'=>'jetengine','label'=>'Gallery'],
            'offer_banners'=>['type'=>'media_collection','default'=>'local_only','per_language'=>true,'provider'=>'site-marketing','label'=>'Offer banners'],
            'seo_title'=>['type'=>'text','default'=>'local_wins','per_language'=>true,'provider'=>'rank-math','label'=>'SEO title'],
            'seo_description'=>['type'=>'text','default'=>'local_wins','per_language'=>true,'provider'=>'rank-math','label'=>'SEO description'],
            'seo_focus_keyword'=>['type'=>'text','default'=>'local_wins','per_language'=>true,'provider'=>'rank-math','label'=>'Focus keyword'],
            'seo_og_image'=>['type'=>'media_ref','default'=>'local_wins','per_language'=>true,'provider'=>'rank-math','label'=>'Social share image'],
            // A typed layout preference, never a raw Elementor/JetEngine JSON document.
            'presentation_variant'=>['type'=>'text','default'=>'local_wins',
                'per_language'=>true,'provider'=>'site-presentation','label'=>'Layout: Card or Accordion'],
        ];
        if(function_exists('apply_filters')){
            $filtered=apply_filters('mad4b/tenant_sync/field_definitions',$rows,$post_type);
            if(is_array($filtered))$rows=$filtered;
        }
        $out=[];
        foreach($rows as $key=>$def){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_:.]{1,95}$/D',$key)
                || preg_match('/(?:cost|price|profit|margin|payment|booking|order|customer|secret|password|token|credential|inventory|allotment|email|phone|user_id)/i',$key)
                || !is_array($def)||!in_array($def['type']??'',self::TYPES,true)
                || !in_array($def['default']??'',self::POLICIES,true)
                || empty($def['provider'])||!is_string($def['provider'])
                || !preg_match('/^[a-z][a-z0-9_-]{1,60}$/D',$def['provider'])
                || !array_key_exists('per_language',$def)||!is_bool($def['per_language'])
                || !isset($def['label'])||!is_string($def['label']))continue;
            $out[$key]=[
                'type'=>$def['type'],'default'=>$def['default'],
                'per_language'=>$def['per_language'],'provider'=>$def['provider'],
                'label'=>substr($def['label'],0,90),
                'schema_version'=>1,'financial'=>false,'tenant_editable'=>true,
            ];
        }
        ksort($out,SORT_STRING);
        return $out;
    }
    public static function valid_value($field,$value,$site_uuid,$role,$definitions=null) {
        $defs=is_array($definitions)?$definitions:self::definitions();
        if(!isset($defs[$field])||!in_array($role,['source','tenant'],true))return false;
        $type=$defs[$field]['type'];
        if($value===null)return true; // explicit clear; controlled by field policy.
        if($field==='presentation_variant')
            return is_string($value)&&in_array($value,['card','accordion'],true);
        if($type==='text')return is_string($value)&&strlen($value)<=20000;
        if($type==='hash')return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;
        if($type==='taxonomy_refs'){
            if(!is_array($value)||count($value)>100)return false;
            foreach($value as $ref)
                if(!is_string($ref)||!preg_match('/^[a-z0-9_:-]{2,150}$/D',$ref))return false;
            return true;
        }
        if($type==='media_ref')return self::valid_media($value,$site_uuid,$role);
        if($type==='media_collection'){
            if(!is_array($value)||count($value)>60)return false;
            foreach($value as $ref)if(!self::valid_media($ref,$site_uuid,$role))return false;
            return true;
        }
        return false;
    }
    private static function valid_media($ref,$site_uuid,$role) {
        // The remote Hub can ONLY supply canonical media IDs, never WP IDs.
        if(is_string($ref))return preg_match('/^media-[a-f0-9]{32}$/D',$ref)===1;
        if($role!=='tenant'||!is_array($ref)
            ||array_keys($ref)!==['scope','site_uuid','attachment_id'])return false;
        $id=$ref['attachment_id']??null;
        return ($ref['scope']??'')==='tenant'
            &&($ref['site_uuid']??'')===$site_uuid
            &&is_int($id)&&$id>0;
        // Actual attachment existence and ownership must be verified by a
        // future tenant-local write adapter before applying a media change.
    }
}
