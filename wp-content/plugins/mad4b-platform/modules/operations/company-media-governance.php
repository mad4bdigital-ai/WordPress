<?php
/**
 * Plugin Name: Content Media Integrity Governance
 * Description: Govern reused imagery across native WordPress, JetEngine, WooCommerce and WPML content.
 * Version: 0.1.0-local
 */
if (!defined('ABSPATH')) exit;

/** Versioned safe policy. Site-wide unless resolved through trusted company-policy filter. */
function company_media_default_policy() {
    return [
        'enabled'=>true,'threshold'=>3,
        'term_mode'=>'warn','post_mode'=>'warn',
        'type_overrides'=>[],
        'quarantine_mode'=>'block',
        'term_fields'=>['featured_image','image_gellery','thumbnail_id','thumbnail','image','gallery','image_gallery','tour_types_image','amenity_icon','cover_image','banner_image'],
        'post_fields'=>['_thumbnail_id','featured_image','image','image_gallery','gallery','cover_image','banner_image','itinerary_item_image','_product_image_gallery'],
        'skip_post_types'=>['attachment','revision'],
        'scan_unavailable_assets'=>'warn',
        'event_limit'=>250
    ];
}
function company_media_policy() {
    $defaults=company_media_default_policy();
    $stored=get_option('company_media_governance_policy_v1',[]);
    $policy=array_replace($defaults,is_array($stored)?$stored:[]);
    $policy=apply_filters('company_media_governance_policy_for_context',$policy);
    if(!is_array($policy))return $defaults;
    $policy['threshold']=max(2,min(100,(int)($policy['threshold']??3)));
    foreach(['term_mode','post_mode','quarantine_mode'] as $key){
        $value=$policy[$key]??'warn';
        if(!in_array($value,['warn','block','off'],true))$policy[$key]=$defaults[$key];
    }
    return $policy;
}
function company_media_normalize($raw) {
    if(is_numeric($raw) && absint($raw)) {
        $id=absint($raw);
        if(get_post_type($id)!=='attachment')return '';
        $raw=wp_get_attachment_url($id);
    }
    if(!is_string($raw)||trim($raw)===''||strlen($raw)>4096)return '';
    $url=html_entity_decode(trim($raw),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $path=wp_parse_url($url,PHP_URL_PATH);
    if(!is_string($path))return '';
    $pos=strpos($path,'/wp-content/uploads/');
    if($pos===false)return '';
    $relative=rawurldecode(substr($path,$pos+strlen('/wp-content/uploads/')));
    if(!$relative||str_contains($relative,"\0")||str_contains($relative,'..'))return '';
    $relative=preg_replace('~\.(jpe?g|png)\.webp$~i', '.$1', $relative);
    return strtolower($relative);
}
function company_media_extract($value, $max=60, $depth=0) {
    if($depth>4)return [];
    if(is_array($value)){
        $out=[];foreach($value as $key=>$item){
            if(is_string($key)&&in_array(strtolower($key),['alt','title','caption','description','width','height','size','sizes'],true))continue;
            foreach(company_media_extract($item,$max,$depth+1) as $x)$out[$x]=true;
            if(count($out)>$max)break;
        }
        return array_keys($out);
    }
    if(!is_string($value)&&!is_numeric($value))return [];
    $v=trim((string)$value);
    if($v==='')return [];
    if(strlen($v)>65536)return [];
    if(($v[0]==='[' || $v[0]==='{')){
        $array=json_decode($v,true);
        if(is_array($array))return company_media_extract($array,$max,$depth+1);
    }
    if(preg_match('/^a:\d+:\{/', $v)){
        $array=@unserialize($v,['allowed_classes'=>false]);
        if(is_array($array))return company_media_extract($array,$max,$depth+1);
    }
    $parts=str_contains($v,',')?explode(',',$v):[$v];
    $result=[];
    foreach($parts as $part){
        $key=company_media_normalize(trim($part));
        if($key)$result[$key]=true;
        if(count($result)>$max)break;
    }
    return array_keys($result);
}
function company_media_entity($kind,$id) {
    global $wpdb;
    $id=absint($id);
    if($kind==='term'){
        $term=get_term($id);
        if(!$term instanceof WP_Term)return null;
        $trid=$wpdb->get_var($wpdb->prepare("SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d LIMIT 1",
            'tax_'.$term->taxonomy,$term->term_taxonomy_id));
        return ['kind'=>'term','id'=>$id,'type'=>$term->taxonomy,
            'group'=>$trid?'wpml:term:'.$term->taxonomy.':'.(int)$trid:'term:'.$term->taxonomy.':'.$term->term_taxonomy_id,
            'title'=>$term->name];
    }
    if($kind==='post'){
        $post=get_post($id);
        if(!$post instanceof WP_Post)return null;
        $trid=$wpdb->get_var($wpdb->prepare("SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d LIMIT 1",
            'post_'.$post->post_type,$id));
        return ['kind'=>'post','id'=>$id,'type'=>$post->post_type,
            'group'=>$trid?'wpml:post:'.$post->post_type.':'.(int)$trid:'post:'.$id,
            'title'=>$post->post_title];
    }
    return null;
}
function company_media_attachment_ids_for_asset($asset){
    global $wpdb;
    if(!$asset)return [];
    $ids=$wpdb->get_col($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta}
       WHERE meta_key='_wp_attached_file' AND LOWER(meta_value)=%s LIMIT 12",
       strtolower($asset)));
    return array_map('intval',(array)$ids);
}
function company_media_resolve_local_asset($asset) {
    $path=WP_CONTENT_DIR.'/uploads/'.$asset;
    if(is_file($path))return $path;
    global $wpdb;
    $relative=$wpdb->get_var($wpdb->prepare(
       "SELECT meta_value FROM {$wpdb->postmeta}
       WHERE meta_key='_wp_attached_file' AND LOWER(meta_value)=%s LIMIT 1",
       strtolower($asset)));
    if(!is_string($relative)||!$relative||str_contains($relative,'..')||
       str_contains($relative,"\\0"))return '';
    $path=WP_CONTENT_DIR.'/uploads/'.$relative;
    return is_file($path)?$path:'';
}

