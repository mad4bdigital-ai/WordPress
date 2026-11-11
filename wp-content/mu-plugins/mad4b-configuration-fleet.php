<?php
/** Central-only, authenticated fleet view; snapshot produced by authorized local host reader. */
defined('ABSPATH') || exit;
final class MAD4B_Configuration_Fleet {
    const STORE='mad4b_configuration_fleet_snapshot_v1';
    const CONTRACT='mad4b.configuration-fleet.v1';
    const PAGE='mad4b-configuration-fleet';
    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'menu'],91);
        add_action('admin_bar_menu',[__CLASS__,'bar'],98);
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('wp_abilities_api_init',[__CLASS__,'ability'],45);
        add_filter('mad4b/configuration_jobs/discover',[__CLASS__,'coverage_alerts'],15,1);
    }
    public static function snapshot():array {
        $s=get_option(self::STORE,[]);
        if(!is_array($s)||($s['contract']??'')!==self::CONTRACT)return ['contract'=>self::CONTRACT,'status'=>'not_collected','sites'=>[]];
        $site=get_option('mad4b_scp_site_profile_v2',[]);
        if(($s['central_site_uuid']??'')!==($site['site_uuid']??''))return ['contract'=>self::CONTRACT,'status'=>'site_identity_mismatch','sites'=>[]];
        $age=time()-(int)strtotime((string)($s['observed_utc']??''));
        $s['snapshot_fresh']=$age>=0&&$age<90*MINUTE_IN_SECONDS;
        $s['snapshot_age_minutes']=$age>=0?(int)floor($age/60):null;
        return $s;
    }
    /** A fleet coverage gap is an operational configuration alert, not an offline-site verdict. */
    public static function coverage_alerts(array $existing):array {
        $snapshot=self::snapshot();
        if(($snapshot['status']??'')==='not_collected')return $existing;
        if(empty($snapshot['snapshot_fresh'])) {
            $existing[]=['provider'=>'mad4b-fleet','code'=>'provider_diagnostics_unavailable',
                'scope'=>'fleet','source'=>'probe','severity'=>'medium',
                'label'=>'Central configuration fleet snapshot is stale',
                'guidance'=>'Review the VPS fleet collector, site read receipts and worker admission; do not infer remote-site health.'];
            return $existing;
        }
        $missing=(int)($snapshot['summary']['unverified_sites']??0);
        if($missing>0){
            $existing[]=['provider'=>'mad4b-fleet','code'=>'configuration_telemetry_coverage_incomplete',
                'scope'=>'fleet','source'=>'probe','severity'=>'medium',
                'label'=>'Configuration alert coverage is unverified in '.$missing.' company environments',
                'guidance'=>'Enroll the Configuration Jobs read-only contract through each existing company transport and verify live tenant snapshots without requiring new OAuth or certificates.'];
            foreach((array)($snapshot['sites']??[]) as $site){
                if(!is_array($site) || ($site['coverage']??'')==='verified')continue;
                $company=sanitize_key((string)($site['company_code']??''));
                $env=sanitize_key((string)($site['environment']??''));
                if(!$company || !in_array($env,['staging','production'],true))continue;
                $coverage=sanitize_key((string)($site['coverage']??'not_verified'));
                $next=in_array($coverage,['remote_read_only_adapter_missing','remote_read_only_adapter_unverified'],true)
                    ? 'Verified remote WordPress read exists. Validate and install the site-local Configuration Jobs adapter using the existing authorized company deployment path, then read back alerts and task scope.'
                    : 'Verify the registered company host transport and exact Site Profile first, then establish the read-only Configuration Jobs feed. Do not assume the site is unhealthy.';
                $existing[]=[
                    'provider'=>'mad4b-fleet','code'=>'configuration_telemetry_site_unverified',
                    'scope'=>$company.'-'.$env,'source'=>'probe','severity'=>'medium',
                    'label'=>'Missing Configuration Alerts coverage: '.$company.' / '.$env,
                    'guidance'=>$next,
                ];
            }
        }
        return $existing;
    }
    public static function ability():void {
        if(!function_exists('wp_register_ability'))return;
        if(function_exists('wp_has_ability') && wp_has_ability('mad4b/configuration-fleet-status'))return;
        wp_register_ability('mad4b/configuration-fleet-status',[
            'label'=>'Read Company Configuration Fleet Alerts',
            'description'=>'Read verified site-scoped company configuration alert snapshots; unknown coverage is not a healthy verdict.',
            'category'=>'mad4b-read',
            'execute_callback'=>static fn($input)=>self::snapshot(),
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'input_schema'=>['type'=>'object','properties'=>[],'required'=>[],
                'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
        ]);
    }
    public static function routes():void {
        register_rest_route('mad4b-configuration/v1','/fleet',[
            'methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::snapshot()),
            'permission_callback'=>static fn()=>current_user_can('manage_options')]);
    }
    public static function menu():void {
        add_submenu_page('mad4b-control-plane','Fleet Configuration Alerts',
          'Fleet Config Alerts','manage_options',self::PAGE,[__CLASS__,'render']);
    }
    public static function bar($bar):void {
        if(!current_user_can('manage_options')||!is_admin_bar_showing())return;
        $s=self::snapshot();$sum=(array)($s['summary']??[]);
        if((int)($sum['critical']??0)<1)return;
        $bar->add_node(['id'=>'mad4b-fleet-config-alerts',
            'title'=>'Fleet Config Critical: '.(int)$sum['critical'],
            'href'=>admin_url('admin.php?page='.self::PAGE)]);
    }
    public static function render():void {
        if(!current_user_can('manage_options'))wp_die('Forbidden');
        $s=self::snapshot();$sites=(array)($s['sites']??[]);
        echo '<div class="wrap"><h1>MAD4B Fleet Configuration Alerts</h1>';
        echo '<p>Verified, read-only host collector snapshot from enrolled companies. A company marked Not Verified is not healthy by assumption. Never execute fixes from this dashboard.</p>';
        if(empty($s['snapshot_fresh']))echo '<div class="notice notice-warning"><p>Fleet snapshot missing or stale. Inspect the host Configuration Fleet Collector.</p></div>';
        $sum=(array)($s['summary']??[]);
        echo '<p><strong>Verified sites:</strong> '.(int)($sum['verified_sites']??0).' / '.(int)($sum['registry_sites']??0).
            ' &nbsp; <strong>Open alerts:</strong> '.(int)($sum['open']??0).
            ' &nbsp; <strong>Critical:</strong> '.(int)($sum['critical']??0).
            ' &nbsp; <strong>Remote WordPress read-only:</strong> '.(int)($sum['remote_read_only_sites']??0).
            ' &nbsp; <strong>Missing full alert coverage:</strong> '.(int)($sum['unverified_sites']??0).'</p>';
        $rec=(array)($s['credential_recovery']??[]);
        echo '<h2>Credential Key Recovery Assurance</h2>';
        echo '<p><strong>Verified current-key encrypted backups:</strong> '.(int)($rec['verified_current_key_backups']??0).
            ' / '.(int)($rec['registered_local_sites']??0).
            ' &nbsp; <strong>Off-host escrow:</strong> '.esc_html((string)($rec['offhost_escrow_status']??'NOT_VERIFIED')).
            ' &nbsp; <strong>Audit freshness:</strong> '.(!empty($rec['fresh'])?'Verified recent':'Not verified').'</p>';
        echo '<h2>Encrypted Credential Transport Readiness</h2>';
        echo '<p><strong>Central signing identities:</strong> '.(int)($sum['credential_issuer_ready_sites']??0).
            ' &nbsp; <strong>Company recipient identities:</strong> '.(int)($sum['credential_recipient_ready_sites']??0).
            ' &nbsp; <strong>Recipient identities pinned to current central signer:</strong> '.(int)($sum['credential_recipient_trust_matches']??0).'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Identity</th><th>State</th><th>Trust</th><th>Vendor key moved?</th></tr></thead><tbody>';
        foreach($sites as $site){
            $c=(array)($site['credential_transport']??[]);
            echo '<tr><td>'.esc_html((string)($site['company_name']??$site['company_code']??'')).'</td>' .
                '<td>'.esc_html((string)($site['environment']??'')).'</td>' .
                '<td>'.esc_html((string)($c['role']??'unverified')).'</td>' .
                '<td>'.esc_html((string)($c['state']??'unverified')).'</td>' .
                '<td>'.(isset($c['trust_matches_verified_central_issuer'])?($c['trust_matches_verified_central_issuer']?'Verified':'Not verified'):'Not applicable / unverified').'</td>' .
                '<td>No</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Credential Provider Adapter Qualification</h2>';
        echo '<p>Verified local provider feeds: '.(int)($sum['credential_provider_qualification_verified_sites']??0).
             '. Status labels are cached/plugin-local evidence; none authorizes copying a vendor secret.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Provider</th><th>Qualification evidence</th><th>SMTP sandbox auth</th><th>Secret transfer</th></tr></thead><tbody>';
        foreach($sites as $site){
            $items=(array)($site['credential_provider_qualification']['states']??[]);
            foreach($items as $provider=>$v){
                echo '<tr><td>'.esc_html((string)($site['company_name']??$site['company_code']??'')).'</td>'.
                    '<td>'.esc_html((string)($site['environment']??'')).'</td>'.
                    '<td>'.esc_html((string)$provider).'</td>'.
                    '<td>'.esc_html((string)($v['state']??'unverified')).'</td>'.
                    '<td>'.esc_html((string)($v['smtp_sandbox_protocol_state']??'not_verified')).'</td>'.
                    '<td>Not enabled</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<h2>Company Plugin Vendor Activation Receipts</h2>';
        echo '<p>Recipient-native verification results, distinct from source-key presence or cached plugin status. Blank or unverified does not mean activated.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Plugin</th><th>Vendor result</th><th>Verified at (UTC)</th><th>Key transferred</th></tr></thead><tbody>';
        foreach($sites as $site){
            foreach((array)($site['credential_native_plugin_activation']['provider_states']??[]) as $provider=>$state){
                $verified=!empty($state['vendor_receipt_and_current_license_valid']);
                echo '<tr><td>'.esc_html((string)($site['company_name']??$site['company_code']??'')).'</td>'.
                    '<td>'.esc_html((string)($site['environment']??'')).'</td>'.
                    '<td>'.esc_html((string)$provider).'</td>'.
                    '<td>'.esc_html($verified?'Native activation and local valid confirmed':(string)($state['state']??'not_verified')).'</td>'.
                    '<td>'.esc_html((string)($state['vendor_activated_at_utc']??'Not verified')).'</td><td>No</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<h2>Private Vault Integrity</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Vault status</th><th>Unexpected files</th><th>Corrupt/missing ciphertext</th><th>Audit events</th><th>Embedded audit (valid/invalid)</th><th>Legacy (unverified)</th></tr></thead><tbody>';
        foreach($sites as $site){
            $v=(array)($site['credential_vault_integrity']??[]);
            $verified=($v['status']??'')==='site_private_vault_observed';
            $unknown='Not verified';
            echo '<tr><td>'.esc_html((string)($site['company_name']??$site['company_code']??'')).'</td>'.
                '<td>'.esc_html((string)($site['environment']??'')).'</td>'.
                '<td>'.esc_html((string)($v['status']??'not_verified')).'</td>'.
                '<td>'.($verified?(string)(int)($v['unexpected_sealed_file_count']??0):$unknown).'</td>'.
                '<td>'.($verified?(string)(int)($v['missing_or_corrupt_cipher_count']??0):$unknown).'</td>'.
                '<td>'.($verified?(string)(int)($v['audited_lifecycle_event_count']??0):$unknown).'</td>'.
                '<td>'.($verified?((int)($v['embedded_audit_valid_stage_count']??0).' / '.(int)($v['embedded_audit_invalid_stage_count']??0)):$unknown).'</td>'.
                '<td>'.($verified?(string)(int)($v['legacy_pre_audit_stage_count']??0):$unknown).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Unified Incident Classification</h2>';
        echo '<p>Verified local routing feeds: '.(int)($sum['routing_verified_sites']??0).' of '.(int)($sum['registry_sites']??0).
            ' &nbsp; <strong>Unique incidents:</strong> '.(int)($sum['routed_open']??0).
            ' &nbsp; <strong>Content:</strong> '.(int)($sum['routed_content']??0).
            ' &nbsp; <strong>Configuration:</strong> '.(int)($sum['routed_configuration']??0).
            ' &nbsp; <strong>Investigation:</strong> '.(int)($sum['routed_investigation']??0).
            ' &nbsp; <strong>Coalesced cross-family children:</strong> '.(int)($sum['routed_cross_family_coalesced']??0).'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Classification coverage</th><th>Unique incidents</th><th>Content</th><th>Configuration</th><th>Investigation</th></tr></thead><tbody>';
        foreach($sites as $site){
            $r=(array)($site['routing']??[]);
            echo '<tr><td>'.esc_html((string)($site['company_name']??$site['company_code']??'')).'</td>' .
                '<td>'.esc_html((string)($r['status']??'not_verified')).'</td>' .
                '<td>'.(isset($r['open'])?(int)$r['open']:'&mdash;').'</td>' .
                '<td>'.(isset($r['by_family']['content'])?(int)$r['by_family']['content']:'&mdash;').'</td>' .
                '<td>'.(isset($r['by_family']['configuration'])?(int)$r['by_family']['configuration']:'&mdash;').'</td>' .
                '<td>'.(isset($r['by_family']['investigation'])?(int)$r['by_family']['investigation']:'&mdash;').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Coverage</th><th>Open</th><th>Critical</th><th>High</th><th>Details</th></tr></thead><tbody>';
        foreach($sites as $item){if(!is_array($item))continue;
            $name=esc_html((string)($item['company_name']??$item['company_code']??'Unknown'));
            $env=esc_html((string)($item['environment']??''));
            $state=(string)($item['coverage']??'not_verified');
            $labels=[
              'verified'=>'Full Configuration Alerts verified',
              'remote_read_only_adapter_missing'=>'Remote WordPress verified — Configuration Jobs absent',
              'remote_read_only_adapter_unverified'=>'Remote WordPress verified — Configuration Jobs unverified',
              'identity_mismatch'=>'Site identity mismatch — not trusted',
              'read_unavailable'=>'Read failed — not verified',
              'not_verified'=>'No verified telemetry',
            ];
            $coverage=esc_html($labels[$state]??$state);
            $counts=(array)($item['summary']??[]);
            $url=(string)($item['admin_url']??'');
            echo '<tr><td>'.$name.'</td><td>'.$env.'</td><td>'.$coverage.'</td>' .
                '<td>'.(int)($counts['open']??0).'</td><td>'.(int)($counts['critical']??0).'</td><td>'.(int)($counts['high']??0).'</td><td>' .
                ($url?'<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Site jobs</a>':
                  ($state==='remote_read_only_adapter_missing'?'Install/configure native alert adapter':
                    ($state==='remote_read_only_adapter_unverified'?'Verify native alert adapter':'Not yet connected'))).'</td></tr>';
        }
        echo '</tbody></table><h2>Verified active alerts</h2><table class="widefat striped"><thead><tr><th>Company</th><th>Priority</th><th>Provider</th><th>Issue</th><th>Linked Job</th></tr></thead><tbody>';
        foreach($sites as $site){if(($site['coverage']??'')!=='verified')continue;
            foreach((array)($site['alerts']??[]) as $a){
                echo '<tr><td>'.esc_html((string)($site['company_name']??'')).'</td><td>'.esc_html(strtoupper((string)($a['severity']??''))).'</td>'.
                    '<td>'.esc_html((string)($a['provider']??'')).'</td><td>'.esc_html((string)($a['label']??'')).'</td><td>#'.(int)($a['job_id']??0).'</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
}
MAD4B_Configuration_Fleet::boot();
