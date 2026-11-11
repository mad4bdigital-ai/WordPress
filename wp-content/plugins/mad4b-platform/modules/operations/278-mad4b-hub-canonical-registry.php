<?php
/**
 * Plugin Name: MAD4B Canonical Source Registry (Staging)
 * Description: Persistent versioned private source-entity registry and dedup decision ledger. Never publishes or distributes.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Canonical_Registry_V1 {
    const VERSION = '0.1.0';
    const OPTION = 'mad4b_hub_canonical_registry_schema';
    const ALLOWED_TYPES = ['tours-and-activities', 'properties', 'transportations'];

    public static function site(): bool {
        return wp_get_environment_type() === 'staging' &&
            strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com';
    }
    public static function admin(): bool {
        return self::site() && current_user_can('manage_options');
    }
    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'mad4b_hub_' . $name;
    }
    private static function fail($code, $status=409) {
        return new WP_Error('canonical_'.$code, 'Canonical registry operation refused', ['status'=>$status]);
    }
    private static function ready(): bool {
        return get_option(self::OPTION) === self::VERSION;
    }
    public static function install() {
        if (!self::admin()) return self::fail('forbidden',403);
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $entities = self::table('canonical_entities');
        $versions = self::table('canonical_versions');
        $reviews = self::table('canonical_reviews');
        dbDelta("CREATE TABLE $entities (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_key char(64) NOT NULL,
            source_hub_id bigint(20) unsigned NOT NULL,
            company_id varchar(90) NOT NULL,
            source_type varchar(65) NOT NULL,
            language varchar(12) NOT NULL,
            state varchar(30) NOT NULL,
            version bigint(20) unsigned NOT NULL,
            snapshot_hash char(64) NOT NULL,
            source_fingerprint char(64) NOT NULL,
            snapshot_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_key (entity_key),
            UNIQUE KEY source_hub_id (source_hub_id),
            KEY company_state (company_id,state),
            KEY kind_language (source_type,language)
        ) $charset;");
        dbDelta("CREATE TABLE $versions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_id bigint(20) unsigned NOT NULL,
            version bigint(20) unsigned NOT NULL,
            action varchar(36) NOT NULL,
            snapshot_hash char(64) NOT NULL,
            snapshot_json longtext NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_version (entity_id,version)
        ) $charset;");
        dbDelta("CREATE TABLE $reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pair_key char(64) NOT NULL,
            left_hub_id bigint(20) unsigned NOT NULL,
            right_hub_id bigint(20) unsigned NOT NULL,
            company_id varchar(90) NOT NULL,
            source_type varchar(65) NOT NULL,
            language varchar(12) NOT NULL,
            identity_state varchar(45) NOT NULL,
            decision varchar(35) NOT NULL,
            evidence_json longtext NOT NULL,
            note text NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY pair_key (pair_key),
            KEY company_decision (company_id,decision),
            KEY left_hub_id (left_hub_id),
            KEY right_hub_id (right_hub_id)
        ) $charset;");
        foreach ([$entities,$versions,$reviews] as $table) {
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
            if ($exists !== $table) return self::fail('schema_creation');
        }
        update_option(self::OPTION, self::VERSION, false);
        return ['schema'=>self::VERSION,'tables'=>3,'private_only'=>true];
    }
    public static function sync_reviews($company='tourism-almallahgroup') {
        if (!self::admin() || !self::ready()) return self::fail('forbidden_or_uninstalled',403);
        if (!class_exists('MAD4B_Hub_Dedup_Intelligence_V1')) return self::fail('dedup_unavailable');
        $scan=MAD4B_Hub_Dedup_Intelligence_V1::discover(['company_id'=>$company]);
        if (is_wp_error($scan)) return $scan;
        $typed=$scan['specialists']['typed_evidence_v1']??[];
        if (empty($typed['scan_complete']) || !empty($typed['scan_truncated']) || !isset($typed['review_candidates'])) return self::fail('incomplete_scan');
        global $wpdb;
        $table=self::table('canonical_reviews');
        $now=gmdate('Y-m-d H:i:s');
        $added=0;$preserved=0;$holds=0;
        foreach ($typed['review_candidates'] as $candidate) {
            $ids=array_map('intval',$candidate['hub_ids']??[]);
            if (count($ids)!==2 || $ids[0]<1 || $ids[1]<1 || $ids[0]===$ids[1]) return self::fail('invalid_review_pair');
            sort($ids,SORT_NUMERIC);
            $owner=(string)($candidate['company_id']??'');
            $type=(string)($candidate['source_type']??'');
            $lang=(string)($candidate['language']??'');
            if ($owner!==$company || !in_array($type,self::ALLOWED_TYPES,true) || !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$lang))
                return self::fail('review_scope');
            $fusion=$candidate['field_fusion']??[];
            $identity=(string)($fusion['entity_resolution_state']??'identity_unverified_hold');
            $pairKey=hash('sha256',implode('|',[$owner,$type,$lang,$ids[0],$ids[1]]));
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE pair_key=%s",$pairKey));
            if ($existing) {$preserved++; continue;}
            $evidence=[
                'identity_conflict_fields'=>$fusion['identity_conflict_fields']??[],
                'identity_unverified_fields'=>$fusion['identity_unverified_fields']??[],
                'source_ids'=>$candidate['source_post_ids']??[],
                'similarity_is_not_identity'=>true,
                'requires_verified_identity'=>true
            ];
            $status=$identity==='identity_conflict_hold'?'identity_conflict_hold':
                ($identity==='identity_unverified_hold'?'identity_unverified_hold':'needs_evidence');
            if ($status!=='needs_evidence')$holds++;
            $ok=$wpdb->insert($table,[
                'pair_key'=>$pairKey,'left_hub_id'=>$ids[0],'right_hub_id'=>$ids[1],
                'company_id'=>$owner,'source_type'=>$type,'language'=>$lang,
                'identity_state'=>$identity,'decision'=>$status,
                'evidence_json'=>wp_json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'note'=>'','actor_id'=>0,'created_at'=>$now,'updated_at'=>$now,
            ]);
            if (!$ok) return self::fail('review_insert');
            $added++;
        }
        return ['review_candidates'=>count($typed['review_candidates']),'added'=>$added,'preserved'=>$preserved,
            'identity_holds_inserted'=>$holds,'auto_merge'=>false];
    }
    private static function validated_snapshot($id,$company) {
        $post=get_post($id);
        if (!$post || $post->post_type!=='mad4b_hub_content' || $post->post_status!=='private') return null;
        $owner=(string)get_post_meta($id,'_mad4b_hub_import_owner',true);
        $provider=(string)get_post_meta($id,'_mad4b_hub_import_provider_key',true);
        $dataset=(string)get_post_meta($id,'_mad4b_hub_import_dataset_key',true);
        $type=(string)get_post_meta($id,'_mad4b_hub_import_type',true);
        $language=(string)get_post_meta($id,'_mad4b_hub_import_language',true);
        $sourceId=(int)get_post_meta($id,'_mad4b_hub_import_source_id',true);
        if ($owner!==$company || !$provider || !$dataset ||
            !in_array($type,self::ALLOWED_TYPES,true) ||
            !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$language) ||
            $sourceId<1 || get_post_meta($id,'_mad4b_hub_import_reference_only',true)!=='yes' ||
            get_post_meta($id,'_mad4b_hub_import_may_publish',true)!=='no' ||
            get_post_meta($id,'_mad4b_hub_import_may_checkout',true)!=='no') return null;
        global $wpdb;
        $reviews=self::table('canonical_reviews');
        $blockCount=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $reviews WHERE (left_hub_id=%d OR right_hub_id=%d) AND decision NOT IN ('distinct_verified')",
            $id,$id
        ));
        // Only keys in the latest complete authorized Source Snapshot may enter preview.
        // Stale/tombstoned source posts remain versioned but are withdrawn from selection.
        static $activeKeyIndex=[];
        if(!isset($activeKeyIndex[$owner])){
            $active=get_option(MAD4B_Hub_Source_Import_V1::ACTIVE,[]);
            $activeKeyIndex[$owner]=is_array($active) && ($active['source_company']??'')===$owner &&
                is_array($active['keys']??null)?array_fill_keys($active['keys'],true):[];
        }
        $sourceKey=$owner.':'.$type.':'.$language.':'.$sourceId;
        $activeSource=isset($activeKeyIndex[$owner][$sourceKey]);
        $fingerprint=(string)get_post_meta($id,'_mad4b_hub_import_item_hash',true);
        $fields=[
            'title'=>$post->post_title,'description'=>$post->post_content,'summary'=>$post->post_excerpt,
            'source_url'=>get_post_meta($id,'_mad4b_hub_import_source_url',true),
            'source_slug'=>get_post_meta($id,'_mad4b_hub_import_source_slug',true),
            'identity_facts'=>get_post_meta($id,'_mad4b_hub_import_identity_facts',true),
            'source_metadata'=>get_post_meta($id,'_mad4b_hub_import_source_metadata',true),
            'taxonomy_refs'=>get_post_meta($id,'_mad4b_hub_import_taxonomy_refs',true),
            'translation_trid'=>get_post_meta($id,'_mad4b_hub_import_translation_trid',true),
        ];
        $snapshot=[
            'contract'=>'mad4b.hub.private-canonical-source.v1',
            'company_id'=>$owner,'provider'=>$provider,'dataset'=>$dataset,
            'source_type'=>$type,'language'=>$language,
            'source_id'=>$sourceId,'hub_post_id'=>$id,
            'source_fingerprint'=>$fingerprint,
            'fields'=>$fields,'field_provenance'=>'source_hub_post',
            'rights'=>['reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,'may_distribute'=>false],
            'identity_review_hold'=>$blockCount>0,
            'commerce_eligible'=>false,
        ];
        if(!$activeSource)$snapshot['source_withdrawn']=true;
        return ['key'=>hash('sha256',implode('|',[$owner,$provider,$dataset,$type,$language,$sourceId])),
            'state'=>!$activeSource?'withdrawn':($blockCount?'review_hold':'private_preview'),
            'fingerprint'=>strlen($fingerprint)===64?$fingerprint:hash('sha256',wp_json_encode($fields)),
            'snapshot'=>$snapshot,
            'owner'=>$owner,'type'=>$type,'lang'=>$language];
    }
    public static function sync_sources($company='tourism-almallahgroup',$offset=0,$limit=150) {
        if (!self::admin() || !self::ready()) return self::fail('forbidden_or_uninstalled',403);
        if (!is_string($company)||!preg_match('/^[a-z0-9-]{3,70}$/',$company) ||
            !is_int($offset)||$offset<0 || !is_int($limit)||$limit<1||$limit>200) return self::fail('invalid_scope');
        if (class_exists('MAD4B_Hub_Auto_Distribute_V1') && MAD4B_Hub_Auto_Distribute_V1::settings()['enabled']) return self::fail('distribution_must_be_disabled');
        global $wpdb;
        $ids=get_posts(['post_type'=>'mad4b_hub_content','post_status'=>'private','fields'=>'ids',
            'numberposts'=>$limit,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
        $entities=self::table('canonical_entities');
        $versions=self::table('canonical_versions');
        $now=gmdate('Y-m-d H:i:s');
        $created=0;$updated=0;$unchanged=0;$held=0;$skipped=0;
        $lock='mad4b_canonical_sync_'.md5($company);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1) return self::fail('busy');
        try {
            $wpdb->query('START TRANSACTION');
            foreach ($ids as $id) {
                $id=(int)$id;
                $item=self::validated_snapshot($id,$company);
                if (!$item) {$skipped++;continue;}
                $raw=wp_json_encode($item['snapshot'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if (!$raw) throw new RuntimeException('snapshot_encoding');
                $hash=hash('sha256',$raw);
                $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM $entities WHERE entity_key=%s",$item['key']),ARRAY_A);
                if ($record && (int)$record['source_hub_id']!==$id) throw new RuntimeException('source_identity_collision');
                if ($item['state']==='review_hold')$held++;
                if ($record && $record['snapshot_hash']===$hash && $record['state']===$item['state']) {$unchanged++;continue;}
                $version=$record?(int)$record['version']+1:1;
                $data=[
                    'entity_key'=>$item['key'],'source_hub_id'=>$id,'company_id'=>$company,
                    'source_type'=>$item['type'],'language'=>$item['lang'],'state'=>$item['state'],
                    'version'=>$version,'snapshot_hash'=>$hash,'source_fingerprint'=>$item['fingerprint'],
                    'snapshot_json'=>$raw,'updated_at'=>$now,
                ];
                if ($record) {
                    $ok=$wpdb->update($entities,$data,['id'=>(int)$record['id']]);
                    if ($ok===false)throw new RuntimeException('entity_update');
                    $eid=(int)$record['id'];$updated++;
                } else {
                    $data['created_at']=$now;
                    $ok=$wpdb->insert($entities,$data);
                    if (!$ok)throw new RuntimeException('entity_insert');
                    $eid=(int)$wpdb->insert_id;$created++;
                }
                $ok=$wpdb->insert($versions,[
                    'entity_id'=>$eid,'version'=>$version,'action'=>$record?'source_refresh':'source_materialized',
                    'snapshot_hash'=>$hash,'snapshot_json'=>$raw,'actor_id'=>get_current_user_id(),'created_at'=>$now
                ]);
                if (!$ok)throw new RuntimeException('version_insert');
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::fail('transaction_'.$e->getMessage(),503);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
        return ['offset'=>$offset,'processed'=>count($ids),'created'=>$created,'updated'=>$updated,
            'unchanged'=>$unchanged,'held'=>$held,'skipped'=>$skipped,'next_offset'=>$offset+count($ids),
            'complete'=>count($ids)<$limit,'auto_distribute'=>false,'commerce_eligible'=>false];
    }
    public static function reviews($company='tourism-almallahgroup',$offset=0,$limit=30) {
        if(!self::admin()||!self::ready())return self::fail('forbidden_or_uninstalled',403);
        if(!is_string($company)||!preg_match('/^[a-z0-9-]{3,70}$/D',$company) ||
           !is_int($offset)||$offset<0||!is_int($limit)||$limit<1||$limit>100)
            return self::fail('invalid_review_scope');
        global $wpdb;
        $t=self::table('canonical_reviews');
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE company_id=%s",$company));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,left_hub_id,right_hub_id,source_type,language,identity_state,decision,evidence_json,note,updated_at
             FROM $t WHERE company_id=%s ORDER BY id ASC LIMIT %d OFFSET %d",
            $company,$limit,$offset),ARRAY_A);
        $items=[];
        foreach($rows as $r){
            $e=json_decode($r['evidence_json'],true);
            $items[]=['review_id'=>(int)$r['id'],
               'hub_ids'=>[(int)$r['left_hub_id'],(int)$r['right_hub_id']],
               'source_type'=>$r['source_type'],'language'=>$r['language'],
               'identity_state'=>$r['identity_state'],'decision'=>$r['decision'],
               'identity_conflict_fields'=>$e['identity_conflict_fields']??[],
               'identity_unverified_fields'=>$e['identity_unverified_fields']??[],
               'source_ids'=>$e['source_ids']??[],
               'note'=>$r['note'],'updated_at'=>$r['updated_at'],
               'auto_merge'=>false];
        }
        return ['contract'=>'mad4b.hub.canonical-review-queue.v1',
            'company_id'=>$company,'total'=>$count,'offset'=>$offset,'items'=>$items,
            'review_required'=>true,'auto_merge'=>false];
    }
    public static function review_decision($pairId,$decision,$note) {
        if (!self::admin() || !self::ready()) return self::fail('forbidden_or_uninstalled',403);
        if (!is_int($pairId)||$pairId<1 || !in_array($decision,['distinct_verified','hold_manual'],true) ||
            !is_string($note)||mb_strlen(trim($note))<15||mb_strlen($note)>1000) return self::fail('invalid_review');
        global $wpdb;
        $table=self::table('canonical_reviews');
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$pairId),ARRAY_A);
        if (!$r)return self::fail('missing_review',404);
        if ($r['decision']==='identity_conflict_hold' && $decision==='distinct_verified') {
            // Explicitly verified different entities is safe, but must explain the distinct identities.
            if (mb_strlen(trim($note))<30) return self::fail('conflict_requires_detail');
        }
        foreach (['left_hub_id','right_hub_id'] as $k) {
            if (get_post_meta((int)$r[$k],'_mad4b_hub_import_owner',true)!==$r['company_id']) return self::fail('stale_scope');
        }
        $ok=$wpdb->update($table,['decision'=>$decision,'note'=>sanitize_textarea_field($note),
            'actor_id'=>get_current_user_id(),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$pairId]);
        if ($ok===false) return self::fail('review_write');
        return ['review_id'=>$pairId,'decision'=>$decision,'requires_source_resync'=>true,
            'merged'=>false,'auto_distribute'=>false];
    }
    public static function catalog($company='tourism-almallahgroup',$page=1,$limit=40) {
        if (!self::admin() || !self::ready()) return self::fail('forbidden_or_uninstalled',403);
        if (!is_string($company)||!preg_match('/^[a-z0-9-]{3,70}$/',$company) ||
            !is_int($page)||$page<1||!is_int($limit)||$limit<1||$limit>100) return self::fail('scope');
        global $wpdb;
        $table=self::table('canonical_entities');
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE company_id=%s AND state='private_preview'",$company));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,source_hub_id,source_type,language,version,snapshot_hash,snapshot_json FROM $table WHERE company_id=%s AND state='private_preview' ORDER BY id ASC LIMIT %d OFFSET %d",
            $company,$limit,($page-1)*$limit),ARRAY_A);
        $items=[];
        foreach ($rows as $r) {
            $s=json_decode($r['snapshot_json'],true);
            if (!is_array($s)||!empty($s['commerce_eligible'])||empty($s['rights']['reference_only']) ||
                !empty($s['rights']['may_distribute']))return self::fail('unsafe_catalog');
            $items[]=['canonical_id'=>(int)$r['id'],'source_hub_id'=>(int)$r['source_hub_id'],
                'type'=>$r['source_type'],'language'=>$r['language'],'version'=>(int)$r['version'],
                'title'=>$s['fields']['title']??'','snapshot_sha256'=>$r['snapshot_hash'],
                'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false];
        }
        return ['contract'=>'mad4b.hub.canonical-catalog-preview.v1','total'=>$total,'page'=>$page,
            'items'=>$items,'company_id'=>$company,'private_only'=>true,'distribution_enabled'=>false];
    }
    public static function status() {
        if (!self::admin()) return self::fail('forbidden',403);
        if (!self::ready()) return ['installed'=>false,'schema'=>get_option(self::OPTION,false)];
        global $wpdb;
        $entities=self::table('canonical_entities');
        $reviews=self::table('canonical_reviews');
        $versions=self::table('canonical_versions');
        return ['installed'=>true,'schema'=>self::VERSION,
            'entity_states'=>$wpdb->get_results("SELECT state,COUNT(*) n FROM $entities GROUP BY state",ARRAY_A),
            'review_states'=>$wpdb->get_results("SELECT decision,COUNT(*) n FROM $reviews GROUP BY decision",ARRAY_A),
            'versions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $versions"),
            'auto_distribute'=>class_exists('MAD4B_Hub_Auto_Distribute_V1')?MAD4B_Hub_Auto_Distribute_V1::settings()['enabled']:null,
            'production_eligible'=>false];
    }
    public static function rest() {
        if (!self::site()) return;
        register_rest_route('mad4b-central/v1','/canonical/status',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function(){return rest_ensure_response(self::status());}
        ]);
        register_rest_route('mad4b-central/v1','/canonical/reviews',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $v=self::reviews((string)($r->get_param('company_id')??'tourism-almallahgroup'),
                    max(0,(int)($r->get_param('offset')??0)),
                    min(100,max(1,(int)($r->get_param('limit')??30))));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
        register_rest_route('mad4b-central/v1','/canonical/review-decision',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $v=self::review_decision((int)$r->get_param('review_id'),
                  (string)$r->get_param('decision'),(string)$r->get_param('note'));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
        register_rest_route('mad4b-central/v1','/canonical/catalog-preview',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($req){
                $r=self::catalog((string)($req->get_param('company_id')??'tourism-almallahgroup'),
                    max(1,(int)($req->get_param('page')??1)),min(100,max(1,(int)($req->get_param('limit')??40))));
                return is_wp_error($r)?$r:rest_ensure_response($r);
            }
        ]);
    }
    public static function admin_page() {
        if (!self::admin())return;
        $s=self::status();
        echo '<div class="wrap"><h1>MAD4B Canonical Source Registry</h1>';
        echo '<p>Private reference catalog only. Approval, pricing, publishing and checkout remain separate.</p>';
        echo '<pre>'.esc_html(wp_json_encode($s,JSON_PRETTY_PRINT)).'</pre>';
        $queue=self::reviews('tourism-almallahgroup',0,30);
        if(!is_wp_error($queue)){
            echo '<h2>Dedup Review Queue (first 30)</h2><table class="widefat striped"><thead><tr><th>Review</th><th>Source IDs</th><th>Type</th><th>Decision</th><th>Evidence</th></tr></thead><tbody>';
            foreach($queue['items'] as $item){
                echo '<tr><td>'.(int)$item['review_id'].'</td><td>'.esc_html(implode(' / ',$item['source_ids'])).'</td><td>'.esc_html($item['source_type']).'</td><td>'.esc_html($item['decision']).'</td><td>'.esc_html(implode(', ',array_merge($item['identity_conflict_fields'],$item['identity_unverified_fields']))).'</td></tr>';
            }
            echo '</tbody></table><p>Review decisions are admin-only. A decision never auto-merges or publishes; refresh Canonical after an approved distinct-entity review.</p>';
        }
        echo '</div>';
    }
    public static function menu() {
        if (!self::site())return;
        add_submenu_page('edit.php?post_type=mad4b_hub_nile','Canonical Source Registry',
            'Canonical Registry','manage_options','mad4b-canonical-registry',[__CLASS__,'admin_page']);
    }
    public static function boot() {
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('admin_menu',[__CLASS__,'menu']);
    }
}
MAD4B_Hub_Canonical_Registry_V1::boot();

