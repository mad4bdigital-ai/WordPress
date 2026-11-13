<?php
/**
 * Optional Woo/JetEngine-compatible local draft shadow.
 * Untrusted source metadata NEVER creates published content or a Woo product.
 * Permanent imported drafts require separate signed-source enrollment and
 * approval: currently plan only. QA writes are isolated and self-cleaning.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Shadow {
    const CONTRACT='mad4b.tenant-shadow-draft.v1';
    const QA_META='_mad4b_qa_shadow_fixture_v1';
    private static function blocked($why) {
        return new WP_Error('mad4b_shadow_'.$why,$why);
    }
    public static function plan($envelope) {
        $verified=MAD4B_Tenant_Signed_Publication::verify_live($envelope);
        if(is_wp_error($verified))return $verified;
        if(($verified['publication_state']??'')!=='approved'
          ||!MAD4B_Tenant_Operation_Authority::scope(false))
          return self::blocked('publication_or_environment_not_eligible');
        if(!MAD4B_Tenant_Sparse_Store::exists())return self::blocked('schema_missing');
        $map=MAD4B_Tenant_Sparse_Store::mapping($verified['global_content_id'],$verified['language']);
        if(is_wp_error($map))return $map;
        return [
            'contract'=>self::CONTRACT.'.plan','status'=>'preview_only',
            'global_content_id'=>$verified['global_content_id'],
            'language'=>$verified['language'],'post_type'=>$verified['post_type'],
            'already_mapped'=>is_array($map),
            'proposed_post_status'=>'draft',
            'copy_content_fields'=>['title','excerpt'],
            'media_linking'=>'pending_catalog_resolution',
            'wpml_translation_linking'=>'pending_language_reconciliation',
            'jetengine_taxonomy_linking'=>'pending_term_reconciliation',
            'commerce_product_or_rate_created'=>false,
            'execution_enabled'=>false,'mutation_performed'=>false,
        ];
    }
    public static function qa_create_draft($verified) {
        if(!MAD4B_Tenant_Operation_Authority::qa_draft())
            return self::blocked('qa_signed_sandbox_authority_required');
        $s=MAD4B_SCP_Site_Profile::status();
        if(empty($s['authority_ready'])||empty($s['same_origin_clone_protection'])
          ||empty($s['deployment_binding_match'])||empty($s['origin_match']))
          return self::blocked('site_binding_unverified');
        if(!is_array($verified)||($verified['valid']??false)!==true
          ||($verified['qa_only']??false)!==true
          ||($verified['publication_state']??'')!=='qa_signed'
          ||($verified['target_site_uuid']??'')!==($s['site_uuid']??'')
          ||($verified['post_type']??'')!=='tours-and-activities'
          ||!is_array($verified['source_fields']??null))
          return self::blocked('qa_certificate_not_accepted');
        if(empty(MAD4B_SCP_Audit::storage_status()['ready']))return self::blocked('audit_missing');
        $title=$verified['source_fields']['title']??null;
        if(!is_string($title)||$title===''||strlen($title)>220)
            return self::blocked('unsafe_shadow_title');
        $marker='qa-'.substr($verified['envelope_sha256'],0,24);
        $audit=MAD4B_SCP_Audit::record('mad4b/tenant-shadow-qa-draft-intent',[
            'site_uuid'=>$s['site_uuid'],'fixture'=>$marker,
            'post_type'=>'tours-and-activities','expected_status'=>'draft',
            'commercial_price_created'=>false],'intent');
        if(is_wp_error($audit))return self::blocked('audit_intent_failed');
        $id=wp_insert_post([
            'post_type'=>'tours-and-activities','post_status'=>'draft',
            'post_title'=>'[MAD4B QA SHADOW] '.$title,
            'post_excerpt'=>(string)($verified['source_fields']['excerpt']??''),
            'post_content'=>'','comment_status'=>'closed','ping_status'=>'closed',
        ],true);
        if(is_wp_error($id))return $id;
        update_post_meta($id,self::QA_META,$marker);
        $p=get_post($id);
        if(!$p ||$p->post_status!=='draft'||$p->post_type!=='tours-and-activities'
          ||get_post_meta($id,self::QA_META,true)!==$marker) {
            wp_delete_post($id,true);
            return self::blocked('draft_readback_incomplete');
        }
        MAD4B_SCP_Audit::record('mad4b/tenant-shadow-qa-draft-result',[
            'site_uuid'=>$s['site_uuid'],'post_id'=>$id,
            'fixture'=>$marker,'post_status'=>'draft','woocommerce_product_created'=>false
        ],'ok');
        return [
            'contract'=>self::CONTRACT.'.qa-draft',
            'status'=>'draft_created_for_qa','post_id'=>$id,
            'post_status'=>$p->post_status,'fixture_marker'=>$marker,
            'live_product_created'=>false,'source_reference_approved'=>false,
        ];
    }
}
