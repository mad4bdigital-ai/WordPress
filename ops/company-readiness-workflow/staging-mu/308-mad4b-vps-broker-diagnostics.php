<?php
/**
 * Plugin Name: MAD4B VPS Broker Job Diagnostics
 * Description: Auditable, read-only WordPress Broker job/claim status for enrolled Central Administrators.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
final class MAD4B_VPS_Broker_Diagnostics_V1 {
    const CONTRACT = 'mad4b.vps-broker-diagnostics.v1';
    const PAGE = 'mad4b-vps-broker-diagnostics';
    const COMPANY = 'wayfatours';
    const LIMIT = 50;
    public static function authorized(): bool {
        return class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            && MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            && current_user_can('manage_options');
    }
    private static function denial(string $code, int $status = 403) {
        return new WP_Error('mad4b_broker_diag_'.$code, 'Broker diagnostics: '.$code,
            array('status'=>$status, 'contract'=>self::CONTRACT));
    }
    private static function safe_time($epoch): ?string {
        return is_numeric($epoch) && (int)$epoch > 0
            ? gmdate('Y-m-d H:i:s', (int)$epoch).' UTC' : null;
    }
    /** Never infer VERIFIED from a signed claim, an SSH heartbeat, or WordPress job state. */
    public static function classify(array $job, $claim, $receipt = null): array {
        $native = (string)($job['state'] ?? 'MISSING_STATE');
        $claim_ok = is_array($claim)
            && ($claim['job_id']??null)===($job['job_id']??null)
            && ($claim['host_id']??null)===($job['target_host_id']??null)
            && ($claim['state']??null)==='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            && (int)($claim['claimed_epoch']??0)>0;
        $expired = (int)($job['expires_epoch']??0)>0
            && (int)$job['expires_epoch'] < time();
        $status = 'UNKNOWN';
        $reason = 'state_requires_review';
        if ($native === 'QUEUED_FOR_VPS_BROKER_CLAIM') {
            if ($claim_ok) {
                $status = 'INCONSISTENT';
                $reason = 'claim_exists_but_job_queued';
            } elseif ($expired) {
                $status = 'EXPIRED';
                $reason = 'request_expired_before_claim';
            } else {
                $status = 'QUEUED';
                $reason = 'awaiting_signed_vps_broker_claim';
            }
        } elseif ($native === 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY') {
            if ($claim_ok) {
                $status = 'CLAIMED';
                $reason = 'signed_claim_persisted_execution_not_proven';
            } else {
                $status = 'INCONSISTENT';
                $reason = 'missing_or_mismatched_original_claim';
            }
        } elseif (str_starts_with($native,'FAILED_') || in_array($native,
                array('ERROR_UNCERTAIN','CANCELLED','REVOKED','EXPIRED'),true)) {
            $status = 'FAILED';
            $reason = strtolower($native);
        }
        // Readback receipts are deliberately not available in Broker V1.
        // No execution state is promoted to VERIFIED without an independent
        // native execution receipt and a separately proven readback.
        $execution_verified = false;
        return array(
            'status'=>$status, 'reason'=>$reason, 'source_job_state'=>$native,
            'claim_record_matches'=>$claim_ok,
            'signed_claim_was_accepted_by_original_broker'=>$claim_ok,
            'shell_execution_verified'=>$execution_verified,
            'independent_readback_verified'=>false,
            'host_execution_receipt_present'=>is_array($receipt),
            'completion_verified'=>false,
            'company_production_mutation_claimed'=>false
        );
    }
    /** Attested original host events are evidence of claim, never execution. */
    public static function host_evidence(string $id): array {
        $snapshot=get_option('mad4b_vps_broker_host_journal_v1',array());
        if (!is_array($snapshot)
            || empty($snapshot['signature_verified'])
            || ($snapshot['host_id']??'')!==(string)get_option(
                MAD4B_Central_VPS_Broker_V1::HOST_ID,''))
            return array('journal_verified'=>false,'events'=>array(),
                'host_claim_evidence'=>false,'shell_execution_verified'=>false);
        $matched=array();
        foreach ((array)($snapshot['events']??array()) as $event) {
            if (!is_array($event) || ($event['job_id']??'')!==$id
                || ($event['company']??'')!==self::COMPANY) continue;
            if (!in_array($event['event']??'',array(
              'CLAIM_ATTEMPTED','CLAIM_VERIFIED_NOT_EXECUTED','CLAIM_RESULT_UNCERTAIN'),true))
              continue;
            $matched[]=array(
              'event'=>(string)$event['event'],
              'at'=>(string)($event['at']??''));
        }
        $verified_claim=count(array_filter($matched,
          static fn($row)=>$row['event']==='CLAIM_VERIFIED_NOT_EXECUTED'))>0;
        return array('journal_verified'=>true,
          'signed_snapshot_at'=>self::safe_time($snapshot['verified_at']??null),
          'events'=>$matched,'host_claim_evidence'=>$verified_claim,
          'shell_execution_verified'=>false);
    }

    public static function detail(string $id) {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id))
            return self::denial('invalid_job_id',422);
        if (!class_exists('MAD4B_Central_VPS_Broker_V1',false))
            return self::denial('broker_module_missing',503);
        $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id, false);
        if (!is_array($job) || ($job['job_id']??null)!==$id
            || ($job['company']??'')!==self::COMPANY)
            return self::denial('job_not_found',404);
        $claim=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id, false);
        $state=self::classify($job,$claim);
        $host=self::host_evidence($id);
        return array(
            'job_id'=>$id, 'company'=>self::COMPANY,
            'environment'=>(string)($job['environment']??''),
            'site_uuid'=>(string)($job['site_uuid']??''),
            'host_id'=>(string)($job['target_host_id']??''),
            'operation'=>(string)($job['operation']??''),
            'grant_mode'=>(string)($job['grant_mode']??''),
            'created_at'=>self::safe_time($job['created_epoch']??null),
            'expires_at'=>self::safe_time($job['expires_epoch']??null),
            'claimed_at'=>is_array($claim)
                ? self::safe_time($claim['claimed_epoch']??null):null,
            'command_sha256'=>(string)($job['command_sha256']??''),
            'correlation_id'=>(string)($job['correlation_id']??''),
            'host_journal'=>$host,
            'claim_cross_checked_with_host'=>!empty($state['claim_record_matches'])
                && !empty($host['host_claim_evidence']),
            ...$state
        );
    }
    public static function list_jobs(): array {
        global $wpdb;
        if (!class_exists('MAD4B_Central_VPS_Broker_V1',false)) return array();
        $prefix=MAD4B_Central_VPS_Broker_V1::JOB;
        $match=$wpdb->esc_like($prefix).'%';
        $names=$wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100",
            $match));
        $rows=array();
        foreach ((array)$names as $name) {
            if (!is_string($name) || !str_starts_with($name,$prefix))continue;
            $id=substr($name,strlen($prefix));
            if (!preg_match('/^[a-f0-9]{32}$/D',$id))continue;
            $row=self::detail($id);
            if (is_wp_error($row))continue;
            $rows[]=$row;
            if (count($rows)>=self::LIMIT)break;
        }
        return $rows;
    }
    public static function snapshot(): array {
        $h=get_option(MAD4B_Central_VPS_Broker_V1::HEARTBEAT, array());
        $fresh=is_array($h) && !empty($h['verified'])
            && (int)($h['at']??0)>time()-600;
        $journal=get_option('mad4b_vps_broker_host_journal_v1',array());
        $has_journal=is_array($journal)&&!empty($journal['signature_verified'])
          && ($journal['host_id']??'')===(string)get_option(MAD4B_Central_VPS_Broker_V1::HOST_ID,'');
        $rows=self::list_jobs(); $counts=array();
        foreach ($rows as $row) {
            $s=$row['status'];$counts[$s]=($counts[$s]??0)+1;
        }
        return array(
            'contract'=>self::CONTRACT,
            'source'=>'original_wordpress_job_and_claim_options',
            'host_journal_ingested'=>$has_journal,
            'host_journal_event_count'=>$has_journal?count($journal['events']??array()):0,
            'host_journal_note'=>$has_journal?'Signed VPS event snapshot ingested; Claim events are not execution receipts.':'No signed VPS event snapshot has been received.',
            'read_only'=>true,
            'host_id'=>(string)get_option(MAD4B_Central_VPS_Broker_V1::HOST_ID,''),
            'signed_heartbeat_fresh'=>$fresh,
            'signed_heartbeat_at'=>self::safe_time(is_array($h)?($h['at']??null):null),
            'counts'=>$counts,'jobs'=>$rows,'total_displayed'=>count($rows),
            'production_mutation_performed_by_diagnostics'=>false
        );
    }
    public static function rest(): void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central()) return;
        register_rest_route('mad4b/v1','/vps-broker-diagnostics',array(
            'methods'=>'GET',
            'permission_callback'=>array(__CLASS__,'authorized'),
            'callback'=>static function(){return rest_ensure_response(self::snapshot());}
        ));
        register_rest_route('mad4b/v1','/vps-broker-diagnostics/(?P<job_id>[a-f0-9]{32})',array(
            'methods'=>'GET',
            'permission_callback'=>array(__CLASS__,'authorized'),
            'callback'=>static function(WP_REST_Request $r) {
                $row=self::detail((string)$r['job_id']);
                return is_wp_error($row)?$row:rest_ensure_response($row);
            }
        ));
    }
    public static function menu():void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central())return;
        add_management_page('MAD4B VPS Broker Diagnostics',
            'MAD4B VPS Broker Diagnostics', 'manage_options',
            self::PAGE,array(__CLASS__,'render'));
    }
    public static function render():void {
        if (!self::authorized())wp_die('Access denied',403);
        $snapshot=self::snapshot();
        echo '<div class="wrap"><h1>MAD4B VPS Broker Diagnostics</h1>';
        echo '<p>Read-only status from the original WordPress job and claim records. No shell execution or privilege grants.</p>';
        echo '<p>Host: <strong>'.esc_html($snapshot['host_id']).'</strong> · Signed heartbeat: <strong>'.
            esc_html($snapshot['signed_heartbeat_fresh']?'verified':'stale/unavailable').'</strong> · Last: '.
            esc_html((string)$snapshot['signed_heartbeat_at']).'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('tools.php?page='.self::PAGE)).'">Refresh status</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('tools.php?page=mad4b-broker-canary')).'">Dispatch diagnostic</a></p>';
        echo '<p><strong>Important:</strong> CLAIMED proves only signed broker claim; VERIFIED requires a separate host execution receipt and independent readback. Signed VPS events: '.esc_html($snapshot['host_journal_ingested']?'verified':'not yet synced').'.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Job ID</th><th>Status</th><th>Original state</th><th>Host</th><th>Created</th><th>Claimed</th><th>VPS Evidence</th><th>Reason</th></tr></thead><tbody>';
        foreach($snapshot['jobs'] as $job){
            echo '<tr><td><code>'.esc_html($job['job_id']).'</code></td>'.
                '<td><strong>'.esc_html($job['status']).'</strong></td>'.
                '<td>'.esc_html($job['source_job_state']).'</td>'.
                '<td>'.esc_html($job['host_id']).'</td>'.
                '<td>'.esc_html((string)$job['created_at']).'</td>'.
                '<td>'.esc_html((string)$job['claimed_at']).'</td>'.
                '<td>'.esc_html(!empty($job['claim_cross_checked_with_host'])?'Signed host Claim confirmed':'Not cross-checked').'</td>'.
                '<td>'.esc_html($job['reason']).'</td></tr>';
        }
        if(!$snapshot['jobs'])echo '<tr><td colspan="8">No Broker jobs recorded.</td></tr>';
        echo '</tbody></table></div>';
    }
    public static function boot():void {
        add_action('rest_api_init',array(__CLASS__,'rest'),95);
        add_action('admin_menu',array(__CLASS__,'menu'),79);
    }
}
MAD4B_VPS_Broker_Diagnostics_V1::boot();