function company_media_existing_groups($asset,$policy) {
    global $wpdb;
    $base=basename($asset);
    if(!$base||strlen($base)<4)return [];
    $termFields=array_values(array_filter((array)$policy['term_fields'],'is_string'));
    $postFields=array_values(array_filter((array)$policy['post_fields'],'is_string'));
    $matches=[];
    $attachmentIds=company_media_attachment_ids_for_asset($asset);
    foreach(['term'=>$termFields,'post'=>$postFields] as $kind=>$fields){
        if(!$fields)continue;
        $table=$kind==='term'?$wpdb->termmeta:$wpdb->postmeta;
        $col=$kind==='term'?'term_id':'post_id';
        $placeholders=implode(',',array_fill(0,count($fields),'%s'));
        // Indexed key plus filename fragment; no full data scan during a normal metadata save.
        $conditions="meta_value LIKE %s";
        $args=array_merge($fields,['%'.$wpdb->esc_like($base).'%']);
        if($attachmentIds){
            $idPlaceholders=implode(',',array_fill(0,count($attachmentIds),'%s'));
            $conditions.=" OR meta_value IN ({$idPlaceholders})";
            foreach($attachmentIds as $aid)$args[]=(string)$aid;
        }
        $query=$wpdb->prepare("SELECT {$col} AS object_id,meta_value FROM {$table}
            WHERE meta_key IN ({$placeholders}) AND ({$conditions}) LIMIT 2500",$args);
        $rows=$wpdb->get_results($query,ARRAY_A);
        foreach((array)$rows as $row){
            $media=company_media_extract($row['meta_value']);
            if(!in_array($asset,$media,true))continue;
            $ent=company_media_entity($kind,(int)$row['object_id']);
            if($ent)$matches[$ent['group']]=['group'=>$ent['group'],'kind'=>$kind,'type'=>$ent['type'],'id'=>$ent['id']];
        }
    }
    return array_values($matches);
}
function company_media_exception($asset,$entity){
    $registry=get_option('company_media_governance_exceptions_v1',[]);
    $row=is_array($registry)?($registry[hash('sha256',$asset)]??null):null;
    if(!is_array($row))return false;
    return in_array($entity['group'],(array)($row['groups']??[]),true);
}
function company_media_assess($kind,$id,$field,$value,$batch=[]){
    $policy=company_media_policy();
    $entity=company_media_entity($kind,$id);
    $result=['entity'=>$kind.':'.absint($id),'field'=>$field,
        'decision'=>'allow','issues'=>[],'assets'=>[]];
    if(!$entity || !$policy['enabled'] || in_array($entity['type'],(array)$policy['skip_post_types'],true))
        return $result;
    $fields=$kind==='term'?$policy['term_fields']:$policy['post_fields'];
    if(!in_array($field,(array)$fields,true))return $result;
    $mode=$policy['type_overrides'][$kind.':'.$entity['type']]??$policy[$kind.'_mode'];
    if(!in_array($mode,['warn','block','off'],true))$mode='warn';
    $assets=company_media_extract($value);
    $result['assets']=$assets;
    $existing= $kind==='term'?get_term_meta($id,$field,true):get_post_meta($id,$field,true);
    $oldAssets=company_media_extract($existing);
    if(!$assets && $value!=='' && $value!==null && $value!=$existing) {
        $result['issues'][]=['code'=>'unrecognized_media_reference','decision'=>$mode];
    }
    $legacy=get_option('company_term_media_quarantine_v1',[]);
    foreach($assets as $asset){
        $q=is_array($legacy)?($legacy[hash('sha256',$asset)]??null):null;
        if(is_array($q)){
            $allow=$kind==='term' && in_array($id,array_map('intval',$q['allowed_term_ids']??[]),true);
            if(!$allow)$result['issues'][]=['code'=>'legacy_quarantined','asset'=>$asset,
                'decision'=>$policy['quarantine_mode'],'groups'=>0];
        }
        $generic=get_option('company_media_governance_quarantine_v1',[]);
        $ban=is_array($generic)?($generic[hash('sha256',$asset)]??null):null;
        if(is_array($ban)&&!in_array($entity['group'],(array)($ban['allowed_groups']??[]),true)){
            $result['issues'][]=['code'=>'quarantined','asset'=>$asset,
                'decision'=>$policy['quarantine_mode'],'groups'=>0];
        }
        if(in_array($asset,$oldAssets,true))continue; // No-op save preserves existing media.
        $local=company_media_resolve_local_asset($asset);
        if(!$local || !is_file($local)) {
            $result['issues'][]=['code'=>'media_asset_unavailable','asset'=>$asset,
                'decision'=>$mode];
        }else{
            $mime=wp_check_filetype($local);
            if(!in_array((string)($mime['type']??''),[
                'image/jpeg','image/png','image/webp','image/avif','image/gif'
            ],true)) {
                $result['issues'][]=['code'=>'unsupported_media_type','asset'=>$asset,
                    'decision'=>$mode];
            }
        }
        $groups=company_media_existing_groups($asset,$policy);
        $identities=[];
        foreach($groups as $g)$identities[$g['group']]=true;
        foreach((array)$batch as $member){
            if(($member['asset']??'')===$asset) $identities[(string)$member['group']]=true;
        }
        if(count($identities)>= $policy['threshold'] && !isset($identities[$entity['group']]) &&
            !company_media_exception($asset,$entity)) {
            $result['issues'][]=['code'=>'cross_group_reuse','asset'=>$asset,
                'decision'=>$mode,'groups'=>count($identities),'threshold'=>$policy['threshold']];
        }
    }
    foreach($result['issues'] as $issue){
        if($issue['decision']==='block'){$result['decision']='block';break;}
        if($issue['decision']==='warn')$result['decision']='warn';
    }
    return $result;
}
function company_media_add_event($assess,$origin){
    $log=get_option('company_media_governance_events_v1',[]);
    if(!is_array($log))$log=[];
    $event=[
        'time'=>gmdate(DATE_ATOM),'decision'=>$assess['decision'],
        'entity'=>$assess['entity'],'field'=>$assess['field'],
        'origin'=>$origin,'issues'=>$assess['issues'],
        'user'=>get_current_user_id()
    ];
    array_unshift($log,$event);
    $max=max(20,min(1000,(int)(company_media_policy()['event_limit']??250)));
    update_option('company_media_governance_events_v1',array_slice($log,0,$max),false);
}
function company_media_metadata_guard($check,$id,$key,$value) {
    if($check!==null)return $check;
    $kind=current_filter()==='add_term_metadata'||current_filter()==='update_term_metadata'?'term':'post';
    $assess=company_media_assess($kind,(int)$id,(string)$key,$value);
    // Capture original values before a successful WordPress metadata update.
    // WordPress exposes updated_meta hooks only AFTER a change is committed.
    if($assess['decision']!=='block' && company_media_policy()['enabled'] &&
        in_array($key,(array)(company_media_policy()[$kind.'_fields']??[]),true)){
        $old=$kind==='term'?get_term_meta($id,$key,true):get_post_meta($id,$key,true);
        if($old!==$value){
            $index=$kind.':'.absint($id).':'.(string)$key;
            $GLOBALS['company_media_pending_journal'][$index]=[
                'kind'=>$kind,'id'=>absint($id),'field'=>(string)$key,
                'old'=>$old,'new'=>$value,
                'had_key'=>$kind==='term'?metadata_exists('term',$id,$key):metadata_exists('post',$id,$key),
                'time'=>gmdate(DATE_ATOM)
            ];
        }
    }
    if($assess['decision']==='allow')return null;
    company_media_add_event($assess,'wp_metadata_api');
    if(is_user_logged_in())set_transient(
      'company_media_governance_notice_'.get_current_user_id(),
      ['level'=>$assess['decision'],'message'=>$assess['decision']==='block'
        ?'Image update was blocked by media integrity policy.'
        :'Image saved with a media-integrity warning; review its source and sharing permissions.'],
      MINUTE_IN_SECONDS);
    return $assess['decision']==='block'?false:null;
}
function company_media_journal_after_update($meta_id,$id,$key,$value) {
    $hook=current_filter();
    $kind=str_contains($hook,'term')?'term':'post';
    $index=$kind.':'.absint($id).':'.(string)$key;
    $item=$GLOBALS['company_media_pending_journal'][$index]??null;
    if(!is_array($item))return;
    unset($GLOBALS['company_media_pending_journal'][$index]);
    if($item['new']!==$value)return;
    $item['meta_id']=absint($meta_id);
    $item['action_id']=hash('sha256',$item['time'].':'.$index.':'.$meta_id.':'.wp_json_encode($item['old']).':'.wp_json_encode($item['new']));
    $item['user_id']=get_current_user_id();
    $history=get_option('company_media_governance_recovery_history_v1',[]);
    if(!is_array($history))$history=[];
    array_unshift($history,$item);
    $limit=max(20,min(1000,(int)(company_media_policy()['event_limit']??250)));
    update_option('company_media_governance_recovery_history_v1',array_slice($history,0,$limit),false);
}
foreach(['updated_term_meta','added_term_meta','updated_post_meta','added_post_meta'] as $hook)
    add_action($hook,'company_media_journal_after_update',10,4);

function company_media_restore_journal_entry($action_id) {
    if(!current_user_can('manage_options'))return new WP_Error('forbidden','Not authorized.');
    $history=get_option('company_media_governance_recovery_history_v1',[]);
    $record=null;
    foreach((array)$history as $row) {
        if(isset($row['action_id'])&&hash_equals((string)$row['action_id'],(string)$action_id)){
            $record=$row;break;
        }
    }
    if(!$record||!empty($record['restored_at']))return new WP_Error('unknown_record','No unrestored matching history entry.');
    $kind=$record['kind'];$id=absint($record['id']);$field=$record['field'];
    $now=$kind==='term'?get_term_meta($id,$field,true):get_post_meta($id,$field,true);
    if($now!==$record['new'])return new WP_Error('concurrent_change','The saved image has changed since this history entry; no overwrite.');
    // The original source must still pass current policy. Never bypass quarantine.
    if($record['had_key'] && company_media_assess($kind,$id,$field,$record['old'])['decision']==='block')
        return new WP_Error('unsafe_recovery','The previous image now violates current protection policy.');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    if($record['had_key']){
        $done=$kind==='term'?update_term_meta($id,$field,$record['old']):update_post_meta($id,$field,$record['old']);
    }else{
        $done=$kind==='term'?delete_term_meta($id,$field,$record['new']):delete_post_meta($id,$field,$record['new']);
    }
    if(!$done){
        $wpdb->query('ROLLBACK');
        if($kind==='term')wp_cache_delete($id,'term_meta');else wp_cache_delete($id,'post_meta');
        return new WP_Error('restore_failed','Restore was not applied.');
    }
    $next=$kind==='term'?get_term_meta($id,$field,true):get_post_meta($id,$field,true);
    if($next!==$record['old']){
        $wpdb->query('ROLLBACK');
        if($kind==='term')wp_cache_delete($id,'term_meta');else wp_cache_delete($id,'post_meta');
        return new WP_Error('restore_readback_failed','Restore readback did not match; transaction rolled back.');
    }
    $wpdb->query('COMMIT');
    foreach($history as &$entry){
        if(($entry['action_id']??'')===$action_id){
            $entry['restored_at']=gmdate(DATE_ATOM);
            $entry['restored_by']=get_current_user_id();
            break;
        }
    }
    unset($entry);
    update_option('company_media_governance_recovery_history_v1',$history,false);
    company_media_add_event(['entity'=>$kind.':'.$id,'field'=>$field,
        'decision'=>'warn','issues'=>[['code'=>'restored_prior_verified_value']]],
        'admin_verified_restore');
    return true;
}

foreach(['add_term_metadata','update_term_metadata','add_post_metadata','update_post_metadata'] as $hook)
    add_filter($hook,'company_media_metadata_guard',7,4);

/** Dry-run preflight API. Importers must call and refuse mutations if blockers exist. */
function company_media_preflight($changes) {
    $results=[];$pending=[];
    if(!is_array($changes)||count($changes)>10000)
        return ['status'=>'invalid','counts'=>['block'=>0,'warn'=>0,'allow'=>0],'results'=>[]];
    foreach($changes as $n=>$change){
        $kind=$change['kind']??'';
        $id=absint($change['id']??0);
        $field=(string)($change['field']??'');
        $value=$change['value']??'';
        if(!in_array($kind,['term','post'],true)||!$id||!$field||!company_media_entity($kind,$id)) {
            $result=['entity'=>$kind.':'.$id,'field'=>$field,'decision'=>'block',
                'issues'=>[['code'=>'invalid_manifest_row','decision'=>'block']]];
        }else{
            $result=company_media_assess($kind,$id,$field,$value,$pending);
            $entity=company_media_entity($kind,$id);
            foreach($result['assets'] as $asset)$pending[]=['asset'=>$asset,'group'=>$entity['group']];
        }
        $result['row']=$n;
        $results[]=$result;
    }
    $counts=['block'=>0,'warn'=>0,'allow'=>0];
    foreach($results as $r)$counts[$r['decision']]++;
    return ['status'=>$counts['block']?'blocked':($counts['warn']?'review':'pass'),
        'counts'=>$counts,'results'=>$results];
}
function company_media_audit_full($save=true){
    global $wpdb;
    $policy=company_media_policy();
    $index=[];$quarantine=[];$read=0;
    $legacy=get_option('company_term_media_quarantine_v1',[]);
    $generic=get_option('company_media_governance_quarantine_v1',[]);
    foreach(['term'=>$policy['term_fields'],'post'=>$policy['post_fields']] as $kind=>$keys) {
        $keys=array_values(array_filter((array)$keys,'is_string'));
        if(!$keys)continue;
        $table=$kind==='term'?$wpdb->termmeta:$wpdb->postmeta;
        $idCol=$kind==='term'?'term_id':'post_id';
        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT {$idCol} AS object_id,meta_key,meta_value FROM {$table}
            WHERE meta_key IN ({$placeholders}) AND meta_value<>''",
            $keys),ARRAY_A);
        foreach((array)$rows as $row){
            $entity=company_media_entity($kind,(int)$row['object_id']);
            if(!$entity || in_array($entity['type'],(array)$policy['skip_post_types'],true))continue;
            $read++;
            foreach(company_media_extract($row['meta_value']) as $asset){
                $index[$asset][$entity['group']]=true;
                $q=$legacy[hash('sha256',$asset)]??null;
                if(is_array($q)&&!($kind==='term'&&in_array((int)$row['object_id'],
                    array_map('intval',$q['allowed_term_ids']??[]),true))) {
                    $quarantine[]=['entity'=>$kind.':'.$entity['id'],'asset'=>$asset,'key'=>$row['meta_key'],'origin'=>'legacy'];
                }
                $q2=$generic[hash('sha256',$asset)]??null;
                if(is_array($q2)&&!in_array($entity['group'],(array)($q2['allowed_groups']??[]),true)){
                    $quarantine[]=['entity'=>$kind.':'.$entity['id'],'asset'=>$asset,'key'=>$row['meta_key'],'origin'=>'governance'];
                }
            }
        }
    }
    $duplicates=[];
    foreach($index as $asset=>$groups){
        if(count($groups)>=$policy['threshold']+1)
            $duplicates[]=['asset'=>$asset,'distinct_groups'=>count($groups),
                'is_excepted'=>(bool)(get_option('company_media_governance_exceptions_v1',[])[hash('sha256',$asset)]??null)];
    }
    usort($duplicates,fn($a,$b)=>$b['distinct_groups']<=>$a['distinct_groups']);
    $report=['created_at'=>gmdate(DATE_ATOM),
      'status'=>$quarantine?'violations':($duplicates?'review':'pass'),
      'scanned_records'=>$read,'unique_assets'=>count($index),
      'quarantine_violations'=>$quarantine,
      'cross_group_violations_count'=>count($duplicates),
      'top_cross_group'=>array_slice($duplicates,0,100),
      'policy_sha256'=>hash('sha256',wp_json_encode($policy))];
    if($save)update_option('company_media_governance_last_audit_v1',$report,false);
    return $report;
}
add_action('company_media_governance_scheduled_audit', 'company_media_audit_full');
add_action('admin_notices',static function(){
    if(!current_user_can('manage_options'))return;
    $key='company_media_governance_notice_'.get_current_user_id();
    $notice=get_transient($key);
    if(!$notice)return;
    delete_transient($key);
    $level=is_array($notice)?($notice['level']??'block'):'block';
    $message=is_array($notice)?($notice['message']??'Media integrity warning.'):$notice;
    echo '<div class="notice '.($level==='block'?'notice-error':'notice-warning').'"><p>'.
        esc_html($message).'</p></div>';
});

