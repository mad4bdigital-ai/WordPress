<?php
if(!defined('ABSPATH'))exit;
/**
 * Recoverable source lifecycle. Archive stores the exact Site/Brand-scoped
 * inventory before native audited removal; restore re-enrolls the source only.
 * Historic approvals are never revived without a fresh provider scan/review.
 */
final class MAD4B_SCP_Source_Lifecycle {
    const OPTION='mad4b_scp_context_source_archive_v2';
    const MAX_ARCHIVES=25;
    public static function boot(){
        add_action('admin_post_mad4b_context_archive_source',array(__CLASS__,'handle_archive'));
        add_action('admin_post_mad4b_context_restore_source',array(__CLASS__,'handle_restore'));
    }
    private static function allowed(){
        return current_user_can('manage_options') && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_connect_user(get_current_user_id())
            && class_exists('MAD4B_SCP_Context_Authority');
    }
    private static function raw(){
        $stored=get_option(self::OPTION,array());
        return is_array($stored)?$stored:array();
    }
    private static function save($state){
        if(!is_array($state)||count($state)>self::MAX_ARCHIVES)return false;
        update_option(self::OPTION,$state,false);
        return serialize(self::raw())===serialize($state);
    }
    public static function archives(){
        if(!self::allowed())return array();
        $profile=MAD4B_SCP_Context_Authority::profile();
        $list=array();
        foreach(self::raw() as $id=>$row) {
            if(!is_array($profile) || !hash_equals((string)($profile['site_uuid']??''),(string)($row['site_uuid']??''))
               || !hash_equals((string)($profile['brand_id']??''),(string)($row['brand_id']??'')))continue;
            if(!is_array($row)||!preg_match('/^[a-f0-9-]{36}$/D',(string)$id))continue;
            $list[$id]=array(
                'archive_id'=>$id,'source_id'=>(string)($row['source_id']??''),
                'label'=>(string)($row['source']['label']??''),
                'state'=>(string)($row['state']??''),
                'asset_count'=>count($row['assets']??array()),
                'archived_at'=>(string)($row['archived_at']??''),
            );
        }
        return $list;
    }
    public static function archive_plan($source_id){
        if(!self::allowed())return new WP_Error('mad4b_source_archive_denied','An authorized source administrator is required.');
        $source=MAD4B_SCP_Context_Authority::source((string)$source_id);
        if(!$source)return new WP_Error('mad4b_source_archive_not_found','Source not in the active site.');
        $assets=array();
        foreach(MAD4B_SCP_Context_Authority::assets() as $id=>$asset){
            if((string)($asset['source_id']??'')!==(string)$source['source_id'])continue;
            foreach(array('site_uuid','brand_id') as $key){
                if(!hash_equals((string)($source[$key]??''),(string)($asset[$key]??'')))
                    return new WP_Error('mad4b_source_archive_owner_mismatch','Foreign or unverified indexed asset found; refuse archive.');
            }
            $assets[$id]=$asset;
        }
        $identity=array('site_uuid'=>(string)($source['site_uuid']??''),
            'brand_id'=>(string)($source['brand_id']??''),
            'source_id'=>(string)$source['source_id'],'asset_count'=>count($assets));
        if(empty($identity['site_uuid'])||empty($identity['brand_id']))
            return new WP_Error('mad4b_source_archive_scope_unbound','Site/Brand identity required.');
        return array('source'=>$source,'assets'=>$assets,
            'digest'=>hash('sha256',wp_json_encode(array($source,$assets))),
            'registry_revision'=>MAD4B_SCP_Context_Authority::registry_revision(),
            'identity'=>$identity,'external_files_mutated'=>false);
    }
    public static function archive_source($source_id,$expected_digest){
        $plan=self::archive_plan($source_id);
        if(is_wp_error($plan))return $plan;
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected_digest)
           || !hash_equals((string)$plan['digest'],(string)$expected_digest))
            return new WP_Error('mad4b_source_archive_plan_stale','Source inventory changed; review a fresh archive plan.');
        $all=self::raw();
        if(count($all)>=self::MAX_ARCHIVES)
            return new WP_Error('mad4b_source_archive_capacity','Archive retention is full; review stored source histories.');
        $archive_id=wp_generate_uuid4();
        $all[$archive_id]=array(
            'contract'=>'mad4b.context-source-archive.v2','state'=>'prepared',
            'source_id'=>(string)$plan['source']['source_id'],
            'source'=>$plan['source'],'assets'=>$plan['assets'],
            'snapshot_sha256'=>$plan['digest'],
            'site_uuid'=>$plan['identity']['site_uuid'],'brand_id'=>$plan['identity']['brand_id'],
            'archived_at'=>gmdate('c'),'archived_by'=>get_current_user_id()
        );
        if(!self::save($all))return new WP_Error('mad4b_source_archive_snapshot_failed','Archive snapshot failed; original source remains active.');
        // Native removal preserves its existing Site/Brand checks, locking,
        // foreign-asset quarantine, audit and rollback. Never bypass it.
        $removed=MAD4B_SCP_Context_Authority::remove_source($source_id);
        if(is_wp_error($removed)){
            $all=self::raw();
            $all[$archive_id]['state']='remove_failed';
            $all[$archive_id]['last_error']=$removed->get_error_code();
            self::save($all);
            return $removed;
        }
        $all=self::raw();
        $all[$archive_id]['state']='detached';
        $all[$archive_id]['detached_at']=gmdate('c');
        self::save($all);
        return array('archive_id'=>$archive_id,'source_id'=>$source_id,'state'=>'detached',
            'archived_asset_count'=>count($plan['assets']),
            'external_files_deleted'=>false,'restorable_source_only'=>true,
            'former_approvals_restored'=>false);
    }
    public static function restore_source($archive_id){
        if(!self::allowed())return new WP_Error('mad4b_source_restore_denied','Authorized administrator required.');
        $all=self::raw();
        $entry=$all[$archive_id]??null;
        if(!is_array($entry)||($entry['state']??'')!=='detached')
            return new WP_Error('mad4b_source_archive_not_detached','Exact detached archive required.');
        $source=$entry['source']??array();
        $profile=MAD4B_SCP_Context_Authority::profile();
        if(!is_array($profile)||
            !hash_equals((string)($entry['brand_id']??''),(string)($profile['brand_id']??'')) ||
            !hash_equals((string)($entry['site_uuid']??''),(string)($profile['site_uuid']??'')))
            return new WP_Error('mad4b_source_restore_scope_mismatch','Archive Site/Brand identity changed.');
        if(MAD4B_SCP_Context_Authority::source((string)$entry['source_id']))
            return new WP_Error('mad4b_source_restore_collision','Source already exists; rescan that source instead.');
        $restored=MAD4B_SCP_Context_Authority::upsert_source(array(
            'provider'=>(string)$source['provider'],'mode'=>(string)$source['mode'],
            'external_root_id'=>(string)$source['external_root_id'],
            'label'=>(string)$source['label'],'task_scope'=>(string)($source['task_scope']??''),
            'recursive'=>!empty($source['recursive']),
            // Re-enroll read-only; never re-issue old Drive write authority.
            'write_policy'=>'read_only'
        ));
        if(is_wp_error($restored))return $restored;
        if(!hash_equals((string)$entry['source_id'],(string)$restored['source_id']))
            return new WP_Error('mad4b_source_restore_identity_mismatch','Source re-enrolled under unexpected identity; inspect before scanning.');
        $all=self::raw();$all[$archive_id]['state']='restored_rescan_required';
        $all[$archive_id]['restored_at']=gmdate('c');
        $all[$archive_id]['restored_by']=get_current_user_id();
        self::save($all);
        return array('archive_id'=>$archive_id,'source_id'=>$entry['source_id'],
            'state'=>'restored_rescan_required','old_assets_reapproved'=>false,
            'next_action'=>'rescan_provider_then_review_attachments');
    }
    public static function handle_archive(){
        if(!self::allowed()||!check_admin_referer('mad4b_context_archive_source'))
            wp_die('Not authorized','',array('response'=>403));
        if(empty($_POST['confirm_archive']))wp_die('Confirm source archival','',array('response'=>400));
        $id=isset($_POST['source_id'])?sanitize_text_field(wp_unslash($_POST['source_id'])):'';
        $digest=isset($_POST['plan_digest'])?sanitize_text_field(wp_unslash($_POST['plan_digest'])):'';
        $result=self::archive_source($id,$digest);
        $code=is_wp_error($result)?$result->get_error_code():'source_archived';
        wp_safe_redirect(add_query_arg(array('page'=>'mad4b-control-plane-context','tab'=>'sources',
            'mad4b_context_result'=>sanitize_key($code)),admin_url('admin.php')));exit;
    }
    public static function handle_restore(){
        if(!self::allowed()||!check_admin_referer('mad4b_context_restore_source'))
            wp_die('Not authorized','',array('response'=>403));
        if(empty($_POST['confirm_restore']))wp_die('Confirm source restore','',array('response'=>400));
        $id=isset($_POST['archive_id'])?sanitize_text_field(wp_unslash($_POST['archive_id'])):'';
        $result=self::restore_source($id);
        $code=is_wp_error($result)?$result->get_error_code():'source_restored_rescan_required';
        wp_safe_redirect(add_query_arg(array('page'=>'mad4b-control-plane-context','tab'=>'sources',
            'mad4b_context_result'=>sanitize_key($code)),admin_url('admin.php')));exit;
    }
}
MAD4B_SCP_Source_Lifecycle::boot();
