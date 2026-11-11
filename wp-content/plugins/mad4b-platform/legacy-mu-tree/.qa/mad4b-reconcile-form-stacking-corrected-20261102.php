<?php
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
$profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
if(site_url()!=='https://tours.dev.mad4b.com'||wp_get_environment_type()!=='staging'||($profile['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 WP_CLI::error('Wrong site identity');
global $wpdb;
$table=$wpdb->prefix.'fluentform_form_meta';
foreach([6,7,8,11] as $id){
 $rows=$wpdb->get_results($wpdb->prepare("SELECT id,value FROM {$table} WHERE form_id=%d AND meta_key=%s",$id,'_custom_form_css'));
 if(count($rows)!==1)WP_CLI::error('Missing unique source CSS on form '.$id);
 $original=file_get_contents(WP_CONTENT_DIR.'/mu-plugins/.qa/fluentform-'.$id.'-css-before-index-20261102.css');
 if(!is_string($original)||strlen($original)<2000) WP_CLI::error('Backup invalid');
 $current=$rows[0]->value;
 $prior=preg_replace('/(z-index\s*:\s*)(?:999999|999)(\s*!important\s*;|\s*;)/i','$13$2',$original,-1,$nh);
 if(in_array($id,[7,8],true))$prior=preg_replace('/(z-index\s*:\s*)10(\s*!important\s*;)/i','$11$2',$prior);
 if($nh!==2||$prior!==$current)WP_CLI::error('Live CSS drift on form '.$id.'; refusing overwrite');
 $safe=preg_replace_callback(
  '/(z-index\s*:\s*)(?:999999|999)(\s*!important\s*;|\s*;)/i',
  static fn($m)=>$m[1].'3'.$m[2],$original,-1,$hits
 );
 if(in_array($id,[7,8],true)){
  $safe=preg_replace_callback('/(z-index\s*:\s*)10(\s*!important\s*;)/i',
   static fn($m)=>$m[1].'1'.$m[2],$safe,-1,$wrapper);
  if($wrapper!==1)WP_CLI::error('Wrapper mismatch form '.$id);
 }
 if($hits!==2||strpos($safe,'999999')!==false) WP_CLI::error('Updated CSS invalid');
 $result=$wpdb->update($table,['value'=>$safe],['id'=>$rows[0]->id],['%s'],['%d']);
 if($result!==1)WP_CLI::error('Write failed form '.$id);
 WP_CLI::log('Corrected form '.$id.' high z-index declarations: '.$hits);
}
WP_CLI::success('All four Staging form CSS sources reconciled using original backups');
