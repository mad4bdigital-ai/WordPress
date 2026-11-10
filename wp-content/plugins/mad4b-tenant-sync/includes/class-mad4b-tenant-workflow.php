<?php
/**
 * Tenant-owned two-person content review workflow. Field proposals are
 * isolated from live content until approved and applied to sparse storage.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Workflow {
    const CONTRACT = 'mad4b.tenant-approval-workflow.v2';
    const SCHEMA_OPTION = 'mad4b_tenant_workflow_schema_v1';
    private static $approved_context = null;
    // Private, per-request first-touch mapping context. Only a verified
    // Workflow Submit request may open it, never the legacy Sparse UI.
    private static $seed_context = null;
    public static function permitted_seed($post_id,$site_uuid) {
        $ctx=self::$seed_context;
        return is_array($ctx)
          && get_current_user_id()>0
          && (int)($ctx['actor']??0)===get_current_user_id()
          && (int)($ctx['post_id']??0)===(int)$post_id
          && (string)($ctx['site_uuid']??'')===(string)$site_uuid
          && current_user_can('edit_post',(int)$post_id)
          && class_exists('MAD4B_SCP_Site_Profile')
          && !empty(MAD4B_SCP_Site_Profile::status()['authority_ready']);
    }
    private static function table(){global $wpdb;return $wpdb->prefix.'mad4b_tenant_approvals';}
    private static function err($code){return new WP_Error('mad4b_workflow_'.$code,$code);}
    private static function profile($write=false,$operation=''){
        if(!class_exists('MAD4B_Tenant_Sync'))return self::err('tenant_not_loaded');
        $p=MAD4B_Tenant_Sync::profile();
        if(empty($p['ready'])||empty($p['site_uuid']))return self::err('site_not_enrolled');
        if($write){
            // Company approval Submit/Review is an ordinary tenant-owned
            // operation, not an environment migration. Never infer authority
            // from staging/production labels, but require verified host scope.
            $binding=class_exists('MAD4B_Tenant_Operation_Authority',false)
                ?MAD4B_Tenant_Operation_Authority::status(true):null;
            if(!is_array($binding)
                ||(string)($binding['site_uuid']??'')!==(string)$p['site_uuid'])
                return self::err('company_host_binding_unverified');
            // Approval is an ordinary audited write; schema has a separate
            // signed DDL authorization checked by install() below.
            if(!MAD4B_Tenant_Operation_Authority::secure_origin())
                return self::err('secure_origin_required');
        }
        return $p;
    }
    /**
     * Read-only, current-request capability/readiness view. Never writes,
     * grants a role, creates an approval or changes the environment policy.
     * Distinguish tenant-authorized reads from audited approval writes.
     */
    public static function scope_status() {
        $p=self::profile(false);
        $read=is_array($p) && !empty($p['ready'])
            && !empty($p['site_uuid']);
        $environment=wp_get_environment_type();
        // Host binding, not the Staging/Production label, controls scope.
        $write_environment=MAD4B_Tenant_Operation_Authority::local_write();
        $approval=self::profile(true,'approval');
        $approval_ready=is_array($approval) && !empty($approval['site_uuid']);
        $audit=class_exists('MAD4B_SCP_Audit')
            ? MAD4B_SCP_Audit::storage_status() : [];
        $audit_ready=is_array($audit) && !empty($audit['ready']);
        $reviewer=self::reviewer();
        $table=self::exists();
        return [
            'contract'=>self::CONTRACT.'.scope-status.v1',
            'site_identity_ready'=>$read,
            'read_scope_ready'=>$read && $table,
            'workflow_table_ready'=>$table,
            'audit_storage_ready'=>$audit_ready,
            'actor_can_review'=>$reviewer,
            'write_environment_eligible'=>$write_environment,
            'schema_provision_environment_eligible'=>MAD4B_Tenant_Operation_Authority::schema(
                'tenant.workflow.schema',[self::table()]),
            'approval_write_scope_ready'=>$approval_ready,
            'request_submit_ready'=>$read && $table && $audit_ready
                && $approval_ready && get_current_user_id()>0,
            'review_action_ready'=>$read && $table && $audit_ready
                && $approval_ready && $reviewer && get_current_user_id()>0,
            'write_blockers'=>array_values(array_filter([
                !$read?'site_identity_not_ready':null,
                !$table?'workflow_table_not_ready':null,
                !$audit_ready?'audit_storage_not_ready':null,
                !$approval_ready?'approval_site_or_audit_scope_unverified':null,
            ])),
            // This status endpoint never itself grants an action.
            // Actual Submit/Review still enforce object-level permission,
            // four-eyes, revision, source hash and native audit.
            'production_write_permission_granted'=>false,
            'status_grants_permission'=>false,
            'mutation_performed'=>false,
            'note'=>'Status is indicative; each original submit/review action still enforces object ownership, editor permission, separate reviewer, revision and source hashes.',
        ];
    }
    public static function reviewer(){
        return current_user_can('mad4b_manage_company_operations') || current_user_can('manage_options');
    }
    public static function permitted_apply($map,$field) {
        $ctx=self::$approved_context;
        return is_array($ctx)&&$ctx['field']===$field
            &&(int)$ctx['post_id']===(int)($map['local_post_id']??0)
            &&($ctx['site_uuid']??'')===($map['site_uuid']??'')
            &&self::reviewer();
    }
    public static function exists(){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',$wpdb->esc_like(self::table())))===self::table();
    }
    public static function install(){
        $p=self::profile(true,'schema');
        if(is_wp_error($p))return $p;
        if(!MAD4B_Tenant_Operation_Authority::schema('tenant.workflow.schema',[self::table()]))
            return self::err('signed_schema_migration_authority_required');
        if(!function_exists('dbDelta'))require_once ABSPATH.'wp-admin/includes/upgrade.php';
        global $wpdb;
        $table=self::table();$collate=$wpdb->get_charset_collate();
        $sql="CREATE TABLE $table (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
site_uuid char(36) NOT NULL,
post_id bigint(20) unsigned NOT NULL,
field_path varchar(96) NOT NULL,
value_json longtext NOT NULL,
value_sha256 char(64) NOT NULL,
expected_revision bigint(20) unsigned NOT NULL DEFAULT 0,
source_sha256 char(64) NOT NULL,
request_state varchar(24) NOT NULL,
pending_slot char(64) DEFAULT NULL,
submitted_by bigint(20) unsigned NOT NULL,
reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
reviewed_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY pending_slot (pending_slot),
KEY site_state (site_uuid,request_state),
KEY site_post (site_uuid,post_id)
) $collate;";
        if(!self::exists()||(int)get_option(self::SCHEMA_OPTION)!==2){
            $a=MAD4B_SCP_Audit::record('mad4b/tenant-workflow-schema-intent',
                ['site_uuid'=>$p['site_uuid'],'table'=>$table,'schema'=>2],'intent');
            if(is_wp_error($a))return self::err('audit_intent_failed');
        }
        dbDelta($sql);
        if(!self::exists())return self::err('schema_verify_failed');
        update_option(self::SCHEMA_OPTION,2,false);
        $a=MAD4B_SCP_Audit::record('mad4b/tenant-workflow-schema-result',
            ['site_uuid'=>$p['site_uuid'],'table'=>$table,'schema'=>2],'ok');
        if(is_wp_error($a))return self::err('audit_result_failed');
        return ['contract'=>self::CONTRACT,'status'=>'schema_ready','table'=>$table];
    }
    private static function map($post_id,$actor=false){
        $ctx=MAD4B_Tenant_Override_Policy::context($post_id);
        if(!$ctx||($actor&&!current_user_can('edit_post',$post_id)))
            return self::err('post_not_allowed');
        $p=self::profile($actor,$actor?'approval':'');
        if(is_wp_error($p)||$p['site_uuid']!==$ctx['site_uuid'])
            return self::err('company_site_mismatch');
        $global='local-'.substr(hash('sha256',$ctx['site_uuid'].'|'
            .$ctx['post']->post_type.'|'.$post_id),0,32);
        $map=MAD4B_Tenant_Sparse_Store::mapping($global,$ctx['language']);
        if(is_wp_error($map))return $map;
        if(!$map && $actor){
            self::$seed_context=[
                'post_id'=>(int)$post_id,
                'site_uuid'=>$p['site_uuid'],
                'actor'=>get_current_user_id()
            ];
            try{
                $map=MAD4B_Tenant_Sparse_Store::ensure_local_seed($post_id);
            }finally{
                self::$seed_context=null;
            }
        }
        if(!is_array($map)||($map['state']??'')!=='local_seed'
            ||(int)$map['local_post_id']!==(int)$post_id)return self::err('local_mapping_not_ready');
        return $map;
    }
    private static function serial($map,$field,$value){
        $defs=MAD4B_Tenant_Field_Registry::definitions($map['post_type']);
        if(!isset($defs[$field])||!in_array($field,[
            'title','excerpt','seo_title','seo_description','seo_focus_keyword',
            'featured_media_global_id','offer_banners','gallery_media',
            'presentation_variant'
        ],true))return self::err('field_not_workflow_editable');
        if(!MAD4B_Tenant_Field_Registry::valid_value($field,$value,
           $map['site_uuid'],'tenant',$defs))return self::err('field_value_invalid');
        // Real tenant-owned image IDs are checked before request acceptance.
        if(in_array($defs[$field]['type'],['media_ref','media_collection'],true)){
            $imgs=$defs[$field]['type']==='media_ref'?[$value]:$value;
            foreach((array)$imgs as $img){
                if(!is_array($img)||($img['scope']??'')!=='tenant'
                  ||($img['site_uuid']??'')!==$map['site_uuid']
                  ||get_post_type($img['attachment_id']??0)!=='attachment'
                  ||!str_starts_with((string)get_post_mime_type($img['attachment_id']),'image/'))
                    return self::err('tenant_media_invalid');
            }
        }
        $json=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||strlen($json)>40000)return self::err('payload_invalid');
        return $json;
    }
    private static function base_field_hash($map,$field){
        $value=MAD4B_Tenant_Sparse_Store::effective($map['global_content_id'],$map['language']);
        if(is_wp_error($value)||!is_array($value)||($value['status']??'')!=='preview_only')
            return self::err('source_snapshot_unavailable');
        $current=$value['fields'][$field]??null;
        $json=wp_json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))return self::err('source_digest_unavailable');
        return hash('sha256',$json);
    }
    private static function expected_revision($map,$field){
        $rows=MAD4B_Tenant_Sparse_Store::override_rows($map);
        if(is_wp_error($rows))return $rows;
        return (int)($rows[$field]['revision']??0);
    }
    public static function submit($post_id,$field,$value,$expected){
        global $wpdb;
        $p=self::profile(true,'approval');
        if(is_wp_error($p)||!self::exists())return self::err('workflow_unavailable');
        if(get_current_user_id()<1 || !current_user_can('edit_post',$post_id))
            return self::err('editor_permission_required');
        $map=self::map($post_id,true);
        if(is_wp_error($map))return $map;
        $json=self::serial($map,$field,$value);
        if(is_wp_error($json))return $json;
        $revision=self::expected_revision($map,$field);
        if(is_wp_error($revision)||!is_int($expected)||$expected!==$revision)
            return self::err('stale_local_value');
        $baseline=self::base_field_hash($map,$field);
        if(is_wp_error($baseline))return $baseline;
        $slot=hash('sha256',$p['site_uuid'].'|'.$post_id.'|'.$field);
        $table=self::table();$hash=hash('sha256',$json);
        $intent=MAD4B_SCP_Audit::record('mad4b/company-content-request-intent',[
            'site_uuid'=>$p['site_uuid'],'post_id'=>$post_id,'field'=>$field,
            'actor'=>get_current_user_id(),'expected_revision'=>$revision,
            'value_hash'=>$hash,'source_sha256'=>$baseline],'intent');
        if(is_wp_error($intent))return self::err('audit_intent_failed');
        $ok=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (site_uuid,post_id,field_path,value_json,value_sha256,
             expected_revision,source_sha256,request_state,pending_slot,submitted_by,created_at)
             VALUES (%s,%d,%s,%s,%s,%d,%s,'submitted',%s,%d,%s)",
            $p['site_uuid'],$post_id,$field,$json,$hash,$revision,$baseline,
            $slot,get_current_user_id(),current_time('mysql',true)));
        if($ok!==1)return self::err('pending_request_exists_or_write_failed');
        $id=(int)$wpdb->insert_id;$row=self::get($id);
        if(!$row||$row['value_sha256']!==$hash)return self::err('request_readback_incomplete');
        $a=MAD4B_SCP_Audit::record('mad4b/company-content-request-result',[
            'site_uuid'=>$p['site_uuid'],'request_id'=>$id,'state'=>'submitted',
            'field'=>$field,'actor'=>get_current_user_id(),'content_modified'=>false],'ok');
        if(is_wp_error($a))return self::err('request_audit_reconcile_required');
        return ['status'=>'submitted','request_id'=>$id,'post_id'=>$post_id,
            'field'=>$field,'live_content_modified'=>false];
    }
    public static function get($id){
        global $wpdb;
        $p=self::profile();
        if(is_wp_error($p)||!self::exists())return null;
        $row=$wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.self::table().' WHERE id=%d AND site_uuid=%s LIMIT 1',
            $id,$p['site_uuid']),ARRAY_A);
        if(!$row||!hash_equals($row['value_sha256'],hash('sha256',$row['value_json'])))
            return null;
        return $row;
    }
    public static function queue($limit=40){
        global $wpdb;
        $p=self::profile();
        if(is_wp_error($p)||!self::exists())return [];
        if(!self::reviewer())return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,post_id,field_path,request_state,submitted_by,created_at FROM '
            .self::table().' WHERE site_uuid=%s AND request_state IN (%s,%s) ORDER BY id DESC LIMIT %d',
            $p['site_uuid'],'submitted','requires_review',min(100,max(1,(int)$limit))),ARRAY_A);
    }
    public static function my_requests($limit=15){
        global $wpdb;
        $p=self::profile();
        $actor=get_current_user_id();
        if(is_wp_error($p)||!self::exists()||$actor<1)return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,post_id,field_path,request_state,created_at,reviewed_at FROM '
             .self::table().' WHERE site_uuid=%s AND submitted_by=%d ORDER BY id DESC LIMIT %d',
            $p['site_uuid'],$actor,min(50,max(1,(int)$limit))),ARRAY_A);
    }
    public static function review($id,$decision){
        global $wpdb;
        $p=self::profile(true,'approval');
        if(is_wp_error($p)||!self::exists())return self::err('workflow_unavailable');
        if(!self::reviewer()||get_current_user_id()<1)return self::err('reviewer_permission_required');
        if(!in_array($decision,['approve','reject'],true))return self::err('decision_invalid');
        $request=self::get($id);
        if(!$request||$request['request_state']!=='submitted')
            return self::err('request_not_pending');
        if((int)$request['submitted_by']===get_current_user_id())
            return self::err('four_eyes_required');
        $map=self::map((int)$request['post_id']);
        if(is_wp_error($map)||!current_user_can('edit_post',(int)$request['post_id']))
            return self::err('reviewer_lacks_post_permission');
        $revision=self::expected_revision($map,$request['field_path']);
        if(is_wp_error($revision)||$revision!==(int)$request['expected_revision'])
            return self::err('content_revision_conflict');
        if($decision==='approve'){
            $baseline=self::base_field_hash($map,$request['field_path']);
            if(is_wp_error($baseline)||!hash_equals((string)$request['source_sha256'],(string)$baseline))
                return self::err('source_changed_since_request');
        }
        $json=$request['value_json'];$value=json_decode($json,true);
        if(json_last_error()!==JSON_ERROR_NONE||is_wp_error(self::serial($map,$request['field_path'],$value)))
            return self::err('proposal_invalid_at_review');
        $audit=MAD4B_SCP_Audit::record('mad4b/company-content-review-intent',[
            'site_uuid'=>$p['site_uuid'],'request_id'=>$id,'decision'=>$decision,
            'reviewer'=>get_current_user_id(),'payload_sha256'=>$request['value_sha256']],'intent');
        if(is_wp_error($audit))return self::err('audit_intent_failed');
        $table=self::table();
        $state=$decision==='approve'?'applying':'rejected';
        $affected=$wpdb->query($wpdb->prepare(
            "UPDATE $table SET request_state=%s,reviewed_by=%d,reviewed_at=%s,pending_slot=NULL
             WHERE id=%d AND site_uuid=%s AND request_state='submitted' AND value_sha256=%s",
            $state,get_current_user_id(),current_time('mysql',true),$id,
            $p['site_uuid'],$request['value_sha256']));
        if($affected!==1)return self::err('concurrent_review');
        if($decision==='reject'){
            $result=['status'=>'rejected','request_id'=>$id,'live_content_modified'=>false];
        }else{
            self::$approved_context=['request_id'=>$id,
                'site_uuid'=>$p['site_uuid'],'post_id'=>(int)$request['post_id'],
                'field'=>$request['field_path']];
            try{
                $applied=MAD4B_Tenant_Sparse_Store::set_override(
                    $map,$request['field_path'],$value,$revision);
            } finally {self::$approved_context=null;}
            if(is_wp_error($applied)){
                $wpdb->update($table,['request_state'=>'requires_review'],['id'=>$id,'site_uuid'=>$p['site_uuid']]);
                return self::err('apply_requires_reconciliation');
            }
            $appliedRows=MAD4B_Tenant_Sparse_Store::override_rows($map);
            if(is_wp_error($appliedRows)||!isset($appliedRows[$request['field_path']])
                ||$appliedRows[$request['field_path']]['value']!==$value)
                return self::err('apply_readback_incomplete');
            $wpdb->update($table,['request_state'=>'approved'],['id'=>$id,'site_uuid'=>$p['site_uuid'],
                'request_state'=>'applying']);
            $result=['status'=>'approved','request_id'=>$id,'sparse_row_applied'=>true,
                'native_wp_post_modified'=>false];
        }
        $after=self::get($id);
        if(!$after||$after['request_state']!==$result['status'])
            return self::err('review_readback_failed');
        $audit=MAD4B_SCP_Audit::record('mad4b/company-content-review-result',[
            'site_uuid'=>$p['site_uuid'],'request_id'=>$id,
            'review_state'=>$result['status'],'reviewer'=>get_current_user_id(),
            'native_content_modified'=>false],'ok');
        if(is_wp_error($audit))return self::err('review_audit_reconcile_required');
        return $result;
    }
}
