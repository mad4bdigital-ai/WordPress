<?php
if (!defined('ABSPATH')) exit;
/** A narrowly scoped AI text repair adapter; never authorizes publication. */
final class MAD4B_SCP_AI_Source_Repair {
    const CONTRACT='mad4b.ai-source-repair.v1';
    public static function validate_revision($old,$next,$mime) {
        if(!is_string($old)||!is_string($next)||!in_array($mime,array('text/plain','text/markdown'),true))
            return new WP_Error('mad4b_ai_repair_type_denied','Only registered text/Markdown source repairs supported.');
        if(strlen($next)<max(250,(int)(strlen($old)*0.60)) || strlen($next)>min(180000,strlen($old)*2))
            return new WP_Error('mad4b_ai_repair_bounds','Model revision must preserve sufficient original evidence.');
        if(hash_equals(hash('sha256',$old),hash('sha256',$next)))
            return new WP_Error('mad4b_ai_repair_unchanged','A repair requires an actual content change.');
        $first=trim((string)(preg_split('/\R/u',str_replace("\xEF\xBB\xBF",'',$old),2)[0]??''));
        $first_new=trim((string)(preg_split('/\R/u',str_replace("\xEF\xBB\xBF",'',$next),2)[0]??''));
        if($first==='' || $first!==$first_new)
            return new WP_Error('mad4b_ai_repair_identity_drift','Document identity header must not change.');
        if(stripos($next,'DRAFT FOR OWNER REVIEW')!==false)
            return new WP_Error('mad4b_ai_repair_manual_review_regression','Revision reintroduced an obsolete manual review gate.');
        if(strpos($next,"\x00")!==false || !preg_match('//u',$next))
            return new WP_Error('mad4b_ai_repair_text_invalid','Text encoding invalid.');
        preg_match_all('~https?://[^\s\)\]>]+~i',$old,$before);
        preg_match_all('~https?://[^\s\)\]>]+~i',$next,$after);
        $new_urls=array_diff(array_unique($after[0]),array_unique($before[0]));
        if($new_urls)return new WP_Error('mad4b_ai_repair_new_external_urls','A model may not invent external provenance URLs.');
        return true;
    }
    public static function apply_registered_text_revision($asset_id,$expected_hash,$text) {
        if(wp_get_environment_type()!=='local' || !MAD4B_SCP_Local_Client::bound())
            return new WP_Error('mad4b_ai_repair_scope_denied','Local Lab only.');
        $cfg=MAD4B_SCP_AI_Model_Router::settings();
        if(empty($cfg['enabled']) || empty($cfg['allow_source_repair']))
            return new WP_Error('mad4b_ai_repair_disabled','Source repair has not been enabled.');
        $trusted_admin=current_user_can('manage_options') &&
            MAD4B_SCP_Policy::can_connect_user(get_current_user_id());
        $trusted_host=PHP_SAPI==='cli' && getenv('MAD4B_AI_HOST_RUNNER')==='1' &&
            defined('MAD4B_LOCAL_CLIENT_PROVISION_ENABLED') && MAD4B_LOCAL_CLIENT_PROVISION_ENABLED;
        if(!$trusted_admin && !$trusted_host)
            return new WP_Error('mad4b_ai_repair_operator_denied','Certified site operator context required.');
        $asset=MAD4B_SCP_Context_Authority::asset((string)$asset_id);
        if(!is_array($asset) || ($asset['source_mode']??'')!=='governed' ||
            ($asset['review_status']??'')!=='needs_changes')
            return new WP_Error('mad4b_ai_repair_review_required','Exact needs_changes review receipt required.');
        $source=MAD4B_SCP_Context_Authority::source((string)($asset['source_id']??''));
        if(!is_array($source) || ($source['provider']??'')!=='wordpress_media')
            return new WP_Error('mad4b_ai_repair_source_denied','Registered Media only.');
        if(!hash_equals((string)$asset['content_hash'],(string)$expected_hash) ||
            !hash_equals((string)$asset['reviewed_content_hash'],(string)$expected_hash))
            return new WP_Error('mad4b_ai_repair_drift','Review must bind exact current content.');
        $id=(int)($asset['file_id']??0);
        if($id<=0 || get_post_type($id)!=='attachment')
            return new WP_Error('mad4b_ai_repair_attachment_denied','Expected attachment unavailable.');
        $mime=(string)get_post_mime_type($id);
        $path=get_attached_file($id);
        $real=is_string($path)?realpath($path):false;
        $uploads=wp_get_upload_dir();$base=is_array($uploads)?realpath((string)($uploads['basedir']??'')):false;
        if(!$real || !$base || strpos($real,$base.DIRECTORY_SEPARATOR)!==0 ||
            !is_file($real) || !is_readable($real) || !is_writable($real) || is_link($path) ||
            filesize($real)>262144)
            return new WP_Error('mad4b_ai_repair_path_denied','Certified local writable upload required.');
        $old=file_get_contents($real);
        if(!is_string($old) || !hash_equals(hash('sha256',$old),(string)$expected_hash))
            return new WP_Error('mad4b_ai_repair_disk_drift','Source bytes have changed.');
        $verified=self::validate_revision($old,$text,$mime);
        if(is_wp_error($verified))return $verified;
        $normal=MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,$text);
        if(is_wp_error($normal) || empty($normal['content_complete']))
            return new WP_Error('mad4b_ai_repair_normalization_failed','Revised source normalization failed.');
        $stat=stat($real);
        $tmp=tempnam(dirname($real),'.mad4b-ai-repair-');
        if(!$tmp || file_put_contents($tmp,$text,LOCK_EX)!==strlen($text)) {
            if($tmp)@unlink($tmp);
            return new WP_Error('mad4b_ai_repair_atomic_write_failed','Unable to prepare revised source.');
        }
        @chown($tmp,(int)$stat['uid']);@chgrp($tmp,(int)$stat['gid']);@chmod($tmp,(int)$stat['mode']&0777);
        if(!rename($tmp,$real)) {@unlink($tmp);return new WP_Error('mad4b_ai_repair_atomic_swap_failed','Unable to replace source.');}
        // Existing scan must perform the only Context Registry mutation.
        $actor=get_current_user_id();
        if(!$trusted_admin) {
            $policy=MAD4B_SCP_Context_Authority::review_policy();
            $owner=(int)($policy['updated_by_wp_user_id']??0);
            if(!$owner || !user_can($owner,'manage_options') ||
                !MAD4B_SCP_Site_Profile::user_is_enrolled($owner)) {
                file_put_contents($real,$old,LOCK_EX);
                return new WP_Error('mad4b_ai_repair_owner_unavailable','Governed Source owner is required.');
            }
            wp_set_current_user($owner);
        }
        $scan=MAD4B_SCP_Context_Source_Pipeline::scan_complete((string)$asset['source_id'],
            array('mime_types'=>array('application/pdf','text/plain','text/markdown')));
        if(!$trusted_admin)wp_set_current_user($actor);
        if(is_wp_error($scan)) {
            file_put_contents($real,$old,LOCK_EX);
            return new WP_Error('mad4b_ai_repair_rescan_failed','Original bytes restored; source rescan failed.',
                array('original_error_code'=>$scan->get_error_code()));
        }
        return array('contract'=>self::CONTRACT,'applied'=>true,'rescan_performed'=>true,
            'asset_id'=>$asset_id,'previous_content_hash'=>$expected_hash,
            'new_content_hash'=>hash('sha256',$text),'registry_revision'=>MAD4B_SCP_Context_Authority::registry_revision(),
            'approved'=>false,'publication_authorized'=>false);
    }
}
