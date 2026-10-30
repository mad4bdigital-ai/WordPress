<?php
/**
 * Plugin Name: MAD4B Content Hub Source Import
 * Description: Private import of published source content into central Hub (default ingestion mode).
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Hub_Source_Import_V1 {
    const CPT = 'mad4b_hub_content';
    const ACTIVE = 'mad4b_hub_source_active_batch_v1';
    const RECEIPT = 'mad4b_hub_source_import_receipt_v1';
    const TYPES = ['tours-and-activities'=>'tour','properties'=>'property','transportations'=>'transportation'];
    const SOURCE = 'tourism-almallahgroup';

    public static function enabled(): bool {
        return class_exists('MAD4B_Central_Simple_Connections_V1') &&
            MAD4B_Central_Simple_Connections_V1::enabled();
    }
    public static function register(): void {
        register_post_type(self::CPT,[
            'label'=>'Content Hub Imported Sources',
            'public'=>false,'publicly_queryable'=>false,
            'show_ui'=>true,'show_in_menu'=>'edit.php?post_type=mad4b_hub_nile',
            'show_in_rest'=>false,'exclude_from_search'=>true,
            'has_archive'=>false,'rewrite'=>false,
            'supports'=>['title','editor','excerpt'],
            'capabilities'=>[
                'edit_posts'=>'manage_options','edit_others_posts'=>'manage_options',
                'publish_posts'=>'manage_options','read_private_posts'=>'manage_options',
                'edit_post'=>'manage_options','read_post'=>'manage_options',
                'delete_post'=>'manage_options','delete_posts'=>'manage_options',
                'delete_others_posts'=>'manage_options','delete_published_posts'=>'manage_options',
            ], 'map_meta_cap'=>false,
        ]);
    }
    private static function failure($reason,$code=409) {
        return new WP_Error('mad4b_hub_pull_'.$reason,'Source import blocked.',['status'=>$code]);
    }
    /** Validate complete source page before any local WordPress mutation. */
    public static function validate_item($row,$company,$type) {
        if (!is_array($row) || ($row['source_company']??'')!==$company ||
            ($row['source_post_type']??'')!==$type ||
            ($row['kind']??'')!==(self::TYPES[$type]??null) ||
            !is_int($row['source_id']??null) || $row['source_id']<1 ||
            !in_array($row['language']??null,['en','ar','fr','de','it','es'],true) ||
            !is_string($row['title']??null) || trim($row['title'])==='' ||
            strlen($row['title'])>1500 ||
            !is_string($row['summary']??null) || strlen($row['summary'])>3000 ||
            !is_string($row['body']??null) || strlen($row['body'])>50000 ||
            !is_string($row['modified_gmt']??null) ||
            !preg_match('/^(?:\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}|0000-00-00 00:00:00)$/D',$row['modified_gmt']) ||
            ($row['reference_only']??false)!==true ||
            ($row['may_publish']??true)!==false ||
            ($row['may_checkout']??true)!==false) {
            return self::failure('invalid_source_item');
        }
        return [
            'source_company'=>$company,'source_post_type'=>$type,'kind'=>self::TYPES[$type],
            'source_id'=>$row['source_id'],'language'=>$row['language'],
            'title'=>sanitize_text_field($row['title']),
            'summary'=>sanitize_textarea_field($row['summary']),
            'body'=>sanitize_textarea_field($row['body']),
            'modified_gmt'=>$row['modified_gmt'],
        ];
    }
    /** Source pages are all read and checked before writing; no partial page accepted. */
    public static function collect($company,array $types) {
        if (!self::enabled() || $company!==self::SOURCE || !$types ||
            count($types)>3 || count($types)!==count(array_unique($types))) {
            return self::failure('invalid_selection',400);
        }
        $all=[];$seen=[];
        foreach ($types as $type) {
            if(!isset(self::TYPES[$type]))return self::failure('invalid_post_type',400);
            $expected=null;$fetched=0;
            for($page=1;$page<=100;$page++) {
                $batch=MAD4B_Central_Simple_Connections_V1::fetch_published_page($company,$type,$page);
                if(is_wp_error($batch))return $batch;
                $total=$batch['total'];
                if ($expected===null)$expected=$total;
                if ($expected!==$total || $fetched>$expected || count($batch['items'])>20) {
                    return self::failure('source_changed_during_read');
                }
                if (count($batch['items'])!==min(20,$expected-$fetched)) {
                    return self::failure('incomplete_page');
                }
                foreach($batch['items'] as $row) {
                    $safe=self::validate_item($row,$company,$type);
                    if(is_wp_error($safe))return $safe;
                    $key=$company.':'.$type.':'.$safe['language'].':'.$safe['source_id'];
                    if(isset($seen[$key]))return self::failure('duplicate_source');
                    $seen[$key]=true;$all[$key]=$safe;
                }
                $fetched+=count($batch['items']);
                if ($fetched===$expected)break;
            }
            if ($fetched!==$expected)return self::failure('incomplete_collection');
        }
        ksort($all,SORT_STRING);
        return $all;
    }
    private static function existing_map($company) {
        $ids=get_posts(['post_type'=>self::CPT,
            'post_status'=>['private','draft','pending'],'numberposts'=>-1,'fields'=>'ids',
            'suppress_filters'=>true]);
        $map=[];
        foreach($ids as $id) {
            $id=(int)$id;
            if(get_post_meta($id,'_mad4b_hub_import_owner',true)!==$company)continue;
            $type=(string)get_post_meta($id,'_mad4b_hub_import_type',true);
            $language=(string)get_post_meta($id,'_mad4b_hub_import_language',true);
            $source_id=(int)get_post_meta($id,'_mad4b_hub_import_source_id',true);
            $key=$company.':'.$type.':'.$language.':'.$source_id;
            if($source_id<=0||isset($map[$key]))return self::failure('duplicate_existing');
            $map[$key]=$id;
        }
        return $map;
    }
    public static function import($company,array $types) {
        if(!self::enabled() || !post_type_exists(self::CPT))return self::failure('not_installed',409);
        $incoming=self::collect($company,$types);
        if(is_wp_error($incoming))return $incoming;
        return self::save_batch($company,$types,$incoming);
    }
    /**
     * Host-mediated WP-CLI Cross SSH source import.
     * Never accepts browser requests, public REST, or arbitrary source companies.
     * The paired host runner pins SSH keys/hosts and supplies an all-types manifest.
     */
    public static function import_cross_ssh_snapshot($manifest) {
        if(!defined('WP_CLI') || !WP_CLI ||
            !defined('MAD4B_HUB_CROSS_SSH_HOST_RUNNER') ||
            MAD4B_HUB_CROSS_SSH_HOST_RUNNER!==true ||
            !self::enabled() || !post_type_exists(self::CPT)) {
            return self::failure('cross_ssh_cli_only',403);
        }
        if(!class_exists('MAD4B_Hub_Auto_Distribute_V1'))return self::failure('policy_missing');
        $policy=MAD4B_Hub_Auto_Distribute_V1::settings();
        if(($policy['source_transport']??'')!=='wp_cli_cross_ssh' ||
            ($policy['source_mode']??'')!=='import_to_hub' ||
            !empty($policy['consumers']))return self::failure('cross_ssh_policy_invalid',409);
        if(!is_array($manifest) ||
            array_keys($manifest)!==['contract','source_company','canonical_host','types','counts','items'] ||
            $manifest['contract']!=='mad4b.hub.wp-cli-cross-ssh.v1' ||
            $manifest['source_company']!==self::SOURCE ||
            $manifest['canonical_host']!=='tourism.almallahgroup-mg.com' ||
            !is_array($manifest['types']) ||
            !is_array($manifest['counts']) ||
            !is_array($manifest['items']) ||
            $manifest['types']!==array_keys(self::TYPES) ||
            $manifest['types']!==$policy['source_post_types'] ||
            count($manifest['items'])>6000) {
            return self::failure('cross_ssh_manifest_invalid',409);
        }
        $totals=[];$sum=0;
        foreach($manifest['types'] as $type) {
            $count=$manifest['counts'][$type]??null;
            if(!is_int($count) || $count<0 || $count>2000)
                return self::failure('cross_ssh_count_invalid',409);
            $totals[$type]=$count;
            $sum+=$count;
        }
        if(count($manifest['counts'])!==count($totals) || $sum!==count($manifest['items']))
            return self::failure('cross_ssh_incomplete',409);
        $incoming=[];$seenByType=array_fill_keys($manifest['types'],0);
        foreach($manifest['items'] as $row) {
            if(!is_array($row) || !in_array($row['source_post_type']??null,$manifest['types'],true))
                return self::failure('cross_ssh_item_type',409);
            $type=$row['source_post_type'];
            $safe=self::validate_item($row,self::SOURCE,$type);
            if(is_wp_error($safe))return $safe;
            $key=self::SOURCE.':'.$type.':'.$safe['language'].':'.$safe['source_id'];
            if(isset($incoming[$key]))return self::failure('cross_ssh_duplicate',409);
            $incoming[$key]=$safe;
            $seenByType[$type]++;
        }
        if($seenByType!==$totals)return self::failure('cross_ssh_type_count_mismatch',409);
        ksort($incoming,SORT_STRING);
        $saved=self::save_batch(self::SOURCE,$manifest['types'],$incoming);
        if(is_array($saved)) {
            $saved['transport']='wp_cli_cross_ssh';
            $saved['source_host']='tourism.almallahgroup-mg.com';
            $saved['counts']=$totals;
            $saved['commercial_publication']=false;
        }
        return $saved;
    }

    /**
     * Reuse already validated Tourism Nile references from CENTRAL Staging.
     * A transitional bootstrap; not proof of a fresh live source sync.
     * Only tours, never 364 historical rates or cross-company references.
     */
    public static function bootstrap_verified_nile_tours() {
        if(!self::enabled() || !current_user_can('manage_options') ||
            !post_type_exists(self::CPT) || !post_type_exists('mad4b_hub_nile')) {
            return self::failure('bootstrap_forbidden',403);
        }
        $ids=get_posts([
            'post_type'=>'mad4b_hub_nile','post_status'=>'publish',
            'numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true,
            'meta_query'=>[['key'=>'_mad4b_hub_source_kind','value'=>'nile_tour']]
        ]);
        if(!is_array($ids) || count($ids)<1 || count($ids)>2000) {
            return self::failure('bootstrap_source_unavailable',409);
        }
        $company=self::SOURCE;$type='tours-and-activities';
        $incoming=[];$seen=[];
        foreach($ids as $id) {
            $id=(int)$id;
            if(get_post_meta($id,'_mad4b_hub_source_company',true)!==$company ||
                get_post_meta($id,'_mad4b_hub_content_owned_by',true)!==$company ||
                get_post_meta($id,'_mad4b_hub_publication_state',true)!=='published_content_hub' ||
                get_post_meta($id,'_mad4b_hub_commerce_approved',true)!=='no' ||
                get_post_meta($id,'_mad4b_hub_bookable',true)!=='no') {
                return self::failure('bootstrap_source_owner_invalid',409);
            }
            $raw=(string)get_post_meta($id,'_mad4b_hub_source_payload_json',true);
            $hash=(string)get_post_meta($id,'_mad4b_hub_source_sha256',true);
            if(!$raw || !preg_match('/^[a-f0-9]{64}$/D',$hash) ||
                !hash_equals($hash,hash('sha256',$raw)))return self::failure('bootstrap_source_tampered',409);
            $data=json_decode($raw,true);
            $sourceId=(int)get_post_meta($id,'_mad4b_hub_source_id',true);
            $lang=$data['translation_source']['language_code']??null;
            if(!is_array($data) || ($data['source_company']??'')!==$company ||
                ($data['publish_allowed']??true)!==false ||
                !is_int($data['source_tour_id']??null) || $data['source_tour_id']!==$sourceId ||
                !in_array($lang,['en','ar','fr','de','it','es'],true) ||
                !is_string($data['title']??null) || !is_string($data['summary']??null) ||
                !is_string($data['source_content_text']??null)) {
                return self::failure('bootstrap_reference_invalid',409);
            }
            $row=[
                'source_company'=>$company,'source_post_type'=>$type,'kind'=>'tour',
                'source_id'=>$sourceId,'language'=>$lang,
                'title'=>$data['title'],
                'summary'=>$data['summary'],
                'body'=>mb_substr(wp_strip_all_tags($data['source_content_text']),0,12000),
                'modified_gmt'=>'0000-00-00 00:00:00',
                'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false
            ];
            $safe=self::validate_item($row,$company,$type);
            if(is_wp_error($safe))return $safe;
            $key=$company.':'.$type.':'.$lang.':'.$sourceId;
            if(isset($seen[$key]))return self::failure('bootstrap_duplicate_reference',409);
            $seen[$key]=true;
            $incoming[$key]=$safe;
        }
        ksort($incoming,SORT_STRING);
        $result=self::save_batch($company,[$type],$incoming);
        if(is_array($result)){
            $result['input']='previously_verified_staging_nile_references';
            $result['fresh_source_fetch']=false;
        }
        return $result;
    }
    private static function save_batch($company,array $types,array $incoming) {
        $existing=self::existing_map($company);
        if(is_wp_error($existing))return $existing;
        // Batch hash is content-addressed, not time-based; reruns of identical
        // source data do not modify content or generate additional duplicates.
        $hash=hash('sha256',wp_json_encode($incoming,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $created=0;$updated=0;
        foreach($incoming as $key=>$row) {
            $id=$existing[$key]??0;
            $itemHash=hash('sha256',wp_json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $unchanged=$id && get_post_meta($id,'_mad4b_hub_import_item_hash',true)===$itemHash;
            if ($unchanged)continue;
            $data=[
                'post_type'=>self::CPT,'post_status'=>'private',
                'post_title'=>$row['title'],'post_content'=>$row['body'],
                'post_excerpt'=>$row['summary'],
                'comment_status'=>'closed','ping_status'=>'closed',
            ];
            if($id)$data['ID']=$id;
            $written=$id?wp_update_post($data,true):wp_insert_post($data,true);
            if(is_wp_error($written) || !$written)return self::failure('write_failed',503);
            $id=(int)$written;
            $meta=[
                '_mad4b_hub_import_owner'=>$company,
                '_mad4b_hub_import_type'=>$row['source_post_type'],
                '_mad4b_hub_import_kind'=>$row['kind'],
                '_mad4b_hub_import_source_id'=>$row['source_id'],
                '_mad4b_hub_import_language'=>$row['language'],
                '_mad4b_hub_import_source_modified'=>$row['modified_gmt'],
                '_mad4b_hub_import_item_hash'=>$itemHash,
                '_mad4b_hub_import_reference_only'=>'yes',
                '_mad4b_hub_import_may_publish'=>'no',
                '_mad4b_hub_import_may_checkout'=>'no',
            ];
            foreach($meta as $field=>$value)update_post_meta($id,$field,$value);
            if($key!==$company.':'.$row['source_post_type'].':'.$row['language'].':'.$row['source_id']) {
                return self::failure('identity_drift',409);
            }
            if(isset($existing[$key]))$updated++;else{$created++;$existing[$key]=$id;}
        }
        // Atomic active index swap: removed source records are not distributed,
        // even though their private Hub posts are retained for manual review.
        $active=['source_company'=>$company,'types'=>$types,
            'snapshot_sha256'=>$hash,'keys'=>array_keys($incoming),
            'saved_at'=>gmdate('c'),'record_count'=>count($incoming)];
        update_option(self::ACTIVE,$active,false);
        $receipt=['source_company'=>$company,'imported'=>count($incoming),
            'created'=>$created,'updated'=>$updated,
            'unchanged'=>count($incoming)-$created-$updated,
            'snapshot_sha256'=>$hash,'status'=>'imported'];
        update_option(self::RECEIPT,$receipt,false);
        return $receipt;
    }
    public static function active_items($company,array $kinds,array $languages,$selected_ids=[],$auto_include=true) {
        $active=get_option(self::ACTIVE,[]);
        if(!is_array($active)||($active['source_company']??'')!==$company ||
            !is_array($active['keys']??null))return self::failure('no_active_import');
        $wanted=array_fill_keys($active['keys'],true);
        $selected=array_fill_keys($selected_ids,true);
        $ids=get_posts(['post_type'=>self::CPT,'post_status'=>'private',
            'numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
        $items=[];$chosen=0;
        foreach($ids as $id) {
            $id=(int)$id;
            if(!$auto_include&&!isset($selected[$id]))continue;
            $owner=(string)get_post_meta($id,'_mad4b_hub_import_owner',true);
            $type=(string)get_post_meta($id,'_mad4b_hub_import_type',true);
            $language=(string)get_post_meta($id,'_mad4b_hub_import_language',true);
            $sourceId=(int)get_post_meta($id,'_mad4b_hub_import_source_id',true);
            $kind=(string)get_post_meta($id,'_mad4b_hub_import_kind',true);
            $key=$company.':'.$type.':'.$language.':'.$sourceId;
            if($owner!==$company||!isset($wanted[$key])||
                !in_array($kind,$kinds,true)||!in_array($language,$languages,true))continue;
            if(get_post_meta($id,'_mad4b_hub_import_reference_only',true)!=='yes' ||
                get_post_meta($id,'_mad4b_hub_import_may_publish',true)!=='no' ||
                get_post_meta($id,'_mad4b_hub_import_may_checkout',true)!=='no') {
                return self::failure('unsafe_local_import');
            }
            $post=get_post($id);
            if(!$post || $post->post_type!==self::CPT || $post->post_status!=='private')
                return self::failure('local_post_changed');
            $items[]=[
                'source_company'=>$company,'kind'=>$kind,'language'=>$language,
                'source_id'=>$sourceId,'title'=>$post->post_title,
                'summary'=>$post->post_excerpt,
                'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
            ];
            $chosen++;
            if($chosen>150)return self::failure('too_many_to_distribute');
        }
        if(!$auto_include && $chosen!==count($selected))return self::failure('selected_hub_post_missing');
        usort($items,static function($a,$b) {
            return strcmp($a['kind'].':'.$a['language'].':'.sprintf('%010d',$a['source_id']),
                $b['kind'].':'.$b['language'].':'.sprintf('%010d',$b['source_id']));
        });
        return $items;
    }
    public static function boot(): void {
        if(!self::enabled())return;
        add_action('init',[__CLASS__,'register'],12);
        add_action('rest_api_init',static function() {
            register_rest_route('mad4b-central/v1','/hub-source-import/bootstrap-verified-nile',[
                'methods'=>WP_REST_Server::CREATABLE,
                'permission_callback'=>static function(){return current_user_can('manage_options');},
                'callback'=>static function() {
                    $result=self::bootstrap_verified_nile_tours();
                    return is_wp_error($result)?$result:new WP_REST_Response($result,200);
                }
            ]);
        });

    }
}
MAD4B_Hub_Source_Import_V1::boot();
