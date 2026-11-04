<?php
defined('ABSPATH') || exit;

/** First central-only canonical candidate store. Research is never publication. */
final class MAD4B_Hub_Canonical_Store {
    const CONTRACT = 'mad4b.hub.canonical-candidate.v1';

    public static function tables() {
        global $wpdb;
        return array(
            'records' => $wpdb->prefix . 'mad4b_hub_content_records',
            'versions' => $wpdb->prefix . 'mad4b_hub_content_versions',
            'runs' => $wpdb->prefix . 'mad4b_hub_ingestion_runs',
        );
    }

    public static function permitted() {
        return class_exists('MAD4B_Content_Hub')
            && MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_mutate();
    }

    public static function install() {
        if (!self::permitted()) return new WP_Error('mad4b_hub_actor_denied','Authenticated central Staging administrator required.');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $tables = self::tables();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$tables['records']} (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          record_key char(64) NOT NULL,
          site_uuid char(36) NOT NULL,
          brand_id varchar(64) NOT NULL,
          source_url varchar(1000) NOT NULL,
          locale varchar(12) NOT NULL,
          status varchar(32) NOT NULL DEFAULT 'research_candidate',
          current_version_sha256 char(64) NOT NULL DEFAULT '',
          revision bigint(20) unsigned NOT NULL DEFAULT 0,
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY record_key (record_key),
          KEY brand_status (site_uuid,brand_id,status)
        ) $collate;");
        dbDelta("CREATE TABLE {$tables['versions']} (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          record_id bigint(20) unsigned NOT NULL,
          version_sha256 char(64) NOT NULL,
          source_sha256 char(64) NOT NULL,
          job_id char(36) NOT NULL,
          provider_id varchar(64) NOT NULL,
          normalized_json longtext NOT NULL,
          provenance_json longtext NOT NULL,
          observed_at datetime NOT NULL,
          created_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY record_version (record_id,version_sha256),
          KEY job_id (job_id),
          KEY record_latest (record_id,created_at)
        ) $collate;");
        dbDelta("CREATE TABLE {$tables['runs']} (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          run_key char(64) NOT NULL,
          site_uuid char(36) NOT NULL,
          brand_id varchar(64) NOT NULL,
          job_id char(36) NOT NULL DEFAULT '',
          record_key char(64) NOT NULL DEFAULT '',
          state varchar(32) NOT NULL DEFAULT 'new',
          last_error_code varchar(100) NOT NULL DEFAULT '',
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY run_key (run_key),
          KEY job_id (job_id)
        ) $collate;");
        foreach ($tables as $name => $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)
                return new WP_Error('mad4b_hub_table_missing','Central Hub table unavailable: '.$name);
        }
        return array('contract'=>self::CONTRACT,'tables_ready'=>true,'table_count'=>3);
    }

    public static function run_key($site, $brand, $key) {
        return hash('sha256', $site.'|'.$brand.'|'.(string)$key);
    }

    public static function find_run($run_key) {
        global $wpdb;
        $t=self::tables()['runs'];
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE run_key=%s",$run_key),ARRAY_A);
    }

    public static function claim_run($run_key,$site,$brand) {
        global $wpdb;
        $t=self::tables()['runs'];
        $now=gmdate('Y-m-d H:i:s');
        if (false === $wpdb->query('START TRANSACTION')) return new WP_Error('mad4b_hub_tx_unavailable');
        try {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (run_key,site_uuid,brand_id,created_at,updated_at) VALUES (%s,%s,%s,%s,%s)",
                $run_key,$site,$brand,$now,$now));
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE run_key=%s FOR UPDATE",$run_key),ARRAY_A);
            if (!$row || $row['site_uuid']!==$site || $row['brand_id']!==$brand) throw new RuntimeException('run_scope_mismatch');
            if (in_array($row['state'],array('claimed','provider_running'),true)) {
                $wpdb->query('COMMIT');
                return array('state'=>'already_running','row'=>$row);
            }
            if ($row['state']==='persisted') {
                $wpdb->query('COMMIT');
                return array('state'=>'persisted','row'=>$row);
            }
            if ($wpdb->update($t,array('state'=>'claimed','updated_at'=>$now),array('id'=>$row['id']))===false)
                throw new RuntimeException('run_claim_failed');
            if(false === $wpdb->query('COMMIT')) throw new RuntimeException('commit_uncertain');
            return array('state'=>'claimed','row'=>$row);
        } catch(Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mad4b_hub_run_claim_failed','Could not claim central ingestion run.');
        }
    }

    public static function run_update($run_key,array $update) {
        if (!self::permitted()) return new WP_Error('mad4b_hub_actor_denied');
        global $wpdb;
        $allowed=array('job_id','record_key','state','last_error_code');
        $values=array_intersect_key($update,array_fill_keys($allowed,true));
        if (!$values) return new WP_Error('mad4b_hub_run_update_empty');
        $values['updated_at']=gmdate('Y-m-d H:i:s');
        $t=self::tables()['runs'];
        if ($wpdb->update($t,$values,array('run_key'=>$run_key))===false) return new WP_Error('mad4b_hub_run_update_failed');
        return self::find_run($run_key);
    }

    public static function upsert_evidence($job_id,array $evidence,$scope,$language='en') {
        if (!self::permitted()) return new WP_Error('mad4b_hub_actor_denied');
        if (!is_array($scope) || !isset($scope['site_uuid'],$scope['brand_ref']))
            return new WP_Error('mad4b_hub_scope_invalid');
        $site=(string)$scope['site_uuid'];
        $brand=(string)$scope['brand_ref'];
        $language=sanitize_key((string)$language);
        $url=(string)($evidence['source_url']??'');
        $markdown=(string)($evidence['markdown']??'');
        $source_sha=(string)($evidence['source_sha256']??'');
        if ($url!==home_url('/') || strlen($markdown)<10 || strlen($markdown)>60000
            || !hash_equals(hash('sha256',$markdown),$source_sha)
            || !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$language)
            || !preg_match('/^[a-f0-9-]{36}$/',$job_id))
            return new WP_Error('mad4b_hub_evidence_invalid','Source identity, SHA or bounded text invalid.');
        $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job_id));
        if (is_wp_error($job)) return $job;
        $row=$job['job']??array();
        if(($row['site_uuid']??'')!==$site || ($row['brand_id']??'')!==$brand || ($row['language']??'')!==$language)
            return new WP_Error('mad4b_hub_job_scope_mismatch');
        // Only an owned-source observation: it is not a licensed commercial copy.
        $plain=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($markdown)));
        $normalized=array(
            'content_kind'=>'research_observation',
            'title'=>get_bloginfo('name'),
            'description'=>mb_substr($plain,0,1000),
            'language'=>$language,
            'source_content_sha256'=>$source_sha,
            'quality_state'=>'not_evaluated',
            'rights_state'=>'owned_site_source_review_pending',
            'publish_authorized'=>false,
        );
        $provenance=array(
            'contract'=>self::CONTRACT,
            'provider'=>'firecrawl',
            'source_url'=>$url,'source_sha256'=>$source_sha,
            'captured_at'=>(string)($evidence['captured_at']??gmdate('c')),
            'job_id'=>$job_id,'site_uuid'=>$site,'brand_id'=>$brand,
            'research_group_uid'=>(string)($evidence['research_group_uid']??''),
            'research_record_uid'=>(string)($evidence['research_record_uid']??''),
            'evidence_state'=>'raw_observed',
            'test_only'=>true,'external_distribution_authorized'=>false,
        );
        $normalized_json=wp_json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $provenance_json=wp_json_encode($provenance,JSON_UNESCAPED_SLASHES);
        if (!$normalized_json||!$provenance_json) return new WP_Error('mad4b_hub_serialization_failed');
        $record_key=hash('sha256',$site.'|'.$brand.'|'.$language.'|'.$url);
        $version_sha=hash('sha256',$source_sha.'|'.$normalized_json.'|'.$language);
        global $wpdb;
        $t=self::tables();
        $now=gmdate('Y-m-d H:i:s');
        if (false === $wpdb->query('START TRANSACTION')) return new WP_Error('mad4b_hub_tx_unavailable');
        try{
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$t['records']} (record_key,site_uuid,brand_id,source_url,locale,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                $record_key,$site,$brand,$url,$language,$now,$now));
            $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['records']} WHERE record_key=%s FOR UPDATE",$record_key),ARRAY_A);
            if(!$record || $record['site_uuid']!==$site || $record['brand_id']!==$brand || $record['source_url']!==$url)
                throw new RuntimeException('record_binding_invalid');
            $existing=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t['versions']} WHERE record_id=%d AND version_sha256=%s",
                (int)$record['id'],$version_sha));
            if (!$existing) {
                $ok=$wpdb->insert($t['versions'],array(
                    'record_id'=>(int)$record['id'],'version_sha256'=>$version_sha,
                    'source_sha256'=>$source_sha,'job_id'=>$job_id,
                    'provider_id'=>'firecrawl','normalized_json'=>$normalized_json,
                    'provenance_json'=>$provenance_json,'observed_at'=>$now,'created_at'=>$now));
                if (!$ok) throw new RuntimeException('version_insert_failed');
                $ok=$wpdb->update($t['records'],array(
                    'current_version_sha256'=>$version_sha,
                    'revision'=>(int)$record['revision']+1,
                    'updated_at'=>$now),array('id'=>$record['id']));
                if($ok===false)throw new RuntimeException('record_update_failed');
            }
            if(false === $wpdb->query('COMMIT'))throw new RuntimeException('commit_uncertain');
            $receipt=self::read_record($record_key);
            if(is_wp_error($receipt) || ($receipt['version_sha256']??'')!==$version_sha)
                return new WP_Error('mad4b_hub_readback_uncertain','Committed record requires reconciliation.');
            $receipt['reused_version']=(bool)$existing;
            return $receipt;
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            return new WP_Error('mad4b_hub_persist_failed','Central canonical candidate could not be committed.');
        }
    }

    public static function read_record($record_key) {
        if (!class_exists('MAD4B_Content_Hub') || !MAD4B_Content_Hub::permission())
            return new WP_Error('mad4b_hub_actor_denied');
        global $wpdb;
        $t=self::tables();
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['records']} WHERE record_key=%s",$record_key),ARRAY_A);
        if(!$r)return new WP_Error('mad4b_hub_record_missing');
        $v=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['versions']} WHERE record_id=%d AND version_sha256=%s",
            (int)$r['id'],(string)$r['current_version_sha256']),ARRAY_A);
        if(!$v)return new WP_Error('mad4b_hub_record_version_missing');
        return array(
            'contract'=>self::CONTRACT,'record_key'=>$r['record_key'],
            'site_uuid'=>$r['site_uuid'],'brand_id'=>$r['brand_id'],
            'source_url'=>$r['source_url'],'locale'=>$r['locale'],
            'state'=>$r['status'],'revision'=>(int)$r['revision'],
            'version_sha256'=>$v['version_sha256'],
            'source_sha256'=>$v['source_sha256'],
            'job_id'=>$v['job_id'],
            'normalized'=>json_decode($v['normalized_json'],true),
            'provenance'=>json_decode($v['provenance_json'],true),
            'publication_authorized'=>false,'distribution_authorized'=>false,
        );
    }
}
