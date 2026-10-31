<?php
defined('ABSPATH') || exit;

/**
 * Owner-facing, evidence-bound reconciliation inbox.
 * This is intake/visibility, never an authorization decision or mutation API.
 */
final class MAD4B_SCP_Authority_Reviews_Bridge {
    const OPTION='mad4b_authority_reconciliation_reviews_v1';
    const CONTRACT='mad4b.authority-review-intake.v1';

    public static function boot() {
        if(!function_exists('add_action'))return;
        add_action('mad4b_authority_reconciliation_required',array(__CLASS__,'capture'),10,1);
        add_action('admin_menu',array(__CLASS__,'admin_menu'));
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b authority reviews',array(__CLASS__,'cli_status'));
    }

    private static function rows($p) {
        $rows=array();
        $missing=is_array($p['missing_exact_grants']??null)?$p['missing_exact_grants']:array();
        $baseline=class_exists('MAD4B_SCP_Post_Update_Continuation')
            ? MAD4B_SCP_Post_Update_Continuation::approved_grant_baseline():array();
        $sealed=is_array($baseline)&&!empty($baseline['seal_verified'])
            &&!empty($baseline['captured_before_update'])
            &&!empty($baseline['audit_verified']);
        $approved_capabilities=class_exists('MAD4B_SCP_Staging_Write_Grant_Reconciliation')
            ? MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers():array();
        foreach($missing as $g) {
            if(!is_array($g)||'missing_exact_grant'!==($g['reason']??''))continue;
            $ability=(string)($g['ability']??'');
            $provider=sanitize_key((string)($g['provider']??''));
            if(''===$ability||''===$provider)continue;
            $source_approved=isset($approved_capabilities[$ability])
                && sanitize_key((string)$approved_capabilities[$ability])===$provider;
            $rows[]=array(
                'kind'=>'exact_grant','subject'=>$ability.'@'.$provider,
                'state'=>!$source_approved?'SOURCE_POLICY_REVIEW_REQUIRED':(
                    $sealed?'AWAITING_NATIVE_APPROVAL_EVALUATION':'REVIEW_REQUIRED'),
                'reason'=>!$source_approved?'capability_provider_outside_reviewed_source_policy':(
                    $sealed?'preapproved_baseline_must_pass_native_delta_gate':'no_sealed_prior_grant_approval'),
                'native_owner'=>'MAD4B_SCP_Staging_Write_Grant_Reconciliation',
                'source_reviewed'=>$source_approved,
                'automatic_grant_issued'=>false,
            );
        }
        foreach((array)($p['needs_review']??array()) as $g) {
            if(!is_array($g))continue;
            $rows[]=array('kind'=>'stale_grant','subject'=>(string)($g['ability']??''),
                'state'=>'REVIEW_REQUIRED','reason'=>'unreviewed_stale_grant',
                'native_owner'=>'MAD4B_SCP_Staging_Write_Grant_Reconciliation',
                'automatic_grant_issued'=>false);
        }
        foreach((array)($p['subject_conflicts']??array()) as $reason) {
            $rows[]=array('kind'=>'subject_delegation','subject'=>'oauth@'.$p['agent_public_id'],
                'state'=>'REVIEW_REQUIRED','reason'=>sanitize_key((string)$reason),
                'native_owner'=>'MAD4B_SCP_Agent_Registry',
                'requires_exact_owner_evidence'=>true,'identity_reassigned'=>false);
        }
        if(empty($p['candidate_binding_match']))$rows[]=array(
            'kind'=>'candidate_binding','subject'=>$p['source_commit_sha'],
            'state'=>'WAITING_DEPENDENCY','reason'=>'grant_and_identity_reconciliation_required',
            'native_owner'=>'MAD4B_SCP_Staging_Write_Candidate_Binding',
            'binding_mutation_performed'=>false,
        );
        return $rows;
    }

