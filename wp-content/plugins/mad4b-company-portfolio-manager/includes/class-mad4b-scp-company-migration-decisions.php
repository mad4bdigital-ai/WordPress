<?php
/** REV134: two-person, tenant-scoped and reversible migration decisions. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Migration_Decisions {
    const CONTRACT='mad4b.company-migration-decisions.v1';
    const SCHEMA='mad4b_company_migration_decisions_schema_v1';
    const CONFIRM='APPROVE COMPANY MIGRATION DECISION';
    const APPLY='APPLY REVIEWED POLICY ONLY';
    const REVERT='REVERT APPLIED COMPANY POLICY';
    const MENU='mad4b-company-migration-decisions';

    public static function table(): string {
        global $wpdb; return $wpdb->prefix.'mad4b_company_migration_decisions';
    }
    public static function install(): bool {
        if(wp_get_environment_type()!=='staging')return false;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        global $wpdb;
        $t=self::table(); $collate=$wpdb->get_charset_collate();
        $sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_uuid varchar(64) NOT NULL,
            asset_key varchar(64) NOT NULL,
            decision varchar(48) NOT NULL,
            reason text NOT NULL,
            source_sha char(64) NOT NULL,
            target_sha char(64) NOT NULL,
            expected_applied_id bigint(20) unsigned NOT NULL DEFAULT 0,
            prior_applied_id bigint(20) unsigned NOT NULL DEFAULT 0,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            proposed_by bigint(20) unsigned NOT NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_utc datetime NOT NULL,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY site_asset_state (site_uuid,asset_key,state),
            KEY site_asset_id (site_uuid,asset_key,id)
        ) $collate;";
        dbDelta($sql);
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
        if($exists)update_option(self::SCHEMA,'1',false);
        return $exists;
    }
    public static function ready(): bool {
        global $wpdb; $t=self::table();
        return get_option(self::SCHEMA)==='1' &&
          $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
    }
    private static function identity(): string {
        $s=MAD4B_SCP_Site_Profile::status();
        if(wp_get_environment_type()!=='staging' || !defined('WP_ENVIRONMENT_TYPE')
            || WP_ENVIRONMENT_TYPE!=='staging' || empty($s['authority_ready'])
            || empty($s['origin_match']) || empty($s['deployment_binding_match'])
            || empty($s['same_origin_clone_protection']))return '';
        return (string)($s['site_uuid']??'');
    }
    public static function can_propose() {
        return (current_user_can('mad4b_manage_company_operations') ||
            (current_user_can('manage_options') && current_user_can('activate_plugins')))
            && get_current_user_id()>0;
    }
    private static function owner() {
        return class_exists('MAD4B_SCP_Company_Owner_Activation') &&
           MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized();
    }
    private static function err($code,$message,$status=409) {
        return new WP_Error($code,$message,['status'=>$status]);
    }
    public static function current($asset,$site='') {
        global $wpdb;
        $site=$site?:self::identity();
        if($site==='' || !MAD4B_SCP_Migration_Decision_Catalog::asset_supported($asset))
            return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().
            " WHERE site_uuid=%s AND asset_key=%s AND state='applied' ORDER BY id DESC LIMIT 1",
            $site,$asset),ARRAY_A) ?: null;
    }
    public static function row($id) {
        global $wpdb;
        $site=self::identity();
        if(!$site || !self::ready() || $id<1)return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().
            ' WHERE id=%d AND site_uuid=%s',(int)$id,$site),ARRAY_A) ?: null;
    }
    private static function lock($site,$asset): bool {
        global $wpdb;
        $name='mad4b:decision:'.substr(hash('sha256',$site.'|'.$asset),0,33);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,4)',$name))===1;
    }
    private static function unlock($site,$asset): void {
        global $wpdb;
        $name='mad4b:decision:'.substr(hash('sha256',$site.'|'.$asset),0,33);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }
    private static function read_proposal($id,$state) {
        $r=self::row($id);
        return $r && $r['state']===$state?$r:null;
    }
    private static function matches_source($r): bool {
        $comp=MAD4B_SCP_Migration_Decision_Catalog::comparison($r['asset_key']);
        return $comp && hash_equals($r['source_sha'],$comp['existing_sha256'])
            && hash_equals($r['target_sha'],$comp['target_sha256']);
    }
    private static function audit($event,array $data) {
        return MAD4B_SCP_Audit::record('mad4b/company-migration-decisions-'.$event,
            $data,'ok');
    }
    private static function atomic(callable $callback) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $result=$callback();
            if(is_wp_error($result)){ $wpdb->query('ROLLBACK'); return $result; }
            if($wpdb->query('COMMIT')===false)
                return self::err('mad4b_decision_commit_uncertain','Audit reconciliation required',500);
            return $result;
        } catch(Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::err('mad4b_decision_transaction_failed','Change rolled back',500);
        }
    }
    public static function snapshot($requested_page_id=0): array {
        $site=self::identity(); $catalog=[];
        if($site!=='' && self::ready()) {
            $keys=array_keys(MAD4B_SCP_Migration_Decision_Catalog::definitions());
            foreach(MAD4B_SCP_Migration_Decision_Catalog::special_pages() as $pageId)
                $keys[]=MAD4B_SCP_Migration_Decision_Catalog::page_key($pageId);
            $requested_page_id=absint($requested_page_id);
            if($requested_page_id && MAD4B_SCP_Migration_Decision_Catalog::valid_page($requested_page_id))
                $keys[]=MAD4B_SCP_Migration_Decision_Catalog::page_key($requested_page_id);
            foreach(array_unique($keys) as $key){
                $comparison=MAD4B_SCP_Migration_Decision_Catalog::comparison($key);
                $policy=self::current($key,$site);
                $comparison['applied_policy']=$policy?[
                    'id'=>(int)$policy['id'],'decision'=>$policy['decision'],
                    'reason'=>$policy['reason'],'source_sha'=>$policy['source_sha']
                ]:null;
                $catalog[]=$comparison;
            }
        }
        return ['contract'=>self::CONTRACT,'site_uuid'=>$site,
            'read_only'=>true,'schema_ready'=>self::ready(),
            'catalog'=>$catalog,'external_provider_mutation_performed'=>false,
            'license_data_exposed'=>false,'raw_snippets_exposed'=>false];
    }
    public static function history($limit=80): array {
        global $wpdb;
        $site=self::identity();
        if(!$site || !self::ready())return [];
        return $wpdb->get_results($wpdb->prepare('SELECT id,site_uuid,asset_key,decision,
            reason,source_sha,target_sha,expected_applied_id,prior_applied_id,
            state,proposed_by,reviewed_by,applied_by,created_utc,updated_utc
            FROM '.self::table().' WHERE site_uuid=%s ORDER BY id DESC LIMIT %d',
            $site,min(100,max(1,(int)$limit))),ARRAY_A);
    }
    public static function propose($asset,$decision,$reason,$source_sha,$target_sha,$expected_id) {
        if(!self::can_propose())return self::err('mad4b_decision_proposer_denied','Company operations permission needed',403);
        $site=self::identity();
        if(!$site || !self::ready())return self::err('mad4b_decision_site_unready','Staging company identity/schema not ready');
        if(!MAD4B_SCP_Migration_Decision_Catalog::asset_supported($asset)
         || !isset(MAD4B_SCP_Migration_Decision_Catalog::choices()[$decision]))
            return self::err('mad4b_decision_invalid_choice','Unregistered migration choice',400);
        if(preg_match('/^page_([1-9][0-9]*)$/D',$asset,$matches) &&
            MAD4B_SCP_Migration_Decision_Catalog::legal_page((int)$matches[1]) &&
            $decision==='retire')
            return self::err('mad4b_decision_legal_page_protected',
                'Legal pages cannot be retired without a separate legal-approved replacement workflow',409);
        $reason=trim(wp_strip_all_tags((string)$reason));
        if(strlen($reason)<12 || strlen($reason)>1200)
            return self::err('mad4b_decision_reason_required','Reason must be 12-1200 characters',400);
        if(!self::lock($site,$asset))return self::err('mad4b_decision_busy','Decision is being edited',423);
        try {
            return self::atomic(function()use($site,$asset,$decision,$reason,$source_sha,$target_sha,$expected_id){
                global $wpdb;
                $compare=MAD4B_SCP_Migration_Decision_Catalog::comparison($asset);
                if(!hash_equals($compare['existing_sha256'],(string)$source_sha) ||
                  !hash_equals($compare['target_sha256'],(string)$target_sha))
                    return self::err('mad4b_decision_source_drift','Comparison has changed');
                $current=self::current($asset,$site);
                if((int)($current['id']??0)!==(int)$expected_id)
                    return self::err('mad4b_decision_policy_drift','Current policy changed');
                $pending=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().
                  " WHERE site_uuid=%s AND asset_key=%s AND state IN ('proposed','approved')",$site,$asset));
                if($pending)return self::err('mad4b_decision_pending_exists','Review existing request first');
                $now=current_time('mysql',true);
                $ok=$wpdb->insert(self::table(),[
                    'site_uuid'=>$site,'asset_key'=>$asset,'decision'=>$decision,'reason'=>$reason,
                    'source_sha'=>$source_sha,'target_sha'=>$target_sha,
                    'expected_applied_id'=>(int)$expected_id,'state'=>'proposed',
                    'proposed_by'=>get_current_user_id(),'created_utc'=>$now,'updated_utc'=>$now]);
                if(!$ok)return self::err('mad4b_decision_insert_failed','Request could not be saved',500);
                $id=(int)$wpdb->insert_id;
                $audit=self::audit('proposed',['id'=>$id,'site_uuid'=>$site,
                    'asset_key'=>$asset,'decision'=>$decision,'source_sha'=>$source_sha,
                    'actor_id'=>get_current_user_id()]);
                if(is_wp_error($audit))return $audit;
                return ['status'=>'proposed','id'=>$id,'site_uuid'=>$site,
                    'external_asset_changed'=>false];
            });
        } finally {self::unlock($site,$asset);}
    }
    public static function review($id,$decision) {
        if(!self::owner())return self::err('mad4b_decision_owner_browser_required',
            'Real site owner session and REST nonce required',403);
        if(!in_array($decision,['approve','reject'],true))
            return self::err('mad4b_decision_invalid_review','Invalid review',400);
        $r=self::read_proposal((int)$id,'proposed');
        if(!$r)return self::err('mad4b_decision_no_pending','Pending proposal not found',404);
        $site=$r['site_uuid'];$asset=$r['asset_key'];
        if(!self::lock($site,$asset))return self::err('mad4b_decision_busy','Another action is in progress',423);
        try {
            return self::atomic(function()use($id,$decision,$site,$asset){
                global $wpdb;
                $r=self::read_proposal((int)$id,'proposed');
                if(!$r)return self::err('mad4b_decision_no_pending','Proposal status changed');
                if((int)$r['proposed_by']===get_current_user_id())
                    return self::err('mad4b_decision_four_eyes','Proposer cannot review own change',403);
                if($decision==='approve'&&!self::matches_source($r))
                    return self::err('mad4b_decision_source_drift','Source/target changed after proposal');
                $current=self::current($asset,$site);
                if($decision==='approve' &&
                   (int)($current['id']??0)!==(int)$r['expected_applied_id'])
                    return self::err('mad4b_decision_policy_drift','Current applied policy changed');
                $state=$decision==='approve'?'approved':'rejected';
                $updated=$wpdb->update(self::table(),[
                    'state'=>$state,'reviewed_by'=>get_current_user_id(),
                    'updated_utc'=>current_time('mysql',true)],
                    ['id'=>(int)$id,'site_uuid'=>$site,'state'=>'proposed']);
                if($updated!==1)return self::err('mad4b_decision_review_race','Review no longer available');
                $audit=self::audit($state,['id'=>(int)$id,'site_uuid'=>$site,
                    'asset_key'=>$asset,'reviewer_id'=>get_current_user_id()]);
                if(is_wp_error($audit))return $audit;
                return ['status'=>$state,'id'=>(int)$id,'external_asset_changed'=>false];
            });
        }finally{self::unlock($site,$asset);}
    }
    public static function apply($id,$confirmation) {
        if(!self::owner())return self::err('mad4b_decision_owner_browser_required',
            'Real site owner session and REST nonce required',403);
        if(!hash_equals(self::APPLY,(string)$confirmation))
            return self::err('mad4b_decision_apply_confirmation','Explicit apply confirmation required',409);
        $r=self::read_proposal((int)$id,'approved');
        if(!$r)return self::err('mad4b_decision_not_approved','Independent approval required',409);
        $site=$r['site_uuid'];$asset=$r['asset_key'];
        if(!self::lock($site,$asset))return self::err('mad4b_decision_busy','Another action is in progress',423);
        try {
            return self::atomic(function()use($id,$site,$asset){
                global $wpdb;
                $r=self::read_proposal((int)$id,'approved');
                if(!$r)return self::err('mad4b_decision_not_approved','Request is no longer approved');
                if(!self::matches_source($r))
                    return self::err('mad4b_decision_source_drift','Provider/source changed; review again');
                $prior=self::current($asset,$site);
                if((int)($prior['id']??0)!==(int)$r['expected_applied_id'])
                    return self::err('mad4b_decision_policy_drift','Applied policy no longer matches plan');
                if($prior){
                    $old=$wpdb->update(self::table(),['state'=>'superseded',
                        'updated_utc'=>current_time('mysql',true)],
                        ['id'=>(int)$prior['id'],'site_uuid'=>$site,'state'=>'applied']);
                    if($old!==1)return self::err('mad4b_decision_policy_race','Policy changed during apply');
                }
                $now=current_time('mysql',true);
                $ok=$wpdb->update(self::table(),[
                    'state'=>'applied','prior_applied_id'=>(int)($prior['id']??0),
                    'applied_by'=>get_current_user_id(),'updated_utc'=>$now],
                    ['id'=>(int)$id,'site_uuid'=>$site,'state'=>'approved']);
                if($ok!==1)return self::err('mad4b_decision_apply_race','Approved request changed');
                $audit=self::audit('policy-applied',[
                    'id'=>(int)$id,'site_uuid'=>$site,'asset_key'=>$asset,
                    'new_decision'=>$r['decision'],'previous_id'=>(int)($prior['id']??0),
                    'owner_id'=>get_current_user_id(),'provider_mutation'=>false,
                    'license_keys_unchanged'=>true,'code_snippets_not_executed'=>true]);
                if(is_wp_error($audit))return $audit;
                return ['status'=>'policy_applied','id'=>(int)$id,
                    'prior_id'=>(int)($prior['id']??0),
                    'execution_scope'=>'decision_registry_only',
                    'provider_changes'=>'PENDING_SEPARATE_CERTIFIED_ADAPTER',
                    'external_asset_changed'=>false];
            });
        }finally{self::unlock($site,$asset);}
    }
    public static function revert($id,$confirmation) {
        if(!self::owner())return self::err('mad4b_decision_owner_browser_required',
            'Real site owner session and REST nonce required',403);
        if(!hash_equals(self::REVERT,(string)$confirmation))
            return self::err('mad4b_decision_revert_confirmation','Explicit revert confirmation required');
        $r=self::read_proposal((int)$id,'applied');
        if(!$r)return self::err('mad4b_decision_not_current','Only current policy can be reverted',409);
        $site=$r['site_uuid'];$asset=$r['asset_key'];
        if(!self::lock($site,$asset))return self::err('mad4b_decision_busy','Another action is in progress',423);
        try{
            return self::atomic(function()use($id,$site,$asset){
                global $wpdb;
                $r=self::read_proposal((int)$id,'applied');
                if(!$r)return self::err('mad4b_decision_not_current','Decision changed');
                $current=self::current($asset,$site);
                if((int)($current['id']??0)!==(int)$id)
                    return self::err('mad4b_decision_not_current','Policy has a successor');
                $prior=(int)$r['prior_applied_id'];
                if($prior){
                    $back=$wpdb->update(self::table(),['state'=>'applied',
                        'updated_utc'=>current_time('mysql',true)],
                        ['id'=>$prior,'site_uuid'=>$site,'state'=>'superseded']);
                    if($back!==1)return self::err('mad4b_decision_prior_missing','Previous version cannot be restored');
                }
                $undone=$wpdb->update(self::table(),['state'=>'reverted',
                    'updated_utc'=>current_time('mysql',true)],
                    ['id'=>(int)$id,'site_uuid'=>$site,'state'=>'applied']);
                if($undone!==1)return self::err('mad4b_decision_revert_race','Policy changed during revert');
                $audit=self::audit('policy-reverted',['id'=>(int)$id,'site_uuid'=>$site,
                    'asset_key'=>$asset,'restored_previous_id'=>$prior,
                    'owner_id'=>get_current_user_id(),'external_asset_changed'=>false]);
                if(is_wp_error($audit))return $audit;
                return ['status'=>'policy_reverted','id'=>(int)$id,
                    'restored_id'=>$prior,'external_asset_changed'=>false];
            });
        }finally{self::unlock($site,$asset);}
    }
    public static function permission() {
        return self::can_propose() ? true : self::err('mad4b_decision_access_denied',
            'Company migration planning permission required',403);
    }
    public static function route(WP_REST_Request $r) {
        $op=(string)$r->get_param('operation');
        if($op==='propose'){
            return rest_ensure_response(self::propose(
                sanitize_key($r->get_param('asset_key')),
                sanitize_key($r->get_param('decision')),
                (string)$r->get_param('reason'),
                (string)$r->get_param('source_sha'),
                (string)$r->get_param('target_sha'),
                (int)$r->get_param('expected_applied_id')));
        }
        $id=(int)$r->get_param('id');
        if($op==='review')return rest_ensure_response(self::review($id,
            sanitize_key($r->get_param('decision'))));
        if($op==='apply')return rest_ensure_response(self::apply($id,(string)$r->get_param('confirmation')));
        if($op==='revert')return rest_ensure_response(self::revert($id,(string)$r->get_param('confirmation')));
        return self::err('mad4b_decision_operation_invalid','Unknown operation',400);
    }
    public static function page(): void {
        if(!self::can_propose())wp_die('Company migration planning access denied',403);
        $requested=absint(wp_unslash($_GET['asset_page_id']??0));
        $info=self::snapshot($requested);$history=self::history();
        echo '<div class="wrap"><h1>Company Migration Decisions</h1>';
        echo '<p>Compare existing tenant assets with proposed MAD4B capabilities. '
            .'Select each decision separately. A different owner must approve it before '
            .'a reversible company migration policy is activated.</p>';
        echo '<p><strong>Company UUID:</strong> <code>'.esc_html($info['site_uuid']).'</code>. '
            .'<strong>Provider deployment:</strong> blocked pending separate certified adapters.</p>';
        if(!$info['schema_ready'] || $info['site_uuid']==='') {
            echo '<p>Migration decision storage or Staging site authority not ready.</p></div>';
            return;
        }
        echo '<h2>Special company pages — About Us, Privacy Policy, Terms, Contact</h2>';
        echo '<p>Each page is compared independently. Existing content, Elementor layouts, '
            .'language links, SEO and canonical URLs remain unchanged until a separate approved migration.</p>';
        echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'">'
            .'<input type="hidden" name="page" value="'.esc_attr(self::MENU).'">'
            .'<label>Compare page by existing WordPress ID '
            .'<input type="number" min="1" name="asset_page_id" value="'.(int)$requested.'"></label> '
            .'<button class="button" type="submit">Compare this page</button></form>';
        echo '<p>Legal pages require content-owner/legal review; their retirement is blocked. '
            .'WordPress page IDs are site-local, not transferable to another company database.</p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:18%">Asset</th>'
            .'<th style="width:29%">Current company configuration</th>'
            .'<th style="width:28%">New system candidate</th>'
            .'<th>Choose reviewed outcome</th></tr></thead><tbody>';
        foreach($info['catalog'] as $item) {
            $existing=wp_json_encode($item['existing']['details'],
                JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $target=wp_json_encode($item['target'],
                JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $applied=$item['applied_policy'];
            echo '<tr><td><strong>'.esc_html($item['label']).'</strong><p><code>'
                .esc_html($item['key']).'</code></p>';
            if($applied)echo '<p><strong>Policy:</strong> '.esc_html($applied['decision'])
                .' (#'.(int)$applied['id'].')</p>';
            if(!empty($item['requires_legal_signoff']))
                echo '<p><strong>Legal content review required before real replacement</strong></p>';
            if(!empty($item['page_id']))
                echo '<p><a href="'.esc_url(get_permalink((int)$item['page_id'])).'"
                    target="_blank" rel="noopener noreferrer">View current page</a></p>';
            echo '</td><td><details><summary>Inspect existing (read only)</summary><pre style="white-space:pre-wrap;word-break:break-word">'
                .esc_html((string)$existing).'</pre></details><p>SHA256 <code>'
                .esc_html(substr($item['existing_sha256'],0,12)).'</code></p></td>';
            echo '<td><p><strong>'.esc_html($item['target']['description']).'</strong></p>'
                .'<p>Compatibility: <strong>Uncertified for this company</strong></p>'
                .'<details><summary>Target manifest</summary><pre style="white-space:pre-wrap;word-break:break-word">'
                .esc_html((string)$target).'</pre></details>'
                .'<p>SHA256 <code>'.esc_html(substr($item['target_sha256'],0,12)).'</code></p></td>';
            echo '<td><form class="mad4b-migration-proposal">';
            echo '<input type="hidden" name="asset_key" value="'.esc_attr($item['key']).'">'
                .'<input type="hidden" name="source_sha" value="'.esc_attr($item['existing_sha256']).'">'
                .'<input type="hidden" name="target_sha" value="'.esc_attr($item['target_sha256']).'">'
                .'<input type="hidden" name="expected_applied_id" value="'.(int)($applied['id']??0).'">';
            echo '<select name="decision" style="max-width:100%;width:100%">';
            foreach($item['choices'] as $name=>$label)
                echo '<option value="'.esc_attr($name).'">'.esc_html($label).'</option>';
            echo '</select><p><textarea name="reason" rows="2" required maxlength="1200" '
                .'placeholder="Why is this better for this company?" style="width:100%"></textarea></p>'
                .'<button class="button" type="submit">Submit for independent review</button>'
                .'</form></td></tr>';
        }
        echo '</tbody></table><h2>Approval, applied policy & rollback history</h2>';
        echo '<p><strong>Applying a decision changes only the company policy registry.</strong> '
           .'It does not install, activate, remove or update plugins, run snippets, change '
           .'Elementor templates, reuse license keys or alter customer records. '
           .'A separate provider-specific certified plan is mandatory.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID / asset</th><th>Choice</th>'
            .'<th>State</th><th>Actor / reviewer</th><th>Action</th></tr></thead><tbody>';
        foreach($history as $r) {
            echo '<tr><td>#'.(int)$r['id'].' '.esc_html($r['asset_key']).'</td>'
                .'<td>'.esc_html($r['decision']).'<p>'.esc_html($r['reason']).'</p></td>'
                .'<td>'.esc_html($r['state']).'</td>'
                .'<td>'.(int)$r['proposed_by'].' / '.(int)$r['reviewed_by'].'</td><td>';
            if(current_user_can('manage_options') && current_user_can('activate_plugins')) {
                if($r['state']==='proposed'){
                    echo '<button type="button" class="button mad4b-migration-action" data-id="'.(int)$r['id']
                        .'" data-operation="review" data-decision="approve">Approve</button> '
                        .'<button type="button" class="button mad4b-migration-action" data-id="'.(int)$r['id']
                        .'" data-operation="review" data-decision="reject">Reject</button>';
                }
                if($r['state']==='approved')
                    echo '<button type="button" class="button mad4b-migration-action" data-id="'.(int)$r['id']
                        .'" data-operation="apply">Activate policy only</button>';
                if($r['state']==='applied')
                    echo '<button type="button" class="button mad4b-migration-action" data-id="'.(int)$r['id']
                        .'" data-operation="revert">Revert company policy</button>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p id="mad4b-decision-result" role="status" aria-live="polite"></p>';
        $url=rest_url('mad4b/v1/company-migration-decisions/action');
        $nonce=wp_create_nonce('wp_rest');
        echo '<script>(function(){'
            .'const endpoint='.wp_json_encode($url).', nonce='.wp_json_encode($nonce).';'
            .'const status=document.getElementById("mad4b-decision-result");'
            .'async function submit(payload){status.textContent="Request submitted for verification...";'
            .'try{const response=await fetch(endpoint,{method:"POST",credentials:"same-origin",'
            .'headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},'
            .'body:JSON.stringify(payload)});const body=await response.json();'
            .'status.textContent=response.ok?JSON.stringify(body):(body.message||"Change rejected");'
            .'if(response.ok)window.location.reload();}catch(e){status.textContent="No confirmed change";}}'
            .'document.querySelectorAll(".mad4b-migration-proposal").forEach(function(form){'
            .'form.addEventListener("submit",function(e){e.preventDefault();'
            .'const f=new FormData(form);submit({operation:"propose",asset_key:f.get("asset_key"),'
            .'decision:f.get("decision"),reason:f.get("reason"),source_sha:f.get("source_sha"),'
            .'target_sha:f.get("target_sha"),expected_applied_id:Number(f.get("expected_applied_id"))});});});'
            .'document.querySelectorAll(".mad4b-migration-action").forEach(function(btn){'
            .'btn.addEventListener("click",function(){const operation=btn.dataset.operation;'
            .'const needed=operation==="apply"?'.wp_json_encode(self::APPLY).':'
            .'(operation==="revert"?'.wp_json_encode(self::REVERT).':'
            .wp_json_encode(self::CONFIRM).');'
            .'if(window.prompt("Enter exact approval phrase:", "")!==needed){'
            .'status.textContent="Owner confirmation not supplied.";return;}'
            .'submit({operation:operation,id:Number(btn.dataset.id),'
            .'decision:btn.dataset.decision||"",confirmation:needed});});});'
            .'})();</script></div>';
    }
    public static function boot(): void {
        add_action('admin_menu',static function(){
            add_management_page('Company Migration Decisions','Company Migration Decisions',
                'manage_options',self::MENU,[__CLASS__,'page']);
            if(class_exists('MAD4B_Tenant_Operations_Workspace'))
                add_submenu_page('mad4b-company-workspace','Migration Decisions','Migration Decisions',
                    'mad4b_manage_company_operations',self::MENU,[__CLASS__,'page']);
        });
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/company-migration-decisions/plan',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>static function(){
                    $r=rest_ensure_response(['snapshot'=>self::snapshot(
                        absint($_GET['asset_page_id']??0)),
                        'history'=>self::history()]);
                    $r->header('Cache-Control','private, no-store');return $r;
                }]);
            register_rest_route('mad4b/v1','/company-migration-decisions/action',[
                'methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'route']
            ]);
        });
    }
}
MAD4B_SCP_Company_Migration_Decisions::boot();