/** Editor-managed policies, review queue and approved sharing exceptions. */
function company_media_policy_admin_update($posted) {
    $old=company_media_policy();
    $mode=static function($value,$fallback) {
        return in_array($value,['warn','block','off'],true)?$value:$fallback;
    };
    $next=$old;
    $next['enabled']=!empty($posted['enabled']);
    $next['threshold']=max(2,min(100,absint($posted['threshold']??3)));
    foreach(['term_mode','post_mode','quarantine_mode'] as $key)
        $next[$key]=$mode(sanitize_key($posted[$key]??''),$old[$key]);
    $overrides=[];
    $lines=preg_split('/\r\n|\r|\n/',(string)($posted['type_overrides']??''));
    foreach(array_slice($lines,0,150) as $line){
        $line=trim($line);
        if(!$line)continue;
        $parts=explode('=',$line,2);
        if(count($parts)!==2)continue;
        $type=trim($parts[0]);$value=trim($parts[1]);
        if(!preg_match('/^(term|post):[a-z0-9_-]+$/D',$type))continue;
        if(!in_array($value,['warn','block','off'],true))continue;
        $overrides[$type]=$value;
    }
    $next['type_overrides']=$overrides;
    foreach(['term_fields','post_fields'] as $key){
        $values=preg_split('/[,\s]+/',(string)($posted[$key]??''));
        $fields=[];
        foreach((array)$values as $value)
            if(preg_match('/^[_a-zA-Z][_a-zA-Z0-9-]{0,80}$/D',$value))
                $fields[$value]=true;
        if($fields)$next[$key]=array_slice(array_keys($fields),0,120);
    }
    update_option('company_media_governance_policy_v1',$next,false);
    return $next;
}
function company_media_approve_exception($posted) {
    $kind=sanitize_key($posted['kind']??'');
    $id=absint($posted['object_id']??0);
    $media=$posted['asset']??'';
    $reason=sanitize_text_field(wp_unslash($posted['reason']??''));
    if(!in_array($kind,['term','post'],true)||$id<1||mb_strlen($reason)<12||mb_strlen($reason)>400)
        return new WP_Error('invalid_exception','A valid object and documented reason are required.');
    $entity=company_media_entity($kind,$id);
    $key=company_media_normalize(is_scalar($media)?wp_unslash((string)$media):'');
    if(!$entity||!$key)return new WP_Error('unknown_media','Unknown content or media asset.');
    $local=company_media_resolve_local_asset($key);
    $type=$local?wp_check_filetype($local):[];
    if(!$local || !in_array((string)($type['type']??''),[
        'image/jpeg','image/png','image/webp','image/avif','image/gif'
    ],true))return new WP_Error('missing_asset','A verified local image file is required.');
    // Exceptions do not cancel known quarantine; quarantine requires original source recovery.
    $quarantined=get_option('company_term_media_quarantine_v1',[]);
    if(isset($quarantined[hash('sha256',$key)])||
        isset(get_option('company_media_governance_quarantine_v1',[])[hash('sha256',$key)]))
        return new WP_Error('quarantined','This quarantined asset cannot receive a shared-use exception.');
    $registry=get_option('company_media_governance_exceptions_v1',[]);
    if(!is_array($registry))$registry=[];
    $hash=hash('sha256',$key);
    if(!isset($registry[$hash]))$registry[$hash]=['asset'=>$key,'groups'=>[],'approvals'=>[]];
    if(!in_array($entity['group'],$registry[$hash]['groups'],true))
        $registry[$hash]['groups'][]=$entity['group'];
    $registry[$hash]['approvals'][]=['group'=>$entity['group'],'entity'=>$kind.':'.$id,
        'reason'=>$reason,'user_id'=>get_current_user_id(),'time'=>gmdate(DATE_ATOM)];
    update_option('company_media_governance_exceptions_v1',$registry,false);
    company_media_add_event(['entity'=>$kind.':'.$id,'field'=>'approved_exception','decision'=>'warn',
       'issues'=>[['code'=>'approved_shared_asset','asset'=>$key,'group'=>$entity['group'],
          'reason'=>$reason]]],'admin_exception');
    return true;
}
function company_media_quarantine_asset($posted) {
    $input=$posted['asset']??'';
    $reason=sanitize_text_field(wp_unslash($posted['reason']??''));
    $asset=company_media_normalize(is_scalar($input)?wp_unslash((string)$input):'');
    if(!$asset||mb_strlen($reason)<12||mb_strlen($reason)>400)
        return new WP_Error('invalid_quarantine','A valid local media ID/URL and documented reason are required.');
    if(!company_media_resolve_local_asset($asset))
        return new WP_Error('missing_media','Media file does not exist in the local library.');
    $allowed=[];
    $kind=sanitize_key($posted['allow_kind']??'');
    $object=absint($posted['allow_object_id']??0);
    if($kind && $object){
        $entity=company_media_entity($kind,$object);
        if(!$entity)return new WP_Error('invalid_exception','The permitted content reference is invalid.');
        $allowed[]=$entity['group'];
    }
    $quarantine=get_option('company_media_governance_quarantine_v1',[]);
    if(!is_array($quarantine))$quarantine=[];
    $hash=hash('sha256',$asset);
    if(isset($quarantine[$hash]))return new WP_Error('already_quarantined','This asset is already quarantined.');
    $quarantine[$hash]=[
        'asset'=>$asset,'allowed_groups'=>$allowed,'reason'=>$reason,
        'created_at'=>gmdate(DATE_ATOM),'created_by'=>get_current_user_id()
    ];
    update_option('company_media_governance_quarantine_v1',$quarantine,false);
    company_media_add_event(['entity'=>'admin','field'=>'new_quarantine','decision'=>'block',
      'issues'=>[['code'=>'quarantine_created','asset'=>$asset,'reason'=>$reason]]],'admin_quarantine');
    return true;
}
function company_media_unquarantine_asset($posted) {
    $hash=sanitize_text_field($posted['asset_hash']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return false;
    $list=get_option('company_media_governance_quarantine_v1',[]);
    if(!is_array($list)||!isset($list[$hash]))return false;
    $original=$list[$hash]['asset'];
    unset($list[$hash]);
    update_option('company_media_governance_quarantine_v1',$list,false);
    company_media_add_event(['entity'=>'admin','field'=>'quarantine_removed','decision'=>'warn',
      'issues'=>[['code'=>'quarantine_removed','asset'=>$original]]],'admin_quarantine');
    return true;
}

function company_media_revoke_exception($posted) {
    $hash=sanitize_text_field($posted['asset_hash']??'');
    $group=sanitize_text_field($posted['group']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!$group)return false;
    $registry=get_option('company_media_governance_exceptions_v1',[]);
    if(!isset($registry[$hash])||!in_array($group,$registry[$hash]['groups'],true))return false;
    $registry[$hash]['groups']=array_values(array_diff($registry[$hash]['groups'],[$group]));
    if(!$registry[$hash]['groups'])unset($registry[$hash]);
    update_option('company_media_governance_exceptions_v1',$registry,false);
    company_media_add_event(['entity'=>'admin','field'=>'revoked_exception','decision'=>'warn',
       'issues'=>[['code'=>'revoked_shared_asset','asset_hash'=>$hash,'group'=>$group]]],'admin_exception');
    return true;
}
add_action('admin_menu',static function(){
    add_management_page('Media Governance','Media Governance','manage_options','company-media-governance',
        'company_media_admin_page');
});
function company_media_admin_page(){
    if(!current_user_can('manage_options'))wp_die('Not authorized.');
    $feedback='';
    if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['media_governance_action'])){
        check_admin_referer('company_media_governance_change');
        $operation=sanitize_key($_POST['media_governance_action']);
        if($operation==='save_policy'){
            company_media_policy_admin_update(wp_unslash($_POST));$feedback='Policy saved.';
        }elseif($operation==='audit'){
            $scan=company_media_audit_full();$feedback='Audit complete: '.strtoupper($scan['status']).'.';
        }elseif($operation==='exception'){
            $saved=company_media_approve_exception($_POST);
            $feedback=is_wp_error($saved)?$saved->get_error_message():'Documented sharing exception recorded.';
        }elseif($operation==='revoke'){
            $feedback=company_media_revoke_exception($_POST)?
              'Sharing exception revoked.':'No matching exception.';
        }elseif($operation==='quarantine'){
            $created=company_media_quarantine_asset($_POST);
            $feedback=is_wp_error($created)?$created->get_error_message():'Media quarantine created.';
        }elseif($operation==='unquarantine'){
            $feedback=company_media_unquarantine_asset($_POST)?
                'Media quarantine removed.':'No matching quarantine.';
        }elseif($operation==='restore'){
            $restored=company_media_restore_journal_entry(
               sanitize_text_field(wp_unslash($_POST['action_id']??'')));
            $feedback=is_wp_error($restored)?$restored->get_error_message():
                'Previous media value restored with current-policy and optimistic checks.';
        }
    }
    $policy=company_media_policy();
    $audit=get_option('company_media_governance_last_audit_v1',[]);
    $events=get_option('company_media_governance_events_v1',[]);
    $exceptions=get_option('company_media_governance_exceptions_v1',[]);
    echo '<div class="wrap"><h1>Media Integrity Governance</h1>';
    echo '<p>Image integrity policies for WordPress, JetEngine, WooCommerce and WPML.';
    echo ' Original images remain editable; background repairs require separately approved source evidence.</p>';
    if($feedback)echo '<div class="notice notice-info"><p>'.esc_html($feedback).'</p></div>';
    echo '<h2>Policy</h2><form method="post">';
    wp_nonce_field('company_media_governance_change');
    echo '<input type="hidden" name="media_governance_action" value="save_policy">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Enable validation</th><td><label><input type="checkbox" name="enabled" value="1" '.
        checked((bool)$policy['enabled'],true,false).'> Validate media metadata writes</label></td></tr>';
    echo '<tr><th>Reuse group threshold</th><td><input type="number" min="2" max="100" name="threshold" value="'.
        esc_attr($policy['threshold']).'"> Independent WPML groups before a new assignment triggers a rule</td></tr>';
    foreach(['term_mode'=>'All taxonomies','post_mode'=>'All post/product types',
        'quarantine_mode'=>'Known invalid/quarantined assets'] as $key=>$label){
        echo '<tr><th>'.esc_html($label).'</th><td><select name="'.esc_attr($key).'">';
        foreach(['off'=>'Off','warn'=>'Warn & audit','block'=>'Block'] as $value=>$title)
            echo '<option value="'.esc_attr($value).'" '.selected($policy[$key],$value,false).'>'.
                esc_html($title).'</option>';
        echo '</select></td></tr>';
    }
    echo '<tr><th>Type-specific policies</th><td><textarea name="type_overrides" rows="6" cols="72">'.
        esc_textarea(implode("\n",array_map(static fn($k,$v)=>$k.'='.$v,
        array_keys($policy['type_overrides']),(array)$policy['type_overrides']))).
        '</textarea><p class="description">Example: term:destinations=block. Defaults for other types: warn.</p></td></tr>';
    foreach(['term_fields'=>'Term image meta keys','post_fields'=>'Post image meta keys'] as $key=>$label)
        echo '<tr><th>'.esc_html($label).'</th><td><textarea rows="3" cols="90" name="'.
            esc_attr($key).'">'.esc_textarea(implode(', ',$policy[$key])).'</textarea></td></tr>';
    echo '</tbody></table>';submit_button('Save media governance policy');
    echo '</form><hr/><h2>Full inventory and anomalies</h2>';
    echo '<p>Last audit '.esc_html($audit['created_at']??'not yet run').
        ' | Status '.esc_html(strtoupper($audit['status']??'unknown')).
        ' | Checked '.esc_html((string)($audit['scanned_records']??0)).
        ' | Unique assets '.esc_html((string)($audit['unique_assets']??0)).
        ' | Cross-group alerts '.esc_html((string)($audit['cross_group_violations_count']??0)).
        ' | Quarantine violations '.esc_html((string)count($audit['quarantine_violations']??[])).'</p>';
    echo '<form method="post">';wp_nonce_field('company_media_governance_change');
    echo '<input type="hidden" name="media_governance_action" value="audit">';
    submit_button('Run read-only audit','secondary');echo '</form>';
    $top=array_slice($audit['top_cross_group']??[],0,30);
    if($top){
        echo '<table class="widefat striped"><thead><tr><th>Repeated image</th><th>Distinct groups</th><th>Exception</th></tr></thead><tbody>';
        foreach($top as $row)
            echo '<tr><td>'.esc_html($row['asset']).'</td><td>'.esc_html((string)$row['distinct_groups']).
                '</td><td>'.($row['is_excepted']?'Approved groups exist':'Review').'</td></tr>';
        echo '</tbody></table>';
    }
    echo '<hr/><h2>Quarantine invalid or misassigned imagery</h2>';
    echo '<p>Explicitly block a media asset across content types, optionally preserving one approved WPML translation group. Existing records are audited, never automatically erased.</p>';
    echo '<form method="post">';wp_nonce_field('company_media_governance_change');
    echo '<input type="hidden" name="media_governance_action" value="quarantine">';
    echo '<p><input type="text" name="asset" size="65" placeholder="Media Library ID or image URL" required></p>';
    echo '<p><select name="allow_kind"><option value="">Do not preserve a group</option><option value="term">Term</option><option value="post">Post/product</option></select> ';
    echo '<input type="number" min="1" name="allow_object_id" placeholder="Optional allowed content ID"></p>';
    echo '<p><textarea name="reason" rows="2" cols="95" placeholder="Evidence of invalid image distribution" required></textarea></p>';
    submit_button('Quarantine image','secondary');echo '</form>';
    $quarantine=get_option('company_media_governance_quarantine_v1',[]);
    if(is_array($quarantine)&&$quarantine){
        echo '<h3>Active managed quarantines</h3>';
        foreach(array_slice($quarantine,0,100,true) as $hash=>$entry){
            echo '<form method="post" style="margin-bottom:8px;">';
            wp_nonce_field('company_media_governance_change');
            echo '<input type="hidden" name="media_governance_action" value="unquarantine">';
            echo '<input type="hidden" name="asset_hash" value="'.esc_attr($hash).'">';
            echo '<code>'.esc_html($entry['asset']).'</code> '.
                esc_html($entry['reason']??''). ' <button class="button" type="submit">Remove quarantine</button></form>';
        }
    }
    echo '<hr/><h2>Approve legitimate shared imagery</h2>';
    echo '<p>Only a documented exception for the selected post/term and image; quarantined media cannot be approved here.</p>';
    echo '<form method="post">';wp_nonce_field('company_media_governance_change');
    echo '<input type="hidden" name="media_governance_action" value="exception">';
    echo '<p><select name="kind"><option value="term">Taxonomy term</option><option value="post">Post or product</option></select> ';
    echo '<input type="number" min="1" name="object_id" placeholder="ID" required> ';
    echo '<input type="text" name="asset" size="62" placeholder="Media Library attachment ID or approved image URL" required></p>';
    echo '<p><textarea rows="2" cols="95" name="reason" placeholder="Why is this photo legitimately shared by these items?" required></textarea></p>';
    submit_button('Approve documented sharing','secondary');echo '</form>';
    if(is_array($exceptions)&&$exceptions){
        echo '<h3>Manage exceptions</h3>';
        foreach(array_slice($exceptions,0,100) as $hash=>$row){
            foreach($row['groups'] as $group){
                echo '<form method="post" style="margin-bottom:8px;">';
                wp_nonce_field('company_media_governance_change');
                echo '<input type="hidden" name="media_governance_action" value="revoke">';
                echo '<input type="hidden" name="asset_hash" value="'.esc_attr($hash).'">';
                echo '<input type="hidden" name="group" value="'.esc_attr($group).'">';
                echo '<code>'.esc_html($row['asset']).'</code> '.esc_html($group).' ';
                echo '<button class="button" type="submit">Revoke</button></form>';
            }
        }
    }
    echo '<hr/><h2>Recover a previous value from WordPress media history</h2>';
    echo '<p>Only restores values recorded during guarded WordPress edits. Current value must still match; quarantine policy cannot be bypassed. Historical SQL backup recovery uses a separate reviewed import manifest.</p>';
    $history=get_option('company_media_governance_recovery_history_v1',[]);
    if($history){
        echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Content</th><th>Field</th><th>Action</th></tr></thead><tbody>';
        foreach(array_slice($history,0,30) as $item){
            echo '<tr><td>'.esc_html($item['time']).'</td><td>'.
                esc_html($item['kind'].':'.$item['id']).'</td><td>'.
                esc_html($item['field']).'</td><td>';
            if(empty($item['restored_at'])){
                echo '<form method="post">';
                wp_nonce_field('company_media_governance_change');
                echo '<input type="hidden" name="media_governance_action" value="restore">';
                echo '<input type="hidden" name="action_id" value="'.esc_attr($item['action_id']).'">';
                echo '<button class="button" type="submit">Restore prior value (verified)</button></form>';
            }else echo 'Restored '.esc_html($item['restored_at']);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }else echo '<p>No successful guarded media edits have been recorded yet.</p>';
    echo '<hr/><h2>Review history</h2>';
    if($events){
        echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Decision</th><th>Content</th><th>Field</th><th>Reason</th></tr></thead><tbody>';
        foreach(array_slice($events,0,60) as $row)
            echo '<tr><td>'.esc_html($row['time']).'</td><td>'.esc_html($row['decision']).'</td>'.
                '<td>'.esc_html($row['entity']).'</td><td>'.esc_html($row['field']).'</td><td>'.
                esc_html(implode(', ',array_map(static fn($i)=>$i['code']??'unknown',(array)$row['issues']))).
                '</td></tr>';
        echo '</tbody></table>';
    }else echo '<p>No reviewed or blocked image updates recorded yet.</p>';
    echo '</div>';
}