    public static function capture($p) {
        if(!is_array($p)||($p['contract']??'')!=='mad4b.authority-auto-reconciliation.v1'
            ||empty($p['plan_sha256'])||empty($p['site_uuid']))return;
        if(!class_exists('MAD4B_SCP_Site_Profile')
            ||'staging'!==MAD4B_SCP_Site_Profile::current_environment()
            ||!hash_equals((string)MAD4B_SCP_Site_Profile::site_uuid(),(string)$p['site_uuid']))return;
        $existing=get_option(self::OPTION,array());
        if(!is_array($existing))$existing=array();
        $active=array();
        foreach(self::rows($p) as $row) {
            $id=hash('sha256',implode("\0",array(
                $p['site_uuid'],$row['kind'],$row['subject'])));
            $prior=is_array($existing[$id]??null)?$existing[$id]:array();
            $active[$id]=array_merge($prior,$row,array(
                'contract'=>self::CONTRACT,'review_id'=>$id,'site_uuid'=>$p['site_uuid'],
                'plan_sha256'=>$p['plan_sha256'],'source_commit_sha'=>$p['source_commit_sha'],
                'first_seen'=>(int)($prior['first_seen']??time()),
                'last_seen'=>time(),'resolved'=>false,
                'authorization_granted'=>false,
            ));
        }
        // Old reviewed items are retained as resolved evidence, never silently
        // deleted; reopening the same item is deterministic.
        foreach($existing as $id=>$old) {
            if(isset($active[$id])||!is_array($old)
                ||($old['site_uuid']??'')!==$p['site_uuid'])continue;
            $old['resolved']=true;
            $old['state']='RESOLVED_BY_CURRENT_READBACK';
            $old['last_seen']=time();
            $active[$id]=$old;
        }
        ksort($active,SORT_STRING);
        if(count($active)>250)$active=array_slice($active,-250,null,true);
        update_option(self::OPTION,$active,false);
        do_action('mad4b_authority_review_intake_updated',array(
            'site_uuid'=>$p['site_uuid'],'plan_sha256'=>$p['plan_sha256'],
            'unresolved'=>count(array_filter($active,static function($r){return empty($r['resolved']);})),
            'mutation_performed'=>false,
        ));
    }

    public static function status() {
        $entries=get_option(self::OPTION,array());
        if(!is_array($entries))$entries=array();
        $open=array_values(array_filter($entries,static function($r){
            return is_array($r)&&empty($r['resolved']);
        }));
        return array('contract'=>self::CONTRACT,'total'=>count($entries),
            'unresolved'=>count($open),'entries'=>$open,
            'approval_tickets_issued'=>0,'mutation_performed'=>false,
            'read_only'=>true);
    }

    public static function cli_status($args,$assoc_args) {
        if($args||$assoc_args)WP_CLI::error('No external selector is permitted.');
        if(!class_exists('MAD4B_SCP_Execution_Authority'))
            WP_CLI::error('Canonical authority unavailable.');
        $actor=MAD4B_SCP_Execution_Authority::resolve('read');
        if(is_wp_error($actor))WP_CLI::error($actor->get_error_code());
        WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES));
    }

    public static function admin_menu() {
        if(!current_user_can('manage_options'))return;
        add_management_page('MAD4B Authority Reviews','Authority Reviews',
            'manage_options','mad4b-authority-reviews',array(__CLASS__,'admin_page'));
    }
    public static function admin_page() {
        if(!current_user_can('manage_options'))wp_die('Not authorized.');
        $s=self::status();
        echo '<div class="wrap"><h1>MAD4B Authority Reviews</h1>';
        echo '<p>Source-backed authority reconciliation items. This screen does not issue grants or transfer an identity.</p>';
        echo '<p><strong>Open: '.esc_html((string)$s['unresolved']).'</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Subject</th><th>State</th><th>Reason</th></tr></thead><tbody>';
        foreach($s['entries'] as $r) {
            echo '<tr><td>'.esc_html($r['kind']).'</td><td>'.esc_html($r['subject'])
                .'</td><td>'.esc_html($r['state']).'</td><td>'.esc_html($r['reason']).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
MAD4B_SCP_Authority_Reviews_Bridge::boot();
