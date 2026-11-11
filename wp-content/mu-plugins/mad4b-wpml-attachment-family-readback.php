<?php
/**
 * Plugin Name: MAD4B WPML Attachment Family Readback
 * Description: Deterministic, read-only WPML attachment translation resolver with primary API and verified DB fallback.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_WPML_Attachment_Family_Readback_V1 {
    const CONTRACT='mad4b.wpml-attachment-family-readback.v1';
    public static function translations($source_id):array {
        $aid=absint($source_id);
        if(!$aid||get_post_type($aid)!=='attachment')return [];
        global $wpdb;
        $table=$wpdb->prefix.'icl_translations';
        $trid=$wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM $table WHERE element_id=%d AND element_type='post_attachment' LIMIT 1",$aid));
        if(!$trid)return [];
        $native=apply_filters('wpml_get_element_translations',[],(int)$trid,'post_attachment');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT tr.element_id,tr.language_code,tr.source_language_code
             FROM $table tr
             JOIN {$wpdb->posts} p ON p.ID=tr.element_id
             WHERE tr.trid=%d AND tr.element_type='post_attachment'
               AND p.post_type='attachment'
             ORDER BY tr.language_code,tr.element_id",
            (int)$trid),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error)return [];
        $verified=[];$invalid=false;
        foreach($rows as $row){
            $lang=(string)$row['language_code'];$id=(int)$row['element_id'];
            if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$lang)
                ||!$id||isset($verified[$lang])){$invalid=true;break;}
            $verified[$lang]=(object)[
                'element_id'=>$id,'language_code'=>$lang,
                'source_language_code'=>$row['source_language_code'],
                'trid'=>(int)$trid
            ];
        }
        if($invalid||!$verified)return [];
        // The translated records are the same WPML originals, not
        // synthesized identities. Native API may expose only EN due a stale
        // persistent cache immediately after media translation creation.
        if(is_array($native) && count($native)===count($verified)){
            $same=true;
            foreach($verified as $lang=>$entry)
                if((int)($native[$lang]->element_id??0)!==$entry->element_id){
                    $same=false;break;
                }
            if($same)return $native;
        }
        return $verified;
    }
    public static function audit($aid):array {
        $data=self::translations($aid);
        return ['contract'=>self::CONTRACT,'source_attachment_id'=>$aid,
            'languages'=>array_keys($data),
            'attachment_ids'=>array_map(fn($r)=>(int)$r->element_id,$data),
            'complete'=>!empty($data),
            'read_only'=>true,'translations_created'=>false];
    }
}

