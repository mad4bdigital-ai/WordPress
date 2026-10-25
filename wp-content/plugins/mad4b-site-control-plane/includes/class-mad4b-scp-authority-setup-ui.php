<?php
if (!defined('ABSPATH')) exit;
/**
 * Visibility and request preparation for governed Developer, Breakglass and
 * Full Staging Authority. This screen never issues grants or invokes Apply.
 * Exact OAuth-bearing ability calls remain the sole mutation authority.
 */
final class MAD4B_SCP_Authority_Setup_UI {
    const CONTRACT='mad4b.authority-setup-ui.v1';

    public static function workflows() {
        $common=array(
            'expected_plan_sha256'=>'Exact authority plan SHA-256',
            'expected_source_commit_sha'=>'Certified source commit SHA',
            'expected_site_uuid'=>'Enrolled Site UUID',
            'expected_environment'=>'Site Profile environment',
            'expected_profile_revision'=>'Site Profile revision',
            'expected_profile_digest'=>'Site Profile digest',
            'confirmation'=>'Exact confirmation phrase',
        );
        return array(
            'developer'=>array(
                'label'=>'Developer Grants',
                'plan_ability'=>'mad4b/developer-authority-plan',
                'apply_ability'=>'mad4b/developer-authority-apply',
                'confirmation'=>'PROVISION MAD4B DEVELOPER AGENT',
                'fields'=>$common,
                'environment'=>'local|development|staging',
            ),
            'developer_breakglass'=>array(
                'label'=>'Developer Breakglass (not raw SQL)',
                'plan_ability'=>'mad4b/developer-breakglass-authority-plan',
                'apply_ability'=>'mad4b/developer-breakglass-authority-apply',
                'confirmation'=>'PROVISION MAD4B DEVELOPER BREAKGLASS',
                'fields'=>$common,
                'environment'=>'local|development|staging',
            ),
            'full_staging'=>array(
                'label'=>'Full Staging Authority',
                'plan_ability'=>'mad4b/full-staging-authority-plan',
                'apply_ability'=>'mad4b/full-staging-authority-apply',
                'confirmation'=>'ENABLE FULL STAGING AUTHORITY',
                'fields'=>array(
                    'expected_plan_sha256'=>'Exact composite plan SHA-256',
                    'expected_source_commit_sha'=>'Source commit SHA',
                    'expected_build_fingerprint'=>'Build fingerprint SHA-256',
                    'expected_package_manifest_digest'=>'Package manifest SHA-256',
                    'expected_artifact_identity'=>'Exact artifact identity',
                    'expected_site_uuid'=>'Enrolled Site UUID',
                    'expected_profile_revision'=>'Site Profile revision',
                    'expected_profile_digest'=>'Site Profile digest',
                    'confirmation'=>'Exact owner confirmation',
                ),
                'environment'=>'staging only',
            ),
        );
    }
    public static function authorized() {
        return current_user_can('manage_options')
            && class_exists('MAD4B_SCP_Site_Profile')
            && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }
    public static function project_status() {
        if(!self::authorized())
            return new WP_Error('mad4b_setup_access_denied','An enrolled administrator is required.');
        $dev=class_exists('MAD4B_SCP_Developer_Authority')
            ?MAD4B_SCP_Developer_Authority::status():array();
        $full=class_exists('MAD4B_SCP_Full_Staging_Authority')
            ?MAD4B_SCP_Full_Staging_Authority::status():array();
        $env=sanitize_key((string)MAD4B_SCP_Site_Profile::current_environment());
        return array('contract'=>self::CONTRACT,'environment'=>$env,
            'developer'=>array('enabled'=>!empty($dev['developer_enabled']),
                'direct_execution'=>!empty($dev['direct_execution_enabled']),
                'authority_ready'=>!empty($dev['normal_authority']['ready']),
                'grant_status'=>$dev['normal_authority']['state']??'unknown',
                'kill_switch'=>!empty($dev['kill_switch_enabled'])),
            'developer_breakglass'=>array('enabled'=>!empty($dev['breakglass_enabled']),
                'authority_ready'=>!empty($dev['breakglass_authority']['ready']),
                'grant_status'=>$dev['breakglass_authority']['state']??'unknown'),
            'full_staging'=>array('eligible_environment'=>$env==='staging',
                'authority_ready'=>!empty($full['authority_ready']),
                'operational_ready'=>!empty($full['operational_ready']),
                'operational_blockers'=>(array)($full['operational_blockers']??array())),
            'oauth_bearer_required'=>true,'no_implicit_apply'=>true,
            'raw_sql_breakglass_not_included'=>true,
            'mutation_performed'=>false);
    }
    public static function validate_payload($workflow,$input) {
        $flows=self::workflows();
        if(!isset($flows[$workflow]) || !is_array($input))
            return new WP_Error('mad4b_setup_flow_invalid','Choose a known governed authority workflow.');
        $flow=$flows[$workflow];
        $expected=$flow['fields'];
        if(array_diff(array_keys($input),array_keys($expected)))
            return new WP_Error('mad4b_setup_fields_unrecognized','Unexpected authority fields.');
        $out=array();
        foreach($expected as $name=>$label) {
            $value=$input[$name]??null;
            if($name==='expected_profile_revision') {
                if(!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value))
                    return new WP_Error('mad4b_setup_revision_invalid','Positive Site Profile revision required.');
                $out[$name]=(int)$value;continue;
            }
            if(!is_string($value))return new WP_Error('mad4b_setup_field_missing','All exact authority fields are required.');
            $value=trim($value);
            if($name==='confirmation') {
                if(!hash_equals($flow['confirmation'],$value))
                    return new WP_Error('mad4b_setup_confirmation_invalid','Exact owner confirmation phrase required.');
            } elseif($name==='expected_site_uuid') {
                if(!preg_match('/^[a-f0-9-]{36}$/Di',$value))
                    return new WP_Error('mad4b_setup_uuid_invalid','Expected enrolled UUID required.');
            } elseif($name==='expected_environment') {
                if(!in_array($value,array('local','development','staging'),true))
                    return new WP_Error('mad4b_setup_environment_invalid','Unsupported developer environment.');
            } elseif($name==='expected_source_commit_sha') {
                if(!preg_match('/^[a-f0-9]{40}$/Di',$value))
                    return new WP_Error('mad4b_setup_commit_invalid','Exact source SHA required.');
            } elseif($name==='expected_artifact_identity') {
                if(strlen($value)<40 || strlen($value)>191 || preg_match('/[\\r\\n\\t]/',$value))
                    return new WP_Error('mad4b_setup_artifact_invalid','Exact artifact identity required.');
            } elseif(!preg_match('/^[a-f0-9]{64}$/Di',$value)) {
                return new WP_Error('mad4b_setup_hash_invalid','Exact SHA-256 field required.');
            }
            $out[$name]=$value;
        }
        // Shape validation is NOT plan validation. Runtime re-checks every CAS
        // value under a verified OAuth bearer before any grant can be issued.
        return array('contract'=>self::CONTRACT,'workflow'=>$workflow,
            'ability'=>$flow['apply_ability'],'input'=>$out,
            'prepared_only'=>true,'plan_verified'=>false,'mutation_performed'=>false);
    }
    public static function admin_menu() {
        add_options_page('MAD4B Authority Setup','MAD4B Authority Setup','manage_options',
            'mad4b-authority-setup',array(__CLASS__,'render'));
    }
    public static function render() {
        if(!self::authorized()) {
            wp_die('Only the enrolled Site Profile administrator can access this page.','Forbidden',array('response'=>403));
        }
        $s=self::project_status();
        $flows=self::workflows();
        echo '<div class="wrap"><h1>MAD4B — Authority Setup</h1>';
        echo '<p>One source of authority: the existing MAD4B OAuth and exact grant-plan engine. This page does not enable flags, issue grants, bypass step-up authorization, or change the Site Profile.</p>';
        echo '<p><strong>Environment:</strong> '.esc_html($s['environment']).'. Developer: '.esc_html($s['developer']['authority_ready']?'ready':'not ready').
            '. Developer Breakglass: '.esc_html($s['developer_breakglass']['authority_ready']?'ready':'not ready').
            '. Full Staging: '.esc_html($s['full_staging']['authority_ready']?'ready':'not ready').
            '. Operational: '.esc_html($s['full_staging']['operational_ready']?'ready':'not ready').'.</p>';
        echo '<h2>Live authority readiness (read-only)</h2><table class="widefat striped"><thead><tr><th>Authority condition</th><th>Current status</th><th>Source of truth</th></tr></thead><tbody>';
        $rows=array(
            array('Developer granted abilities',!empty($s['developer']['authority_ready']),'Developer Authority grants'),
            array('Developer runtime enabled',!empty($s['developer']['enabled']),'Developer Runtime settings'),
            array('Developer direct execution',!empty($s['developer']['direct_execution']),'Developer Runtime settings'),
            array('Developer kill switch clear',empty($s['developer']['kill_switch']),'Developer Runtime kill switch'),
            array('Developer Breakglass granted abilities',!empty($s['developer_breakglass']['authority_ready']),'Developer Breakglass grants'),
            array('Developer Breakglass enabled',!empty($s['developer_breakglass']['enabled']),'Developer Runtime settings'),
            array('Full Staging authority ready',!empty($s['full_staging']['authority_ready']),'Full Staging Authority'),
            array('Full Staging operational ready',!empty($s['full_staging']['operational_ready']),'Full Staging operational projection'),
        );
        foreach($rows as $row) echo '<tr><td>'.esc_html($row[0]).'</td><td>'.esc_html($row[1]?'ready':'not ready').'</td><td>'.esc_html($row[2]).'</td></tr>';
        echo '</tbody></table>';
        if(!empty($s['full_staging']['operational_blockers'])) {
            echo '<p><strong>Operational blockers:</strong> ';
            echo esc_html(implode(', ',array_map('sanitize_key',$s['full_staging']['operational_blockers'])));
            echo '</p>';
        }
        if($s['environment']!=='staging') echo '<p><strong>Full Staging Authority cannot be applied in this environment.</strong> Staging Site Profile and verified owner OAuth are mandatory.</p>';
        if($s['developer']['kill_switch'])echo '<p><strong>Developer kill switch is active.</strong> The exact governance path must resolve this before execution.</p>';
        foreach($flows as $slug=>$flow) {
            echo '<hr/><h2>'.esc_html($flow['label']).'</h2>';
            echo '<p>Use the authenticated agent to call <code>'.esc_html($flow['plan_ability']).'</code> first, then review its blockers and exact source/agent identity. The apply ability is <code>'.esc_html($flow['apply_ability']).'</code>.</p>';
            echo '<p><strong>Environment:</strong> '.esc_html($flow['environment']).'; <strong>exact confirmation:</strong> <code>'.esc_html($flow['confirmation']).'</code>.</p>';
            if($slug==='developer_breakglass')
                echo '<p>Requires normal Developer authority first; this does not activate generic raw-SQL Breakglass.</p>';
            echo '<details><summary>Required exact input fields (preparation only)</summary>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_authority_prepare');
            echo '<input type="hidden" name="action" value="mad4b_authority_prepare"/>';
            echo '<input type="hidden" name="authority_workflow" value="'.esc_attr($slug).'"/>';
            echo '<table class="form-table">';
            foreach($flow['fields'] as $name=>$label) {
                echo '<tr><th><label for="'.esc_attr($slug.'_'.$name).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="'.esc_attr($slug.'_'.$name).'" name="authority_fields['.esc_attr($name).']" type="text" required autocomplete="off" placeholder="'.esc_attr($name).'" />';
                if($name==='confirmation')echo '<p class="description">Type the exact confirmation above; copying it never enables authority.</p>';
                echo '</td></tr>';
            }
            echo '</table><button class="button button-secondary" type="submit">Validate input shape and show OAuth handoff payload (no grants)</button></form></details>';
        }
        echo '<p><strong>Security boundary:</strong> completing a form is not an authorization. Verified original OAuth client identity, read-only plan, exact SHA fingerprints, owner attestation and append-only audit are checked again by the original apply Ability. Production activation is excluded.</p></div>';
    }
    public static function prepare() {
        if(!self::authorized())wp_die('Enrolled administrator required.','Forbidden',array('response'=>403));
        check_admin_referer('mad4b_authority_prepare');
        $workflow=isset($_POST['authority_workflow'])
            ?sanitize_key(wp_unslash((string)$_POST['authority_workflow'])):'';
        $data=isset($_POST['authority_fields']) && is_array($_POST['authority_fields'])
            ?wp_unslash($_POST['authority_fields']):array();
        $validated=self::validate_payload($workflow,$data);
        if(is_wp_error($validated))wp_die(esc_html($validated->get_error_code()),'Invalid request',array('response'=>400));
        $environment=sanitize_key((string)MAD4B_SCP_Site_Profile::current_environment());
        if($workflow==='full_staging' && $environment!=='staging')
            wp_die('Full Authority is not available outside Staging.','Forbidden',array('response'=>403));
        if($workflow!=='full_staging' && ($validated['input']['expected_environment']??'')!==$environment)
            wp_die('Developer request environment must match the enrolled site.','Conflict',array('response'=>409));
        nocache_headers();
        echo '<div class="wrap"><h1>Authority request prepared — NOT EXECUTED</h1><p>Copy this JSON only into the original verified OAuth Ability after obtaining and reviewing a fresh plan. This form has not created or changed any grant.</p><textarea readonly rows="20" cols="110">'.esc_textarea(wp_json_encode(array('ability'=>$validated['ability'],'input'=>$validated['input']),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</textarea></div>';
    }
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'admin_menu'));
        add_action('admin_post_mad4b_authority_prepare',array(__CLASS__,'prepare'));
    }
}
MAD4B_SCP_Authority_Setup_UI::boot();
