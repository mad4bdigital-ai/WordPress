<?php
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
$profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
if(site_url()!=='https://tours.dev.mad4b.com'
 ||wp_get_environment_type()!=='staging'
 ||($profile['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a') {
 WP_CLI::error('Wrong site identity');
}
global $wpdb;
$table=$wpdb->prefix.'fluentform_form_meta';
foreach([6,7,8,11] as $form) {
 $rows=$wpdb->get_results($wpdb->prepare("SELECT id,value FROM {$table} WHERE form_id=%d AND meta_key=%s",$form,'_custom_form_css'));
 if(count($rows)!==1)WP_CLI::error('Expected one CSS meta row on form '.$form);
 $row=$rows[0];
 $original=(string)$row->value;
 $backup=WP_CONTENT_DIR.'/mu-plugins/.qa/fluentform-'.$form.'-css-before-index-20261102.css';
 if(!is_file($backup) && file_put_contents($backup,$original,LOCK_EX)===false)
  WP_CLI::error('Could not back up form '.$form);
 // Only the specific exaggerated z-index declarations from the user's
 // existing mobile stepper CSS are touched; all other custom CSS survives.
 $updated=preg_replace('/(z-index\s*:\s*)(?:999999|999)(\s*!important\s*;|\s*;)/i','$1'.'3'.'$2',$original,-1,$hits);
 if(!is_string($updated)||$hits!==2)WP_CLI::error('Unexpected high z-index count on form '.$form.': '.$hits);
 if(in_array($form,[7,8],true)){
  $updated=preg_replace('/(z-index\s*:\s*)10(\s*!important\s*;)/i','$1'.'1'.'$2',$updated,-1,$wrapHits);
  if($wrapHits!==1)WP_CLI::error('Unexpected wrapper count on form '.$form);
 }
 if($updated===$original)WP_CLI::error('Expected source change not found for form '.$form);
 $ok=$wpdb->update($table,['value'=>$updated],['id'=>$row->id],['%s'],['%d']);
 if($ok!==1)WP_CLI::error('Failed to update form '.$form);
 $stored=(string)$wpdb->get_var($wpdb->prepare("SELECT value FROM {$table} WHERE id=%d",$row->id));
 if($stored!==$updated)WP_CLI::error('Failed readback for form '.$form);
 WP_CLI::log('FORM '.$form.' revised exactly '.$hits.' exaggerated button layers, backup='.basename($backup));
}
WP_CLI::success('Staging Fluent Forms stacking settings reconciled; no form field or JS changes');
