<?php
/**
 * One-time CAS reconciliation of the original JetEngine "destinations" taxonomy.
 * Adds an editorial repeater, not a new taxonomy, endpoint or runtime provider.
 * Default dry-run; APPLY=yes requires a persisted, hash-checked baseline.
 */
defined('ABSPATH') || exit;
global $wpdb;
$table=$wpdb->prefix.'jet_taxonomies';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,meta_fields FROM $table WHERE slug=%s LIMIT 1",'destinations'),ARRAY_A);
if(!$row)WP_CLI::error('Original destinations taxonomy missing');
$before=(string)$row['meta_fields'];
$fields=maybe_unserialize($before);
if(!is_array($fields))WP_CLI::error('Original JetEngine field data not an array');
$names=array_column($fields,'name');
if(count($names)!==count(array_unique($names)))WP_CLI::error('existing duplicate field names');
$existingKey=array_search('tour_highlight_aliases',$names,true);
if($existingKey!==false){
 $existing=$fields[$existingKey];
 if(($existing['type']??'')!=='repeater')WP_CLI::error('alias field has unexpected type');
 $children=array_column((array)($existing['repeater-fields']??[]),'name');
 if($children!==['tour_lang','tour_alias'])WP_CLI::error('alias field children have drifted');
 WP_CLI::success('Already configured; count='.count($fields).'; no write');
 return;
}
$idx=array_search('highlights',$names,true);
if($idx===false || ($fields[$idx]['type']??'')!=='repeater'
 || empty($fields[$idx]['repeater-fields'][0]))
 WP_CLI::error('Expected native Highlights repeater is missing');
$baseline=hash('sha256',$before);
$backupDir=(string)getenv('DEST_ALIAS_BACKUP_DIR');
if($backupDir===''||!is_dir($backupDir)||!is_writable($backupDir))
 WP_CLI::error('Non-public writable backup folder is required');
$backup=$backupDir.'/destinations-meta-fields-'.$baseline.'.ser';
if(!file_exists($backup)){
 if(file_put_contents($backup,$before,LOCK_EX)===false)WP_CLI::error('Could not persist baseline');
 @chmod($backup,0600);
}
if(hash_file('sha256',$backup)!==$baseline)WP_CLI::error('Baseline backup checksum mismatch');
$seen=[];
$collectIds=function($a)use(&$seen,&$collectIds){
 if(!is_array($a))return;
 if(isset($a['id'])&&is_numeric($a['id']))$seen[(int)$a['id']]=true;
 foreach($a as $v)if(is_array($v))$collectIds($v);
};
$collectIds($fields);
$ids=[91231,91232,91233,91234,91235,91236,91237,91238,91239];
foreach($ids as $id)if(isset($seen[$id]))WP_CLI::error("Field id conflict: $id");
$field=$fields[$idx];
$field['title']='Tour Highlight — Alternative Destination Names';
$field['name']='tour_highlight_aliases';
$field['id']=$ids[0];
$child=$fields[$idx]['repeater-fields'][0];
$language=$child;
$language['title']='Language';
$language['name']='tour_lang';
$language['id']=$ids[1];
$language['type']='select';
$language['options_source']='manual';
$language['options']=[];
foreach(['en'=>'English','es'=>'Español','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch','it'=>'Italiano'] as $k=>$v)
 $language['options'][]=['key'=>$k,'value'=>$v,'id'=>$ids[count($language['options'])+3]];
$alias=$child;
$alias['title']='Alternative place name appearing in a Highlight';
$alias['name']='tour_alias';
$alias['id']=$ids[2];
$alias['type']='text';
$alias['options_source']='manual';
$alias['options']=[];
$field['repeater-fields']=[$language,$alias];
array_splice($fields,$idx+1,0,[$field]);
$after=maybe_serialize($fields);
if(!is_string($after)||$after==='')WP_CLI::error('Serialization failed');
$summary=['taxonomy'=>'destinations','from_count'=>count($names),'to_count'=>count($fields),
 'baseline_sha256'=>$baseline,'candidate_sha256'=>hash('sha256',$after),
 'mode'=>(getenv('DEST_ALIAS_APPLY')==='yes'?'apply':'dry-run')];
WP_CLI::log(wp_json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if(getenv('DEST_ALIAS_APPLY')!=='yes')return;
$expected=(string)getenv('DEST_ALIAS_EXPECTED_SHA256');
if($expected!==$baseline)WP_CLI::error('Expected baseline SHA mismatch');
$affected=$wpdb->query($wpdb->prepare("UPDATE $table SET meta_fields=%s WHERE id=%d AND BINARY meta_fields=%s",
 $after,(int)$row['id'],$before));
if($affected!==1)WP_CLI::error('Taxonomy CAS failed; no update');
$read=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM $table WHERE id=%d",(int)$row['id']));
if($read!==$after)WP_CLI::error('Taxonomy independent readback failed');
WP_CLI::success('Original JetEngine taxonomy field reconciled; count='.count($fields));
