<?php
/**
 * Plugin Name: MAD4B Canonical Legacy Nile Alias Bridge (Staging)
 * Description: Verified identity references from legacy Nile Hub to new private Canonical Registry; no merges or content copies.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Legacy_Alias_Bridge_V1 {
    const STORE='mad4b_hub_canonical_nile_legacy_aliases_v1';
    private static function admin() {
        return class_exists('MAD4B_Hub_Canonical_Registry_V1') &&
            MAD4B_Hub_Canonical_Registry_V1::site() && current_user_can('manage_options');
    }
    private static function fail($code,$status=409) {
        return new WP_Error('legacy_alias_'.$code,'Legacy identity bridge unavailable',['status'=>$status]);
    }
    public static function sync() {
        if(!self::admin())return self::fail('permission',403);
        $scan=MAD4B_Hub_Dedup_Intelligence_V1::discover(['company_id'=>'tourism-almallahgroup']);
        if(is_wp_error($scan))return $scan;
        $candidate=$scan['specialists']['tourism_nile_v1']['links_for_review']??null;
        if(!is_array($candidate)||count($candidate)<1||count($candidate)>300)
            return self::fail('evidence_unavailable');
        global $wpdb;
        $t=$wpdb->prefix.'mad4b_hub_canonical_entities';
        $items=[];
        foreach($candidate as $row){
            if(($row['decision']??'')!=='link_same_source_identity_preserve_legacy' ||
               ($row['auto_merge']??null)!==false)return self::fail('unsafe_decision');
            $import=(int)($row['imported_hub_post_id']??0);
            $legacy=(int)($row['legacy_hub_post_id']??0);
            if($import<1||$legacy<1||isset($items[$import]))return self::fail('duplicate_link');
            $p=get_post($import);$l=get_post($legacy);
            if(!$p||!$l||$p->post_type!=='mad4b_hub_content'||
                $l->post_type!=='mad4b_hub_nile')return self::fail('post_type');
            $owner=(string)get_post_meta($import,'_mad4b_hub_import_owner',true);
            $type=(string)get_post_meta($import,'_mad4b_hub_import_type',true);
            $language=(string)get_post_meta($import,'_mad4b_hub_import_language',true);
            $sid=(int)get_post_meta($import,'_mad4b_hub_import_source_id',true);
            $key=$owner.':'.$type.':'.$language.':'.$sid;
            if($owner!=='tourism-almallahgroup'||$type!=='tours-and-activities' ||
               $sid<1||$key!==$row['source_key']||
               get_post_meta($legacy,'_mad4b_hub_source_company',true)!==$owner ||
               (int)get_post_meta($legacy,'_mad4b_hub_source_id',true)!==$sid)
                return self::fail('source_identity_mismatch');
            $e=$wpdb->get_row($wpdb->prepare("SELECT id,company_id,source_type,state FROM $t WHERE source_hub_id=%d",$import),ARRAY_A);
            if(!$e||$e['company_id']!==$owner||$e['source_type']!==$type)
                return self::fail('canonical_binding');
            $items[$import]=['canonical_id'=>(int)$e['id'],
               'imported_hub_post_id'=>$import,'legacy_hub_post_id'=>$legacy,
               'source_key'=>$key,'source_identity_verified'=>true,
               'auto_merge'=>false,'source_records_preserved'=>true];
        }
        ksort($items,SORT_NUMERIC);
        $digest=hash('sha256',wp_json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $stored=get_option(self::STORE,[]);
        if(is_array($stored)&&($stored['sha256']??'')===$digest)
            return ['links'=>count($items),'unchanged'=>true,'sha256'=>$digest,'auto_merge'=>false];
        update_option(self::STORE,['contract'=>'mad4b.hub.canonical-nile-alias.v1',
          'source_company'=>'tourism-almallahgroup','items'=>$items,'sha256'=>$digest,
          'updated_utc'=>gmdate('c'),'auto_merge'=>false,'publish'=>false],false);
        $verified=get_option(self::STORE,[]);
        if(($verified['sha256']??'')!==$digest)return self::fail('readback');
        return ['links'=>count($items),'unchanged'=>false,'sha256'=>$digest,
            'auto_merge'=>false,'readback_verified'=>true];
    }
    public static function status() {
        if(!self::admin())return self::fail('permission',403);
        $a=get_option(self::STORE,[]);
        return ['contract'=>'mad4b.hub.canonical-nile-alias.v1',
            'links'=>count($a['items']??[]),'sha256'=>$a['sha256']??null,
            'auto_merge'=>false,'source_records_preserved'=>true,'commercial_admission'=>false];
    }
    public static function rest() {
        if(!MAD4B_Hub_Canonical_Registry_V1::site())return;
        register_rest_route('mad4b-central/v1','/canonical/nile-legacy-links',[
            'methods'=>'GET','permission_callback'=>static function(){return self::admin();},
            'callback'=>static function(){return rest_ensure_response(self::status());}
        ]);
    }
    public static function boot() {add_action('rest_api_init',[__CLASS__,'rest']);}
}
MAD4B_Hub_Legacy_Alias_Bridge_V1::boot();

