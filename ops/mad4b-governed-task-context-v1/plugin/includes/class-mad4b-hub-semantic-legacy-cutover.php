<?php
defined('ABSPATH') || exit;

/**
 * Snapshot-only continuity for tasks admitted BEFORE Single Flight enforcement.
 * No idempotency or new authority is imputed to a legacy task.
 */
final class MAD4B_Hub_Semantic_Legacy_Cutover {
    const CONTRACT = 'mad4b.hub.semantic-legacy-cutover.v1';
    const STATES = ['QUEUED','RETRY','WAITING_EXECUTOR','WAITING_EXTERNAL','RUNNING',
        'CLAIMED','NEEDS_RECONCILIATION','CANCEL_REQUESTED'];
    static function table() {
        global $wpdb;
        return $wpdb->prefix.'mad4b_hub_semantic_legacy';
    }
    static function locked() {
        return get_option('mad4b_hub_semantic_legacy_cutover_locked', '') === '1';
    }
    static function hash_row(array $row) {
        $keys = ['task_id','batch_id','task_key','content_job_id','target_ref',
            'stage','operation','dependencies_json','payload_json','created_at'];
        $identity = [];
        foreach($keys as $key) {
            if (!array_key_exists($key,$row)) return null;
            $identity[$key] = (string)$row[$key];
        }
        return hash('sha256', wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    static function schema() {
        global $wpdb;
        $t = self::table();
        $engine=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t));
        $unique=$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME=%s AND INDEX_NAME=%s AND NON_UNIQUE=0',$t,'PRIMARY'));
        return strtoupper((string)$engine)==='INNODB' && (int)$unique>=1;
    }
    static function install() {
        if (!current_user_can('manage_options') || wp_get_environment_type() !== 'staging'
            || rtrim(home_url(), '/') !== 'https://tours.dev.mad4b.com')
            return new WP_Error('mad4b_legacy_install_denied','Original staging administrator required');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        global $wpdb;
        $t = self::table();
        $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            task_id char(36) NOT NULL,
            batch_id char(36) NOT NULL,
            identity_sha256 char(64) NOT NULL,
            captured_at datetime NOT NULL,
            PRIMARY KEY  (task_id)
        ) ENGINE=InnoDB $collate;");
        return self::schema();
    }
    static function snapshot($apply=false,$expected_count=null,$expected_digest=null) {
        if (wp_get_environment_type() !== 'staging'
            || rtrim(home_url(), '/') !== 'https://tours.dev.mad4b.com')
            return new WP_Error('mad4b_legacy_scope_invalid');
        if ($apply && (!current_user_can('manage_options')
            || getenv('MAD4B_HUB_SEMANTIC_FLIGHT_MODE') === 'enforce'
            || self::locked() || !self::schema()))
            return new WP_Error('mad4b_legacy_cutover_denied');
        global $wpdb;
        $tasks=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        $where="'".implode("','",self::STATES)."'";
        $rows=$wpdb->get_results("SELECT task_id,batch_id,task_key,content_job_id,
            target_ref,stage,operation,dependencies_json,payload_json,created_at
            FROM $tasks WHERE state IN ($where) ORDER BY task_id ASC",ARRAY_A);
        if (!is_array($rows)) return new WP_Error('mad4b_legacy_snapshot_unavailable');
        $map=[];
        foreach($rows as $row) {
            $fingerprint=self::hash_row($row);
            if ($fingerprint===null) return new WP_Error('mad4b_legacy_identity_incomplete');
            $map[$row['task_id']]=$fingerprint;
        }
        $digest=hash('sha256',wp_json_encode($map,JSON_UNESCAPED_SLASHES));
        $base=['contract'=>self::CONTRACT,'task_count'=>count($map),
            'snapshot_sha256'=>$digest,'applied'=>false,'new_permission_granted'=>false,
            'deduplication_inferred'=>false];
        if (!$apply) return $base;
        if (!is_int($expected_count) || !is_string($expected_digest)
            || $expected_count!==count($map) || !hash_equals($digest,$expected_digest))
            return new WP_Error('mad4b_legacy_snapshot_drift');
        $table=self::table();
        if ($wpdb->query('START TRANSACTION')===false)
            return new WP_Error('mad4b_legacy_transaction_unavailable');
        try {
            if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $table FOR UPDATE") !== 0)
                throw new RuntimeException('LEGACY_ALLOWLIST_NOT_EMPTY');
            foreach($map as $id=>$fingerprint) {
                $batch_id=(string)$rows[array_search($id,array_column($rows,'task_id'),true)]['batch_id'];
                if($wpdb->insert($table,['task_id'=>$id,'batch_id'=>$batch_id,
                    'identity_sha256'=>$fingerprint,'captured_at'=>gmdate('Y-m-d H:i:s')])!==1)
                    throw new RuntimeException('LEGACY_ALLOWLIST_INSERT_FAILED');
            }
            if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")!==count($map))
                throw new RuntimeException('LEGACY_ALLOWLIST_COUNT_MISMATCH');
            if($wpdb->query('COMMIT')===false)
                throw new RuntimeException('LEGACY_ALLOWLIST_COMMIT_UNCERTAIN');
        } catch(Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mad4b_legacy_capture_failed',$e->getMessage());
        }
        update_option('mad4b_hub_semantic_legacy_cutover_locked','1',false);
        $base['applied']=self::locked();
        return $base;
    }
    static function claim_allowed(array $row) {
        if (!self::locked() || !self::schema()) return false;
        $hash=self::hash_row($row);
        if($hash===null) return false;
        global $wpdb;
        $table=self::table();
        $saved=$wpdb->get_row($wpdb->prepare("SELECT batch_id,identity_sha256
            FROM $table WHERE task_id=%s LIMIT 1", $row['task_id']),ARRAY_A);
        return is_array($saved)
            && hash_equals((string)$saved['batch_id'],(string)$row['batch_id'])
            && hash_equals((string)$saved['identity_sha256'],$hash);
    }
}
